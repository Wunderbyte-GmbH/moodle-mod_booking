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
 * Options without dates and hidden options in the option search.
 *
 * An option without dates stays listed, but after the dated ones, so the limit never cuts a real
 * upcoming offer in its favour. The skill tells the model what is hidden, what has no date and
 * how many options matched in total.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\booking_skill_support
 * @covers     \mod_booking\local\wizard\options\skills\search_options_skill
 */
final class wizard_search_options_dateless_hidden_test extends booking_advanced_testcase {
    /** @var int Course-module id of the test instance. */
    private int $cmid = 0;

    /** @var int Booking id of the test instance. */
    private int $bookingid = 0;

    /** @var int Optionid of the option without dates (created first, lowest id). */
    private int $datelessid = 0;

    /** @var int Optionid of the hidden option, five days ahead. */
    private int $hiddenid = 0;

    /** @var int Optionid of the visible option, ten days ahead. */
    private int $soonid = 0;

    /** @var int Optionid of the visible option, twenty days ahead. */
    private int $laterid = 0;

    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();

        global $DB, $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Dateless Test', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->bookingid = (int)$booking->id;
        $DB->set_field('booking', 'optionsfields', 'text,description,location,teacher,booknow', ['id' => $this->bookingid]);
        singleton_service::destroy_booking_singleton_by_cmid($this->cmid);
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);

        $this->datelessid = $this->seed_option('Anytime Self Study', 0);
        $this->hiddenid = $this->seed_option('Hidden Draft Workshop', time() + (5 * DAYSECS), 1);
        $this->soonid = $this->seed_option('Upcoming Pottery Class', time() + (10 * DAYSECS));
        $this->laterid = $this->seed_option('Later Pottery Class', time() + (20 * DAYSECS));
    }

    /**
     * Create one option; start 0 means no dates at all.
     *
     * @param string $title
     * @param int $start
     * @param int $invisible
     * @return int optionid
     */
    private function seed_option(string $title, int $start, int $invisible = 0): int {
        global $DB;
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $record = ['bookingid' => $this->bookingid, 'text' => $title, 'maxanswers' => 10, 'type' => 0];
        if ($start > 0) {
            $record['coursestarttime'] = $start;
            $record['courseendtime'] = $start + HOURSECS;
        }
        $optionid = (int)$gen->create_option($record)->id;
        if ($start === 0) {
            $DB->delete_records('booking_optiondates', ['optionid' => $optionid]);
            $DB->update_record('booking_options', (object)['id' => $optionid, 'coursestarttime' => 0, 'courseendtime' => 0]);
        }
        if ($invisible !== 0) {
            $DB->set_field('booking_options', 'invisible', $invisible, ['id' => $optionid]);
        }
        singleton_service::destroy_booking_option_singleton($optionid);
        return $optionid;
    }

    /**
     * Ids returned by a browse call, in order.
     *
     * @param int $limit
     * @param string $when
     * @param string $whenuntil
     * @return int[]
     */
    private function browse_ids(int $limit, string $when = '', string $whenuntil = ''): array {
        $rows = booking_skill_support::search_option_candidates_for_preview($this->cmid, '', $limit, $when, true, $whenuntil);
        return array_map(static fn(array $r): int => (int)($r['optionid'] ?? 0), $rows);
    }

    /**
     * Run the skill as the current user.
     *
     * @param array $input
     * @return array
     */
    private function run_skill(array $input): array {
        global $USER;
        return (new search_options_skill())->execute(
            $input,
            (int)\context_module::instance($this->cmid)->id,
            (int)$USER->id
        );
    }

    /**
     * Browsing lists what has a date first; the option without dates stays, at the end.
     */
    public function test_browse_lists_dated_options_before_dateless(): void {
        $this->assertSame(
            [$this->hiddenid, $this->soonid, $this->laterid, $this->datelessid],
            $this->browse_ids(50)
        );
    }

    /**
     * The limit must cut the option without dates, never an upcoming one.
     */
    public function test_limit_cuts_the_dateless_option_first(): void {
        $this->assertSame([$this->hiddenid, $this->soonid, $this->laterid], $this->browse_ids(3));
    }

    /**
     * A period keeps the same order: what takes place in it first.
     */
    public function test_period_lists_dated_options_before_dateless(): void {
        $ids = $this->browse_ids(3, date('Y-m-d'), date('Y-m-d', time() + (30 * DAYSECS)));

        $this->assertSame([$this->hiddenid, $this->soonid, $this->laterid], $ids);
    }

    /**
     * The skill tells the model what is hidden, what has no date and when the rest starts.
     */
    public function test_skill_marks_hidden_and_dateless_options(): void {
        $result = $this->run_skill(['query' => '']);
        $options = array_column((array)($result['options'] ?? []), null, 'id');

        $this->assertSame('hidden', $options[$this->hiddenid]['visibility'] ?? '');
        $this->assertArrayNotHasKey('visibility', $options[$this->soonid]);
        $this->assertTrue($options[$this->datelessid]['nofixeddate'] ?? false);
        $this->assertArrayNotHasKey('start', $options[$this->datelessid]);
        $this->assertSame(
            userdate(time() + (10 * DAYSECS), '%Y-%m-%d %H:%M', 99, false, false),
            (string)($options[$this->soonid]['start'] ?? '')
        );
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $options[$this->soonid]['start']);

        $observation = (string)($result['observation_full'] ?? '');
        $this->assertStringContainsString('"visibility": "hidden"', $observation);
        $this->assertStringContainsString('"nofixeddate": true', $observation);
    }

    /**
     * A cut list says how many options matched.
     */
    public function test_skill_reports_total_when_the_list_is_cut(): void {
        $result = $this->run_skill(['query' => '', 'limit' => 2]);

        $this->assertCount(2, (array)($result['options'] ?? []));
        $observation = (string)($result['observation_full'] ?? '');
        $this->assertStringContainsString('"shown": 2', $observation);
        $this->assertStringContainsString('"total_matching": 4', $observation);
        $this->assertStringContainsString('"total_hidden": 1', $observation);
        $this->assertStringNotContainsString('total_is_minimum', $observation);

        $complete = (string)($this->run_skill(['query' => ''])['observation_full'] ?? '');
        $this->assertStringNotContainsString('total_matching', $complete, 'a complete list needs no total');
    }

    /**
     * A user who may not see hidden options gets none, and no visibility marker.
     */
    public function test_user_without_capability_never_gets_hidden_options(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        singleton_service::destroy_instance();

        $result = $this->run_skill(['query' => '']);
        $ids = array_map(static fn(array $o): int => (int)($o['id'] ?? 0), (array)($result['options'] ?? []));

        $this->assertNotContains($this->hiddenid, $ids);
        $this->assertContains($this->soonid, $ids);
        $this->assertStringNotContainsString('"visibility"', (string)($result['observation_full'] ?? ''));
    }

    /**
     * Looking an option up by its title is not affected: the option without dates resolves.
     */
    public function test_title_lookup_still_resolves_the_dateless_option(): void {
        $resolved = booking_skill_support::resolve_single_option($this->cmid, 'Anytime Self Study');

        $this->assertSame('ok', (string)($resolved['status'] ?? ''));
        $this->assertSame($this->datelessid, (int)($resolved['optionid'] ?? 0));
    }
}
