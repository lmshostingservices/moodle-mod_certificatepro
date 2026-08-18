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

namespace mod_certificatepro\element;

use mod_certificatepro\local\media_library;

/**
 * Shared behaviour for every element that draws a picture.
 *
 * Handles the things that are easy to get wrong: keeping the aspect ratio,
 * fitting inside the box rather than overflowing it, and drawing vector artwork
 * as vectors instead of rasterising it.
 *
 * Alternative text is collected and used everywhere it can be: the designer
 * canvas, the activity page and the mobile app. It is deliberately not written
 * into the PDF, because the PDF library Moodle ships has no mechanism for
 * tagging an image with a text alternative, and pretending otherwise would be
 * worse than being clear about the limit.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class image_base extends base {
    /**
     * The file area this element stores its picture in, when it has one.
     *
     * @return string
     */
    protected function filearea(): string {
        return 'elementimage';
    }

    /**
     * The default typography, which images barely use.
     *
     * @return array
     */
    public static function default_style(): array {
        return array_merge(parent::default_style(), [
            'opacity' => 1.0,
        ]);
    }

    /**
     * Add the settings every image element shares.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @return void
     */
    protected function add_common_image_settings(\MoodleQuickForm $mform): void {
        if ($this->has_media_picker()) {
            $mform->addElement('select', 'mediaid',
                get_string('mediafromlibrary', 'mod_certificatepro'),
                media_library::menu($this->media_kind()));
            $mform->addHelpButton('mediaid', 'mediafromlibrary', 'mod_certificatepro');
        }

        $mform->addElement('advcheckbox', 'keepaspect',
            get_string('keepaspect', 'mod_certificatepro'));
        $mform->setDefault('keepaspect', 1);

        $mform->addElement('text', 'opacity',
            get_string('elementopacity', 'mod_certificatepro'), ['size' => 6]);
        $mform->setType('opacity', PARAM_FLOAT);
        $mform->setDefault('opacity', 1);

        $mform->addElement('text', 'alttext',
            get_string('elementalttext', 'mod_certificatepro'), ['size' => 60]);
        $mform->setType('alttext', PARAM_TEXT);
        $mform->addHelpButton('alttext', 'elementalttext', 'mod_certificatepro');
    }

    /**
     * Read the settings every image element shares out of submitted form data.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    protected function common_image_data(\stdClass $data): array {
        return [
            'mediaid' => (int) ($data->mediaid ?? 0),
            'keepaspect' => !empty($data->keepaspect) ? 1 : 0,
            'opacity' => min(1.0, max(0.0, (float) ($data->opacity ?? 1))),
            'alttext' => (string) ($data->alttext ?? ''),
        ];
    }

    /**
     * Whether this element offers a picture from the shared library at all.
     *
     * False for elements that draw something the library has no business
     * holding, such as the learner's own profile picture.
     *
     * @return bool
     */
    protected function has_media_picker(): bool {
        return true;
    }

    /**
     * Which kind of shared picture this element narrows the library down to.
     *
     * Null offers the whole library, which is right for a general purpose
     * picture element: somebody who filed a crest under seals should still find
     * it when they reach for a picture.
     *
     * @return string|null a kind from {@see media_library::kinds()}, or null for all
     */
    protected function media_kind(): ?string {
        return null;
    }

    /**
     * The shared library picture this element points at, if any.
     *
     * @return int the library record id, or zero when the element holds its own file
     */
    protected function media_id(): int {
        return (int) $this->config('mediaid', 0);
    }

    /**
     * The stored file this element draws, when it has one.
     *
     * @param render_context $ctx the render context
     * @return \stored_file|null
     */
    protected function stored_file(render_context $ctx): ?\stored_file {
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $ctx->get_context()->id,
            'mod_certificatepro',
            $this->filearea(),
            $this->get_id(),
            'filename',
            false
        );
        if (empty($files)) {
            return null;
        }
        return reset($files);
    }

    /**
     * An absolute path to the picture this element draws.
     *
     * @param render_context $ctx the render context
     * @return string|null null when there is nothing to draw
     */
    protected function image_path(render_context $ctx): ?string {
        $mediaid = $this->media_id();
        if ($mediaid > 0) {
            $path = media_library::path($mediaid);
            if ($path !== null) {
                return $path;
            }
        }

        $file = $this->stored_file($ctx);
        if ($file === null) {
            return null;
        }
        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) ?: 'png';
        $path = make_request_directory() . '/element' . $this->get_id() . '.' . $extension;
        $file->copy_content_to($path);
        return $path;
    }

    /**
     * A browser URL for the picture this element draws.
     *
     * @param render_context $ctx the render context
     * @return string|null
     */
    protected function image_url(render_context $ctx): ?string {
        $mediaid = $this->media_id();
        if ($mediaid > 0) {
            $url = media_library::url($mediaid);
            if ($url !== null) {
                return $url;
            }
        }

        $file = $this->stored_file($ctx);
        if ($file === null) {
            return null;
        }
        return \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
    }

    /**
     * The picture's own pixel dimensions, when they can be determined.
     *
     * @param render_context $ctx the render context
     * @return array{0: int, 1: int} width then height, both zero when unknown
     */
    protected function intrinsic_size(render_context $ctx): array {
        $mediaid = $this->media_id();
        if ($mediaid > 0) {
            [$width, $height] = media_library::dimensions($mediaid);
            if ($width > 0 && $height > 0) {
                return [$width, $height];
            }
        }

        $file = $this->stored_file($ctx);
        if ($file === null) {
            return [0, 0];
        }
        $info = $file->get_imageinfo();
        if (!$info || empty($info['width']) || empty($info['height'])) {
            return [0, 0];
        }
        return [(int) $info['width'], (int) $info['height']];
    }

    /**
     * Resolve to the path of the picture, so it lands in the snapshot.
     *
     * @param render_context $ctx the render context
     * @return string|null
     */
    public function resolve(render_context $ctx) {
        return $this->image_path($ctx);
    }

    /**
     * Work out the drawn size, keeping the aspect ratio when asked to.
     *
     * @param render_context $ctx the render context
     * @return array{0: float, 1: float} width then height in millimetres
     */
    protected function drawn_size(render_context $ctx): array {
        $boxwidth = $this->get_width();
        $boxheight = $this->get_height();

        if (!$this->config('keepaspect', 1)) {
            return [max(1.0, $boxwidth), max(1.0, $boxheight)];
        }

        [$pixelwidth, $pixelheight] = $this->intrinsic_size($ctx);

        return media_library::fit(
            $pixelwidth,
            $pixelheight,
            $boxwidth,
            $boxheight,
            (string) $this->config('fitmode', 'contain')
        );
    }

    /**
     * Draw the picture onto the PDF.
     *
     * @param \pdf $pdf the PDF being built
     * @param render_context $ctx the render context
     * @return void
     */
    public function render_pdf(\pdf $pdf, render_context $ctx): void {
        if ($this->is_hidden()) {
            return;
        }

        $path = $this->image_path($ctx);
        if ($path === null || !is_readable($path)) {
            return;
        }

        [$width, $height] = $this->drawn_size($ctx);
        [$left, $top] = $this->box_origin($width, $height);

        $opacity = (float) $this->config('opacity', 1.0);
        if ($opacity < 1.0) {
            $pdf->SetAlpha(max(0.0, min(1.0, $opacity)));
        }

        $rotation = $this->get_rotation();
        if (abs($rotation) > 0.001) {
            $pdf->StartTransform();
            $pdf->Rotate($rotation, $this->get_posx(), $this->get_posy());
        }

        try {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
                $pdf->ImageSVG($path, $left, $top, $width, $height, '', '', '', 0, false);
            } else {
                $pdf->Image($path, $left, $top, $width, $height, '', '', '', true, 300,
                    '', false, false, 0, false, false, false);
            }
        } catch (\Throwable $e) {
            debugging('Certificate Pro could not draw an image: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        if (abs($rotation) > 0.001) {
            $pdf->StopTransform();
        }
        if ($opacity < 1.0) {
            $pdf->SetAlpha(1.0);
        }
    }

    /**
     * Produce the designer canvas markup for this element.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function render_html(render_context $ctx): string {
        $url = $this->image_url($ctx);
        if ($url === null) {
            return \html_writer::div(
                \html_writer::tag('span', static::display_name()),
                'certificatepro-element-placeholder'
            );
        }

        $classes = 'certificatepro-element-image';
        if ($this->config('circular', 0)) {
            $classes .= ' certificatepro-circular';
        }

        $fit = $this->config('keepaspect', 1) ? 'contain' : 'fill';
        if ($this->config('fitmode', '') === 'cover') {
            $fit = 'cover';
        }

        return \html_writer::empty_tag('img', [
            'src' => $url,
            'alt' => (string) $this->config('alttext', ''),
            'class' => $classes,
            'style' => 'width:100%;height:100%;object-fit:' . $fit
                . ';opacity:' . (float) $this->config('opacity', 1.0),
        ]);
    }
}
