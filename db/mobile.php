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
 * Mobile app definitions for the Certificate Pro activity module.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$addons = [
    'mod_certificatepro' => [
        'handlers' => [
            'view' => [
                'displaydata' => [
                    'title' => 'pluginname',
                    'icon' => $CFG->wwwroot . '/mod/certificatepro/pix/monologo.svg',
                    'class' => '',
                ],
                'delegate' => 'CoreCourseModuleDelegate',
                'method' => 'mobile_view',
                'offlinefunctions' => [
                    'mobile_view' => [],
                ],
                'styles' => [
                    'url' => $CFG->wwwroot . '/mod/certificatepro/mobile/styles.css',
                    'version' => 1,
                ],
            ],
        ],
        'lang' => [
            ['pluginname', 'mod_certificatepro'],
            ['downloadcertificate', 'mod_certificatepro'],
            ['notyetavailable', 'mod_certificatepro'],
            ['notissuedyet', 'mod_certificatepro'],
            ['status_valid', 'mod_certificatepro'],
            ['status_expired', 'mod_certificatepro'],
            ['status_revoked', 'mod_certificatepro'],
            ['verifytitle', 'mod_certificatepro'],
        ],
    ],
];
