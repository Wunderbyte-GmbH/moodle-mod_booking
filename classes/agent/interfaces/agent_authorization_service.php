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

/**
 * Agent authorization service interface.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\agent\interfaces;

/**
 * Interface for agent authorization checks.
 *
 * All capability and context checks must go through this service so that
 * the same rules are applied in the web-service layer, the async task, and
 * any future extraction into a standalone plugin.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface agent_authorization_service {

    /**
     * Assert that the given user may use the AI instructions feature for this cmid.
     *
     * Throws \required_capability_exception on failure.
     *
     * @param int $userid
     * @param int $cmid
     * @return void
     */
    public function require_use_capability(int $userid, int $cmid): void;

    /**
     * Return true if the user has permission to use AI instructions.
     *
     * @param int $userid
     * @param int $cmid
     * @return bool
     */
    public function can_use(int $userid, int $cmid): bool;

    /**
     * Assert that the context (cmid) belongs to an active booking module.
     *
     * Throws \moodle_exception on failure.
     *
     * @param int $cmid
     * @return void
     */
    public function require_valid_context(int $cmid): void;
}
