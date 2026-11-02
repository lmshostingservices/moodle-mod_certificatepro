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

use mod_certificatepro\element\base;
use mod_certificatepro\element\image_base;
use mod_certificatepro\element\render_context;
use mod_certificatepro\local\brand;

/**
 * A picture that covers the whole page, sitting behind everything else.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bgimage extends image_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'bgimage';
    }

    /**
     * The picker drawer this element belongs to.
     *
     * @return string
     */
    public static function category(): string {
        return self::CATEGORY_DECOR;
    }

    /**
     * The picker icon.
     *
     * @return string
     */
    public static function icon(): string {
        return 'fa-panorama';
    }

    /**
     * Only one background makes sense per page.
     *
     * @return bool
     */
    public static function allows_multiple(): bool {
        return false;
    }

    /**
     * The default box size in millimetres, replaced by the page size on render.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [297.0, 210.0];
    }

    /**
     * The file area this element stores its picture in.
     *
     * @return string[]
     */
    public static function file_areas(): array {
        return ['backgroundimage'];
    }

    /**
     * The file area used by this element instance.
     *
     * @return string
     */
    protected function filearea(): string {
        return 'backgroundimage';
    }

    /**
     * Which kind of shared picture this element can be pointed at.
     *
     * @return string|null
     */
    protected function media_kind(): ?string {
        return \mod_certificatepro\local\media_library::KIND_BACKGROUND;
    }

    /**
     * Add the file picker and the fit options.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement(
            'filemanager',
            'imagefile',
            get_string('elementbgimagefile', 'mod_certificatepro'),
            null,
            [
                'subdirs' => 0,
                'maxfiles' => 1,
                'accepted_types' => ['web_image', '.svg'],
            ]
        );

        $mform->addElement(
            'select',
            'mediaid',
            get_string('mediafromlibrary', 'mod_certificatepro'),
            \mod_certificatepro\local\media_library::menu(
                \mod_certificatepro\local\media_library::KIND_BACKGROUND
            )
        );
        $mform->addHelpButton('mediaid', 'mediafromlibrary', 'mod_certificatepro');

        $mform->addElement(
            'select',
            'fitmode',
            get_string('bgfitmode', 'mod_certificatepro'),
            [
                'cover' => get_string('bgfitmode_cover', 'mod_certificatepro'),
                'contain' => get_string('bgfitmode_contain', 'mod_certificatepro'),
                'stretch' => get_string('bgfitmode_stretch', 'mod_certificatepro'),
            ]
        );
        $mform->setDefault('fitmode', 'cover');

        $mform->addElement(
            'text',
            'opacity',
            get_string('elementopacity', 'mod_certificatepro'),
            ['size' => 6]
        );
        $mform->setType('opacity', PARAM_FLOAT);
        $mform->setDefault('opacity', 1);
    }

    /**
     * Persist the background options.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'mediaid' => (int) ($data->mediaid ?? 0),
            'fitmode' => $data->fitmode ?? 'cover',
            'opacity' => min(1.0, max(0.0, (float) ($data->opacity ?? 1))),
            'keepaspect' => 1,
        ];
    }

    /**
     * Stretch the background to the page before drawing it.
     *
     * @param \pdf $pdf the PDF being built
     * @param render_context $ctx the render context
     * @return void
     */
    public function render_pdf(\pdf $pdf, render_context $ctx): void {
        $this->record->posx = 0.0;
        $this->record->posy = 0.0;
        $this->record->refpoint = 'tl';
        $this->record->width = $ctx->get_page_width();
        $this->record->height = $ctx->get_page_height();
        parent::render_pdf($pdf, $ctx);
    }}
