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
namespace mod_booking\task;

defined('MOODLE_INTERNAL') || die();

class remove_activity_completion extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('modulename', 'mod_booking');
    }

    public function execute() {
        global $DB, $CFG;
        $now = time();
        $params = array('now' => $now);

        $result = $DB->get_records_sql(
                'SELECT ba.id, ba.bookingid, ba.optionid, ba.userid, b.course
            FROM {booking_answers} ba
            LEFT JOIN {booking_options} bo
            ON bo.id = ba.optionid
            LEFT JOIN {booking} b
            ON b.id = bo.bookingid
            WHERE bo.removeafterminutes > 0
            AND ba.completed = 1
                AND ba.timemodified < (:now - bo.removeafterminutes * 60);', $params);

        require_once($CFG->libdir . '/completionlib.php');

        foreach ($result as $value) {
            $course = $DB->get_record('course', array('id' => $value->course));
            $completion = new \completion_info($course);
            $cm = get_coursemodule_from_instance('booking', $value->bookingid);

            $userdata = $DB->get_record('booking_answers', array('id' => $value->id));
            $booking = $DB->get_record('booking', array('id' => $value->bookingid));

            $userdata->completed = '0';
            $userdata->timemodified = time();

            $DB->update_record('booking_answers', $userdata);

            $countcompleted = $DB->count_records('booking_answers',
                array('bookingid' => $value->bookingid, 'userid' => $userdata->userid, 'completed' => '1'));

            if ($completion->is_enabled($cm) && $booking->enablecompletion > $countcompleted) {
                $completion->update_state($cm, COMPLETION_INCOMPLETE, $userdata->userid);
            }
        }
    }
}
