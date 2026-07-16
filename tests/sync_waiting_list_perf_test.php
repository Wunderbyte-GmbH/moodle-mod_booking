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
 * Performance-baseline test for booking_option::sync_waiting_list().
 *
 * Promoting a waiting-list user currently triggers several full purge_cache_for_answers
 * calls (each doing system-wide cache purges + an answers rebuild), so the DB read
 * cost grows with the number of users promoted in one sync. This test measures the
 * marginal DB reads per promoted user by comparing a sync that promotes 3 users with
 * one that promotes 9, isolating the per-user cost from constant setup overhead.
 *
 * It is intentionally a target/guard: it is expected to FAIL on the current code
 * (the failure message reports the real per-user read cost) and to pass once the
 * purge cascade in sync_waiting_list is reduced. Adjust the threshold to the agreed
 * target before relying on it as a guard.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::sync_waiting_list
 */
final class sync_waiting_list_perf_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Build a free single-seat option with one booked user and $waiting users on the
     * waiting list, then raise capacity and measure the DB reads sync_waiting_list
     * spends promoting all $waiting of them in one go.
     *
     * @param int $waiting number of users to promote in a single sync
     * @return int DB reads consumed by the sync
     */
    private function measure_promotion_reads(int $waiting): int {
        global $DB;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Waitlist perf booking',
        ]);

        $users = [];
        for ($i = 0; $i <= $waiting; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
            $users[] = $user;
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
            'maxoverbooking' => $waiting + 5,
            'waitforconfirmation' => 0,
        ]);
        $cmid = (int) $booking->cmid;
        $optionid = (int) $option->id;

        // First user takes the seat; the rest land on the waiting list.
        $opt = singleton_service::get_instance_of_booking_option($cmid, $optionid);
        foreach ($users as $user) {
            $opt->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }

        // Raise capacity so the whole waiting list can be promoted in one sync.
        $DB->set_field('booking_options', 'maxanswers', $waiting + 1, ['id' => $optionid]);
        booking_option::purge_cache_for_option($optionid);

        // Warm settings + answers so we measure the sync's own cache churn, not a cold load.
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        singleton_service::get_instance_of_booking_answers($settings);
        $opt = singleton_service::get_instance_of_booking_option($cmid, $optionid);

        $before = $DB->perf_get_reads();
        $opt->sync_waiting_list();
        return $DB->perf_get_reads() - $before;
    }

    /**
     * Document (and ultimately guard) the marginal DB read cost per promoted user.
     */
    public function test_promotion_db_reads_per_user(): void {
        $small = $this->measure_promotion_reads(3);
        $large = $this->measure_promotion_reads(9);

        // The constant per-sync overhead cancels in the difference, leaving the
        // marginal cost of the 6 extra promotions.
        $peruser = ($large - $small) / 6.0;

        // Coarse regression guard against a relapse towards the old per-user cost (a redundant
        // loop purge adds ~+3.7 reads/user). The absolute perf_get_reads() count is core-version
        // sensitive: the clean baseline is ~12 reads/user on Moodle 5.1, ~14 on 4.5 and ~15 on
        // 5.2 — so the bound sits just above the 5.2 baseline while still catching a full-magnitude
        // relapse on 5.2 (~18.7) and 4.5 (~17.7). It deliberately does NOT catch a 5.1-only relapse
        // (~15.7): a coarse cross-version guard that survives core bumps beats a razor-tight one
        // that flaps. Tighten (or make version-aware) once the per-call broadcast purge inside
        // user_submit_response is also deferred during the sync.
        $this->assertLessThan(
            16,
            $peruser,
            sprintf(
                'sync_waiting_list costs ~%.1f DB reads per promoted user '
                    . '(reads: 3 users=%d, 9 users=%d). Reducing the per-user purge cascade should bring this down.',
                $peruser,
                $small,
                $large
            )
        );
    }
}
