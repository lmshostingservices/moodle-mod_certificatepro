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
use mod_certificatepro\local\font_manager;
use mod_certificatepro\local\typography;
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
            new external_single_structure([
                'name' => new external_value(PARAM_ALPHANUMEXT, 'The question'),
                'value' => new external_value(PARAM_TEXT, 'The answer'),
            ])
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
                            'The option value'),
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
                    'label' => self::option_label($field, $prefix . $name . '_' . $value,
                        (string) $value, (string) $text),
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

    /**
     * The display name of one option.
     *
     * A field names up to two language string prefixes. The first is the short
     * name a thumbnail wants — a tile under a picture has room for "Top and
     * bottom", not for "Name around the top, legend around the bottom". The
     * second is the longer name the element settings form already uses, which
     * is both the fallback when a site has not got the short one and the reason
     * the wizard and the settings form never call the same edge two things.
     * After those, the wizard's own key, then the catalogue's own English.
     *
     * @param array $field the field definition
     * @param string $fallbackkey the wizard's own key for this option
     * @param string $value the option value
     * @param string $english the catalogue's own English, possibly empty
     * @return string
     */
    protected static function option_label(array $field, string $fallbackkey, string $value,
            string $english): string {
        foreach ([$field['prefix'] ?? '', $field['altprefix'] ?? ''] as $prefix) {
            if ($prefix !== '' && get_string_manager()->string_exists($prefix . $value,
                    'mod_certificatepro')) {
                return get_string($prefix . $value, 'mod_certificatepro');
            }
        }

        return self::label($fallbackkey, $english !== '' ? $english : $value);
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
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
        ]);
    }

    /**
     * The questions the artwork wizard asks.
     *
     * @param int $templateid the design being edited
     * @return array
     */
    public static function get_artwork_brief(int $templateid): array {
        $params = self::validate_parameters(self::get_artwork_brief_parameters(), [
            'templateid' => $templateid,
        ]);

        $template = self::require_readable($params['templateid']);

        return [
            'questions' => self::questions(artwork_brief::fields(), 'briefopt_'),
            'canusemodel' => has_capability('mod/certificatepro:generateartwork',
                $template->get_context()),
        ];
    }

    /**
     * What reading the artwork questions returns.
     *
     * @return external_single_structure
     */
    public static function get_artwork_brief_returns(): external_single_structure {
        return new external_single_structure([
            'questions' => self::questions_structure(),
            'canusemodel' => new external_value(PARAM_BOOL,
                'Whether this author may ask a model for artwork'),
        ]);
    }

    /**
     * Parameters for previewing an artwork brief.
     *
     * @return external_function_parameters
     */
    public static function preview_artwork_brief_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'answers' => self::answers_structure(),
        ]);
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
        $params = self::validate_parameters(self::preview_artwork_brief_parameters(), [
            'templateid' => $templateid,
            'answers' => $answers,
        ]);

        $template = self::require_readable($params['templateid']);
        $page = self::require_page($template, 0);

        $brief = artwork_brief::from_input(
            self::collect($params['answers'], artwork_brief::fields()));
        $style = $brief->to_procedural_style();
        $entry = background_library::styles()[$style] ?? null;

        return [
            'style' => $style,
            'stylename' => $entry === null
                ? $style
                : self::label('bgstyle_' . $style, $entry['name']),
            'summary' => $brief->summary(),
            'svg' => background_library::svg($style, $template->get_palette(),
                (float) $page->width, (float) $page->height, 7),
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
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'pageid' => new external_value(PARAM_INT, 'The page to place it on'),
            'answers' => self::answers_structure(),
            'usemodel' => new external_value(PARAM_BOOL, 'Whether a model may be asked',
                VALUE_DEFAULT, true),
            'variant' => new external_value(PARAM_TEXT, // style~seed; decode_variant() keeps only tokens the catalogue knows
                'The variant the author picked, or an empty string for the brief itself',
                VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Generate artwork from a brief and place it on a page.
     *
     * The author chose one of nine drawings. Placing anything other than the
     * one they clicked would make the variant grid a decoration, so the chosen
     * style and seed win over the brief's own first answer whenever a variant
     * key survives decoding.
     *
     * @param int $templateid the design being edited
     * @param int $pageid the page to place it on
     * @param array $answers the submitted answers
     * @param bool $usemodel whether a model may be asked
     * @param string $variant the chosen variant key, or an empty string
     * @return array
     */
    public static function apply_artwork_brief(int $templateid, int $pageid, array $answers,
            bool $usemodel = true, string $variant = ''): array {
        $params = self::validate_parameters(self::apply_artwork_brief_parameters(), [
            'templateid' => $templateid,
            'pageid' => $pageid,
            'answers' => $answers,
            'usemodel' => $usemodel,
            'variant' => $variant,
        ]);

        $template = self::require_editable($params['templateid']);
        $page = self::require_page($template, $params['pageid']);
        $context = $template->get_context();

        require_capability('mod/certificatepro:generateartwork', $context);

        $brief = artwork_brief::from_input(
            self::collect($params['answers'], artwork_brief::fields()));
        $chosen = artwork_brief::decode_variant((string) $params['variant']);
        $style = $chosen === [] ? $brief->to_procedural_style() : $chosen['style'];
        $seed = $chosen === [] ? artwork_brief::BASE_SEED : (int) $chosen['seed'];
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
                    'seed' => $seed,
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
        $result = self::ensure_procedural($context, $result, $style, $name, $palette, $page, $seed);

        $elementid = self::place_artwork($template, $page, (int) $result['id']);

        \mod_certificatepro\event\artwork_generated::create([
            'objectid' => (int) $result['id'],
            'context' => $context,
        ])->trigger();

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
     * @param int $seed the composition seed the author chose
     * @return array the result, with the drawn artwork
     */
    protected static function ensure_procedural(\context $context, array $result, string $style,
            string $name, array $palette, \stdClass $page, int $seed = 7): array {
        global $DB;

        if ($result['source'] !== generator::SOURCE_PROCEDURAL) {
            return $result;
        }

        $svg = background_library::svg($style, $palette,
            (float) $page->width, (float) $page->height, max(1, $seed));

        if ($svg === '') {
            // An unknown style. Whatever the generator produced is still better
            // than an empty page.
            return $result;
        }

        if ((int) $result['id'] > 0) {
            $DB->set_field('certificatepro_backgrounds', 'svg', $svg, ['id' => (int) $result['id']]);
        } else {
            $result['id'] = background_repository::save($context, $name, $svg, [
                'prompt' => '',
                'style' => $style,
                'palette' => $palette['primary'] ?? '',
                'orientation' => $page->orientation,
                'generator' => generator::SOURCE_PROCEDURAL,
            ]);
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

        $existing = $DB->get_records('certificatepro_elements',
            ['pageid' => (int) $page->id, 'element' => 'artwork'], 'id ASC');

        $record = reset($existing);

        if ($record === false) {
            $elementid = $template->add_element((int) $page->id, 'artwork');
            $record = $DB->get_record('certificatepro_elements', ['id' => $elementid],
                '*', MUST_EXIST);
        }

        $data = json_decode((string) $record->data, true);
        $data = is_array($data) ? $data : [];
        $data['backgroundid'] = $backgroundid;
        $data['opacity'] = isset($data['opacity']) ? (float) $data['opacity'] : 1.0;

        $DB->update_record('certificatepro_elements', (object) [
            'id' => (int) $record->id,
            'data' => json_encode($data, JSON_PRESERVE_ZERO_FRACTION),
            'posx' => 0,
            'posy' => 0,
            'width' => round((float) $page->width, 3),
            'height' => round((float) $page->height, 3),
            'refpoint' => 'tl',
            'zindex' => -100,
            'timemodified' => time(),
        ]);

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
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
        ]);
    }

    /**
     * The questions the seal wizard asks.
     *
     * @param int $templateid the design being edited
     * @return array
     */
    public static function get_seal_brief(int $templateid): array {
        $params = self::validate_parameters(self::get_seal_brief_parameters(), [
            'templateid' => $templateid,
        ]);

        self::require_readable($params['templateid']);

        return ['questions' => self::questions(seal_brief::fields(), 'sealopt_')];
    }

    /**
     * What reading the seal questions returns.
     *
     * @return external_single_structure
     */
    public static function get_seal_brief_returns(): external_single_structure {
        return new external_single_structure([
            'questions' => self::questions_structure(),
        ]);
    }

    /**
     * Parameters for previewing a seal brief.
     *
     * @return external_function_parameters
     */
    public static function preview_seal_brief_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'answers' => self::answers_structure(),
        ]);
    }

    /**
     * Draw the seal a set of answers describes, without saving anything.
     *
     * @param int $templateid the design being edited
     * @param array $answers the submitted answers
     * @return array
     */
    public static function preview_seal_brief(int $templateid, array $answers): array {
        $params = self::validate_parameters(self::preview_seal_brief_parameters(), [
            'templateid' => $templateid,
            'answers' => $answers,
        ]);

        $template = self::require_readable($params['templateid']);
        $painter = self::seal_painter($template);

        $brief = seal_brief::from_input(
            self::collect($params['answers'], seal_brief::fields()));
        $data = $brief->to_spec();

        $warnings = [];
        foreach ($brief->warnings() as $warning) {
            $warnings[] = ['message' => get_string($warning['key'], 'mod_certificatepro',
                $warning['a'])];
        }

        return [
            'svg' => $painter($data, true),
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
                new external_single_structure([
                    'message' => new external_value(PARAM_TEXT, 'What the author should know'),
                ])
            ),
        ]);
    }

    /**
     * Parameters for placing a seal.
     *
     * @return external_function_parameters
     */
    public static function apply_seal_brief_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'pageid' => new external_value(PARAM_INT, 'The page to place it on'),
            'answers' => self::answers_structure(),
            'elementid' => new external_value(PARAM_INT,
                'An existing seal element to restyle, or 0 to add one', VALUE_DEFAULT, 0),
            'variant' => new external_value(PARAM_TEXT, // style~seed; decode_variant() keeps only tokens the catalogue knows
                'The variant the author picked, or an empty string for the brief itself',
                VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Place, or restyle, a seal from a brief.
     *
     * The variant key is how the author gets the seal they clicked rather than
     * the seal the brief would have produced on its own. It is opaque to the
     * browser and decoded here against the engine's own option lists, so the
     * worst a tampered key can name is a seal this wizard would have drawn.
     *
     * @param int $templateid the design being edited
     * @param int $pageid the page to place it on
     * @param array $answers the submitted answers
     * @param int $elementid an existing seal to restyle, or 0
     * @param string $variant the chosen variant key, or an empty string
     * @return array
     */
    public static function apply_seal_brief(int $templateid, int $pageid, array $answers,
            int $elementid = 0, string $variant = ''): array {
        global $DB;

        $params = self::validate_parameters(self::apply_seal_brief_parameters(), [
            'templateid' => $templateid,
            'pageid' => $pageid,
            'answers' => $answers,
            'elementid' => $elementid,
            'variant' => $variant,
        ]);

        $template = self::require_editable($params['templateid']);
        $page = self::require_page($template, $params['pageid']);

        $brief = seal_brief::from_input(
            self::collect($params['answers'], seal_brief::fields()));
        $data = $brief->to_spec(seal_brief::decode_variant((string) $params['variant']));

        $record = false;
        if ($params['elementid'] > 0) {
            $record = $DB->get_record('certificatepro_elements', [
                'id' => $params['elementid'],
                'pageid' => (int) $page->id,
                'element' => 'seal',
            ]);
        }

        if ($record === false) {
            // Starter designs already carry a seal, and a wizard that adds a
            // second one on top of it produces a design that looks broken and
            // an author who cannot see why. Restyle the seal that is there.
            $existing = $DB->get_records('certificatepro_elements',
                ['pageid' => (int) $page->id, 'element' => 'seal'], 'id ASC', '*', 0, 1);
            $record = reset($existing);
        }

        $added = $record === false;

        if ($added) {
            $newid = $template->add_element((int) $page->id, 'seal');
            $record = $DB->get_record('certificatepro_elements', ['id' => $newid],
                '*', MUST_EXIST);
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
        $DB->update_record('certificatepro_elements', (object) [
            'id' => (int) $record->id,
            'name' => $name !== '' ? $name : get_string('sealbriefdefaultname', 'mod_certificatepro'),
            'data' => json_encode($data, JSON_PRESERVE_ZERO_FRACTION),
            'width' => (float) $record->width,
            'height' => (float) $record->height,
            'posx' => (float) $record->posx,
            'posy' => (float) $record->posy,
            'refpoint' => $record->refpoint,
            'timemodified' => time(),
        ]);

        $template->touch();

        \mod_certificatepro\event\template_updated::create([
            'objectid' => $template->get_id(),
            'context' => $template->get_context(),
        ])->trigger();

        return ['elementid' => (int) $record->id, 'added' => $added];
    }

    /**
     * What placing a seal returns.
     *
     * @return external_single_structure
     */
    public static function apply_seal_brief_returns(): external_single_structure {
        return new external_single_structure([
            'elementid' => new external_value(PARAM_INT, 'The seal element id'),
            'added' => new external_value(PARAM_BOOL,
                'Whether a seal was added, rather than an existing one restyled'),
        ]);
    }

    // ------------------------------------------------------------------
    // The shared wizard: one call per screen, thumbnails and preview together.
    // ------------------------------------------------------------------

    /**
     * Parameters for reading one wizard screen.
     *
     * @return external_function_parameters
     */
    public static function get_wizard_screen_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'kind' => new external_value(PARAM_ALPHA, 'artwork or seal'),
            'screen' => new external_value(PARAM_ALPHANUMEXT, 'The screen identifier'),
            'answers' => self::answers_structure(),
        ]);
    }

    /**
     * Everything one wizard screen needs, in a single call.
     *
     * WHY THIS IS ONE SERVICE AND NOT ONE PER THUMBNAIL
     *
     * A gallery of nine seals is nine renders, and a render is a few hundred
     * recorded operations — cheap. Nine round trips are not: on a school
     * network that is most of a second of latency before anything appears, and
     * the browser would draw the grid in whatever order the responses came
     * back. One call returns the whole screen, so the gallery appears at once
     * and in order.
     *
     * The thumbnails are drawn from the answers already given, with the one
     * option under each tile substituted. That is what makes the wizard honest:
     * the picture under "Cogged" is the seal the author would get by choosing
     * cogged, carrying their own name, not an illustration of the word.
     *
     * @param int $templateid the design being edited
     * @param string $kind artwork or seal
     * @param string $screen the screen identifier
     * @param array $answers everything answered so far
     * @return array
     */
    public static function get_wizard_screen(int $templateid, string $kind, string $screen,
            array $answers): array {
        $params = self::validate_parameters(self::get_wizard_screen_parameters(), [
            'templateid' => $templateid,
            'kind' => $kind,
            'screen' => $screen,
            'answers' => $answers,
        ]);

        $template = self::require_readable($params['templateid']);
        $kind = $params['kind'] === 'seal' ? 'seal' : 'artwork';

        $screens = $kind === 'seal' ? seal_brief::screens() : artwork_brief::screens();
        $index = 0;
        $found = null;
        foreach ($screens as $i => $one) {
            if ($one['id'] === $params['screen']) {
                $index = $i;
                $found = $one;
            }
        }
        if ($found === null) {
            $index = 0;
            $found = $screens[0];
        }

        $painter = $kind === 'seal'
            ? self::seal_painter($template)
            : self::artwork_painter($template);

        $fields = $kind === 'seal' ? seal_brief::fields() : artwork_brief::fields();
        $values = self::collect($params['answers'], $fields);
        $brief = $kind === 'seal'
            ? seal_brief::from_input($values)
            : artwork_brief::from_input($values);

        $groups = [];
        foreach ($found['galleries'] as $name) {
            $groups[] = self::gallery($kind, $brief, $fields[$name], $name, $painter);
        }

        $inputs = [];
        foreach ($found['fields'] as $name) {
            $field = $fields[$name];
            $inputs[] = [
                'name' => $name,
                'label' => get_string($field['label'], 'mod_certificatepro'),
                'type' => (string) ($field['type'] ?? 'text'),
                'maxlength' => (int) ($field['limit'] ?? 0),
                'required' => !empty($field['required']),
                'value' => (string) ($brief->answers()[$name] ?? ''),
            ];
        }

        return [
            'screen' => $found['id'],
            'screens' => array_column($screens, 'id'),
            'index' => $index,
            'total' => count($screens),
            'title' => get_string($found['title'], 'mod_certificatepro'),
            'hint' => get_string($found['hint'], 'mod_certificatepro'),
            'preview' => self::preview_of($kind, $brief, $painter),
            'groups' => $groups,
            'fields' => $inputs,
        ];
    }

    /**
     * What reading a wizard screen returns.
     *
     * @return external_single_structure
     */
    public static function get_wizard_screen_returns(): external_single_structure {
        return new external_single_structure([
            'screen' => new external_value(PARAM_ALPHANUMEXT, 'The screen identifier'),
            'screens' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'A screen identifier'),
                'Every screen, in order, so the wizard can navigate without guessing'
            ),
            'index' => new external_value(PARAM_INT, 'Which screen this is, from zero'),
            'total' => new external_value(PARAM_INT, 'How many screens there are'),
            'title' => new external_value(PARAM_TEXT, 'The question'),
            'hint' => new external_value(PARAM_TEXT, 'A sentence of guidance'),
            'preview' => self::preview_structure(),
            'groups' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_ALPHANUMEXT, 'The field name'),
                    'label' => new external_value(PARAM_TEXT, 'The gallery heading'),
                    'wide' => new external_value(PARAM_BOOL,
                        'Whether the tiles want the page aspect rather than a square'),
                    'options' => new external_multiple_structure(
                        new external_single_structure([
                            'value' => new external_value(PARAM_ALPHANUMEXT, 'The option value'),
                            'label' => new external_value(PARAM_TEXT, 'The option name'),
                            'selected' => new external_value(PARAM_BOOL, 'Whether it is chosen'),
                            // pipeline-ignore: PARAM_RAW - sanitised SVG drawn by this plugin.
                            'svg' => new external_value(
                                PARAM_RAW, // pipeline-ignore: PARAM_RAW - sanitised SVG
                                'The option, drawn'),
                        ])
                    ),
                ])
            ),
            'fields' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_ALPHANUMEXT, 'The field name'),
                    'label' => new external_value(PARAM_TEXT, 'The prompt'),
                    'type' => new external_value(PARAM_ALPHA, 'text or year'),
                    'maxlength' => new external_value(PARAM_INT, 'The character limit, 0 for none'),
                    'required' => new external_value(PARAM_BOOL, 'Whether an answer is required'),
                    'value' => new external_value(PARAM_TEXT, 'What is answered already'),
                ])
            ),
        ]);
    }

    /**
     * Parameters for reading a set of variants.
     *
     * @return external_function_parameters
     */
    public static function get_wizard_variants_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'kind' => new external_value(PARAM_ALPHA, 'artwork or seal'),
            'answers' => self::answers_structure(),
            'like' => new external_value(PARAM_TEXT, // style~seed; decode_variant() keeps only tokens the catalogue knows
                'A variant key to stay close to', VALUE_DEFAULT, ''),
            'count' => new external_value(PARAM_INT, 'How many to draw', VALUE_DEFAULT, 9),
        ]);
    }

    /**
     * Draw a spread of results the answers could resolve to.
     *
     * A wizard that produces one answer is asking the author to accept or
     * reject it. A wizard that produces nine is asking them to choose, which is
     * a question people are much better at — and every reference implementation
     * of this kind of tool, from logo generators to image models, converged on
     * the same thing.
     *
     * @param int $templateid the design being edited
     * @param string $kind artwork or seal
     * @param array $answers everything answered
     * @param string $like a variant key to stay close to, or an empty string
     * @param int $count how many to draw
     * @return array
     */
    public static function get_wizard_variants(int $templateid, string $kind, array $answers,
            string $like = '', int $count = 9): array {
        $params = self::validate_parameters(self::get_wizard_variants_parameters(), [
            'templateid' => $templateid,
            'kind' => $kind,
            'answers' => $answers,
            'like' => $like,
            'count' => $count,
        ]);

        $template = self::require_readable($params['templateid']);
        $kind = $params['kind'] === 'seal' ? 'seal' : 'artwork';
        $count = max(6, min(12, (int) $params['count']));
        $like = (string) $params['like'];

        $out = [];

        if ($kind === 'seal') {
            $painter = self::seal_painter($template);
            $brief = seal_brief::from_input(
                self::collect($params['answers'], seal_brief::fields()));

            $near = seal_brief::decode_variant($like);
            foreach ($brief->variants($count, $near, $near !== []) as $variant) {
                $spec = $brief->to_spec($variant['choice']);
                $out[] = [
                    'key' => $variant['key'],
                    'label' => self::seal_caption($spec),
                    'caption' => self::seal_caption($spec),
                    'svg' => $painter($spec, false),
                ];
            }
        } else {
            $painter = self::artwork_painter($template);
            $brief = artwork_brief::from_input(
                self::collect($params['answers'], artwork_brief::fields()));

            // Ask for more candidates than are wanted and drop any that draw
            // exactly what an earlier one drew. Several styles ignore the seed
            // entirely, so "the same style at another seed" is sometimes the
            // same picture; a grid of nine has to be nine.
            $seen = [];
            foreach ($brief->variants($count * 2, $like) as $variant) {
                if (count($out) >= $count) {
                    break;
                }

                $svg = $painter($variant['style'], $variant['seed'], false);
                $fingerprint = md5($svg);
                if (isset($seen[$fingerprint])) {
                    continue;
                }
                $seen[$fingerprint] = true;

                $entry = background_library::styles()[$variant['style']] ?? null;
                $name = $entry === null
                    ? $variant['style']
                    : self::label('bgstyle_' . $variant['style'], $entry['name']);
                $out[] = [
                    'key' => $variant['key'],
                    'label' => $name,
                    'caption' => $name,
                    'svg' => $svg,
                ];
            }
        }

        return ['variants' => $out];
    }

    /**
     * What reading a set of variants returns.
     *
     * @return external_single_structure
     */
    public static function get_wizard_variants_returns(): external_single_structure {
        return new external_single_structure([
            'variants' => new external_multiple_structure(
                new external_single_structure([
                    // pipeline-ignore: PARAM_RAW - opaque key, decoded against a fixed vocabulary.
                    'key' => new external_value(PARAM_RAW, 'The variant key'), // pipeline-ignore: PARAM_RAW - markup return value
                    'label' => new external_value(PARAM_TEXT, 'A short name'),
                    'caption' => new external_value(PARAM_TEXT, 'What it is'),
                    // pipeline-ignore: PARAM_RAW - sanitised SVG drawn by this plugin.
                    'svg' => new external_value(PARAM_RAW, 'The variant, drawn'), // pipeline-ignore: PARAM_RAW - sanitised SVG
                ])
            ),
        ]);
    }

    // ------------------------------------------------------------------
    // Drawing.
    // ------------------------------------------------------------------

    /**
     * The structure a live preview comes back in.
     *
     * @return external_single_structure
     */
    protected static function preview_structure(): external_single_structure {
        return new external_single_structure([
            // pipeline-ignore: PARAM_RAW - sanitised SVG drawn by this plugin.
            'svg' => new external_value(PARAM_RAW, 'The preview'), // pipeline-ignore: PARAM_RAW - sanitised SVG
            'caption' => new external_value(PARAM_TEXT, 'What it is'),
            'warnings' => new external_multiple_structure(
                new external_single_structure([
                    'message' => new external_value(PARAM_TEXT, 'What the author should know'),
                ])
            ),
        ]);
    }

    /**
     * The live preview for a set of answers.
     *
     * @param string $kind artwork or seal
     * @param object $brief the answered brief
     * @param callable $painter the drawing function for this kind
     * @return array
     */
    protected static function preview_of(string $kind, object $brief, callable $painter): array {
        if ($kind === 'seal') {
            $spec = $brief->to_spec();
            $warnings = [];
            foreach ($brief->warnings() as $warning) {
                $warnings[] = ['message' => get_string($warning['key'], 'mod_certificatepro',
                    $warning['a'])];
            }
            return [
                'svg' => $painter($spec, true),
                'caption' => self::seal_caption($spec),
                'warnings' => $warnings,
            ];
        }

        $style = $brief->to_procedural_style();
        $entry = background_library::styles()[$style] ?? null;

        return [
            'svg' => $painter($style, artwork_brief::BASE_SEED, true),
            'caption' => $entry === null
                ? $style
                : self::label('bgstyle_' . $style, $entry['name']),
            'warnings' => [],
        ];
    }

    /**
     * A drawing function for seals in this design's colours and face.
     *
     * The face matters. A placed seal sets its ring lettering in the design's
     * heading family, so a wizard that previewed everything in Helvetica would
     * show the author a seal they are not going to get — which is the exact
     * failure the element's single-geometry design exists to prevent.
     *
     * @param template $template the design
     * @return callable taking the stored settings and whether this is the large preview
     */
    protected static function seal_painter(template $template): callable {
        $palette = $template->get_palette();
        $pairing = typography::resolve($template->get_pairing(),
            font_manager::installed_families());
        $family = (string) ($pairing['heading']['resolved'] ?? '');
        $family = $family !== '' ? $family : 'helvetica';

        return function (array $data, bool $large) use ($palette, $family): string {
            return self::seal_svg($data, $palette, $family, $large);
        };
    }

    /**
     * A drawing function for backgrounds in this design's colours and page shape.
     *
     * @param template $template the design
     * @return callable taking a style, a seed and whether this is the large preview
     */
    protected static function artwork_painter(template $template): callable {
        $palette = $template->get_palette();
        $page = self::require_page($template, 0);
        $width = max(40.0, (float) $page->width);
        $height = max(30.0, (float) $page->height);

        // A screen of the artwork wizard is six or nine full pages of vector,
        // and the ornate half of the catalogue is three hundred milliseconds of
        // trigonometry. It is all deterministic in the style, the seed, the
        // sheet and the palette, so it is drawn once per site and read back
        // afterwards. Walking back a screen, or a second author making the same
        // choices, then costs nothing.
        $cache = \cache::make_from_params(\cache_store::MODE_APPLICATION,
            'mod_certificatepro', 'wizardartwork');
        $stamp = substr(md5(json_encode([$palette, $width, $height])), 0, 8);

        return function (string $style, int $seed, bool $large)
                use ($palette, $width, $height, $cache, $stamp) {
            // Drawn at the real page size even for a thumbnail. Drafting them
            // on a smaller sheet was tempting and wrong twice over: several
            // styles compute how many times a motif repeats from the page in
            // millimetres, so a small sheet is a different composition, and
            // rule widths are in points and do not scale with it, so every
            // hairline came out three times heavier than it prints. A thumbnail
            // that flatters the artwork is not a preview. It costs about a
            // tenth more on the wire and nothing in time.
            $key = $stamp . '_' . $style . '_' . max(1, $seed) . '_' . ($large ? 'l' : 't');
            $svg = $cache->get($key);

            if ($svg === false) {
                $svg = background_library::svg($style, $palette,
                    $width, $height, max(1, $seed));

                // Both the thumbnail and the preview beside it are about a
                // quarter of a metre of paper shown in a quarter of a thousand
                // pixels, so both get the same floor and they agree with each
                // other. Nothing that is saved goes through here.
                $svg = self::hairline_floor($svg, $width / 320.0);
                $svg = self::compact($svg, $large ? 2 : 1);

                $cache->set($key, $svg);
            }

            // After the cache, never before: two copies of one drawing in one
            // document must not answer to one set of ids.
            return self::unique_ids((string) $svg);
        };
    }

    /**
     * Raise the thinnest rules in a thumbnail to the thinnest the screen can draw.
     *
     * A certificate border is set in hairlines because that is what looks
     * expensive on paper at 300 dots to the inch. A thumbnail of the same page
     * is 265 pixels across, where a half-millimetre rule is four tenths of a
     * pixel and the browser draws it at four tenths of its colour — so the
     * gallery showed six pale rectangles for six backgrounds that print
     * distinctly. The floor says "this line exists", which is exactly what the
     * printer says about it and what the screen cannot.
     *
     * This is a screen adjustment and it is applied to the wizard's own
     * pictures only. The artwork that is generated and stored when the author
     * presses the button is drawn straight from the catalogue and never passes
     * through here, so what is kept is what the style really is.
     *
     * @param string $svg the markup
     * @param float $floor the thinnest rule worth drawing, in millimetres
     * @return string the markup
     */
    protected static function hairline_floor(string $svg, float $floor): string {
        return (string) preg_replace_callback('/stroke-width="([\d.]+)"/',
            function (array $m) use ($floor): string {
                $width = max((float) $m[1], $floor);
                return 'stroke-width="' . rtrim(rtrim(number_format($width, 3, '.', ''), '0'), '.')
                    . '"';
            }, $svg);
    }

    /**
     * Draw a seal from stored settings.
     *
     * @param array $data the element settings
     * @param array $palette the design's palette
     * @param string $family the font family the ring is set in
     * @param bool $large whether this is the large preview rather than a thumbnail
     * @return string the SVG markup
     */
    protected static function seal_svg(array $data, array $palette, string $family = 'helvetica',
            bool $large = true): string {
        $size = self::SEAL_DIAMETER;
        $canvas = new canvas($size, $size, $palette, 7);

        seal_engine::draw($canvas, 0.5, 0.5, $size / 2.0, [
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
            'family' => $family,
            'divider' => (bool) $data['sealdivider'],
            'innerkeyline' => (bool) $data['sealkeyline'],
            'weight' => 1.0,
        ]);

        // Never stretched: a seal squeezed to a non-square box is the one
        // failure a reader notices instantly.
        $svg = (new svg_painter('cpseal' . substr(md5(serialize($data) . $large), 0, 6)))
            ->render($canvas->ops(), $size, $size, false);

        return self::unique_ids(self::compact($svg, $large ? 3 : 2));
    }

    /**
     * A short description of a seal, for a caption.
     *
     * @param array $data the element settings
     * @return string
     */
    protected static function seal_caption(array $data): string {
        $fields = seal_brief::fields();

        return implode(' · ', [
            self::option_label($fields['edge'], '', $data['sealedge'], $data['sealedge']),
            self::option_label($fields['mark'], '', $data['sealmark'], $data['sealmark']),
            self::option_label($fields['ring'], '', $data['sealring'], $data['sealring']),
        ]);
    }

    /**
     * Round the coordinates in a drawing down to the precision it is shown at.
     *
     * The painter emits three decimal places, which is a thousandth of a
     * millimetre. On a thumbnail eighty pixels across that is four orders of
     * magnitude past anything the screen can show, and the most ornate styles
     * in the catalogue are four hundred kilobytes of it. Trimming to one place
     * on a thumbnail is invisible and takes about a third off the wire.
     *
     * @param string $svg the markup
     * @param int $places how many decimal places to keep
     * @return string the markup
     */
    protected static function compact(string $svg, int $places): string {
        $places = max(0, min(4, $places));

        return (string) preg_replace_callback('/(\d)\.(\d{2,})/',
            function (array $m) use ($places): string {
                $kept = rtrim(substr($m[2], 0, $places), '0');
                return $kept === '' ? $m[1] : $m[1] . '.' . $kept;
            }, $svg);
    }

    /**
     * Make every element id in a drawing unique to this copy of it.
     *
     * WHY THIS IS NOT OPTIONAL
     *
     * The painter names its clip paths and gradients from the style and the
     * seed, which is stable and readable and exactly wrong here: a screen puts
     * nine drawings and a large preview into one document, and two of them are
     * routinely the same style. SVG ids are global to the page, so the second
     * copy's clipPath silently loses to the first — and since a thumbnail is
     * drawn on a third-size sheet, the full-size preview beside it gets clipped
     * to a third of itself. It looks like a rendering bug in the artwork and it
     * is not: it is two elements answering to one name.
     *
     * @param string $svg the markup
     * @return string the markup, with its ids made local to this copy
     */
    protected static function unique_ids(string $svg): string {
        static $counter = 0;

        $suffix = 'x' . base_convert((string) ++$counter, 10, 36);

        if (!preg_match_all('/\bid="([A-Za-z][\w-]*)"/', $svg, $matches)) {
            return $svg;
        }

        $find = [];
        $replace = [];
        foreach (array_unique($matches[1]) as $id) {
            $find[] = $id;
            $replace[] = $id . $suffix;
        }

        // The ids the painter emits are random-looking tokens that appear
        // nowhere else in the document, so a plain replacement reaches the
        // definition, every url(#...) reference and any href to it.
        return str_replace($find, $replace, $svg);
    }

    /**
     * One gallery: every option for a field, each drawn as a whole result.
     *
     * For the artwork wizard two neighbouring options can score the same style
     * highest, which would put the same picture under two different words and
     * teach the author that the question does not matter. Each option therefore
     * takes the best style not already spoken for, walking down its own
     * ranking.
     *
     * The answer the author has already given gets first refusal, whoever else
     * wanted it. Otherwise the tile marked as chosen could be showing the
     * runner-up while the live preview beside it shows the winner, and the one
     * thing the preview must never do is disagree with the tile it came from.
     *
     * @param string $kind artwork or seal
     * @param object $brief the answered brief
     * @param array $field the field definition
     * @param string $name the field name
     * @param callable $painter the drawing function for this kind
     * @return array the gallery
     */
    protected static function gallery(string $kind, object $brief, array $field, string $name,
            callable $painter): array {
        $chosen = (string) ($brief->answers()[$name] ?? '');
        $options = [];
        $taken = [];

        if ($kind !== 'seal' && isset($field['options'][$chosen])) {
            $taken[(string) $brief->with([$name => $chosen])->to_procedural_style()] = true;
        }

        foreach (array_keys($field['options']) as $value) {
            $value = (string) $value;

            if ($kind === 'seal') {
                $svg = $painter($brief->to_spec([$name => $value]), false);
            } else {
                $ranked = $brief->with([$name => $value])->ranked_styles();
                $style = $value === $chosen ? (string) array_key_first($ranked) : '';

                foreach (array_keys($ranked) as $candidate) {
                    if ($style !== '') {
                        break;
                    }
                    if (!isset($taken[$candidate])) {
                        $style = $candidate;
                    }
                }

                $style = $style !== '' ? $style : (string) array_key_first($ranked);
                $taken[$style] = true;
                $svg = $painter($style, artwork_brief::BASE_SEED, false);
            }

            $options[] = [
                'value' => $value,
                'label' => self::option_label($field, self::wizard_option_key($kind, $name, $value),
                    $value, (string) ($field['options'][$value] ?? '')),
                'selected' => $value === $chosen,
                'svg' => $svg,
            ];
        }

        return [
            'name' => $name,
            'label' => get_string($field['label'], 'mod_certificatepro'),
            'wide' => $kind !== 'seal',
            'options' => $options,
        ];
    }

    /**
     * The wizard's own language key for one option.
     *
     * @param string $kind artwork or seal
     * @param string $name the field name
     * @param string $value the option value
     * @return string
     */
    protected static function wizard_option_key(string $kind, string $name, string $value): string {
        return ($kind === 'seal' ? 'sealopt_' : 'briefopt_') . $name . '_' . $value;
    }
}
