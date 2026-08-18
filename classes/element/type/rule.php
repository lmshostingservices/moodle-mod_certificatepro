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

/**
 * A horizontal or vertical divider line.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule extends base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'rule';
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
        return 'fa-minus';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [90.0, 1.0];
    }

    /**
     * The default appearance for a newly created rule.
     *
     * @return array
     */
    public static function default_style(): array {
        return array_merge(parent::default_style(), [
            'colour' => 'rule',
        ]);
    }

    /**
     * Add the thickness, style and end cap choices.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement('select', 'linestyle',
            get_string('linestyle', 'mod_certificatepro'), [
                'solid' => get_string('linestyle_solid', 'mod_certificatepro'),
                'dashed' => get_string('linestyle_dashed', 'mod_certificatepro'),
                'dotted' => get_string('linestyle_dotted', 'mod_certificatepro'),
                'double' => get_string('linestyle_double', 'mod_certificatepro'),
                'tapered' => get_string('linestyle_tapered', 'mod_certificatepro'),
            ]);
        $mform->setDefault('linestyle', 'solid');

        $mform->addElement('text', 'thickness',
            get_string('linethickness', 'mod_certificatepro'), ['size' => 6]);
        $mform->setType('thickness', PARAM_FLOAT);
        $mform->setDefault('thickness', 0.4);

        $mform->addElement('text', 'colour',
            get_string('elementcolour', 'mod_certificatepro'), ['size' => 10]);
        $mform->setType('colour', PARAM_TEXT);
        $mform->setDefault('colour', 'rule');
    }

    /**
     * Persist the rule settings.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'linestyle' => $data->linestyle ?? 'solid',
            'thickness' => max(0.05, (float) ($data->thickness ?? 0.4)),
            'colour' => (string) ($data->colour ?? 'rule'),
        ];
    }

    /**
     * A rule has no value of its own.
     *
     * @param render_context $ctx the render context
     * @return null
     */
    public function resolve(render_context $ctx) {
        return null;
    }

    /**
     * Draw the rule.
     *
     * @param \pdf $pdf the PDF being built
     * @param render_context $ctx the render context
     * @return void
     */
    public function render_pdf(\pdf $pdf, render_context $ctx): void {
        if ($this->is_hidden()) {
            return;
        }

        $width = max(0.1, $this->get_width());
        $height = max(0.1, $this->get_height());
        [$left, $top] = $this->box_origin($width, $height);

        $palette = $ctx->get_palette();
        $key = (string) $this->config('colour', 'rule');
        $hex = $palette[$key] ?? (brand::is_hex($key) ? brand::normalise_hex($key) : '#cccccc');
        [$r, $g, $b] = brand::to_rgb($hex);

        $thickness = (float) $this->config('thickness', 0.4);
        $style = ['width' => $thickness, 'color' => [$r, $g, $b]];

        switch ($this->config('linestyle', 'solid')) {
            case 'dashed':
                $style['dash'] = '3,2';
                break;
            case 'dotted':
                $style['dash'] = '0.6,1.4';
                $style['cap'] = 'round';
                break;
        }

        $centre = $top + ($height / 2);

        if ($this->config('linestyle', 'solid') === 'tapered') {
            // A tapered rule reads as a hand drawn flourish rather than a border.
            // Drawn as one filled shape: stacking short lines of varying weight
            // leaves visible steps and gaps at print resolution.
            $steps = 96;
            $top = [];
            $bottom = [];

            for ($i = 0; $i <= $steps; $i++) {
                $t = $i / $steps;
                $weight = ($thickness / 2) * (1 - ((abs($t - 0.5) * 2) ** 1.7));
                $weight = max(0.0, $weight);
                $x = $left + ($width * $t);
                $top[] = $x;
                $top[] = $centre - $weight;
                array_unshift($bottom, $centre + $weight);
                array_unshift($bottom, $x);
            }

            $pdf->Polygon(array_merge($top, $bottom), 'F', [], [$r, $g, $b]);

            return;
        }

        if ($this->config('linestyle', 'solid') === 'double') {
            $gap = max(0.6, $thickness * 2.5);
            $pdf->Line($left, $centre - ($gap / 2), $left + $width, $centre - ($gap / 2), $style);
            $pdf->Line($left, $centre + ($gap / 2), $left + $width, $centre + ($gap / 2), $style);
            return;
        }

        $pdf->Line($left, $centre, $left + $width, $centre, $style);
    }

    /**
     * Produce the designer canvas markup.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function render_html(render_context $ctx): string {
        $palette = $ctx->get_palette();
        $key = (string) $this->config('colour', 'rule');
        $hex = $palette[$key] ?? (brand::is_hex($key) ? brand::normalise_hex($key) : '#cccccc');

        $linestyle = (string) $this->config('linestyle', 'solid');
        $css = match ($linestyle) {
            'dashed' => 'dashed',
            'dotted' => 'dotted',
            'double' => 'double',
            default => 'solid',
        };
        $thickness = max(1.0, (float) $this->config('thickness', 0.4) * 3);
        if ($linestyle === 'double') {
            $thickness = max(3.0, $thickness);
        }

        return \html_writer::div('', 'certificatepro-element-rule', [
            'style' => 'width:100%;height:100%;display:flex;align-items:center;'
                . 'border-top:' . $thickness . 'px ' . $css . ' ' . $hex . ';',
        ]);
    }}
