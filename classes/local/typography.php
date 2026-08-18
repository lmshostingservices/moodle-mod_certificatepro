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

namespace mod_certificatepro\local;

/**
 * Curated font pairings and the type scale that drives them.
 *
 * Authors pick a pairing rather than a font. Each pairing names a preferred
 * face for headings, body and accents, together with a guaranteed fallback
 * that ships with every Moodle install, so a pairing always renders even
 * before the site has uploaded any fonts of its own.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class typography {
    /** @var string Fallback serif family present in every Moodle install. */
    public const FALLBACK_SERIF = 'freeserif';

    /** @var string Fallback sans family present in every Moodle install. */
    public const FALLBACK_SANS = 'freesans';

    /** @var string Fallback monospace family present in every Moodle install. */
    public const FALLBACK_MONO = 'freemono';

    /**
     * The full catalogue of font pairings.
     *
     * @return array[] keyed by pairing identifier
     */
    public static function pairings(): array {
        return [
            'heritage' => self::pairing(
                'heritage',
                ['family' => 'playfairdisplay', 'fallback' => self::FALLBACK_SERIF, 'style' => 'B'],
                ['family' => 'lato', 'fallback' => self::FALLBACK_SANS, 'style' => ''],
                ['family' => 'lato', 'fallback' => self::FALLBACK_SANS, 'style' => 'I'],
                ['tracking' => 0.6, 'case' => 'none', 'scale' => 1.0]
            ),
            'modernist' => self::pairing(
                'modernist',
                ['family' => 'montserrat', 'fallback' => self::FALLBACK_SANS, 'style' => 'B'],
                ['family' => 'opensans', 'fallback' => self::FALLBACK_SANS, 'style' => ''],
                ['family' => 'montserrat', 'fallback' => self::FALLBACK_SANS, 'style' => ''],
                ['tracking' => 2.4, 'case' => 'upper', 'scale' => 0.92]
            ),
            'academic' => self::pairing(
                'academic',
                ['family' => 'ebgaramond', 'fallback' => self::FALLBACK_SERIF, 'style' => ''],
                ['family' => 'ebgaramond', 'fallback' => self::FALLBACK_SERIF, 'style' => ''],
                ['family' => 'ebgaramond', 'fallback' => self::FALLBACK_SERIF, 'style' => 'I'],
                ['tracking' => 1.2, 'case' => 'none', 'scale' => 1.05]
            ),
            'corporate' => self::pairing(
                'corporate',
                ['family' => 'sourcesanspro', 'fallback' => self::FALLBACK_SANS, 'style' => 'B'],
                ['family' => 'sourceserifpro', 'fallback' => self::FALLBACK_SERIF, 'style' => ''],
                ['family' => 'sourcesanspro', 'fallback' => self::FALLBACK_SANS, 'style' => ''],
                ['tracking' => 0.4, 'case' => 'none', 'scale' => 0.96]
            ),
            'editorial' => self::pairing(
                'editorial',
                ['family' => 'librebaskerville', 'fallback' => self::FALLBACK_SERIF, 'style' => 'B'],
                ['family' => 'inter', 'fallback' => self::FALLBACK_SANS, 'style' => ''],
                ['family' => 'librebaskerville', 'fallback' => self::FALLBACK_SERIF, 'style' => 'I'],
                ['tracking' => 0.0, 'case' => 'none', 'scale' => 1.0]
            ),
            'technical' => self::pairing(
                'technical',
                ['family' => 'roboto', 'fallback' => self::FALLBACK_SANS, 'style' => 'B'],
                ['family' => 'roboto', 'fallback' => self::FALLBACK_SANS, 'style' => ''],
                ['family' => 'robotomono', 'fallback' => self::FALLBACK_MONO, 'style' => ''],
                ['tracking' => 1.0, 'case' => 'upper', 'scale' => 0.9]
            ),
            'ceremonial' => self::pairing(
                'ceremonial',
                ['family' => 'cinzel', 'fallback' => self::FALLBACK_SERIF, 'style' => 'B'],
                ['family' => 'crimsontext', 'fallback' => self::FALLBACK_SERIF, 'style' => ''],
                ['family' => 'cinzel', 'fallback' => self::FALLBACK_SERIF, 'style' => ''],
                ['tracking' => 3.0, 'case' => 'upper', 'scale' => 0.94]
            ),
            'friendly' => self::pairing(
                'friendly',
                ['family' => 'nunito', 'fallback' => self::FALLBACK_SANS, 'style' => 'B'],
                ['family' => 'nunito', 'fallback' => self::FALLBACK_SANS, 'style' => ''],
                ['family' => 'nunito', 'fallback' => self::FALLBACK_SANS, 'style' => 'I'],
                ['tracking' => 0.2, 'case' => 'none', 'scale' => 1.0]
            ),
            'minimal' => self::pairing(
                'minimal',
                ['family' => 'inter', 'fallback' => self::FALLBACK_SANS, 'style' => ''],
                ['family' => 'inter', 'fallback' => self::FALLBACK_SANS, 'style' => ''],
                ['family' => 'inter', 'fallback' => self::FALLBACK_SANS, 'style' => ''],
                ['tracking' => 4.0, 'case' => 'upper', 'scale' => 0.82]
            ),
            'system' => self::pairing(
                'system',
                ['family' => self::FALLBACK_SERIF, 'fallback' => self::FALLBACK_SERIF, 'style' => 'B'],
                ['family' => self::FALLBACK_SERIF, 'fallback' => self::FALLBACK_SERIF, 'style' => ''],
                ['family' => self::FALLBACK_SANS, 'fallback' => self::FALLBACK_SANS, 'style' => ''],
                ['tracking' => 0.0, 'case' => 'none', 'scale' => 1.0]
            ),
        ];
    }

    /**
     * Build one pairing record.
     *
     * @param string $id pairing identifier
     * @param array $heading heading face specification
     * @param array $body body face specification
     * @param array $accent accent face specification
     * @param array $options tracking, case and scale modifiers
     * @return array
     */
    protected static function pairing(string $id, array $heading, array $body, array $accent,
            array $options): array {
        return [
            'id' => $id,
            'heading' => $heading,
            'body' => $body,
            'accent' => $accent,
            'tracking' => $options['tracking'] ?? 0.0,
            'case' => $options['case'] ?? 'none',
            'scale' => $options['scale'] ?? 1.0,
        ];
    }

    /**
     * The base type scale in points, before a pairing's scale modifier.
     *
     * Keys correspond to the semantic roles used by the starter designs.
     *
     * @return array<string, float>
     */
    public static function scale(): array {
        return [
            'eyebrow' => 11.0,
            'title' => 40.0,
            'subtitle' => 18.0,
            'presentedto' => 13.0,
            'recipient' => 34.0,
            'body' => 13.0,
            'course' => 20.0,
            'meta' => 10.5,
            'metalabel' => 8.5,
            'signature' => 11.0,
            'signaturename' => 12.0,
            'footer' => 8.0,
            'code' => 9.5,
        ];
    }

    /**
     * Resolve a pairing to concrete font families that exist on this site.
     *
     * @param string $pairingid a key from {@see self::pairings()}
     * @param string[] $available font families that are installed, lowercase
     * @return array the pairing with a resolved family and a missing flag on each face
     */
    public static function resolve(string $pairingid, array $available): array {
        $pairings = self::pairings();
        $pairing = $pairings[$pairingid] ?? $pairings['system'];
        $available = array_map('strtolower', $available);

        foreach (['heading', 'body', 'accent'] as $role) {
            $preferred = strtolower($pairing[$role]['family']);
            $installed = in_array($preferred, $available, true);
            $pairing[$role]['resolved'] = $installed ? $preferred : $pairing[$role]['fallback'];
            $pairing[$role]['missing'] = !$installed && $preferred !== $pairing[$role]['fallback'];
            $pairing[$role]['preferred'] = $preferred;
        }

        return $pairing;
    }

    /**
     * The point size for a semantic role under a given pairing.
     *
     * @param string $pairingid a key from {@see self::pairings()}
     * @param string $role a key from {@see self::scale()}
     * @return float the size in points, rounded to one decimal place
     */
    public static function size(string $pairingid, string $role): float {
        $pairings = self::pairings();
        $pairing = $pairings[$pairingid] ?? $pairings['system'];
        $scale = self::scale();
        $base = $scale[$role] ?? 12.0;
        return round($base * $pairing['scale'], 1);
    }

    /**
     * Apply a pairing's case transform to a string.
     *
     * @param string $text the text to transform
     * @param string $case none or upper
     * @return string
     */
    public static function apply_case(string $text, string $case): string {
        if ($case === 'upper') {
            return \core_text::strtoupper($text);
        }
        return $text;
    }

    /**
     * Options list for a pairing selector, keyed by identifier.
     *
     * @return array<string, string>
     */
    public static function pairing_menu(): array {
        $menu = [];
        foreach (array_keys(self::pairings()) as $id) {
            $menu[$id] = get_string('fontpairing_' . $id, 'mod_certificatepro');
        }
        return $menu;
    }
}
