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
 * Tests for the read / access capabilities of mod_booking (capability table 1a).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_course;
use context_module;
use context_system;
use mod_booking\booking_answers\booking_answers;
use mod_booking\booking_answers\scopes\option as optionscope;
use mod_booking\local\bookingstracker\columns_helper;
use mod_booking\local\bookingstracker\report2_access;
use mod_booking\local\report_access;
use mod_booking\tests\capability_testcase;
use required_capability_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * Every row of the "read / access capabilities" table: the documented default
 * (context level + archetypes) and, where the gate is reachable from a class,
 * that the capability really unlocks what the table claims.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class read_access_capabilities_test extends capability_testcase {
    /**
     * Context level and archetype defaults of the read / access capabilities.
     *
     * @return array
     */
    public static function capability_default_provider(): array {
        return [
            'view' => [
                'mod/booking:view',
                CONTEXT_MODULE,
                ['guest', 'student', 'teacher', 'editingteacher', 'manager'],
            ],
            'readresponses' => [
                'mod/booking:readresponses',
                CONTEXT_MODULE,
                ['teacher', 'editingteacher', 'manager'],
            ],
            'viewreports' => [
                'mod/booking:viewreports',
                CONTEXT_MODULE,
                ['manager'],
            ],
            'managebookedusers' => [
                'mod/booking:managebookedusers',
                CONTEXT_MODULE,
                ['editingteacher', 'manager'],
            ],
            'downloadresponses' => [
                'mod/booking:downloadresponses',
                CONTEXT_MODULE,
                ['teacher', 'editingteacher', 'manager'],
            ],
            'canseenumberofbookings' => [
                'mod/booking:canseenumberofbookings',
                CONTEXT_SYSTEM,
                ['manager'],
            ],
        ];
    }

    /**
     * The capabilities are defined on the documented context level with the
     * documented archetype defaults.
     *
     * @param string $capability
     * @param int $contextlevel
     * @param array $archetypes
     * @dataProvider capability_default_provider
     * @covers \mod_booking\tests\capability_testcase::assert_capability_default
     */
    public function test_capability_defaults(string $capability, int $contextlevel, array $archetypes): void {
        $this->assert_capability_default($capability, $contextlevel, $archetypes);
    }

    /**
     * mod/booking:view unlocks seeing the instance / the option details.
     *
     * @covers \mod_booking\booking_option::can_view_option_details
     */
    public function test_view_unlocks_the_option_details(): void {
        $this->create_option();
        set_config('bookonlyondetailspage', 0, 'booking');
        $optionid = (int)$this->settings->id;

        $without = $this->user_with([]);
        $this->assertFalse(booking_option::can_view_option_details($optionid, (int)$without->id));

        $with = $this->user_with(['mod/booking:view']);
        $this->assertTrue(booking_option::can_view_option_details($optionid, (int)$with->id));
    }

    /**
     * mod/booking:readresponses (the fallback gate of report.php) and
     * mod/booking:viewreports each open report.php and the option scope of
     * report2.php.
     *
     * report.php itself is a page script and cannot be loaded from PHPUnit,
     * so its entry rule was extracted into report_access - the same split
     * report2_access already is for report2.php.
     *
     * @covers \mod_booking\local\report_access::has_report_access
     * @covers \mod_booking\local\bookingstracker\report2_access::has_option_scope_access
     */
    public function test_readresponses_and_viewreports_unlock_the_reports(): void {
        $this->create_option();
        $cmid = (int)$this->settings->cmid;
        $optionid = (int)$this->settings->id;

        $this->user_with([]);
        $this->assertFalse(report_access::has_report_access($cmid, $optionid));
        $this->assertFalse(report2_access::has_option_scope_access($cmid, $optionid));

        $this->user_with(['mod/booking:readresponses']);
        $this->assertTrue(report_access::has_report_access($cmid, $optionid));
        $this->assertTrue(report2_access::has_option_scope_access($cmid, $optionid));

        $this->user_with(['mod/booking:viewreports']);
        $this->assertTrue(report_access::has_report_access($cmid, $optionid));
        $this->assertTrue(report2_access::has_option_scope_access($cmid, $optionid));
    }

    /**
     * Without any of the three ways in, report.php throws the readresponses
     * capability exception - the behaviour of the inline check it replaced.
     *
     * @covers \mod_booking\local\report_access::require_report_access
     */
    public function test_report_entry_requires_readresponses(): void {
        $this->create_option();
        $cmid = (int)$this->settings->cmid;
        $optionid = (int)$this->settings->id;

        $this->user_with([]);
        try {
            report_access::require_report_access($cmid, $optionid);
            $this->fail('Users without any report capability must not enter report.php.');
        } catch (required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
            $this->assertStringContainsString(
                get_capability_string('mod/booking:readresponses'),
                $e->getMessage()
            );
        }

        $this->user_with(['mod/booking:readresponses']);
        report_access::require_report_access($cmid, $optionid);
    }

    /**
     * mod/booking:managebookedusers unlocks the aggregated scopes of
     * report2.php - each one checked in its own context, so the assignment
     * level decides which scopes open.
     *
     * @covers \mod_booking\local\bookingstracker\report2_access::has_instance_scope_access
     * @covers \mod_booking\local\bookingstracker\report2_access::has_course_scope_access
     * @covers \mod_booking\local\bookingstracker\report2_access::has_system_scope_access
     */
    public function test_managebookedusers_unlocks_the_tracker_scopes(): void {
        $this->create_option();
        $cmid = (int)$this->settings->cmid;
        $courseid = (int)$this->course->id;

        $this->user_with([]);
        $this->assertFalse(report2_access::has_instance_scope_access($cmid));

        // Module level assignment: only the instance scope of this module.
        $this->user_with(['mod/booking:managebookedusers'], context_module::instance($cmid));
        $this->assertTrue(report2_access::has_instance_scope_access($cmid));
        $this->assertFalse(report2_access::has_course_scope_access($courseid));
        $this->assertFalse(report2_access::has_system_scope_access());

        // Global assignment: instance, course and system scope.
        $this->user_with(['mod/booking:managebookedusers'], context_system::instance());
        $this->assertTrue(report2_access::has_instance_scope_access($cmid));
        $this->assertTrue(report2_access::has_course_scope_access($courseid));
        $this->assertTrue(report2_access::has_system_scope_access());
    }

    /**
     * mod/booking:downloadresponses unlocks the download button of the table.
     *
     * @covers \mod_booking\booking_answers\scopes\option::show_download_button
     */
    public function test_downloadresponses_unlocks_the_download_button(): void {
        $this->create_option();
        $optionid = (int)$this->settings->id;

        $this->user_with([]);
        $this->assertFalse((bool)($this->booked_users_table($optionid, 'without')->showdownloadbutton ?? false));

        $this->user_with(['mod/booking:downloadresponses']);
        $this->assertTrue((bool)($this->booked_users_table($optionid, 'with')->showdownloadbutton ?? false));
    }

    /**
     * mod/booking:canseenumberofbookings unlocks the real booking place
     * counts: without it (and with the info texts turned on) only the
     * availability info text is shown.
     *
     * @covers \mod_booking\booking_answers\booking_answers::add_availability_info_texts_to_booking_information
     */
    public function test_canseenumberofbookings_unlocks_the_place_counts(): void {
        $this->create_option();
        set_config('bookingplacesinfotexts', 1, 'booking');

        $this->user_with([]);
        $information = ['maxanswers' => 10, 'freeonlist' => 5];
        booking_answers::add_availability_info_texts_to_booking_information($information);
        $this->assertTrue(
            $information['showbookingplacesinfotext'] ?? false,
            'Without the capability the place counts are replaced by an info text.'
        );

        $this->user_with(['mod/booking:canseenumberofbookings'], context_system::instance());
        $information = ['maxanswers' => 10, 'freeonlist' => 5];
        booking_answers::add_availability_info_texts_to_booking_information($information);
        $this->assertArrayNotHasKey(
            'showbookingplacesinfotext',
            $information,
            'With the capability the real number of booked places stays visible.'
        );
    }

    /**
     * moodle/site:viewuseridentity unlocks the identity columns (institution,
     * idnumber) of the tracker.
     *
     * @covers \mod_booking\local\bookingstracker\columns_helper::download_columns
     */
    public function test_viewuseridentity_unlocks_the_identity_columns(): void {
        global $DB;

        $this->create_option();
        $cmid = (int)$this->settings->cmid;
        $DB->set_field('booking', 'reportfields', 'fullname,institution', ['id' => $this->booking->id]);
        singleton_service::destroy_instance();
        \cache::make('mod_booking', 'cachedbookinginstances')->purge();

        $this->user_with([]);
        $this->assertArrayNotHasKey('institution', columns_helper::download_columns($cmid));

        $this->user_with(['moodle/site:viewuseridentity']);
        $this->assertArrayHasKey('institution', columns_helper::download_columns($cmid));
    }

    /**
     * moodle/site:accessallgroups bypasses the separate groups filter. The
     * only implementation is inline in report.php (see report.php:810 and
     * report.php:1527), so this only asserts the check report.php performs -
     * report2.php has no such bypass.
     *
     * @covers \mod_booking\tests\capability_testcase::user_with
     */
    public function test_accessallgroups_is_checked_in_the_course_context(): void {
        $this->create_option();
        $coursecontext = context_course::instance((int)$this->course->id);

        $this->user_with([]);
        $this->assertFalse(has_capability('moodle/site:accessallgroups', $coursecontext));

        $this->user_with(['moodle/site:accessallgroups'], $coursecontext);
        $this->assertTrue(has_capability('moodle/site:accessallgroups', $coursecontext));
    }

    /**
     * Helper: the booked users table of the option scope.
     *
     * @param int $optionid
     * @param string $prefix unique table name prefix
     * @return \local_wunderbyte_table\wunderbyte_table
     */
    private function booked_users_table(int $optionid, string $prefix) {
        $scope = new optionscope();
        return $scope->return_users_table(
            'option',
            $optionid,
            MOD_BOOKING_STATUSPARAM_BOOKED,
            'captest' . $prefix,
            ['firstname'],
            [get_string('firstname')]
        );
    }
}
