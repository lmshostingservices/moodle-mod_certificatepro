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
     * @param \context $context the context that owns it
     * @param string $name the author facing name
     * @param string $svg the sanitised markup
     * @param array $meta prompt, style, palette, orientation and generator
     * @return int the new record id
     */
    public static function save(\context $context, string $name, string $svg, array $meta): int {
        global $DB, $USER;

        $record = new \stdClass();
        $record->contextid = $context->id;
        $record->name = $name;
        $record->prompt = (string) ($meta['prompt'] ?? '');
        $record->style = (string) ($meta['style'] ?? '');
        $record->palette = (string) ($meta['palette'] ?? '');
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

        $id = self::save(
            $context,
            $name,
            $result['svg'],
            [
                'prompt' => $prompt,
                'style' => $style,
                'palette' => $palette['primary'] ?? '',
                'orientation' => $orientation,
                'generator' => $result['source'],
            ]
        );

        return [
            'id' => $id,
            'svg' => $result['svg'],
            'source' => $result['source'],
            'notice' => $result['notice'],
        ];
    }
}
