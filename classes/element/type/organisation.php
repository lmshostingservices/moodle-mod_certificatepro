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
 * The name of the site issuing the certificate.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class organisation extends text_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'organisation';
    }

    /**
     * The picker drawer this element belongs to.
     *
     * @return string
     */
    public static function category(): string {
        return self::CATEGORY_DATA;
    }

    /**
     * The picker icon.
     *
     * @return string
     */
    public static function icon(): string {
        return 'fa-building';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [120.0, 8.0];
    }

    /**
     * The default typography for a newly created element.
     *
     * @return array
     */
    public static function default_style(): array {
        return array_merge(
            parent::default_style(),
            [
                'font' => 'accent',
                'scalerole' => 'eyebrow',
                'size' => null,
                'colour' => 'primary',
                'tracking' => 1.6,
                'case' => 'upper',
            ]
        );
    }

    /**
     * Let the author override the site name with their own wording.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement(
            'select',
            'source',
            get_string('organisationsource', 'mod_certificatepro'),
            [
                'fullname' => get_string('fullsitename'),
                'shortname' => get_string('shortsitename'),
                'custom' => get_string('organisationcustom', 'mod_certificatepro'),
            ]
        );
        $mform->setDefault('source', 'fullname');

        $mform->addElement(
            'text',
            'customname',
            get_string('organisationcustom', 'mod_certificatepro'),
            ['size' => 60]
        );
        $mform->setType('customname', PARAM_TEXT);
        $mform->hideIf('customname', 'source', 'neq', 'custom');
    }

    /**
     * Persist the chosen source.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'source' => $data->source ?? 'fullname',
            'customname' => (string) ($data->customname ?? ''),
        ];
    }

    /**
     * The organisation name.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    protected function text(render_context $ctx): string {
        $source = $this->config('source', 'fullname');
        if ($source === 'custom') {
            return (string) $this->config('customname', '');
        }
        $site = get_site();
        $value = $source === 'shortname' ? $site->shortname : $site->fullname;
        return format_string($value, true, ['context' => \context_system::instance()]);
    }}
