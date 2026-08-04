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

/**
 * Regression tests for a real-world incident (observed on booking 9.3.2):
 *
 * maxoverbooking was -1 (unlimited waiting list), the option itself was fully
 * booked and several users were already on the waiting list. The admin then
 * reduced maxoverbooking below the current waiting count via the option form
 * while all waiting users stayed on the list (site setting
 * keepusersbookedonreducingmaxanswers). Result on the live site: users ended
 * up on "booked" beyond maxanswers - the over-full waiting list (negative
 * number of free places) was misread as "places left", so subsequent booking
 * traffic was booked straight onto the already full option.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::check_if_limit
 * @covers \mod_booking\booking_option::update
 * @covers \mod_booking\bo_availability\conditions\fullybooked::is_available
 * @covers \mod_booking\booking_answers\booking_answers::return_all_booking_information
 */
final class overfull_waitinglist_reduction_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Read the current booked + waiting-list user ids from a freshly rebuilt
     * answers object (singleton dropped, so we read the persisted truth).
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
     * Build the incident state: a single-seat option is fully booked, the given
     * users wait on an unlimited (-1) waiting list, then the admin reduces
     * maxoverbooking to 2 while all of them stay on the list.
     *
     * @param \stdClass $bookeduser user holding the single seat
     * @param \stdClass[] $waiters users on the waiting list
     * @param \stdClass[] $extrausers additional users to enrol (not booked)
     * @return array{0:int,1:int} [cmid, optionid]
     */
    private function seed_overfull_state(\stdClass $bookeduser, array $waiters, array $extrausers = []): array {
        set_config('keepusersbookedonreducingmaxanswers', 1, 'booking');

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Overfull waitinglist booking',
        ]);
        foreach (array_merge([$bookeduser], $waiters, $extrausers) as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Single seat option with unlimited waiting list',
            'description' => 'Single seat option with unlimited waiting list',
            'chooseorcreatecourse' => 0,
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'limitanswers' => 1,
            'maxanswers' => 1,
            'maxoverbooking' => -1,
            'waitforconfirmation' => 0,
        ]);
        $cmid = (int) $booking->cmid;
        $optionid = (int) $option->id;

        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookingoption->user_submit_response($bookeduser, 0, 0, 0, MOD_BOOKING_VERIFIED);
        foreach ($waiters as $waiter) {
            $bookingoption->user_submit_response($waiter, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }

        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $this->assertSame([(int) $bookeduser->id], $bookedids, 'precondition: the single seat is taken');
        $this->assertCount(count($waiters), $waitingids, 'precondition: all waiters are on the unlimited list');

        booking_option::update([
            'id' => $optionid,
            'cmid' => $cmid,
            'maxoverbooking' => 2,
        ]);
        singleton_service::destroy_instance();

        return [$cmid, $optionid];
    }

    /**
     * Stagger the waiting-list entry times so the promotion order is
     * deterministic: the first given waiter has been waiting longest.
     *
     * @param int $optionid
     * @param \stdClass[] $waiters waiting list users, longest-waiting first
     * @return void
     */
    private function stagger_waiting_times(int $optionid, array $waiters): void {
        global $DB;

        foreach ($waiters as $i => $waiter) {
            $DB->set_field(
                'booking_answers',
                'timemodified',
                time() - 500 + ($i * 100),
                [
                    'optionid' => $optionid,
                    'userid' => $waiter->id,
                    'waitinglist' => MOD_BOOKING_STATUSPARAM_WAITINGLIST,
                ]
            );
        }
        booking_option::purge_cache_for_option($optionid);
        singleton_service::destroy_instance();
    }

    /**
     * Reducing maxoverbooking from unlimited (-1) to 2 while four users are
     * already waiting must not lead to users being booked beyond maxanswers.
     */
    public function test_reduce_maxoverbooking_below_current_waiting_must_not_overbook(): void {
        // The site keeps excess users booked/waiting when limits shrink.
        set_config('keepusersbookedonreducingmaxanswers', 1, 'booking');

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Waitlist reduction booking',
        ]);

        $bookeduser = $this->getDataGenerator()->create_user();
        $waiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        $latecomer = $this->getDataGenerator()->create_user();
        foreach (array_merge([$bookeduser, $latecomer], $waiters) as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Single seat option with unlimited waiting list',
            'description' => 'Single seat option with unlimited waiting list',
            'chooseorcreatecourse' => 0,
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'limitanswers' => 1,
            'maxanswers' => 1,
            'maxoverbooking' => -1,
            'waitforconfirmation' => 0,
        ]);
        $cmid = (int) $booking->cmid;
        $optionid = (int) $option->id;

        // Fill the single seat, then put four users on the unlimited waiting list.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookingoption->user_submit_response($bookeduser, 0, 0, 0, MOD_BOOKING_VERIFIED);
        foreach ($waiters as $waiter) {
            $bookingoption->user_submit_response($waiter, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }

        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $this->assertSame([(int) $bookeduser->id], $bookedids, 'precondition: the single seat is taken');
        $this->assertCount(4, $waitingids, 'precondition: four users wait on the unlimited list');

        // The admin reduces the waiting list limit to 2 via the option form.
        booking_option::update([
            'id' => $optionid,
            'cmid' => $cmid,
            'maxoverbooking' => 2,
        ]);
        singleton_service::destroy_instance();

        // The four waiters stay on the (now over-full) list, the seat stays taken.
        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $this->assertSame([(int) $bookeduser->id], $bookedids, 'reducing the limit must not change who is booked');
        $this->assertCount(4, $waitingids, 'the four waiters must stay on the over-full waiting list');

        // Follow-up booking traffic on the full option: nobody may be booked
        // beyond maxanswers and nobody may join the over-full waiting list.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $this->assertFalse(
            $bookingoption->user_submit_response($latecomer, 0, 0, 0, MOD_BOOKING_VERIFIED),
            'a booking attempt on the full option with an over-full waiting list must be rejected'
        );

        // The waiters themselves must not be able to move from waiting to booked either.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        foreach ($waiters as $waiter) {
            $bookingoption->user_submit_response($waiter, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }

        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $this->assertSame(
            [(int) $bookeduser->id],
            $bookedids,
            'nobody must be moved from the waiting list onto the full option (maxanswers = 1)'
        );
        $this->assertCount(4, $waitingids, 'the waiting list must keep exactly the four original waiters');
        $this->assertNotContains((int) $latecomer->id, $bookedids, 'the latecomer must not be booked');
        $this->assertNotContains((int) $latecomer->id, $waitingids, 'the latecomer must not join the full waiting list');
    }

    /**
     * Replay of the incident timeline as reconstructed from the affected site's
     * mail and option change history:
     *
     * One user is booked, the option is full. Three users land on the unlimited
     * (-1) waiting list (each receiving the waiting list receipt rule mail). The
     * admin then reduces maxoverbooking to 2 - three already waiting, so the
     * free places compute to -1. The next user joins the over-full list anyway
     * (computed -1 mistaken for the unlimited sentinel, list now 4/2 = -2 free).
     * From then on every further attempt was booked STRAIGHT onto the full
     * option (overbooking fallthrough), so those users received the
     * booked-confirmation rule mail (bookingoption_booked) instead of a waiting
     * list mail or a rejection.
     *
     * The rule setup involved is recreated generically (a booked-confirmation
     * mail rule, a waiting list receipt rule cancelling the plain waiting list
     * place rule); no waitforconfirmation anywhere.
     */
    public function test_incident_timeline_reduction_then_booking_attempts(): void {
        set_config('keepusersbookedonreducingmaxanswers', 1, 'booking');

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Incident timeline booking',
        ]);

        $firstuser = $this->getDataGenerator()->create_user();
        $earlywaiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        $fourthwaiter = $this->getDataGenerator()->create_user();
        $latecomer = $this->getDataGenerator()->create_user();
        foreach (array_merge([$firstuser, $fourthwaiter, $latecomer], $earlywaiters) as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Single seat incident option',
            'description' => 'Single seat incident option',
            'chooseorcreatecourse' => 0,
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'limitanswers' => 1,
            'maxanswers' => 1,
            'maxoverbooking' => -1,
            'waitforconfirmation' => 0,
        ]);
        $cmid = (int) $booking->cmid;
        $optionid = (int) $option->id;

        // The mail rules reacting on booking events (subjects shortened to needles).
        $makerule = static function (string $name, string $event, string $subject, array $cancel) use ($gen): \stdClass {
            return $gen->create_rule([
                'name' => $name,
                'conditionname' => 'select_user_from_event',
                'contextid' => 1,
                'conditiondata' => '{"userfromeventtype":"relateduserid"}',
                'actionname' => 'send_mail',
                'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",'
                    . '"subject":"' . $subject . ' {title}","template":"x","templateformat":"1"}',
                'rulename' => 'rule_react_on_event',
                'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\' . $event . '",'
                    . '"condition":"0","aftercompletion":1,"cancelrules":' . json_encode($cancel) . '}',
            ]);
        };
        $ruleplace = $makerule('waitinglist place mail', 'bookingoptionwaitinglist_booked', 'Waiting list place for', []);
        $makerule(
            'waitinglist receipt mail',
            'bookingoptionwaitinglist_booked',
            'Registration received for',
            [(string) $ruleplace->id]
        );
        $makerule('booked confirmation mail', 'bookingoption_booked', 'Booking confirmed for', []);

        // Reads the rule mails queued so far as "subject-needle for userid" strings.
        $queuedmails = static function (): array {
            $found = [];
            foreach (\core\task\manager::get_adhoc_tasks('\mod_booking\task\send_mail_by_rule_adhoc') as $task) {
                $customdata = $task->get_custom_data();
                $encoded = json_encode($customdata);
                foreach (['Booking confirmed for', 'Registration received for', 'Waiting list place for'] as $needle) {
                    if (str_contains($encoded, $needle)) {
                        $found[] = $needle . ' -> user ' . ($customdata->userid ?? '?');
                    }
                }
            }
            return $found;
        };

        // The first user takes the single seat, three users join the unlimited
        // waiting list.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookingoption->user_submit_response($firstuser, 0, 0, 0, MOD_BOOKING_VERIFIED);
        foreach ($earlywaiters as $waiter) {
            $bookingoption->user_submit_response($waiter, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $this->assertSame([(int) $firstuser->id], $bookedids, 'precondition: the seat is taken');
        $this->assertCount(3, $waitingids, 'precondition: three users wait on the unlimited list');

        // The admin reduces the waiting list limit to 2 (three waiting -> -1 free).
        booking_option::update([
            'id' => $optionid,
            'cmid' => $cmid,
            'maxoverbooking' => 2,
        ]);
        singleton_service::destroy_instance();
        $mailsbefore = $queuedmails();

        // A fourth user tries to book: the over-full list must reject them.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $fourthresult = $bookingoption->user_submit_response($fourthwaiter, 0, 0, 0, MOD_BOOKING_VERIFIED);

        // A latecomer tries to book: the full option must reject them.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $latecomerresult = $bookingoption->user_submit_response($latecomer, 0, 0, 0, MOD_BOOKING_VERIFIED);
        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $newmails = array_values(array_diff($queuedmails(), $mailsbefore));

        // Desired behaviour: after the reduction the option accepts nobody -
        // neither onto the over-full waiting list nor onto the booked list - and
        // consequently no booked-confirmation rule mail may go out.
        $this->assertFalse(
            $fourthresult,
            'the fourth user must be rejected - a computed -1 must not reopen the waiting list as unlimited'
        );
        $this->assertFalse(
            $latecomerresult,
            'a booking attempt on the full option must be rejected, not booked'
        );
        $this->assertSame(
            [(int) $firstuser->id],
            $bookedids,
            'nobody but the original user may be booked (the incident booked every further attempt)'
        );
        $this->assertCount(3, $waitingids, 'the waiting list must keep exactly the three original waiters');
        $this->assertSame(
            [],
            preg_grep('/Booking confirmed for/', $newmails),
            'no booked-confirmation rule mail may be queued after the reduction'
        );
    }

    /**
     * Raising the waiting list limit above the current waiting count (the admin's
     * recovery path) must defuse the over-full state: nobody is promoted or booked
     * as a side effect, the original waiters stay, and new users can join the
     * waiting list again.
     */
    public function test_raising_limit_defuses_overfull_waitinglist(): void {
        $bookeduser = $this->getDataGenerator()->create_user();
        $waiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        $latecomer = $this->getDataGenerator()->create_user();
        [$cmid, $optionid] = $this->seed_overfull_state($bookeduser, $waiters, [$latecomer]);
        $waiterids = array_map(static fn($u): int => (int) $u->id, $waiters);

        // While the list is over-full (4 waiting, limit 2), nobody can join.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $this->assertFalse(
            $bookingoption->user_submit_response($latecomer, 0, 0, 0, MOD_BOOKING_VERIFIED),
            'precondition: the over-full waiting list rejects new users'
        );

        // The admin raises the limit above the current waiting count.
        booking_option::update([
            'id' => $optionid,
            'cmid' => $cmid,
            'maxoverbooking' => 8,
        ]);
        singleton_service::destroy_instance();

        // Raising the limit must not move anybody: the seat and the list are unchanged.
        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $this->assertSame([(int) $bookeduser->id], $bookedids, 'raising the limit must not promote or book anybody');
        $this->assertEqualsCanonicalizing($waiterids, $waitingids, 'the original waiters stay on the list unchanged');

        // The defused list accepts new users again - onto the waiting list only.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $this->assertNotFalse(
            $bookingoption->user_submit_response($latecomer, 0, 0, 0, MOD_BOOKING_VERIFIED),
            'after raising the limit the waiting list accepts new users again'
        );
        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $this->assertSame([(int) $bookeduser->id], $bookedids, 'the new user must not be booked onto the full option');
        $this->assertCount(5, $waitingids, 'the new user joined the waiting list');
        $this->assertContains((int) $latecomer->id, $waitingids, 'the new user is on the waiting list');
    }

    /**
     * When the booked user cancels while the waiting list is over-full, exactly one
     * waiter - the longest-waiting one - is promoted to the freed seat. The list
     * stays over its limit afterwards (3 waiting, limit 2), so it must still
     * reject further users and must never overbook the option.
     */
    public function test_cancel_in_overfull_state_promotes_exactly_one_waiter(): void {
        $bookeduser = $this->getDataGenerator()->create_user();
        $waiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        $latecomer = $this->getDataGenerator()->create_user();
        [$cmid, $optionid] = $this->seed_overfull_state($bookeduser, $waiters, [$latecomer]);
        $waiterids = array_map(static fn($u): int => (int) $u->id, $waiters);

        $this->stagger_waiting_times($optionid, $waiters);

        // The booked user cancels - sync_waiting_list promotes from the over-full list.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookingoption->user_delete_response($bookeduser->id);

        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $this->assertSame(
            [(int) $waiters[0]->id],
            $bookedids,
            'exactly the longest-waiting user must be promoted to the freed seat'
        );
        $this->assertEqualsCanonicalizing(
            array_slice($waiterids, 1),
            $waitingids,
            'the other three waiters stay on the waiting list'
        );
        $this->assertNotContains((int) $bookeduser->id, $waitingids, 'the cancelled user is gone');

        // The list is still over its limit (3 waiting, limit 2): further users
        // must still be rejected and the seat must not be overbooked.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $this->assertFalse(
            $bookingoption->user_submit_response($latecomer, 0, 0, 0, MOD_BOOKING_VERIFIED),
            'the still over-full waiting list keeps rejecting new users'
        );
        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $this->assertCount(1, $bookedids, 'the option holds exactly one booked user after the promotion');
        $this->assertCount(3, $waitingids, 'the waiting list keeps exactly the three remaining waiters');
    }

    /**
     * Disabling the waiting list (maxoverbooking emptied to 0) while users are
     * waiting must keep the existing waiters untouched: nobody is promoted,
     * booked or removed, new users are rejected (full option, no waiting list),
     * and the existing queue keeps draining through freed seats.
     */
    public function test_disabling_waitinglist_keeps_existing_waiters(): void {
        $bookeduser = $this->getDataGenerator()->create_user();
        $waiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        $latecomer = $this->getDataGenerator()->create_user();
        [$cmid, $optionid] = $this->seed_overfull_state($bookeduser, $waiters, [$latecomer]);
        $waiterids = array_map(static fn($u): int => (int) $u->id, $waiters);
        $this->stagger_waiting_times($optionid, $waiters);

        // The admin empties the waiting list limit (waiting list off).
        booking_option::update([
            'id' => $optionid,
            'cmid' => $cmid,
            'maxoverbooking' => 0,
        ]);
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertEquals(0, (int) $settings->maxoverbooking, 'precondition: the waiting list is disabled');

        // Disabling the list must not move or remove anybody.
        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $this->assertSame([(int) $bookeduser->id], $bookedids, 'disabling the list must not promote or book anybody');
        $this->assertEqualsCanonicalizing($waiterids, $waitingids, 'the existing waiters stay on the list');

        // New users are rejected: the option is full and there is no waiting list.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $this->assertFalse(
            $bookingoption->user_submit_response($latecomer, 0, 0, 0, MOD_BOOKING_VERIFIED),
            'with the waiting list disabled the full option rejects new users'
        );

        // The existing queue still drains: a freed seat promotes the
        // longest-waiting user.
        $bookingoption = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $bookingoption->user_delete_response($bookeduser->id);

        [$bookedids, $waitingids] = $this->booked_and_waiting($optionid);
        $this->assertSame(
            [(int) $waiters[0]->id],
            $bookedids,
            'the longest-waiting user is promoted to the freed seat'
        );
        $this->assertEqualsCanonicalizing(
            array_slice($waiterids, 1),
            $waitingids,
            'the other waiters keep their place in the queue'
        );
    }
}
