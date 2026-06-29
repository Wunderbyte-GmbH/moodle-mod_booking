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
 * Performance-regression tests pinning mod_booking's caching contracts with the DB
 * read counter ($DB->perf_get_reads()), the pattern Moodle core uses (e.g.
 * calendar/tests/coursecat_proxy_test.php).
 *
 * The promise these tests lock in: once an option's settings / price / answers are
 * warm, accessing them again must not hit the database — neither from the in-memory
 * singleton (same request) nor from the MUC cache (after the request-scoped
 * singleton is dropped). A regression that adds an uncached query to one of these
 * hot paths (called per option on every list page) is caught deterministically.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\singleton_service
 */
final class caching_db_reads_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        singleton_service::destroy_instance();
        \cache_helper::purge_all();
    }

    /**
     * Seed one booking instance with $n plain options.
     *
     * @param int $n
     * @return array{0:int,1:int[]} [bookingid, optionids]
     */
    private function seed_options(int $n): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Caching perf booking',
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
        return [(int) $booking->id, $optionids];
    }

    /**
     * A second access to option settings within the same request is served from the
     * in-memory singleton without any DB read.
     */
    public function test_option_settings_singleton_is_db_free(): void {
        global $DB;
        [, $optionids] = $this->seed_options(1);
        $optionid = reset($optionids);

        singleton_service::get_instance_of_booking_option_settings($optionid);

        $before = $DB->perf_get_reads();
        singleton_service::get_instance_of_booking_option_settings($optionid);
        $this->assertSame(0, $DB->perf_get_reads() - $before, 'settings singleton re-access hit the DB');
    }

    /**
     * After the request-scoped singleton is dropped, rebuilding option settings is
     * served from the MUC cache with no (or negligible) DB reads.
     */
    public function test_option_settings_muc_hit_is_db_free(): void {
        global $DB;
        [, $optionids] = $this->seed_options(1);
        $optionid = reset($optionids);

        // Warm the MUC cache.
        singleton_service::get_instance_of_booking_option_settings($optionid);

        // Drop the in-memory singleton; the MUC cache survives.
        singleton_service::destroy_instance();

        $before = $DB->perf_get_reads();
        singleton_service::get_instance_of_booking_option_settings($optionid);
        $delta = $DB->perf_get_reads() - $before;
        $this->assertLessThan(
            3,
            $delta,
            "Rebuilding option settings from the MUC cache issued {$delta} DB reads; "
                . "a full cache hit must not re-query the option."
        );
    }

    /**
     * Loading settings for every option once warms them; a second pass over all
     * options must add zero DB reads (no per-option re-query).
     */
    public function test_repeated_settings_pass_does_not_requery(): void {
        global $DB;
        [, $optionids] = $this->seed_options(20);

        // First pass: cold, builds + caches each option.
        foreach ($optionids as $optionid) {
            singleton_service::get_instance_of_booking_option_settings($optionid);
        }

        // Second pass: everything is warm.
        $before = $DB->perf_get_reads();
        foreach ($optionids as $optionid) {
            singleton_service::get_instance_of_booking_option_settings($optionid);
        }
        $this->assertSame(
            0,
            $DB->perf_get_reads() - $before,
            'a second pass over already-loaded option settings re-queried the DB'
        );
    }

    /**
     * A second price lookup for an option is served from cache without a DB read.
     */
    public function test_price_cache_hit_is_db_free(): void {
        global $DB;
        [, $optionids] = $this->seed_options(1);
        $optionid = reset($optionids);

        price::get_prices_from_cache_or_db('option', $optionid);

        $before = $DB->perf_get_reads();
        price::get_prices_from_cache_or_db('option', $optionid);
        $this->assertSame(0, $DB->perf_get_reads() - $before, 'price cache re-access hit the DB');
    }

    /**
     * A second access to an option's booking answers within the same request is
     * served from the singleton without a DB read.
     */
    public function test_booking_answers_singleton_is_db_free(): void {
        global $DB;
        [, $optionids] = $this->seed_options(1);
        $optionid = reset($optionids);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        singleton_service::get_instance_of_booking_answers($settings);

        $before = $DB->perf_get_reads();
        singleton_service::get_instance_of_booking_answers($settings);
        $this->assertSame(0, $DB->perf_get_reads() - $before, 'booking_answers singleton re-access hit the DB');
    }

    /**
     * After the singleton is dropped, rebuilding booking answers is served from the
     * MUC cache with no (or negligible) DB reads.
     */
    public function test_booking_answers_muc_hit_is_db_free(): void {
        global $DB;
        [, $optionids] = $this->seed_options(1);
        $optionid = reset($optionids);
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        // Warm the answers MUC cache.
        singleton_service::get_instance_of_booking_answers($settings);

        // Drop the singleton; re-fetch settings from MUC (not measured) first.
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);

        $before = $DB->perf_get_reads();
        singleton_service::get_instance_of_booking_answers($settings);
        $delta = $DB->perf_get_reads() - $before;
        $this->assertLessThan(
            3,
            $delta,
            "Rebuilding booking answers from the MUC cache issued {$delta} DB reads; "
                . "a full cache hit must not re-query."
        );
    }
}
