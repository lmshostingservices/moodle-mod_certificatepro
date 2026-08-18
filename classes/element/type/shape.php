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
 * A rectangle, rounded panel, circle or ribbon used to build up a layout.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class shape extends base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'shape';
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
        return 'fa-shapes';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [60.0, 20.0];
    }

    /**
     * The shapes on offer.
     *
     * @return array<string, string>
     */
    public static function shapes(): array {
        return [
            'rectangle' => get_string('shape_rectangle', 'mod_certificatepro'),
            'rounded' => get_string('shape_rounded', 'mod_certificatepro'),
            'circle' => get_string('shape_circle', 'mod_certificatepro'),
            'ribbon' => get_string('shape_ribbon', 'mod_certificatepro'),
            'chevron' => get_string('shape_chevron', 'mod_certificatepro'),
        ];
    }

    /**
     * Add the shape settings.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement('select', 'shape',
            get_string('shape', 'mod_certificatepro'), self::shapes());
        $mform->setDefault('shape', 'rounded');

        $mform->addElement('text', 'fillcolour',
            get_string('fillcolour', 'mod_certificatepro'), ['size' => 12]);
        $mform->setType('fillcolour', PARAM_TEXT);
        $mform->setDefault('fillcolour', 'primarywash');

        $mform->addElement('text', 'strokecolour',
            get_string('strokecolour', 'mod_certificatepro'), ['size' => 12]);
        $mform->setType('strokecolour', PARAM_TEXT);
        $mform->setDefault('strokecolour', '');

        $mform->addElement('text', 'thickness',
            get_string('linethickness', 'mod_certificatepro'), ['size' => 6]);
        $mform->setType('thickness', PARAM_FLOAT);
        $mform->setDefault('thickness', 0.4);

        $mform->addElement('text', 'radius',
            get_string('cornerradius', 'mod_certificatepro'), ['size' => 6]);
        $mform->setType('radius', PARAM_FLOAT);
        $mform->setDefault('radius', 3);
        $mform->hideIf('radius', 'shape', 'neq', 'rounded');
    }

    /**
     * Persist the shape settings.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return [
            'shape' => array_key_exists($data->shape ?? '', self::shapes()) ? $data->shape : 'rounded',
            'fillcolour' => (string) ($data->fillcolour ?? ''),
            'strokecolour' => (string) ($data->strokecolour ?? ''),
            'thickness' => max(0.0, (float) ($data->thickness ?? 0.4)),
            'radius' => max(0.0, (float) ($data->radius ?? 3)),
        ];
    }

    /**
     * A shape has no value of its own.
     *
     * @param render_context $ctx the render context
     * @return null
     */
    public function resolve(render_context $ctx) {
        return null;
    }

    /**
     * Resolve a stored colour reference to red, green and blue components.
     *
     * @param render_context $ctx the render context
     * @param string $key a palette role or a hex colour
     * @return int[]|null null when the colour is empty
     */
    protected function colour(render_context $ctx, string $key): ?array {
        $key = trim($key);
        if ($key === '') {
            return null;
        }
        $palette = $ctx->get_palette();
        $hex = $palette[$key] ?? (brand::is_hex($key) ? brand::normalise_hex($key) : null);
        if ($hex === null) {
            return null;
        }
        return brand::to_rgb($hex);
    }

    /**
     * Draw the shape.
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

        $fill = $this->colour($ctx, (string) $this->config('fillcolour', ''));
        $stroke = $this->colour($ctx, (string) $this->config('strokecolour', ''));
        $thickness = (float) $this->config('thickness', 0.4);

        $mode = '';
        if ($fill !== null) {
            $mode .= 'F';
        }
        if ($stroke !== null && $thickness > 0) {
            $mode .= 'D';
        }
        if ($mode === '') {
            return;
        }

        $border = $stroke !== null
            ? ['all' => ['width' => $thickness, 'color' => $stroke]]
            : [];
        $fillcolour = $fill ?? [];

        switch ($this->config('shape', 'rounded')) {
            case 'circle':
                $radius = min($width, $height) / 2;
                $pdf->Circle($left + ($width / 2), $top + ($height / 2), $radius, 0, 360,
                    $mode, $border['all'] ?? [], $fillcolour);
                break;

            case 'rounded':
                $pdf->RoundedRect($left, $top, $width, $height,
                    (float) $this->config('radius', 3), '1111', $mode, $border['all'] ?? [],
                    $fillcolour);
                break;

            case 'ribbon':
                $notch = min($height * 0.5, $width * 0.12);
                $points = [
                    $left, $top,
                    $left + $width, $top,
                    $left + $width - $notch, $top + ($height / 2),
                    $left + $width, $top + $height,
                    $left, $top + $height,
                    $left + $notch, $top + ($height / 2),
                ];
                $pdf->Polygon($points, $mode, $border, $fillcolour);
                break;

            case 'chevron':
                $notch = min($height * 0.5, $width * 0.18);
                $points = [
                    $left, $top,
                    $left + $width - $notch, $top,
                    $left + $width, $top + ($height / 2),
                    $left + $width - $notch, $top + $height,
                    $left, $top + $height,
                ];
                $pdf->Polygon($points, $mode, $border, $fillcolour);
                break;

            default:
                $pdf->Rect($left, $top, $width, $height, $mode, $border, $fillcolour);
                break;
        }
    }

    /**
     * Produce the designer canvas markup.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function render_html(render_context $ctx): string {
        $palette = $ctx->get_palette();
        $resolve = static function (string $key) use ($palette): string {
            $key = trim($key);
            if ($key === '') {
                return 'transparent';
            }
            return $palette[$key] ?? (brand::is_hex($key) ? brand::normalise_hex($key) : 'transparent');
        };

        $fill = $resolve((string) $this->config('fillcolour', ''));
        $stroke = $resolve((string) $this->config('strokecolour', ''));
        $thickness = max(0.0, (float) $this->config('thickness', 0.4)) * 3;

        $styles = [
            'width:100%',
            'height:100%',
            'background:' . $fill,
        ];
        if ($stroke !== 'transparent' && $thickness > 0) {
            $styles[] = 'border:' . $thickness . 'px solid ' . $stroke;
        }

        switch ($this->config('shape', 'rounded')) {
            case 'circle':
                $styles[] = 'border-radius:50%';
                break;
            case 'rounded':
                $styles[] = 'border-radius:' . ((float) $this->config('radius', 3) * 3) . 'px';
                break;
            case 'ribbon':
                $styles[] = 'clip-path:polygon(0 0,100% 0,92% 50%,100% 100%,0 100%,8% 50%)';
                break;
            case 'chevron':
                $styles[] = 'clip-path:polygon(0 0,86% 0,100% 50%,86% 100%,0 100%)';
                break;
        }

        return \html_writer::div('', 'certificatepro-element-shape', [
            'style' => implode(';', $styles),
        ]);
    }}
