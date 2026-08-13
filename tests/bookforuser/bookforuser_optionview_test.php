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
 * Characterization tests: which user the option detail view (OPTIONVIEW) targets.
 *
 * IMPORTANT: These tests document CURRENT behaviour, including known defects,
 * as a safety net before the planned fix. See Wunderbyte-GmbH/Wunderbyte-GmbH#2191
 * (detailed analysis) and Wunderbyte-GmbH/moodle-taskflowadapter_tuines#154
 * (customer report). The MOD_BOOKING_DESCRIPTION_OPTIONVIEW branch of
 * bookingoption_description currently ignores the $user object resolved by
 * optionview.php and re-reads the session-global bookforuser cache instead.
 * When Phase 1 of #2191 changes this, the assertions marked "documents ..."
 * below must be updated deliberately in the same commit as the fix.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use cache;
use mod_booking\output\bookingoption_description;
use mod_booking\tests\booking_advanced_testcase;
use tool_mocktesttime\time_mock;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Characterization tests for the OPTIONVIEW buy-for-user resolution.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bookforuser_optionview_test extends booking_advanced_testcase {
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
     * Creates course, booking instance, option and the users used by these tests.
     *
     * @return array env with keys option, cmid, viewer, employee
     */
    private function create_env(): array {
        global $PAGE;

        // The option generator runs the full fields pipeline, which needs an admin user.
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $viewer = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($viewer->id, $course->id, 'student');
        $employee = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($employee->id, $course->id, 'student');

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Bookforuser Test Booking',
            'eventtype' => 'Test',
            'bookingmanager' => $teacher->username,
        ]);

        /** @var \mod_booking_generator $plugingenerator */
        $plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $plugingenerator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Bookforuser Test Option',
        ]);

        // The OPTIONVIEW rendering path may build return urls from the current page.
        $PAGE->set_url('/');

        return [
            'option' => $option,
            'cmid' => $booking->cmid,
            'viewer' => $viewer,
            'employee' => $employee,
        ];
    }

    /**
     * Renders the detail view (OPTIONVIEW) as it is done by optionview.php and
     * returns the bookit section HTML.
     *
     * @param int $optionid
     * @param object|null $user the user object optionview.php passes into the constructor
     * @return string bookitsection HTML
     */
    private function render_optionview_bookitsection(int $optionid, ?object $user): string {
        $description = new bookingoption_description(
            $optionid,
            null,
            MOD_BOOKING_DESCRIPTION_OPTIONVIEW,
            true,
            null,
            $user,
            true
        );
        $returnarray = $description->get_returnarray();
        return $returnarray['bookitsection'] ?? '';
    }

    /**
     * Without any cache entry, the detail view targets the acting user.
     *
     * @covers \mod_booking\output\bookingoption_description::get_returnarray
     */
    public function test_detail_view_targets_acting_user_without_cache_entry(): void {
        $env = $this->create_env();
        $this->setUser($env['viewer']);

        $bookitsection = $this->render_optionview_bookitsection((int)$env['option']->id, null);

        $this->assertStringContainsString('data-userid="' . $env['viewer']->id . '"', $bookitsection);
    }

    /**
     * A stale (expired) bookforuser entry redirects the detail view to the leaked
     * user, even though an explicit $user object was passed into the constructor.
     *
     * This is exactly the cross-tab scenario from taskflowadapter_tuines#154:
     * the supervisor's unrelated tab renders the buy button for the employee.
     * The #360 for-user label (name + ID under the button) must be present, so at
     * least the wrong target is VISIBLE - that visibility guarantee must survive
     * any future fix.
     *
     * @covers \mod_booking\output\bookingoption_description::get_returnarray
     */
    public function test_detail_view_ignores_passed_user_when_stale_cache_entry_exists(): void {
        $env = $this->create_env();
        $this->setUser($env['viewer']);

        $cache = cache::make('mod_booking', 'bookforuser');
        $cache->set('bookforuser', [(int)$env['employee']->id, time() - 1]);

        // Documents CURRENT behaviour: the passed $user (the acting viewer, as resolved
        // by optionview.php) is ignored, the leaked cache entry wins.
        $bookitsection = $this->render_optionview_bookitsection(
            (int)$env['option']->id,
            \core_user::get_user($env['viewer']->id)
        );

        $this->assertStringContainsString('data-userid="' . $env['employee']->id . '"', $bookitsection);
        // The for-user label (issue Moodle-local_taskflow#360) makes the foreign target visible.
        $this->assertStringContainsString('(ID:' . $env['employee']->id . ')', $bookitsection);
    }

    /**
     * A fresh, VALID override is currently discarded on the detail view.
     *
     * This documents the inverted expiry check at rendering level: the flow
     * local_taskflow intends (set_bookforuser() right before the supervisor opens
     * the option) does NOT reach the button within the validity window.
     *
     * @covers \mod_booking\output\bookingoption_description::get_returnarray
     */
    public function test_detail_view_discards_valid_override_documents_inverted_expiry(): void {
        $env = $this->create_env();
        $this->setUser($env['viewer']);

        price::set_bookforuser((int)$env['employee']->id);

        $bookitsection = $this->render_optionview_bookitsection((int)$env['option']->id, null);

        // CURRENT (buggy) behaviour: valid override discarded, acting user targeted.
        $this->assertStringContainsString('data-userid="' . $env['viewer']->id . '"', $bookitsection);
    }
}
