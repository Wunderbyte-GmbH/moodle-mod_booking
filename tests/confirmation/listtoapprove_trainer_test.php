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
use mod_booking\shortcodes;
use mod_booking\singleton_service;
use mod_booking\booking_bookit;
use mod_booking\bo_availability\bo_info;
use mod_booking\table\manageusers_table;
use local_wunderbyte_table\wunderbyte_table;
use mod_booking_generator;
use stdClass;

/**
 * Tests the rendered output of the [listtoapprove] shortcode for the trainer confirmation
 * workflow ALONE: trainer subplugin enabled site-wide and in the option json, supervisor
 * subplugin (if installed) disabled site-wide. The trainer subplugin is always delivered
 * with mod_booking, so these tests live in the mod_booking test folder.
 *
 * @package    mod_booking
 * @category   test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class listtoapprove_trainer_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        // On MariaDB, phpunit resets auto-increments so every test reuses identical ids.
        // Without destroying the singletons, cached users/answers/settings from a previous
        // test (same ids!) leak into the next one and distort the confirmation counts.
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
     * Happy path of the trainer workflow: a user with mod/booking:bookforothers sees the
     * pending answer with the thumbs-up button, can confirm it, and afterwards the answer
     * is booked and no longer rendered in the listtoapprove.
     *
     * @covers \mod_booking\shortcodes::listtoapprove
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     */
    public function test_bookforothers_holder_gets_button_and_confirms(): void {
        $env = $this->setup_environment();
        $student1 = $env['student1'];
        $approver1 = $env['approver1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        // The approver sees the row with the confirm button.
        $this->setUser($approver1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNotNull($table, 'A bookforothers holder should see the pending answer.');
        $this->assertEquals(1, $table->totalrows);
        $usersids = array_map(fn($record) => $record->userid, $table->rawdata);
        $this->assertContains($student1->id, $usersids);
        $this->assertStringContainsString(
            "confirmbooking-username-{$student1->username}",
            $html,
            'The trainer workflow must render the confirm button for a bookforothers holder.'
        );

        // The confirmation succeeds and books the user (the trainer workflow needs 1 confirmation).
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(1, $result['success']);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ALREADYBOOKED, $id);

        // The booked answer is no longer rendered in the listtoapprove.
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNull($table, 'A booked answer must not be rendered in the listtoapprove anymore.');
    }

    /**
     * A user WITHOUT mod/booking:bookforothers (but with readresponses) sees the pending
     * answer, but gets the "not allowed" badge instead of the thumbs-up - and the confirm
     * action is refused server-side.
     *
     * @covers \mod_booking\shortcodes::listtoapprove
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     */
    public function test_user_without_bookforothers_is_refused(): void {
        $env = $this->setup_environment();
        $student1 = $env['student1'];
        $viewer1 = $env['viewer1'];
        $settings = $env['settings'];
        $boinfo = $env['boinfo'];
        $answer = $env['answer'];

        $mutable = new manageusers_table('test_optionstoconfirm_0');

        $this->setUser($viewer1);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        // Visibility only needs readresponses, so the row is rendered...
        $this->assertNotNull($table);
        $this->assertEquals(1, $table->totalrows);
        // ...but without bookforothers there is no thumbs-up, only the "not allowed" badge.
        $this->assertStringNotContainsString(
            "confirmbooking-username-{$student1->username}",
            $html,
            'Without bookforothers the trainer workflow must not render a confirm button.'
        );
        $this->assertStringContainsString(
            get_string('notallowedtoconfirm', 'mod_booking'),
            $html,
            'Without bookforothers the user should see the "not allowed to confirm" badge.'
        );

        // The confirm action is refused server-side and the answer stays on the waiting list.
        $result = $mutable->action_confirmbooking(0, json_encode(['id' => $answer->baid]));
        $this->assertEquals(0, $result['success'], 'Without bookforothers the confirmation must be refused.');
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);
    }

    /**
     * listtoapprove visibility for the trainer workflow: readresponses (or
     * seealllisttoapprove, e.g. admin) makes the rows visible, a user without any
     * mod/booking capability sees nothing.
     *
     * @covers \mod_booking\shortcodes::listtoapprove
     * @covers \bookingextension_confirmation_trainer\local\confirmbooking
     */
    public function test_listtoapprove_visibility(): void {
        $env = $this->setup_environment();
        $student1 = $env['student1'];

        $seerows = [
            'approver1 (bookforothers + readresponses)' => $env['approver1'],
            'viewer1 (readresponses only)' => $env['viewer1'],
        ];
        foreach ($seerows as $label => $user) {
            $this->setUser($user);
            [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
            $this->assertNotNull($table, "$label must see the pending answer.");
            $this->assertEquals(1, $table->totalrows, "$label must see exactly one pending answer.");
            $usersids = array_map(fn($record) => $record->userid, $table->rawdata);
            $this->assertContains($student1->id, $usersids);
        }

        // Admin sees the rows via mod/booking:seealllisttoapprove.
        $this->setAdminUser();
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNotNull($table, 'Admin must see the pending answer via seealllisttoapprove.');
        $this->assertEquals(1, $table->totalrows);

        // A user without any mod/booking capability sees nothing.
        $this->setUser($env['norights1']);
        [$html, $table] = $this->get_table_from_listtoapprove_shortcode();
        $this->assertNull($table, 'A user without readresponses must not see any pending answers.');
    }

    /**
     * Creates course, users, roles, configs and a booking option using ONLY the trainer
     * workflow (trainer subplugin enabled site-wide and in the option json, supervisor
     * subplugin disabled site-wide), and books student1 onto the waiting list.
     *
     * @return array
     */
    private function setup_environment(): array {
        global $CFG;

        $this->resetAfterTest(true);
        $this->preventResetByRollback();

        $this->setAdminUser();

        // Trainer workflow ON site-wide, supervisor workflow OFF site-wide.
        set_config('confirmationtrainerenabled', 1, 'bookingextension_confirmation_trainer');
        if (\core_component::get_component_directory('bookingextension_confirmation_supervisor')) {
            set_config('confirmationsupervisorenabled', 0, 'bookingextension_confirmation_supervisor');
        }

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        // Create users.
        $student1 = $this->getDataGenerator()->create_user();
        $approver1 = $this->getDataGenerator()->create_user();
        $viewer1 = $this->getDataGenerator()->create_user();
        $norights1 = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'cancancelbook' => 1,
        ]);

        // Create booking option: wait for confirmation, trainer workflow enabled, no supervisor order.
        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'Test option trainer workflow',
            'courseid' => $course->id,
            'chooseorcreatecourse' => 1,
            'waitforconfirmation' => 1,
            'confirmationtrainerenabled' => 1,
            'confirmationsupervisorenabled' => 0,
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $boinfo = new bo_info($settings);

        $syscontext = \context_system::instance();

        // Approver role: sees the rows AND may confirm (readresponses + bookforothers).
        $approverroleid = create_role('Approver', 'approver', 'Approver with booking capabilities');
        assign_capability('mod/booking:bookforothers', CAP_ALLOW, $approverroleid, SYSCONTEXTID, true);
        assign_capability('mod/booking:readresponses', CAP_ALLOW, $approverroleid, SYSCONTEXTID, true);
        role_assign($approverroleid, $approver1->id, $syscontext->id);

        // Viewer role: sees the rows but must not be able to confirm (readresponses only).
        $viewerroleid = create_role('Viewer', 'viewer', 'Viewer without bookforothers');
        assign_capability('mod/booking:readresponses', CAP_ALLOW, $viewerroleid, SYSCONTEXTID, true);
        role_assign($viewerroleid, $viewer1->id, $syscontext->id);

        // No role at all for norights1: must not see any rows.

        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');

        // Student 1 books and lands on the waiting list.
        $this->setUser($student1);
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ASKFORCONFIRMATION, $id);
        booking_bookit::bookit('option', $settings->id, $student1->id); // Attempt to book.
        booking_bookit::bookit('option', $settings->id, $student1->id); // Confirm to book.
        [$id, $isavailable, $description] = $boinfo->is_available($settings->id, $student1->id, true);
        $this->assertEquals(MOD_BOOKING_BO_COND_ONWAITINGLIST, $id);

        $bookinganswers = singleton_service::get_instance_of_booking_answers($settings);
        $answer = ($bookinganswers->get_users())[$student1->id] ?? null;
        $this->assertNotEmpty($answer);

        return [
            'course' => $course,
            'booking' => $booking,
            'option' => $option,
            'settings' => $settings,
            'boinfo' => $boinfo,
            'answer' => $answer,
            'student1' => $student1,
            'approver1' => $approver1,
            'viewer1' => $viewer1,
            'norights1' => $norights1,
        ];
    }

    /**
     * Calls the real [listtoapprove] shortcode and returns its HTML output together with
     * the rendered table, re-instantiated from the data-encodedtable hash in the HTML.
     * The table is null if the shortcode did not render one (no answers to confirm).
     *
     * @return array [string $html, ?wunderbyte_table $table]
     */
    private function get_table_from_listtoapprove_shortcode(): array {
        global $PAGE;

        // Use a fresh page for each call, so context & url can be set repeatedly.
        $PAGE = new \moodle_page();
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url(new \moodle_url('/mod/booking/tests/confirmation/listtoapprove_trainer_test.php'));

        $env = new stdClass();
        $next = function () {
        };
        $html = shortcodes::listtoapprove('listtoapprove', ['reduced' => 1], null, $env, $next);

        // In reduced mode the shortcode returns an empty string when there is nothing to confirm.
        if (trim($html) === '') {
            return ['', null];
        }
        $this->assertStringNotContainsString('alert-warning', $html, 'The shortcode returned an error message.');

        if (!preg_match('/<div[^>]*\sdata-encodedtable=["\']?([^"\'>\s]+)["\']?/i', $html, $matches)) {
            return [$html, null];
        }

        $table = wunderbyte_table::instantiate_from_tablecache_hash($matches[1]);
        $table->printtable($table->pagesize, $table->useinitialsbar, $table->downloadhelpbutton);
        return [$html, $table];
    }
}
