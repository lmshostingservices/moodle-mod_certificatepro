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

namespace mod_certificatepro\output;

use mod_certificatepro\element\registry;
use mod_certificatepro\element\render_context;
use mod_certificatepro\local\font_manager;
use mod_certificatepro\local\issue_service;
use mod_certificatepro\template;

/**
 * Draws the plugin's pages.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * The page a learner sees.
     *
     * @param \stdClass $instance the activity instance
     * @param \stdClass $course the course
     * @param \cm_info $cm the course module
     * @param \context $context the module context
     * @param \stdClass|null $issue the learner's issue, when they have one
     * @param string[] $unmet the criteria they have not met
     * @return string
     */
    public function render_learner_view(\stdClass $instance, \stdClass $course, \cm_info $cm,
            \context $context, ?\stdClass $issue, array $unmet): string {
        $output = \html_writer::start_div('certificatepro-learner');

        if ($issue !== null) {
            $status = issue_service::status($issue);
            $output .= $this->status_panel($status, $issue);

            if ($status === 'valid' || $status === 'expired') {
                $download = new \moodle_url('/mod/certificatepro/view.php', [
                    'id' => $cm->id,
                    'download' => 1,
                    'sesskey' => sesskey(),
                ]);
                $badge = new \moodle_url('/mod/certificatepro/view.php', [
                    'id' => $cm->id,
                    'badge' => 1,
                    'sesskey' => sesskey(),
                ]);
                // Deliberately the plain three-argument form. The typed
                // button constants are a newer core addition, and depending on
                // them would raise this plugin's minimum Moodle for nothing —
                // the default styling is already what these two buttons want.
                $output .= \html_writer::div(
                    $this->output->single_button($download,
                        get_string('downloadcertificate', 'mod_certificatepro'), 'get')
                    . $this->output->single_button($badge,
                        get_string('downloadbadge', 'mod_certificatepro'), 'get'),
                    'certificatepro-actions mt-3'
                );
                $output .= \html_writer::div(
                    get_string('badgehint', 'mod_certificatepro'),
                    'certificatepro-badgehint text-muted small mt-1'
                );
            }

            $verify = new \moodle_url('/mod/certificatepro/verify.php', ['code' => $issue->code]);
            $output .= \html_writer::div(
                get_string('verifyhint', 'mod_certificatepro', [
                    'code' => \html_writer::tag('code', s($issue->code)),
                    'url' => \html_writer::link($verify, $verify->out(false)),
                ]),
                'certificatepro-verifyhint text-muted mt-3'
            );
        } else if (!empty($unmet)) {
            $items = '';
            foreach ($unmet as $reason) {
                $items .= \html_writer::tag('li', s($reason));
            }
            $output .= $this->output->notification(
                get_string('notyetavailable', 'mod_certificatepro')
                    . \html_writer::tag('ul', $items, ['class' => 'mt-2 mb-0']),
                'info'
            );
        } else {
            $output .= $this->output->notification(
                get_string('notissuedyet', 'mod_certificatepro'), 'info');
        }

        $output .= \html_writer::end_div();

        return $output;
    }

    /**
     * The control that packs every issued certificate into one zip.
     *
     * A plain form rather than a link, because this is an export of named
     * learners' documents and a GET would leave the whole cohort in browser
     * history and in the next referrer header. The two choices are offered
     * rather than assumed: an expired certificate is a true record of an award
     * that lapsed and is included by default, while a revoked one has been
     * withdrawn and is left out until the teacher deliberately asks for it.
     *
     * @param \cm_info $cm the course module
     * @param array $counts total, revoked and expired issue counts
     * @return string
     */
    public function render_download_all(\cm_info $cm, array $counts): string {
        $total = (int) ($counts['total'] ?? 0);

        if ($total === 0) {
            return '';
        }

        $revoked = (int) ($counts['revoked'] ?? 0);
        $expired = (int) ($counts['expired'] ?? 0);

        $action = new \moodle_url('/mod/certificatepro/report.php');

        $output = \html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $action->out(false),
            'class' => 'certificatepro-downloadall d-flex flex-wrap align-items-center gap-3 mb-3',
        ]);
        $output .= \html_writer::empty_tag('input',
            ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
        $output .= \html_writer::empty_tag('input',
            ['type' => 'hidden', 'name' => 'action', 'value' => 'downloadall']);
        $output .= \html_writer::empty_tag('input',
            ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

        $output .= \html_writer::tag('button', get_string('downloadall', 'mod_certificatepro'), [
            'type' => 'submit',
            'class' => 'btn btn-secondary',
            'id' => 'certificatepro-downloadall',
        ]);

        // Unchecked boxes are simply absent from a POST, so each carries a
        // hidden zero ahead of it. Without one, clearing "include expired"
        // would fall back to the parameter default and silently include them.
        $output .= $this->download_all_option('includeexpired', 1,
            get_string('downloadallexpired', 'mod_certificatepro', $expired));
        $output .= $this->download_all_option('includerevoked', 0,
            get_string('downloadallrevoked', 'mod_certificatepro', $revoked));

        $output .= \html_writer::div(
            get_string('downloadallcount', 'mod_certificatepro', $total),
            'text-muted small w-100 mt-1'
        );

        $output .= \html_writer::end_tag('form');

        return $output;
    }

    /**
     * One checkbox on the bulk download form.
     *
     * @param string $name the parameter name
     * @param int $checked whether it starts ticked
     * @param string $label the visible label
     * @return string
     */
    protected function download_all_option(string $name, int $checked, string $label): string {
        $id = 'certificatepro-' . $name;

        $output = \html_writer::empty_tag('input',
            ['type' => 'hidden', 'name' => $name, 'value' => 0]);

        $attributes = [
            'type' => 'checkbox',
            'name' => $name,
            'id' => $id,
            'value' => 1,
            'class' => 'form-check-input m-0',
        ];
        if ($checked) {
            $attributes['checked'] = 'checked';
        }

        return \html_writer::div(
            $output . \html_writer::empty_tag('input', $attributes)
                . \html_writer::tag('label', $label,
                    ['for' => $id, 'class' => 'form-check-label ms-1 mb-0']),
            'form-check d-flex align-items-center mb-0'
        );
    }

    /**
     * A coloured panel describing the state of an issued certificate.
     *
     * @param string $status valid, expired, revoked, pending or unknown
     * @param \stdClass $issue the issue record
     * @return string
     */
    protected function status_panel(string $status, \stdClass $issue): string {
        $classes = [
            'valid' => 'alert-success',
            'expired' => 'alert-warning',
            'revoked' => 'alert-danger',
            // A cycle document still being rendered is a warning rather than a
            // failure: it is on its way, and the holder has nothing to correct.
            'pending' => 'alert-warning',
        ];

        $lines = [
            \html_writer::tag('strong', get_string('status_' . $status, 'mod_certificatepro')),
        ];

        $lines[] = get_string('issuedon', 'mod_certificatepro',
            userdate($issue->timecreated, get_string('strftimedaydate', 'langconfig')));

        if (!empty($issue->serial)) {
            $lines[] = get_string('serialis', 'mod_certificatepro', s($issue->serial));
        }
        if (!empty($issue->expires)) {
            $lines[] = get_string('expireson', 'mod_certificatepro',
                userdate($issue->expires, get_string('strftimedaydate', 'langconfig')));
        }
        if ($status === 'revoked' && !empty($issue->revokedreason)) {
            $lines[] = get_string('revokedbecause', 'mod_certificatepro', s($issue->revokedreason));
        }

        return \html_writer::div(
            implode(\html_writer::empty_tag('br'), $lines),
            'alert ' . ($classes[$status] ?? 'alert-info')
        );
    }

    /**
     * The public verification result.
     *
     * @param string $code the code that was checked
     * @param string|null $result valid, expired, revoked, unknown or null when nothing was checked
     * @param \stdClass|null $issue the issue, when one was found
     * @param \stdClass|null $instance the activity, when one was found
     * @param \stdClass|null $recipient the holder, when one was found
     * @return string
     */
    public function render_verification(string $code, ?string $result, ?\stdClass $issue,
            ?\stdClass $instance, ?\stdClass $recipient): string {
        $output = \html_writer::start_div('certificatepro-verify container');

        $form = \html_writer::start_tag('form', [
            'method' => 'get',
            'action' => (new \moodle_url('/mod/certificatepro/verify.php'))->out(false),
            'class' => 'certificatepro-verify-form mb-4',
        ]);
        $form .= \html_writer::tag('label', get_string('verifycodelabel', 'mod_certificatepro'), [
            'for' => 'certificatepro-code',
            'class' => 'form-label',
        ]);
        $form .= \html_writer::empty_tag('input', [
            'type' => 'text',
            'id' => 'certificatepro-code',
            'name' => 'code',
            'value' => s($code),
            'class' => 'form-control form-control-lg',
            'placeholder' => 'CP-0000-0000-0000',
            'autocomplete' => 'off',
            'spellcheck' => 'false',
        ]);
        $form .= \html_writer::tag('button', get_string('verifysubmit', 'mod_certificatepro'), [
            'type' => 'submit',
            'class' => 'btn btn-primary mt-2',
        ]);
        $form .= \html_writer::end_tag('form');

        $output .= $form;

        if ($result !== null) {
            $output .= $this->verification_result($result, $issue, $instance, $recipient);
        }

        $output .= \html_writer::end_div();

        return $output;
    }

    /**
     * The panel describing what a checked code turned out to be.
     *
     * @param string $result valid, expired, revoked or unknown
     * @param \stdClass|null $issue the issue, when one was found
     * @param \stdClass|null $instance the activity, when one was found
     * @param \stdClass|null $recipient the holder, when one was found
     * @return string
     */
    protected function verification_result(string $result, ?\stdClass $issue,
            ?\stdClass $instance, ?\stdClass $recipient): string {
        if ($result === 'unknown' || $issue === null) {
            return \html_writer::div(
                \html_writer::tag('h3', get_string('verifyunknown', 'mod_certificatepro'))
                    . \html_writer::tag('p', get_string('verifyunknowndetail', 'mod_certificatepro')),
                'alert alert-danger'
            );
        }

        $classes = [
            'valid' => 'alert-success',
            'expired' => 'alert-warning',
            'revoked' => 'alert-danger',
        ];

        $rows = [];
        if ($recipient !== null) {
            $rows[get_string('verifyawardedto', 'mod_certificatepro')] = fullname($recipient);
        }
        if ($instance !== null) {
            $rows[get_string('verifycertificate', 'mod_certificatepro')] =
                format_string($instance->name);
            $rows[get_string('verifycourse', 'mod_certificatepro')] =
                format_string($instance->coursefullname);
        }
        $rows[get_string('verifyissued', 'mod_certificatepro')] =
            userdate($issue->timecreated, get_string('strftimedaydate', 'langconfig'));

        if (!empty($issue->serial)) {
            $rows[get_string('verifyserial', 'mod_certificatepro')] = s($issue->serial);
        }
        if (!empty($issue->expires)) {
            $rows[get_string('verifyexpires', 'mod_certificatepro')] =
                userdate($issue->expires, get_string('strftimedaydate', 'langconfig'));
        }

        // The person checking the code is being told the certificate was
        // withdrawn; leaving out why makes that an accusation they cannot weigh.
        // The wording was typed by staff in the knowledge that revoking is
        // announced, so it belongs here rather than only on the holder's page.
        if ($result === 'revoked' && !empty($issue->revokedreason)) {
            $rows[get_string('revokereason', 'mod_certificatepro')] = s($issue->revokedreason);
        }

        $table = new \html_table();
        $table->attributes['class'] = 'table table-sm mb-0 mt-3';
        foreach ($rows as $label => $value) {
            $table->data[] = [\html_writer::tag('strong', $label), $value];
        }

        return \html_writer::div(
            \html_writer::tag('h3', get_string('verify_' . $result, 'mod_certificatepro'))
                . \html_writer::tag('p', get_string('verify_' . $result . '_detail',
                    'mod_certificatepro'))
                . \html_writer::table($table),
            'alert ' . ($classes[$result] ?? 'alert-info')
        );
    }

    /**
     * The designer.
     *
     * @param template $template the design being edited
     * @param render_context $ctx the render context
     * @param \cm_info $cm the course module
     * @return string
     */
    public function render_designer(template $template, render_context $ctx, \cm_info $cm): string {
        $data = $template->export_for_designer($ctx);
        $firstpage = $data['pages'][0]['id'] ?? 0;

        // The bootstrap payload travels on a data attribute rather than as a
        // js_call_amd argument. Moodle inlines those arguments into a script
        // tag and warns past 1024 characters, which a real design exceeds
        // immediately — and inlining a whole document into markup is wasteful
        // besides. Both modules read it back from the root element.
        $bootstrap = [
            'template' => $data,
            'cmid' => (int) $cm->id,
            'templateid' => (int) $template->get_id(),
            'pageid' => (int) $firstpage,
            'filetypes' => $this->designer_file_types(),
        ];

        $this->page->requires->js_call_amd('mod_certificatepro/designer', 'init',
            ['#certificatepro-designer']);
        $this->page->requires->js_call_amd('mod_certificatepro/library', 'init',
            ['#certificatepro-designer']);

        // Serving the real font files to the browser is what makes the canvas
        // and the PDF agree about how wide a word is.
        $fontcss = font_manager::web_font_css();
        $output = $fontcss !== '' ? \html_writer::tag('style', $fontcss) : '';

        $output .= \html_writer::start_div('certificatepro-designer', [
            'id' => 'certificatepro-designer',
            'tabindex' => '-1',
            'data-cpro-bootstrap' => json_encode($bootstrap, JSON_PRESERVE_ZERO_FRACTION),
        ]);

        $output .= $this->designer_toolbar($cm);
        $output .= $this->designer_library_bar();

        $output .= \html_writer::start_div('certificatepro-designer-body');
        $output .= $this->designer_picker();
        $output .= $this->designer_canvas();
        $output .= $this->designer_sidebar($data, $cm);
        $output .= \html_writer::end_div();

        $output .= \html_writer::div('', 'certificatepro-status', ['data-region' => 'status']);

        // What the designer says out loud. The canvas answers a keypress by
        // moving a rectangle, which is no answer at all to somebody who cannot
        // see it, so every keyboard change to the selection, the geometry or the
        // history is read out here. It is polite rather than assertive: none of
        // it is urgent enough to cut across what is already being read.
        $output .= \html_writer::div('', 'certificatepro-live cpro-visually-hidden', [
            'data-region' => 'live',
            'role' => 'status',
            'aria-live' => 'polite',
            'aria-atomic' => 'true',
        ]);

        $output .= \html_writer::end_div();

        return $output;
    }

    /**
     * The element types that own an uploaded file.
     *
     * Deleting an element deletes the files it owns, and undoing the delete
     * writes a fresh row rather than resurrecting the old one, so the picture
     * does not come back with it. The designer says so before it deletes, and
     * this is where it learns which types the warning applies to: the element
     * classes are the only thing that knows, and a list kept in the JavaScript
     * would go quietly wrong the day a new picture element is added.
     *
     * @return string[] element type identifiers
     */
    protected function designer_file_types(): array {
        $types = [];

        foreach (registry::types() as $type) {
            $class = registry::class_for($type);
            if ($class::file_areas()) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * The designer toolbar.
     *
     * @param \cm_info $cm the course module
     * @return string
     */
    protected function designer_toolbar(\cm_info $cm): string {
        // Grouped, and every group is labelled. An unlabelled row of sixteen
        // identical icon buttons is not discoverable: an author cannot tell
        // "send to back" from "align bottom" without hovering each one in turn.
        $groups = [
            ['label' => 'designer_group_history', 'buttons' => [
                ['action' => 'undo', 'icon' => 'fa-rotate-left', 'label' => 'designer_undo'],
                ['action' => 'redo', 'icon' => 'fa-rotate-right', 'label' => 'designer_redo'],
                ['action' => 'save', 'icon' => 'fa-floppy-disk', 'label' => 'designer_save'],
                ['action' => 'reset', 'icon' => 'fa-arrow-rotate-left', 'label' => 'designer_reset',
                    'variant' => 'danger'],
            ]],
            ['label' => 'designer_group_align', 'buttons' => [
                ['action' => 'align', 'value' => 'left', 'icon' => 'fa-align-left',
                    'label' => 'designer_alignleft'],
                ['action' => 'align', 'value' => 'centre', 'icon' => 'fa-align-center',
                    'label' => 'designer_aligncentre'],
                ['action' => 'align', 'value' => 'right', 'icon' => 'fa-align-right',
                    'label' => 'designer_alignright'],
                ['action' => 'align', 'value' => 'top', 'icon' => 'fa-arrow-up',
                    'label' => 'designer_aligntop'],
                ['action' => 'align', 'value' => 'middle', 'icon' => 'fa-arrows-up-down',
                    'label' => 'designer_alignmiddle'],
                ['action' => 'align', 'value' => 'bottom', 'icon' => 'fa-arrow-down',
                    'label' => 'designer_alignbottom'],
                ['action' => 'distribute', 'value' => 'horizontal',
                    'icon' => 'fa-arrows-left-right', 'label' => 'designer_distributeh'],
                ['action' => 'distribute', 'value' => 'vertical',
                    'icon' => 'fa-arrows-up-down', 'label' => 'designer_distributev'],
            ]],
            ['label' => 'designer_group_order', 'buttons' => [
                ['action' => 'tofront', 'icon' => 'fa-angles-up', 'label' => 'designer_tofront'],
                ['action' => 'toback', 'icon' => 'fa-angles-down', 'label' => 'designer_toback'],
            ]],
            ['label' => 'designer_group_edit', 'buttons' => [
                ['action' => 'duplicate', 'icon' => 'fa-clone', 'label' => 'designer_duplicate'],
                ['action' => 'delete', 'icon' => 'fa-trash', 'label' => 'designer_delete',
                    'variant' => 'danger'],
            ]],
            ['label' => 'designer_group_view', 'buttons' => [
                ['action' => 'togglezones', 'icon' => 'fa-table-cells-large',
                    'label' => 'designer_showzones', 'toggle' => true],
                ['action' => 'togglesnap', 'icon' => 'fa-magnet',
                    'label' => 'designer_snap', 'toggle' => true, 'pressed' => true],
            ]],
        ];

        // A real ARIA toolbar: one tab stop, arrow keys inside it. The role was
        // already claimed here and the designer now keeps the promise, which
        // also takes thirty-odd tab stops out of the way of an author whose
        // next stop is the canvas.
        $output = \html_writer::start_div('certificatepro-toolbar', [
            'role' => 'toolbar',
            'data-region' => 'toolbar',
            'aria-label' => get_string('designer_toolbar', 'mod_certificatepro'),
        ]);

        foreach ($groups as $group) {
            $output .= \html_writer::start_div('certificatepro-toolbar-group', [
                'role' => 'group',
                'aria-label' => get_string($group['label'], 'mod_certificatepro'),
            ]);
            $output .= \html_writer::tag('span',
                get_string($group['label'], 'mod_certificatepro'),
                ['class' => 'certificatepro-toolbar-grouplabel', 'aria-hidden' => 'true']);
            $output .= \html_writer::start_div('certificatepro-toolbar-buttons');

            foreach ($group['buttons'] as $button) {
                $label = get_string($button['label'], 'mod_certificatepro');
                $classes = 'certificatepro-toolbar-button';
                if (!empty($button['variant'])) {
                    $classes .= ' certificatepro-toolbar-' . $button['variant'];
                }
                $attributes = [
                    'type' => 'button',
                    'class' => $classes,
                    'data-action' => $button['action'],
                    'title' => $label,
                    'aria-label' => $label,
                ];
                if (isset($button['value'])) {
                    $attributes['data-value'] = $button['value'];
                }
                if (!empty($button['toggle'])) {
                    $attributes['aria-pressed'] = !empty($button['pressed']) ? 'true' : 'false';
                }
                $output .= \html_writer::tag('button',
                    \html_writer::tag('i', '', ['class' => 'fa ' . $button['icon'],
                        'aria-hidden' => 'true']),
                    $attributes);
            }

            $output .= \html_writer::end_div();
            $output .= \html_writer::end_div();
        }

        $output .= $this->designer_page_controls();
        $output .= $this->designer_view_controls($cm);
        $output .= \html_writer::end_div();

        return $output;
    }

    /**
     * Orientation and page size, which had no control at all before.
     *
     * A design's orientation used to be fixed for ever by the starter it was
     * created from, so an author who wanted portrait had to throw the design
     * away and begin again.
     *
     * @return string
     */
    protected function designer_page_controls(): string {
        $output = \html_writer::start_div('certificatepro-toolbar-group', [
            'role' => 'group',
            'aria-label' => get_string('designer_group_page', 'mod_certificatepro'),
        ]);
        $output .= \html_writer::tag('span',
            get_string('designer_group_page', 'mod_certificatepro'),
            ['class' => 'certificatepro-toolbar-grouplabel', 'aria-hidden' => 'true']);
        $output .= \html_writer::start_div('certificatepro-toolbar-buttons');

        foreach ([
            ['value' => 'L', 'icon' => 'fa-image', 'label' => 'designer_landscape'],
            ['value' => 'P', 'icon' => 'fa-portrait', 'label' => 'designer_portrait'],
        ] as $option) {
            $label = get_string($option['label'], 'mod_certificatepro');
            $output .= \html_writer::tag('button',
                \html_writer::tag('i', '', ['class' => 'fa ' . $option['icon'],
                    'aria-hidden' => 'true']),
                [
                    'type' => 'button',
                    'class' => 'certificatepro-toolbar-button',
                    'data-action' => 'orientation',
                    'data-value' => $option['value'],
                    'title' => $label,
                    'aria-label' => $label,
                    'aria-pressed' => 'false',
                ]);
        }

        $sizes = '';
        foreach (\mod_certificatepro\local\zone\zone_library::page_sizes() as $key => $size) {
            $sizes .= \html_writer::tag('option', $size['name'], ['value' => $key]);
        }
        $output .= \html_writer::tag('select', $sizes, [
            'class' => 'certificatepro-toolbar-select',
            'data-action' => 'pagesize',
            'aria-label' => get_string('designer_pagesize', 'mod_certificatepro'),
            'title' => get_string('designer_pagesize', 'mod_certificatepro'),
        ]);

        $output .= \html_writer::end_div();
        $output .= \html_writer::end_div();

        return $output;
    }

    /**
     * Zoom, the save indicator and the preview link.
     *
     * @param \cm_info $cm the course module
     * @return string
     */
    protected function designer_view_controls(\cm_info $cm): string {
        $output = \html_writer::start_div('certificatepro-toolbar-group ms-auto', [
            'role' => 'group',
            'aria-label' => get_string('designer_zoom', 'mod_certificatepro'),
        ]);
        $output .= \html_writer::tag('span',
            get_string('designer_zoom', 'mod_certificatepro'),
            ['class' => 'certificatepro-toolbar-grouplabel', 'aria-hidden' => 'true']);
        $output .= \html_writer::start_div('certificatepro-toolbar-buttons');

        foreach ([0.5, 0.75, 1, 1.5, 2] as $zoom) {
            $percent = (int) ($zoom * 100);
            $output .= \html_writer::tag('button', $percent . '%', [
                'type' => 'button',
                'class' => 'certificatepro-toolbar-button certificatepro-toolbar-zoom',
                'data-action' => 'zoom',
                'data-value' => (string) $zoom,
                'aria-pressed' => $zoom === 1.0 ? 'true' : 'false',
            ]);
        }

        $output .= \html_writer::end_div();
        $output .= \html_writer::end_div();

        $output .= \html_writer::div(
            get_string('designer_saved', 'mod_certificatepro'),
            'certificatepro-saveindicator',
            ['data-region' => 'saveindicator', 'role' => 'status', 'aria-live' => 'polite']
        );

        $output .= \html_writer::link(
            new \moodle_url('/mod/certificatepro/preview.php', ['id' => $cm->id]),
            get_string('designer_preview', 'mod_certificatepro'),
            ['class' => 'certificatepro-previewlink', 'target' => '_blank', 'rel' => 'noopener']
        );

        return $output;
    }

    /**
     * The row of buttons that opens the design libraries.
     *
     * These are kept apart from the alignment toolbar on purpose. That toolbar
     * changes one selected element; this row changes the design.
     *
     * @return string
     */
    protected function designer_library_bar(): string {
        $buttons = [
            // The procedural background catalogue and the ornament picker were
            // removed: neither produced artwork good enough to put on a
            // certificate, and offering a weak option is worse than offering
            // none. The two guided designers are the product.
            ['action' => 'artworkbrief', 'icon' => 'fa-wand-magic-sparkles',
                'label' => 'libraryartworkbrief', 'desc' => 'libraryartworkbriefdesc'],
            ['action' => 'sealbrief', 'icon' => 'fa-award',
                'label' => 'librarysealbrief', 'desc' => 'librarysealbriefdesc'],
            ['action' => 'styles', 'icon' => 'fa-font',
                'label' => 'librarystyles', 'desc' => 'librarystylesdesc'],
        ];

        $output = \html_writer::start_div('certificatepro-librarybar', [
            'role' => 'group',
            'aria-label' => get_string('librarybar', 'mod_certificatepro'),
            'data-region' => 'librarybar',
        ]);

        foreach ($buttons as $button) {
            $label = get_string($button['label'], 'mod_certificatepro');
            $description = get_string($button['desc'], 'mod_certificatepro');
            $descid = 'certificatepro-librarybar-' . $button['action'] . '-desc';

            // "Guided artwork" does not say what pressing it does. The sentence
            // that does was only ever a title attribute, which a keyboard user
            // never sees and most screen readers do not announce, so it is also
            // carried by a described-by target that is read out with the button.
            $output .= \html_writer::tag('button',
                \html_writer::tag('i', '', ['class' => 'fa ' . $button['icon'],
                    'aria-hidden' => 'true'])
                    . \html_writer::tag('span', $label),
                [
                    'type' => 'button',
                    'class' => 'btn btn-sm btn-outline-primary',
                    'data-library-action' => $button['action'],
                    'title' => $description,
                    'aria-describedby' => $descid,
                ]);
            $output .= \html_writer::span($description, 'cpro-visually-hidden',
                ['id' => $descid]);
        }

        $output .= \html_writer::end_div();

        return $output;
    }

    /**
     * The element picker.
     *
     * @return string
     */
    protected function designer_picker(): string {
        $output = \html_writer::start_div('certificatepro-picker', [
            'data-region' => 'picker',
            'role' => 'group',
            'aria-label' => get_string('designer_addelement', 'mod_certificatepro'),
        ]);
        $output .= \html_writer::tag('h3', get_string('designer_addelement', 'mod_certificatepro'),
            ['class' => 'certificatepro-panel-heading']);

        foreach (registry::picker_catalogue() as $category) {
            $output .= \html_writer::tag('h4', $category['name'],
                ['class' => 'certificatepro-picker-category']);
            $output .= \html_writer::start_div('certificatepro-picker-items');

            foreach ($category['elements'] as $element) {
                $output .= \html_writer::tag('button',
                    \html_writer::tag('i', '', ['class' => 'fa ' . $element['icon'],
                        'aria-hidden' => 'true'])
                        . \html_writer::tag('span', $element['name']),
                    [
                        'type' => 'button',
                        'class' => 'certificatepro-picker-item',
                        'data-element-type' => $element['type'],
                        'title' => $element['description'],
                    ]);
            }

            $output .= \html_writer::end_div();
        }

        $output .= \html_writer::end_div();

        return $output;
    }

    /**
     * The canvas.
     *
     * Nothing about the design is written into the markup here: the designer
     * draws the pages, the elements and the page strip itself from the
     * bootstrap payload, so this only lays out the regions it draws into.
     *
     * @return string
     */
    protected function designer_canvas(): string {
        // The canvas is focusable but not tabbable. It is where the keyboard is
        // put down when whatever held it has just been deleted — an element, a
        // page tab — so that focus never falls out of the designer and leaves
        // every shortcut dead. It is not a tab stop because there is nothing to
        // do on the canvas itself; the elements inside it are the tab stops.
        $output = \html_writer::start_div('certificatepro-canvas', [
            'data-region' => 'canvas',
            'tabindex' => '-1',
            'role' => 'group',
            'aria-label' => get_string('designer_canvas', 'mod_certificatepro'),
        ]);

        // The strip is left empty for the designer to fill. Pages are added and
        // removed while the author works, and the tab for the page on screen has
        // to be the one that looks current, so markup written once by PHP would
        // be out of date from the first click; only the container it goes in is
        // stated here.
        $output .= \html_writer::div('', 'certificatepro-pagetabs', [
            'data-region' => 'pagetabs',
            'role' => 'group',
            'aria-label' => get_string('designer_pages', 'mod_certificatepro'),
        ]);

        // The shortcuts, stated once where a screen reader will read them out
        // when the author arrives on the elements. Nothing else on the page
        // says what Enter, Control and Space, or Control and an arrow key do,
        // and a canvas whose keys have to be guessed at is not operable.
        $output .= \html_writer::div(
            get_string('designer_keyboardhelp', 'mod_certificatepro'),
            'cpro-visually-hidden',
            ['id' => 'certificatepro-designer-help']
        );

        $output .= \html_writer::start_div('certificatepro-stage', ['data-region' => 'stage']);
        $output .= \html_writer::div('', 'certificatepro-margins', ['data-region' => 'margins']);

        // The elements are a multiple selection list, not a heap of buttons: a
        // button has no selected state to report, and which of these the
        // toolbar is about to act on is the one thing an author has to know.
        $output .= \html_writer::div('', 'certificatepro-elements', [
            'data-region' => 'elements',
            'role' => 'listbox',
            'aria-multiselectable' => 'true',
            'aria-label' => get_string('designer_elements', 'mod_certificatepro'),
            'aria-describedby' => 'certificatepro-designer-help',
        ]);
        $output .= \html_writer::div('', 'certificatepro-overlay', [
            'data-region' => 'overlay',
            'aria-hidden' => 'true',
        ]);
        $output .= \html_writer::end_div();

        $output .= \html_writer::end_div();

        return $output;
    }

    /**
     * The inspector and layers sidebar.
     *
     * @param array $data the exported design
     * @param \cm_info $cm the course module
     * @return string
     */
    protected function designer_sidebar(array $data, \cm_info $cm): string {
        $output = \html_writer::start_div('certificatepro-sidebar');

        $output .= \html_writer::start_div('certificatepro-inspector', [
            'data-region' => 'inspector',
            'data-count' => '0',
            'role' => 'group',
            'aria-label' => get_string('designer_inspector', 'mod_certificatepro'),
        ]);
        $output .= \html_writer::tag('h3', get_string('designer_inspector', 'mod_certificatepro'),
            ['class' => 'certificatepro-panel-heading']);
        $output .= \html_writer::div('', 'certificatepro-inspector-name', ['data-field' => 'name']);
        $output .= \html_writer::div('', 'certificatepro-inspector-zone', ['data-field' => 'zone']);

        foreach (['posx', 'posy', 'width', 'height', 'rotation'] as $field) {
            $output .= \html_writer::start_div('certificatepro-inspector-row');
            $output .= \html_writer::tag('label',
                get_string('designer_' . $field, 'mod_certificatepro'),
                ['for' => 'certificatepro-field-' . $field]);
            $output .= \html_writer::empty_tag('input', [
                'type' => 'number',
                'step' => '0.01',
                'id' => 'certificatepro-field-' . $field,
                'class' => 'form-control form-control-sm',
                'data-field' => $field,
                'readonly' => 'readonly',
            ]);
            $output .= \html_writer::end_div();
        }

        $output .= \html_writer::link(
            new \moodle_url('/mod/certificatepro/edit.php',
                ['id' => $cm->id, 'action' => 'editelement']),
            get_string('designer_editsettings', 'mod_certificatepro'),
            ['class' => 'btn btn-sm btn-outline-primary mt-2',
             'data-region' => 'editelementlink']
        );

        $output .= \html_writer::end_div();

        $output .= \html_writer::start_div('certificatepro-layers-panel');
        $output .= \html_writer::tag('h3', get_string('designer_layers', 'mod_certificatepro'),
            ['class' => 'certificatepro-panel-heading']);
        $output .= \html_writer::div('', 'certificatepro-layers', [
            'data-region' => 'layers',
            'role' => 'group',
            'aria-label' => get_string('designer_layers', 'mod_certificatepro'),
        ]);
        $output .= \html_writer::end_div();

        $output .= \html_writer::end_div();

        return $output;
    }
}
