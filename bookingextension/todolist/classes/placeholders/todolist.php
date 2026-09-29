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

namespace bookingextension_todolist\placeholders;

use bookingextension_todolist\local\todolist_helper;
use mod_booking\placeholders\placeholder_base;

/**
 * Placeholder for rendering todo list as plain text.
 *
 * @package     bookingextension_todolist
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class todolist extends placeholder_base {
    /**
     * Return replacement value for {todolist}.
     *
     * @param int $cmid
     * @param int $optionid
     * @param int $userid
     * @param int $installmentnr
     * @param int $duedate
     * @param float $price
     * @param string $text
     * @param array $params
     * @param int $descriptionparam
     * @param string $rulejson
     * @return string
     */
    public static function return_value(
        int $cmid = 0,
        int $optionid = 0,
        int $userid = 0,
        int $installmentnr = 0,
        int $duedate = 0,
        float $price = 0,
        string &$text = '',
        array &$params = [],
        int $descriptionparam = MOD_BOOKING_DESCRIPTION_WEBSITE,
        string $rulejson = ''
    ): string {
        $optionid = self::resolve_optionid($optionid, $rulejson);
        return todolist_helper::format_as_plaintext($optionid);
    }

    /**
     * Resolve a valid booking option id for placeholder rendering.
     *
     * @param int $optionid
     * @param string $rulejson
     * @return int
     */
    private static function resolve_optionid(int $optionid, string $rulejson): int {
        global $DB;

        if ($optionid > 0 && $DB->record_exists('booking_options', ['id' => $optionid])) {
            return $optionid;
        }

        $ruleobject = json_decode($rulejson);
        if (!empty($ruleobject->datafromevent->other->optionid)) {
            $eventoptionid = (int)$ruleobject->datafromevent->other->optionid;
            if ($eventoptionid > 0) {
                return $eventoptionid;
            }
        }

        if ($optionid > 0) {
            $itemoptionid = (int)$DB->get_field('bookingextension_todolist_item', 'optionid', ['id' => $optionid]);
            if ($itemoptionid > 0) {
                return $itemoptionid;
            }
        }

        return 0;
    }

    /**
     * Placeholder is always applicable.
     *
     * @return bool
     */
    public static function is_applicable(): bool {
        return true;
    }
}
