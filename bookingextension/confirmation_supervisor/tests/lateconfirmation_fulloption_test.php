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
use mod_booking\booking_answers\booking_answers;
use mod_booking\booking_bookit;
use mod_booking\event\bookinganswer_confirmed;
use mod_booking\singleton_service;
use mod_booking\table\manageusers_table;
use mod_booking_generator;
use stdClass;
use tool_mocktesttime\time_mock;

/**
 * Late confirmation by the supervisor when the booking option is fully booked in the meantime.
 *
 * A pending request ("wait for confirmation") does not hold a place. So the last place can be
 * taken by somebody else before the supervisor confirms. Without places on the waiting list,
 * the late confirmation cannot book the user. It must then neither report a success nor
 * trigger the event "booking answer confirmed" (booking rules send the mail with the course
 * link on this event).
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
     * One place, two pending requests: the second confirmation comes too late.
     *
     * @covers \mod_booking\table\manageusers_table::action_confirmbooking
     * @covers \mod_booking\booking_option::user_submit_response
     * @covers \bookingextension_confirmation_supervisor\local\confirmbooking::has_capability_to_confirm_booking
     *
     * @param int $turnoffwaitinglist value of the site setting "Turn off waiting list globally"
     * @dataProvider waitinglist_off_provider
     */
    public function test_late_confirmation_on_fully_booked_option(int $turnoffwaitinglist): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->setAdminUser();

        set_config('turnoffwaitinglist', $turnoffwaitinglist, 'booking');
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
        $anna = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        // Anna is the supervisor of both users.
        profile_save_data((object)['id' => $max->id, 'profile_field_supervisor' => $anna->id]);
        profile_save_data((object)['id' => $other->id, 'profile_field_supervisor' => $anna->id]);

        $this->getDataGenerator()->enrol_user($max->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'student');

        // A supervisor only needs the restricted "book my team" capability to confirm for the team.
        $roleid = create_role('Supervisor', 'supervisor', 'Supervisor who may confirm for the own team');
        assign_capability('mod/booking:bookmyteam', CAP_ALLOW, $roleid, SYSCONTEXTID, true);
        role_assign($roleid, $anna->id, context_system::instance()->id);

        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
            'cancancelbook' => 1,
        ]);

        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)[
            'bookingid' => $booking->id,
            'text' => 'One place only',
            'courseid' => $course->id,
            'chooseorcreatecourse' => 1,
            'limitanswers' => 1,
            'maxanswers' => 1,
            'maxoverbooking' => 0,
            'waitforconfirmation' => 1,
            'confirmationsupervisorenabled' => 1, // Confirmation by supervisor.
        ]);

        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        $this->assertEquals(1, (int)$settings->maxanswers, 'precondition: the option has exactly one place');
        $this->assertEquals(0, (int)$settings->maxoverbooking, 'precondition: no places on the waiting list');

        // Both users request the only place. A pending request does not hold the place.
        foreach ([$max, $other] as $user) {
            $this->setUser($user);
            booking_bookit::bookit('option', $settings->id, $user->id); // Attempt to book.
            booking_bookit::bookit('option', $settings->id, $user->id); // Confirm to book.
        }

        $answers = $this->get_fresh_answers($option->id);
        $this->assertCount(0, $answers->get_usersonlist(), 'pending requests are not booked');
        $pending = $answers->get_usersonwaitinglist();
        $this->assertArrayHasKey($max->id, $pending, 'the request of Max waits for confirmation');
        $this->assertArrayHasKey($other->id, $pending, 'the request of the other user waits for confirmation');
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

        // The option must never be overbooked, and the request of Max stays pending.
        $answers = $this->get_fresh_answers($option->id);
        $this->assertCount(1, $answers->get_usersonlist(), 'the option must not be overbooked');
        $this->assertArrayNotHasKey($max->id, $answers->get_usersonlist(), 'Max must not be booked');
        $this->assertArrayHasKey($max->id, $answers->get_usersonwaitinglist(), 'the request of Max is still pending');
        $maxjson = json_decode($answers->get_usersonwaitinglist()[$max->id]->json ?? '') ?: new stdClass();
        $this->assertEmpty($maxjson->confirmationcount ?? 0, 'the request of Max must not count as confirmed');

        // The supervisor has to learn that the confirmation did not book the user...
        $this->assertEquals(
            0,
            $result['success'],
            'a confirmation that cannot book the user must not be reported as successful'
        );

        $this->assertEquals(
            get_string('confirmbookingfailedfullybooked', 'mod_booking'),
            $result['message'],
            'the supervisor is told that the option is fully booked by now'
        );

        // A confirmation that did not happen must not be logged in the booking history.
        $this->assertFalse(
            $DB->record_exists('booking_history', [
                'optionid' => $option->id,
                'userid' => $max->id,
                'status' => MOD_BOOKING_STATUSPARAM_WAITINGLIST_CONFIRMED,
            ]),
            'the booking history must not contain a confirmation for Max'
        );

        // ... and no rule may send the "your booking was confirmed" mail with the course link.
        $confirmedevents = array_filter($events, fn($event) => $event instanceof bookinganswer_confirmed);
        $this->assertCount(
            0,
            $confirmedevents,
            'the event "booking answer confirmed" must not be triggered when the user was not booked'
        );
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
