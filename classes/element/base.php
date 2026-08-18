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

/**
 * The contract every certificate element implements.
 *
 * An element owns three things: the form that configures it, the value it
 * resolves to for a given recipient, and how that value is drawn. Everything
 * shared between elements lives here: position, size, anchoring, typography
 * and colour, so an individual element type only has to describe what makes
 * it different.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base {
    /** @var string Elements that produce words. */
    public const CATEGORY_TEXT = 'text';

    /** @var string Elements that pull a value from the course, user or grade. */
    public const CATEGORY_DATA = 'data';

    /** @var string Elements that draw pictures. */
    public const CATEGORY_IMAGE = 'image';

    /** @var string Elements that prove the certificate is real. */
    public const CATEGORY_VERIFY = 'verify';

    /** @var string Elements that decorate the page. */
    public const CATEGORY_DECOR = 'decor';

    /** @var \stdClass The stored element record. */
    protected \stdClass $record;

    /** @var array The decoded element specific configuration. */
    protected array $data;

    /** @var array The decoded typography and colour. */
    protected array $style;

    /**
     * Wrap a stored element record.
     *
     * @param \stdClass $record a row from the elements table
     */
    public function __construct(\stdClass $record) {
        $this->record = $record;
        $decoded = json_decode($record->data ?? '', true);
        $this->data = is_array($decoded) ? $decoded : [];
        $decodedstyle = json_decode($record->style ?? '', true);
        $this->style = is_array($decodedstyle) ? $decodedstyle : [];
    }

    // Identity.

    /**
     * The element type identifier, matching the class file name.
     *
     * @return string
     */
    abstract public static function type(): string;

    /**
     * Which drawer of the element picker this type appears in.
     *
     * @return string one of the CATEGORY_ constants
     */
    abstract public static function category(): string;

    /**
     * The Font Awesome icon key used in the element picker.
     *
     * @return string
     */
    public static function icon(): string {
        return 'fa-square';
    }

    /**
     * The translated display name for this element type.
     *
     * @return string
     */
    public static function display_name(): string {
        return get_string('element_' . static::type(), 'mod_certificatepro');
    }

    /**
     * The translated one line explanation shown in the element picker.
     *
     * @return string
     */
    public static function description(): string {
        return get_string('element_' . static::type() . '_desc', 'mod_certificatepro');
    }

    /**
     * Whether more than one of this element may exist on a page.
     *
     * @return bool
     */
    public static function allows_multiple(): bool {
        return true;
    }

    /**
     * The default width and height in millimetres when first dropped.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [80.0, 12.0];
    }

    /**
     * The default typography and colour for a newly created element.
     *
     * @return array
     */
    public static function default_style(): array {
        return [
            'font' => 'body',
            'size' => 12.0,
            'bold' => false,
            'italic' => false,
            'underline' => false,
            'colour' => 'ink',
            'tracking' => 0.0,
            'lineheight' => 1.25,
            'case' => 'none',
            'opacity' => 1.0,
        ];
    }

    // Stored properties.

    /**
     * The element record id.
     *
     * @return int
     */
    public function get_id(): int {
        return (int) $this->record->id;
    }

    /**
     * The author facing label.
     *
     * @return string
     */
    public function get_name(): string {
        return (string) $this->record->name;
    }

    /**
     * The underlying record.
     *
     * @return \stdClass
     */
    public function get_record(): \stdClass {
        return $this->record;
    }

    /**
     * One value from the element's own configuration.
     *
     * @param string $key the configuration key
     * @param mixed $default what to return when the key is absent
     * @return mixed
     */
    public function config(string $key, $default = null) {
        return $this->data[$key] ?? $default;
    }

    /**
     * The whole configuration array.
     *
     * @return array
     */
    public function get_data(): array {
        return $this->data;
    }

    /**
     * One value from the element's typography and colour.
     *
     * @param string $key the style key
     * @param mixed $default what to return when the key is absent
     * @return mixed
     */
    public function style(string $key, $default = null) {
        if (array_key_exists($key, $this->style)) {
            return $this->style[$key];
        }
        $defaults = static::default_style();
        if (array_key_exists($key, $defaults)) {
            return $defaults[$key];
        }
        return $default;
    }

    /**
     * The whole style array, with defaults filled in.
     *
     * @return array
     */
    public function get_style(): array {
        return array_merge(static::default_style(), $this->style);
    }

    // Geometry.

    /**
     * The horizontal position of the element's anchor point, in millimetres.
     *
     * @return float
     */
    public function get_posx(): float {
        return (float) $this->record->posx;
    }

    /**
     * The vertical position of the element's anchor point, in millimetres.
     *
     * @return float
     */
    public function get_posy(): float {
        return (float) $this->record->posy;
    }

    /**
     * The element width in millimetres, where zero means fit to content.
     *
     * @return float
     */
    public function get_width(): float {
        return (float) $this->record->width;
    }

    /**
     * The element height in millimetres, where zero means fit to content.
     *
     * @return float
     */
    public function get_height(): float {
        return (float) $this->record->height;
    }

    /**
     * The rotation in degrees.
     *
     * @return float
     */
    public function get_rotation(): float {
        return (float) $this->record->rotation;
    }

    /**
     * The anchor point, one of tl tc tr ml mc mr bl bc br.
     *
     * @return string
     */
    public function get_refpoint(): string {
        return (string) $this->record->refpoint;
    }

    /**
     * The horizontal text alignment, one of L C R J.
     *
     * @return string
     */
    public function get_alignment(): string {
        return (string) $this->record->alignment;
    }

    /**
     * The vertical alignment, one of T M B.
     *
     * @return string
     */
    public function get_valignment(): string {
        return (string) $this->record->valignment;
    }

    /**
     * Whether the element is hidden from output.
     *
     * @return bool
     */
    public function is_hidden(): bool {
        return !empty($this->record->hidden);
    }

    /**
     * Convert the anchored position into the top left corner of the element box.
     *
     * @param float $width the resolved width in millimetres
     * @param float $height the resolved height in millimetres
     * @return array{0: float, 1: float} left then top, in millimetres
     */
    public function box_origin(float $width, float $height): array {
        $refpoint = $this->get_refpoint();
        $vertical = substr($refpoint, 0, 1);
        $horizontal = substr($refpoint, 1, 1);

        $left = match ($horizontal) {
            'c' => $this->get_posx() - ($width / 2),
            'r' => $this->get_posx() - $width,
            default => $this->get_posx(),
        };

        $top = match ($vertical) {
            'm' => $this->get_posy() - ($height / 2),
            'b' => $this->get_posy() - $height,
            default => $this->get_posy(),
        };

        return [round($left, 3), round($top, 3)];
    }

    // Configuration form.

    /**
     * Add this element's own settings to the edit form.
     *
     * Shared settings such as position, size and typography are added by the
     * form itself, so an implementation only describes what is unique to it.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        // Many elements need no settings of their own.
    }

    /**
     * Validate this element's own settings.
     *
     * @param array $data the submitted data
     * @param array $files the submitted files
     * @return array errors keyed by form element name, empty when valid
     */
    public function validate_form(array $data, array $files): array {
        return [];
    }

    /**
     * Turn submitted form data into the configuration array to persist.
     *
     * @param \stdClass $data the submitted data
     * @return array the configuration to store as JSON
     */
    public function save_form_data(\stdClass $data): array {
        return [];
    }

    /**
     * Populate the edit form with this element's stored configuration.
     *
     * @param \MoodleQuickForm $mform the form to populate
     * @return void
     */
    public function prepare_form(\MoodleQuickForm $mform): void {
        foreach ($this->data as $key => $value) {
            if ($mform->elementExists($key)) {
                $mform->setDefault($key, $value);
            }
        }
    }

    // Resolution and rendering.

    /**
     * Resolve this element to the value that will be drawn.
     *
     * The return type varies by element: text elements return a string, image
     * elements return a path, and decorative elements may return null.
     *
     * @param render_context $ctx the render context
     * @return mixed
     */
    abstract public function resolve(render_context $ctx);

    /**
     * Resolve, reading from the snapshot first so issued certificates never change.
     *
     * @param render_context $ctx the render context
     * @return mixed
     */
    public function resolve_cached(render_context $ctx) {
        if ($ctx->is_snapshot()) {
            $stored = $ctx->snapshot_value($this->get_id());
            if ($stored !== null) {
                return $stored;
            }
        }
        $value = $this->resolve($ctx);
        if (is_scalar($value) || $value === null) {
            $ctx->record_value($this->get_id(), $value);
        }
        return $value;
    }

    /**
     * Draw this element onto a PDF page.
     *
     * @param \pdf $pdf the PDF being built
     * @param render_context $ctx the render context
     * @return void
     */
    abstract public function render_pdf(\pdf $pdf, render_context $ctx): void;

    /**
     * Produce the HTML the designer canvas draws for this element.
     *
     * @param render_context $ctx the render context
     * @return string safe HTML
     */
    abstract public function render_html(render_context $ctx): string;

    /**
     * A short plain text summary shown in the layers panel.
     *
     * @param render_context $ctx the render context
     * @return string
     */
    public function summary(render_context $ctx): string {
        $value = $this->resolve($ctx);
        if (is_string($value)) {
            return shorten_text(strip_tags($value), 48);
        }
        return static::display_name();
    }

    /**
     * Files this element owns, so backup and restore can carry them.
     *
     * @return string[] file area names within the certificatepro component
     */
    public static function file_areas(): array {
        return [];
    }

    /**
     * Everything the designer needs to know about this element, as plain data.
     *
     * @param render_context $ctx the render context
     * @return array
     */
    public function export_for_designer(render_context $ctx): array {
        return [
            'id' => $this->get_id(),
            'type' => static::type(),
            'category' => static::category(),
            'icon' => static::icon(),
            'name' => $this->get_name(),
            'typename' => static::display_name(),
            'posx' => $this->get_posx(),
            'posy' => $this->get_posy(),
            'width' => $this->get_width(),
            'height' => $this->get_height(),
            'rotation' => $this->get_rotation(),
            'refpoint' => $this->get_refpoint(),
            'alignment' => $this->get_alignment(),
            'valignment' => $this->get_valignment(),
            'zoneid' => $this->record->zoneid ?? null,
            'zindex' => (int) $this->record->zindex,
            'locked' => !empty($this->record->locked),
            'hidden' => $this->is_hidden(),
            'style' => $this->get_style(),
            'data' => $this->get_data(),
            'html' => $this->render_html($ctx),
        ];
    }
}
