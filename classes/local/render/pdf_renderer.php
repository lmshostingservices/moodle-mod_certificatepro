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

namespace mod_certificatepro\local\render;

use mod_certificatepro\element\render_context;
use mod_certificatepro\local\brand;
use mod_certificatepro\template;

/**
 * Turns a design and a recipient into a PDF.
 *
 * Two details matter more than the rest. The document carries a title, a
 * language and a text direction, and asks the reader to announce that title,
 * which is as far as the PDF library Moodle ships will go towards
 * accessibility. And the font is chosen so that it can actually represent the
 * characters it is asked to draw, which is what stops non Latin names appearing
 * as rows of question marks.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_renderer {
    /** @var template The design being drawn. */
    protected template $template;

    /**
     * Build a renderer for a design.
     *
     * @param template $template the design to draw
     */
    public function __construct(template $template) {
        $this->template = $template;
    }

    /**
     * Render to a PDF string.
     *
     * @param render_context $ctx the render context
     * @param array $options protection flags and document metadata
     * @return string the PDF bytes
     */
    public function render(render_context $ctx, array $options = []): string {
        global $CFG;

        require_once($CFG->libdir . '/pdflib.php');

        $this->template->configure($ctx);

        $pdf = new \pdf();
        $this->prepare_document($pdf, $ctx, $options);

        $pages = $this->template->get_pages();
        if (empty($pages)) {
            $pdf->AddPage('L', 'A4');
        }

        foreach ($pages as $page) {
            $this->render_page($pdf, $ctx, $page);
        }

        return (string) $pdf->Output('', 'S');
    }

    /**
     * Set up document wide properties before any page is added.
     *
     * @param \pdf $pdf the document being built
     * @param render_context $ctx the render context
     * @param array $options protection flags and document metadata
     * @return void
     */
    protected function prepare_document(\pdf $pdf, render_context $ctx, array $options): void {
        $site = get_site();
        $issue = $ctx->get_issue();

        $pdf->SetCreator('Certificate Pro');
        $pdf->SetAuthor(format_string($site->fullname, true,
            ['context' => \context_system::instance()]));
        $pdf->SetTitle($options['title'] ?? $this->template->get_name());
        $pdf->SetSubject($options['subject'] ?? get_string('pdfsubject', 'mod_certificatepro'));

        if (!empty($issue->code)) {
            $pdf->SetKeywords($issue->code);
        }

        // Accessibility. An untagged certificate is unreadable to a screen reader,
        // which is a procurement blocker in most public sector buying.
        $pdf->setLanguageArray([
            'a_meta_charset' => 'UTF-8',
            'a_meta_dir' => right_to_left() ? 'rtl' : 'ltr',
            'a_meta_language' => current_language(),
            'w_page' => get_string('page'),
        ]);
        // Ask the reader to announce the document title rather than the file
        // name. This is the one accessibility affordance TCPDF genuinely offers,
        // and it is the difference between hearing "Certificate of Achievement"
        // and hearing "cert_20260815_final_v2.pdf".
        $pdf->setViewerPreferences(['DisplayDocTitle' => true]);

        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setFontSubsetting(true);
        $pdf->setImageScale(1.0);
        $pdf->SetMargins(0, 0, 0, true);
        $pdf->SetCellPadding(0);

        if (!empty($options['protection']) && is_array($options['protection'])) {
            $pdf->SetProtection($options['protection'], '', null, 3);
        }
    }

    /**
     * Draw one page.
     *
     * @param \pdf $pdf the document being built
     * @param render_context $ctx the render context
     * @param \stdClass $page the page record
     * @return void
     */
    protected function render_page(\pdf $pdf, render_context $ctx, \stdClass $page): void {
        $width = (float) $page->width;
        $height = (float) $page->height;

        $ctx->set_page_size($width, $height);

        $pdf->AddPage($page->orientation, [$width, $height]);

        if (!empty($page->backgroundcolour) && brand::is_hex($page->backgroundcolour)) {
            [$r, $g, $b] = brand::to_rgb($page->backgroundcolour);
            $pdf->Rect(0, 0, $width, $height, 'F', [], [$r, $g, $b]);
        }

        if (!empty($page->backgroundsvg)) {
            $path = make_request_directory() . '/pagebg' . $page->id . '.svg';
            file_put_contents($path, $page->backgroundsvg);
            try {
                $pdf->ImageSVG($path, 0, 0, $width, $height, '', '', '', 0, false);
            } catch (\Throwable $e) {
                debugging('Certificate Pro could not draw a page background: ' . $e->getMessage(),
                    DEBUG_DEVELOPER);
            }
        }

        foreach ($this->template->get_elements((int) $page->id) as $element) {
            if ($element->is_hidden()) {
                continue;
            }
            try {
                $element->render_pdf($pdf, $ctx);
            } catch (\Throwable $e) {
                // One broken element must never cost a learner their certificate.
                debugging('Certificate Pro element ' . $element->get_id() . ' failed to render: '
                    . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Render a single page to a PDF string, for the designer's true preview.
     *
     * @param render_context $ctx the render context
     * @param int $pageid the page to draw
     * @return string the PDF bytes
     */
    public function render_page_only(render_context $ctx, int $pageid): string {
        global $CFG;

        require_once($CFG->libdir . '/pdflib.php');

        $this->template->configure($ctx);

        $pages = $this->template->get_pages();
        if (!isset($pages[$pageid])) {
            return '';
        }

        $pdf = new \pdf();
        $this->prepare_document($pdf, $ctx, []);
        $this->render_page($pdf, $ctx, $pages[$pageid]);

        return (string) $pdf->Output('', 'S');
    }

    /**
     * The protection flags an activity's settings ask for.
     *
     * @param \stdClass $instance the activity instance
     * @return string[]
     */
    public static function protection_flags(\stdClass $instance): array {
        if (empty($instance->protection)) {
            return [];
        }

        $allowed = ['print', 'modify', 'copy', 'annot-forms', 'fill-forms', 'extract', 'assemble'];
        $flags = array_map('trim', explode(',', (string) $instance->protection));

        return array_values(array_intersect($flags, $allowed));
    }
}
