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

use context_system;
use moodle_page;
use Throwable;

/**
 * Class to fix problems with context handling in booking.
 *
 * Copied from local_shopping_cart so mod_booking has no hard dependency
 * on the shopping cart plugin.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH
 * @author Bernhard Fischer-Sengseis
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class context_helper {
    /**
     * Helper function to fix $PAGE->context problems ,
     * e.g. with shortcodes.
     *
     * @param moodle_page $page reference to global $PAGE instance.
     */
    public static function fix_page_context(moodle_page &$page): void {
        // With shortcodes & webservice we might not have a valid context object.
        try {
            if (!$page->has_set_url()) {
                $page->set_url('/');
            }
        } catch (Throwable $e) {
            $page->set_url('/');
        }

        try {
            if (!$context = $page->context ?? null) {
                if (empty($context)) {
                    $page->set_context(context_system::instance());
                }
            }
        } catch (Throwable $e) {
            $page->set_context(context_system::instance());
        }
    }
}
