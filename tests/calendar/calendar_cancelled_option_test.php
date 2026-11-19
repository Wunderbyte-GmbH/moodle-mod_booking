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
 * Calendar events of a cancelled booking option.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\local\calendar\calendar_helper;
use mod_booking\option\fields\addtocalendar;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/calendar/lib.php');

/**
 * Cancelling a booking option removes its calendar events (site, course and user events),
 * a cancelled option never gets new ones, and undoing the cancellation brings them back.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_cancelled_option_test extends booking_advanced_testcase {
    /**
     * Start of the first session (all sessions are in the year 2050, one per day).
     */
    private const FIRSTSTART = '20 June 2050 08:00:00';

    /**
     * Number of sessions of the options created by this test.
     */
    private const NDATES = 3;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        // The capability is installed by the plugin upgrade (version bump). A PHPUnit dataroot that was
        // initialised before it existed would not know it - run the same code the upgrade runs.
        if (get_capability_info(addtocalendar::CAP_CREATE_SITE_EVENTS) === null) {
            update_capabilities('mod_booking');
        }
    }

    /**
     * The two kinds of instance-wide events.
     *
     * @return array
     */
    public static function instance_eventtype_provider(): array {
        return [
            'site events' => [calendar::ADDTOCALENDAR_SITE, 'site'],
            'course events' => [calendar::ADDTOCALENDAR_COURSE, 'course'],
        ];
    }

    /**
     * Cancelling the option removes the instance-wide events from the calendar, undoing the
     * cancellation creates them again.
     *
     * @covers \mod_booking\booking_option::cancelbookingoption
     * @covers \mod_booking\observer::bookingoption_cancelled
     * @covers \mod_booking\local\calendar\calendar_helper::option_delete_all_calendar_events
     * @covers \mod_booking\local\calendar\calendar_helper::option_create_instance_and_teacher_calendar_events
     *
     * @dataProvider instance_eventtype_provider
     *
     * @param int $addtocalendar
     * @param string $eventtype
     */
    public function test_cancelling_removes_instance_events_and_undo_restores_them(int $addtocalendar, string $eventtype): void {
        global $DB;

        [$course, $booking] = $this->create_booking_instance();
        $student = $this->getDataGenerator()->create_user(['username' => 'student1']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $this->setAdminUser();
        $option = $this->create_option($booking, $addtocalendar, 'cancelled option');
        // A second option that is NOT cancelled must keep its events.
        $otheroption = $this->create_option($booking, $addtocalendar, 'other option');

        $this->assertCount(self::NDATES, $this->get_instance_events($option->id, $eventtype));
        [$tstart, $tend] = $this->get_time_range();
        $this->assertCount(2 * self::NDATES, $this->get_booking_events_seen_by($student, $tstart, $tend));

        // Cancel the option.
        $this->setAdminUser();
        booking_option::cancelbookingoption($option->id, 'no trainer');

        $this->assertCount(0, $this->get_instance_events($option->id), 'Events of a cancelled option must be deleted');
        foreach ($DB->get_records('booking_optiondates', ['optionid' => $option->id]) as $optiondate) {
            $this->assertEmpty($optiondate->eventid, 'The reference to the deleted event must be cleared');
        }
        // The other option is untouched, and this is all the student still sees.
        $otherevents = $this->get_instance_events($otheroption->id, $eventtype);
        $this->assertCount(self::NDATES, $otherevents);
        $seen = $this->get_booking_events_seen_by($student, $tstart, $tend);
        $this->assertEqualsCanonicalizing(array_keys($otherevents), array_keys($seen));

        // Undo the cancellation: the events are back and referenced by the dates.
        $this->setAdminUser();
        booking_option::cancelbookingoption($option->id, '', true);

        $events = $this->get_instance_events($option->id, $eventtype);
        $this->assertCount(self::NDATES, $events);
        $optiondates = $DB->get_records('booking_optiondates', ['optionid' => $option->id]);
        $this->assertEqualsCanonicalizing(array_keys($events), array_map('intval', array_column($optiondates, 'eventid')));
        $this->assertCount(2 * self::NDATES, $this->get_booking_events_seen_by($student, $tstart, $tend));
    }

    /**
     * A cancelled option does not get its events back when it is saved again
     * (bookingoption_updated observer, addtocalendar::save_data) or when a date is added.
     *
     * @covers \mod_booking\calendar::booking_optiondate_add_to_cal
     * @covers \mod_booking\observer::bookingoption_cancelled
     */
    public function test_saving_a_cancelled_option_creates_no_events(): void {
        [, $booking] = $this->create_booking_instance();
        $teacher = $this->getDataGenerator()->create_user(['username' => 'teacher1']);

        $this->setAdminUser();
        $option = $this->create_option($booking, calendar::ADDTOCALENDAR_SITE, 'cancelled option', 'teacher1');
        $this->assertCount(self::NDATES, $this->get_instance_events($option->id));

        booking_option::cancelbookingoption($option->id, 'no trainer');
        $this->assertCount(0, $this->get_instance_events($option->id));

        // Save without changes.
        $this->update_option($booking, $option->id, []);
        $this->assertCount(0, $this->get_instance_events($option->id), 'Saving must not re-create the events');
        $this->assertCount(0, $this->get_user_events($option->id, (int)$teacher->id));

        // Save with a new date.
        $newindex = self::NDATES;
        $this->update_option($booking, $option->id, [
            'optiondateid_' . $newindex => 0,
            'daystonotify_' . $newindex => 0,
            'coursestarttime_' . $newindex => strtotime('+' . $newindex . ' days', strtotime(self::FIRSTSTART)),
            'courseendtime_' . $newindex => strtotime('+' . $newindex . ' days 1 hour', strtotime(self::FIRSTSTART)),
        ]);
        $this->assertCount(0, $this->get_instance_events($option->id), 'A new date must not create an event');
        $this->assertCount(0, $this->get_user_events($option->id, (int)$teacher->id));

        // After the undo all dates have an event, also the one that was added while the option was cancelled.
        booking_option::cancelbookingoption($option->id, '', true);
        $this->assertCount(self::NDATES + 1, $this->get_instance_events($option->id, 'site'));
        $this->assertCount(self::NDATES + 1, $this->get_user_events($option->id, (int)$teacher->id));
    }

    /**
     * The personal calendar events of the booked users and of the teachers are removed as well.
     * Undoing the cancellation restores the events of the teachers (the bookings are gone for good).
     *
     * @covers \mod_booking\observer::bookingoption_cancelled
     * @covers \mod_booking\local\calendar\calendar_helper::option_delete_all_calendar_events
     * @covers \mod_booking\local\calendar\calendar_helper::option_create_instance_and_teacher_calendar_events
     */
    public function test_cancelling_removes_user_events_of_booked_users_and_teachers(): void {
        global $DB;

        [$course, $booking] = $this->create_booking_instance();
        $student = $this->getDataGenerator()->create_user(['username' => 'student1']);
        $teacher = $this->getDataGenerator()->create_user(['username' => 'teacher1']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->setAdminUser();
        // No instance-wide events here: the user events must go away on their own.
        $option = $this->create_option($booking, calendar::ADDTOCALENDAR_NONE, 'cancelled option', 'teacher1');
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $student->id]);

        $this->assertCount(self::NDATES, $this->get_user_events($option->id, (int)$student->id));
        $this->assertCount(self::NDATES, $this->get_user_events($option->id, (int)$teacher->id));

        booking_option::cancelbookingoption($option->id, 'no trainer');

        $this->assertCount(0, $this->get_user_events($option->id, (int)$student->id));
        $this->assertCount(0, $this->get_user_events($option->id, (int)$teacher->id));
        $this->assertSame(0, $DB->count_records('booking_userevents', ['optionid' => $option->id]));
        $this->assertSame(
            0,
            $DB->count_records_select('event', "component = 'mod_booking' AND name = :name", ['name' => 'cancelled option']),
            'No orphaned calendar events may be left'
        );

        booking_option::cancelbookingoption($option->id, '', true);

        $this->assertCount(self::NDATES, $this->get_user_events($option->id, (int)$teacher->id));
        $this->assertCount(0, $this->get_user_events($option->id, (int)$student->id));
        $this->assertCount(0, $this->get_instance_events($option->id), 'addtocalendar is off, so no instance-wide events');
    }

    /**
     * The helper that (re)creates the events does nothing for a cancelled option.
     *
     * @covers \mod_booking\local\calendar\calendar_helper::option_create_instance_and_teacher_calendar_events
     */
    public function test_create_helper_ignores_cancelled_options(): void {
        [, $booking] = $this->create_booking_instance();
        $this->setAdminUser();
        $option = $this->create_option($booking, calendar::ADDTOCALENDAR_COURSE, 'cancelled option');

        booking_option::cancelbookingoption($option->id, 'no trainer');
        calendar_helper::option_create_instance_and_teacher_calendar_events($option->id);

        $this->assertCount(0, $this->get_instance_events($option->id));
    }

    /**
     * Creates a course with a booking instance.
     *
     * @return array [$course, $booking]
     */
    private function create_booking_instance(): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bdata = [
            'name' => 'Test Booking cancelled events',
            'eventtype' => 'Test event',
            'enablecompletion' => 1,
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'tags' => '',
            'completion' => 2,
            'showviews' => ['mybooking,myoptions,optionsiamresponsiblefor,showall,showactive,myinstitution'],
            'course' => $course->id,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);
        return [$course, $booking];
    }

    /**
     * Creates a booking option with NDATES sessions and the given addtocalendar value.
     *
     * @param stdClass $booking
     * @param int $addtocalendar
     * @param string $text
     * @param string $teachers comma separated usernames of the teachers
     * @return stdClass the created option record (id set)
     */
    private function create_option(stdClass $booking, int $addtocalendar, string $text, string $teachers = ''): stdClass {
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = $text;
        $record->chooseorcreatecourse = 1;
        $record->courseid = 0;
        $record->description = 'description';
        $record->addtocalendar = $addtocalendar;
        if ($teachers !== '') {
            $record->teachersforoption = $teachers;
        }
        for ($i = 0; $i < self::NDATES; $i++) {
            $record->{'optiondateid_' . $i} = 0;
            $record->{'daystonotify_' . $i} = 0;
            $record->{'coursestarttime_' . $i} = strtotime('+' . $i . ' days', strtotime(self::FIRSTSTART));
            $record->{'courseendtime_' . $i} = strtotime('+' . $i . ' days 1 hour', strtotime(self::FIRSTSTART));
        }
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);
        singleton_service::destroy_instance();
        return $option;
    }

    /**
     * Updates an existing option via booking_option::update(), re-submitting its current dates
     * with their real ids (so they are kept) and its teachers, plus the given overrides.
     *
     * @param stdClass $booking
     * @param int $optionid
     * @param array $overrides
     * @return void
     */
    private function update_option(stdClass $booking, int $optionid, array $overrides): void {
        global $DB;

        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $record = new stdClass();
        $record->id = $optionid;
        $record->cmid = $booking->cmid;
        $record->bookingid = $booking->id;
        $record->text = $settings->text;
        $record->addtocalendar = (int)$settings->addtocalendar;
        $record->invisible = (int)$settings->invisible;
        // Like the form, we submit the teachers again, otherwise they would be removed.
        $record->teachersforoption = array_values($settings->teacherids);
        $i = 0;
        foreach ($DB->get_records('booking_optiondates', ['optionid' => $optionid], 'coursestarttime ASC') as $optiondate) {
            $record->{'optiondateid_' . $i} = (int)$optiondate->id;
            $record->{'daystonotify_' . $i} = (int)$optiondate->daystonotify;
            $record->{'coursestarttime_' . $i} = (int)$optiondate->coursestarttime;
            $record->{'courseendtime_' . $i} = (int)$optiondate->courseendtime;
            $i++;
        }
        foreach ($overrides as $key => $value) {
            $record->{$key} = $value;
        }
        booking_option::update($record);
        singleton_service::destroy_instance();
    }

    /**
     * Instance-wide (course/site) events of an option, keyed by event id.
     *
     * @param int $optionid
     * @param string|null $eventtype restrict to 'course' or 'site'
     * @return array
     */
    private function get_instance_events(int $optionid, ?string $eventtype = null): array {
        global $DB;
        $sql = "SELECT *
                  FROM {event}
                 WHERE component = 'mod_booking'
                   AND eventtype <> 'user'
                   AND uuid LIKE " . $DB->sql_concat(':optionid', "'-%'");
        $params = ['optionid' => $optionid];
        if ($eventtype !== null) {
            $sql .= " AND eventtype = :eventtype";
            $params['eventtype'] = $eventtype;
        }
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Personal calendar events of one user for an option (tracked in booking_userevents), keyed by event id.
     *
     * @param int $optionid
     * @param int $userid
     * @return array
     */
    private function get_user_events(int $optionid, int $userid): array {
        global $DB;
        $sql = "SELECT e.*
                  FROM {event} e
                  JOIN {booking_userevents} bue ON bue.eventid = e.id
                 WHERE bue.optionid = :optionid
                   AND bue.userid = :userid
                   AND e.eventtype = 'user'
                   AND e.userid = bue.userid";
        return $DB->get_records_sql($sql, ['optionid' => $optionid, 'userid' => $userid]);
    }

    /**
     * The mod_booking events a user sees in their calendar in the given time range - the same way
     * the calendar view collects them (default courses incl. the site + calendar filters).
     *
     * @param stdClass $user
     * @param int $tstart
     * @param int $tend
     * @return array
     */
    private function get_booking_events_seen_by(stdClass $user, int $tstart, int $tend): array {
        $this->setUser($user);
        $courses = calendar_get_default_courses(null, '*', false, $user->id);
        [$courseids, $groupids, $userid] = calendar_set_filters($courses, false, $user);
        $events = calendar_get_legacy_events($tstart, $tend, $userid, $groupids, $courseids);
        return array_filter($events, function ($event) {
            return $event->component === 'mod_booking';
        });
    }

    /**
     * Time range covering all sessions created by this test.
     *
     * @return array [$tstart, $tend]
     */
    private function get_time_range(): array {
        $start = strtotime(self::FIRSTSTART);
        return [$start - DAYSECS, $start + 30 * DAYSECS];
    }
}
