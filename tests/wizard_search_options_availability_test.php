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

use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\search_options_skill;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Browsing must never present past options as bookable — independent of language (#2318, F57b).
 *
 * The old behaviour derived the time window from an English-only regex over user text; any
 * other language got NO window at all, so past events surfaced as bookable. The default
 * window comes from the calendar (SQL), not from vocabulary. Targeted title search stays
 * unbounded (a past option must remain findable, resolvable and duplicate-checkable).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\booking_skill_support
 */
final class wizard_search_options_availability_test extends booking_advanced_testcase {
    /** @var int Course-module id of the test instance. */
    private int $cmid = 0;

    /** @var int Booking id of the test instance. */
    private int $bookingid = 0;

    /** @var int Optionid of the past option. */
    private int $pastid = 0;

    /** @var int Optionid of the future option. */
    private int $futureid = 0;

    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();

        global $DB, $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Availability Test', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->bookingid = (int)$booking->id;
        $DB->set_field('booking', 'optionsfields', 'text,description,location,teacher,booknow', ['id' => $this->bookingid]);
        singleton_service::destroy_booking_singleton_by_cmid($this->cmid);
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);

        $this->pastid = $this->seed_option('Bygone Pottery Class', time() - (30 * DAYSECS));
        $this->futureid = $this->seed_option('Upcoming Pottery Class', time() + (10 * DAYSECS));
    }

    /**
     * Create one option with explicit start time.
     *
     * @param string $title
     * @param int $start
     * @return int optionid
     */
    private function seed_option(string $title, int $start): int {
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $gen->create_option([
            'bookingid' => $this->bookingid,
            'text' => $title,
            'maxanswers' => 10,
            'type' => 0,
            'coursestarttime' => $start,
            'courseendtime' => $start + HOURSECS,
        ]);
        return (int)$option->id;
    }

    /**
     * Ids returned by a preview call.
     *
     * @param string $query
     * @param string $when
     * @param bool $upcomingdefault
     * @return int[]
     */
    private function preview_ids(string $query, string $when = '', bool $upcomingdefault = false): array {
        $rows = booking_skill_support::search_option_candidates_for_preview(
            $this->cmid,
            $query,
            50,
            $when,
            $upcomingdefault
        );
        return array_map(static fn(array $r): int => (int)($r['optionid'] ?? 0), $rows);
    }

    /**
     * Browsing without any query must list only options that are not over yet.
     */
    public function test_browse_without_query_excludes_past_options(): void {
        $ids = $this->preview_ids('', '', true);

        $this->assertContains($this->futureid, $ids);
        $this->assertNotContains($this->pastid, $ids, 'a past option must not be presented as bookable');
    }

    /**
     * The default window must not depend on the language of the temporal phrase.
     */
    public function test_default_window_is_language_independent(): void {
        foreach (['demnächst', 'en ce moment', ''] as $when) {
            $ids = $this->preview_ids('', $when, true);
            $this->assertNotContains($this->pastid, $ids, "past option leaked for when='{$when}'");
            $this->assertContains($this->futureid, $ids, "future option missing for when='{$when}'");
        }
    }

    /**
     * An explicit past date in "when" is a deliberate question about the past — it must
     * narrow to that day instead of being silently overridden by the default window.
     */
    public function test_explicit_past_when_reaches_past_options(): void {
        $ids = $this->preview_ids('', date('Y-m-d', time() - (30 * DAYSECS)), true);

        $this->assertContains($this->pastid, $ids, 'an explicit past date must reach the past option');
        $this->assertNotContains($this->futureid, $ids, 'the day window must actually narrow');
    }

    /**
     * Targeted title search stays unbounded: past options remain findable and the
     * duplicate-title check keeps seeing them.
     */
    public function test_title_search_and_duplicate_check_still_see_past_options(): void {
        $this->assertContains(
            $this->pastid,
            $this->preview_ids('Bygone Pottery Class'),
            'a targeted title search must still find a past option'
        );

        $exact = booking_skill_support::find_existing_options_by_exact_title($this->cmid, 'Bygone Pottery Class');
        $this->assertNotSame(
            'none',
            (string)($exact['status'] ?? 'none'),
            'the duplicate-title check must keep seeing past options'
        );
    }

    /**
     * Thread 23513 (2026-09-30, Wunderbyte-GmbH/Wunderbyte-GmbH#2453): a slot-booking option has no course dates at all
     * (courseendtime NULL, not 0). The browse default "not over yet" dropped every such option - 9 of the 13 options of
     * the activity "slotbooking" - and the user was told there is nothing to book. An option without an end is never over.
     */
    public function test_browse_without_query_keeps_undated_options(): void {
        global $DB;
        $undated = $this->seed_option('Sprechstunde ohne Termin', time() + DAYSECS);
        $DB->set_field('booking_options', 'coursestarttime', null, ['id' => $undated]);
        $DB->set_field('booking_options', 'courseendtime', null, ['id' => $undated]);
        singleton_service::destroy_instance();

        $ids = $this->preview_ids('', '', true);

        $this->assertContains($undated, $ids, 'an option without dates is not over and must be listed');
        $this->assertContains($this->futureid, $ids);
        $this->assertNotContains($this->pastid, $ids);
    }

    /**
     * When nothing lies ahead but the activity holds options that are over, the skill says so and lists them
     * instead of "no matching booking options found" (the choice the user can act on, never a bare miss).
     */
    public function test_skill_browse_reports_past_options_when_nothing_is_upcoming(): void {
        global $DB, $USER;
        $DB->set_field('booking_options', 'coursestarttime', time() - 3 * DAYSECS, ['id' => $this->futureid]);
        $DB->set_field('booking_options', 'courseendtime', time() - 3 * DAYSECS + HOURSECS, ['id' => $this->futureid]);
        singleton_service::destroy_instance();

        $result = (new search_options_skill())->execute(
            ['query' => ''],
            (int)\context_module::instance($this->cmid)->id,
            (int)$USER->id
        );

        $ids = array_map(static fn(array $o): int => (int)($o['id'] ?? 0), (array)($result['options'] ?? []));
        $this->assertSame('executed', (string)$result['status']);
        $this->assertTrue(!empty($result['pastonly']), 'the result must state that every listed option is over');
        $this->assertContains($this->pastid, $ids);
        $this->assertContains($this->futureid, $ids);
        $this->assertStringNotContainsString(
            get_string('searchoptionsnotfound', 'booking'),
            (string)$result['usermessage']
        );
        $this->assertSame(get_string('searchoptionsfoundpastonly', 'booking', 2), (string)$result['usermessage']);
    }

    /**
     * End to end: the skill itself applies the browse default when no search text is given.
     */
    public function test_skill_browse_applies_the_default_window(): void {
        global $USER;
        $result = (new search_options_skill())->execute(
            ['query' => ''],
            (int)\context_module::instance($this->cmid)->id,
            (int)$USER->id
        );

        $ids = array_map(static fn(array $o): int => (int)($o['id'] ?? 0), (array)($result['options'] ?? []));
        $this->assertContains($this->futureid, $ids);
        $this->assertNotContains($this->pastid, $ids, 'the skill must pass the browse default to the search');
    }
}
