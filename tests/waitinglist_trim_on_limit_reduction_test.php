<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_booking;

use mod_booking\event\bookinganswer_cancelled;

/**
 * Tests for the limit-reduction sync path of sync_waiting_list() when the site
 * setting keepusersbookedonreducingmaxanswers is OFF: reducing maxanswers via
 * the option form demotes excess booked users to the waiting list, and excess
 * waiting list entries beyond maxoverbooking are removed with a cancellation
 * event. (With the setting ON, reductions never move or remove anybody - that
 * side is covered by the over-full waiting list tests.)
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::sync_waiting_list
 * @covers \mod_booking\booking_option::update
 * @covers \mod_booking\event\bookinganswer_cancelled
 */
final class waitinglist_trim_on_limit_reduction_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Create a two-seat option with the given waiting list limit, book two users
     * (the first one longer ago than the second) and put two users on the waiting
     * list.
     *
     * @param \stdClass[] $bookedusers exactly two users to book
     * @param \stdClass[] $waiters users for the waiting list
     * @param int $maxoverbooking waiting list limit
     * @return array{0:int,1:int} [cmid, optionid]
     */
    private function seed_two_seat_option(array $bookedusers, array $waiters, int $maxoverbooking): array {
        global $DB;

        set_config('keepusersbookedonreducingmaxanswers', 0, 'booking');

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Limit reduction booking',
        ]);
        foreach (array_merge($bookedusers, $waiters) as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Two seat option',
            'description' => 'Two seat option',
            'chooseorcreatecourse' => 0,
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'limitanswers' => 1,
            'maxanswers' => 2,
            'maxoverbooking' => $maxoverbooking,
            'waitforconfirmation' => 0,
        ]);
        $cmid = (int) $booking->cmid;
        $optionid = (int) $option->id;

        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        foreach ($bookedusers as $user) {
            $bookingoption->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        foreach ($waiters as $waiter) {
            $bookingoption->user_submit_response($waiter, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }

        // Stagger the booked times so the demotion order is deterministic: the
        // first booked user has held the seat longest and must keep it.
        foreach ($bookedusers as $i => $user) {
            $DB->set_field(
                'booking_answers',
                'timemodified',
                time() - 500 + ($i * 100),
                [
                    'optionid' => $optionid,
                    'userid' => $user->id,
                    'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
                ]
            );
        }
        booking_option::purge_cache_for_option($optionid);
        singleton_service::destroy_instance();

        return [$cmid, $optionid];
    }

    /**
     * Read the current booked + waiting-list user ids from a freshly rebuilt
     * answers object.
     *
     * @param int $optionid
     * @return array{0:int[],1:int[]} [bookedids, waitinglistids]
     */
    private function booked_and_waiting(int $optionid): array {
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $booked = array_map(static fn($o): int => (int) $o->userid, $answers->get_usersonlist());
        $waiting = array_map(static fn($o): int => (int) $o->userid, $answers->get_usersonwaitinglist());
        return [array_values($booked), array_values($waiting)];
    }

    /**
     * Reducing maxanswers demotes the newest booked user to the waiting list when
     * the list still has room; nobody is removed from the option.
     */
    public function test_reducing_maxanswers_demotes_newest_booked_user_to_waitinglist(): void {
        $firstbooked = $this->getDataGenerator()->create_user();
        $secondbooked = $this->getDataGenerator()->create_user();
        $waiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        // Waiting list limit 3: room for both waiters plus the demoted user.
        [$cmid, $optionid] = $this->seed_two_seat_option([$firstbooked, $secondbooked], $waiters, 3);

        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $this->assertEqualsCanonicalizing(
            [(int) $firstbooked->id, (int) $secondbooked->id],
            $bookedids,
            'precondition: both seats are taken'
        );
        $this->assertCount(2, $waitingids, 'precondition: two users wait');

        // The admin reduces the seats from 2 to 1.
        booking_option::update([
            'id' => $optionid,
            'cmid' => $cmid,
            'maxanswers' => 1,
        ]);
        singleton_service::destroy_instance();

        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $this->assertSame(
            [(int) $firstbooked->id],
            $bookedids,
            'the longest-booked user keeps the remaining seat'
        );
        $this->assertEqualsCanonicalizing(
            [(int) $waiters[0]->id, (int) $waiters[1]->id, (int) $secondbooked->id],
            $waitingids,
            'the newest booked user is demoted to the waiting list, the waiters stay'
        );
    }

    /**
     * Reducing maxanswers while the waiting list has no room for the demoted user
     * must not leave the lists over their limits: afterwards the option holds
     * exactly maxanswers booked and at most maxoverbooking waiting users, and the
     * user pushed out receives a cancellation event.
     */
    public function test_reducing_maxanswers_trims_waitinglist_overflow(): void {
        $firstbooked = $this->getDataGenerator()->create_user();
        $secondbooked = $this->getDataGenerator()->create_user();
        $waiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        // Waiting list limit 2: already full, no room for a demoted user.
        [$cmid, $optionid] = $this->seed_two_seat_option([$firstbooked, $secondbooked], $waiters, 2);

        // The admin reduces the seats from 2 to 1; capture the cancellation events.
        $sink = $this->redirectEvents();
        booking_option::update([
            'id' => $optionid,
            'cmid' => $cmid,
            'maxanswers' => 1,
        ]);
        $cancelled = [];
        foreach ($sink->get_events() as $event) {
            if ($event instanceof bookinganswer_cancelled) {
                $cancelled[] = (int) $event->relateduserid;
            }
        }
        $sink->close();
        singleton_service::destroy_instance();

        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $this->assertSame(
            [(int) $firstbooked->id],
            $bookedids,
            'the longest-booked user keeps the remaining seat'
        );
        $this->assertEqualsCanonicalizing(
            [(int) $waiters[0]->id, (int) $waiters[1]->id],
            $waitingids,
            'the waiting list keeps its two original waiters and stays within its limit'
        );
        $this->assertNotContains(
            (int) $secondbooked->id,
            array_merge($bookedids, $waitingids),
            'the user without room anywhere is removed from the option'
        );
        $this->assertSame(
            [(int) $secondbooked->id],
            $cancelled,
            'exactly the removed user receives a cancellation event'
        );
    }

    /**
     * The user removed by the trim must end up with exactly ONE queued
     * cancellation rule mail. The adhoc-task dedup (reschedule_or_queue_adhoc_task)
     * cannot merge the two cancellation events of the trim path, because the
     * second event carries an extrainfo payload: the rule data embedded in the
     * task differs, so the duplicate event becomes a duplicate mail.
     */
    public function test_trim_queues_single_cancellation_mail(): void {
        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $gen->create_rule([
            'name' => 'cancellation notice mail',
            'conditionname' => 'select_user_from_event',
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"relateduserid"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",'
                . '"subject":"Cancellation notice for {title}","template":"x","templateformat":"1"}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookinganswer_cancelled",'
                . '"condition":"0","aftercompletion":1,"cancelrules":[]}',
        ]);

        $firstbooked = $this->getDataGenerator()->create_user();
        $secondbooked = $this->getDataGenerator()->create_user();
        $waiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        // Waiting list limit 2: already full, no room for a demoted user.
        [$cmid, $optionid] = $this->seed_two_seat_option([$firstbooked, $secondbooked], $waiters, 2);

        // The admin reduces the seats from 2 to 1 - live observers, so the rule
        // reacts to the cancellation event(s) of the trimmed user.
        booking_option::update([
            'id' => $optionid,
            'cmid' => $cmid,
            'maxanswers' => 1,
        ]);
        singleton_service::destroy_instance();

        $mails = 0;
        foreach (\core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc') as $task) {
            $customdata = $task->get_custom_data();
            if (
                (int) ($customdata->userid ?? 0) === (int) $secondbooked->id
                && str_contains(json_encode($customdata), 'Cancellation notice for')
            ) {
                $mails++;
            }
        }
        $this->assertSame(
            1,
            $mails,
            'the removed user must receive exactly one cancellation rule mail'
        );
    }
}
