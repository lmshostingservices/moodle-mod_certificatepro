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

use mod_certificatepro\local\art\ground;

/**
 * Everything an element needs in order to resolve itself to a value.
 *
 * One context is built per render. In preview mode the context carries sample
 * data so an author sees a realistic certificate without issuing anything. When
 * a real certificate is rendered from a stored issue the context is hydrated
 * from that issue's snapshot, which is what makes an issued certificate
 * immutable: renaming the course later cannot change what it says.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class render_context {
    /** @var string A live render for a real issued certificate. */
    public const MODE_ISSUE = 'issue';

    /** @var string A designer preview using sample data. */
    public const MODE_PREVIEW = 'preview';

    /** @var string A render replayed from a stored snapshot. */
    public const MODE_SNAPSHOT = 'snapshot';

    /** @var string The current render mode. */
    protected string $mode = self::MODE_PREVIEW;

    /** @var \stdClass|null The user the certificate is for. */
    protected ?\stdClass $user = null;

    /** @var \stdClass|null The course the certificate belongs to. */
    protected ?\stdClass $course = null;

    /** @var \stdClass|null The activity instance. */
    protected ?\stdClass $instance = null;

    /** @var \stdClass|null The issue record, when one exists. */
    protected ?\stdClass $issue = null;

    /** @var \context The context to resolve files and capabilities against. */
    protected \context $context;

    /** @var array Values already resolved, keyed by element id. */
    protected array $snapshot = [];

    /** @var array The palette in use for this render, as the design stored it. */
    protected array $palette = [];

    /** @var string How text must sit on the page being drawn. */
    protected string $groundink = ground::INK_DARK;

    /** @var string|null The ground colour the page named, when it named one. */
    protected ?string $groundcolour = null;

    /** @var array|null The palette as this ground needs it, built on first use. */
    protected ?array $resolvedpalette = null;

    /** @var string The font pairing identifier in use. */
    protected string $pairing = 'system';

    /** @var float The page width in millimetres. */
    protected float $pagewidth = 297.0;

    /** @var float The page height in millimetres. */
    protected float $pageheight = 210.0;

    /**
     * Build a context.
     *
     * @param \context $context the context to resolve against
     */
    public function __construct(\context $context) {
        $this->context = $context;
        $this->palette = \mod_certificatepro\local\brand::palette();
    }

    /**
     * Create a preview context populated with believable sample data.
     *
     * @param \context $context the context to resolve against
     * @param \stdClass|null $course the real course, when there is one
     * @param \stdClass|null $instance the real activity instance, when there is one
     * @return self
     */
    public static function preview(\context $context, ?\stdClass $course = null,
            ?\stdClass $instance = null): self {
        global $USER;

        $ctx = new self($context);
        $ctx->mode = self::MODE_PREVIEW;
        $ctx->user = $USER;
        $ctx->course = $course;
        $ctx->instance = $instance;

        $issue = new \stdClass();
        $issue->id = 0;
        $issue->code = 'PREVIEW-0000-0000';
        $issue->serial = 'SAMPLE-000001';
        $issue->timecreated = time();
        $issue->expires = 0;
        $issue->revoked = 0;
        $ctx->issue = $issue;

        return $ctx;
    }

    /**
     * Create a context for a real issue.
     *
     * @param \context $context the module context
     * @param \stdClass $instance the activity instance
     * @param \stdClass $course the course
     * @param \stdClass $user the recipient
     * @param \stdClass $issue the issue record
     * @return self
     */
    public static function for_issue(\context $context, \stdClass $instance, \stdClass $course,
            \stdClass $user, \stdClass $issue): self {
        $ctx = new self($context);
        $ctx->mode = self::MODE_ISSUE;
        $ctx->instance = $instance;
        $ctx->course = $course;
        $ctx->user = $user;
        $ctx->issue = $issue;

        if (!empty($issue->snapshot)) {
            $decoded = json_decode($issue->snapshot, true);
            if (is_array($decoded)) {
                $ctx->snapshot = $decoded;
                $ctx->mode = self::MODE_SNAPSHOT;
                return $ctx;
            }
        }

        // Capture the tokens up front. A design whose wording happens to contain
        // no placeholders would otherwise never trigger resolution, and the
        // snapshot would be missing the very values that make it immutable.
        $ctx->tokens();

        return $ctx;
    }

    /**
     * The current render mode.
     *
     * @return string one of the MODE_ constants
     */
    public function get_mode(): string {
        return $this->mode;
    }

    /**
     * Whether this render is a designer preview.
     *
     * @return bool
     */
    public function is_preview(): bool {
        return $this->mode === self::MODE_PREVIEW;
    }

    /**
     * Whether values should be read back from a stored snapshot.
     *
     * @return bool
     */
    public function is_snapshot(): bool {
        return $this->mode === self::MODE_SNAPSHOT;
    }

    /**
     * The recipient.
     *
     * @return \stdClass|null
     */
    public function get_user(): ?\stdClass {
        return $this->user;
    }

    /**
     * The course.
     *
     * @return \stdClass|null
     */
    public function get_course(): ?\stdClass {
        return $this->course;
    }

    /**
     * The activity instance.
     *
     * @return \stdClass|null
     */
    public function get_instance(): ?\stdClass {
        return $this->instance;
    }

    /**
     * The issue record.
     *
     * @return \stdClass|null
     */
    public function get_issue(): ?\stdClass {
        return $this->issue;
    }

    /**
     * The context files and capabilities resolve against.
     *
     * @return \context
     */
    public function get_context(): \context {
        return $this->context;
    }

    /**
     * The palette roles resolve against for the page being drawn.
     *
     * This is the design's own palette on an ordinary light page, and the same
     * palette adapted to the ground on a dark one. Everything that draws a
     * colour — text, seals, borders, rules, shapes, signatures, codes — asks
     * here, so one answer keeps the whole page legible, and keeps the canvas
     * and the print saying the same thing.
     *
     * @return array<string, string>
     */
    public function get_palette(): array {
        if ($this->resolvedpalette === null) {
            $this->resolvedpalette = ground::adapt_palette(
                $this->palette, $this->groundink, $this->groundcolour);
        }

        return $this->resolvedpalette;
    }

    /**
     * The palette exactly as the design stores it, before any ground adaptation.
     *
     * Used where the question is what the design's colours are rather than how
     * they have to be drawn: painting the background artwork itself, for one,
     * which must not be lifted off its own ground.
     *
     * @return array<string, string>
     */
    public function get_base_palette(): array {
        return $this->palette;
    }

    /**
     * Replace the palette for this render.
     *
     * @param array $palette a palette from {@see \mod_certificatepro\local\brand::palette()}
     * @return void
     */
    public function set_palette(array $palette): void {
        $this->palette = $palette;
        $this->resolvedpalette = null;
    }

    /**
     * Record what the page being drawn is standing on.
     *
     * @param string $ink one of the ground::INK_ constants
     * @param string|null $colour the ground colour, when the page named one
     * @return void
     */
    public function set_ground(string $ink, ?string $colour = null): void {
        $this->groundink = $ink === ground::INK_LIGHT ? ground::INK_LIGHT : ground::INK_DARK;
        $this->groundcolour = $colour;
        $this->resolvedpalette = null;
    }

    /**
     * How text must sit on the page being drawn.
     *
     * @return string one of the ground::INK_ constants
     */
    public function get_ground_ink(): string {
        return $this->groundink;
    }

    /**
     * Whether the page being drawn needs its text reversed out of the ground.
     *
     * @return bool
     */
    public function needs_light_ink(): bool {
        return $this->groundink === ground::INK_LIGHT;
    }

    /**
     * The font pairing identifier for this render.
     *
     * @return string
     */
    public function get_pairing(): string {
        return $this->pairing;
    }

    /**
     * Set the font pairing identifier for this render.
     *
     * @param string $pairing a key from the typography catalogue
     * @return void
     */
    public function set_pairing(string $pairing): void {
        $this->pairing = $pairing;
    }

    /**
     * Record the page geometry currently being rendered.
     *
     * @param float $width the page width in millimetres
     * @param float $height the page height in millimetres
     * @return void
     */
    public function set_page_size(float $width, float $height): void {
        $this->pagewidth = $width;
        $this->pageheight = $height;
    }

    /**
     * Enter a page: its geometry and the ground it stands on, together.
     *
     * The two always have to be set as a pair, because a design can put a dark
     * page next to a light one and whatever was resolved for the previous page
     * must not leak into the next.
     *
     * @param \stdClass $page a page record
     * @return void
     */
    public function set_page(\stdClass $page): void {
        $this->set_page_size((float) $page->width, (float) $page->height);

        $resolved = ground::for_page($page);
        $this->set_ground($resolved['ink'], $resolved['colour']);
    }

    /**
     * The page width in millimetres.
     *
     * @return float
     */
    public function get_page_width(): float {
        return $this->pagewidth;
    }

    /**
     * The page height in millimetres.
     *
     * @return float
     */
    public function get_page_height(): float {
        return $this->pageheight;
    }

    /**
     * A value previously stored in the snapshot for an element.
     *
     * @param int $elementid the element the value belongs to
     * @return mixed|null the stored value, or null when there is none
     */
    public function snapshot_value(int $elementid) {
        $key = 'e' . $elementid;
        return $this->snapshot[$key] ?? null;
    }

    /**
     * Store a resolved value against an element.
     *
     * @param int $elementid the element the value belongs to
     * @param mixed $value the resolved value
     * @return void
     */
    public function record_value(int $elementid, $value): void {
        $this->snapshot['e' . $elementid] = $value;
    }

    /**
     * Everything resolved during this render, ready to persist on an issue.
     *
     * @return array
     */
    public function get_snapshot(): array {
        return $this->snapshot;
    }

    /**
     * Merge extra values into the snapshot.
     *
     * @param array $values values to merge, keyed by snapshot key
     * @return void
     */
    public function merge_snapshot(array $values): void {
        $this->snapshot = array_merge($this->snapshot, $values);
    }

    /**
     * The tokens available for interpolation inside text elements.
     *
     * @return array<string, string> token name without braces mapped to its value
     */
    public function tokens(): array {
        if (!empty($this->snapshot['tokens']) && is_array($this->snapshot['tokens'])) {
            return $this->snapshot['tokens'];
        }

        $user = $this->user;
        $course = $this->course;
        $issue = $this->issue;

        $tokens = [
            'user.firstname' => $user->firstname ?? get_string('sample_firstname', 'mod_certificatepro'),
            'user.lastname' => $user->lastname ?? get_string('sample_lastname', 'mod_certificatepro'),
            'user.fullname' => $user ? fullname($user) : get_string('sample_fullname', 'mod_certificatepro'),
            'user.email' => $user->email ?? 'learner@example.com',
            'user.idnumber' => $user->idnumber ?? '',
            'user.city' => $user->city ?? '',
            'user.country' => $user->country ?? '',
            'course.fullname' => $course
                ? format_string($course->fullname, true, ['context' => $this->context])
                : get_string('sample_coursename', 'mod_certificatepro'),
            'course.shortname' => $course
                ? format_string($course->shortname, true, ['context' => $this->context])
                : 'SAMPLE-101',
            'course.idnumber' => $course->idnumber ?? '',
            'site.fullname' => format_string(get_site()->fullname, true, ['context' => \context_system::instance()]),
            'site.shortname' => format_string(get_site()->shortname, true, ['context' => \context_system::instance()]),
            'site.initials' => \mod_certificatepro\local\art\seal_engine::initials(
                format_string(get_site()->fullname, true, ['context' => \context_system::instance()])),
            'cert.code' => $issue->code ?? '',
            'cert.serial' => $issue->serial ?? '',
            'cert.date' => userdate($issue->timecreated ?? time(), get_string('strftimedate', 'langconfig')),
            'cert.expiry' => !empty($issue->expires)
                ? userdate($issue->expires, get_string('strftimedate', 'langconfig'))
                : get_string('neverexpires', 'mod_certificatepro'),
            'cert.verifyurl' => $this->verify_url(),
        ];

        $this->snapshot['tokens'] = $tokens;

        return $tokens;
    }

    /**
     * The public verification URL for this certificate.
     *
     * @return string
     */
    public function verify_url(): string {
        $params = [];
        if (!empty($this->issue->code)) {
            $params['code'] = $this->issue->code;
        }
        return (new \moodle_url('/mod/certificatepro/verify.php', $params))->out(false);
    }

    /**
     * Replace every double brace token in a string with its resolved value.
     *
     * Unknown tokens are left untouched so an author can see their mistake.
     *
     * @param string $text the text to interpolate
     * @return string
     */
    public function interpolate(string $text): string {
        if (strpos($text, '{{') === false) {
            return $text;
        }
        $tokens = $this->tokens();
        return preg_replace_callback('/\{\{\s*([a-z]+\.[a-z]+)\s*\}\}/i',
            static function (array $matches) use ($tokens): string {
                $key = strtolower($matches[1]);
                return array_key_exists($key, $tokens) ? (string) $tokens[$key] : $matches[0];
            }, $text) ?? $text;
    }
}
