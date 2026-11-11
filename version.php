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
 * Version details for the Certificate Pro activity module.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026111105;

// Moodle 4.4. The code's own floor is lower — the newest core APIs it touches
// are core/modal's static Modal.create() from 4.3 and the namespaced
// core_external classes from 4.2 — so 4.4 sits comfortably above what it
// actually needs, and nothing here uses a 4.5 or 5.0 API.
$plugin->requires  = 2024042200;

// Every branch from 4.4 to 5.1. An administrator outside this range still gets
// the option to install, with a warning, rather than being refused outright.
$plugin->supported = [405, 503]; // Moodle 4.5 to 5.3.

$plugin->component = 'mod_certificatepro';
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '2.0.5';
$plugin->cron      = 0;
