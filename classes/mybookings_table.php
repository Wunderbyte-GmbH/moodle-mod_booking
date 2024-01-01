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
 * My bookings table
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use moodle_url;
use table_sql;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/tablelib.php');

/**
 * Class to hahdle mybookings_table
 *
 * @package mod_booking
 * @copyright 2021 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mybookings_table extends table_sql {

    /**
     * mybookings_table constructor.
     *
     * @param string $uniqueid
     * @throws \coding_exception
     */
    public function __construct($uniqueid) {
        parent::__construct($uniqueid);

        // Define the list of columns to show.
        $columns = ['name', 'text', 'status', 'coursestarttime'];
        $this->define_columns($columns);

        // Define the titles of columns to show in header.
        $headers = [
            get_string('mybookingsbooking', 'booking'),
            get_string('mybookingsoption', 'booking'),
            get_string('status', 'booking'),
            get_string('coursestarttime', 'booking'),
        ];
        $this->define_headers($headers);
        $this->no_sorting('status');
    }

    /**
     * Column course start time
     *
     * @param mixed $values
     * @return string
     */
    protected function col_coursestarttime($values) {
        if ($values->coursestarttime == 0) {
            return '';
        }

        return userdate($values->coursestarttime);
    }

    /**
     * Column text
     *
     * @param mixed $values
     * @return string
     * @throws \moodle_exception
     */
    protected function col_text($values) {
        global $CFG;
        $optionurl = new moodle_url($CFG->wwwroot . '/mod/booking/view.php', [
            'id' => booking_option::get_cmid_from_optionid($values->optionid),
            'optionid' => $values->optionid,
            'whichview' => 'showonlyone',
        ]);
        return "<a href='{$optionurl}'>{$values->text}</a>";
    }

    /**
     * Column name
     *
     * @param mixed $values
     * @return string
     */
    protected function col_name($values) {
        $bookingurl = new moodle_url("/mod/booking/view.php?id={$values->cmid}");
        $courseurl = new moodle_url("/course/view.php?id={$values->courseid}");

        return "<a href='{$bookingurl}'>{$values->name}</a> (<a href='{$courseurl}'>{$values->fullname}</a>)";
    }

    /**
     * Column status
     *
     * @param mixed $values
     * @return string
     * @throws \coding_exception
     */
    protected function col_status($values) {
        return booking_getoptionstatus($values->coursestarttime, $values->courseendtime);
    }
}
