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
use mod_certificatepro\local\font_manager;
use mod_certificatepro\local\typography;

/**
 * A complete signature block: the signature image, a rule, a name and a role.

Certificates almost always need these four things together and in proportion,
so shipping them as one element removes four separate positioning problems.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class signature extends image_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'signature';
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
        return 'fa-signature';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [66.0, 28.0];
    }

    /**
     * The file area this element stores its signature image in.
     *
     * @return string[]
     */
    public static function file_areas(): array {
        return ['signatureimage'];
    }

    /**
     * The file area used by this element instance.
     *
     * @return string
     */
    protected function filearea(): string {
        return 'signatureimage';
    }

    /**
     * Which kind of shared picture this element can be pointed at.
     *
     * @return string|null
     */
    protected function media_kind(): ?string {
        return \mod_certificatepro\local\media_library::KIND_SIGNATURE;
    }

    /**
     * Add the signature block settings.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement(
            'filemanager',
            'imagefile',
            get_string('signatureimage', 'mod_certificatepro'),
            null,
            [
                'subdirs' => 0,
                'maxfiles' => 1,
                'accepted_types' => ['web_image', '.svg'],
            ]
        );
        $mform->addHelpButton('imagefile', 'signatureimage', 'mod_certificatepro');

        $mform->addElement(
            'select',
            'mediaid',
            get_string('mediafromlibrary', 'mod_certificatepro'),
            \mod_certificatepro\local\media_library::menu(
                \mod_certificatepro\local\media_library::KIND_SIGNATURE
            )
        );
        $mform->addHelpButton('mediaid', 'mediafromlibrary', 'mod_certificatepro');

        $mform->addElement(
            'select',
            'namesource',
            get_string('signaturenamesource', 'mod_certificatepro'),
            [
                'custom' => get_string('signaturenamesource_custom', 'mod_certificatepro'),
                'teacher' => get_string('signaturenamesource_teacher', 'mod_certificatepro'),
                'none' => get_string('signaturenamesource_none', 'mod_certificatepro'),
            ]
        );
        $mform->setDefault('namesource', 'custom');

        $mform->addElement(
            'text',
            'signaturename',
            get_string('signaturename', 'mod_certificatepro'),
            ['size' => 40]
        );
        $mform->setType('signaturename', PARAM_TEXT);
        $mform->hideIf('signaturename', 'namesource', 'neq', 'custom');

        $mform->addElement(
            'text',
            'signaturerole',
            get_string('signaturerole', 'mod_certificatepro'),
            ['size' => 40]
        );
        $mform->setType('signaturerole', PARAM_TEXT);

        $mform->addElement(
            'advcheckbox',
            'showrule',
            get_string('signatureshowrule', 'mod_certificatepro')
        );
        $mform->setDefault('showrule', 1);

        $mform->addElement(
            'text',
            'alttext',
            get_string('elementalttext', 'mod_certificatepro'),
            ['size' => 60]
        );
        $mform->setType('alttext', PARAM_TEXT);
    }

    /**
     * Persist the signature block settings.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'mediaid' => (int) ($data->mediaid ?? 0),
            'namesource' => $data->namesource ?? 'custom',
            'signaturename' => (string) ($data->signaturename ?? ''),
            'signaturerole' => (string) ($data->signaturerole ?? ''),
            'showrule' => !empty($data->showrule) ? 1 : 0,
            'alttext' => (string) ($data->alttext ?? ''),
            'keepaspect' => 1,
            'opacity' => 1.0,
        ];
    }

    /**
     * The name printed under the rule.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    protected function signature_name(render_context $ctx): string {
        switch ($this->config('namesource', 'custom')) {
            case 'teacher':
                $teachers = \mod_certificatepro\local\course_helper::teachers($ctx->get_context());
                $first = reset($teachers);
                return $first ? fullname($first) : '';
            case 'none':
                return '';
            default:
                return $ctx->interpolate((string) $this->config('signaturename', ''));
        }
    }

    /**
     * The whole block resolves to its printed name, which is what a snapshot needs.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function resolve(render_context $ctx) {
        return $this->signature_name($ctx);
    }

    /**
     * Draw the image, the rule, the name and the role as one stacked block.
     *
     * @param \pdf $pdf the PDF being built
     * @param render_context $ctx the render context
     * @return void
     */
    public function render_pdf(\pdf $pdf, render_context $ctx): void {
        if ($this->is_hidden()) {
            return;
        }

        $width = max(10.0, $this->get_width());
        $height = max(10.0, $this->get_height());
        [$left, $top] = $this->box_origin($width, $height);

        $name = (string) $this->resolve_cached($ctx);
        $role = $ctx->interpolate((string) $this->config('signaturerole', ''));

        $palette = $ctx->get_palette();
        [$inkr, $inkg, $inkb] = brand::to_rgb($palette['ink'] ?? '#111111');
        [$mutedr, $mutedg, $mutedb] = brand::to_rgb($palette['muted'] ?? '#6b7280');
        [$ruler, $ruleg, $ruleb] = brand::to_rgb($palette['ink'] ?? '#111111');

        $installed = font_manager::installed_families();
        $pairing = typography::resolve($ctx->get_pairing(), $installed);
        $namefamily = $pairing['body']['resolved'];
        $accentfamily = $pairing['accent']['resolved'];

        $namesize = typography::size($ctx->get_pairing(), 'signaturename');
        $rolesize = typography::size($ctx->get_pairing(), 'signature');

        // Reserve space at the foot of the block for the words.
        $textheight = 0.0;
        if ($name !== '') {
            $textheight += ($namesize * 25.4 / 72) * 1.5;
        }
        if ($role !== '') {
            $textheight += ($rolesize * 25.4 / 72) * 1.5;
        }
        $ruleheight = $this->config('showrule', 1) ? 2.5 : 0.0;
        $imageheight = max(4.0, $height - $textheight - $ruleheight);

        $path = $this->image_path($ctx);
        if ($path !== null && is_readable($path)) {
            [$pixelwidth, $pixelheight] = $this->intrinsic_size($ctx);
            $drawnwidth = $width * 0.8;
            $drawnheight = $imageheight;
            if ($pixelwidth > 0 && $pixelheight > 0) {
                $ratio = $pixelwidth / $pixelheight;
                $drawnwidth = min($width * 0.9, $imageheight * $ratio);
                $drawnheight = $drawnwidth / $ratio;
            }
            $imageleft = $left + (($width - $drawnwidth) / 2);
            $imagetop = $top + max(0.0, $imageheight - $drawnheight);
            try {
                if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg') {
                    $pdf->ImageSVG(
                        $path,
                        $imageleft,
                        $imagetop,
                        $drawnwidth,
                        $drawnheight,
                        '',
                        '',
                        '',
                        0,
                        false
                    );
                } else {
                    $pdf->Image(
                        $path,
                        $imageleft,
                        $imagetop,
                        $drawnwidth,
                        $drawnheight,
                        '',
                        '',
                        '',
                        true,
                        300,
                        '',
                        false,
                        false,
                        0,
                        false,
                        false,
                        false
                    );
                }
            } catch (\Throwable $e) {
                debugging(
                    'Certificate Pro could not draw a signature image: ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        $cursor = $top + $imageheight;

        if ($this->config('showrule', 1)) {
            $pdf->Line(
                $left + ($width * 0.08),
                $cursor,
                $left + ($width * 0.92),
                $cursor,
                ['width' => 0.3, 'color' => [$ruler, $ruleg, $ruleb]]
            );
            $cursor += 1.6;
        }

        if ($name !== '') {
            $pdf->SetTextColor($inkr, $inkg, $inkb);
            $pdf->SetFont($namefamily, 'B', $namesize);
            $pdf->writeHTMLCell($width, 0, $left, $cursor, s($name), 0, 1, false, true, 'C', true);
            $cursor += ($namesize * 25.4 / 72) * 1.4;
        }

        if ($role !== '') {
            $pdf->SetTextColor($mutedr, $mutedg, $mutedb);
            $pdf->SetFont($accentfamily, '', $rolesize);
            $pdf->writeHTMLCell($width, 0, $left, $cursor, s($role), 0, 1, false, true, 'C', true);
        }

        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Produce the designer canvas markup.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function render_html(render_context $ctx): string {
        $palette = $ctx->get_palette();
        $url = $this->image_url($ctx);
        $name = s($this->signature_name($ctx));
        $role = s($ctx->interpolate((string) $this->config('signaturerole', '')));

        $parts = '';
        $parts .= \html_writer::div(
            $url !== null
                ? \html_writer::empty_tag(
                    'img',
                    [
                        'src' => $url,
                        'alt' => (string) $this->config('alttext', ''),
                        'style' => 'max-width:90%;max-height:100%;object-fit:contain;',
                    ]
                )
                : '',
            'certificatepro-signature-image'
        );

        if ($this->config('showrule', 1)) {
            $parts .= \html_writer::div(
                '',
                'certificatepro-signature-rule',
                [
                    'style' => 'border-top:1px solid ' . ($palette['ink'] ?? '#111') . ';margin:0 8%;',
                ]
            );
        }

        if ($name !== '') {
            $parts .= \html_writer::div(
                $name,
                'certificatepro-signature-name',
                [
                    'style' => 'font-weight:700;text-align:center;color:' . ($palette['ink'] ?? '#111'),
                ]
            );
        }
        if ($role !== '') {
            $parts .= \html_writer::div(
                $role,
                'certificatepro-signature-role',
                [
                    'style' => 'text-align:center;font-size:0.85em;color:' . ($palette['muted'] ?? '#666'),
                ]
            );
        }

        return \html_writer::div(
            $parts,
            'certificatepro-element-signature',
            [
                'style' => 'display:flex;flex-direction:column;justify-content:flex-end;height:100%;',
            ]
        );
    }}
