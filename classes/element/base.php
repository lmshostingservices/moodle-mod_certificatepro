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
     * The drawing size, in millimetres, that the last render_html() baked in.
     *
     * Recorded by {@see canvas_box_style()} rather than declared by each type,
     * because that method is the one place a drawn size is turned into a fixed
     * CSS length. Reading it back after the markup exists is therefore a record
     * of what actually happened, not a guess about what a type probably does.
     *
     * @var array{0: float, 1: float}|null width then height, or null when the
     *      markup carries no baked in drawing size
     */
    protected ?array $canvasdrawn = null;

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
     * The element's own configuration as a newly dropped element should hold it.
     *
     * This has to mirror the setDefault() calls in definition_form(). An element
     * created with an empty configuration draws nothing at all until the author
     * has opened and saved its settings form once, which reads as a bug rather
     * than as an empty element.
     *
     * @return array the configuration to store as JSON on creation
     */
    public static function default_data(): array {
        return [];
    }

    /**
     * Whether this element is always drawn square, whatever shape its box is.
     *
     * A seal, a QR code and a portrait are all drawn at min(width, height) by
     * the PDF, so the designer has to constrain their boxes the same way rather
     * than letting the author stretch something that cannot stretch.
     *
     * @return bool
     */
    public static function keepsquare(): bool {
        return false;
    }

    /**
     * Whether the author may resize this element's box.
     *
     * @return bool
     */
    public static function resizable(): bool {
        return true;
    }

    /**
     * The width and height this type will actually accept for its box.
     *
     * {@see self::keepsquare()} and {@see self::resizable()} say what a type
     * permits, but saying it is not the same as enforcing it, and there is more
     * than one way into the columns: the designer posts a whole page of geometry
     * at once, and the settings form offers width and height as two boxes an
     * author can type any number into. Both used to write what they were given,
     * so the same seal that could not be dragged into an oval could still be
     * saved as one, and a page frame the canvas refuses to resize could be given
     * any size at all through the form.
     *
     * The rule belongs with the flags it reads rather than with either caller,
     * so both ask the type itself and get the same answer. What comes back is
     * only ever a size: refusing a move as well would be a different and much
     * more annoying rule, and an author may always reposition something they may
     * not reshape.
     *
     * @param float $width the width being asked for, in millimetres
     * @param float $height the height being asked for, in millimetres
     * @param float $storedwidth the width the element already has
     * @param float $storedheight the height the element already has
     * @return array{0: float, 1: float} the width and height to store
     */
    public static function constrain_size(float $width, float $height, float $storedwidth,
            float $storedheight): array {
        if (!static::resizable()) {
            return [$storedwidth, $storedheight];
        }

        $width = max(0.0, $width);
        $height = max(0.0, $height);

        if (static::keepsquare()) {
            // The larger side, so dragging a corner outwards makes the seal
            // bigger rather than snapping it down to whichever edge moved least.
            $side = max($width, $height);
            return [$side, $side];
        }

        return [$width, $height];
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
     * A key the stored configuration has never carried falls back to the type's
     * own default, the way {@see style()} falls back to default_style(). That
     * matters for elements stored before those defaults existed: a shape whose
     * record has no fill colour at all is meant to be the pale wash its form
     * offers, not invisible.
     *
     * @param string $key the configuration key
     * @param mixed $default what to return when the key is absent
     * @return mixed
     */
    public function config(string $key, $default = null) {
        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }
        $defaults = static::default_data();
        if (array_key_exists($key, $defaults)) {
            return $defaults[$key];
        }
        return $default;
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

    // Canvas geometry.

    /**
     * A CSS length in millimetres of page, expressed so that it follows the zoom.
     *
     * The designer sizes the stage in pixels at whatever zoom is current, so any
     * absolute unit written into an element's markup - a point size, a border
     * weight - stops agreeing with the page as soon as the author zooms. The
     * stage is a container ({@see styles.css}), so one container query width
     * unit is one hundredth of the page whatever the zoom is, and a length
     * stated as a percentage of the page width scales with the paper.
     *
     * @param render_context $ctx the render context
     * @param float $mm the length in millimetres
     * @return string a CSS length
     */
    protected function canvas_mm(render_context $ctx, float $mm): string {
        $pagewidth = $ctx->get_page_width();
        if ($pagewidth <= 0) {
            $pagewidth = 297.0;
        }
        return round(($mm / $pagewidth) * 100.0, 4) . 'cqw';
    }

    /**
     * A CSS length in typographic points, expressed so that it follows the zoom.
     *
     * @param render_context $ctx the render context
     * @param float $points the length in points
     * @return string a CSS length
     */
    protected function canvas_points(render_context $ctx, float $points): string {
        return $this->canvas_mm($ctx, $points * 25.4 / 72.0);
    }

    /**
     * CSS placing a drawing of a given size inside the element box on the canvas.
     *
     * The PDF anchors what it draws with {@see box_origin()}, which means a
     * drawing smaller than its box sits against whichever corner the reference
     * point names. The canvas box is drawn by the designer at the stored width
     * and height, so the drawing inside it has to be anchored the same way or a
     * seal in a wide box appears in the middle on screen and on the left in the
     * PDF. Automatic margins absorb the free space on the side the anchor is
     * not on, in both axes.
     *
     * @param render_context $ctx the render context
     * @param float $width the drawn width in millimetres
     * @param float $height the drawn height in millimetres
     * @return string[] CSS declarations
     */
    protected function canvas_box_style(render_context $ctx, float $width, float $height): array {
        $refpoint = $this->get_refpoint();
        $vertical = substr($refpoint, 0, 1);
        $horizontal = substr($refpoint, 1, 1);

        // Remember what was baked in, so canvas_fit() can tell the designer how
        // to rescale it the instant the author drags a handle. See the property.
        $this->canvasdrawn = [$width, $height];

        $styles = [
            'width:' . $this->canvas_mm($ctx, $width),
            'height:' . $this->canvas_mm($ctx, $height),
            // The content box is a flex container, and a flex item that is not
            // told to hold its size is stretched or squashed to fit.
            'flex:0 0 auto',
            'margin-left:' . ($horizontal === 'c' || $horizontal === 'r' ? 'auto' : '0'),
            'margin-right:' . ($horizontal === 'c' || $horizontal === 'l' ? 'auto' : '0'),
            'margin-top:' . ($vertical === 'm' || $vertical === 'b' ? 'auto' : '0'),
            'margin-bottom:' . ($vertical === 'm' || $vertical === 't' ? 'auto' : '0'),
        ];

        return $styles;
    }

    /**
     * The CSS object-position that matches this element's reference point.
     *
     * @return string a CSS object-position value
     */
    protected function canvas_object_position(): string {
        $refpoint = $this->get_refpoint();

        $horizontal = match (substr($refpoint, 1, 1)) {
            'c' => 'center',
            'r' => 'right',
            default => 'left',
        };
        $vertical = match (substr($refpoint, 0, 1)) {
            'm' => 'center',
            'b' => 'bottom',
            default => 'top',
        };

        return $horizontal . ' ' . $vertical;
    }

    /**
     * How the drawing inside this element's markup relates to the element's box.
     *
     * The markup an element exports is rendered once, on the server, for the box
     * the element had at that moment, and some of what it contains is a fixed
     * length worked out from that box: a seal states the side of its square, and
     * a text element states the point size its words were shrunk to so they
     * would fit. Resizing the box on the canvas restyles the outer node alone,
     * so until fresh markup arrives the drawing inside keeps the size it was
     * exported at and the designer stops showing what will print.
     *
     * Fresh markup does arrive - {@see \mod_certificatepro\external\designer_api::save_layout()}
     * re-exports whatever the save reshaped - but the autosave is debounced, so
     * it arrives a moment after the drag rather than during it. This is what
     * lets the browser bridge that moment honestly: rather than the designer
     * knowing that a seal is square and that text shrinks to fit, the element
     * says so itself, and the designer only applies the rule it is handed.
     *
     * The answer describes the markup that has just been rendered, so it must be
     * asked for after render_html() and not before.
     *
     * @param render_context $ctx the render context
     * @return array the fit descriptor, always carrying the box it describes
     */
    protected function canvas_fit(render_context $ctx): array {
        $fit = [
            // Everything the designer needs to tell whether this description is
            // still the current one. Markup exported for a box the element has
            // since left has been overtaken by a later drag, and applying it
            // would put the canvas back where the author has just moved it on
            // from. Position is not here because no element's markup depends on
            // it: box_origin() is a print concern, and the canvas draws the box.
            'boxwidth' => $this->get_width(),
            'boxheight' => $this->get_height(),
            'refpoint' => $this->get_refpoint(),
            'alignment' => $this->get_alignment(),
            'valignment' => $this->get_valignment(),
            'mode' => 'none',
            'minsize' => '',
            'maxsize' => '',
        ];

        if ($this->canvasdrawn !== null) {
            [$drawnwidth, $drawnheight] = $this->canvasdrawn;

            // A square drawing in a box that need not be square is drawn at the
            // shorter side, which is what keeps a seal's ring lettering inside
            // its box; anything else was stated as a plain fraction of the box
            // and follows each side of it separately.
            $fit['mode'] = abs($drawnwidth - $drawnheight) < 0.001 ? 'square' : 'box';

            return $fit;
        }

        // Type set to fit its box is the other thing a resize invalidates, and
        // text_base is where every element that sets type inherits that
        // behaviour from, so asking the object rather than listing the types
        // keeps a new text element working the day it is written.
        if ($this instanceof text_base && $this->style('fit', true)
                && ($this->get_height() > 0 || (int) $this->style('maxlines', 0) > 0)) {
            $fit['mode'] = 'type';

            // The two ends of shrink to fit, in the same unit the markup states
            // its point size in, so the designer can clamp a scaled size against
            // them without knowing anything about points or paper.
            $fit['minsize'] = $this->canvas_points($ctx, text_base::MIN_FIT_SIZE);
            $fit['maxsize'] = $this->canvas_points($ctx, $this->resolve_size($ctx));
        }

        return $fit;
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
        // Drawn first, because canvas_fit() reports on the markup that exists
        // rather than on the markup that is about to.
        $html = $this->render_html($ctx);

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
            'keepsquare' => static::keepsquare(),
            'resizable' => static::resizable(),
            'style' => $this->get_style(),
            'data' => $this->get_data(),
            'html' => $html,
            'canvasfit' => $this->canvas_fit($ctx),
        ];
    }
}
