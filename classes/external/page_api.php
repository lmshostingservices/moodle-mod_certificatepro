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
use mod_certificatepro\element\render_context;
use mod_certificatepro\template;

/**
 * The services behind the designer's structural edits.
 *
 * A design's page count used to be settled the moment it was created from a
 * starter: multi-page rendering worked, but nothing could ever make a second
 * page, take one away or put the second sheet first. {@see self::add_page()},
 * {@see self::delete_page()} and {@see self::reorder_pages()} are what the
 * designer's page strip drives, and all three return the whole design rather
 * than the pages they touched, because all three change the shape of the design
 * itself — the page numbering, which page is current, and which pages exist at
 * all — and handing back only the affected page would leave the browser to
 * guess the rest.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class page_api extends library_base {
    /**
     * A preview context that knows which course and activity the design serves.
     *
     * The designer page builds its context with the course and the activity so
     * that an element resolves the real course name and the viewer's real grade.
     * A context built from the design's context alone makes every element fall
     * back to its sample string, so a design that had just gained or lost a page
     * would come back reading as somebody else's certificate.
     *
     * @param template $template the design being edited
     * @return render_context a context carrying the course and activity when there is one
     */
    protected static function preview_context(template $template): render_context {
        global $DB;

        $context = $template->get_context();

        // A design owned by the system context is a site-wide template with no
        // course behind it, so samples are genuinely the right answer there.
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return render_context::preview($context);
        }

        $cm = get_coursemodule_from_id('certificatepro', $context->instanceid, 0, false,
            IGNORE_MISSING);
        if (!$cm) {
            return render_context::preview($context);
        }

        $course = $DB->get_record('course', ['id' => $cm->course]);
        $instance = $DB->get_record('certificatepro', ['id' => $cm->instance]);

        return render_context::preview($context, $course ?: null, $instance ?: null);
    }

    /**
     * The design as the designer bootstraps from, as JSON.
     *
     * @param template $template the design to export
     * @return string the design as JSON
     */
    protected static function export_design(template $template): string {
        return json_encode($template->export_for_designer(self::preview_context($template)),
            JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Confirm every page of a design is numbered 1, 2, 3 with nothing missing.
     *
     * Page order is what the print follows and what the page strip numbers its
     * tabs from, so a gap left behind by an add or a delete would show as a
     * design whose second sheet is called page three. The design renumbers after
     * a delete; this is the check that the promise was actually kept, and it
     * repairs the numbering rather than leaving a design an author cannot fix.
     *
     * @param template $template the design to check
     * @return void
     */
    protected static function ensure_contiguous(template $template): void {
        global $DB;

        $sequence = 1;
        foreach ($template->get_pages() as $page) {
            if ((int) $page->sequence !== $sequence) {
                $DB->set_field('certificatepro_pages', 'sequence', $sequence, ['id' => $page->id]);
            }
            $sequence++;
        }
    }

    /**
     * Parameters for adding a page.
     *
     * @return external_function_parameters
     */
    public static function add_page_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design to add a page to'),
            'orientation' => new external_value(PARAM_ALPHA, 'L or P', VALUE_DEFAULT, ''),
            'pagesize' => new external_value(PARAM_ALPHANUMEXT,
                'A key from the page size catalogue', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Add a page to the end of a design.
     *
     * The paper and orientation default to those of the design's last page,
     * because a certificate whose second sheet is a different shape from its
     * first is a mistake far more often than it is a decision. An unrecognised
     * value is normalised by the design rather than refused, exactly as changing
     * a page's shape is: the only way to send one is a tampered request, and
     * landing on A4 landscape is a safer answer to that than a broken page.
     *
     * @param int $templateid the design to add a page to
     * @param string $orientation L or P, or empty to follow the last page
     * @param string $pagesize a page size key, or empty to follow the last page
     * @return array
     */
    public static function add_page(int $templateid, string $orientation = '',
            string $pagesize = ''): array {
        $params = self::validate_parameters(self::add_page_parameters(), [
            'templateid' => $templateid,
            'orientation' => $orientation,
            'pagesize' => $pagesize,
        ]);

        $template = self::require_editable($params['templateid']);

        $pages = $template->get_pages();
        $last = end($pages);

        $orientation = $params['orientation'] !== ''
            ? $params['orientation']
            : ($last ? $last->orientation : 'L');
        $pagesize = $params['pagesize'] !== ''
            ? $params['pagesize']
            : ($last ? $last->pagesize : 'A4');

        $pageid = $template->add_page($orientation, $pagesize);
        self::ensure_contiguous($template);

        \mod_certificatepro\event\template_updated::create([
            'objectid' => $template->get_id(),
            'context' => $template->get_context(),
        ])->trigger();

        return [
            'pageid' => $pageid,
            'design' => self::export_design($template),
        ];
    }

    /**
     * What adding a page returns.
     *
     * @return external_single_structure
     */
    public static function add_page_returns(): external_single_structure {
        return new external_single_structure([
            'pageid' => new external_value(PARAM_INT, 'The new page'),
            'design' => new external_value(
                PARAM_RAW, // pipeline-ignore: PARAM_RAW - JSON, decoded by caller
                'The design with the new page in it, as JSON'),
        ]);
    }

    /**
     * Parameters for removing a page.
     *
     * @return external_function_parameters
     */
    public static function delete_page_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'pageid' => new external_value(PARAM_INT, 'The page to remove'),
        ]);
    }

    /**
     * Remove a page and everything on it.
     *
     * The last page cannot go. A design with no pages has nothing to print, and
     * every part of the plugin that reads a design — the canvas, the preview,
     * the PDF — assumes there is a first page to read, so the refusal is here
     * rather than left for one of them to fail on later.
     *
     * @param int $templateid the design being edited
     * @param int $pageid the page to remove
     * @return array
     */
    public static function delete_page(int $templateid, int $pageid): array {
        $params = self::validate_parameters(self::delete_page_parameters(), [
            'templateid' => $templateid,
            'pageid' => $pageid,
        ]);

        $template = self::require_editable($params['templateid']);

        // A page id of zero means "the first page" to require_page, which is
        // useful when reading and dangerous when deleting: the delete itself
        // would match nothing and report success on a page still there.
        if ($params['pageid'] < 1) {
            throw new \moodle_exception('errorpagenotfound', 'mod_certificatepro');
        }

        self::require_page($template, $params['pageid']);

        if (count($template->get_pages()) < 2) {
            throw new \moodle_exception('errorlastpage', 'mod_certificatepro');
        }

        // The design takes every element on the page with it and renumbers what
        // is left; this only confirms the renumbering held.
        $template->delete_page($params['pageid']);
        self::ensure_contiguous($template);

        \mod_certificatepro\event\template_updated::create([
            'objectid' => $template->get_id(),
            'context' => $template->get_context(),
        ])->trigger();

        return ['design' => self::export_design($template)];
    }

    /**
     * What removing a page returns.
     *
     * @return external_single_structure
     */
    public static function delete_page_returns(): external_single_structure {
        return new external_single_structure([
            'design' => new external_value(
                PARAM_RAW, // pipeline-ignore: PARAM_RAW - JSON, decoded by caller
                'The design without the page, as JSON'),
        ]);
    }

    /**
     * Parameters for rearranging pages.
     *
     * @return external_function_parameters
     */
    public static function reorder_pages_parameters(): external_function_parameters {
        return new external_function_parameters([
            'templateid' => new external_value(PARAM_INT, 'The design being edited'),
            'pageids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'A page id'),
                'Every page of the design, in the order wanted'
            ),
        ]);
    }

    /**
     * Put a design's pages into a new order.
     *
     * The caller states the whole order rather than one page and where it is to
     * land, so that a strip drawn from a design that has since changed cannot
     * half-apply: either the list is exactly this design's pages rearranged and
     * the order is taken, or it describes a design that no longer exists and
     * nothing moves.
     *
     * @param int $templateid the design being edited
     * @param array $pageids every page of the design, in the order wanted
     * @return array
     */
    public static function reorder_pages(int $templateid, array $pageids): array {
        $params = self::validate_parameters(self::reorder_pages_parameters(), [
            'templateid' => $templateid,
            'pageids' => $pageids,
        ]);

        $template = self::require_editable($params['templateid']);

        if (!$template->reorder_pages($params['pageids'])) {
            throw new \moodle_exception('errorpageorder', 'mod_certificatepro');
        }

        self::ensure_contiguous($template);

        \mod_certificatepro\event\template_updated::create([
            'objectid' => $template->get_id(),
            'context' => $template->get_context(),
        ])->trigger();

        return ['design' => self::export_design($template)];
    }

    /**
     * What rearranging pages returns.
     *
     * @return external_single_structure
     */
    public static function reorder_pages_returns(): external_single_structure {
        return new external_single_structure([
            'design' => new external_value(
                PARAM_RAW, // pipeline-ignore: PARAM_RAW - JSON, decoded by caller
                'The design in its new order, as JSON'),
        ]);
    }
}
