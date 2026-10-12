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
     * A coloured panel describing the state of an issued certificate.
     *
     * @param string $status valid, expired or revoked
     * @param \stdClass $issue the issue record
     * @return string
     */
    protected function status_panel(string $status, \stdClass $issue): string {
        $classes = [
            'valid' => 'alert-success',
            'expired' => 'alert-warning',
            'revoked' => 'alert-danger',
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
        $output .= $this->designer_canvas($data);
        $output .= $this->designer_sidebar($data, $cm);
        $output .= \html_writer::end_div();

        $output .= \html_writer::div('', 'certificatepro-status', ['data-region' => 'status']);
        $output .= \html_writer::end_div();

        return $output;
    }

    /**
     * The designer toolbar.
     *
     * @param \cm_info $cm the course module
     * @return string
     */
    protected function designer_toolbar(\cm_info $cm): string {
        $groups = [
            [
                ['action' => 'undo', 'icon' => 'fa-rotate-left', 'label' => 'designer_undo'],
                ['action' => 'redo', 'icon' => 'fa-rotate-right', 'label' => 'designer_redo'],
            ],
            [
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
            ],
            [
                ['action' => 'distribute', 'value' => 'horizontal', 'icon' => 'fa-arrows-left-right',
                    'label' => 'designer_distributeh'],
                ['action' => 'distribute', 'value' => 'vertical', 'icon' => 'fa-arrows-up-down',
                    'label' => 'designer_distributev'],
            ],
            [
                ['action' => 'tofront', 'icon' => 'fa-bring-front', 'label' => 'designer_tofront'],
                ['action' => 'toback', 'icon' => 'fa-send-back', 'label' => 'designer_toback'],
            ],
            [
                ['action' => 'duplicate', 'icon' => 'fa-clone', 'label' => 'designer_duplicate'],
                ['action' => 'delete', 'icon' => 'fa-trash', 'label' => 'designer_delete'],
            ],
            [
                ['action' => 'togglezones', 'icon' => 'fa-table-cells-large',
                    'label' => 'designer_showzones'],
            ],
        ];

        $output = \html_writer::start_div('certificatepro-toolbar',
            ['role' => 'toolbar', 'data-region' => 'toolbar']);

        foreach ($groups as $group) {
            $output .= \html_writer::start_div('certificatepro-toolbar-group');
            foreach ($group as $button) {
                $attributes = [
                    'type' => 'button',
                    'class' => 'btn btn-sm btn-outline-secondary',
                    'data-action' => $button['action'],
                    'title' => get_string($button['label'], 'mod_certificatepro'),
                    'aria-label' => get_string($button['label'], 'mod_certificatepro'),
                ];
                if (isset($button['value'])) {
                    $attributes['data-value'] = $button['value'];
                }
                $output .= \html_writer::tag('button',
                    \html_writer::tag('i', '', ['class' => 'fa ' . $button['icon'],
                        'aria-hidden' => 'true']),
                    $attributes);
            }
            $output .= \html_writer::end_div();
        }

        $output .= \html_writer::start_div('certificatepro-toolbar-group ms-auto');
        foreach ([0.5, 0.75, 1, 1.5, 2] as $zoom) {
            $output .= \html_writer::tag('button', ((int) ($zoom * 100)) . '%', [
                'type' => 'button',
                'class' => 'btn btn-sm btn-outline-secondary',
                'data-action' => 'zoom',
                'data-value' => (string) $zoom,
            ]);
        }
        $output .= \html_writer::link(
            new \moodle_url('/mod/certificatepro/preview.php', ['id' => $cm->id]),
            get_string('designer_preview', 'mod_certificatepro'),
            ['class' => 'btn btn-sm btn-primary ms-2', 'target' => '_blank', 'rel' => 'noopener']
        );
        $output .= \html_writer::end_div();

        $output .= \html_writer::end_div();

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
            ['action' => 'styles', 'icon' => 'fa-font', 'label' => 'librarystyles'],
            ['action' => 'backgrounds', 'icon' => 'fa-image', 'label' => 'librarybackgrounds'],
            ['action' => 'ornaments', 'icon' => 'fa-crown', 'label' => 'libraryornaments'],
            ['action' => 'artworkbrief', 'icon' => 'fa-wand-magic-sparkles',
                'label' => 'libraryartworkbrief'],
            ['action' => 'sealbrief', 'icon' => 'fa-award', 'label' => 'librarysealbrief'],
        ];

        $output = \html_writer::start_div('certificatepro-librarybar', [
            'role' => 'group',
            'aria-label' => get_string('librarybar', 'mod_certificatepro'),
            'data-region' => 'librarybar',
        ]);

        foreach ($buttons as $button) {
            $label = get_string($button['label'], 'mod_certificatepro');
            $output .= \html_writer::tag('button',
                \html_writer::tag('i', '', ['class' => 'fa ' . $button['icon'],
                    'aria-hidden' => 'true'])
                    . \html_writer::tag('span', $label),
                [
                    'type' => 'button',
                    'class' => 'btn btn-sm btn-outline-primary',
                    'data-library-action' => $button['action'],
                    'title' => get_string($button['label'] . 'desc', 'mod_certificatepro'),
                ]);
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
        $output = \html_writer::start_div('certificatepro-picker', ['data-region' => 'picker']);
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
     * @param array $data the exported design
     * @return string
     */
    protected function designer_canvas(array $data): string {
        $output = \html_writer::start_div('certificatepro-canvas', ['data-region' => 'canvas']);

        if (count($data['pages']) > 1) {
            $output .= \html_writer::start_div('certificatepro-pagetabs');
            foreach ($data['pages'] as $index => $page) {
                $output .= \html_writer::tag('button',
                    get_string('pagenumber', 'mod_certificatepro', $index + 1), [
                        'type' => 'button',
                        'class' => 'btn btn-sm btn-outline-secondary',
                        'data-action' => 'page',
                        'data-value' => (string) $index,
                    ]);
            }
            $output .= \html_writer::end_div();
        }

        $output .= \html_writer::start_div('certificatepro-stage', ['data-region' => 'stage']);
        $output .= \html_writer::div('', 'certificatepro-margins', ['data-region' => 'margins']);
        $output .= \html_writer::div('', 'certificatepro-elements', ['data-region' => 'elements']);
        $output .= \html_writer::div('', 'certificatepro-overlay', ['data-region' => 'overlay']);
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

        $output .= \html_writer::start_div('certificatepro-inspector',
            ['data-region' => 'inspector', 'data-count' => '0']);
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
        $output .= \html_writer::div('', 'certificatepro-layers', ['data-region' => 'layers']);
        $output .= \html_writer::end_div();

        $output .= \html_writer::end_div();

        return $output;
    }
}
