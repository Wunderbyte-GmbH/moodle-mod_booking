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
 * Booking history table.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer-Sengseis
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\table;

defined('MOODLE_INTERNAL') || die();

use local_wunderbyte_table\wunderbyte_table;
use stdClass;

global $CFG;

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Booking history table.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author      Bernhard Fischer-Sengseis
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_history_table extends wunderbyte_table {
    /**
     * Column for timecreated value.
     * @param stdClass $values
     * @return string
     */
    public function col_timecreated(stdClass $values) {
        return userdate($values->timecreated);
    }

    /**
     * Column for details of operation.
     * @param stdClass $values
     * @return string
     */
    public function col_json(stdClass $values) {
        if (empty($values->json)) {
            return "";
        } else {
            return "some values to be treated";
        };
    }
}
