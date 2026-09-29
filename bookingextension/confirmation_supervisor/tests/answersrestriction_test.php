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
use mod_booking\booking_answers\scopes\instance;
use mod_booking\booking_answers\scopes\option;
use mod_booking\local\bookingworkflow\answersrestriction;
use mod_booking\singleton_service;
use mod_booking_generator;
use stdClass;

/**
 * Tests for the setting which limits supervisors to the booking answers of their own team.
 *
 * @package bookingextension_confirmation_supervisor
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class answersrestriction_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        singleton_service::destroy_instance();
        answersrestriction::reset_static_cache();
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        answersrestriction::reset_static_cache();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Without the setting, a supervisor sees every booked user of the option.
     *
     * @covers \bookingextension_confirmation_supervisor\local\answersrestriction::restrict_to_user_ids
     */
    public function test_restriction_is_off_by_default(): void {
        $env = $this->setup_booking_environment();

        $this->setUser($env['users']['supervisor1']);
        $userids = $this->booked_userids_of_option($env['settings']->id);

        $this->assertEqualsCanonicalizing(
            [
                (int) $env['users']['student1']->id,
                (int) $env['users']['student2']->id,
                (int) $env['users']['supervisor1']->id,
            ],
            $userids
        );
    }

    /**
     * With the setting activated, a supervisor only sees their own team - and themselves.
     *
     * @covers \bookingextension_confirmation_supervisor\local\answersrestriction::restrict_to_user_ids
     */
    public function test_supervisor_sees_only_own_team(): void {
        $env = $this->setup_booking_environment();
        $this->activate_restriction();

        $this->setUser($env['users']['supervisor1']);
        $userids = $this->booked_userids_of_option($env['settings']->id);

        // Student2 has no supervisor and is therefore not visible.
        $this->assertEqualsCanonicalizing(
            [
                (int) $env['users']['student1']->id,
                (int) $env['users']['supervisor1']->id,
            ],
            $userids
        );
    }

    /**
     * The deputy of a supervisor sees the same team as the supervisor.
     *
     * @covers \bookingextension_confirmation_supervisor\local\answersrestriction::restrict_to_user_ids
     */
    public function test_deputy_sees_the_team_of_the_supervisor(): void {
        $env = $this->setup_booking_environment();
        $this->activate_restriction();

        $this->setUser($env['users']['deputy1']);
        $userids = $this->booked_userids_of_option($env['settings']->id);

        $this->assertEqualsCanonicalizing([(int) $env['users']['student1']->id], $userids);
    }

    /**
     * A user without a team sees nothing at all once the restriction applies to them.
     *
     * @covers \bookingextension_confirmation_supervisor\local\answersrestriction::restrict_to_user_ids
     */
    public function test_user_without_team_sees_nothing(): void {
        $env = $this->setup_booking_environment();
        $this->activate_restriction();

        $this->setUser($env['users']['nobody']);
        $this->assertEmpty($this->booked_userids_of_option($env['settings']->id));
    }

    /**
     * Users who may confirm for everybody, HR users and teachers of the booking instance
     * keep the full view.
     *
     * @covers \bookingextension_confirmation_supervisor\local\answersrestriction::restrict_to_user_ids
     */
    public function test_privileged_users_are_not_restricted(): void {
        $env = $this->setup_booking_environment();
        $this->activate_restriction();

        $expected = [
            (int) $env['users']['student1']->id,
            (int) $env['users']['student2']->id,
            (int) $env['users']['supervisor1']->id,
        ];

        // Admin has mod/booking:seealllisttoapprove.
        $this->setAdminUser();
        answersrestriction::reset_static_cache();
        $this->assertEqualsCanonicalizing($expected, $this->booked_userids_of_option($env['settings']->id));

        // HR is configured by user id in the plugin settings.
        set_config(
            'confirmation_supervisor_hrusers',
            $env['users']['hr']->id,
            'bookingextension_confirmation_supervisor'
        );
        $this->setUser($env['users']['hr']);
        answersrestriction::reset_static_cache();
        $this->assertEqualsCanonicalizing($expected, $this->booked_userids_of_option($env['settings']->id));

        // The editing teacher of the course has mod/booking:managebookedusers in the module context.
        $this->setUser($env['users']['editingteacher']);
        answersrestriction::reset_static_cache();
        $this->assertEqualsCanonicalizing($expected, $this->booked_userids_of_option($env['settings']->id));
    }

    /**
     * In the aggregated instance scope the restriction is applied inside the grouped query,
     * so the answers count only contains the answers of the own team.
     *
     * @covers \mod_booking\booking_answers\scopes\instance::return_sql_for_booked_users
     */
    public function test_answerscount_of_aggregated_scope_is_restricted(): void {
        global $DB;

        $env = $this->setup_booking_environment();
        $this->activate_restriction();
        $cmid = (int) $env['settings']->cmid;

        $scope = new instance();

        $this->setAdminUser();
        answersrestriction::reset_static_cache();
        [$fields, $from, $where, $params] = $scope->return_sql_for_booked_users('instance', $cmid, 0);
        $rows = $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params);
        $this->assertEquals(3, (int) reset($rows)->answerscount);

        $this->setUser($env['users']['supervisor1']);
        answersrestriction::reset_static_cache();
        [$fields, $from, $where, $params] = $scope->return_sql_for_booked_users('instance', $cmid, 0);
        $rows = $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params);
        $this->assertEquals(2, (int) reset($rows)->answerscount);
    }

    /**
     * The main table of the legacy report.php builds its own sql and adds the restriction to
     * $addsqlwhere. This verifies that the clause works with the aliases used there.
     *
     * @covers \mod_booking\booking_answers\scope_base::get_answers_restriction_sql
     */
    public function test_restriction_works_with_the_report_php_query(): void {
        global $DB;

        $env = $this->setup_booking_environment();
        $this->activate_restriction();
        $optionid = (int) $env['settings']->id;

        $this->setUser($env['users']['supervisor1']);
        answersrestriction::reset_static_cache();

        // The where part of report.php, including the restriction it appends to $addsqlwhere.
        $sqlvalues = ['optionid' => $optionid];
        $scope = (new \mod_booking\booking_answers\booking_answers())->return_class_for_scope('option');
        $addsqlwhere = $scope->get_answers_restriction_sql('ba.userid', $optionid, $sqlvalues);

        $rows = $DB->get_records_sql(
            "SELECT ba.id, ba.userid
             FROM {booking_answers} ba
             JOIN {user} u ON u.id = ba.userid
             JOIN {booking_options} bo ON bo.id = ba.optionid
             WHERE ba.optionid = :optionid AND ba.waitinglist < 2 $addsqlwhere",
            $sqlvalues
        );

        $this->assertEqualsCanonicalizing(
            [
                (int) $env['users']['student1']->id,
                (int) $env['users']['supervisor1']->id,
            ],
            array_map(fn($row) => (int) $row->userid, array_values($rows))
        );
    }

    /**
     * Helper: activates the setting and drops the static cache of the restriction.
     *
     * @return void
     */
    private function activate_restriction(): void {
        set_config('restricttrackertomyteam', 1, 'bookingextension_confirmation_supervisor');
        answersrestriction::reset_static_cache();
    }

    /**
     * Helper: userids of the booked users table of the option scope, as the current user sees them.
     *
     * @param int $optionid
     * @return int[]
     */
    private function booked_userids_of_option(int $optionid): array {
        global $DB;

        answersrestriction::reset_static_cache();

        $scope = new option();
        [$fields, $from, $where, $params] = $scope->return_sql_for_booked_users(
            'option',
            $optionid,
            MOD_BOOKING_STATUSPARAM_BOOKED
        );
        $rows = $DB->get_records_sql("SELECT $fields FROM $from WHERE $where", $params);

        return array_map(fn($row) => (int) $row->userid, array_values($rows));
    }

    /**
     * Creates the profile fields, the users, the booking option and the booked answers.
     *
     * @return array
     */
    private function setup_booking_environment(): array {
        global $CFG, $DB;

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
        $hr = $this->getDataGenerator()->create_user();
        $nobody = $this->getDataGenerator()->create_user();
        $editingteacher = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        // Student1 is supervised by supervisor1, deputy1 is the deputy of supervisor1.
        // Student2 has no supervisor at all.
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

        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($editingteacher->id, $course->id, 'editingteacher');

        // The supervisor is booked as well, so we can verify that they see their own answer.
        foreach ([$student1, $student2, $supervisor1] as $user) {
            $DB->insert_record('booking_answers', (object)[
                'bookingid' => (int) $booking->id,
                'optionid' => (int) $option->id,
                'userid' => (int) $user->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
                'places' => 1,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        return [
            'settings' => $settings,
            'users' => [
                'student1' => $student1,
                'student2' => $student2,
                'supervisor1' => $supervisor1,
                'deputy1' => $deputy1,
                'hr' => $hr,
                'nobody' => $nobody,
                'editingteacher' => $editingteacher,
            ],
        ];
    }

    /**
     * Helper: creates a custom user profile field.
     *
     * @param string $shortname
     * @param string $name
     * @return void
     */
    private function create_custom_profile_field(string $shortname, string $name): void {
        global $DB;

        if (!$DB->record_exists('user_info_category', ['name' => 'Test Category'])) {
            $cat = new stdClass();
            $cat->name = 'Test Category';
            $cat->sortorder = 1;
            $cat->id = $DB->insert_record('user_info_category', $cat);
        } else {
            $cat = $DB->get_record('user_info_category', ['name' => 'Test Category']);
        }

        $field = new stdClass();
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
        $field->param2 = 2048;

        $DB->insert_record('user_info_field', $field);
    }
}
