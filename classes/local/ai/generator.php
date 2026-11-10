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

use mod_certificatepro\local\art\background_library;
use mod_certificatepro\local\brand;

/**
 * Turns a written description of a background into vector artwork.
 *
 * Three routes are tried in order, and the first that produces something
 * drawable wins:
 *
 * 1. The site's own Moodle artificial intelligence provider, if one is set up.
 *    This is the preferred route because the administrator already owns the
 *    credentials and the consent conversation has already happened.
 * 2. A directly configured provider, for sites that have not adopted the core
 *    subsystem. The key is entered by an administrator in plugin settings and
 *    is never shipped with the plugin.
 * 3. The procedural drawing engine, which needs no network access at all.
 *
 * Route three always succeeds, so artwork generation never fails outright and
 * never blocks a certificate from being produced.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generator {
    /** @var string Artwork drawn by the built in engine. */
    public const SOURCE_PROCEDURAL = 'procedural';

    /** @var string Artwork composed by the site's Moodle provider. */
    public const SOURCE_COREAI = 'coreai';

    /** @var string Artwork composed by a directly configured provider. */
    public const SOURCE_DIRECT = 'direct';

    /** @var string Drawn from the current style catalogue, so it can be replayed. */
    public const ENGINE_LIBRARY = 'library';

    /** @var string Drawn from the original markup-emitting engine. */
    public const ENGINE_LEGACY = 'legacy';

    /** @var string Not drawn here at all: the markup is the artwork. */
    public const ENGINE_NONE = 'none';

    /**
     * Produce artwork.
     *
     * @param string $prompt what the author asked for, in their own words
     * @param string $style a procedural style to use or to guide the composition
     * @param array $palette a palette from {@see brand::palette()}
     * @param string $orientation L or P
     * @param \context $context the context the request is made in
     * @param array $options seed, intensity and whether to allow a model
     * @return array{svg: string, source: string, notice: string|null, engine: string}
     */
    public static function generate(string $prompt, string $style, array $palette,
            string $orientation, \context $context, array $options = []): array {
        [$width, $height] = $orientation === 'P' ? [210.0, 297.0] : [297.0, 210.0];
        $seed = (int) ($options['seed'] ?? 1);
        $intensity = (float) ($options['intensity'] ?? 0.5);
        $allowmodel = (bool) ($options['allowmodel'] ?? true);

        [$fallback, $engine] = self::draw_procedural($style, $palette, $width, $height, $seed,
            $intensity);

        if (!$allowmodel || trim($prompt) === '') {
            return ['svg' => $fallback, 'source' => self::SOURCE_PROCEDURAL, 'notice' => null,
                'engine' => $engine];
        }

        $instruction = self::compose_instruction($prompt, $style, $palette, $width, $height);

        foreach (self::providers() as $provider) {
            if (!$provider->is_available()) {
                continue;
            }
            try {
                $raw = $provider->compose($instruction, $context);
            } catch (\Throwable $e) {
                debugging('Certificate Pro artwork provider failed: ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
                continue;
            }

            if ($raw === null || trim($raw) === '') {
                continue;
            }

            $clean = svg_sanitiser::clean($raw);
            if ($clean === null || !svg_sanitiser::is_drawable($clean)) {
                continue;
            }

            return [
                'svg' => $clean,
                'source' => $provider->source(),
                'notice' => null,
                // Real artwork from a provider. Nothing can redraw it, so the
                // markup is the artwork and the engine is nobody's.
                'engine' => self::ENGINE_NONE,
            ];
        }

        return [
            'svg' => $fallback,
            'source' => self::SOURCE_PROCEDURAL,
            'notice' => get_string('artworkfellback', 'mod_certificatepro'),
            'engine' => $engine,
        ];
    }

    /**
     * Draw a procedural background, saying which engine drew it.
     *
     * There are two procedural vocabularies in the plugin. The catalogue in
     * {@see background_library} is the current one: twenty-six styles, each an
     * operation list that either painter can replay. {@see artwork_engine} is
     * the original one: ten styles that emit SVG markup directly and have no
     * operation list behind them.
     *
     * Which one drew a background decides whether its print can be replayed
     * natively, so it is answered here, once, and recorded — rather than
     * guessed later from a style name that both vocabularies might answer to.
     * Handed a catalogue style, {@see artwork_engine::procedural()} does not
     * refuse it: its match falls through to the plain wash, which is a
     * different drawing under the same name. That is precisely the ambiguity a
     * stored recipe must not inherit.
     *
     * @param string $style the requested style
     * @param array $palette a palette from {@see brand::palette()}
     * @param float $width page width in millimetres
     * @param float $height page height in millimetres
     * @param int $seed the composition seed
     * @param float $intensity how bold the artwork should be
     * @return array{0: string, 1: string} the markup and the engine that drew it
     */
    protected static function draw_procedural(string $style, array $palette, float $width,
            float $height, int $seed, float $intensity): array {
        if (background_library::exists($style)) {
            $svg = background_library::svg($style, $palette, $width, $height, max(1, $seed));
            if ($svg !== '') {
                return [$svg, self::ENGINE_LIBRARY];
            }
        }

        return [
            artwork_engine::procedural($style, $palette, $width, $height, $seed, $intensity),
            self::ENGINE_LEGACY,
        ];
    }

    /**
     * The providers to try, in order of preference.
     *
     * @return provider[]
     */
    protected static function providers(): array {
        return [
            new core_ai_provider(),
            new direct_provider(),
        ];
    }

    /**
     * Build the instruction sent to a model.
     *
     * @param string $prompt what the author asked for
     * @param string $style the chosen procedural style, used as a hint
     * @param array $palette the palette to work within
     * @param float $width the page width in millimetres
     * @param float $height the page height in millimetres
     * @return string
     */
    protected static function compose_instruction(string $prompt, string $style, array $palette,
            float $width, float $height): string {
        $colours = [];
        foreach (['primary', 'primarydark', 'primarylight', 'primarywash', 'accent'] as $role) {
            if (!empty($palette[$role])) {
                $colours[] = $role . ' ' . $palette[$role];
            }
        }

        return implode("\n", [
            'Compose decorative background artwork for a printed certificate as a single SVG document.',
            '',
            'The author describes what they want as: ' . trim($prompt),
            'Take stylistic guidance from the named style: ' . $style . '.',
            '',
            'Requirements, all of which are mandatory:',
            '- Output nothing but the SVG document. No prose, no explanation, no code fences.',
            '- Use exactly this root element, with no width or height attributes beyond those given:',
            '  <svg xmlns="http://www.w3.org/2000/svg" width="' . $width . 'mm" height="'
                . $height . 'mm" viewBox="0 0 ' . $width . ' ' . $height . '">',
            '- Work only in these colours: ' . implode(', ', $colours) . '.',
            '- This is a background. Nothing may compete with the text that will sit on top of it.',
            '  Keep the central sixty percent of the page quiet: opacity below 0.12 there.',
            '- Use only these elements: path, rect, circle, ellipse, line, polyline, polygon, g,',
            '  defs, linearGradient, radialGradient, stop, clipPath, use.',
            '- No script, no foreignObject, no image, no external references of any kind,',
            '  no event handler attributes, no CSS style blocks, no embedded fonts, no text.',
            '- Keep the whole document under 40 kilobytes and prefer few, well composed shapes',
            '  over thousands of small ones.',
            '- The artwork must be symmetrical or deliberately asymmetrical, never accidentally',
            '  lopsided, and must not touch the outer 8mm of any edge.',
        ]);
    }

    /**
     * Whether any model backed route is configured on this site.
     *
     * @return bool
     */
    public static function model_available(): bool {
        foreach (self::providers() as $provider) {
            if ($provider->is_available()) {
                return true;
            }
        }
        return false;
    }

    /**
     * A short description of which route will be used, for the settings page.
     *
     * @return string
     */
    public static function status_summary(): string {
        foreach (self::providers() as $provider) {
            if ($provider->is_available()) {
                return get_string('artworkstatus_' . $provider->source(), 'mod_certificatepro');
            }
        }
        return get_string('artworkstatus_procedural', 'mod_certificatepro');
    }
}
