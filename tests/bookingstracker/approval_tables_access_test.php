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
use context_system;
use local_wunderbyte_table\external\load_data;
use mod_booking\output\booked_users;
use mod_booking_generator;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * The lists of bookings to approve and of the bookings of the own team are built for the user
 * who opens them: the rows are the ones this user may see. Such a table is cached and reloaded
 * by its hash, so it must only be delivered to the user it was built for. Deputies get the same
 * list as the supervisor in a table of their own.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_answers\scopes\optionstoconfirm
 * @covers \mod_booking\booking_answers\scopes\supervisorteam
 *
 * @runInSeparateProcess
 * @runTestsInSeparateProcesses
 */
final class approval_tables_access_test extends advanced_testcase {
    /** @var stdClass[] Users of the test by name. */
    private array $users = [];

    /**
     * Tests set up: a team with supervisor and deputy and an option the team member asked for.
     */
    public function setUp(): void {
        global $CFG, $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        singleton_service::destroy_instance();

        if (!class_exists(\bookingextension_confirmation_supervisor\local\confirmbooking::class)) {
            $this->markTestSkipped('The booking extension for the approval by supervisors is not installed.');
        }
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->setAdminUser();

        foreach (['supervisor', 'deputy'] as $shortname) {
            $this->getDataGenerator()->create_custom_profile_field(
                ['shortname' => $shortname, 'name' => $shortname, 'datatype' => 'text']
            );
            set_config($shortname, $shortname, 'bookingextension_confirmation_supervisor');
        }
        set_config('confirmationsupervisorenabled', 1, 'bookingextension_confirmation_supervisor');

        $course = $this->getDataGenerator()->create_course();
        foreach (['teammember', 'colleague', 'supervisor', 'deputy', 'othersupervisor'] as $name) {
            $this->users[$name] = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($this->users[$name]->id, $course->id, 'student');
        }

        // The team member is supervised by the supervisor, who has a deputy. The colleague belongs to nobody.
        profile_save_data((object)[
            'id' => $this->users['teammember']->id,
            'profile_field_supervisor' => $this->users['supervisor']->id,
        ]);
        profile_save_data((object)[
            'id' => $this->users['supervisor']->id,
            'profile_field_deputy' => (string)$this->users['deputy']->id,
        ]);

        // The lists only contain options in which the user may read the responses.
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:readresponses', CAP_ALLOW, $roleid, context_system::instance()->id, true);
        foreach (['supervisor', 'deputy', 'othersupervisor'] as $name) {
            role_assign($roleid, $this->users[$name]->id, context_system::instance()->id);
        }

        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);

        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Option that needs an approval',
            'courseid' => $course->id,
            'chooseorcreatecourse' => 1,
            'waitforconfirmation' => 1,
            'confirmationsupervisorenabled' => 1,
        ]);

        // Team member and colleague both wait for the approval and both have a booking in a second option.
        $bookedoption = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Booked option',
            'courseid' => $course->id,
            'chooseorcreatecourse' => 1,
            'waitforconfirmation' => 1,
            'confirmationsupervisorenabled' => 1,
        ]);
        foreach (['teammember', 'colleague'] as $name) {
            foreach (
                [
                    [$option->id, MOD_BOOKING_STATUSPARAM_WAITINGLIST],
                    [$bookedoption->id, MOD_BOOKING_STATUSPARAM_BOOKED],
                ] as [$optionid, $status]
            ) {
                $DB->insert_record('booking_answers', (object)[
                    'bookingid' => (int)$booking->id,
                    'optionid' => (int)$optionid,
                    'userid' => (int)$this->users[$name]->id,
                    'waitinglist' => $status,
                    'places' => 1,
                    'timecreated' => time(),
                    'timemodified' => time(),
                ]);
            }
        }

        \cache::make('local_wunderbyte_table', 'cachedrawdata')->purge();
        \cache::make('mod_booking', 'bookedusertable')->purge();
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
     * Render the table of a scope as the current user, like the shortcode does.
     *
     * @param string $scope
     * @param int $statusparam
     * @return array hash of the cached table and the ids of the users in its rows
     */
    private function render_table(string $scope, int $statusparam): array {
        $table = (new booked_users($scope))->return_raw_table($scope, 0, $statusparam);
        $this->assertNotNull($table, "No table for scope $scope.");
        $this->assertNotEmpty($table->tablecachehash, "The table of scope $scope was not cached.");
        $userids = array_values(array_map(fn($row) => (int)$row->userid, $table->rawdata));
        sort($userids);
        return [$table->tablecachehash, $userids];
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
     * The table of a supervisor is delivered to the supervisor only; the deputy sees the same
     * rows in an own table.
     *
     * @dataProvider scope_provider
     * @param string $scope
     * @param int $statusparam
     */
    public function test_table_is_only_delivered_to_the_user_it_was_built_for(string $scope, int $statusparam): void {
        $teammemberid = (int)$this->users['teammember']->id;

        $this->setUser($this->users['supervisor']);
        [$supervisorhash, $supervisorrows] = $this->render_table($scope, $statusparam);
        $this->assertSame([$teammemberid], $supervisorrows);
        $this->assertSame(1, $this->count_loaded_rows($supervisorhash));

        // The deputy gets the same list.
        $this->setUser($this->users['deputy']);
        [$deputyhash, $deputyrows] = $this->render_table($scope, $statusparam);
        $this->assertSame($supervisorrows, $deputyrows);
        $this->assertSame(1, $this->count_loaded_rows($deputyhash));

        // A supervisor of nobody has an empty list and does not get the table of the other team.
        $this->setUser($this->users['othersupervisor']);
        [$otherhash, $otherrows] = $this->render_table($scope, $statusparam);
        $this->assertSame([], $otherrows);
        $this->assertSame(0, $this->count_loaded_rows($otherhash));
        $this->assertSame(0, $this->count_loaded_rows($supervisorhash));

        // Neither does a user without any role in the approval.
        $this->setUser($this->users['colleague']);
        $this->assertSame(0, $this->count_loaded_rows($supervisorhash));

        // The table of the supervisor still works for the supervisor.
        $this->setUser($this->users['supervisor']);
        $this->assertSame(1, $this->count_loaded_rows($supervisorhash));
    }

    /**
     * The table of a user who sees the bookings of everybody is not delivered to other users.
     *
     * @dataProvider scope_provider
     * @param string $scope
     * @param int $statusparam
     */
    public function test_table_with_all_bookings_is_not_delivered_to_supervisors(string $scope, int $statusparam): void {
        $this->setAdminUser();
        [$adminhash, $adminrows] = $this->render_table($scope, $statusparam);
        $this->assertCount(2, $adminrows);
        $this->assertSame(2, $this->count_loaded_rows($adminhash));

        $this->setUser($this->users['supervisor']);
        $this->assertSame(0, $this->count_loaded_rows($adminhash));
    }

    /**
     * The scopes with their tables.
     *
     * @return array
     */
    public static function scope_provider(): array {
        return [
            'to approve' => ['optionstoconfirm', MOD_BOOKING_STATUSPARAM_WAITINGLIST],
            'to approve, reduced' => ['optionstoconfirmreduced', MOD_BOOKING_STATUSPARAM_WAITINGLIST],
            'team' => ['supervisorteam', MOD_BOOKING_STATUSPARAM_BOOKED],
            'team, reduced' => ['supervisorteamreduced', MOD_BOOKING_STATUSPARAM_BOOKED],
        ];
    }
}
