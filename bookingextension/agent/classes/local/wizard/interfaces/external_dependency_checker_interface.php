<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace bookingextension_agent\local\wizard\interfaces;

use bookingextension_agent\local\wizard\dto\preflight_result_v2;

/**
 * External dependency checker contract for PF_L3_EXT.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface external_dependency_checker_interface {
    /**
     * Check external dependencies for one command.
     *
     * @param array $command
     * @param int $contextid
     * @param int $userid
     * @return preflight_result_v2
     */
    public function check(array $command, int $contextid, int $userid): preflight_result_v2;
}
