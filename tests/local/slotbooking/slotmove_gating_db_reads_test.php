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

use mod_booking\local\slotbooking\slot_mover;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../lib.php');

/**
 * Read-count guards for the slotmove self-rebooking gate.
 *
 * slot_mover::get_self_rebookable_answer() runs per rendered row of every
 * booking options table (slotmove bo condition, alreadybooked step-back), so
 * it must stay DB-free for options that cannot be self-rebooked at all
 * (issue #2209: it used to fetch the user's booking_answers record per row
 * BEFORE any slot gate - one read per option per request for every viewer).
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\slotbooking\slot_mover::get_self_rebookable_answer
 */
final class slotmove_gating_db_reads_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Seed a booking instance with $n plain options (no slot config).
     *
     * @param int $n
     * @return int[] optionids
     */
    private function seed_options(int $n): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Slotmove gating booking',
        ]);

        /** @var \mod_booking_generator $gen */
        $gen = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $optionids = [];
        for ($i = 0; $i < $n; $i++) {
            $option = $gen->create_option((object) [
                'bookingid' => $booking->id,
                'courseid' => $course->id,
                'text' => 'Option ' . $i,
                'description' => 'Option ' . $i,
                'chooseorcreatecourse' => 0,
                'coursestarttime_0' => strtotime('now + 1 day'),
                'courseendtime_0' => strtotime('now + 2 day'),
                'maxanswers' => 5,
                'maxoverbooking' => 0,
            ]);
            $optionids[] = (int) $option->id;
        }
        return $optionids;
    }

    /**
     * For options without a slot config the gate must answer from the cached
     * settings without a single DB read - it runs once per rendered row.
     */
    public function test_gate_is_db_free_for_options_without_slot_config(): void {
        global $DB;
        $optionids = $this->seed_options(20);
        $user = $this->getDataGenerator()->create_user();

        // Warm the option settings (as any real request rendering the table has).
        foreach ($optionids as $optionid) {
            singleton_service::get_instance_of_booking_option_settings($optionid);
        }

        $before = $DB->perf_get_reads();
        foreach ($optionids as $optionid) {
            $this->assertNull(slot_mover::get_self_rebookable_answer($optionid, (int)$user->id));
        }
        $delta = $DB->perf_get_reads() - $before;
        $this->assertSame(
            0,
            $delta,
            "get_self_rebookable_answer() issued {$delta} DB reads for 20 options "
                . "without slot config; the gate must be settings-only (issue #2209)."
        );
    }

    /**
     * An option WITH a slot config that allows self-rebooking passes the gate:
     * without a booked answer the result stays null, but the answer lookup is
     * allowed to hit the DB here.
     */
    public function test_gate_passes_through_for_rebookable_slot_options(): void {
        global $DB;
        $optionids = $this->seed_options(1);
        $optionid = reset($optionids);
        $user = $this->getDataGenerator()->create_user();

        $DB->insert_record('booking_slot_config', (object) [
            'optionid' => $optionid,
            'allow_self_rebooking' => 1,
        ]);
        // The slot config is part of the cached settings; rebuild them.
        singleton_service::destroy_instance();
        \cache_helper::purge_all();

        $this->assertNull(slot_mover::get_self_rebookable_answer($optionid, (int)$user->id));

        // The gate itself (settings warm, no answer) costs at most the answer lookup.
        singleton_service::get_instance_of_booking_option_settings($optionid);
        $before = $DB->perf_get_reads();
        slot_mover::get_self_rebookable_answer($optionid, (int)$user->id);
        $this->assertLessThanOrEqual(1, $DB->perf_get_reads() - $before);
    }
}
