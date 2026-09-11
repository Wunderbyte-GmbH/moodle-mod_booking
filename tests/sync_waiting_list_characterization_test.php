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

    /**
     * Create a course + booking instance + a priced option (default price category)
     * with the given seat and waiting-list limits, and enrol the users as students.
     *
     * @param array $users user records to enrol
     * @param int $maxanswers seat limit
     * @param int $maxoverbooking waiting list limit
     * @return array{0:int,1:int} [cmid, optionid]
     */
    private function seed_priced_option(array $users, int $maxanswers, int $maxoverbooking): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Priced waitlist booking',
        ]);
        foreach ($users as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $gen->create_pricecategory((object) [
            'ordernum' => 1,
            'identifier' => 'default',
            'name' => 'Standard',
            'defaultvalue' => 25,
        ]);
        $option = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Priced option',
            'description' => 'Priced option',
            'chooseorcreatecourse' => 0,
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'limitanswers' => 1,
            'maxanswers' => $maxanswers,
            'maxoverbooking' => $maxoverbooking,
            'waitforconfirmation' => 0,
            'useprice' => 1,
        ]);

        return [(int) $booking->cmid, (int) $option->id];
    }

    /**
     * On a priced option nobody is promoted automatically: the freed seat has to be
     * bought, so the waiting user stays on the waiting list after a cancellation.
     */
    public function test_paid_option_does_not_auto_promote_waiting_user(): void {
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        [$cmid, $optionid] = $this->seed_priced_option([$usera, $userb], 1, 5);

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_submit_response($usera, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $option->user_submit_response($userb, 0, 0, 0, MOD_BOOKING_VERIFIED);

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(1, $booked, 'precondition: the single seat is taken');
        $this->assertCount(1, $waiting, 'precondition: one user waits');
        $bookeduser = reset($booked);
        $waitinguser = reset($waiting);

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_delete_response($bookeduser);

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertSame([$waitinguser], $waiting, 'the waiting user must not be promoted on a priced option');
        $this->assertEmpty($booked, 'the freed seat stays empty until it is bought');
    }

    /**
     * Reducing the limits of a priced option must never DELETE a booked (paid)
     * user's answer: the demotion loop skips paid users, so the trim loop must not
     * remove them through the back door either.
     */
    public function test_paid_option_reduction_must_not_delete_booked_users(): void {
        global $DB;

        set_config('keepusersbookedonreducingmaxanswers', 0, 'booking');

        $firstbooked = $this->getDataGenerator()->create_user();
        $secondbooked = $this->getDataGenerator()->create_user();
        $waiter = $this->getDataGenerator()->create_user();
        [$cmid, $optionid] = $this->seed_priced_option([$firstbooked, $secondbooked, $waiter], 2, 1);

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        foreach ([$firstbooked, $secondbooked, $waiter] as $user) {
            $option->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        // Stagger the booked times so the demotion order is deterministic.
        foreach ([$firstbooked, $secondbooked] as $i => $user) {
            $DB->set_field('booking_answers', 'timemodified', time() - 500 + ($i * 100), [
                'optionid' => $optionid,
                'userid' => $user->id,
                'waitinglist' => MOD_BOOKING_STATUSPARAM_BOOKED,
            ]);
        }
        booking_option::purge_cache_for_option($optionid);
        singleton_service::destroy_instance();

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(2, $booked, 'precondition: both seats are taken');
        $this->assertCount(1, $waiting, 'precondition: one user waits');

        booking_option::update([
            'id' => $optionid,
            'cmid' => $cmid,
            'maxanswers' => 1,
        ]);
        singleton_service::destroy_instance();

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        // Paid users are skipped by the demotion loop and therefore keep their booking.
        $this->assertEqualsCanonicalizing(
            [(int) $firstbooked->id, (int) $secondbooked->id],
            $booked,
            'paid booked users must keep their booking on limit reduction'
        );
        $this->assertContains(
            (int) $waiter->id,
            array_merge($booked, $waiting),
            'the waiting user must not silently lose the answer'
        );
    }

    /**
     * A user without mod/booking:deleteresponses reducing the limits must not
     * demote or remove anybody - the reduction phases are capability-gated.
     */
    public function test_reduction_without_deleteresponses_keeps_all_users(): void {
        global $DB;

        set_config('keepusersbookedonreducingmaxanswers', 0, 'booking');

        $editor = $this->getDataGenerator()->create_user();
        $firstbooked = $this->getDataGenerator()->create_user();
        $secondbooked = $this->getDataGenerator()->create_user();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Capability gate booking',
        ]);
        foreach ([$editor, $firstbooked, $secondbooked] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $created = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Two seat option',
            'description' => 'Two seat option',
            'chooseorcreatecourse' => 0,
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'limitanswers' => 1,
            'maxanswers' => 2,
            'maxoverbooking' => 5,
            'waitforconfirmation' => 0,
        ]);
        $cmid = (int) $booking->cmid;
        $optionid = (int) $created->id;

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_submit_response($firstbooked, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $option->user_submit_response($secondbooked, 0, 0, 0, MOD_BOOKING_VERIFIED);

        // The editor may update the option but not delete responses.
        $context = \context_module::instance($cmid);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:updatebooking', CAP_ALLOW, $roleid, $context->id);
        assign_capability('mod/booking:deleteresponses', CAP_PROHIBIT, $roleid, $context->id);
        role_assign($roleid, $editor->id, $context->id);

        // Reduce the limit directly in the DB, bypassing the update pipeline, so the
        // capability gate inside sync_waiting_list is the only thing under test.
        $DB->set_field('booking_options', 'maxanswers', 1, ['id' => $optionid]);
        booking_option::purge_cache_for_option($optionid);
        singleton_service::destroy_instance();

        $this->setUser($editor);
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->sync_waiting_list(false, true);

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertEqualsCanonicalizing(
            [(int) $firstbooked->id, (int) $secondbooked->id],
            $booked,
            'without deleteresponses the reduction must not demote anybody'
        );
        $this->assertEmpty($waiting, 'nobody may be moved to the waiting list');

        // Control: the same sync as admin (who holds deleteresponses) demotes the
        // newest booked user - proving the capability really is the differentiator.
        $this->setAdminUser();
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->sync_waiting_list(false, true);

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(1, $booked, 'as admin the reduction demotes down to the new limit');
        $this->assertCount(1, $waiting, 'the demoted user lands on the waiting list');
    }

    /**
     * With turnoffwaitinglistaftercoursestart enabled, a cancellation after the
     * option has started must not promote anybody from the waiting list.
     */
    public function test_no_promotion_after_coursestart_when_configured(): void {

        set_config('turnoffwaitinglistaftercoursestart', 1, 'booking');

        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        // Allowupdate keeps the optionhasstarted condition permissive, so the
        // turnoffwaitinglistaftercoursestart setting is the only thing blocking.
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Started waitlist booking',
            'allowupdate' => 1,
        ]);
        foreach ([$usera, $userb] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Started option',
            'description' => 'Started option',
            'chooseorcreatecourse' => 0,
            'optiondateid_0' => 0,
            'coursestarttime_0' => strtotime('now - 1 day'),
            'courseendtime_0' => strtotime('now + 1 day'),
            'limitanswers' => 1,
            'maxanswers' => 1,
            'maxoverbooking' => 5,
            'waitforconfirmation' => 0,
        ]);
        $cmid = (int) $booking->cmid;
        $optionid = (int) $option->id;

        // Guard against silently losing the seeded date (optiondateid_0 must make the
        // indexed date row parse): the gate under test reads coursestarttime.
        $seedcheck = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertGreaterThan(0, (int) $seedcheck->coursestarttime, 'seed: coursestarttime persisted');
        $this->assertLessThan(time(), (int) $seedcheck->coursestarttime, 'seed: option start is in the past');

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_submit_response($usera, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $option->user_submit_response($userb, 0, 0, 0, MOD_BOOKING_VERIFIED);

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(1, $booked, 'precondition: the seat is taken');
        $this->assertCount(1, $waiting, 'precondition: one user waits');
        $bookeduser = reset($booked);
        $waitinguser = reset($waiting);

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_delete_response($bookeduser);

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertSame(
            [$waitinguser],
            $waiting,
            'after course start no promotion may happen with turnoffwaitinglistaftercoursestart'
        );
        $this->assertEmpty($booked, 'the freed seat stays empty');
    }

    /**
     * With turnoffwaitinglist enabled globally, the sync does nothing at all:
     * a cancellation must not promote an already waiting user.
     */
    public function test_turnoffwaitinglist_blocks_all_syncing(): void {
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        // Seed WITH the waiting list still enabled, so userb really waits.
        [$cmid, $optionid] = $this->seed_single_seat_option([$usera, $userb]);
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_submit_response($usera, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $option->user_submit_response($userb, 0, 0, 0, MOD_BOOKING_VERIFIED);

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(1, $booked, 'precondition: seat taken');
        $this->assertCount(1, $waiting, 'precondition: one waits');
        $bookeduser = reset($booked);
        $waitinguser = reset($waiting);

        // Now the global off-switch is flipped and the booked user cancels.
        set_config('turnoffwaitinglist', 1, 'booking');
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_delete_response($bookeduser);

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertSame(
            ['booked' => [], 'waiting' => [$waitinguser]],
            ['booked' => $booked, 'waiting' => $waiting],
            'nobody may be promoted with turnoffwaitinglist on'
        );
    }

    /**
     * When the option has started and the instance does not allow booking after
     * start (allowupdate off), the optionhasstarted gate blocks the sync entirely
     * - independent of the turnoffwaitinglistaftercoursestart setting (off here).
     */
    public function test_option_started_without_allowupdate_blocks_promotion(): void {

        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Started no-allowupdate booking',
            'allowupdate' => 0,
        ]);
        foreach ([$usera, $userb] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Started option no allowupdate',
            'description' => 'Started option no allowupdate',
            'chooseorcreatecourse' => 0,
            'optiondateid_0' => 0,
            'coursestarttime_0' => strtotime('now - 1 day'),
            'courseendtime_0' => strtotime('now + 1 day'),
            'limitanswers' => 1,
            'maxanswers' => 1,
            'maxoverbooking' => 5,
            'waitforconfirmation' => 0,
        ]);
        $cmid = (int) $booking->cmid;
        $optionid = (int) $option->id;

        // Guard against silently losing the seeded date (see the sibling test).
        $seedcheck = singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertGreaterThan(0, (int) $seedcheck->coursestarttime, 'seed: coursestarttime persisted');
        $this->assertLessThan(time(), (int) $seedcheck->coursestarttime, 'seed: option start is in the past');

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_submit_response($usera, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $option->user_submit_response($userb, 0, 0, 0, MOD_BOOKING_VERIFIED);

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(1, $booked, 'precondition: seat taken');
        $this->assertCount(1, $waiting, 'precondition: one waits');
        $bookeduser = reset($booked);
        $waitinguser = reset($waiting);

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_delete_response($bookeduser);

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertSame(
            [$waitinguser],
            $waiting,
            'optionhasstarted (started + allowupdate off) must block the promotion'
        );
        $this->assertEmpty($booked, 'the freed seat stays empty');
    }

    /**
     * Options in demand-confirmation mode are never reduced automatically:
     * lowering maxanswers must not demote or remove anybody.
     */
    public function test_waitforconfirmation_blocks_reduction(): void {
        global $DB;

        set_config('keepusersbookedonreducingmaxanswers', 0, 'booking');

        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Confirmation booking',
        ]);
        foreach ([$usera, $userb] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option((object) [
            'bookingid' => $booking->id,
            'courseid' => $course->id,
            'text' => 'Confirmation option',
            'description' => 'Confirmation option',
            'chooseorcreatecourse' => 0,
            'coursestarttime_0' => strtotime('now + 1 day'),
            'courseendtime_0' => strtotime('now + 2 day'),
            'limitanswers' => 1,
            'maxanswers' => 2,
            'maxoverbooking' => 5,
            'waitforconfirmation' => 1,
        ]);
        $cmid = (int) $booking->cmid;
        $optionid = (int) $option->id;

        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_submit_response($usera, 0, 0, 0, MOD_BOOKING_VERIFIED);
        $option->user_submit_response($userb, 0, 0, 0, MOD_BOOKING_VERIFIED);

        // Force both users to BOOKED, whatever the confirmation flow did on submit.
        $DB->set_field('booking_answers', 'waitinglist', MOD_BOOKING_STATUSPARAM_BOOKED, ['optionid' => $optionid]);
        booking_option::purge_cache_for_option($optionid);
        singleton_service::destroy_instance();

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(2, $booked, 'precondition: both booked');

        booking_option::update([
            'id' => $optionid,
            'cmid' => $cmid,
            'maxanswers' => 1,
        ]);
        singleton_service::destroy_instance();

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertEqualsCanonicalizing(
            [(int) $usera->id, (int) $userb->id],
            $booked,
            'waitforconfirmation options must not be reduced automatically'
        );
        $this->assertEmpty($waiting, 'nobody may be moved to the waiting list');
    }

    /**
     * When the limits are removed from an option (unlimited), ALL waiting users
     * are promoted and informed.
     */
    public function test_removing_limits_promotes_all_waiting_users(): void {
        set_config('keepusersbookedonreducingmaxanswers', 0, 'booking');

        $usera = $this->getDataGenerator()->create_user();
        $waiters = [
            $this->getDataGenerator()->create_user(),
            $this->getDataGenerator()->create_user(),
        ];
        [$cmid, $optionid] = $this->seed_single_seat_option(array_merge([$usera], $waiters));
        $option = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        $option->user_submit_response($usera, 0, 0, 0, MOD_BOOKING_VERIFIED);
        foreach ($waiters as $waiter) {
            $option->user_submit_response($waiter, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(1, $booked, 'precondition: seat taken');
        $this->assertCount(2, $waiting, 'precondition: two wait');

        // Remove the limits entirely.
        booking_option::update([
            'id' => $optionid,
            'cmid' => $cmid,
            'limitanswers' => 0,
            'maxanswers' => 0,
        ]);
        singleton_service::destroy_instance();

        [$booked, $waiting] = $this->booked_and_waiting($cmid, $optionid);
        $this->assertCount(3, $booked, 'all users hold a seat once the option is unlimited');
        $this->assertEmpty($waiting, 'the waiting list is empty after removing the limits');
    }
}
