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
use mod_certificatepro\local\media_library;

/**
 * The site logo, pulled straight from the Moodle theme so it never goes stale.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class themelogo extends image_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'themelogo';
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
        return 'fa-certificate';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [40.0, 20.0];
    }

    /**
     * Which logo to draw.
     *
     * @return array<string, string>
     */
    public static function sources(): array {
        $sources = [
            'themefull' => get_string('logovariant_full', 'mod_certificatepro'),
            'themecompact' => get_string('logovariant_compact', 'mod_certificatepro'),
        ];

        foreach (brand::logo_slots() as $slot => $name) {
            $sources['slot' . $slot] = $name;
        }

        $sources['library'] = get_string('logovariant_library', 'mod_certificatepro');

        return $sources;
    }

    /**
     * Choose which logo this element draws.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement('select', 'logosource',
            get_string('logosource', 'mod_certificatepro'), self::sources());
        $mform->setDefault('logosource', 'themefull');
        $mform->addHelpButton('logosource', 'logosource', 'mod_certificatepro');

        $this->add_common_image_settings($mform);
        $mform->hideIf('mediaid', 'logosource', 'neq', 'library');
    }

    /**
     * Persist the logo options.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        $source = $data->logosource ?? 'themefull';

        return array_merge($this->common_image_data($data), [
            'logosource' => array_key_exists($source, self::sources()) ? $source : 'themefull',
        ]);
    }

    /**
     * Logos are the one thing the shared library is most needed for.
     *
     * @return string|null
     */
    protected function media_kind(): ?string {
        return media_library::KIND_LOGO;
    }

    /**
     * The chosen source, falling back to the theme logo.
     *
     * @return string
     */
    protected function source(): string {
        $source = (string) $this->config('logosource', '');

        if ($source === '') {
            // Designs made before named slots existed stored a variant instead.
            $variant = (string) $this->config('logovariant', 'full');
            return $variant === 'compact' ? 'themecompact' : 'themefull';
        }

        return $source;
    }

    /**
     * The chosen logo on disk.
     *
     * @param render_context $ctx the render context
     * @return string|null an absolute path, or null when there is nothing to draw
     */
    protected function image_path(render_context $ctx): ?string {
        $source = $this->source();

        if (str_starts_with($source, 'slot')) {
            return brand::slot_logo_path((int) substr($source, 4));
        }

        if ($source === 'library') {
            return media_library::path($this->media_id());
        }

        return brand::logo_path($source === 'themecompact');
    }

    /**
     * The chosen logo as a browser URL.
     *
     * @param render_context $ctx the render context
     * @return string|null
     */
    protected function image_url(render_context $ctx): ?string {
        $source = $this->source();

        if (str_starts_with($source, 'slot')) {
            return brand::slot_logo_url((int) substr($source, 4));
        }

        if ($source === 'library') {
            return media_library::url($this->media_id());
        }

        $url = brand::logo_url();

        return $url ? $url->out(false) : null;
    }

    /**
     * The intrinsic size of the chosen logo.
     *
     * @param render_context $ctx the render context
     * @return array{0: int, 1: int}
     */
    protected function intrinsic_size(render_context $ctx): array {
        $source = $this->source();

        if (str_starts_with($source, 'slot')) {
            return brand::slot_logo_dimensions((int) substr($source, 4));
        }

        if ($source === 'library') {
            return media_library::dimensions($this->media_id());
        }

        return brand::logo_dimensions($source === 'themecompact');
    }
}
