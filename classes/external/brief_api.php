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
use mod_certificatepro\local\ai\artwork_brief;
use mod_certificatepro\local\ai\background_repository;
use mod_certificatepro\local\ai\generator;
use mod_certificatepro\local\ai\seal_brief;
use mod_certificatepro\local\art\background_library;
use mod_certificatepro\local\art\canvas;
use mod_certificatepro\local\art\seal_engine;
use mod_certificatepro\local\art\svg_painter;
use mod_certificatepro\template;

/**
 * The services behind the two guided wizards.
 *
 * Neither wizard asks an author to write a prompt. They answer questions the
 * domain classes define, and those answers compose both the model instruction
 * and the procedural fallback, so a site with no model configured gets the same
 * conversation and a real result at the end of it.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class brief_api extends library_base {
    /** @var float The diameter a seal is previewed and placed at, in millimetres. */
    public const SEAL_DIAMETER = 34.0;

    /**
     * Regroup the flat answer list the browser sends into the shape a brief takes.
     *
     * A question that accepts several answers arrives as several entries with
     * the same name, which is how an HTML form would send it too.
     *
     * @param array $answers the submitted name and value pairs
     * @param array $fields the brief's field definitions
     * @return array values keyed by field name
     */
    protected static function collect(array $answers, array $fields): array {
        $out = [];

        foreach ($answers as $answer) {
            $name = (string) $answer['name'];
            if (!isset($fields[$name])) {
                continue;
            }

            if (!empty($fields[$name]['multiple'])) {
                $out[$name][] = (string) $answer['value'];
            } else {
                $out[$name] = (string) $answer['value'];
            }
        }

        return $out;
    }

    /**
     * The structure a wizard's answers arrive in.
     *
     * @return external_multiple_structure
     */
    protected static function answers_structure(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure(
                [
                    'name' => new external_value(PARAM_ALPHANUMEXT, 'The question'),
                    'value' => new external_value(PARAM_TEXT, 'The answer'),
                ]
            )
        );
    }

    /**
     * The structure a wizard's questions come back in.
     *
     * @return external_multiple_structure
     */
    protected static function questions_structure(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'name' => new external_value(PARAM_ALPHANUMEXT, 'The field name'),
                'label' => new external_value(PARAM_TEXT, 'The question'),
                'type' => new external_value(PARAM_ALPHA, 'choice, text or year'),
                'multiple' => new external_value(PARAM_BOOL, 'Whether several answers are allowed'),
                'required' => new external_value(PARAM_BOOL, 'Whether an answer is required'),
                'maxlength' => new external_value(PARAM_INT, 'The character limit, 0 for none'),
                'options' => new external_multiple_structure(
                    new external_single_structure([
                        'value' => new external_value(
                            PARAM_RAW, // pipeline-ignore: PARAM_RAW - opaque catalogue id
                            'The option value'
                        ),
                        'label' => new external_value(PARAM_TEXT, 'The option text'),
                        'selected' => new external_value(PARAM_BOOL, 'Whether it is the default'),
                    ])
                ),
            ])
        );
    }

    /**
     * Turn a brief's field definitions into questions a wizard can draw.
     *
     * @param array $fields the field definitions
     * @param string $prefix the language string prefix for option labels
     * @return array
     */
    protected static function questions(array $fields, string $prefix): array {
        $questions = [];

        foreach ($fields as $name => $field) {
            $options = [];
            $default = $field['default'] ?? '';

            foreach (($field['options'] ?? []) ?: [] as $value => $text) {
                $options[] = [
                    'value' => (string) $value,
                    'label' => self::label($prefix . $name . '_' . $value, (string) $text),
                    'selected' => is_array($default)
                        ? in_array($value, $default, true)
                        : ($value === $default),
                ];
            }

            $type = (string) ($field['type'] ?? 'choice');
            if (empty($field['options'])) {
                $type = $type === 'choice' ? 'text' : $type;
            }

            $questions[] = [
                'name' => $name,
                'label' => get_string($field['label'], 'mod_certificatepro'),
                'type' => $type,
                'multiple' => !empty($field['multiple']),
                'required' => !empty($field['required']),
                'maxlength' => (int) ($field['limit'] ?? 0),
                'options' => $options,
            ];
        }

        return $questions;
    }

    // ------------------------------------------------------------------
    // The artwork wizard.
    // ------------------------------------------------------------------

    /**
     * Parameters for reading the artwork questions.
     *
     * @return external_function_parameters
     */
    public static function get_artwork_brief_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            ]
        );
    }

    /**
     * The questions the artwork wizard asks.
     *
     * @param int $templateid the design being edited
     * @return array
     */
    public static function get_artwork_brief(int $templateid): array {
        $params = self::validate_parameters(
            self::get_artwork_brief_parameters(),
            [
                'templateid' => $templateid,
            ]
        );

        $template = self::require_readable($params['templateid']);

        return [
            'questions' => self::questions(artwork_brief::fields(), 'briefopt_'),
            'canusemodel' => has_capability(
                'mod/certificatepro:generateartwork',
                $template->get_context()
            ),
        ];
    }

    /**
     * What reading the artwork questions returns.
     *
     * @return external_single_structure
     */
    public static function get_artwork_brief_returns(): external_single_structure {
        return new external_single_structure(
            [
                'questions' => self::questions_structure(),
                'canusemodel' => new external_value(
                    PARAM_BOOL,
                    'Whether this author may ask a model for artwork'
                ),
            ]
        );
    }

    /**
     * Parameters for previewing an artwork brief.
     *
     * @return external_function_parameters
     */
    public static function preview_artwork_brief_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'templateid' => new external_value(PARAM_INT, 'The design being edited'),
                'answers' => self::answers_structure(),
            ]
        );
    }

    /**
     * Show what a set of artwork answers resolves to, without saving anything.
     *
     * This is the procedural answer, which is what the author gets if no model
     * is configured and what they fall back to if one is and it fails. Showing
     * it before generating means the wizard never promises what it cannot draw.
     *
     * @param int $templateid the design being edited
     * @param array $answers the submitted answers
     * @return array
     */
    public static function preview_artwork_brief(int $templateid, array $answers): array {
        $params = self::validate_parameters(
            self::preview_artwork_brief_parameters(),
            [
                'templateid' => $templateid,
                'answers' => $answers,
            ]
        );

        $template = self::require_readable($params['templateid']);
        $page = self::require_page($template, 0);

        $brief = artwork_brief::from_input(
            self::collect($params['answers'], artwork_brief::fields())
        );
        $style = $brief->to_procedural_style();
        $entry = background_library::styles()[$style] ?? null;

        return [
            'style' => $style,
            'stylename' => $entry === null
                ? $style
                : self::label('bgstyle_' . $style, $entry['name']),
            'summary' => $brief->summary(),
            'svg' => background_library::svg(
                $style,
                $template->get_palette(),
                (float) $page->width,
                (float) $page->height,
                7
            ),
        ];
    }

    /**
     * What previewing an artwork brief returns.
     *
     * @return external_single_structure
     */
    public static function preview_artwork_brief_returns(): external_single_structure {
        return new external_single_structure([
            'style' => new external_value(PARAM_ALPHANUMEXT, 'The matched procedural style'),
            'stylename' => new external_value(PARAM_TEXT, 'Its display name'),
            'summary' => new external_value(PARAM_TEXT, 'What the brief asked for'),
            'svg' => new external_value(PARAM_RAW, 'The preview as SVG'), // pipeline-ignore: PARAM_RAW - sanitised SVG
        ]);
    }

    /**
     * Parameters for generating from an artwork brief.
     *
     * @return external_function_parameters
     */
    public static function apply_artwork_brief_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'templateid' => new external_value(PARAM_INT, 'The design being edited'),
                'pageid' => new external_value(PARAM_INT, 'The page to place it on'),
                'answers' => self::answers_structure(),
                'usemodel' => new external_value(
                    PARAM_BOOL,
                    'Whether a model may be asked',
                    VALUE_DEFAULT,
                    true
                ),
            ]
        );
    }

    /**
     * Generate artwork from a brief and place it on a page.
     *
     * @param int $templateid the design being edited
     * @param int $pageid the page to place it on
     * @param array $answers the submitted answers
     * @param bool $usemodel whether a model may be asked
     * @return array
     */
    public static function apply_artwork_brief(int $templateid, int $pageid, array $answers,
            bool $usemodel = true): array {
        $params = self::validate_parameters(
            self::apply_artwork_brief_parameters(),
            [
                'templateid' => $templateid,
                'pageid' => $pageid,
                'answers' => $answers,
                'usemodel' => $usemodel,
            ]
        );

        $template = self::require_editable($params['templateid']);
        $page = self::require_page($template, $params['pageid']);
        $context = $template->get_context();

        require_capability('mod/certificatepro:generateartwork', $context);

        $brief = artwork_brief::from_input(
            self::collect($params['answers'], artwork_brief::fields())
        );
        $style = $brief->to_procedural_style();
        $palette = $template->get_palette();
        $name = $brief->summary();

        $result = ['id' => 0, 'svg' => '', 'source' => generator::SOURCE_PROCEDURAL,
            'notice' => ''];

        if ($params['usemodel']) {
            $result = background_repository::create(
                $context,
                $name,
                $brief->to_prompt($palette, (float) $page->width, (float) $page->height),
                $style,
                $page->orientation === 'P' ? 'P' : 'L',
                [
                    'seed' => 7,
                    'intensity' => 0.5,
                    'allowmodel' => true,
                    'palette' => $palette,
                ]
            );
        }

        // The shared generator's own fallback answers to an older and much
        // smaller style vocabulary, and it assumes an A4 page, so a brief that
        // matched "Guilloche Border" on a non-A4 sheet would quietly come back
        // as a plain wash at the wrong size. Whenever no model produced
        // anything, draw the style the brief actually matched instead.
        $result = self::ensure_procedural($context, $result, $style, $name, $palette, $page);

        $elementid = self::place_artwork($template, $page, (int) $result['id']);

        \mod_certificatepro\event\artwork_generated::create(
            [
                'objectid' => (int) $result['id'],
                'context' => $context,
            ]
        )->trigger();

        return [
            'backgroundid' => (int) $result['id'],
            'elementid' => $elementid,
            'source' => (string) $result['source'],
            'notice' => (string) ($result['notice'] ?? ''),
            'svg' => (string) $result['svg'],
        ];
    }

    /**
     * Make sure a procedural result really is the style the brief matched.
     *
     * Leaves a model's answer untouched. Replaces a fallback, storing a new
     * background when nothing was stored yet and rewriting the stored markup
     * when something was.
     *
     * @param \context $context the context that owns the background
     * @param array $result the generator result
     * @param string $style the style the brief matched
     * @param string $name the author facing name
     * @param array $palette the design's palette
     * @param \stdClass $page the page it is going on
     * @return array the result, with the drawn artwork
     */
    protected static function ensure_procedural(\context $context, array $result, string $style,
            string $name, array $palette, \stdClass $page): array {
        global $DB;

        if ($result['source'] !== generator::SOURCE_PROCEDURAL) {
            return $result;
        }

        $svg = background_library::svg(
            $style,
            $palette,
            (float) $page->width,
            (float) $page->height,
            7
        );

        if ($svg === '') {
            // An unknown style. Whatever the generator produced is still better
            // than an empty page.
            return $result;
        }

        if ((int) $result['id'] > 0) {
            $DB->set_field('certificatepro_backgrounds', 'svg', $svg, ['id' => (int) $result['id']]);
        } else {
            $result['id'] = background_repository::save(
                $context,
                $name,
                $svg,
                [
                    'prompt' => '',
                    'style' => $style,
                    'palette' => $palette['primary'] ?? '',
                    'orientation' => $page->orientation,
                    'generator' => generator::SOURCE_PROCEDURAL,
                ]
            );
        }

        $result['svg'] = $svg;

        return $result;
    }

    /**
     * Point a page's artwork element at a stored background, creating it if needed.
     *
     * @param template $template the design
     * @param \stdClass $page the page
     * @param int $backgroundid the stored background
     * @return int the artwork element id
     */
    protected static function place_artwork(template $template, \stdClass $page,
            int $backgroundid): int {
        global $DB;

        $existing = $DB->get_records(
            'certificatepro_elements',
            ['pageid' => (int) $page->id, 'element' => 'artwork'],
            'id ASC'
        );

        $record = reset($existing);

        if ($record === false) {
            $elementid = $template->add_element((int) $page->id, 'artwork');
            $record = $DB->get_record(
                'certificatepro_elements',
                ['id' => $elementid],
                '*',
                MUST_EXIST
            );
        }

        $data = json_decode((string) $record->data, true);
        $data = is_array($data) ? $data : [];
        $data['backgroundid'] = $backgroundid;
        $data['opacity'] = isset($data['opacity']) ? (float) $data['opacity'] : 1.0;

        $DB->update_record(
            'certificatepro_elements',
            (object) [
                'id' => (int) $record->id,
                'data' => json_encode($data, JSON_PRESERVE_ZERO_FRACTION),
                'posx' => 0,
                'posy' => 0,
                'width' => round((float) $page->width, 3),
                'height' => round((float) $page->height, 3),
                'refpoint' => 'tl',
                'zindex' => -100,
                'timemodified' => time(),
            ]
        );

        $template->touch();

        return (int) $record->id;
    }

    /**
     * What generating from an artwork brief returns.
     *
     * @return external_single_structure
     */
    public static function apply_artwork_brief_returns(): external_single_structure {
        return new external_single_structure([
            'backgroundid' => new external_value(PARAM_INT, 'The stored background id'),
            'elementid' => new external_value(PARAM_INT, 'The artwork element it was placed on'),
            'source' => new external_value(PARAM_ALPHANUMEXT, 'Which route produced it'),
            'notice' => new external_value(PARAM_TEXT, 'A message for the author, or empty'),
            'svg' => new external_value(PARAM_RAW, 'The artwork as SVG'), // pipeline-ignore: PARAM_RAW - sanitised SVG
        ]);
    }

    // ------------------------------------------------------------------
    // The seal wizard.
    // ------------------------------------------------------------------

    /**
     * Parameters for reading the seal questions.
     *
     * @return external_function_parameters
     */
    public static function get_seal_brief_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            ]
        );
    }

    /**
     * The questions the seal wizard asks.
     *
     * @param int $templateid the design being edited
     * @return array
     */
    public static function get_seal_brief(int $templateid): array {
        $params = self::validate_parameters(
            self::get_seal_brief_parameters(),
            [
                'templateid' => $templateid,
            ]
        );

        self::require_readable($params['templateid']);

        return ['questions' => self::questions(seal_brief::fields(), 'sealopt_')];
    }

    /**
     * What reading the seal questions returns.
     *
     * @return external_single_structure
     */
    public static function get_seal_brief_returns(): external_single_structure {
        return new external_single_structure(
            [
                'questions' => self::questions_structure(),
            ]
        );
    }

    /**
     * Parameters for previewing a seal brief.
     *
     * @return external_function_parameters
     */
    public static function preview_seal_brief_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'templateid' => new external_value(PARAM_INT, 'The design being edited'),
                'answers' => self::answers_structure(),
            ]
        );
    }

    /**
     * Draw the seal a set of answers describes, without saving anything.
     *
     * @param int $templateid the design being edited
     * @param array $answers the submitted answers
     * @return array
     */
    public static function preview_seal_brief(int $templateid, array $answers): array {
        $params = self::validate_parameters(
            self::preview_seal_brief_parameters(),
            [
                'templateid' => $templateid,
                'answers' => $answers,
            ]
        );

        $template = self::require_readable($params['templateid']);
        $palette = $template->get_palette();

        $brief = seal_brief::from_input(
            self::collect($params['answers'], seal_brief::fields())
        );
        $data = self::seal_data($brief);

        $warnings = [];
        foreach ($brief->warnings() as $warning) {
            $warnings[] = ['message' => get_string(
                $warning['key'],
                'mod_certificatepro',
                $warning['a']
            )];
        }

        return [
            'svg' => self::seal_svg($data, $palette),
            'edge' => $data['sealedge'],
            'ring' => $data['sealring'],
            'mark' => $data['sealmark'],
            'edgename' => get_string('sealedge_' . $data['sealedge'], 'mod_certificatepro'),
            'ringname' => get_string('sealring_' . $data['sealring'], 'mod_certificatepro'),
            'markname' => get_string('sealmark_' . $data['sealmark'], 'mod_certificatepro'),
            'warnings' => $warnings,
        ];
    }

    /**
     * What previewing a seal brief returns.
     *
     * @return external_single_structure
     */
    public static function preview_seal_brief_returns(): external_single_structure {
        return new external_single_structure([
            'svg' => new external_value(PARAM_RAW, 'The seal as SVG'), // pipeline-ignore: PARAM_RAW - sanitised SVG
            'edge' => new external_value(PARAM_ALPHA, 'The chosen edge treatment'),
            'ring' => new external_value(PARAM_ALPHA, 'The chosen ring layout'),
            'mark' => new external_value(PARAM_ALPHA, 'The chosen centre mark'),
            'edgename' => new external_value(PARAM_TEXT, 'The edge treatment name'),
            'ringname' => new external_value(PARAM_TEXT, 'The ring layout name'),
            'markname' => new external_value(PARAM_TEXT, 'The centre mark name'),
            'warnings' => new external_multiple_structure(
                new external_single_structure(
                    [
                        'message' => new external_value(PARAM_TEXT, 'What the author should know'),
                    ]
                )
            ),
        ]);
    }

    /**
     * Parameters for placing a seal.
     *
     * @return external_function_parameters
     */
    public static function apply_seal_brief_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'templateid' => new external_value(PARAM_INT, 'The design being edited'),
                'pageid' => new external_value(PARAM_INT, 'The page to place it on'),
                'answers' => self::answers_structure(),
                'elementid' => new external_value(
                    PARAM_INT,
                    'An existing seal element to restyle, or 0 to add one',
                    VALUE_DEFAULT,
                    0
                ),
            ]
        );
    }

    /**
     * Place, or restyle, a seal from a brief.
     *
     * @param int $templateid the design being edited
     * @param int $pageid the page to place it on
     * @param array $answers the submitted answers
     * @param int $elementid an existing seal to restyle, or 0
     * @return array
     */
    public static function apply_seal_brief(int $templateid, int $pageid, array $answers,
            int $elementid = 0): array {
        global $DB;

        $params = self::validate_parameters(
            self::apply_seal_brief_parameters(),
            [
                'templateid' => $templateid,
                'pageid' => $pageid,
                'answers' => $answers,
                'elementid' => $elementid,
            ]
        );

        $template = self::require_editable($params['templateid']);
        $page = self::require_page($template, $params['pageid']);

        $brief = seal_brief::from_input(
            self::collect($params['answers'], seal_brief::fields())
        );
        $data = self::seal_data($brief);

        $record = false;
        if ($params['elementid'] > 0) {
            $record = $DB->get_record(
                'certificatepro_elements',
                [
                    'id' => $params['elementid'],
                    'pageid' => (int) $page->id,
                    'element' => 'seal',
                ]
            );
        }

        if ($record === false) {
            // Starter designs already carry a seal, and a wizard that adds a
            // second one on top of it produces a design that looks broken and
            // an author who cannot see why. Restyle the seal that is there.
            $existing = $DB->get_records(
                'certificatepro_elements',
                ['pageid' => (int) $page->id, 'element' => 'seal'],
                'id ASC',
                '*',
                0,
                1
            );
            $record = reset($existing);
        }

        $added = $record === false;

        if ($added) {
            $newid = $template->add_element((int) $page->id, 'seal');
            $record = $DB->get_record(
                'certificatepro_elements',
                ['id' => $newid],
                '*',
                MUST_EXIST
            );
            $record->posy = round((float) $page->height * 0.78, 3);
            $record->posx = round((float) $page->width * 0.5, 3);
            $record->width = self::SEAL_DIAMETER;
            $record->height = self::SEAL_DIAMETER;
            $record->refpoint = 'mc';
        }

        $name = $brief->answers()['organisation'];

        // An existing seal keeps where it sits and how big it is. The wizard
        // decides what the seal says and how it is drawn, not where the author
        // put it.
        $DB->update_record(
            'certificatepro_elements',
            (object) [
                'id' => (int) $record->id,
                'name' => $name !== '' ? $name : get_string('sealbriefdefaultname', 'mod_certificatepro'),
                'data' => json_encode($data, JSON_PRESERVE_ZERO_FRACTION),
                'width' => (float) $record->width,
                'height' => (float) $record->height,
                'posx' => (float) $record->posx,
                'posy' => (float) $record->posy,
                'refpoint' => $record->refpoint,
                'timemodified' => time(),
            ]
        );

        $template->touch();

        \mod_certificatepro\event\template_updated::create(
            [
                'objectid' => $template->get_id(),
                'context' => $template->get_context(),
            ]
        )->trigger();

        return ['elementid' => (int) $record->id, 'added' => $added];
    }

    /**
     * What placing a seal returns.
     *
     * @return external_single_structure
     */
    public static function apply_seal_brief_returns(): external_single_structure {
        return new external_single_structure(
            [
                'elementid' => new external_value(PARAM_INT, 'The seal element id'),
                'added' => new external_value(
                    PARAM_BOOL,
                    'Whether a seal was added, rather than an existing one restyled'
                ),
            ]
        );
    }

    /**
     * Map a seal brief onto the settings the seal element and engine understand.
     *
     * The brief predates the engine and describes a seal in the terms a person
     * uses: a silhouette, a drawing style, something in the middle. The engine
     * offers nine edge treatments, four ring layouts and nine centre marks. This
     * is where the one becomes the other.
     *
     * @param seal_brief $brief the answered brief
     * @return array the element's stored settings
     */
    protected static function seal_data(seal_brief $brief): array {
        $a = $brief->answers();
        $ring = $brief->ring_text();

        $motto = $a['motto'] !== '' ? \core_text::strtoupper($a['motto']) : '';
        $bottom = $motto;
        if ($bottom === '' && $a['established'] !== '') {
            $bottom = 'EST. ' . $a['established'];
        }

        return [
            'sealedge' => self::seal_edge($a),
            'sealring' => self::seal_ring($a, $ring['top'], $bottom),
            'sealmark' => self::seal_mark($a),
            'toptext' => $ring['top'],
            'bottomtext' => $bottom,
            'centretext' => self::seal_centre($a, $brief),
            'yeartext' => $a['established'],
            'mottotext' => $motto,
            'colour' => 'primary',
            'metal' => 'accent',
            'sealdivider' => $bottom !== '' && $ring['top'] !== '' ? 1 : 0,
            // The engine takes a numeric weight, but a seal element does not
            // store one, so a placed seal always draws at weight 1. Rather than
            // preview something the design cannot keep, the weight question is
            // answered with the settings that do survive: a light seal loses
            // its inner keyline, a strong one keeps it.
            'sealkeyline' => self::seal_keyline($a),
        ];
    }

    /**
     * Whether a brief wants the inner keyline.
     *
     * @param array $a the validated answers
     * @return int 1 or 0
     */
    protected static function seal_keyline(array $a): int {
        if ($a['weight'] === 'light') {
            return 0;
        }
        if ($a['weight'] === 'strong') {
            return 1;
        }
        return in_array($a['style'], ['engraved', 'layered', 'geometric'], true) ? 1 : 0;
    }

    /**
     * The edge treatment a brief asks for.
     *
     * The silhouette question leads, because that is the thing the author
     * actually looked at when they chose. A plain circle carries no opinion of
     * its own, so for that one answer the drawing style decides instead.
     *
     * @param array $a the validated answers
     * @return string an edge constant
     */
    protected static function seal_edge(array $a): string {
        if ($a['shape'] !== 'circle') {
            return match ($a['shape']) {
                'oval' => seal_engine::EDGE_DOUBLE,
                'shield' => seal_engine::EDGE_ROPE,
                'hexagon' => seal_engine::EDGE_COG,
                'roundel' => seal_engine::EDGE_DOTTED,
                'starburst' => seal_engine::EDGE_STARBURST,
                default => seal_engine::EDGE_DOUBLE,
            };
        }

        return match ($a['style']) {
            'minimal', 'wordmark' => seal_engine::EDGE_PLAIN,
            'monoline' => seal_engine::EDGE_DOTTED,
            'geometric' => seal_engine::EDGE_COG,
            'layered' => seal_engine::EDGE_SCALLOP,
            default => seal_engine::EDGE_DOUBLE,
        };
    }

    /**
     * The ring layout a brief asks for.
     *
     * @param array $a the validated answers
     * @param string $top the upper arc text
     * @param string $bottom the lower arc text
     * @return string a ring constant
     */
    protected static function seal_ring(array $a, string $top, string $bottom): string {
        if ($top === '') {
            return seal_engine::RING_NONE;
        }
        if ($a['style'] === 'wordmark') {
            // The name is the seal. Setting it around the ring as well would
            // say it twice.
            return seal_engine::RING_NONE;
        }
        return $bottom !== '' ? seal_engine::RING_BOTH : seal_engine::RING_TOP;
    }

    /**
     * The centre mark a brief asks for.
     *
     * @param array $a the validated answers
     * @return string a mark constant
     */
    protected static function seal_mark(array $a): string {
        if ($a['style'] === 'wordmark') {
            return seal_engine::MARK_WORDMARK;
        }

        // A solid disc with the mark reversed out of it is how the engine says
        // "loud", which is what a layered drawing style and a strong weight
        // both ask for.
        $solid = $a['style'] === 'layered' || $a['weight'] === 'strong';

        return match ($a['emblem']) {
            'none' => seal_engine::MARK_NONE,
            'star' => seal_engine::MARK_STAR,
            'abstract' => $solid ? seal_engine::MARK_REVERSED : seal_engine::MARK_STAR,
            'symbolic' => self::mark_for_subject($a['subject']),
            default => $solid ? seal_engine::MARK_REVERSED : seal_engine::MARK_MONOGRAM,
        };
    }

    /**
     * The charge that best stands for what an organisation does.
     *
     * The engine draws a fixed set of marks, so "a simple symbol drawn from
     * what the organisation does" has to land on one of them. Matching on the
     * words the author already typed is better than making them choose from a
     * list of heraldic charges they have no reason to know.
     *
     * @param string $subject what the organisation does, in the author's words
     * @return string a mark constant
     */
    protected static function mark_for_subject(string $subject): string {
        $subject = \core_text::strtolower($subject);

        $groups = [
            seal_engine::MARK_BOOK => ['school', 'college', 'university', 'academy', 'educat',
                'learn', 'teach', 'librar', 'literac', 'study', 'student'],
            seal_engine::MARK_TORCH => ['science', 'research', 'innovat', 'engineer', 'technolog',
                'energy', 'leader', 'discover', 'medic', 'health'],
            seal_engine::MARK_SHIELD => ['govern', 'civic', 'council', 'authorit', 'institut',
                'safety', 'secur', 'defence', 'defense', 'law', 'compliance'],
            seal_engine::MARK_CREST => ['heritage', 'tradition', 'honour', 'honor', 'guild',
                'society', 'fellowship', 'order', 'chapter'],
        ];

        foreach ($groups as $mark => $needles) {
            foreach ($needles as $needle) {
                if ($subject !== '' && \core_text::strpos($subject, $needle) !== false) {
                    return $mark;
                }
            }
        }

        return seal_engine::MARK_BOOK;
    }

    /**
     * The text that sits in the middle of the seal.
     *
     * @param array $a the validated answers
     * @param seal_brief $brief the answered brief
     * @return string
     */
    protected static function seal_centre(array $a, seal_brief $brief): string {
        if ($a['style'] === 'wordmark') {
            return \core_text::strtoupper($a['organisation']);
        }
        if ($a['emblem'] === 'monogram') {
            return $brief->initials();
        }
        return '';
    }

    /**
     * Draw a seal from stored settings.
     *
     * @param array $data the element settings
     * @param array $palette the design's palette
     * @return string the SVG markup
     */
    protected static function seal_svg(array $data, array $palette): string {
        $size = self::SEAL_DIAMETER;
        $canvas = new canvas($size, $size, $palette, 7);

        seal_engine::draw(
            $canvas,
            0.5,
            0.5,
            $size / 2.0,
            [
                'edge' => $data['sealedge'],
                'ring' => $data['sealring'],
                'mark' => $data['sealmark'],
                'top' => $data['toptext'],
                'bottom' => $data['bottomtext'],
                'centre' => $data['centretext'],
                'year' => $data['yeartext'],
                'motto' => $data['mottotext'],
                'ink' => $palette[$data['colour']] ?? ($palette['primary'] ?? '#1b3a5c'),
                'metal' => $palette[$data['metal']] ?? ($palette['accent'] ?? '#b08432'),
                'paper' => $palette['paper'] ?? '#ffffff',
                'family' => 'helvetica',
                'divider' => (bool) $data['sealdivider'],
                'innerkeyline' => (bool) $data['sealkeyline'],
                'weight' => 1.0,
            ]
        );

        return (new svg_painter('cpseal'))->render($canvas->ops(), $size, $size);
    }
}
