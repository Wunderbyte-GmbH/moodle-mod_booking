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

use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

class send_reminder_mails extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('modulename', 'mod_booking');
    }

    public function execute() {
        global $DB;
        $now = time();

        echo "run send_reminder_mails task" . "\n";

        $toprocess = $DB->get_records_sql(
           'SELECT bo.id, bo.coursestarttime, b.daystonotify, b.daystonotify2, bo.sent, bo.sent2
            FROM {booking_options} bo
            LEFT JOIN {booking} b ON b.id = bo.bookingid
            WHERE (b.daystonotify > 0 OR b.daystonotify2 > 0)
            AND bo.coursestarttime > 0  AND bo.coursestarttime > :now
            AND (bo.sent = 0 OR bo.sent2 = 0)', array('now' => $now));

        foreach ($toprocess as $record) {

            echo json_encode($record) . "\n";

            // Check if first notification is sent already.
            if ($record->sent == 0) {

                if ($this->send_notification($record, $record->daystonotify)) {
                    $save = new stdClass();
                    $save->id = $record->id;
                    $save->sent = 1;
                    $DB->update_record("booking_options", $save);
                }
            }

            // Check if second notification is sent already.
            if ($record->sent2 == 0) {
                if ($this->send_notification($record, $record->daystonotify2)) {
                    $save = new stdClass();
                    $save->id = $record->id;
                    $save->sent2 = 1;
                    $DB->update_record("booking_options", $save);
                }
            }
        }

        // Now let's check if reminders for sessions need to be sent.
        $now = time();
        $sessionstoprocess = $DB->get_records_sql(
           'SELECT bod.id, bod.coursestarttime, bod.daystonotify, bod.sent
            FROM {booking_optiondates} bod
            WHERE bod.daystonotify > 0
            AND sent = 0
            AND bod.coursestarttime > 0  AND bod.coursestarttime > :now', array('now' => $now));

        foreach ($sessionstoprocess as $sessionrecord) {

            echo json_encode($sessionrecord) . "\n";

            // Check if session notification has been sent already.
            if ($sessionrecord->sent == 0) {

                if ($this->send_notification($sessionrecord, $sessionrecord->daystonotify, true)) {
                    $save = new stdClass();
                    $save->id = $sessionrecord->id;
                    $save->sent = 1;
                    $DB->update_record("booking_optiondates", $save);
                }
            }
        }
    }

    /**
     * Function to send notification mail to all users if the time is right.
     * Returns true if sent and false if not.
     * @param $record
     * @param $daystonotify
     * @param bool $issession false for option (default), true for sessions (optiondates)
     * @return bool
     */
    private function send_notification($record, $daystonotify, $issession = false) {
        $now = time();
        $timetosend = strtotime('-' . $daystonotify . ' day', $record->coursestarttime);
        if ($timetosend < $now) {
            booking_send_notification($record->id, get_string('notificationsubject', 'booking'), array(), $issession);
            mtrace('booking - send notification triggered');
            return true;
        } else {
            return false;
        }
    }
}
