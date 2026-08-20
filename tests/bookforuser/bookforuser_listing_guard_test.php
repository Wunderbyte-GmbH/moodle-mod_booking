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
 * Guard tests for the stateless actforuser listing path (issue #899 refactor).
 *
 * These tests protect the listing/cashier path (shortcodes::init_table_for_courses
 * -> bookingoptions_wbtable->foruserid -> col_booknow), which is used by USI and
 * the cashier and must NOT change during the fixes of
 * Wunderbyte-GmbH/Wunderbyte-GmbH#2191/#2214. Since #2214 all columns of a row
 * resolve their target user from the stateless foruserid - one source of truth.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_system;
use mod_booking\table\bookingoptions_wbtable;
use mod_booking\tests\booking_advanced_testcase;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Guard tests for actforuser resolution and the col_booknow/col_text discrepancy.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bookforuser_listing_guard_test extends booking_advanced_testcase {
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
     * @return array env with keys option, cmid, supervisor, employee
     */
    private function create_env(): array {
        global $PAGE;

        // The option generator runs the full fields pipeline, which needs an admin user.
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $supervisor = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($supervisor->id, $course->id, 'editingteacher');
        $employee = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($employee->id, $course->id, 'student');

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Listing Guard Booking',
            'eventtype' => 'Test',
            'bookingmanager' => $teacher->username,
        ]);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Listing Guard Option',
        ]);

        // The col_text method builds a returnurl from the current page.
        $PAGE->set_url('/');

        return [
            'option' => $option,
            'cmid' => $booking->cmid,
            'supervisor' => $supervisor,
            'employee' => $employee,
        ];
    }

    /**
     * Grants mod/booking:bookforothers at system level to the given user.
     *
     * @param int $userid
     * @return void
     */
    private function grant_bookforothers(int $userid): void {
        $syscontext = context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:bookforothers', CAP_ALLOW, $roleid, $syscontext->id);
        role_assign($roleid, $userid, $syscontext->id);
    }

    /**
     * Without bookforothers/cashier capability the url param must NOT be resolved.
     *
     * This is the security gate of the stateless actforuser path
     * (shortcodes.php, init_table_for_courses) used by USI listings and the cashier.
     *
     * @covers \mod_booking\shortcodes::init_table_for_courses
     */
    public function test_foruserid_not_resolved_without_capability(): void {
        $env = $this->create_env();
        $this->setUser($env['supervisor']);
        $_GET['userid'] = (string)$env['employee']->id;

        $table = shortcodes::init_table_for_courses(null, 'phase0guardnocap', ['urlparamforuserid' => 'userid']);

        $this->assertSame(0, $table->foruserid);
    }

    /**
     * With bookforothers capability the url param IS resolved into foruserid.
     *
     * @covers \mod_booking\shortcodes::init_table_for_courses
     */
    public function test_foruserid_resolved_with_bookforothers_capability(): void {
        $env = $this->create_env();
        $this->grant_bookforothers((int)$env['supervisor']->id);
        $this->setUser($env['supervisor']);
        $_GET['userid'] = (string)$env['employee']->id;

        $table = shortcodes::init_table_for_courses(null, 'phase0guardcap', ['urlparamforuserid' => 'userid']);

        $this->assertSame((int)$env['employee']->id, $table->foruserid);
    }

    /**
     * All columns of a row agree on the target user: col_booknow renders the
     * button for foruserid and col_text builds the optionview link for the same
     * foruserid (#2214 unified the former session-cache-based col_text).
     *
     * The col_booknow half of this assertion is the USI/cashier behaviour that
     * must survive unchanged.
     *
     * @covers \mod_booking\table\bookingoptions_wbtable::col_booknow
     * @covers \mod_booking\table\bookingoptions_wbtable::col_text
     */
    public function test_row_booknow_and_text_link_agree_on_target_user(): void {
        $env = $this->create_env();
        $this->grant_bookforothers((int)$env['supervisor']->id);
        $this->setUser($env['supervisor']);

        $table = new bookingoptions_wbtable('phase0agree', (int)$env['employee']->id);
        $values = (object)['id' => $env['option']->id, 'text' => 'Listing Guard Option'];

        $booknow = $table->col_booknow($values);
        $this->assertStringContainsString('data-userid="' . $env['employee']->id . '"', $booknow);

        $text = $table->col_text($values);
        $this->assertStringContainsString('userid=' . $env['employee']->id, $text);
    }
}
