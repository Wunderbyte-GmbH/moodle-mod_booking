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

use advanced_testcase;
use context;
use context_course;
use context_module;
use context_system;
use local_wunderbyte_table\external\load_data;
use mod_booking\local\bookingworkflow\answersrestriction;
use mod_booking\output\booked_users;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The tables with the booked users are cached once and reloaded by their hash (paging, filters,
 * lazy loading). On this way the table itself has to check that the current user may see the
 * users of its scope, like the pages do that show the table.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_answers\scope_base
 *
 * @runInSeparateProcess
 * @runTestsInSeparateProcesses
 */
final class users_table_access_test extends advanced_testcase {
    /** @var stdClass[] Users of the test by name. */
    private array $users = [];

    /** @var int[] Scope ids by scope name. */
    private array $scopeids = [];

    /** @var context The module context of the booking instance. */
    private context $modulecontext;

    /**
     * Tests set up: a booked option and users with different rights.
     */
    public function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        singleton_service::destroy_instance();
        answersrestriction::reset_static_cache();

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        foreach (
            [
                'student1', 'student2', 'supervisor', 'otherteam', 'outsider', 'reportviewer',
                'subscriber', 'teacher', 'instancemanager', 'coursemanager', 'sysmanager',
            ] as $name
        ) {
            $this->users[$name] = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($this->users[$name]->id, $course->id, 'student');
        }

        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Option with booked users',
            'courseid' => $course->id,
            'maxanswers' => 5,
            'teachersforoption' => $this->users['teacher']->username,
            'optiondateid_1' => '0',
            'daystonotify_1' => '0',
            'coursestarttime_1' => strtotime('now + 1 day'),
            'courseendtime_1' => strtotime('now + 2 day'),
        ]);
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        foreach (['student1', 'student2', 'supervisor'] as $name) {
            $DB->insert_record('booking_answers', (object)[
                'bookingid' => (int)$booking->id,
                'optionid' => (int)$option->id,
                'userid' => (int)$this->users[$name]->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
                'places' => 1,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        $this->modulecontext = context_module::instance($settings->cmid);
        $coursecontext = context_course::instance($course->id);
        $systemcontext = context_system::instance();

        // The rights the pages ask for, each in the context of its scope.
        $this->allow('reportviewer', ['mod/booking:viewreports'], $this->modulecontext);
        $this->allow('subscriber', ['mod/booking:bookforothers', 'mod/booking:subscribeusers'], $this->modulecontext);
        $this->allow('instancemanager', ['mod/booking:managebookedusers'], $this->modulecontext);
        $this->allow('coursemanager', ['mod/booking:managebookedusers'], $coursecontext);
        $this->allow('sysmanager', ['mod/booking:managebookedusers'], $systemcontext);

        $optiondateid = (int)$DB->get_field('booking_optiondates', 'id', ['optionid' => $option->id], MUST_EXIST);
        $this->scopeids = [
            'option' => (int)$option->id,
            'optiondate' => $optiondateid,
            'instance' => (int)$settings->cmid,
            'instanceanswers' => (int)$settings->cmid,
            'course' => (int)$course->id,
            'courseanswers' => (int)$course->id,
            'system' => 0,
            'systemanswers' => 0,
        ];

        \cache::make('local_wunderbyte_table', 'cachedrawdata')->purge();
        \cache::make('mod_booking', 'bookedusertable')->purge();
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
     * Give a user capabilities in a context.
     *
     * @param string $username
     * @param string[] $capabilities
     * @param context $context
     * @return void
     */
    private function allow(string $username, array $capabilities, context $context): void {
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $this->users[$username]->id, $context->id);
    }

    /**
     * Switch the user of the test.
     *
     * @param string $username name in $this->users, admin for the administrator
     * @return void
     */
    private function switch_user(string $username): void {
        if ($username === 'admin') {
            $this->setAdminUser();
        } else {
            $this->setUser($this->users[$username]);
        }
        answersrestriction::reset_static_cache();
    }

    /**
     * Render the table with the booked users of a scope as the current user, like a page does,
     * and return the hash the browser uses to reload it.
     *
     * @param string $scope
     * @return string
     */
    private function render_table_and_get_hash(string $scope): string {
        $table = (new booked_users())->return_raw_table($scope, $this->scopeids[$scope], MOD_BOOKING_STATUSPARAM_BOOKED);
        $this->assertNotNull($table, "No table for scope $scope.");
        $this->assertNotEmpty($table->tablecachehash, "The table of scope $scope was not cached.");
        return $table->tablecachehash;
    }

    /**
     * Number of rows the data webservice delivers to the current user for a cached table.
     *
     * @param string $hash
     * @return int
     */
    private function count_loaded_rows(string $hash): int {
        $result = load_data::execute($hash, 0, '', '', '', 0, 0, '', '');
        $content = json_decode($result['content'], true);
        return count($content['table']['rows'] ?? []);
    }

    /**
     * Every scope delivers its cached table only to the users who may open the scope on the page.
     */
    public function test_cached_tables_are_only_delivered_to_users_of_their_scope(): void {
        $expectations = [
            'option' => [
                'allowed' => ['admin', 'reportviewer', 'subscriber', 'teacher'],
                'denied' => ['outsider', 'student1'],
            ],
            'optiondate' => [
                'allowed' => ['admin', 'reportviewer', 'teacher'],
                'denied' => ['outsider', 'student1'],
            ],
            'instance' => [
                'allowed' => ['admin', 'instancemanager', 'coursemanager', 'sysmanager'],
                'denied' => ['outsider', 'reportviewer', 'teacher'],
            ],
            'instanceanswers' => [
                'allowed' => ['admin', 'instancemanager', 'coursemanager', 'sysmanager'],
                'denied' => ['outsider', 'reportviewer', 'teacher'],
            ],
            'course' => [
                'allowed' => ['admin', 'coursemanager', 'sysmanager'],
                'denied' => ['outsider', 'instancemanager'],
            ],
            'courseanswers' => [
                'allowed' => ['admin', 'coursemanager', 'sysmanager'],
                'denied' => ['outsider', 'instancemanager'],
            ],
            'system' => [
                'allowed' => ['admin', 'sysmanager'],
                'denied' => ['outsider', 'instancemanager', 'coursemanager'],
            ],
            'systemanswers' => [
                'allowed' => ['admin', 'sysmanager'],
                'denied' => ['outsider', 'instancemanager', 'coursemanager'],
            ],
        ];

        foreach ($expectations as $scope => $expectation) {
            $this->switch_user('admin');
            $hash = $this->render_table_and_get_hash($scope);

            foreach ($expectation['allowed'] as $username) {
                $this->switch_user($username);
                $this->assertGreaterThan(
                    0,
                    $this->count_loaded_rows($hash),
                    "User $username has to get the table of scope $scope."
                );
            }
            foreach ($expectation['denied'] as $username) {
                $this->switch_user($username);
                $this->assertSame(
                    0,
                    $this->count_loaded_rows($hash),
                    "User $username must not get the table of scope $scope."
                );
            }
        }
    }

    /**
     * Users who only see the booked users of their team do not get the table that was built for
     * users who see everybody, and nobody gets the table of another team.
     */
    public function test_table_of_another_audience_is_not_delivered_to_restricted_users(): void {
        global $CFG;

        if (!class_exists(\bookingextension_confirmation_supervisor\local\answersrestriction::class)) {
            $this->markTestSkipped('The booking extension that restricts supervisors to their team is not installed.');
        }
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->switch_user('admin');
        $this->getDataGenerator()->create_custom_profile_field(
            ['shortname' => 'supervisor', 'name' => 'Supervisor', 'datatype' => 'text']
        );
        set_config('supervisor', 'supervisor', 'bookingextension_confirmation_supervisor');
        set_config('confirmationsupervisorenabled', 1, 'bookingextension_confirmation_supervisor');
        set_config('restricttrackertomyteam', 1, 'bookingextension_confirmation_supervisor');
        profile_save_data((object)[
            'id' => $this->users['student1']->id,
            'profile_field_supervisor' => $this->users['supervisor']->id,
        ]);

        // Both may open the report of the option, but only see the users of their own team there.
        $this->allow('supervisor', ['mod/booking:viewreports'], $this->modulecontext);
        $this->allow('otherteam', ['mod/booking:viewreports'], $this->modulecontext);

        // The table for users who see all booked users.
        $this->switch_user('admin');
        $fullhash = $this->render_table_and_get_hash('option');
        $this->assertSame(3, $this->count_loaded_rows($fullhash));

        // The supervisor gets an own table with the own team (and the own booking).
        $this->switch_user('supervisor');
        $teamhash = $this->render_table_and_get_hash('option');
        $this->assertNotSame($fullhash, $teamhash);
        $this->assertSame(2, $this->count_loaded_rows($teamhash));

        // The table with all users is not delivered to the supervisor.
        $this->assertSame(0, $this->count_loaded_rows($fullhash));

        // A user with another team does not get the table of the supervisor.
        $this->switch_user('otherteam');
        $this->assertSame(0, $this->count_loaded_rows($teamhash));
        $this->assertSame(0, $this->count_loaded_rows($fullhash));
    }
}
