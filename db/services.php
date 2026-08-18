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

/**
 * Web service definitions for the Certificate Pro activity module.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_certificatepro_save_layout' => [
        'classname' => 'mod_certificatepro\external\designer_api',
        'methodname' => 'save_layout',
        'description' => 'Save the position and size of every element on a page.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_certificatepro_add_element' => [
        'classname' => 'mod_certificatepro\external\designer_api',
        'methodname' => 'add_element',
        'description' => 'Add an element to a page, optionally docked into a layout zone.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_duplicate_elements' => [
        'classname' => 'mod_certificatepro\external\designer_api',
        'methodname' => 'duplicate_elements',
        'description' => 'Duplicate one or more elements on a page.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_delete_elements' => [
        'classname' => 'mod_certificatepro\external\designer_api',
        'methodname' => 'delete_elements',
        'description' => 'Delete one or more elements from a design.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_generate_artwork' => [
        'classname' => 'mod_certificatepro\external\designer_api',
        'methodname' => 'generate_artwork',
        'description' => 'Generate background artwork for a certificate design.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:generateartwork',
    ],
    'mod_certificatepro_get_style_combinations' => [
        'classname' => 'mod_certificatepro\external\style_api',
        'methodname' => 'get_style_combinations',
        'description' => 'List the typography combinations, with a specimen of each.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_apply_style_combination' => [
        'classname' => 'mod_certificatepro\external\style_api',
        'methodname' => 'apply_style_combination',
        'description' => 'Restyle every text element in a design from one combination.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_get_background_styles' => [
        'classname' => 'mod_certificatepro\external\library_api',
        'methodname' => 'get_background_styles',
        'description' => 'List the procedural background styles, grouped by family.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_get_background_previews' => [
        'classname' => 'mod_certificatepro\external\library_api',
        'methodname' => 'get_background_previews',
        'description' => 'Draw a few background previews in the design palette.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_apply_background' => [
        'classname' => 'mod_certificatepro\external\library_api',
        'methodname' => 'apply_background',
        'description' => 'Draw a procedural background and place it on a page.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_get_ornaments' => [
        'classname' => 'mod_certificatepro\external\library_api',
        'methodname' => 'get_ornaments',
        'description' => 'Browse the bundled public domain ornament.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_place_ornament' => [
        'classname' => 'mod_certificatepro\external\library_api',
        'methodname' => 'place_ornament',
        'description' => 'Place a bundled ornament on a page.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_get_artwork_brief' => [
        'classname' => 'mod_certificatepro\external\brief_api',
        'methodname' => 'get_artwork_brief',
        'description' => 'The questions the guided artwork wizard asks.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_preview_artwork_brief' => [
        'classname' => 'mod_certificatepro\external\brief_api',
        'methodname' => 'preview_artwork_brief',
        'description' => 'Show what a set of artwork answers resolves to.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_apply_artwork_brief' => [
        'classname' => 'mod_certificatepro\external\brief_api',
        'methodname' => 'apply_artwork_brief',
        'description' => 'Generate artwork from a brief and place it on a page.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:generateartwork',
    ],
    'mod_certificatepro_get_seal_brief' => [
        'classname' => 'mod_certificatepro\external\brief_api',
        'methodname' => 'get_seal_brief',
        'description' => 'The questions the guided seal wizard asks.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_preview_seal_brief' => [
        'classname' => 'mod_certificatepro\external\brief_api',
        'methodname' => 'preview_seal_brief',
        'description' => 'Draw the seal a set of answers describes.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_apply_seal_brief' => [
        'classname' => 'mod_certificatepro\external\brief_api',
        'methodname' => 'apply_seal_brief',
        'description' => 'Place or restyle a seal from a brief.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:manage',
    ],
    'mod_certificatepro_get_certificates' => [
        'classname' => 'mod_certificatepro\external\learner_api',
        'methodname' => 'get_certificates',
        'description' => 'List the certificates a user has been issued.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/certificatepro:view',
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
    'mod_certificatepro_verify_code' => [
        'classname' => 'mod_certificatepro\external\learner_api',
        'methodname' => 'verify_code',
        'description' => 'Check a certificate verification code.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => false,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
