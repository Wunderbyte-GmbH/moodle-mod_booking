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

use context_module;
use moodle_page;
use Throwable;

/**
 * Helper functions for context in booking.
 *
 * @package     mod_booking
 * @copyright   2023 Wunderbyte GmbH
 * @author      Bernhard Fischer
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_context_helper {
    /**
     * Helper function to fix $PAGE->context problems in booking,
     * e.g. with shortcodes.
     *
     * @param moodle_page $page reference to global $PAGE instance.
     * @param int $cmid course module id of the booking instance
     */
    public static function fix_booking_page_context(moodle_page &$page, int $cmid) {
        global $PAGE;

        // With shortcodes & webservice we might not have a valid context object.
        try {
            if (!$PAGE->has_set_url()) {
                $PAGE->set_url('/');
            }
        } catch (Throwable $e) {
            $PAGE->set_url('/');
        }

        try {
            if (!$context = $page->context ?? null) {
                if (empty($context)) {
                    $page->set_context(context_module::instance($cmid));
                }
            }
        } catch (Throwable $e) {
            $page->set_context(context_module::instance($cmid));
        }
    }
}
