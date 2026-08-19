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
 * Tests for site calendar events (addtocalendar = 2) of booking options.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use mod_booking\option\fields\addtocalendar;
use mod_booking\option\optiondate;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use required_capability_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/calendar/lib.php');

/**
 * Site calendar events: creation, visibility for unenrolled users, type switching,
 * capability enforcement and the default setting.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class calendar_siteevent_test extends booking_advanced_testcase {
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
     * Site events are created with the right properties, referenced by the optiondates and
     * visible in the calendar of a user who is NOT enrolled in the course - course events are not.
     *
     * @covers \mod_booking\calendar::booking_optiondate_add_to_cal
     * @covers \mod_booking\option\fields\addtocalendar::save_data
     */
    public function test_site_events_are_visible_to_unenrolled_users(): void {
        global $DB;

        [$course, $booking] = $this->create_booking_instance();
        $student = $this->getDataGenerator()->create_user(['username' => 'student1']);
        $outsider = $this->getDataGenerator()->create_user(['username' => 'outsider1']);
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $this->setAdminUser();
        $siteoption = $this->create_option($booking, calendar::ADDTOCALENDAR_SITE, 'site option');
        $courseoption = $this->create_option($booking, calendar::ADDTOCALENDAR_COURSE, 'course option');

        // 1. Properties of the site events.
        $siteevents = $this->get_instance_events($siteoption->id);
        $this->assertCount(self::NDATES, $siteevents);
        $optiondates = $DB->get_records('booking_optiondates', ['optionid' => $siteoption->id], '', 'id, eventid');
        $referenced = array_map('intval', array_column($optiondates, 'eventid'));
        foreach ($siteevents as $event) {
            $this->assertSame('site', $event->eventtype);
            $this->assertEquals(SITEID, $event->courseid);
            // Site events must NOT carry a modulename, otherwise core filters them by enrolment.
            $this->assertSame('', (string)$event->modulename);
            $this->assertEquals(0, $event->instance);
            $this->assertSame('mod_booking', $event->component);
            $this->assertEquals(1, $event->visible);
            $this->assertContains((int)$event->id, $referenced, 'booking_optiondates.eventid must reference the site event');
        }
        $this->assertCount(0, $this->get_instance_events($siteoption->id, 'course'));

        // 2. Properties of the course events (control group).
        $courseevents = $this->get_instance_events($courseoption->id);
        $this->assertCount(self::NDATES, $courseevents);
        foreach ($courseevents as $event) {
            $this->assertSame('course', $event->eventtype);
            $this->assertEquals($course->id, $event->courseid);
            $this->assertSame('booking', $event->modulename);
            $this->assertEquals($booking->id, $event->instance);
        }

        // 3. What the users actually see in their calendar.
        [$tstart, $tend] = $this->get_time_range();
        // The unenrolled user sees the site events but not the course events.
        $seen = $this->get_booking_events_seen_by($outsider, $tstart, $tend);
        $this->assertCount(self::NDATES, $seen);
        foreach ($seen as $event) {
            $this->assertSame('site', $event->eventtype);
            $this->assertArrayHasKey($event->id, $siteevents);
        }
        // The enrolled student sees both.
        $seen = $this->get_booking_events_seen_by($student, $tstart, $tend);
        $this->assertCount(2 * self::NDATES, $seen);
    }

    /**
     * Switching between course and site events converts the existing events in place
     * (stable event ids, no orphans), switching to "do not add" deletes them.
     *
     * @covers \mod_booking\calendar::convert_instance_event
     * @covers \mod_booking\option\fields\addtocalendar::save_data
     * @covers \mod_booking\option\fields\addtocalendar::prepare_save_field
     */
    public function test_switch_between_course_and_site_events(): void {
        global $DB;

        [$course, $booking] = $this->create_booking_instance();
        $this->setAdminUser();
        $option = $this->create_option($booking, calendar::ADDTOCALENDAR_COURSE, 'switch option');
        $optionid = $option->id;

        $courseevents = $this->get_instance_events($optionid);
        $this->assertCount(self::NDATES, $courseevents);
        $eventids = array_keys($courseevents);
        sort($eventids);

        // Course -> site: same event ids, type converted.
        $this->update_option($booking, $optionid, ['addtocalendar' => calendar::ADDTOCALENDAR_SITE]);
        $events = $this->get_instance_events($optionid);
        $this->assertCount(self::NDATES, $events);
        $ids = array_keys($events);
        sort($ids);
        $this->assertSame($eventids, $ids, 'Events must be converted in place');
        foreach ($events as $event) {
            $this->assertSame('site', $event->eventtype);
            $this->assertEquals(SITEID, $event->courseid);
            $this->assertSame('', (string)$event->modulename);
            $this->assertEquals(0, $event->instance);
        }

        // Site -> course: converted back.
        $this->update_option($booking, $optionid, ['addtocalendar' => calendar::ADDTOCALENDAR_COURSE]);
        $events = $this->get_instance_events($optionid);
        $this->assertCount(self::NDATES, $events);
        $ids = array_keys($events);
        sort($ids);
        $this->assertSame($eventids, $ids, 'Events must be converted in place');
        foreach ($events as $event) {
            $this->assertSame('course', $event->eventtype);
            $this->assertEquals($course->id, $event->courseid);
            $this->assertSame('booking', $event->modulename);
            $this->assertEquals($booking->id, $event->instance);
        }

        // Course -> site while adding a new date in the same save: the new date's event is created by the
        // bookingoptiondate_created observer (possibly still with the old value), save_data() has to repair it.
        $newindex = self::NDATES;
        $this->update_option($booking, $optionid, [
            'addtocalendar' => calendar::ADDTOCALENDAR_SITE,
            'optiondateid_' . $newindex => 0,
            'daystonotify_' . $newindex => 0,
            'coursestarttime_' . $newindex => strtotime('+' . $newindex . ' days', strtotime(self::FIRSTSTART)),
            'courseendtime_' . $newindex => strtotime('+' . $newindex . ' days 1 hour', strtotime(self::FIRSTSTART)),
        ]);
        $events = $this->get_instance_events($optionid);
        $this->assertCount(self::NDATES + 1, $events);
        foreach ($events as $event) {
            $this->assertSame('site', $event->eventtype, 'All events incl. the new date must be site events');
            $this->assertEquals(SITEID, $event->courseid);
        }
        $this->assertCount(self::NDATES + 1, $DB->get_records('booking_optiondates', ['optionid' => $optionid]));

        // Site -> none: all instance-wide events are deleted, the references are cleared.
        $this->update_option($booking, $optionid, ['addtocalendar' => calendar::ADDTOCALENDAR_NONE]);
        $this->assertCount(0, $this->get_instance_events($optionid));
        foreach ($DB->get_records('booking_optiondates', ['optionid' => $optionid]) as $optiondate) {
            $this->assertEmpty($optiondate->eventid);
        }

        // None -> site: created again.
        $this->update_option($booking, $optionid, ['addtocalendar' => calendar::ADDTOCALENDAR_SITE]);
        $events = $this->get_instance_events($optionid);
        $this->assertCount(self::NDATES + 1, $events);
        foreach ($events as $event) {
            $this->assertSame('site', $event->eventtype);
        }
    }

    /**
     * Invisible options hide their site events, deleting a date or the option removes them.
     *
     * @covers \mod_booking\local\calendar\calendar_helper::option_set_visibility_for_all_calendar_events
     * @covers \mod_booking\option\optiondate::delete
     * @covers \mod_booking\booking_option::delete_booking_option
     */
    public function test_visibility_and_deletion_of_site_events(): void {
        global $DB;

        [$course, $booking] = $this->create_booking_instance();
        $this->setAdminUser();
        $option = $this->create_option($booking, calendar::ADDTOCALENDAR_SITE, 'visibility option');
        $optionid = $option->id;

        // Invisible option -> hidden site events.
        $this->update_option($booking, $optionid, ['invisible' => 1]);
        $events = $this->get_instance_events($optionid);
        $this->assertCount(self::NDATES, $events);
        foreach ($events as $event) {
            $this->assertSame('site', $event->eventtype);
            $this->assertEquals(0, $event->visible);
        }
        // Visible again.
        $this->update_option($booking, $optionid, ['invisible' => 0]);
        foreach ($this->get_instance_events($optionid) as $event) {
            $this->assertEquals(1, $event->visible);
        }

        // Deleting one date removes exactly its event.
        $optiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid]);
        $first = reset($optiondates);
        optiondate::delete((int)$first->id);
        $this->assertFalse($DB->record_exists('event', ['id' => $first->eventid]));
        $this->assertCount(self::NDATES - 1, $this->get_instance_events($optionid));

        // Deleting the date via the fallback path (eventid missing) also works for site events.
        $optiondates = $DB->get_records('booking_optiondates', ['optionid' => $optionid]);
        $second = reset($optiondates);
        $secondeventid = (int)$second->eventid;
        $DB->set_field('booking_optiondates', 'eventid', null, ['id' => $second->id]);
        optiondate::delete((int)$second->id);
        $this->assertFalse($DB->record_exists('event', ['id' => $secondeventid]));
        $this->assertCount(self::NDATES - 2, $this->get_instance_events($optionid));

        // Deleting the option removes the rest.
        $bookingoption = singleton_service::get_instance_of_booking_option($booking->cmid, $optionid);
        $bookingoption->delete_booking_option();
        $this->assertCount(0, $this->get_instance_events($optionid));
    }

    /**
     * A new date series (dates.php) deletes the old site events before the new ones are created.
     *
     * @covers \mod_booking\dates::set_data
     */
    public function test_new_date_series_replaces_site_events(): void {
        global $DB;

        [$course, $booking] = $this->create_booking_instance();
        $this->setAdminUser();
        $option = $this->create_option($booking, calendar::ADDTOCALENDAR_SITE, 'series option');
        $optionid = $option->id;
        $oldeventids = array_keys($this->get_instance_events($optionid));

        // Submit only new dates (optiondateid 0) -> old dates and their events are replaced.
        $record = new stdClass();
        $record->id = $optionid;
        $record->cmid = $booking->cmid;
        $record->bookingid = $booking->id;
        $record->text = 'series option';
        $record->addtocalendar = calendar::ADDTOCALENDAR_SITE;
        for ($i = 0; $i < 2; $i++) {
            $record->{'optiondateid_' . $i} = 0;
            $record->{'daystonotify_' . $i} = 0;
            $record->{'coursestarttime_' . $i} = strtotime('+' . ($i + 10) . ' days', strtotime(self::FIRSTSTART));
            $record->{'courseendtime_' . $i} = strtotime('+' . ($i + 10) . ' days 1 hour', strtotime(self::FIRSTSTART));
        }
        booking_option::update($record);

        $events = $this->get_instance_events($optionid);
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame('site', $event->eventtype);
            $this->assertNotContains((int)$event->id, array_map('intval', $oldeventids));
        }
        foreach ($oldeventids as $oldeventid) {
            $this->assertFalse($DB->record_exists('event', ['id' => $oldeventid]));
        }
    }

    /**
     * Setting "Add as site event" requires mod/booking:createcalendarsiteevents - on every save path
     * (booking_option::update() is the choke point for form, bulk form, web service and import).
     *
     * @covers \mod_booking\option\fields\addtocalendar::prepare_save_field
     */
    public function test_capability_is_required_to_set_site_events(): void {
        global $DB;

        [$course, $booking] = $this->create_booking_instance();
        $teacher = $this->getDataGenerator()->create_user(['username' => 'teacher1']);
        $manager = $this->getDataGenerator()->create_user(['username' => 'manager1']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($manager->id, $course->id, 'manager');

        $context = \context_module::instance($booking->cmid);
        $this->assertFalse(has_capability(addtocalendar::CAP_CREATE_SITE_EVENTS, $context, $teacher));
        $this->assertTrue(has_capability(addtocalendar::CAP_CREATE_SITE_EVENTS, $context, $manager));
        $this->assertNotNull(get_capability_info(addtocalendar::CAP_CREATE_SITE_EVENTS));

        // 1. Teacher without the capability cannot create a site event option.
        $this->setUser($teacher);
        try {
            $this->create_option($booking, calendar::ADDTOCALENDAR_SITE, 'forbidden option');
            $this->fail('required_capability_exception expected');
        } catch (required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
            $this->assertSame(get_capability_string(addtocalendar::CAP_CREATE_SITE_EVENTS), $e->a);
        }
        // Nothing has been persisted.
        $this->assertFalse($DB->record_exists('booking_options', ['text' => 'forbidden option']));

        // 2. Teacher can still create course event options.
        $courseoption = $this->create_option($booking, calendar::ADDTOCALENDAR_COURSE, 'teacher course option');
        $this->assertCount(self::NDATES, $this->get_instance_events($courseoption->id, 'course'));

        // 3. ...but cannot upgrade them to site events.
        try {
            $this->update_option($booking, $courseoption->id, ['addtocalendar' => calendar::ADDTOCALENDAR_SITE]);
            $this->fail('required_capability_exception expected');
        } catch (required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        singleton_service::destroy_instance();
        $this->assertCount(self::NDATES, $this->get_instance_events($courseoption->id, 'course'));
        $this->assertCount(0, $this->get_instance_events($courseoption->id, 'site'));

        // 4. Manager (capability via archetype) can create site event options.
        $this->setUser($manager);
        $siteoption = $this->create_option($booking, calendar::ADDTOCALENDAR_SITE, 'manager site option');
        $this->assertCount(self::NDATES, $this->get_instance_events($siteoption->id, 'site'));

        // 5. Teacher may save an option that already IS a site event (e.g. frozen select re-submits 2).
        $this->setUser($teacher);
        $this->update_option($booking, $siteoption->id, [
            'addtocalendar' => calendar::ADDTOCALENDAR_SITE,
            'text' => 'manager site option edited by teacher',
        ]);
        $this->assertCount(self::NDATES, $this->get_instance_events($siteoption->id, 'site'));
        $this->assertSame(
            'manager site option edited by teacher',
            singleton_service::get_instance_of_booking_option_settings($siteoption->id)->text
        );

        // 6. Explicitly granting the capability to the teacher role makes it work for teachers too.
        $this->setAdminUser();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(addtocalendar::CAP_CREATE_SITE_EVENTS, CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $teacher->id, $context->id);
        $this->setUser($teacher);
        $this->update_option($booking, $courseoption->id, ['addtocalendar' => calendar::ADDTOCALENDAR_SITE]);
        $this->assertCount(self::NDATES, $this->get_instance_events($courseoption->id, 'site'));
    }

    /**
     * The option form only offers "Add as site event" with the capability, freezes an existing site
     * event for users without it and preselects the value of the setting booking/addtocalendardefault.
     *
     * @covers \mod_booking\option\fields\addtocalendar::instance_form_definition
     * @covers \mod_booking\option\fields\addtocalendar::get_default_for_new_option
     */
    public function test_form_offers_site_events_only_with_capability_and_applies_default(): void {
        [$course, $booking] = $this->create_booking_instance();
        $teacher = $this->getDataGenerator()->create_user(['username' => 'teacher1']);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->setAdminUser();
        $siteoption = $this->create_option($booking, calendar::ADDTOCALENDAR_SITE, 'site option');
        $courseoption = $this->create_option($booking, calendar::ADDTOCALENDAR_COURSE, 'course option');

        // Admin user, new option, the default setting is "do not add" (value 0).
        $element = $this->render_addtocalendar_element($booking->cmid, 0);
        $this->assertSame([0, 1, 2], $this->get_offered_values($element));
        $this->assertSame(0, $this->get_selected_value($element));
        $this->assertFalse($element->isFrozen());

        // Admin, default setting "site event".
        set_config('addtocalendardefault', calendar::ADDTOCALENDAR_SITE, 'booking');
        $element = $this->render_addtocalendar_element($booking->cmid, 0);
        $this->assertSame(2, $this->get_selected_value($element));

        // Teacher without capability, default "site event" -> offered 0/1 only, preselected course event.
        $this->setUser($teacher);
        $element = $this->render_addtocalendar_element($booking->cmid, 0);
        $this->assertSame([0, 1], $this->get_offered_values($element));
        $this->assertSame(1, $this->get_selected_value($element));
        $this->assertFalse($element->isFrozen());

        // Teacher without capability, the default setting is "course event".
        set_config('addtocalendardefault', calendar::ADDTOCALENDAR_COURSE, 'booking');
        $element = $this->render_addtocalendar_element($booking->cmid, 0);
        $this->assertSame(1, $this->get_selected_value($element));

        // Teacher without capability, the default setting is "do not add".
        set_config('addtocalendardefault', calendar::ADDTOCALENDAR_NONE, 'booking');
        $element = $this->render_addtocalendar_element($booking->cmid, 0);
        $this->assertSame(0, $this->get_selected_value($element));

        // Teacher, existing course option -> no site option offered, not frozen.
        $element = $this->render_addtocalendar_element($booking->cmid, $courseoption->id);
        $this->assertSame([0, 1], $this->get_offered_values($element));
        $this->assertFalse($element->isFrozen());

        // Teacher, existing SITE option -> value offered (so it is shown) but frozen.
        $element = $this->render_addtocalendar_element($booking->cmid, $siteoption->id);
        $this->assertSame([0, 1, 2], $this->get_offered_values($element));
        $this->assertTrue($element->isFrozen());

        // Admin, existing site option -> not frozen.
        $this->setAdminUser();
        $element = $this->render_addtocalendar_element($booking->cmid, $siteoption->id);
        $this->assertSame([0, 1, 2], $this->get_offered_values($element));
        $this->assertFalse($element->isFrozen());

        // Locked setting freezes for everyone.
        set_config('addtocalendar_locked', 1, 'booking');
        $element = $this->render_addtocalendar_element($booking->cmid, 0);
        $this->assertTrue($element->isFrozen());

        // The default is NOT applied to existing options (set_data provides their stored value).
        set_config('addtocalendar_locked', 0, 'booking');
        set_config('addtocalendardefault', calendar::ADDTOCALENDAR_SITE, 'booking');
        $data = new stdClass();
        $data->id = $courseoption->id;
        addtocalendar::set_data($data, singleton_service::get_instance_of_booking_option_settings($courseoption->id));
        $this->assertEquals(calendar::ADDTOCALENDAR_COURSE, $data->addtocalendar);
    }

    /**
     * Creates a course and a booking instance.
     *
     * @return array [$course, $booking]
     */
    private function create_booking_instance(): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $bdata = [
            'name' => 'Test Booking site events',
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
     * @return stdClass the created option record (id set)
     */
    private function create_option(stdClass $booking, int $addtocalendar, string $text): stdClass {
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = $text;
        $record->chooseorcreatecourse = 1;
        $record->courseid = 0;
        $record->description = 'description';
        $record->addtocalendar = $addtocalendar;
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
     * with their real ids (so they are kept) plus the given overrides.
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

    /**
     * Renders the addtocalendar field into a fresh form and returns its select element.
     *
     * @param int $cmid
     * @param int $optionid 0 for a new option
     * @return \HTML_QuickForm_element
     */
    private function render_addtocalendar_element(int $cmid, int $optionid) {
        $mform = new \MoodleQuickForm('addtocalendartestform' . uniqid(), 'post', '');
        $formdata = ['id' => $optionid, 'cmid' => $cmid];
        addtocalendar::instance_form_definition($mform, $formdata, [], [], false);
        return $mform->getElement('addtocalendar');
    }

    /**
     * Values offered by the rendered select element.
     *
     * @param \HTML_QuickForm_element $element
     * @return int[]
     */
    private function get_offered_values($element): array {
        $values = [];
        foreach ($element->_options as $option) {
            $values[] = (int)$option['attr']['value'];
        }
        sort($values);
        return $values;
    }

    /**
     * Currently selected value of the rendered select element.
     *
     * @param \HTML_QuickForm_element $element
     * @return int
     */
    private function get_selected_value($element): int {
        $selected = $element->getSelected();
        return (int)reset($selected);
    }
}
