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

/**
 * The recipient's profile picture.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userpicture extends image_base {
    /**
     * The element type identifier.
     *
     * @return string
     */
    public static function type(): string {
        return 'userpicture';
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
        return 'fa-circle-user';
    }

    /**
     * The default box size in millimetres.
     *
     * @return array{0: float, 1: float}
     */
    public static function default_size(): array {
        return [25.0, 25.0];
    }

    /**
     * A learner's own picture is never something the shared library holds.
     *
     * @return bool
     */
    protected function has_media_picker(): bool {
        return false;
    }

    /**
     * Add the shape and image options.
     *
     * @param \MoodleQuickForm $mform the form to extend
     * @param \context $context the context the element lives in
     * @return void
     */
    public function definition_form(\MoodleQuickForm $mform, \context $context): void {
        $mform->addElement(
            'advcheckbox',
            'circular',
            get_string('picturecircular', 'mod_certificatepro')
        );
        $mform->setDefault('circular', 1);

        $this->add_common_image_settings($mform);
    }

    /**
     * Persist the picture options.
     *
     * @param \stdClass $data the submitted data
     * @return array
     */
    public function save_form_data(\stdClass $data): array {
        return array_merge(
            $this->common_image_data($data),
            [
                'circular' => !empty($data->circular) ? 1 : 0,
            ]
        );
    }

    /**
     * The recipient's picture on disk.
     *
     * @param render_context $ctx the render context
     * @return string|null
     */
    protected function image_path(render_context $ctx): ?string {
        $user = $ctx->get_user();
        if ($user === null || empty($user->picture)) {
            return null;
        }

        $fs = get_file_storage();
        $usercontext = \context_user::instance($user->id, IGNORE_MISSING);
        if (!$usercontext) {
            return null;
        }

        $file = $fs->get_file($usercontext->id, 'user', 'icon', 0, '/', 'f1.png')
            ?: $fs->get_file($usercontext->id, 'user', 'icon', 0, '/', 'f1.jpg');
        if (!$file) {
            return null;
        }

        $path = make_request_directory() . '/userpicture.' .
            pathinfo($file->get_filename(), PATHINFO_EXTENSION);
        $file->copy_content_to($path);

        return $path;
    }}
