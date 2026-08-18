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

namespace mod_certificatepro\external;

use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_certificatepro\element\text_base;
use mod_certificatepro\local\font_manager;
use mod_certificatepro\local\type\style_library;
use mod_certificatepro\local\typography;
use mod_certificatepro\template;

/**
 * The services behind the typography combination picker.
 *
 * A combination is a starting point, not a cage. Applying one writes concrete
 * values onto every text element, which is exactly what the element form
 * writes, so an author can afterwards open any single element and override its
 * family, size, colour or case without the combination fighting them back.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class style_api extends library_base {
    /**
     * How a starter design's scale role maps onto a combination's six roles.
     *
     * The second value damps the size, because a combination sizes six roles
     * while the starter designs name thirteen. A course name is a heading, but
     * it must not compete with the certificate's own title, so it takes the
     * title role's family and colour at three quarters of its size.
     *
     * @return array<string, array{0: string, 1: float}>
     */
    protected static function role_map(): array {
        return [
            'eyebrow' => ['subtitle', 0.85],
            'title' => ['title', 1.0],
            'subtitle' => ['subtitle', 1.0],
            'presentedto' => ['subtitle', 1.0],
            'recipient' => ['recipient', 1.0],
            'body' => ['body', 1.0],
            'course' => ['title', 0.75],
            'meta' => ['meta', 1.0],
            'metalabel' => ['meta', 0.85],
            'signature' => ['signature', 1.0],
            'signaturename' => ['signature', 1.1],
            'footer' => ['meta', 0.85],
            'code' => ['meta', 1.0],
        ];
    }

    /**
     * The families from the combination catalogue that this site can actually set.
     *
     * The catalogue names families the way a person does — "EB Garamond" — and
     * the font table names them the way TCPDF does — "ebgaramond". This bridges
     * the two so that style_library::resolve() compares like with like instead
     * of substituting every family it was never shown.
     *
     * @return string[] display names of installed families
     */
    protected static function available_families(): array {
        $available = [];

        $candidates = array_merge(style_library::required_families(),
            style_library::ALWAYS_AVAILABLE);

        foreach ($candidates as $family) {
            if (font_manager::is_installed(self::family_key($family))) {
                $available[$family] = true;
            }
        }

        return array_keys($available);
    }

    /**
     * The point size a role resolves to under a design.
     *
     * A combination states sizes as multipliers of the base type scale. The
     * base is the body size the design already uses, so a design set to a
     * larger pairing scale stays larger after a combination is applied.
     *
     * @param template $tpl the design
     * @param float $multiplier the role's size multiplier
     * @param float $damp the scale role's damping factor
     * @return float the size in points
     */
    protected static function points(template $tpl, float $multiplier, float $damp = 1.0): float {
        $base = typography::size($tpl->get_pairing(), 'body');
        return round($base * $multiplier * $damp, 1);
    }

    /**
     * Parameters for listing the combinations.
     *
     * @return external_function_parameters
     */
    public static function get_style_combinations_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
        ]);
    }

    /**
     * Every combination, with everything the gallery needs to draw a specimen.
     *
     * The specimen is drawn with real CSS rather than a picture, so it uses the
     * same web fonts the designer canvas uses and therefore shows the author
     * what the substitution actually looks like on this site.
     *
     * @param int $templateid the design being edited
     * @return array
     */
    public static function get_style_combinations(int $templateid): array {
        $params = self::validate_parameters(self::get_style_combinations_parameters(), [
            'templateid' => $templateid,
        ]);

        $template = self::require_readable($params['templateid']);
        $palette = $template->get_palette();
        $available = self::available_families();

        // Specimen copy. The catalogue has no sample text of its own, so these
        // carry an English fallback for sites that have not translated them.
        $specimen = [
            'title' => self::label('stylespecimen_title', 'Certificate of Achievement'),
            'subtitle' => self::label('stylespecimen_subtitle', 'presented to'),
            'recipient' => self::label('stylespecimen_recipient', 'Ada Lovelace'),
            'body' => self::label('stylespecimen_body',
                'for completing the programme of study'),
            'meta' => self::label('stylespecimen_meta', 'Issued 16 August 2026'),
            'signature' => self::label('stylespecimen_signature', 'Programme Director'),
        ];

        $combinations = [];

        foreach (style_library::combinations() as $id => $combo) {
            $roles = [];
            $substituted = [];

            foreach (style_library::ROLES as $role) {
                $spec = style_library::resolve($id, $role, $available);
                if ($spec === null) {
                    continue;
                }

                if (!empty($spec['substituted'])) {
                    $substituted[$spec['substituted']] = true;
                }

                $roles[] = [
                    'role' => $role,
                    'family' => $spec['family'],
                    'text' => $specimen[$role] ?? '',
                    'css' => self::specimen_css($spec, $palette),
                ];
            }

            $combinations[] = [
                'id' => $id,
                'name' => self::label('stylecombo_' . $id, $combo['name']),
                'mood' => self::label('stylecombo_' . $id . '_mood', $combo['mood']),
                'families' => implode(', ', $combo['families']),
                'scripts' => implode(', ', $combo['scripts']),
                'substituted' => implode(', ', array_keys($substituted)),
                'hassubstitution' => !empty($substituted),
                'roles' => $roles,
            ];
        }

        return [
            'combinations' => $combinations,
            'paper' => $palette['paper'] ?? '#ffffff',
            'rule' => $palette['rule'] ?? '#d8dee6',
        ];
    }

    /**
     * The inline CSS that draws one specimen line.
     *
     * Every value is either a number this class produced or a colour taken from
     * the design's own palette, so nothing an author typed reaches a style
     * attribute.
     *
     * @param array $spec the resolved role specification
     * @param array $palette the design's palette
     * @return string a CSS declaration list
     */
    protected static function specimen_css(array $spec, array $palette): string {
        // The specimen is drawn at a fraction of print size: a combination's
        // multipliers are relative, so the ratios are what matter here.
        $size = round(9.0 * (float) $spec['size'], 2);
        $colour = $palette[$spec['colour']] ?? ($palette['ink'] ?? '#111111');

        $css = 'font-family:' . font_manager::css_font_stack(self::family_key($spec['family'])) . ';'
            . 'font-weight:' . (int) $spec['weight'] . ';'
            . 'font-size:' . $size . 'px;'
            . 'letter-spacing:' . round((float) $spec['tracking'], 3) . 'em;'
            . 'color:' . $colour . ';';

        switch ($spec['case']) {
            case 'upper':
                $css .= 'text-transform:uppercase;';
                break;
            case 'title':
                $css .= 'text-transform:capitalize;';
                break;
            case 'smallcaps':
                $css .= 'font-variant-caps:small-caps;';
                break;
            default:
                break;
        }

        return $css;
    }

    /**
     * What listing the combinations returns.
     *
     * @return external_single_structure
     */
    public static function get_style_combinations_returns(): external_single_structure {
        return new external_single_structure([
            'combinations' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_ALPHANUMEXT, 'The combination identifier'),
                    'name' => new external_value(PARAM_TEXT, 'Its display name'),
                    'mood' => new external_value(PARAM_TEXT, 'A short description of its register'),
                    'families' => new external_value(PARAM_TEXT, 'The families it names'),
                    'scripts' => new external_value(PARAM_TEXT, 'The scripts those families cover'),
                    'substituted' => new external_value(PARAM_TEXT,
                        'Families missing from this site, or empty'),
                    'hassubstitution' => new external_value(PARAM_BOOL,
                        'Whether anything had to be substituted'),
                    'roles' => new external_multiple_structure(
                        new external_single_structure([
                            'role' => new external_value(PARAM_ALPHA, 'The text role'),
                            'family' => new external_value(PARAM_TEXT, 'The family it resolved to'),
                            'text' => new external_value(PARAM_TEXT, 'Specimen copy for this role'),
                            'css' => new external_value(PARAM_RAW, // pipeline-ignore: PARAM_RAW - generated style text
                                'The declarations that draw the specimen'),
                        ])
                    ),
                ])
            ),
            'paper' => new external_value(PARAM_TEXT, 'The design paper colour'),
            'rule' => new external_value(PARAM_TEXT, 'The design rule colour'),
        ]);
    }

    /**
     * Parameters for applying a combination.
     *
     * @return external_function_parameters
     */
    public static function apply_style_combination_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'combination' => new external_value(PARAM_ALPHANUMEXT, 'The combination identifier'),
        ]);
    }

    /**
     * Restyle every text element in a design.
     *
     * @param int $templateid the design being edited
     * @param string $combination the combination identifier
     * @return array
     */
    public static function apply_style_combination(int $templateid, string $combination): array {
        global $DB;

        $params = self::validate_parameters(self::apply_style_combination_parameters(), [
            'templateid' => $templateid,
            'combination' => $combination,
        ]);

        $template = self::require_editable($params['templateid']);

        if (!style_library::exists($params['combination'])) {
            throw new \moodle_exception('errorunknownstyle', 'mod_certificatepro', '',
                $params['combination']);
        }

        $available = self::available_families();
        $map = self::role_map();
        $changed = 0;
        $substituted = [];

        foreach ($template->get_pages() as $page) {
            foreach ($template->get_elements((int) $page->id) as $element) {
                if (!($element instanceof text_base)) {
                    continue;
                }

                $style = $element->get_style();
                $scalerole = (string) ($style['scalerole'] ?? 'body');
                [$role, $damp] = $map[$scalerole] ?? ['body', 1.0];

                $spec = style_library::resolve($params['combination'], $role, $available);
                if ($spec === null) {
                    continue;
                }

                if (!empty($spec['substituted'])) {
                    $substituted[$spec['substituted']] = true;
                }

                $size = self::points($template, (float) $spec['size'], $damp);

                $style['font'] = font_manager::sanitise_family($spec['family']);
                $style['size'] = $size;
                $style['colour'] = $spec['colour'];
                $style['bold'] = ((int) $spec['weight']) >= 600;
                // Tracking is stored in points because that is what TCPDF's
                // setFontSpacing takes, while a combination states it in ems.
                $style['tracking'] = round(((float) $spec['tracking']) * $size, 3);
                $style['case'] = self::case_for($spec['case']);

                $DB->update_record('certificatepro_elements', (object) [
                    'id' => $element->get_id(),
                    'style' => json_encode($style, JSON_PRESERVE_ZERO_FRACTION),
                    'timemodified' => time(),
                ]);

                $changed++;
            }
        }

        $template->touch();

        \mod_certificatepro\event\template_updated::create([
            'objectid' => $template->get_id(),
            'context' => $template->get_context(),
        ])->trigger();

        return [
            'changed' => $changed,
            'substituted' => implode(', ', array_keys($substituted)),
        ];
    }

    /**
     * Translate a combination's case treatment into one an element can store.
     *
     * Small capitals are a font feature the PDF pipeline cannot synthesise, so
     * a combination asking for them gets capitals rather than nothing.
     *
     * @param string $case the combination's case treatment
     * @return string a case an element understands
     */
    protected static function case_for(string $case): string {
        return match ($case) {
            'upper', 'smallcaps' => 'upper',
            'title' => 'title',
            default => 'none',
        };
    }

    /**
     * What applying a combination returns.
     *
     * @return external_single_structure
     */
    public static function apply_style_combination_returns(): external_single_structure {
        return new external_single_structure([
            'changed' => new external_value(PARAM_INT, 'How many elements were restyled'),
            'substituted' => new external_value(PARAM_TEXT,
                'Families that were not installed and were substituted'),
        ]);
    }
}
