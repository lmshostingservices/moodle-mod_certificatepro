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
use mod_certificatepro\local\art\canvas;
use mod_certificatepro\local\art\seal_engine;
use mod_certificatepro\local\art\svg_painter;
use mod_certificatepro\local\art\tcpdf_painter;
use mod_certificatepro\local\brand;
use mod_certificatepro\local\font_manager;
use mod_certificatepro\local\typography;

/**
 * A vector organisation seal, drawn from the site's colours rather than uploaded.
 *
 * ONE GEOMETRY, TWO PAINTERS
 *
 * This element used to carry two implementations — one drawing straight to the
 * PDF library and a second emitting markup for the designer canvas. That is the
 * pattern that let the preview drift away from the printed certificate, and it
 * is why the backgrounds once previewed with gradients the PDF could not draw.
 *
 * Now the seal is described once as a list of recorded operations, and the two
 * painters replay it. Whatever the author sees in the designer is what prints,
 * by construction rather than by discipline.
 *
 * Ring lettering in particular has to work this way. The PDF library has no
 * text-on-a-path support at all, and the browser's has enough divergences —
 * bottom arcs rendering upside down, no portable way to fit a long name — that
 * two implementations could not agree even in principle. Laying out the glyphs
 * once, in the canvas, sidesteps both.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seal extends base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'seal';
    }

    /**
     * The picker drawer this element belongs to.
     *
     * @return string
     */
    public static function category(): string {
        return self::CATEGORY_IMAGE;
    }

    /**
     * The picker icon.
     *
     * @return string
     */
    public static function icon(): string {
        return 'fa-award';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [34.0, 34.0];
    }

    /**
     * The outer edge treatments on offer.
     *
     * @return array<string, string>
     */
    public static function edges(): array {
        $out = [];
        foreach (seal_engine::edges() as $edge) {
            $out[$edge] = get_string('sealedge_' . $edge, 'mod_certificatepro');
        }
        return $out;
    }

    /**
     * The ring lettering layouts on offer.
     *
     * @return array<string, string>
     */
    public static function rings(): array {
        $out = [];
        foreach (seal_engine::ring_layouts() as $ring) {
            $out[$ring] = get_string('sealring_' . $ring, 'mod_certificatepro');
        }
        return $out;
    }

    /**
     * The centre marks on offer.
     *
     * @return array<string, string>
     */
    public static function marks(): array {
        $out = [];
        foreach (seal_engine::marks() as $mark) {
            $out[$mark] = get_string('sealmark_' . $mark, 'mod_certificatepro');
        }
        return $out;
    }

    /**
     * Add the seal settings.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement('select', 'sealedge',
            get_string('sealedge', 'mod_certificatepro'), self::edges());
        $mform->setDefault('sealedge', seal_engine::EDGE_DOUBLE);
        $mform->addHelpButton('sealedge', 'sealedge', 'mod_certificatepro');

        $mform->addElement('select', 'sealring',
            get_string('sealring', 'mod_certificatepro'), self::rings());
        $mform->setDefault('sealring', seal_engine::RING_BOTH);

        $mform->addElement('select', 'sealmark',
            get_string('sealmark', 'mod_certificatepro'), self::marks());
        $mform->setDefault('sealmark', seal_engine::MARK_MONOGRAM);
        $mform->addHelpButton('sealmark', 'sealmark', 'mod_certificatepro');

        $mform->addElement('text', 'toptext',
            get_string('sealtoptext', 'mod_certificatepro'), ['size' => 40]);
        $mform->setType('toptext', PARAM_TEXT);
        $mform->addHelpButton('toptext', 'sealtoptext', 'mod_certificatepro');

        $mform->addElement('text', 'bottomtext',
            get_string('sealbottomtext', 'mod_certificatepro'), ['size' => 40]);
        $mform->setType('bottomtext', PARAM_TEXT);

        $mform->addElement('text', 'centretext',
            get_string('sealcentretext', 'mod_certificatepro'), ['size' => 24]);
        $mform->setType('centretext', PARAM_TEXT);
        $mform->addHelpButton('centretext', 'sealcentretext', 'mod_certificatepro');

        $mform->addElement('text', 'yeartext',
            get_string('sealyeartext', 'mod_certificatepro'), ['size' => 24]);
        $mform->setType('yeartext', PARAM_TEXT);

        $mform->addElement('text', 'mottotext',
            get_string('sealmottotext', 'mod_certificatepro'), ['size' => 30]);
        $mform->setType('mottotext', PARAM_TEXT);
        $mform->hideIf('mottotext', 'sealmark', 'neq', seal_engine::MARK_CREST);

        $mform->addElement('text', 'colour',
            get_string('elementcolour', 'mod_certificatepro'), ['size' => 12]);
        $mform->setType('colour', PARAM_TEXT);
        $mform->setDefault('colour', 'primary');

        $mform->addElement('text', 'metal',
            get_string('sealmetal', 'mod_certificatepro'), ['size' => 12]);
        $mform->setType('metal', PARAM_TEXT);
        $mform->setDefault('metal', 'accent');
        $mform->addHelpButton('metal', 'sealmetal', 'mod_certificatepro');

        $mform->addElement('advcheckbox', 'sealdivider',
            get_string('sealdivider', 'mod_certificatepro'));
        $mform->setDefault('sealdivider', 1);

        $mform->addElement('advcheckbox', 'sealkeyline',
            get_string('sealkeyline', 'mod_certificatepro'));
        $mform->setDefault('sealkeyline', 1);
    }

    /**
     * Persist the seal settings.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'sealedge' => in_array($data->sealedge ?? '', seal_engine::edges(), true)
                ? $data->sealedge : seal_engine::EDGE_DOUBLE,
            'sealring' => in_array($data->sealring ?? '', seal_engine::ring_layouts(), true)
                ? $data->sealring : seal_engine::RING_BOTH,
            'sealmark' => in_array($data->sealmark ?? '', seal_engine::marks(), true)
                ? $data->sealmark : seal_engine::MARK_MONOGRAM,
            'toptext' => (string) ($data->toptext ?? ''),
            'bottomtext' => (string) ($data->bottomtext ?? ''),
            'centretext' => (string) ($data->centretext ?? ''),
            'yeartext' => (string) ($data->yeartext ?? ''),
            'mottotext' => (string) ($data->mottotext ?? ''),
            'colour' => (string) ($data->colour ?? 'primary'),
            'metal' => (string) ($data->metal ?? 'accent'),
            'sealdivider' => !empty($data->sealdivider) ? 1 : 0,
            'sealkeyline' => !empty($data->sealkeyline) ? 1 : 0,
        ];
    }

    /**
     * Resolve a colour key against the palette, or accept a literal hex value.
     *
     * @param render_context $ctx the render context
     * @param string $key a palette role or a #rrggbb string
     * @param string $fallback the colour to use when neither resolves
     * @return string a #rrggbb string
     */
    protected function colour_for(render_context $ctx, string $key, string $fallback): string {
        $palette = $ctx->get_palette();
        if (isset($palette[$key])) {
            return $palette[$key];
        }
        return brand::is_hex($key) ? brand::normalise_hex($key) : $fallback;
    }

    /**
     * The specification the engine draws from.
     *
     * @param render_context $ctx the render context
     * @return array the resolved specification
     */
    protected function spec(render_context $ctx): array {
        $palette = $ctx->get_palette();

        return [
            'edge' => (string) $this->config('sealedge', seal_engine::EDGE_DOUBLE),
            'ring' => (string) $this->config('sealring', seal_engine::RING_BOTH),
            'mark' => (string) $this->config('sealmark', seal_engine::MARK_MONOGRAM),
            'top' => $ctx->interpolate((string) $this->config('toptext', '')),
            'bottom' => $ctx->interpolate((string) $this->config('bottomtext', '')),
            'centre' => $ctx->interpolate((string) $this->config('centretext', '')),
            'year' => $ctx->interpolate((string) $this->config('yeartext', '')),
            'motto' => $ctx->interpolate((string) $this->config('mottotext', '')),
            'ink' => $this->colour_for($ctx, (string) $this->config('colour', 'primary'), '#0f6cbf'),
            'metal' => $this->colour_for($ctx, (string) $this->config('metal', 'accent'), '#b08432'),
            'paper' => $palette['paper'] ?? '#ffffff',
            'family' => $this->seal_font($ctx),
            'divider' => (bool) $this->config('sealdivider', 1),
            'innerkeyline' => (bool) $this->config('sealkeyline', 1),
        ];
    }

    /**
     * The font family the ring lettering is set in.
     *
     * Falls back to a core font rather than failing, because a seal with no
     * lettering is worse than a seal in the wrong face.
     *
     * @param render_context $ctx the render context
     * @return string the family name
     */
    protected function seal_font(render_context $ctx): string {
        $installed = font_manager::installed_families();
        $pairing = typography::resolve($ctx->get_pairing(), $installed);
        $family = (string) ($pairing['heading']['resolved'] ?? '');

        return $family !== '' ? $family : 'helvetica';
    }

    /**
     * Record the seal as an operation list.
     *
     * @param render_context $ctx the render context
     * @param float $size the seal diameter in millimetres
     * @param callable|null $measurer exact width measurer, or null for the fallback table
     * @return canvas the canvas holding the recorded operations
     */
    protected function record(render_context $ctx, float $size, ?callable $measurer): canvas {
        $c = new canvas($size, $size, $ctx->get_palette(), $this->seed());
        $c->set_measurer($measurer);
        seal_engine::draw($c, 0.5, 0.5, $size / 2.0, $this->spec($ctx));
        return $c;
    }

    /**
     * A stable seed for this element.
     *
     * @return int the seed
     */
    protected function seed(): int {
        return (int) abs(crc32($this->type() . '|' . (string) $this->config('toptext', ''))) ?: 1;
    }

    /**
     * The seal artwork as SVG, for the designer canvas and for baking.
     *
     * @param render_context $ctx the render context
     * @return string the SVG markup
     */
    public function resolve(render_context $ctx) {
        $size = $this->seal_size();
        $c = $this->record($ctx, $size, null);
        return (new svg_painter('seal' . $this->get_id()))->render($c->ops(), $size, $size, false);
    }

    /**
     * The diameter the seal is drawn at.
     *
     * @return float millimetres
     */
    protected function seal_size(): float {
        $size = min($this->get_width(), $this->get_height());
        return $size > 0 ? $size : 34.0;
    }

    /**
     * Draw the seal into the certificate.
     *
     * @param \pdf $pdf the PDF being built
     * @param render_context $ctx the render context
     * @return void
     */
    public function render_pdf(\pdf $pdf, render_context $ctx): void {
        if ($this->is_hidden()) {
            return;
        }

        $size = $this->seal_size();
        [$left, $top] = $this->box_origin($size, $size);

        // The exact metrics of whatever font is actually loaded, so a long
        // institution name is fitted against reality rather than an estimate.
        $measurer = function (string $text, string $family, string $style, float $sizept) use ($pdf) {
            $pdf->SetFont($family, $style, $sizept);
            return $pdf->GetStringWidth($text);
        };

        $before = ['family' => $pdf->getFontFamily(), 'style' => $pdf->getFontStyle(),
            'size' => $pdf->getFontSizePt()];

        $c = $this->record($ctx, $size, $measurer);
        (new tcpdf_painter($pdf, $left, $top))->paint($c->ops());

        // Measuring changed the current font; put it back so the elements
        // drawn after this one are unaffected.
        $pdf->SetFont($before['family'], $before['style'], $before['size']);
    }

    /**
     * Produce the designer canvas markup.
     *
     * @param render_context $ctx the render context
     * @return string the markup
     */
    public function render_html(render_context $ctx): string {
        return \html_writer::div(
            (string) $this->resolve($ctx),
            'certificatepro-element-seal',
            ['role' => 'img', 'aria-label' => get_string('sealaltext', 'mod_certificatepro')]
        );
    }
}
