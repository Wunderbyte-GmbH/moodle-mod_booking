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

namespace mod_booking\output;

use advanced_testcase;
use context_module;
use mod_booking\booking_option;
use mod_booking\singleton_service;
use stdClass;

/**
 * Tests who sees the number of places on the notification list in the column of available places.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\output\col_availableplaces
 */
final class col_availableplaces_notificationlist_test extends advanced_testcase {
    /** @var stdClass the booking option */
    private $option;

    /** @var stdClass a student of the course */
    private $student;

    /** @var stdClass an editing teacher of the course */
    private $teacher;

    /**
     * Set up a booking option with one user on the notification list.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        singleton_service::destroy_instance();

        set_config('usenotificationlist', 1, 'booking');
        set_config('shownotificationlistplaces', 1, 'booking');

        $course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->teacher = $this->getDataGenerator()->create_user();
        $watcher = $this->getDataGenerator()->create_user();

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $this->teacher->username,
        ]);

        $this->getDataGenerator()->enrol_user($this->student->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($watcher->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($this->teacher->id, $course->id, 'editingteacher');

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $this->option = $plugingenerator->create_option((object) [
            'bookingid' => $booking->id,
            'text' => 'Option with notification list',
            'description' => 'Test',
            'maxanswers' => 1,
            'chooseorcreatecourse' => 1,
            'courseid' => $course->id,
        ]);

        booking_option::toggle_notify_user($watcher->id, $this->option->id);
        singleton_service::destroy_instance();
    }

    /**
     * Export the column of available places as the given user.
     *
     * @param stdClass $user
     * @param array $displayoptions
     * @return array
     */
    private function export_as(stdClass $user, array $displayoptions = []): array {
        global $PAGE;

        $this->setUser($user);
        singleton_service::destroy_instance();

        $settings = singleton_service::get_instance_of_booking_option_settings($this->option->id);
        $column = new col_availableplaces(null, $settings);
        $column->apply_display_options($displayoptions);

        return $column->export_for_template($PAGE->get_renderer('mod_booking'));
    }

    /**
     * A user with the capability sees the number of places on the notification list.
     */
    public function test_teacher_with_capability_sees_number(): void {
        $data = $this->export_as($this->teacher);

        $this->assertTrue($data['shownotificationlist'] ?? false);
        $this->assertSame(1, (int) $data['notificationlistplaces']);
    }

    /**
     * A user without the capability neither sees the number nor gets it within the template data.
     */
    public function test_student_without_capability_does_not_see_number(): void {
        $data = $this->export_as($this->student);

        $this->assertArrayNotHasKey('shownotificationlist', $data);
        $this->assertArrayNotHasKey('notificationlistplaces', $data);
    }

    /**
     * The shortcode can not show the number to a user without the capability.
     */
    public function test_shortcode_option_does_not_bypass_capability(): void {
        $data = $this->export_as($this->student, ['shownotificationlist' => 'true']);

        $this->assertArrayNotHasKey('shownotificationlist', $data);
        $this->assertArrayNotHasKey('notificationlistplaces', $data);
    }

    /**
     * The capability can be given to students for a single booking instance.
     */
    public function test_capability_can_be_granted_to_students(): void {
        global $DB;

        $settings = singleton_service::get_instance_of_booking_option_settings($this->option->id);
        $studentrole = (int) $DB->get_field('role', 'id', ['shortname' => 'student']);
        assign_capability(
            'mod/booking:viewnotificationlistplaces',
            CAP_ALLOW,
            $studentrole,
            context_module::instance($settings->cmid)->id
        );

        $data = $this->export_as($this->student);

        $this->assertTrue($data['shownotificationlist'] ?? false);
        $this->assertSame(1, (int) $data['notificationlistplaces']);
    }

    /**
     * Even with the capability, the number is hidden when the setting and the shortcode do not ask for it.
     */
    public function test_setting_off_hides_number_for_teacher(): void {
        set_config('shownotificationlistplaces', 0, 'booking');

        $data = $this->export_as($this->teacher);

        $this->assertArrayNotHasKey('shownotificationlist', $data);
    }
}
