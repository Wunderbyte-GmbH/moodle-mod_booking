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
 * Regression tests for bookings on an over-full waiting list.
 *
 * An over-full waiting list (more users waiting than maxoverbooking allows, e.g. after
 * reducing the limit from unlimited "-1" to a fixed number while the excess entries were
 * kept) used to yield a negative number of free waiting list places. The fullybooked
 * condition misread that as "places left", so check_if_limit fell through to its
 * overbooking branch and booked every further user directly although maxanswers was
 * reached; a computed value of exactly -1 was even mistaken for the "unlimited" sentinel.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::check_if_limit
 * @covers \mod_booking\bo_availability\conditions\fullybooked::is_available
 * @covers \mod_booking\booking_answers\booking_answers::return_all_booking_information
 */
final class overfull_waitinglist_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Create a course + booking instance + one single-seat option with the given
     * waiting list limit, and enrol the given users as students.
     *
     * @param array $users user records to enrol
     * @param int $maxoverbooking waiting list limit (-1 for unlimited)
     * @return array{0:int,1:int} [cmid, optionid]
     */
    private function seed_single_seat_option(array $users, int $maxoverbooking): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Waitlist booking',
        ]);
        foreach ($users as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Single seat option',
            'description' => 'Single seat option',
            'chooseorcreatecourse' => 0,
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'limitanswers' => 1,
            'maxanswers' => 1,
            'maxoverbooking' => $maxoverbooking,
            'waitforconfirmation' => 0,
        ]);

        return [(int) $booking->cmid, (int) $option->id];
    }

    /**
     * Read the current booked + waiting-list user ids straight from a freshly
     * rebuilt answers object (singleton dropped so we read the persisted truth).
     *
     * @param int $cmid
     * @param int $optionid
     * @return array{0:int[],1:int[]} [bookedids, waitinglistids]
     */
    private function booked_and_waiting(int $cmid, int $optionid): array {
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $answers = singleton_service::get_instance_of_booking_answers($settings);
        $booked = array_map(static fn($o): int => (int) $o->userid, $answers->get_usersonlist());
        $waiting = array_map(static fn($o): int => (int) $o->userid, $answers->get_usersonwaitinglist());
        return [array_values($booked), array_values($waiting)];
    }

    /**
     * Fill the single seat and put the given users on the (unlimited) waiting list,
     * then reduce maxoverbooking below the current waiting list size WITHOUT trimming
     * the list - the persisted state the bug needs.
     *
     * @param int $cmid
     * @param int $optionid
     * @param \stdClass $bookeduser
     * @param array $waiters
     * @param int $newmaxoverbooking
     * @return void
     */
    private function overfill_and_reduce(
        int $cmid,
        int $optionid,
        \stdClass $bookeduser,
        array $waiters,
        int $newmaxoverbooking
    ): void {
        global $DB;

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_submit_response($bookeduser, 0, 0, 0, MOD_BOOKING_VERIFIED);
        foreach ($waiters as $waiter) {
            $option->user_submit_response($waiter, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }

        [$bookedids, $waitingids] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(1, $bookedids, 'precondition: the single seat is taken');
        $this->assertCount(count($waiters), $waitingids, 'precondition: all waiters are on the unlimited waiting list');

        $DB->set_field('booking_options', 'maxoverbooking', $newmaxoverbooking, ['id' => $optionid]);
        booking_option::purge_cache_for_option($optionid);
        singleton_service::destroy_instance();
    }

    /**
     * An unlimited waiting list (-1) keeps accepting users: the sentinel path must
     * survive the over-full fixes.
     */
    public function test_unlimited_waitinglist_still_accepts_users(): void {
        $bookeduser = $this->getDataGenerator()->create_user();
        $waiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        [$cmid, $optionid] = $this->seed_single_seat_option(array_merge([$bookeduser], $waiters), -1);

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_submit_response($bookeduser, 0, 0, 0, MOD_BOOKING_VERIFIED);
        foreach ($waiters as $waiter) {
            $this->assertNotFalse(
                $option->user_submit_response($waiter, 0, 0, 0, MOD_BOOKING_VERIFIED),
                'every user must get onto the unlimited waiting list'
            );
        }

        [$bookedids, $waitingids] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(1, $bookedids, 'the single seat holds exactly one user');
        $this->assertCount(3, $waitingids, 'all three waiters are on the unlimited waiting list');
    }

    /**
     * Over-full waiting list (2 over the limit -> negative free places): a further
     * booking attempt must be rejected, not booked onto the full option.
     */
    public function test_overfull_waitinglist_does_not_overbook_option(): void {
        $bookeduser = $this->getDataGenerator()->create_user();
        $waiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        $latecomer = $this->getDataGenerator()->create_user();
        [$cmid, $optionid] = $this->seed_single_seat_option(
            array_merge([$bookeduser, $latecomer], $waiters),
            -1
        );

        // Four users wait, then the limit drops to 2 while all four stay on the list.
        $this->overfill_and_reduce($cmid, $optionid, $bookeduser, $waiters, 2);

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $this->assertFalse(
            $option->user_submit_response($latecomer, 0, 0, 0, MOD_BOOKING_VERIFIED),
            'a booking attempt on a full option with an over-full waiting list must be rejected'
        );

        [$bookedids, $waitingids] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(1, $bookedids, 'the option must not be overbooked beyond maxanswers');
        $this->assertNotContains((int) $latecomer->id, $bookedids, 'the latecomer must not be booked');
        $this->assertNotContains((int) $latecomer->id, $waitingids, 'the latecomer must not join the full waiting list');
    }

    /**
     * Waiting list exactly one over the limit: the computed "-1 free places" must not
     * be mistaken for the unlimited sentinel, so no further user may join the list.
     */
    public function test_waitinglist_one_over_limit_is_not_unlimited(): void {
        $bookeduser = $this->getDataGenerator()->create_user();
        $waiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        $latecomer = $this->getDataGenerator()->create_user();
        [$cmid, $optionid] = $this->seed_single_seat_option(
            array_merge([$bookeduser, $latecomer], $waiters),
            -1
        );

        // Three users wait, then the limit drops to 2: exactly one over the limit.
        $this->overfill_and_reduce($cmid, $optionid, $bookeduser, $waiters, 2);

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $this->assertFalse(
            $option->user_submit_response($latecomer, 0, 0, 0, MOD_BOOKING_VERIFIED),
            'the computed -1 must not reopen the waiting list as "unlimited"'
        );

        [$bookedids, $waitingids] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(1, $bookedids, 'the option must not be overbooked beyond maxanswers');
        $this->assertCount(3, $waitingids, 'the waiting list must not grow beyond its persisted size');
        $this->assertNotContains((int) $latecomer->id, $waitingids, 'the latecomer must not join the full waiting list');
    }
}
