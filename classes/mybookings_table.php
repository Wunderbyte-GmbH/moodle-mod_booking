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

use moodle_url;
use table_sql;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/tablelib.php');

class mybookings_table extends table_sql {

    /**
     * mybookings_table constructor.
     *
     * @param $uniqueid
     * @param $cmid
     * @throws \coding_exception
     */
    public function __construct($uniqueid) {
        parent::__construct($uniqueid);

        // Define the list of columns to show.
        $columns = array('name', 'text', 'status', 'coursestarttime');
        $this->define_columns($columns);

        // Define the titles of columns to show in header.
        $headers = array(get_string('mybookingsbooking', 'booking'), get_string('mybookingsoption', 'booking'),
            get_string('status', 'booking'), get_string('coursestarttime', 'booking'));
        $this->define_headers($headers);
        $this->no_sorting('status');
    }

    protected function col_coursestarttime($values) {
        if ($values->coursestarttime == 0) {
            return '';
        }

        return userdate($values->coursestarttime);
    }

    protected function col_text($values) {
        $optionurl = new moodle_url("/mod/booking/view.php?id={$values->cmid}" .
            "&optionid={$values->optionid}&action=showonlyone&whichview=showonlyone#goenrol");

        return "<a href='{$optionurl}'>{$values->text}</a>";
    }

    protected function col_name($values) {
        $bookingurl = new moodle_url("/mod/booking/view.php?id={$values->cmid}");
        $courseurl = new moodle_url("/course/view.php?id={$values->courseid}");

        return "<a href='{$bookingurl}'>{$values->name}</a> (<a href='{$courseurl}'>{$values->fullname}</a>)";
    }

    protected function col_status($values) {
        return booking_getoptionstatus($values->coursestarttime, $values->courseendtime);
    }
}
