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

namespace bookingextension_confirmation_supervisor;

use advanced_testcase;
use mod_booking\singleton_service;
use mod_booking\local\bookingworkflow\bookforothers;
use mod_booking_generator;

/**
 * Tests for the "book for others" restriction (mod/booking:bookmyteam).
 *
 * @package bookingextension_confirmation_supervisor
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bookforothers_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Creates booking course, users, and booking option, plus a supervisor/deputy/cashier role setup.
     *
     * @return array
     */
    private function setup_booking_environment(): array {
        global $CFG;

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->setAdminUser();

        $this->create_custom_profile_field('supervisor', 'Supervisor');
        $this->create_custom_profile_field('deputy', 'Deputy');

        set_config('supervisor', 'supervisor', 'bookingextension_confirmation_supervisor');
        set_config('deputy', 'deputy', 'bookingextension_confirmation_supervisor');
        set_config('confirmationsupervisorenabled', 1, 'bookingextension_confirmation_supervisor');

        $course = $this->getDataGenerator()->create_course();

        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $supervisor1 = $this->getDataGenerator()->create_user();
        $deputy1 = $this->getDataGenerator()->create_user();
        $cashier = $this->getDataGenerator()->create_user();
        $nobody = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        // Student1 is supervised by supervisor1. Student2 has no supervisor.
        profile_save_data((object)['id' => $student1->id, 'profile_field_supervisor' => $supervisor1->id]);
        profile_save_data((object)['id' => $supervisor1->id, 'profile_field_deputy' => (string) $deputy1->id]);

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Test option',
            'courseid' => $course->id,
            'chooseorcreatecourse' => 1,
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        $teamroleid = create_role('Team booker', 'teambooker', 'Can only book for own team');
        assign_capability('mod/booking:bookmyteam', CAP_ALLOW, $teamroleid, SYSCONTEXTID, true);

        $cashierroleid = create_role('Cashier', 'cashier', 'Can book for anyone');
        assign_capability('mod/booking:bookforothers', CAP_ALLOW, $cashierroleid, SYSCONTEXTID, true);

        $syscontext = \context_system::instance();
        role_assign($teamroleid, $supervisor1->id, $syscontext->id);
        role_assign($teamroleid, $deputy1->id, $syscontext->id);
        role_assign($cashierroleid, $cashier->id, $syscontext->id);

        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        return [
            'settings' => $settings,
            'users' => [
                'student1' => $student1,
                'student2' => $student2,
                'supervisor1' => $supervisor1,
                'deputy1' => $deputy1,
                'cashier' => $cashier,
                'nobody' => $nobody,
            ],
        ];
    }

    /**
     * A supervisor with bookmyteam can book for their own subordinate.
     * @covers \bookingextension_confirmation_supervisor\local\bookforothers
     */
    public function test_supervisor_can_book_for_own_subordinate(): void {
        $env = $this->setup_booking_environment();
        $settings = $env['settings'];
        $users = $env['users'];

        [$allowed, ] = bookforothers::check_booking_capability(
            $settings->id,
            $users['supervisor1']->id,
            $users['student1']->id
        );
        $this->assertTrue($allowed);
    }

    /**
     * A supervisor with bookmyteam cannot book for someone who is not their subordinate.
     * @covers \bookingextension_confirmation_supervisor\local\bookforothers
     */
    public function test_supervisor_cannot_book_for_non_subordinate(): void {
        $env = $this->setup_booking_environment();
        $settings = $env['settings'];
        $users = $env['users'];

        [$allowed, ] = bookforothers::check_booking_capability(
            $settings->id,
            $users['supervisor1']->id,
            $users['student2']->id
        );
        $this->assertFalse($allowed);
    }

    /**
     * A deputy of the supervisor can book for the supervisor's subordinate.
     * @covers \bookingextension_confirmation_supervisor\local\bookforothers
     */
    public function test_deputy_can_book_for_supervisors_subordinate(): void {
        $env = $this->setup_booking_environment();
        $settings = $env['settings'];
        $users = $env['users'];

        [$allowed, ] = bookforothers::check_booking_capability(
            $settings->id,
            $users['deputy1']->id,
            $users['student1']->id
        );
        $this->assertTrue($allowed);
    }

    /**
     * A cashier with the unrestricted bookforothers capability can book for anyone, regardless of relationship.
     * @covers \mod_booking\local\bookingworkflow\bookforothers
     */
    public function test_cashier_can_book_for_anyone(): void {
        $env = $this->setup_booking_environment();
        $settings = $env['settings'];
        $users = $env['users'];

        [$allowed, ] = bookforothers::check_booking_capability(
            $settings->id,
            $users['cashier']->id,
            $users['student2']->id
        );
        $this->assertTrue($allowed);
    }

    /**
     * A user with neither capability cannot book for someone else.
     * @covers \mod_booking\local\bookingworkflow\bookforothers
     */
    public function test_user_without_capability_cannot_book_for_others(): void {
        $env = $this->setup_booking_environment();
        $settings = $env['settings'];
        $users = $env['users'];

        [$allowed, ] = bookforothers::check_booking_capability(
            $settings->id,
            $users['nobody']->id,
            $users['student1']->id
        );
        $this->assertFalse($allowed);
    }

    /**
     * Every user can always book for themselves, regardless of capabilities.
     * @covers \mod_booking\local\bookingworkflow\bookforothers
     */
    public function test_user_can_always_book_for_self(): void {
        $env = $this->setup_booking_environment();
        $settings = $env['settings'];
        $users = $env['users'];

        [$allowed, ] = bookforothers::check_booking_capability(
            $settings->id,
            $users['nobody']->id,
            $users['nobody']->id
        );
        $this->assertTrue($allowed);
    }

    /**
     * Creates a custom user profile field.
     *
     * @param string $shortname Field shortname (e.g. 'supervisor')
     * @param string $name Field name shown in UI
     * @return void
     */
    private function create_custom_profile_field(string $shortname, string $name): void {
        global $DB;

        if (!$DB->record_exists('user_info_category', ['name' => 'Test Category'])) {
            $cat = new \stdClass();
            $cat->name = 'Test Category';
            $cat->sortorder = 1;
            $cat->id = $DB->insert_record('user_info_category', $cat);
        } else {
            $cat = $DB->get_record('user_info_category', ['name' => 'Test Category']);
        }

        $field = new \stdClass();
        $field->shortname = $shortname;
        $field->name = $name;
        $field->datatype = 'text';
        $field->description = '';
        $field->descriptionformat = FORMAT_HTML;
        $field->categoryid = $cat->id;
        $field->sortorder = 1;
        $field->required = 0;
        $field->locked = 0;
        $field->visible = 1;
        $field->forceunique = 0;
        $field->signup = 0;
        $field->defaultdata = '';
        $field->defaultdataformat = FORMAT_HTML;
        $field->param1 = 30;
        $field->id = $DB->insert_record('user_info_field', $field);
    }
}
