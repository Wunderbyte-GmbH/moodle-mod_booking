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

namespace mod_booking;

use context;
/**
 * Helper functions to check permissions.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class permissions {
    /**
     * Checks a capability in all possible contexts. It's expensive.
     * @param string $capability
     * @param int $contextlevel
     * @return bool
     */
    public static function has_capability_anywhere($capability = 'moodle/course:manageactivities', $contextlevel = CONTEXT_MODULE) {

        global $DB;

        $sql = "SELECT ctx.id AS contextid
            FROM {context} ctx
            WHERE ctx.contextlevel = :contextlevel";

        $contextids = $DB->get_fieldset_sql($sql, ['contextlevel' => $contextlevel]);

        foreach ($contextids as $contextid) {
            $context = context::instance_by_id($contextid);
            if (has_capability($capability, $context)) {
                $hascapability = true;
                return true;
            }
        }
        return false;
    }
}
