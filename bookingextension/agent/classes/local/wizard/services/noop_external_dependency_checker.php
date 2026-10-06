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

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\dto\preflight_result_v2;
use bookingextension_agent\local\wizard\interfaces\external_dependency_checker_interface;

/**
 * Default no-op PF_L3_EXT implementation.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class noop_external_dependency_checker implements external_dependency_checker_interface {
    /**
     * No-op external dependency check.
     *
     * @param array $command
     * @param int $contextid
     * @param int $userid
     * @return preflight_result_v2
     */
    public function check(array $command, int $contextid, int $userid): preflight_result_v2 {
        $input = is_array($command['input'] ?? null) ? (array)$command['input'] : [];
        return preflight_result_v2::ok($input);
    }
}
