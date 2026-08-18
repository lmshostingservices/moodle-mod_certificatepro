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

namespace mod_certificatepro\element\type;

use mod_certificatepro\element\render_context;
use mod_certificatepro\element\text_base;

/**
 * A block of formatted text, for citations and multi paragraph wording.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class richtext extends text_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'richtext';
    }

    /**
     * The picker drawer this element belongs to.
     *
     * @return string
     */
    public static function category(): string {
        return self::CATEGORY_TEXT;
    }

    /**
     * The picker icon.
     *
     * @return string
     */
    public static function icon(): string {
        return 'fa-align-left';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [180.0, 30.0];
    }

    /**
     * Rich text may contain markup.
     *
     * @return bool
     */
    protected function allows_html(): bool {
        return true;
    }

    /**
     * Add the editor.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement('editor', 'contenteditor',
            get_string('elementrichtext', 'mod_certificatepro'), ['rows' => 8], [
                'context' => $context,
                'noclean' => false,
                'maxfiles' => 0,
                'trusttext' => false,
            ]);
        $mform->setType('contenteditor', PARAM_RAW); // pipeline-ignore: PARAM_RAW - cleaned on save
        $mform->addHelpButton('contenteditor', 'elementrichtext', 'mod_certificatepro');
    }

    /**
     * Persist the entered markup.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        $content = '';
        if (isset($data->contenteditor) && is_array($data->contenteditor)) {
            $content = (string) ($data->contenteditor['text'] ?? '');
        }

        // The editor field has to be raw, because the whole point of it is
        // markup. That makes cleaning here mandatory rather than optional:
        // this content is rendered into the designer canvas, so anything the
        // editor lets through would run in an author's browser.
        $content = clean_text($content, FORMAT_HTML);

        return ['content' => $content];
    }

    /**
     * Fill the editor with the stored markup.
     *
     * @param \MoodleQuickForm $mform the form to populate
     * @return void
     */
    public function prepare_form(\MoodleQuickForm $mform): void {
        if ($mform->elementExists('contenteditor')) {
            $mform->setDefault('contenteditor', [
                'text' => (string) $this->config('content', ''),
                'format' => FORMAT_HTML,
            ]);
        }
    }

    /**
     * The stored markup.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    protected function text(render_context $ctx): string {
        return (string) $this->config('content', '');
    }}
