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
use mod_certificatepro\local\ai\artwork_engine;
use mod_certificatepro\local\ai\background_repository;
use mod_certificatepro\local\art\background_library;
use mod_certificatepro\local\art\canvas;
use mod_certificatepro\local\art\svg_painter;
use mod_certificatepro\local\art\tcpdf_painter;
use mod_certificatepro\local\brand;

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
     * @var array|null|false The redraw recipe, or null when there is none.
     *
     * False until it has been looked up, so "no recipe" is cached as firmly as
     * a recipe is and a model-composed background costs one query, not one per
     * call.
     */
    protected $recipe = false;

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
     * The recipe that redraws this background, when it has one.
     *
     * Looked up once per element per request. The answer cannot change inside a
     * render — the row it comes from is written when the artwork is generated
     * and not touched afterwards — and a bulk issue run asks for it once per
     * certificate, so one read saved is two thousand reads saved.
     *
     * @return array{style: string, seed: int, palette: array<string, string>}|null
     */
    protected function recipe(): ?array {
        if ($this->recipe === false) {
            $this->recipe = background_repository::recipe(
                (int) $this->config('backgroundid', 0));
        }

        return $this->recipe;
    }

    /**
     * Record this background as an operation list at the page's own size.
     *
     * @param render_context $ctx the render context
     * @param array $recipe the recipe from {@see recipe()}
     * @return canvas|null the canvas holding the operations, or null if nothing drew
     */
    protected function record(render_context $ctx, array $recipe): ?canvas {
        $c = new canvas(
            $ctx->get_page_width(),
            $ctx->get_page_height(),
            // The palette the artwork was drawn from, not the one the page's
            // text resolves against. ground::adapt_palette() lifts the text
            // roles off a dark ground; lifting the ground itself would be
            // chasing its own tail, and would put the printed artwork in
            // colours the designer canvas never showed.
            $recipe['palette'],
            $recipe['seed']
        );

        return background_library::record($recipe['style'], $c) ? $c : null;
    }

    /**
     * The artwork as SVG.
     *
     * A background the style catalogue drew is redrawn here rather than read
     * back as markup, so the canvas is a rendering of the same operation list
     * the print replays, taken at the sheet the design is on today. For a
     * background only a model could have made, the stored markup is the
     * artwork and is returned as it was saved.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function resolve(render_context $ctx) {
        $recipe = $this->recipe();

        if ($recipe !== null) {
            $c = $this->record($ctx, $recipe);
            if ($c !== null) {
                return self::svg_painter_for($recipe)
                    ->render($c->ops(), $ctx->get_page_width(), $ctx->get_page_height());
            }
        }

        return background_repository::svg((int) $this->config('backgroundid', 0), $ctx);
    }

    /**
     * The SVG painter a recipe's markup is produced by.
     *
     * {@see background_library::painter()} owns the identifier prefix, so
     * redrawing a stored background produces the markup that is already on the
     * row rather than the same drawing under different element ids. That is
     * what makes this change a measurable no-op for the canvas.
     *
     * @param array $recipe the recipe
     * @return svg_painter
     */
    protected static function svg_painter_for(array $recipe): svg_painter {
        return background_library::painter($recipe['style'], $recipe['seed']);
    }

    /**
     * Draw the artwork across the whole page.
     *
     * Two paths, and which one a background takes is decided by whether it can
     * be redrawn, never by inspecting its markup.
     *
     * A background the style catalogue drew is replayed through
     * {@see tcpdf_painter}, which is the same thing the award seal does and for
     * the same reason: TCPDF's SVG parser does not render gradients. It reduces
     * every one of them to a single flat fill of the last stop, so a wash that
     * swept from deep blue to near white printed as a slab of deep blue — and
     * the designer canvas, drawn by a browser that renders the gradient
     * properly, disagreed with the paper on every background in the catalogue
     * that uses one. Which is all but three of the twenty-six.
     *
     * A background a model composed cannot be redrawn: its markup is the
     * original artwork and the only copy of it. It keeps the SVG parser, and
     * so does any procedural background whose recipe is incomplete — a row
     * written before the recipe was recorded, or a style the catalogue has
     * since dropped. Those print exactly as they printed before.
     *
     * @param \pdf $pdf the PDF being built
     * @param render_context $ctx the render context
     * @return void
     */
    public function render_pdf(\pdf $pdf, render_context $ctx): void {
        if ($this->is_hidden()) {
            return;
        }

        $opacity = max(0.0, min(1.0, (float) $this->config('opacity', 1.0)));

        $recipe = $this->recipe();
        if ($recipe !== null && $this->render_pdf_native($pdf, $ctx, $recipe, $opacity)) {
            return;
        }

        $svg = (string) $this->resolve_cached($ctx);
        if ($svg === '') {
            return;
        }

        if ($opacity < 1.0) {
            $pdf->SetAlpha($opacity);
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
     * Replay a recorded background onto the page.
     *
     * Returns false rather than throwing if anything goes wrong, so the caller
     * falls back to the stored markup. A background is never worth a learner's
     * award, and a flattened gradient is a far better outcome than a blank
     * page.
     *
     * @param \pdf $pdf the PDF being built
     * @param render_context $ctx the render context
     * @param array $recipe the recipe from {@see recipe()}
     * @param float $opacity the element's opacity
     * @return bool whether the artwork was drawn
     */
    protected function render_pdf_native(\pdf $pdf, render_context $ctx, array $recipe,
            float $opacity): bool {
        try {
            $c = $this->record($ctx, $recipe);
            if ($c === null) {
                return false;
            }
            $ops = $c->ops();

            // The snapshot carried the artwork as markup before this change and
            // still does, so an issue whose stored background is later deleted
            // can still be redrawn from its own record. It is written from the
            // operation list that is about to be painted, so the two cannot
            // disagree, and only while an issue is being minted — a preview and
            // a snapshot replay both have better answers already.
            if ($ctx->get_mode() === render_context::MODE_ISSUE) {
                $ctx->record_value($this->get_id(), self::svg_painter_for($recipe)
                    ->render($ops, $ctx->get_page_width(), $ctx->get_page_height()));
            }

            if ($opacity < 1.0) {
                $pdf->SetAlpha($opacity);
            }

            (new tcpdf_painter($pdf, 0.0, 0.0))->paint($ops);

            if ($opacity < 1.0) {
                $pdf->SetAlpha(1.0);
            }

            return true;
        } catch (\Throwable $e) {
            debugging('Certificate Pro could not replay generated artwork: ' . $e->getMessage(),
                DEBUG_DEVELOPER);
            return false;
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
