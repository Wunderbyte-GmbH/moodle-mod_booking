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
use context_system;
use mod_booking\booking;
use mod_booking\booking_answers\booking_answers;
use mod_booking\booking_bookit;
use mod_booking\event\bookinganswer_confirmed;
use mod_booking\singleton_service;
use mod_booking\table\manageusers_table;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Late confirmation by the supervisor on sites that work without waiting list.
 *
 * A pending request ("wait for confirmation") does not hold a place. So the last place can be
 * taken by somebody else before the supervisor confirms. The confirmation is a decision of its
 * own and must be saved anyway: the user stays on the waiting list as confirmed and leaves the
 * list of bookings to approve. Only when nothing could be saved at all, the confirmation fails.
 *
 * @package bookingextension_confirmation_supervisor
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lateconfirmation_fulloption_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        time_mock::init();
        time_mock::set_mock_time(strtotime('now'));
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
     * Data provider: the two ways a site can work "without waiting list".
     *
     * @return array
     */
    public static function waitinglist_off_provider(): array {
        return [
            'no places on the waiting list of the option' => [0],
            'waiting list turned off globally' => [1],
        ];
    }

    /**
     * One place, three pending requests: the second confirmation comes too late, but is saved,
     * and the confirmed user moves up when the place is available again. The third request is never confirmed.
     *
     * @covers \mod_booking\table\manageusers_table::action_confirmbooking
     * @covers \mod_booking\booking_option::user_submit_response
     *
     * @param int $turnoffwaitinglist value of the site setting "Turn off waiting list globally"
     * @dataProvider waitinglist_off_provider
     */
    public function test_late_confirmation_is_saved_on_fully_booked_option(int $turnoffwaitinglist): void {
        global $DB;

        set_config('turnoffwaitinglist', $turnoffwaitinglist, 'booking');
        [$max, $other, $anna, $booking, $third] = $this->create_team_and_instance();
        $option = $this->create_option_with_one_place((int)$booking->id, 'One place only');
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);

        // Both users request the only place. A pending request does not hold the place.
        $this->request_booking($settings->id, [$max, $other, $third]);

        $pending = $this->get_fresh_answers($option->id)->get_usersonwaitinglist();
        $maxbaid = $pending[$max->id]->baid;
        $otherbaid = $pending[$other->id]->baid;

        $this->setUser($anna);
        $table = new manageusers_table('test_optionstoconfirm_0');

        // The first confirmation takes the only place.
        $result = $table->action_confirmbooking(0, json_encode(['id' => $otherbaid]));
        $this->assertEquals(1, $result['success'], 'the confirmation in time is successful');
        $answers = $this->get_fresh_answers($option->id);
        $this->assertArrayHasKey($other->id, $answers->get_usersonlist(), 'the other user got the place');
        $this->assertTrue($answers->is_fully_booked(), 'precondition: the option is fully booked now');

        // The confirmation for Max comes too late.
        $sink = $this->redirectEvents();
        $result = $table->action_confirmbooking(0, json_encode(['id' => $maxbaid]));
        $events = $sink->get_events();
        $sink->close();

        // The option must never be overbooked.
        $answers = $this->get_fresh_answers($option->id);
        $this->assertCount(1, $answers->get_usersonlist(), 'the option must not be overbooked');
        $this->assertArrayNotHasKey($max->id, $answers->get_usersonlist(), 'Max must not be booked');

        // But the decision of the supervisor is saved: Max waits as a confirmed user.
        $this->assertEquals(1, $result['success'], 'the confirmation is saved, so it is successful');
        $this->assertArrayHasKey($max->id, $answers->get_usersonwaitinglist(), 'Max stays on the waiting list');
        $maxjson = json_decode($answers->get_usersonwaitinglist()[$max->id]->json ?? '') ?: new stdClass();
        $this->assertEquals(1, $maxjson->confirmationcount ?? 0, 'the confirmation must be saved in the answer');

        // The confirmation is logged and announced.
        $this->assertTrue(
            $DB->record_exists('booking_history', [
                'optionid' => $option->id,
                'userid' => $max->id,
                'status' => MOD_BOOKING_STATUSPARAM_WAITINGLIST_CONFIRMED,
            ]),
            'the booking history contains the confirmation for Max'
        );
        $confirmedevents = array_filter($events, fn($event) => $event instanceof bookinganswer_confirmed);
        $this->assertCount(1, $confirmedevents, 'the event "booking answer confirmed" is triggered once');

        // When the place becomes available again, the confirmed user moves up without a second confirmation.
        // This also holds with the waiting list turned off globally: a confirmed request is no waiting list.
        $this->setAdminUser();
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);
        $bookingoption->user_delete_response($other->id);
        $answers = $this->get_fresh_answers($option->id);
        $this->assertArrayHasKey($max->id, $answers->get_usersonlist(), 'the confirmed user gets the free place');
        $this->assertArrayHasKey(
            $third->id,
            $answers->get_usersonwaitinglist(),
            'a request without confirmation keeps waiting for its confirmation'
        );

        // A request that was never confirmed does not move up, even when the place is free.
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);
        $bookingoption->user_delete_response($max->id);
        $answers = $this->get_fresh_answers($option->id);
        $this->assertCount(0, $answers->get_usersonlist(), 'nobody is booked without confirmation');
        $this->assertArrayHasKey($third->id, $answers->get_usersonwaitinglist(), 'the request is still pending');
    }

    /**
     * With the waiting list turned off globally, only confirmed users move up - also when no
     * workflow requires a confirmation, so that the number of required confirmations is 0.
     *
     * @covers \mod_booking\booking_option::sync_waiting_list
     * @covers \mod_booking\local\waitinglist\waitinglist_sync_status::only_confirmed_users_move_up
     */
    public function test_unconfirmed_request_does_not_move_up_without_workflow(): void {
        set_config('turnoffwaitinglist', 1, 'booking');
        [$max, $other, , $booking] = $this->create_team_and_instance();
        // No workflow is active, so no confirmation is required by a workflow.
        set_config('confirmationsupervisorenabled', 0, 'bookingextension_confirmation_supervisor');

        $option = $this->create_option_with_one_place((int)$booking->id, 'One place only');
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $this->request_booking($settings->id, [$max, $other]);

        // The place is given to the other user directly.
        $this->setAdminUser();
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);
        $bookingoption->user_submit_response($other, 0, 0, MOD_BOOKING_BO_SUBMIT_STATUS_DEFAULT, MOD_BOOKING_VERIFIED);
        $answers = $this->get_fresh_answers($option->id);
        $this->assertArrayHasKey($other->id, $answers->get_usersonlist(), 'precondition: the place is taken');
        $this->assertArrayHasKey($max->id, $answers->get_usersonwaitinglist(), 'precondition: Max is still pending');

        // The place becomes available: the request of Max was never confirmed, so it stays pending.
        $bookingoption = singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);
        $bookingoption->user_delete_response($other->id);
        $answers = $this->get_fresh_answers($option->id);
        $this->assertCount(0, $answers->get_usersonlist(), 'nobody is booked without confirmation');
        $this->assertArrayHasKey($max->id, $answers->get_usersonwaitinglist(), 'the request of Max is still pending');
    }

    /**
     * When nothing can be saved at all, the confirmation fails and leaves no trace.
     *
     * Max requests two options. Then the booking instance is limited to one booking per user:
     * the first confirmation books him, so the second one can neither book him nor be saved.
     *
     * @covers \mod_booking\table\manageusers_table::action_confirmbooking
     */
    public function test_confirmation_that_cannot_be_saved_fails_without_trace(): void {
        global $DB;

        [$max, , $anna, $booking] = $this->create_team_and_instance();
        $option1 = $this->create_option_with_one_place((int)$booking->id, 'First option');
        $option2 = $this->create_option_with_one_place((int)$booking->id, 'Second option');
        $settings1 = singleton_service::get_instance_of_booking_option_settings($option1->id);
        $settings2 = singleton_service::get_instance_of_booking_option_settings($option2->id);

        $this->request_booking($settings1->id, [$max]);
        $this->request_booking($settings2->id, [$max]);
        $baid1 = $this->get_fresh_answers($option1->id)->get_usersonwaitinglist()[$max->id]->baid;
        $baid2 = $this->get_fresh_answers($option2->id)->get_usersonwaitinglist()[$max->id]->baid;

        // From now on the booking instance allows only one booking per user.
        $DB->set_field('booking', 'maxperuser', 1, ['id' => $booking->id]);
        booking::purge_cache_for_booking_instance_by_cmid((int)$booking->cmid);
        singleton_service::destroy_instance();

        $this->setUser($anna);
        $table = new manageusers_table('test_optionstoconfirm_0');

        $result = $table->action_confirmbooking(0, json_encode(['id' => $baid1]));
        $this->assertEquals(1, $result['success'], 'the first confirmation books Max');
        $this->assertArrayHasKey($max->id, $this->get_fresh_answers($option1->id)->get_usersonlist());

        $sink = $this->redirectEvents();
        $result = $table->action_confirmbooking(0, json_encode(['id' => $baid2]));
        $events = $sink->get_events();
        $sink->close();

        $this->assertEquals(0, $result['success'], 'a confirmation that was not saved must not be successful');
        $this->assertEquals(get_string('confirmbookingnotsaved', 'mod_booking'), $result['message']);
        $this->assertDebuggingNotCalled('the string of the message has to exist');

        $answers = $this->get_fresh_answers($option2->id);
        $this->assertArrayHasKey($max->id, $answers->get_usersonwaitinglist(), 'the request is still pending');
        $maxjson = json_decode($answers->get_usersonwaitinglist()[$max->id]->json ?? '') ?: new stdClass();
        $this->assertEmpty($maxjson->confirmationcount ?? 0, 'the request must not count as confirmed');

        $this->assertFalse(
            $DB->record_exists('booking_history', [
                'optionid' => $option2->id,
                'userid' => $max->id,
                'status' => MOD_BOOKING_STATUSPARAM_WAITINGLIST_CONFIRMED,
            ]),
            'the booking history must not contain a confirmation that did not happen'
        );
        $confirmedevents = array_filter($events, fn($event) => $event instanceof bookinganswer_confirmed);
        $this->assertCount(0, $confirmedevents, 'the event "booking answer confirmed" must not be triggered');
    }

    /**
     * Creates three users with their supervisor Anna and a booking instance.
     *
     * @return array [$max, $other, $anna, $booking, $third]
     */
    private function create_team_and_instance(): array {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->setAdminUser();

        set_config('confirmationtrainerenabled', 0, 'bookingextension_confirmation_trainer');
        set_config('confirmationsupervisorenabled', 1, 'bookingextension_confirmation_supervisor');
        set_config('supervisor', 'supervisor', 'bookingextension_confirmation_supervisor');

        $this->getDataGenerator()->create_custom_profile_field([
            'shortname' => 'supervisor',
            'name' => 'Supervisor',
            'datatype' => 'text',
        ]);

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $max = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $third = $this->getDataGenerator()->create_user();
        $anna = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        // Anna is the supervisor of all three users.
        foreach ([$max, $other, $third] as $user) {
            profile_save_data((object)['id' => $user->id, 'profile_field_supervisor' => $anna->id]);
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        // A supervisor only needs the restricted "book my team" capability to confirm for the team.
        $roleid = create_role('Supervisor', 'supervisor', 'Supervisor who may confirm for the own team');
        assign_capability('mod/booking:bookmyteam', CAP_ALLOW, $roleid, SYSCONTEXTID, true);
        role_assign($roleid, $anna->id, context_system::instance()->id);

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'cancancelbook' => 1,
        ]);

        return [$max, $other, $anna, $booking, $third];
    }

    /**
     * Creates a booking option with one place, no waiting list and confirmation by the supervisor.
     *
     * @param int $bookingid
     * @param string $title
     * @return stdClass
     */
    private function create_option_with_one_place(int $bookingid, string $title): stdClass {
        $this->setAdminUser();
        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        return $generator->create_option((object)[
            'bookingid' => $bookingid,
            'text' => $title,
            'chooseorcreatecourse' => 0,
            'limitanswers' => 1,
            'maxanswers' => 1,
            'maxoverbooking' => 0,
            'waitforconfirmation' => 1,
            'confirmationsupervisorenabled' => 1, // Confirmation by supervisor.
            // The limit of bookings per user only counts booking options that have not ended yet.
            'optiondateid_0' => "0",
            'daystonotify_0' => "0",
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
        ]);
    }

    /**
     * The users request the booking, which parks them on the waiting list until the confirmation.
     *
     * @param int $optionid
     * @param array $users
     * @return void
     */
    private function request_booking(int $optionid, array $users): void {
        foreach ($users as $user) {
            $this->setUser($user);
            booking_bookit::bookit('option', $optionid, $user->id); // Attempt to book.
            booking_bookit::bookit('option', $optionid, $user->id); // Confirm to book.
            $this->assertArrayHasKey(
                $user->id,
                $this->get_fresh_answers($optionid)->get_usersonwaitinglist(),
                'precondition: the request waits for confirmation'
            );
        }
    }

    /**
     * Returns the booking answers of the option, read from the database and not from a stale singleton.
     *
     * @param int $optionid
     * @return booking_answers
     */
    private function get_fresh_answers(int $optionid): booking_answers {
        singleton_service::destroy_booking_answers($optionid);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        return singleton_service::get_instance_of_booking_answers($settings);
    }
}
