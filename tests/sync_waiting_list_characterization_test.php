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
 * Characterization (safety-net) tests for booking_option::sync_waiting_list().
 *
 * These pin the OBSERVABLE behaviour of automatic waiting-list promotion on a free
 * option, so a later refactor of the cache-purging inside sync_waiting_list (which
 * currently fires several system-wide purges per promoted user) can be proven not
 * to change behaviour. They assert outcomes only, not cache internals.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::sync_waiting_list
 */
final class sync_waiting_list_characterization_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Create a course + booking instance + one free, single-seat option, and enrol
     * the given users as students.
     *
     * @param array $users user records to enrol
     * @return array{0:int,1:int} [cmid, optionid]
     */
    private function seed_single_seat_option(array $users): array {
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
            'maxoverbooking' => 5, // Allow a waiting list.
            'waitforconfirmation' => 0, // Automatic promotion.
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
     * On a single-seat free option, when the booked user cancels, the next user on
     * the waiting list is automatically promoted to booked.
     */
    public function test_cancel_promotes_next_waiting_user(): void {
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        [$cmid, $optionid] = $this->seed_single_seat_option([$usera, $userb]);

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);

        // Two users contend for one seat: one is booked, the other waits.
        $option->user_submit_response($usera, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $option->user_submit_response($userb, 0, 0, 0, MOD_BOOKING_VERIFIED);

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(1, $booked, 'exactly one of the two holds the seat');
        $this->assertCount(1, $waiting, 'the other one waits');
        $bookeduser = reset($booked);
        $waitinguser = reset($waiting);

        // The booked user cancels -> sync_waiting_list must promote the waiting user.
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_delete_response($bookeduser);

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertContains($waitinguser, $booked, 'the waiting user should be promoted after the cancel');
        $this->assertNotContains($bookeduser, $booked, 'the cancelled user should no longer be booked');
        $this->assertEmpty($waiting, 'the waiting list should be empty after promotion');
    }

    /**
     * With two users waiting, freeing one seat promotes exactly one of them and the
     * option stays at its single-seat capacity.
     */
    public function test_only_one_user_promoted_per_freed_seat(): void {
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $userc = $this->getDataGenerator()->create_user();
        [$cmid, $optionid] = $this->seed_single_seat_option([$usera, $userb, $userc]);

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_submit_response($usera, 0, 0, 0, MOD_BOOKING_VERIFIED); // Booked.
        $option->user_submit_response($userb, 0, 0, 0, MOD_BOOKING_VERIFIED); // Waiting.
        $option->user_submit_response($userc, 0, 0, 0, MOD_BOOKING_VERIFIED); // Waiting.

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(1, $booked, 'exactly one seat is taken');
        $this->assertCount(2, $waiting, 'two users wait');

        // Free the seat.
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_delete_response($usera->id);

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(1, $booked, 'still exactly one seat taken after promotion');
        $this->assertCount(1, $waiting, 'exactly one user promoted, one still waiting');
        $this->assertNotContains($usera->id, $booked);
    }

    /**
     * Raising capacity promotes the whole waiting list in one sync: every waiting
     * user becomes booked and the waiting list empties (exercises several loop
     * iterations).
     */
    public function test_capacity_increase_promotes_all_waiting_users(): void {
        global $DB;

        $booked = $this->getDataGenerator()->create_user();
        $waiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        [$cmid, $optionid] = $this->seed_single_seat_option(array_merge([$booked], $waiters));

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_submit_response($booked, 0, 0, 0, MOD_BOOKING_VERIFIED); // Booked.
        foreach ($waiters as $w) {
            $option->user_submit_response($w, 0, 0, 0, MOD_BOOKING_VERIFIED); // Waiting.
        }

        [$bookedids, $waitingids] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(1, $bookedids, 'one seat taken before the capacity change');
        $this->assertCount(3, $waitingids, 'three users waiting before the capacity change');

        // Raise capacity to fit everyone, then sync.
        $DB->set_field('booking_options', 'maxanswers', 4, ['id' => $optionid]);
        booking_option::purge_cache_for_option($optionid);
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->sync_waiting_list();

        [$bookedids, $waitingids] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(4, $bookedids, 'all four users should be booked after the capacity increase');
        $this->assertEmpty($waitingids, 'the waiting list should be empty');
        foreach ($waiters as $w) {
            $this->assertContains((int) $w->id, $bookedids, 'each waiting user should be promoted');
        }
    }
}
