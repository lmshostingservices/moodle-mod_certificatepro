<?php
// This file is part of the Certificate Pro plugin for Moodle - https://lms-labs.com
//
// Certificate Pro is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Certificate Pro is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Certificate Pro.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_certificatepro\local\ai;

use mod_certificatepro\element\render_context;
use mod_certificatepro\local\art\background_library;
use mod_certificatepro\local\brand;

/**
 * Stores and retrieves generated backgrounds.
 *
 * Backgrounds are saved once and reused, so a certificate does not need a
 * model round trip every time it is downloaded, and so an issued certificate
 * always looks exactly as it did on the day it was issued.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class background_repository {
    /**
     * Save a generated background.
     *
     * The whole palette and the seed are kept alongside the markup, because
     * together with the style they are the complete recipe for a procedural
     * drawing. {@see recipe()} reads them back so the print can replay the
     * drawing rather than parse the markup. Omit either and the row falls back
     * to the markup, which is correct but flattens gradients.
     *
     * @param \context $context the context that owns it
     * @param string $name the author facing name
     * @param string $svg the sanitised markup
     * @param array $meta prompt, style, palette, palettefull, seed, orientation and generator
     * @return int the new record id
     */
    public static function save(\context $context, string $name, string $svg, array $meta): int {
        global $DB, $USER;

        $full = $meta['palettefull'] ?? null;

        $record = new \stdClass();
        $record->contextid = $context->id;
        $record->name = $name;
        $record->prompt = (string) ($meta['prompt'] ?? '');
        $record->style = (string) ($meta['style'] ?? '');
        $record->palette = (string) ($meta['palette'] ?? '');
        $record->palettejson = is_array($full) && $full !== [] ? json_encode($full) : null;
        $record->seed = max(0, (int) ($meta['seed'] ?? 0));
        $record->orientation = (string) ($meta['orientation'] ?? 'L');
        $record->svg = $svg;
        $record->generator = (string) ($meta['generator'] ?? generator::SOURCE_PROCEDURAL);
        $record->createdby = (int) $USER->id;
        $record->timecreated = time();

        return (int) $DB->insert_record('certificatepro_backgrounds', $record);
    }

    /**
     * The backgrounds available in a context, including any shared at site level.
     *
     * @param \context $context the context to look in
     * @return \stdClass[]
     */
    public static function available(\context $context): array {
        global $DB;

        $contextids = [\context_system::instance()->id];
        foreach ($context->get_parent_context_ids(true) as $id) {
            $contextids[] = (int) $id;
        }
        $contextids = array_values(array_unique($contextids));

        [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);

        return $DB->get_records_select(
            'certificatepro_backgrounds',
            'contextid ' . $insql,
            $params,
            'timecreated DESC'
        );
    }

    /**
     * A selector menu of the available backgrounds.
     *
     * @param \context $context the context to look in
     * @return array<int, string>
     */
    public static function menu(\context $context): array {
        $menu = [0 => get_string('artworknone', 'mod_certificatepro')];
        foreach (self::available($context) as $record) {
            $menu[(int) $record->id] = format_string($record->name);
        }
        return $menu;
    }

    /**
     * The markup for a stored background.
     *
     * @param int $backgroundid the record to fetch
     * @param render_context $ctx the render context
     * @return string markup, or an empty string when there is none
     */
    public static function svg(int $backgroundid, render_context $ctx): string {
        global $DB;

        if ($backgroundid <= 0) {
            return '';
        }

        $record = $DB->get_record('certificatepro_backgrounds', ['id' => $backgroundid]);
        if (!$record || empty($record->svg)) {
            return '';
        }

        return (string) $record->svg;
    }

    /**
     * The recipe for redrawing a stored background, when there is one.
     *
     * This is what tells a procedurally drawn background apart from one a model
     * composed, and it answers conservatively: a recipe comes back only when
     * every one of the four inputs a drawing needs is on the record and usable.
     *
     *  - the generator says the built in engine drew it, so the markup was
     *    never anything but a rendering of an operation list;
     *  - the style is one {@see background_library} still knows how to draw;
     *  - the seed is on the record, which rows written before the seed column
     *    existed do not have;
     *  - the whole palette is on the record and parses as nine hex roles.
     *
     * A model-composed background fails the first test and can never pass it:
     * its markup is the original artwork and the only copy of it, so it keeps
     * the markup path. A legacy procedural row fails the third or fourth and
     * keeps the markup path too — correct, just flattened, exactly as before.
     *
     * The palette is read from the record rather than from the design being
     * rendered on purpose. A certificate issued last year has to come back out
     * of the archive looking as it did, and the palette the artwork was drawn
     * from is part of that.
     *
     * @param int $backgroundid the record to read
     * @return array{style: string, seed: int, palette: array<string, string>}|null
     *      the recipe, or null when the stored markup is the only answer
     */
    public static function recipe(int $backgroundid): ?array {
        global $DB;

        if ($backgroundid <= 0) {
            return null;
        }

        $record = $DB->get_record('certificatepro_backgrounds', ['id' => $backgroundid],
            'id, style, seed, palettejson, generator');
        if (!$record) {
            return null;
        }

        if ((string) $record->generator !== generator::SOURCE_PROCEDURAL) {
            return null;
        }

        $style = (string) $record->style;
        $seed = (int) $record->seed;
        if ($seed <= 0 || $style === '' || !background_library::exists($style)) {
            return null;
        }

        $palette = self::decode_palette((string) ($record->palettejson ?? ''));
        if ($palette === null) {
            return null;
        }

        return ['style' => $style, 'seed' => $seed, 'palette' => $palette];
    }

    /**
     * Read a stored palette back, refusing anything incomplete.
     *
     * Half a palette would draw, because {@see \mod_certificatepro\local\art\canvas::role()}
     * substitutes the ink role for anything missing — and it would draw
     * something other than the markup beside it on the same record, which is
     * the one outcome this whole change exists to prevent. So a palette is
     * either all nine roles in hex or it is not a palette.
     *
     * @param string $json the stored JSON
     * @return array<string, string>|null the palette, or null when it is unusable
     */
    protected static function decode_palette(string $json): ?array {
        if (trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        $palette = [];
        foreach (array_keys(brand::palette()) as $role) {
            $value = $decoded[$role] ?? null;
            if (!is_string($value) || !brand::is_hex($value)) {
                return null;
            }
            $palette[$role] = brand::normalise_hex($value);
        }

        return $palette;
    }

    /**
     * Delete a stored background.
     *
     * @param int $backgroundid the record to delete
     * @return void
     */
    public static function delete(int $backgroundid): void {
        global $DB;

        $DB->delete_records('certificatepro_backgrounds', ['id' => $backgroundid]);
    }

    /**
     * Produce and store a background in one step.
     *
     * @param \context $context the context that will own it
     * @param string $name the author facing name
     * @param string $prompt what the author asked for
     * @param string $style the procedural style to use or to take guidance from
     * @param string $orientation L or P
     * @param array $options seed, intensity, palette and whether to allow a model
     * @return array{id: int, svg: string, source: string, notice: string|null}
     */
    public static function create(\context $context, string $name, string $prompt, string $style,
            string $orientation, array $options = []): array {
        $palette = !empty($options['palette']) && is_array($options['palette'])
            ? $options['palette']
            : brand::palette($options['primary'] ?? null);

        $result = generator::generate($prompt, $style, $palette, $orientation, $context, $options);

        // The recipe is written only when the style catalogue drew the markup,
        // which is the only case where replaying it reproduces what is stored.
        // A model's artwork and the legacy engine's markup both leave these two
        // blank, which is what keeps them on the markup path for good.
        $replayable = ($result['engine'] ?? generator::ENGINE_NONE) === generator::ENGINE_LIBRARY;

        $id = self::save($context, $name, $result['svg'], [
            'prompt' => $prompt,
            'style' => $style,
            'palette' => $palette['primary'] ?? '',
            'palettefull' => $replayable ? $palette : null,
            'seed' => $replayable ? max(1, (int) ($options['seed'] ?? 1)) : 0,
            'orientation' => $orientation,
            'generator' => $result['source'],
        ]);

        return [
            'id' => $id,
            'svg' => $result['svg'],
            'source' => $result['source'],
            'notice' => $result['notice'],
        ];
    }
}
