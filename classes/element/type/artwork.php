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
use mod_certificatepro\element\render_context;
use mod_certificatepro\local\brand;
use mod_certificatepro\local\ai\artwork_engine;

/**
 * Generated background artwork, either drawn procedurally or written by the
site's configured artificial intelligence provider from a short description.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class artwork extends base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'artwork';
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
        return 'fa-wand-magic-sparkles';
    }

    /**
     * Only one generated background makes sense per page.
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
     * Generated artwork covers the page, so there is nothing to resize.
     *
     * @return bool
     */
    public static function resizable(): bool {
        return false;
    }

    /**
     * Report the artwork to the designer as what it is: the whole page.
     *
     * {@see render_pdf()} draws the panel from the page dimensions and ignores
     * the element box entirely, so a box of any other size is a promise the
     * print will not keep.
     *
     * @param render_context $ctx the render context
     * @return array
     */
    public function export_for_designer(render_context $ctx): array {
        $data = parent::export_for_designer($ctx);

        $data['posx'] = 0.0;
        $data['posy'] = 0.0;
        $data['width'] = $ctx->get_page_width();
        $data['height'] = $ctx->get_page_height();
        $data['refpoint'] = 'tl';
        $data['rotation'] = 0.0;

        return $data;
    }

    /**
     * The configuration a newly dropped artwork panel starts with.
     *
     * @return array
     */
    public static function default_data(): array {
        return [
            'backgroundid' => 0,
            'opacity' => 1.0,
        ];
    }

    /**
     * Add the artwork settings.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement('select', 'backgroundid',
            get_string('artworkchoose', 'mod_certificatepro'),
            \mod_certificatepro\local\ai\background_repository::menu($context));
        $mform->addHelpButton('backgroundid', 'artworkchoose', 'mod_certificatepro');

        $mform->addElement('text', 'opacity',
            get_string('elementopacity', 'mod_certificatepro'), ['size' => 6]);
        $mform->setType('opacity', PARAM_FLOAT);
        $mform->setDefault('opacity', 1);
    }

    /**
     * Persist the artwork settings.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'backgroundid' => (int) ($data->backgroundid ?? 0),
            'opacity' => min(1.0, max(0.0, (float) ($data->opacity ?? 1))),
        ];
    }

    /**
     * The artwork as SVG.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function resolve(render_context $ctx) {
        return \mod_certificatepro\local\ai\background_repository::svg(
            (int) $this->config('backgroundid', 0),
            $ctx
        );
    }

    /**
     * Draw the artwork across the whole page.
     *
     * @param \pdf $pdf the PDF being built
     * @param render_context $ctx the render context
     * @return void
     */
    public function render_pdf(\pdf $pdf, render_context $ctx): void {
        if ($this->is_hidden()) {
            return;
        }

        $svg = (string) $this->resolve_cached($ctx);
        if ($svg === '') {
            return;
        }

        $opacity = (float) $this->config('opacity', 1.0);
        if ($opacity < 1.0) {
            $pdf->SetAlpha(max(0.0, min(1.0, $opacity)));
        }

        $path = make_request_directory() . '/artwork' . $this->get_id() . '.svg';
        file_put_contents($path, $svg);

        try {
            $pdf->ImageSVG($path, 0, 0, $ctx->get_page_width(), $ctx->get_page_height(),
                '', '', '', 0, false);
        } catch (\Throwable $e) {
            debugging('Certificate Pro could not draw generated artwork: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
        }

        if ($opacity < 1.0) {
            $pdf->SetAlpha(1.0);
        }
    }

    /**
     * Produce the designer canvas markup.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function render_html(render_context $ctx): string {
        $svg = (string) $this->resolve($ctx);
        if ($svg === '') {
            return \html_writer::div('', 'certificatepro-element-placeholder');
        }
        return \html_writer::div($svg, 'certificatepro-element-artwork', [
            'style' => 'opacity:' . (float) $this->config('opacity', 1.0),
        ]);
    }}
