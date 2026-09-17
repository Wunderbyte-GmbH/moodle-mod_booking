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
 * Tests for the allowedtobookforuser availability condition.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_system;
use mod_booking\bo_availability\bo_info;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\tests\booking_advanced_testcase;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for the allowedtobookforuser availability condition.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\bo_availability\conditions\allowedtobookforuser
 */
final class condition_allowedtobookforuser_test extends booking_advanced_testcase {
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
     * Tests tear down.
     */
    public function tearDown(): void {
        unset($_GET['userid']);
        parent::tearDown();
    }

    /**
     * Creates course, booking instance, option and users.
     *
     * @param array $optiondata additional option data
     * @return array
     */
    private function create_env(array $optiondata = []): array {
        global $PAGE;

        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $users = [];
        foreach (['agent', 'employee', 'stranger', 'other'] as $key) {
            $users[$key] = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($users[$key]->id, $course->id, 'student');
        }
        $bookingmanager = $this->getDataGenerator()->create_user();

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ]);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)array_merge([
            'bookingid' => $booking->id,
            'text' => 'Allowed to book for user option',
            'courseid' => $course->id,
        ], $optiondata));

        $PAGE->set_url('/');

        return ['option' => $option, 'users' => $users, 'generator' => $plugingenerator];
    }

    /**
     * Grants a capability at system level to the given user.
     *
     * @param string $capability
     * @param int $userid
     * @return void
     */
    private function grant(string $capability, int $userid): void {
        $syscontext = context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($capability, CAP_ALLOW, $roleid, $syscontext->id);
        role_assign($roleid, $userid, $syscontext->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Returns the id of the blocking condition for the given option and user.
     *
     * @param int $optionid
     * @param int $userid
     * @return array [$id, $isavailable, $description]
     */
    private function blocking(int $optionid, int $userid): array {
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $boinfo = new bo_info($settings);
        return $boinfo->is_available($optionid, $userid, false);
    }

    /**
     * Booking for oneself is not affected by the condition.
     */
    public function test_booking_for_oneself_is_allowed(): void {
        $env = $this->create_env();
        $agent = $env['users']['agent'];
        $this->setUser($agent);

        $results = bo_info::get_condition_results($env['option']->id, (int)$agent->id);
        $this->assertArrayNotHasKey(MOD_BOOKING_BO_COND_ALLOWEDTOBOOKFORUSER, $results);

        [$id, , ] = $this->blocking($env['option']->id, (int)$agent->id);
        $this->assertSame(MOD_BOOKING_BO_COND_BOOKITBUTTON, $id);
    }

    /**
     * A plain user is not allowed to book for another user and gets a label with the name.
     */
    public function test_plain_user_is_blocked_for_other_user(): void {
        $env = $this->create_env();
        $this->setUser($env['users']['agent']);
        $employee = $env['users']['employee'];

        [$id, $isavailable, $description] = $this->blocking($env['option']->id, (int)$employee->id);

        $this->assertSame(MOD_BOOKING_BO_COND_ALLOWEDTOBOOKFORUSER, $id);
        $this->assertFalse($isavailable);
        $this->assertStringContainsString(fullname($employee), $description);

        // It is also a hard block, so the booking itself is prevented.
        $settings = singleton_service::get_instance_of_booking_option_settings($env['option']->id);
        [$hardid, , ] = (new bo_info($settings))->is_available($env['option']->id, (int)$employee->id, true);
        $this->assertSame(MOD_BOOKING_BO_COND_ALLOWEDTOBOOKFORUSER, $hardid);
    }

    /**
     * Real restrictions (higher ids) take precedence over the label.
     */
    public function test_real_restriction_takes_precedence(): void {
        $env = $this->create_env(['maxanswers' => 1, 'maxoverbooking' => 0]);
        $env['generator']->create_answer(['optionid' => $env['option']->id, 'userid' => $env['users']['other']->id]);

        $this->setUser($env['users']['agent']);
        [$id, , ] = $this->blocking($env['option']->id, (int)$env['users']['employee']->id);

        $this->assertGreaterThan(MOD_BOOKING_BO_COND_ALLOWEDTOBOOKFORUSER, $id);
        $this->assertNotSame(MOD_BOOKING_BO_COND_ALLOWEDTOBOOKFORUSER, $id);
    }

    /**
     * Users with bookforothers may book for anybody.
     */
    public function test_bookforothers_is_allowed(): void {
        $env = $this->create_env();
        $this->grant('mod/booking:bookforothers', (int)$env['users']['agent']->id);
        $this->setUser($env['users']['agent']);

        $results = bo_info::get_condition_results($env['option']->id, (int)$env['users']['employee']->id);
        $this->assertArrayNotHasKey(MOD_BOOKING_BO_COND_ALLOWEDTOBOOKFORUSER, $results);
    }

    /**
     * Cashiers may book for anybody, even without bookforothers.
     */
    public function test_cashier_is_allowed(): void {
        if (!class_exists('local_shopping_cart\shopping_cart')) {
            $this->markTestSkipped('local_shopping_cart is not installed.');
        }
        $env = $this->create_env();
        $this->grant('local/shopping_cart:cashier', (int)$env['users']['agent']->id);
        $this->setUser($env['users']['agent']);

        $results = bo_info::get_condition_results($env['option']->id, (int)$env['users']['employee']->id);
        $this->assertArrayNotHasKey(MOD_BOOKING_BO_COND_ALLOWEDTOBOOKFORUSER, $results);
    }

    /**
     * Supervisors (bookmyteam) may book for their team only. In a shortcode listing
     * the target user is resolved and the label is rendered for foreign users.
     */
    public function test_supervisor_team_and_shortcode_label(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        if (!\core_component::get_component_directory('bookingextension_confirmation_supervisor')) {
            $this->markTestSkipped('bookingextension_confirmation_supervisor is not installed.');
        }

        $env = $this->create_env();
        $supervisor = $env['users']['agent'];
        $employee = $env['users']['employee'];
        $stranger = $env['users']['stranger'];

        $DB->insert_record('user_info_field', (object)[
            'shortname' => 'supervisor',
            'name' => 'Supervisor',
            'datatype' => 'text',
            'categoryid' => 1,
            'sortorder' => 1,
        ]);
        set_config('confirmationsupervisorenabled', 1, 'bookingextension_confirmation_supervisor');
        set_config('supervisor', 'supervisor', 'bookingextension_confirmation_supervisor');
        // Profile data may be backfilled by observers, so we set it explicitly for both users.
        profile_save_data((object)['id' => $employee->id, 'profile_field_supervisor' => $supervisor->id]);
        profile_save_data((object)['id' => $stranger->id, 'profile_field_supervisor' => '']);

        $this->grant('mod/booking:bookmyteam', (int)$supervisor->id);
        $this->setUser($supervisor);

        $results = bo_info::get_condition_results($env['option']->id, (int)$employee->id);
        $this->assertArrayNotHasKey(MOD_BOOKING_BO_COND_ALLOWEDTOBOOKFORUSER, $results);

        [$id, , ] = $this->blocking($env['option']->id, (int)$stranger->id);
        $this->assertSame(MOD_BOOKING_BO_COND_ALLOWEDTOBOOKFORUSER, $id);

        // The shortcode resolves the target user for team bookers.
        $_GET['userid'] = (string)$stranger->id;
        $table = shortcodes::init_table_for_courses(null, 'allowedtobookforuser', ['urlparamforuserid' => 'userid']);
        $this->assertSame((int)$stranger->id, $table->foruserid);

        singleton_service::destroy_instance();
        $values = (object)['id' => $env['option']->id, 'text' => 'Allowed to book for user option'];
        $booknow = $table->col_booknow($values);
        $this->assertStringContainsString(
            get_string('bocondallowedtobookforusernotavailable', 'mod_booking', fullname($stranger)),
            $booknow
        );

        // For a team member, the regular button is rendered.
        singleton_service::destroy_instance();
        $teamtable = new bookingoptions_wbtable('allowedtobookforuserteam', (int)$employee->id);
        $booknow = $teamtable->col_booknow($values);
        $this->assertStringNotContainsString(
            get_string('bocondallowedtobookforusernotavailable', 'mod_booking', fullname($employee)),
            $booknow
        );
        $this->assertStringContainsString('data-userid="' . $employee->id . '"', $booknow);
    }
}
