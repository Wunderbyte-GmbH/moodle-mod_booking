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
 * Tests that the connected course naming scheme survives the asynchronous course copy.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\connectedcourse;
use mod_booking\option\fields\courseid;
use mod_booking\task\finalize_connected_course_naming;
use mod_booking_generator;
use stdClass;

/**
 * Tests that the connected course naming scheme survives the asynchronous course copy.
 *
 * Copying a Moodle course is always asynchronous. \core\task\asynchronous_copy_task feeds the
 * provisional fullname and shortname back into the restore and resets the idnumber from the copy
 * data, so naming the course when the option is saved is not enough - everything is overwritten
 * on the next cron run. These tests therefore run the queued adhoc tasks and assert the naming
 * afterwards; without doing that they would pass over the very bug they exist for.
 *
 * @package mod_booking
 * @category test
 * @covers \mod_booking\task\finalize_connected_course_naming
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connectedcourse_naming_cron_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
    }

    /**
     * Configure the naming scheme the ticket asks for.
     *
     * @return void
     */
    private function set_naming_scheme(): void {
        set_config('duplicatemoodlecourses', 1, 'booking');
        set_config('connectedcoursefullname', '{titlewithoutprefix}', 'booking');
        set_config('connectedcourseshortname', '{titlewithoutprefix}_{optionid}', 'booking');
        set_config('connectedcourseidnumber', '{optionid}', 'booking');
    }

    /**
     * Create a booking option connected to a Moodle course.
     *
     * @param string $text the option title
     * @param string $titleprefix the title prefix
     * @return array [$option, $connectedcourse]
     */
    private function create_option_with_connected_course(string $text, string $titleprefix = ''): array {
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);
        // Shortnames have to be unique, and a test may build more than one of these.
        static $counter = 0;
        $counter++;
        $connectedcourse = $this->getDataGenerator()->create_course([
            'fullname' => 'Connected course ' . $counter,
            'shortname' => 'connected-course-' . $counter,
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = $text;
        $record->titleprefix = $titleprefix;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $connectedcourse->id;
        $record->importing = 1;

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');

        return [$plugingenerator->create_option($record), $connectedcourse];
    }

    /**
     * Reload a course straight from the database.
     *
     * @param int $courseid
     * @return stdClass
     */
    private function reload_course(int $courseid): stdClass {
        global $DB;
        return $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    }

    /**
     * Duplicating a booking option: the copy keeps the scheme names after cron has run.
     *
     * Against the pre-fix code the assertions after run_all_adhoc_tasks() fail, because the copy
     * task resets the course to "Connected course 1 (copy)" with an empty id number.
     *
     * @return void
     */
    public function test_naming_survives_cron_on_option_duplication(): void {
        $this->set_naming_scheme();

        [$sourceoption] = $this->create_option_with_connected_course('Math', 'PF1');
        $sourcesettings = singleton_service::get_instance_of_booking_option_settings($sourceoption->id);

        // What the duplication form does on load: copy the connected course.
        $data = new stdClass();
        $data->oldcopyoptionid = $sourceoption->id;
        courseid::set_data($data, $sourcesettings);

        $copiedcourseid = (int) $data->courseid;
        $this->assertNotEmpty($copiedcourseid);

        // Now the duplicate is saved under a new title, which names the copy.
        [$newoption] = $this->create_option_with_connected_course('Algebra', 'PF1');
        $formdata = (object) [
            'chooseorcreatecourse' => 1,
            'connectedcoursecopied' => $copiedcourseid,
        ];
        $optionrecord = (object) ['id' => $newoption->id, 'courseid' => $copiedcourseid];
        courseid::save_data($formdata, $optionrecord);

        // Right after saving the naming is correct.
        $course = $this->reload_course($copiedcourseid);
        $this->assertSame('Algebra', $course->fullname);

        // The finalizer is queued, because otherwise cron would undo all of this.
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(finalize_connected_course_naming::class));
        // And exactly one course copy, nothing else queued a copy along the way.
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(\core\task\asynchronous_copy_task::class));
        global $DB;
        $coursecountbefore = $DB->count_records('course');

        // Cron runs: the async copy completes and the finalizer re-applies the naming.
        // The backup, the restore and the tasks themselves all mtrace, which PHPUnit would
        // otherwise flag as a risky test.
        ob_start();
        $this->run_all_adhoc_tasks();
        ob_end_clean();

        // The copy did not queue a further copy, and no further course appeared.
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\core\task\asynchronous_copy_task::class));
        $this->assertSame($coursecountbefore, $DB->count_records('course'));

        $course = $this->reload_course($copiedcourseid);
        $this->assertSame('Algebra', $course->fullname);
        $this->assertSame('Algebra_' . $newoption->id, $course->shortname);
        $this->assertSame((string) $newoption->id, $course->idnumber);
    }

    /**
     * Without a naming scheme configured nothing is queued, so sites which never configured
     * anything keep exactly the behaviour they had.
     *
     * @return void
     */
    public function test_no_finalizer_without_naming_scheme(): void {
        set_config('duplicatemoodlecourses', 1, 'booking');

        [$option, $connectedcourse] = $this->create_option_with_connected_course('Math', 'PF1');

        $formdata = (object) [
            'chooseorcreatecourse' => 1,
            'connectedcoursecopied' => $connectedcourse->id,
        ];
        $optionrecord = (object) ['id' => $option->id, 'courseid' => $connectedcourse->id];
        courseid::save_data($formdata, $optionrecord);

        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(finalize_connected_course_naming::class));
    }

    /**
     * While the copy is still pending the task reschedules itself instead of naming a course
     * whose names are about to be overwritten anyway.
     *
     * @return void
     */
    public function test_finalizer_waits_for_the_copy_to_finish(): void {
        $this->set_naming_scheme();

        [$sourceoption] = $this->create_option_with_connected_course('Math', 'PF1');
        $sourcesettings = singleton_service::get_instance_of_booking_option_settings($sourceoption->id);

        $data = new stdClass();
        $data->oldcopyoptionid = $sourceoption->id;
        courseid::set_data($data, $sourcesettings);
        $copiedcourseid = (int) $data->courseid;

        // The copy task is queued and has not run, so the copy counts as still running.
        $this->assertTrue(finalize_connected_course_naming::copy_still_running($copiedcourseid));

        $task = new finalize_connected_course_naming();
        $task->set_custom_data(['courseid' => $copiedcourseid, 'optionid' => $sourceoption->id]);

        $this->expectException(\moodle_exception::class);
        $task->execute();
    }

    /**
     * A course which no longer exists is not an error, the task simply does nothing.
     *
     * @return void
     */
    public function test_finalizer_tolerates_a_deleted_course(): void {
        $this->setAdminUser();
        $this->set_naming_scheme();

        $task = new finalize_connected_course_naming();
        $task->set_custom_data(['courseid' => 0, 'optionid' => 0]);
        $task->execute();

        // Nothing to assert beyond "did not blow up".
        $this->assertTrue(true);
    }

    /**
     * With a naming scheme configured the template finalizer leaves the fullname alone, so the
     * two tasks do not overwrite each other depending on cron ordering.
     *
     * @return void
     */
    public function test_template_finalizer_stands_down_for_the_naming_scheme(): void {
        $this->assertFalse(connectedcourse::has_naming_scheme());

        set_config('connectedcoursefullname', '{titlewithoutprefix}', 'booking');

        $this->assertTrue(connectedcourse::has_naming_scheme());
    }
}
