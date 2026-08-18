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
 * A route by which a written description becomes vector artwork.
 *
 * @package    mod_certificatepro
 * @copyright  2026 LMS Labs <https://lms-labs.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface provider {

    /**
     * Whether this route is configured and usable right now.
     *
     * @return bool
     */
    public function is_available(): bool;

    /**
     * The identifier recorded against artwork this route produces.
     *
     * @return string
     */
    public function source(): string;

    /**
     * Ask the route to compose artwork.
     *
     * @param string $instruction the full instruction to send
     * @param \context $context the context the request is made in
     * @return string|null raw markup, or null when nothing came back
     */
    public function compose(string $instruction, \context $context): ?string;
}
