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

namespace mod_certificatepro\local\font;

/**
 * Turns an install report into something an administrator can read.
 *
 * Kept apart from the page so the wording can be tested without a browser, and
 * so a report never reaches a human as a var_dump of style flags.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class install_report {
    /**
     * The name of one face.
     *
     * @param string $style a TCPDF style flag: empty, B, I or BI
     * @return string
     */
    public static function face_name(string $style): string {
        return match ($style) {
            'B' => get_string('fontstyle_bold', 'mod_certificatepro'),
            'I' => get_string('fontstyle_italic', 'mod_certificatepro'),
            'BI' => get_string('fontstyle_bolditalic', 'mod_certificatepro'),
            default => get_string('fontstyle_regular', 'mod_certificatepro'),
        };
    }

    /**
     * A list of face names, in the order the faces are installed.
     *
     * @param string[] $styles style flags
     * @return string
     */
    public static function face_list(array $styles): string {
        $names = [];
        foreach (array_keys(google_fonts::FACES) as $style) {
            if (in_array($style, $styles, true)) {
                $names[] = \core_text::strtolower(self::face_name($style));
            }
        }
        return implode(', ', $names);
    }

    /**
     * The lines to show for one family's install report.
     *
     * @param array $report a report from {@see google_fonts::install()}
     * @return array[] each entry has a text and a notification type
     */
    public static function messages(array $report): array {
        $family = (string) ($report['family'] ?? '');
        $lines = [];

        if (!empty($report['error'])) {
            $lines[] = [
                'text' => match ($report['error']) {
                    'network' => get_string('gfontserrornetwork', 'mod_certificatepro'),
                    'notwritable' => get_string('settings_fontsnotwritable', 'mod_certificatepro',
                        \mod_certificatepro\local\font_manager::custom_font_directory()),
                    'nosuchfamily' => get_string('gfontserrornosuchfamily', 'mod_certificatepro',
                        $family),
                    'noregular' => get_string('gfontserrornoregular', 'mod_certificatepro', $family),
                    default => get_string('gfontserrorbadfamily', 'mod_certificatepro'),
                },
                'type' => 'error',
            ];

            // The URL and status that failed, so an administrator chasing a
            // proxy or a firewall has something concrete to chase.
            if (!empty($report['detail'])) {
                $lines[] = [
                    'text' => get_string('gfontserrordetail', 'mod_certificatepro',
                        s((string) $report['detail'])),
                    'type' => 'info',
                ];
            }

            return $lines;
        }

        if (!empty($report['installed'])) {
            $lines[] = [
                'text' => get_string('gfontsresultinstalled', 'mod_certificatepro', (object) [
                    'family' => $family,
                    'faces' => self::face_list(array_keys($report['installed'])),
                ]),
                'type' => 'success',
            ];
        } else if (empty($report['failed']) && empty($report['skipped'])) {
            $lines[] = [
                'text' => get_string('gfontsresultnothing', 'mod_certificatepro', $family),
                'type' => 'info',
            ];
        }

        if (!empty($report['skipped'])) {
            $lines[] = [
                'text' => get_string('gfontsresultskipped', 'mod_certificatepro', (object) [
                    'family' => $family,
                    'faces' => self::face_list($report['skipped']),
                ]),
                'type' => 'info',
            ];
        }

        if (!empty($report['failed'])) {
            $lines[] = [
                'text' => get_string('gfontsresultfailed', 'mod_certificatepro', (object) [
                    'family' => $family,
                    'faces' => self::face_list(array_keys($report['failed'])),
                    'reason' => implode('; ', array_unique(array_values($report['failed']))),
                ]),
                'type' => 'warning',
            ];
        }

        return $lines;
    }

    /**
     * How many faces a batch of reports installed.
     *
     * @param array[] $reports reports from {@see google_fonts::install()}
     * @return array{families: int, faces: int}
     */
    public static function totals(array $reports): array {
        $families = 0;
        $faces = 0;

        foreach ($reports as $report) {
            $installed = count((array) ($report['installed'] ?? []));
            if ($installed > 0) {
                $families++;
                $faces += $installed;
            }
        }

        return ['families' => $families, 'faces' => $faces];
    }
}
