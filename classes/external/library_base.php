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

namespace mod_certificatepro\external;

use core_external\external_api;
use mod_certificatepro\template;

/**
 * Shared plumbing for the services behind the design libraries.
 *
 * Every library service loads the design first and re-checks the capability
 * against that design's own context, exactly as designer_api does. Holding the
 * manage capability in one course must never reach a design in another, and a
 * template id arriving from the browser is never trusted to name a context.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class library_base extends external_api {
    /**
     * Load a design and confirm the caller may edit it.
     *
     * @param int $templateid the design to load
     * @return template
     */
    protected static function require_editable(int $templateid): template {
        $template = template::load($templateid);
        $context = $template->get_context();

        self::validate_context($context);

        if ($context->contextlevel === CONTEXT_SYSTEM) {
            require_capability('mod/certificatepro:managetemplates', $context);
        } else {
            require_capability('mod/certificatepro:manage', $context);
        }

        return $template;
    }

    /**
     * Load a design, confirm the caller may edit it, and confirm they may read it.
     *
     * Read-only library services still need the manage capability: the galleries
     * describe a design an author is editing and carry its palette.
     *
     * @param int $templateid the design to load
     * @return template
     */
    protected static function require_readable(int $templateid): template {
        return self::require_editable($templateid);
    }

    /**
     * Confirm a page belongs to a design.
     *
     * @param template $template the design
     * @param int $pageid the page to check, or 0 for the first page
     * @return \stdClass the page record
     */
    protected static function require_page(template $template, int $pageid): \stdClass {
        $pages = $template->get_pages();

        if ($pageid === 0) {
            $first = reset($pages);
            if ($first === false) {
                throw new \moodle_exception('errorpagenotfound', 'mod_certificatepro');
            }
            return $first;
        }

        if (!isset($pages[$pageid])) {
            throw new \moodle_exception('errorpagenotfound', 'mod_certificatepro');
        }

        return $pages[$pageid];
    }

    /**
     * A translated label, falling back to the English already held in the domain class.
     *
     * The catalogues describe themselves in English — a style is called "Engine
     * Field", a brief option reads "Very sparse — mostly empty paper". Those
     * strings belong to the catalogue, not to this layer, so a site that has
     * translated them wins and a site that has not still reads correctly rather
     * than showing a missing-string marker.
     *
     * @param string $key the language string identifier to prefer
     * @param string $fallback the catalogue's own English
     * @return string
     */
    protected static function label(string $key, string $fallback): string {
        if (get_string_manager()->string_exists($key, 'mod_certificatepro')) {
            return get_string($key, 'mod_certificatepro');
        }
        return $fallback;
    }

    /**
     * Reduce a display family name to the key the font manager stores.
     *
     * "EB Garamond" is how a person and the style catalogue name a family;
     * "ebgaramond" is how TCPDF and the font table name it.
     *
     * @param string $family the display name
     * @return string the family key
     */
    protected static function family_key(string $family): string {
        return \core_text::strtolower(preg_replace('/[^a-z0-9]/i', '', $family) ?? '');
    }
}
