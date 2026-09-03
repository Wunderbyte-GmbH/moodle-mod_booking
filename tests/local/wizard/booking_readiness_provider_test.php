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

use mod_booking\local\wizard\booking\booking_readiness_provider;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../lib.php');

/**
 * Tests for the booking readiness statistics provider.
 *
 * The statistics feed the AI readiness panel which is rendered on every
 * view.php hit, so besides correctness these tests pin that the DB cost of
 * the call does not scale with the number of options of the instance
 * (issue #2208: the previous implementation instantiated the full settings
 * and answers singletons for every single option).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\booking\booking_readiness_provider
 */
final class booking_readiness_provider_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Seed a booking instance with $numoptions options; book $numbooked users
     * onto the first option and put one additional user on its waiting list.
     *
     * @param int $numoptions
     * @param int $numbooked
     * @return array{0:int,1:int,2:int[]} [cmid, bookingid, optionids]
     */
    private function seed(int $numoptions, int $numbooked): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Readiness stats booking',
        ]);

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $optionids = [];
        for ($i = 0; $i < $numoptions; $i++) {
            $option = $gen->create_option((object) [
                'bookingid' => $booking->id,
                'courseid' => $course->id,
                'text' => 'Option ' . $i,
                'description' => 'Option ' . $i,
                'chooseorcreatecourse' => 0,
                'coursestarttime_0' => strtotime('now + 1 day'),
                'courseendtime_0' => strtotime('now + 2 day'),
                'maxanswers' => $numbooked,
                'maxoverbooking' => 5,
            ]);
            $optionids[] = (int) $option->id;
        }

        $firstoption = singleton_service::get_instance_of_booking_option((int) $booking->cmid, $optionids[0]);
        for ($i = 0; $i < $numbooked; $i++) {
            $user = $this->getDataGenerator()->create_user();
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
            $firstoption->user_submit_response($user, 0, 0, 0, MOD_BOOKING_VERIFIED);
        }
        // One user on the waiting list: must NOT be counted as booked.
        $waitinguser = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($waitinguser->id, $course->id);
        $firstoption->user_submit_response($waitinguser, 0, 0, 0, MOD_BOOKING_VERIFIED);

        return [(int) $booking->cmid, (int) $booking->id, $optionids];
    }

    /**
     * The statistics report the real number of options and booked (not
     * waiting) users.
     */
    public function test_statistics_are_correct(): void {
        [$cmid, $bookingid] = $this->seed(4, 3);

        $stats = booking_readiness_provider::get_booking_statistics($cmid, $bookingid);

        $this->assertSame(4, $stats['num_options']);
        $this->assertSame(3, $stats['num_booked']);
    }

    /**
     * The DB reads of the statistics call must not scale with the number of
     * options of the instance: computing two counter values may not build the
     * settings + answers singletons of every single option (issue #2208).
     */
    public function test_statistics_reads_do_not_scale_with_options(): void {
        global $DB;
        [$cmid, $bookingid] = $this->seed(20, 2);

        // Warm-up call, then drop request singletons AND the MUC caches: the
        // measured call runs against a cold cache, like the first page hit
        // after a purge. The old per-option loop rebuilt settings + answers
        // for every option here (several reads each, scaling with N); the
        // aggregate implementation stays flat.
        booking_readiness_provider::get_booking_statistics($cmid, $bookingid);

        singleton_service::destroy_instance();
        \cache_helper::purge_all();
        $before = $DB->perf_get_reads();
        $stats = booking_readiness_provider::get_booking_statistics($cmid, $bookingid);
        $delta = $DB->perf_get_reads() - $before;

        $this->assertSame(20, $stats['num_options']);
        $this->assertSame(2, $stats['num_booked']);
        $this->assertLessThan(
            10,
            $delta,
            "get_booking_statistics() issued {$delta} DB reads for 20 options; "
                . "the cost must not scale with the number of options (issue #2208)."
        );
    }
}
