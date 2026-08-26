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
 * Tests for the naming scheme of connected Moodle courses.
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
use mod_booking\placeholders\placeholders_info;
use stdClass;

/**
 * Tests for \mod_booking\local\connectedcourse::apply_naming_scheme.
 *
 * The three connectedcourse* settings hold placeholder templates for the name of the Moodle
 * course connected to a booking option. An empty setting must leave the corresponding course
 * field exactly as it was, so that sites which never configure anything keep the naming they
 * had before these settings existed.
 *
 * @package mod_booking
 * @category test
 * @covers \mod_booking\local\connectedcourse::apply_naming_scheme
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connectedcourse_naming_test extends advanced_testcase {
    /** @var string The full name the connected course starts out with. */
    private const ORIGINALFULLNAME = 'Original course fullname';

    /** @var string The short name the connected course starts out with. */
    private const ORIGINALSHORTNAME = 'originalcourseshortname';

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        // Option ids may repeat across tests because every test starts from a clean database.
        // Without this reset a cached title from a previous test could leak into this one.
        placeholders_info::$placeholders = [];
    }

    /**
     * Create a booking option together with the Moodle course connected to it.
     *
     * @param string $text the booking option title
     * @param string $titleprefix the title prefix, empty for none
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

        $connectedcourse = $this->getDataGenerator()->create_course([
            'fullname' => self::ORIGINALFULLNAME,
            'shortname' => self::ORIGINALSHORTNAME,
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = $text;
        $record->titleprefix = $titleprefix;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $connectedcourse->id;
        $record->importing = 1;

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        return [$option, $connectedcourse];
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
     * Without any configured template the course keeps the name it already has.
     *
     * @return void
     */
    public function test_no_settings_leaves_course_untouched(): void {
        [$option, $connectedcourse] = $this->create_option_with_connected_course('Aerial Yoga', 'PF1');

        connectedcourse::apply_naming_scheme($connectedcourse->id, $option->id);

        $course = $this->reload_course($connectedcourse->id);
        $this->assertSame(self::ORIGINALFULLNAME, $course->fullname);
        $this->assertSame(self::ORIGINALSHORTNAME, $course->shortname);
        $this->assertSame('', $course->idnumber);
    }

    /**
     * The full name template is applied and the prefix is not part of it.
     *
     * @return void
     */
    public function test_fullname_template_is_applied(): void {
        set_config('connectedcoursefullname', '{titlewithoutprefix}', 'booking');

        [$option, $connectedcourse] = $this->create_option_with_connected_course('Aerial Yoga', 'PF1');

        connectedcourse::apply_naming_scheme($connectedcourse->id, $option->id);

        $course = $this->reload_course($connectedcourse->id);
        $this->assertSame('Aerial Yoga', $course->fullname);
        // Only the configured field is touched.
        $this->assertSame(self::ORIGINALSHORTNAME, $course->shortname);
    }

    /**
     * The short name template combines the title with the option id, which is what makes it
     * unique without any counter being appended.
     *
     * @return void
     */
    public function test_shortname_template_with_optionid(): void {
        set_config('connectedcourseshortname', '{titlewithoutprefix}_{optionid}', 'booking');

        [$option, $connectedcourse] = $this->create_option_with_connected_course('Aerial Yoga', 'PF1');

        connectedcourse::apply_naming_scheme($connectedcourse->id, $option->id);

        $course = $this->reload_course($connectedcourse->id);
        $this->assertSame('Aerial Yoga_' . $option->id, $course->shortname);
        $this->assertSame(self::ORIGINALFULLNAME, $course->fullname);
    }

    /**
     * The course id number can be set to the booking option id.
     *
     * @return void
     */
    public function test_idnumber_template_is_applied(): void {
        set_config('connectedcourseidnumber', '{optionid}', 'booking');

        [$option, $connectedcourse] = $this->create_option_with_connected_course('Aerial Yoga');

        connectedcourse::apply_naming_scheme($connectedcourse->id, $option->id);

        $course = $this->reload_course($connectedcourse->id);
        $this->assertSame((string) $option->id, $course->idnumber);
    }

    /**
     * All three templates work together, which is the setup the ticket asks for.
     *
     * @return void
     */
    public function test_all_three_templates_together(): void {
        set_config('connectedcoursefullname', '{titlewithoutprefix}', 'booking');
        set_config('connectedcourseshortname', '{titlewithoutprefix}_{optionid}', 'booking');
        set_config('connectedcourseidnumber', '{optionid}', 'booking');

        [$option, $connectedcourse] = $this->create_option_with_connected_course('Aerial Yoga', 'PF1');

        connectedcourse::apply_naming_scheme($connectedcourse->id, $option->id);

        $course = $this->reload_course($connectedcourse->id);
        $this->assertSame('Aerial Yoga', $course->fullname);
        $this->assertSame('Aerial Yoga_' . $option->id, $course->shortname);
        $this->assertSame((string) $option->id, $course->idnumber);
    }

    /**
     * A short name which is already taken by another course gets a counter appended,
     * because Moodle would refuse the update otherwise.
     *
     * @return void
     */
    public function test_shortname_collision_gets_counter(): void {
        set_config('connectedcourseshortname', '{titlewithoutprefix}', 'booking');

        [$option, $connectedcourse] = $this->create_option_with_connected_course('Aerial Yoga');

        // Another course already occupies the short name the template renders.
        $this->getDataGenerator()->create_course(['shortname' => 'Aerial Yoga']);

        connectedcourse::apply_naming_scheme($connectedcourse->id, $option->id);

        $course = $this->reload_course($connectedcourse->id);
        $this->assertSame('Aerial Yoga_1', $course->shortname);
    }

    /**
     * A course id number which is already taken is skipped rather than mangled with a counter,
     * because the value is supposed to stay machine readable. The other fields still apply.
     *
     * @return void
     */
    public function test_idnumber_collision_is_skipped(): void {
        set_config('connectedcoursefullname', '{titlewithoutprefix}', 'booking');
        set_config('connectedcourseidnumber', '{optionid}', 'booking');

        [$option, $connectedcourse] = $this->create_option_with_connected_course('Aerial Yoga');

        // Another course already occupies the id number the template renders.
        $this->getDataGenerator()->create_course(['idnumber' => (string) $option->id]);

        connectedcourse::apply_naming_scheme($connectedcourse->id, $option->id);
        $this->assertDebuggingCalled();

        $course = $this->reload_course($connectedcourse->id);
        $this->assertSame('', $course->idnumber);
        // The full name is applied regardless of the id number being skipped.
        $this->assertSame('Aerial Yoga', $course->fullname);
    }

    /**
     * A template which renders to nothing leaves the field alone instead of blanking it.
     *
     * {title} is the realistic way to trigger this: it needs a logged in user and renders
     * empty without one, which is exactly what happens in background tasks.
     *
     * @return void
     */
    public function test_template_rendering_to_empty_leaves_field_untouched(): void {
        set_config('connectedcoursefullname', '{title}', 'booking');

        [$option, $connectedcourse] = $this->create_option_with_connected_course('Aerial Yoga');

        // Drop back to "not logged in", the way a scheduled task runs.
        $this->setUser(null);

        connectedcourse::apply_naming_scheme($connectedcourse->id, $option->id);

        $course = $this->reload_course($connectedcourse->id);
        $this->assertSame(self::ORIGINALFULLNAME, $course->fullname);
    }

    /**
     * Rendered values are cut to the length the course table can actually store.
     *
     * @return void
     */
    public function test_values_are_truncated_to_field_length(): void {
        set_config('connectedcoursefullname', '{titlewithoutprefix}', 'booking');

        // The booking option title may be longer than the course fullname column.
        $longtitle = str_repeat('a', 255);
        [$option, $connectedcourse] = $this->create_option_with_connected_course($longtitle);

        connectedcourse::apply_naming_scheme($connectedcourse->id, $option->id);

        $course = $this->reload_course($connectedcourse->id);
        $this->assertSame(254, strlen($course->fullname));
        $this->assertSame(str_repeat('a', 254), $course->fullname);
    }

    /**
     * A course the user merely picked from the list is never renamed, even with templates
     * configured. It may belong to somebody else and be shared by many booking options.
     *
     * @covers \mod_booking\option\fields\courseid::save_data
     * @return void
     */
    public function test_selected_course_is_not_renamed(): void {
        set_config('connectedcoursefullname', '{titlewithoutprefix}', 'booking');
        set_config('connectedcourseshortname', '{titlewithoutprefix}_{optionid}', 'booking');
        set_config('connectedcourseidnumber', '{optionid}', 'booking');

        // create_option_with_connected_course() uses chooseorcreatecourse = 1, i.e. the user
        // selected an existing course. This goes through the real save pipeline.
        [, $connectedcourse] = $this->create_option_with_connected_course('Aerial Yoga', 'PF1');

        $course = $this->reload_course($connectedcourse->id);
        $this->assertSame(self::ORIGINALFULLNAME, $course->fullname);
        $this->assertSame(self::ORIGINALSHORTNAME, $course->shortname);
        $this->assertSame('', $course->idnumber);
    }

    /**
     * A course created by mod_booking itself is renamed when the option is saved.
     *
     * @covers \mod_booking\option\fields\courseid::save_data
     * @return void
     */
    public function test_newly_created_course_is_renamed(): void {
        global $DB;

        set_config('connectedcoursefullname', '{titlewithoutprefix}', 'booking');
        set_config('connectedcourseshortname', '{titlewithoutprefix}_{optionid}', 'booking');
        set_config('connectedcourseidnumber', '{optionid}', 'booking');

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $bookingmanager = $this->getDataGenerator()->create_user();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Aerial Yoga';
        $record->titleprefix = 'PF1';
        // Let mod_booking create a brand new Moodle course for this option.
        $record->chooseorcreatecourse = 2;
        $record->importing = 1;

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option($record);

        $newcourseid = (int) $DB->get_field('booking_options', 'courseid', ['id' => $option->id]);
        $this->assertNotEmpty($newcourseid);

        $newcourse = $this->reload_course($newcourseid);
        $this->assertSame('Aerial Yoga', $newcourse->fullname);
        $this->assertSame('Aerial Yoga_' . $option->id, $newcourse->shortname);
        $this->assertSame((string) $option->id, $newcourse->idnumber);
    }

    /**
     * A course mod_booking copied during option duplication is renamed as well. The copy is
     * marked with the connectedcoursecopied form field, because by submit time it is otherwise
     * indistinguishable from a course the user picked by hand.
     *
     * @covers \mod_booking\option\fields\courseid::save_data
     * @return void
     */
    public function test_copied_course_is_renamed(): void {
        set_config('connectedcoursefullname', '{titlewithoutprefix}', 'booking');

        [$option, $connectedcourse] = $this->create_option_with_connected_course('Aerial Yoga', 'PF1');

        // Rebuild what the submitted duplication form looks like: the course is a copy we own,
        // even though the select still says "connected Moodle course".
        $formdata = (object) [
            'chooseorcreatecourse' => 1,
            'connectedcoursecopied' => $connectedcourse->id,
        ];
        $optionrecord = (object) [
            'id' => $option->id,
            'courseid' => $connectedcourse->id,
        ];

        courseid::save_data($formdata, $optionrecord);

        $course = $this->reload_course($connectedcourse->id);
        $this->assertSame('Aerial Yoga', $course->fullname);
    }

    /**
     * The marker only counts for the course it actually names. A stale marker pointing at some
     * other course must not turn the selected course into a rename target.
     *
     * @covers \mod_booking\option\fields\courseid::save_data
     * @return void
     */
    public function test_marker_for_a_different_course_is_ignored(): void {
        set_config('connectedcoursefullname', '{titlewithoutprefix}', 'booking');

        [$option, $connectedcourse] = $this->create_option_with_connected_course('Aerial Yoga', 'PF1');
        $othercourse = $this->getDataGenerator()->create_course();

        $formdata = (object) [
            'chooseorcreatecourse' => 1,
            'connectedcoursecopied' => $othercourse->id,
        ];
        $optionrecord = (object) [
            'id' => $option->id,
            'courseid' => $connectedcourse->id,
        ];

        courseid::save_data($formdata, $optionrecord);

        $course = $this->reload_course($connectedcourse->id);
        $this->assertSame(self::ORIGINALFULLNAME, $course->fullname);
    }

    /**
     * Without a course or without an option there is nothing to rename.
     *
     * @return void
     */
    public function test_missing_ids_are_a_noop(): void {
        set_config('connectedcoursefullname', '{titlewithoutprefix}', 'booking');

        [$option, $connectedcourse] = $this->create_option_with_connected_course('Aerial Yoga');

        connectedcourse::apply_naming_scheme($connectedcourse->id, 0);
        connectedcourse::apply_naming_scheme(0, $option->id);

        $course = $this->reload_course($connectedcourse->id);
        $this->assertSame(self::ORIGINALFULLNAME, $course->fullname);
    }
}
