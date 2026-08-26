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

use mod_booking\tests\booking_advanced_testcase;
use backup;
use backup_controller;
use cache_helper;
use mod_booking_generator;
use restore_controller;
use stdClass;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests that the connected Moodle courses are duplicated with the booking instance.
 *
 * A Moodle course is not part of an activity backup - only its id is - so without further
 * action a duplicated booking instance enrols into the very same courses as the original.
 * With the duplicatemoodlecourses setting turned on, those courses are copied as well and
 * the duplicated options are pointed at the copies.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \restore_booking_activity_structure_step::duplicate_connected_course
 *
 * @runTestsInSeparateProcesses
 */
final class duplicate_connected_courses_test extends booking_advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::set_mock_time(strtotime('now'));
        singleton_service::destroy_instance();
    }

    /**
     * Create a minimal booking instance in the given course.
     *
     * @param stdClass $course
     * @param string $name
     * @return stdClass the booking module instance
     */
    private function create_booking_instance(stdClass $course, string $name): stdClass {
        $bdata = [
            'name' => $name,
            'course' => $course->id,
            'eventtype' => 'Test event',
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
        ];
        return $this->getDataGenerator()->create_module('booking', $bdata);
    }

    /**
     * Create a booking option connected to an existing Moodle course.
     *
     * @param mod_booking_generator $plugingenerator
     * @param int $bookingid
     * @param string $text the option title
     * @param int $connectedcourseid the Moodle course the option enrols into
     * @return stdClass the created option
     */
    private function create_connected_option(
        mod_booking_generator $plugingenerator,
        int $bookingid,
        string $text,
        int $connectedcourseid
    ): stdClass {
        $record = new stdClass();
        $record->bookingid = $bookingid;
        $record->text = $text;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $connectedcourseid;
        $record->importing = 1;
        return $plugingenerator->create_option($record);
    }

    /**
     * Purge caches and singletons after a module duplication so subsequent reads hit the DB.
     *
     * @param stdClass $course
     * @return void
     */
    private function reset_after_duplication(stdClass $course): void {
        cache_helper::purge_all();
        singleton_service::destroy_instance();
        get_fast_modinfo($course, 0, true);
    }

    /**
     * Duplicate a booking instance and return the connected courseids of the resulting options,
     * keyed by the option title.
     *
     * @param stdClass $course the course holding the booking instance
     * @param int $cmid the cmid of the booking instance to duplicate
     * @return array [optiontitle => connected courseid]
     */
    private function duplicate_and_return_connected_courses(stdClass $course, int $cmid): array {
        global $DB;

        $cm = get_fast_modinfo($course)->get_cm($cmid);
        $newcm = duplicate_module($course, $cm);
        $this->assertNotNull($newcm, 'Module duplication must return a valid cm_info object.');
        $this->reset_after_duplication($course);

        $newoptions = $DB->get_records('booking_options', ['bookingid' => $newcm->instance], '', 'id, text, courseid');

        $result = [];
        foreach ($newoptions as $option) {
            $result[$option->text] = (int) $option->courseid;
        }
        return $result;
    }

    /**
     * Back up a course and restore it into another course, pretending to be a different site.
     *
     * is_samesite() compares the site identifier stored in the backup with the one of the site
     * performing the restore, so rewriting the identifier in between is enough to get a genuine
     * cross site restore.
     *
     * @param stdClass $source the course to back up
     * @param stdClass $target the course to restore into
     * @return stdClass the single restored booking option (id, courseid)
     */
    private function restore_to_other_site(stdClass $source, stdClass $target): stdClass {
        global $DB, $USER;

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $source->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_YES,
            backup::MODE_IMPORT,
            $USER->id
        );
        $bc->finish_ui();
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        set_config('siteidentifier', 'a-completely-different-site');

        $rc = new restore_controller(
            $backupid,
            $target->id,
            backup::INTERACTIVE_YES,
            backup::MODE_IMPORT,
            $USER->id,
            backup::TARGET_CURRENT_ADDING
        );
        $this->assertFalse($rc->is_samesite(), 'This test is only meaningful for a cross site restore.');
        $rc->finish_ui();
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $this->reset_after_duplication($target);

        $restored = get_fast_modinfo($target->id)->get_instances_of('booking');
        $restored = reset($restored);

        return $DB->get_record(
            'booking_options',
            ['bookingid' => $restored->instance],
            'id, courseid',
            MUST_EXIST
        );
    }

    /**
     * With the setting turned on, the duplicated options point at fresh course copies.
     *
     * @return void
     */
    public function test_connected_courses_are_duplicated_when_setting_enabled(): void {
        $this->setAdminUser();
        set_config('duplicatemoodlecourses', 1, 'booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $course = $this->getDataGenerator()->create_course();
        $connected1 = $this->getDataGenerator()->create_course(['shortname' => 'connected1']);
        $connected2 = $this->getDataGenerator()->create_course(['shortname' => 'connected2']);

        $booking = $this->create_booking_instance($course, 'Booking instance 1');
        $this->create_connected_option($plugingenerator, $booking->id, 'Option one', $connected1->id);
        $this->create_connected_option($plugingenerator, $booking->id, 'Option two', $connected2->id);

        $newcourseids = $this->duplicate_and_return_connected_courses($course, $booking->cmid);

        $this->assertCount(2, $newcourseids);

        // Every duplicated option enrols into a course of its own, not into the original.
        $this->assertNotEquals($connected1->id, $newcourseids['Option one']);
        $this->assertNotEquals($connected2->id, $newcourseids['Option two']);
        $this->assertNotEquals($newcourseids['Option one'], $newcourseids['Option two']);

        // The copies really exist.
        global $DB;
        $this->assertTrue($DB->record_exists('course', ['id' => $newcourseids['Option one']]));
        $this->assertTrue($DB->record_exists('course', ['id' => $newcourseids['Option two']]));

        // The originals are untouched and still connected to the original options.
        $originals = $DB->get_records_menu('booking_options', ['bookingid' => $booking->id], '', 'text, courseid');
        $this->assertEquals($connected1->id, (int) $originals['Option one']);
        $this->assertEquals($connected2->id, (int) $originals['Option two']);
    }

    /**
     * With the setting turned off - which is the default - nothing changes and the duplicated
     * options keep pointing at the original courses.
     *
     * @return void
     */
    public function test_connected_courses_are_kept_when_setting_disabled(): void {
        $this->setAdminUser();
        set_config('duplicatemoodlecourses', 0, 'booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $course = $this->getDataGenerator()->create_course();
        $connected = $this->getDataGenerator()->create_course(['shortname' => 'connected1']);

        $booking = $this->create_booking_instance($course, 'Booking instance 1');
        $this->create_connected_option($plugingenerator, $booking->id, 'Option one', $connected->id);

        $newcourseids = $this->duplicate_and_return_connected_courses($course, $booking->cmid);

        $this->assertSame((int) $connected->id, $newcourseids['Option one']);
    }

    /**
     * Options which shared one connected course must keep sharing it: the course is copied once
     * and both duplicated options point at that single copy.
     *
     * @return void
     */
    public function test_shared_connected_course_is_copied_only_once(): void {
        global $DB;

        $this->setAdminUser();
        set_config('duplicatemoodlecourses', 1, 'booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $course = $this->getDataGenerator()->create_course();
        $shared = $this->getDataGenerator()->create_course(['shortname' => 'sharedcourse']);

        $booking = $this->create_booking_instance($course, 'Booking instance 1');
        $this->create_connected_option($plugingenerator, $booking->id, 'Option one', $shared->id);
        $this->create_connected_option($plugingenerator, $booking->id, 'Option two', $shared->id);

        $coursecountbefore = $DB->count_records('course');

        $newcourseids = $this->duplicate_and_return_connected_courses($course, $booking->cmid);

        $this->assertCount(2, $newcourseids);
        $this->assertNotEquals($shared->id, $newcourseids['Option one']);
        // Both duplicated options share the one copy.
        $this->assertSame($newcourseids['Option one'], $newcourseids['Option two']);
        // Exactly one new course was created, not two.
        $this->assertSame($coursecountbefore + 1, $DB->count_records('course'));
    }

    /**
     * The configured naming scheme is applied to the copy, using the id of the duplicated
     * option rather than the id of the original.
     *
     * @return void
     */
    public function test_naming_scheme_is_applied_to_the_copy(): void {
        global $DB;

        $this->setAdminUser();
        set_config('duplicatemoodlecourses', 1, 'booking');
        set_config('connectedcoursefullname', '{titlewithoutprefix}', 'booking');
        set_config('connectedcourseshortname', '{titlewithoutprefix}_{optionid}', 'booking');
        set_config('connectedcourseidnumber', '{optionid}', 'booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $course = $this->getDataGenerator()->create_course();
        $connected = $this->getDataGenerator()->create_course(['shortname' => 'connected1']);

        $booking = $this->create_booking_instance($course, 'Booking instance 1');
        $this->create_connected_option($plugingenerator, $booking->id, 'Aerial Yoga', $connected->id);

        $cm = get_fast_modinfo($course)->get_cm($booking->cmid);
        $newcm = duplicate_module($course, $cm);
        $this->reset_after_duplication($course);

        $newoption = $DB->get_record('booking_options', ['bookingid' => $newcm->instance], 'id, courseid', MUST_EXIST);
        $newcourse = $DB->get_record('course', ['id' => $newoption->courseid], '*', MUST_EXIST);

        $this->assertSame('Aerial Yoga', $newcourse->fullname);
        $this->assertSame('Aerial Yoga_' . $newoption->id, $newcourse->shortname);
        $this->assertSame((string) $newoption->id, $newcourse->idnumber);
    }

    /**
     * On a restore onto a DIFFERENT site nothing is copied.
     *
     * The stored courseid refers to a course of the origin site, so there is nothing here that
     * could meaningfully be duplicated. Copying whatever course happens to carry that id on the
     * target site would be plain wrong.
     *
     * @return void
     */
    public function test_no_copy_on_cross_site_restore(): void {
        global $DB;

        $this->setAdminUser();
        set_config('duplicatemoodlecourses', 1, 'booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $connected = $this->getDataGenerator()->create_course(['shortname' => 'connected1']);

        $booking = $this->create_booking_instance($course1, 'Booking instance 1');
        $this->create_connected_option($plugingenerator, $booking->id, 'Option one', $connected->id);

        $coursecountbefore = $DB->count_records('course');

        $restoredoption = $this->restore_to_other_site($course1, $course2);

        // No course copy was made. That the connection itself is dropped is the job of
        // remap_connected_course() and is covered by its own test below.
        $this->assertSame($coursecountbefore, $DB->count_records('course'));
        $this->assertNotEmpty($restoredoption->id);
    }

    /**
     * On a cross site restore an option connected to some arbitrary course loses its connection,
     * instead of silently pointing at whatever course carries that id on the target site.
     *
     * @return void
     */
    public function test_cross_site_restore_drops_foreign_connected_course(): void {
        global $DB;

        $this->setAdminUser();
        // Deliberately off: dropping the foreign courseid is not part of the duplication feature.
        set_config('duplicatemoodlecourses', 0, 'booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $connected = $this->getDataGenerator()->create_course(['shortname' => 'connected1']);

        $booking = $this->create_booking_instance($course1, 'Booking instance 1');
        $this->create_connected_option($plugingenerator, $booking->id, 'Option one', $connected->id);

        $restoredoption = $this->restore_to_other_site($course1, $course2);

        $this->assertEquals(0, (int) $restoredoption->courseid);

        // The course it used to point at is of course still there, just no longer connected.
        $this->assertTrue($DB->record_exists('course', ['id' => $connected->id]));
    }

    /**
     * The one id which CAN be translated on another site is the course the backup came from:
     * an option enrolling into that course now enrols into the course being restored into.
     *
     * This runs with the duplication setting ON on purpose. It is the only path which reaches
     * the is_samesite() check in duplicate_connected_course(): every other cross site option
     * has had its courseid zeroed by remap_connected_course() and returns before that check.
     * Without the check, the course we restore into would be copied here.
     *
     * @return void
     */
    public function test_cross_site_restore_maps_the_origin_course(): void {
        global $DB;

        $this->setAdminUser();
        set_config('duplicatemoodlecourses', 1, 'booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();

        $booking = $this->create_booking_instance($course1, 'Booking instance 1');
        // The option enrols into the very course the booking instance lives in.
        $this->create_connected_option($plugingenerator, $booking->id, 'Option one', $course1->id);

        $coursecountbefore = $DB->count_records('course');

        $restoredoption = $this->restore_to_other_site($course1, $course2);

        $this->assertEquals($course2->id, (int) $restoredoption->courseid);
        // Nothing was copied: on another site there is nothing to duplicate.
        $this->assertSame($coursecountbefore, $DB->count_records('course'));
    }

    /**
     * Same site restores are not affected: the courseid stays valid and is kept as it is.
     *
     * @return void
     */
    public function test_same_site_restore_keeps_the_connected_course(): void {
        $this->setAdminUser();
        set_config('duplicatemoodlecourses', 0, 'booking');

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        $course = $this->getDataGenerator()->create_course();
        $connected = $this->getDataGenerator()->create_course(['shortname' => 'connected1']);

        $booking = $this->create_booking_instance($course, 'Booking instance 1');
        $this->create_connected_option($plugingenerator, $booking->id, 'Option one', $connected->id);

        $newcourseids = $this->duplicate_and_return_connected_courses($course, $booking->cmid);

        $this->assertSame((int) $connected->id, $newcourseids['Option one']);
    }
}
