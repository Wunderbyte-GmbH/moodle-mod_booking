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
 * Sending of reminder mails.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\task;

use context_system;
use mod_booking\utils\wb_payment;
use mod_booking\booking_option;
use mod_booking\event\reminder1_sent;
use mod_booking\event\reminder2_sent;
use mod_booking\event\reminder_teacher_sent;
use mod_booking\singleton_service;
use stdClass;
use Throwable;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Class to handle sending of reminder mails.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_reminder_mails extends \core\task\scheduled_task {
    /**
     * Get name
     *
     * @return string
     *
     */
    public function get_name() {
        return get_string('tasksendremindermails', 'mod_booking');
    }

    /**
     * Execute task
     *
     * @return void
     *
     */
    public function execute() {
        global $DB;
        $now = time();

        mtrace("run send_reminder_mails task");

        if (empty(get_config('booking', 'uselegacymailtemplates'))) {
            mtrace("Legacy mails are turned off, this task should be deactivated.");
            return;
        }

        $toprocess = $DB->get_records_sql(
            'SELECT bo.id optionid, bo.bookingid, bo.coursestarttime, b.daystonotify, b.daystonotify2, bo.sent, bo.sent2
            FROM {booking_options} bo
            LEFT JOIN {booking} b ON b.id = bo.bookingid
            WHERE (b.daystonotify > 0 OR b.daystonotify2 > 0)
            AND bo.coursestarttime > 0  AND bo.coursestarttime > :now
            AND (bo.sent = 0 OR bo.sent2 = 0)',
            ['now' => $now]
        );

        foreach ($toprocess as $record) {
            mtrace(json_encode($record));

            // Check if first notification is sent already.
            if ($record->sent == 0) {
                if ($this->send_notification(MOD_BOOKING_MSGPARAM_REMINDER_PARTICIPANT, $record, $record->daystonotify)) {
                    $save = new stdClass();
                    $save->id = $record->optionid;
                    $save->sent = 1;
                    $DB->update_record("booking_options", $save);

                    // Use an event to log that reminder1 has been sent.
                    $event = reminder1_sent::create([
                        'context' => context_system::instance(),
                        'objectid' => $record->optionid,
                    ]);
                    $event->trigger();
                }
            }

            // Check if second notification is sent already.
            if ($record->sent2 == 0) {
                if ($this->send_notification(MOD_BOOKING_MSGPARAM_REMINDER_PARTICIPANT, $record, $record->daystonotify2)) {
                    $save = new stdClass();
                    $save->id = $record->optionid;
                    $save->sent2 = 1;
                    $DB->update_record("booking_options", $save);

                    // Use an event to log that reminder2 has been sent.
                    $event = reminder2_sent::create([
                        'context' => context_system::instance(),
                        'objectid' => $record->optionid,
                    ]);
                    $event->trigger();
                }
            }
        }

        // Now let's check if reminders for sessions (optiondates) need to be sent.
        $this->send_session_notifications();

        // Check if PRO version is activated.
        if (wb_payment::pro_version_is_activated()) {
            // Teacher notifications (PRO feature).
            $this->send_teacher_notifications();
        }
    }

    /**
     * Send session notifications to participants.
     */
    private function send_session_notifications() {
        global $DB;

        $now = time();
        $sessionstoprocess = $DB->get_records_sql(
            "SELECT bod.id optiondateid, bod.bookingid, bod.optionid, bod.coursestarttime, bod.daystonotify, bod.sent
            FROM {booking_optiondates} bod
            WHERE bod.daystonotify > 0
            AND sent = 0
            AND bod.coursestarttime > 0  AND bod.coursestarttime > :now",
            ['now' => $now]
        );

        foreach ($sessionstoprocess as $sessionrecord) {
            mtrace(json_encode($sessionrecord));

            // Check if session notification has been sent already.
            if ($sessionrecord->sent == 0) {
                if ($this->send_notification(MOD_BOOKING_MSGPARAM_SESSIONREMINDER, $sessionrecord, $sessionrecord->daystonotify)) {
                    $save = new stdClass();
                    $save->id = $sessionrecord->optiondateid;
                    $save->sent = 1;
                    $DB->update_record("booking_optiondates", $save);
                }
            }
        }
    }

    /**
     * Will send notifications messages to teachers.
     */
    private function send_teacher_notifications() {
        global $DB;

        $now = time();
        $toprocess = $DB->get_records_sql(
            "SELECT bo.id optionid, bo.bookingid, bo.coursestarttime, b.daystonotifyteachers, bo.sentteachers
            FROM {booking_options} bo
            LEFT JOIN {booking} b ON b.id = bo.bookingid
            WHERE b.daystonotifyteachers > 0
            AND bo.coursestarttime > 0  AND bo.coursestarttime > :now
            AND bo.sentteachers = 0",
            ['now' => $now]
        );

        if (count($toprocess) > 0) {
            mtrace("send_reminder_mails task: send teacher notifications - START");
            foreach ($toprocess as $record) {
                mtrace(json_encode($record));

                // Check if teacher notification has been sent already.
                if ($record->sentteachers == 0) {
                    if ($this->send_notification(MOD_BOOKING_MSGPARAM_REMINDER_TEACHER, $record, $record->daystonotifyteachers)) {
                        $save = new stdClass();
                        $save->id = $record->optionid;
                        $save->sentteachers = 1;
                        $DB->update_record("booking_options", $save);

                        // Use an event to log that teacher reminder has been sent.
                        $event = reminder_teacher_sent::create([
                            'context' => context_system::instance(),
                            'objectid' => $record->optionid,
                            'other' => [
                                'msgparam' => MOD_BOOKING_MSGPARAM_REMINDER_TEACHER,
                                'record' => $record,
                                'daystonotifyteachers' => $record->daystonotifyteachers,
                            ],
                        ]);
                        $event->trigger();
                    }
                }
            }
            mtrace("send_reminder_mails task: send teacher notifications - DONE");
        }
    }

    /**
     * Function to send notification mail to all users if the time is right.
     * Returns true if sent and false if not.
     * @param int $messageparam the message type
     * @param stdClass $record the DB record
     * @param int $daystonotify number of days before course start time - when to notify
     * @return bool
     */
    private function send_notification(int $messageparam, stdClass $record, int $daystonotify) {
        global $DB;
        $now = time();
        $timetosend = strtotime('-' . $daystonotify . ' days', $record->coursestarttime);
        if ($timetosend < $now) {
            $optionid = $record->optionid;
            $bookingid = $record->bookingid;
            $cm = get_coursemodule_from_instance('booking', $bookingid);
            $cmid = $cm->id;
            try {
                $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
            } catch (Throwable $e) {
                // If the bookingoption doesn't exist anymore, we just abort the task.
                return true;
            }

            switch ($messageparam) {
                case MOD_BOOKING_MSGPARAM_SESSIONREMINDER:
                    $optiondateid = $record->optiondateid;
                    $bookingoption->sendmessage_notification($messageparam, [], $optiondateid);
                    break;

                case MOD_BOOKING_MSGPARAM_REMINDER_TEACHER:
                    // Get an array of teacher ids for the booking option.
                    $teachers = $DB->get_records('booking_teachers', ['optionid' => $optionid]);
                    $teacherids = [];
                    foreach ($teachers as $teacher) {
                        $teacherids[] = $teacher->userid;
                    }
                    // Bugfix: Only do this if we have teacherids.
                    // Otherwise, participants will get the message.
                    if (!empty($teacherids)) {
                        $bookingoption->sendmessage_notification($messageparam, $teacherids);
                    }
                    break;

                case MOD_BOOKING_MSGPARAM_REMINDER_PARTICIPANT:
                default:
                    $bookingoption->sendmessage_notification($messageparam, []);
                    break;
            }
            mtrace('booking - send notification triggered');

            return true;
        } else {
            return false;
        }
    }
}
