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

namespace mod_certificatepro\local\ai;

/**
 * Composes artwork through the site's own Moodle artificial intelligence subsystem.
 *
 * This is the preferred route on any site that has configured a provider,
 * because the administrator already holds the credentials, the site's own
 * policy and consent settings apply, and nothing new leaves the site that was
 * not already permitted to.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_ai_provider implements provider {
    /**
     * Whether the core subsystem exists and the administrator has allowed its use here.
     *
     * @return bool
     */
    public function is_available(): bool {
        if (!class_exists('\core_ai\manager')) {
            return false;
        }
        if (!class_exists('\core_ai\aiactions\generate_text')) {
            return false;
        }
        return (bool) get_config('mod_certificatepro', 'usecoreai');
    }

    /**
     * The identifier recorded against artwork from this route.
     *
     * @return string
     */
    public function source(): string {
        return generator::SOURCE_COREAI;
    }

    /**
     * Ask the site's provider to compose artwork.
     *
     * @param string $instruction the full instruction to send
     * @param \context $context the context the request is made in
     * @return string|null
     */
    public function compose(string $instruction, \context $context): ?string {
        global $USER;

        $actionclass = '\core_ai\aiactions\generate_text';
        $managerclass = '\core_ai\manager';

        $action = new $actionclass(
            contextid: $context->id,
            userid: (int) $USER->id,
            prompttext: $instruction
        );

        // Moodle 5.0+ injects the database into the manager's constructor; the DI container builds it on 4.5 too.
        $manager = \core\di::get($managerclass);
        $response = $manager->process_action($action);

        if (!$response || !$response->get_success()) {
            return null;
        }

        $data = $response->get_response_data();

        if (is_array($data)) {
            foreach (['generatedcontent', 'content', 'text'] as $key) {
                if (!empty($data[$key]) && is_string($data[$key])) {
                    return $data[$key];
                }
            }
            return null;
        }

        return is_string($data) ? $data : null;
    }
}
