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
 * Tests for the write capabilities on booking answers (capability table 1b).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_module;
use context_system;
use mod_booking\booking_answers\scopes\option as optionscope;
use mod_booking\output\booked_users;
use mod_booking\table\manageusers_table;
use mod_booking\tests\capability_testcase;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once("$CFG->dirroot/mod/booking/lib.php");

/**
 * Every row of the "write capabilities on booking answers" table: the
 * documented default (context level + archetypes) and, where the gate is
 * reachable from a class, that the capability really unlocks the action.
 *
 * The rows bookforothers, bookallstudents, bookanyone and bookmyteam are only
 * enforced inline in the page scripts (subscribeusers.php:88-89, 512,
 * bulk_book_handler.php:58, report.php:1045, report2.php:421), so for them
 * only the definition is asserted here.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class write_answer_capabilities_test extends capability_testcase {
    /**
     * Context level and archetype defaults of the write capabilities.
     *
     * @return array
     */
    public static function capability_default_provider(): array {
        return [
            'deleteresponses' => ['mod/booking:deleteresponses', CONTEXT_MODULE, ['editingteacher', 'manager']],
            'subscribeusers' => ['mod/booking:subscribeusers', CONTEXT_MODULE, ['editingteacher', 'manager']],
            'bookforothers' => ['mod/booking:bookforothers', CONTEXT_MODULE, ['editingteacher', 'manager']],
            'bookallstudents' => [
                'mod/booking:bookallstudents',
                CONTEXT_MODULE,
                ['teacher', 'editingteacher', 'manager'],
            ],
            'bookanyone' => ['mod/booking:bookanyone', CONTEXT_MODULE, ['manager']],
            'bookmyteam' => ['mod/booking:bookmyteam', CONTEXT_MODULE, ['editingteacher', 'manager']],
            'communicate' => ['mod/booking:communicate', CONTEXT_MODULE, ['teacher', 'editingteacher', 'manager']],
            'cansendmessages' => ['mod/booking:cansendmessages', CONTEXT_SYSTEM, ['editingteacher', 'manager']],
            'rate' => ['mod/booking:rate', CONTEXT_MODULE, ['teacher', 'editingteacher', 'manager']],
            'changecustomformofotherusers' => [
                'mod/booking:changecustomformofotherusers',
                CONTEXT_MODULE,
                ['manager'],
            ],
            'canoverbook' => ['mod/booking:canoverbook', CONTEXT_SYSTEM, ['editingteacher', 'manager']],
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
     * mod/booking:deleteresponses unlocks deleting bookings.
     *
     * @covers \mod_booking\table\manageusers_table::action_delete_checked_booking_answers
     */
    public function test_deleteresponses_unlocks_deleting_bookings(): void {
        global $DB;

        $this->create_option();
        $this->book_students(2);
        $answerids = array_keys($DB->get_records('booking_answers', ['optionid' => $this->settings->id], '', 'id'));
        $payload = json_encode(['checkedids' => $answerids]);

        $this->user_with([]);
        try {
            (new manageusers_table('capdelete1'))->action_delete_checked_booking_answers(-1, $payload);
            $this->fail('Deleting answers without deleteresponses must be rejected.');
        } catch (moodle_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->user_with(['mod/booking:deleteresponses']);
        $result = (new manageusers_table('capdelete2'))->action_delete_checked_booking_answers(-1, $payload);
        $this->assertEquals(1, $result['success']);
        $this->assertEquals(
            0,
            $DB->count_records_select(
                'booking_answers',
                'optionid = :optionid AND waitinglist < 2',
                ['optionid' => $this->settings->id]
            ),
            'With deleteresponses the bookings are really gone.'
        );
    }

    /**
     * mod/booking:communicate unlocks the custom message button,
     * mod/booking:subscribeusers the "transfer users" button of the tracker.
     *
     * @covers \mod_booking\booking_answers\scopes\option::return_users_table
     */
    public function test_communicate_and_subscribeusers_unlock_their_buttons(): void {
        $this->create_option();
        $custommessage = 'mod_booking\form\modal_send_custom_message';
        $transferusers = 'mod_booking\form\modal_transfer_users';

        $this->user_with([]);
        $formnames = $this->button_formnames('none');
        $this->assertNotContains($custommessage, $formnames);
        $this->assertNotContains($transferusers, $formnames);

        $this->user_with(['mod/booking:communicate']);
        $this->assertContains($custommessage, $this->button_formnames('communicate'));

        $this->user_with(['mod/booking:subscribeusers']);
        $this->assertContains($transferusers, $this->button_formnames('subscribe'));
    }

    /**
     * mod/booking:rate unlocks rating. The rating submit on report.php is
     * gated by the core capability moodle/rating:rate instead, which almost
     * every role holds by default - a plain student cannot rate according to
     * the plugin, but does hold the core capability.
     *
     * @covers ::booking_rating_permissions
     */
    public function test_rate_unlocks_rating_but_core_rating_capability_is_open(): void {
        $this->create_option();
        $contextid = context_module::instance((int)$this->settings->cmid)->id;

        $this->user_with([]);
        $permissions = booking_rating_permissions($contextid, 'mod_booking', 'bookingoption');
        $this->assertFalse($permissions['rate']);

        $this->user_with(['mod/booking:rate']);
        $permissions = booking_rating_permissions($contextid, 'mod_booking', 'bookingoption');
        $this->assertTrue($permissions['rate']);

        // A plain student: no mod/booking:rate, but moodle/rating:rate.
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $this->course->id, 'student');
        $this->setUser($student);
        $permissions = booking_rating_permissions($contextid, 'mod_booking', 'bookingoption');
        $this->assertFalse($permissions['rate']);
        $this->assertTrue(
            has_capability('moodle/rating:rate', context_module::instance((int)$this->settings->cmid)),
            'report.php gates the rating submit with a capability almost every role has.'
        );
    }

    /**
     * mod/booking:canoverbook unlocks booking an option which is already full
     * (together with the global setting allowoverbooking).
     *
     * @covers \mod_booking\booking_option::option_allows_booking_for_user
     */
    public function test_canoverbook_unlocks_booking_a_full_option(): void {
        $this->create_option([], ['maxanswers' => 1]);
        $this->book_students(1);
        set_config('allowoverbooking', 1, 'booking');
        $optionid = (int)$this->settings->id;

        $without = $this->user_with([]);
        $this->assertFalse(booking_option::option_allows_booking_for_user($optionid, (int)$without->id));

        $with = $this->user_with(['mod/booking:canoverbook'], context_system::instance());
        $this->assertTrue(booking_option::option_allows_booking_for_user($optionid, (int)$with->id));
    }

    /**
     * tool/certificate:manage unlocks the certificate button (together with
     * the global setting certificateon).
     *
     * @covers \mod_booking\output\booked_users::create_certificate_button
     */
    public function test_certificate_manage_unlocks_the_certificate_button(): void {
        $this->create_option();
        set_config('certificateon', 1, 'booking');

        $this->user_with([]);
        $this->assertEmpty(booked_users::create_certificate_button());

        $this->user_with(['tool/certificate:manage'], context_system::instance());
        $this->assertNotEmpty(booked_users::create_certificate_button());
    }

    /**
     * mod/booking:cansendmessages gates messaging in general: it is the
     * capability of the message provider of the plugin.
     *
     * @covers \mod_booking\tests\capability_testcase::assert_capability_default
     */
    public function test_cansendmessages_gates_the_message_provider(): void {
        global $CFG;

        $messageproviders = [];
        require($CFG->dirroot . '/mod/booking/db/messages.php');

        $this->assertEquals(
            'mod/booking:cansendmessages',
            $messageproviders['sendmessages']['capability'] ?? ''
        );
    }

    /**
     * Helper: form names of the action buttons of the booked users table.
     *
     * @param string $prefix unique table name prefix
     * @return array
     */
    private function button_formnames(string $prefix): array {
        $scope = new optionscope();
        $table = $scope->return_users_table(
            'option',
            (int)$this->settings->id,
            MOD_BOOKING_STATUSPARAM_BOOKED,
            'capwrite' . $prefix,
            ['firstname'],
            [get_string('firstname')]
        );
        return array_column($table->actionbuttons ?? [], 'formname');
    }
}
