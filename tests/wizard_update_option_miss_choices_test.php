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

use advanced_testcase;
use mod_booking\local\wizard\options\skills\update_option_skill;
use mod_booking\local\wizard\options\skills\update_option_trainer_skill;
use mod_booking_generator;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * An option name that matches nothing, or several, ends as a choice between the activity's options.
 *
 * L45 UOT-2, thread 14816: "the Friday pilates session" matched no option (the only pilates option meets on a
 * Tuesday). update_option_trainer returned the shared resolver's text "No option matched optionquery ..." - English,
 * naming a schema field - without any option to choose from, and the reply asked the user to type the exact name
 * ("choices instead of an error", George 2026-09-24). update_option had the same path. Both now offer the options with
 * their start and weekday (hits first when several match), in the language pack's words. Since the resolver also
 * matches a single title word (#2572), the fixture phrase here carries none.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\update_option_trainer_skill
 * @covers     \mod_booking\local\wizard\options\skills\update_option_skill
 */
final class wizard_update_option_miss_choices_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /** @var int Course-module id. */
    private int $cmid = 0;

    /** @var int Booking instance id. */
    private int $bookingid = 0;

    /**
     * One booking activity.
     */
    protected function setUp(): void {
        $this->skip_without_agent_extension();
        parent::setUp();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        $this->resetAfterTest(true);
        $this->setAdminUser();
        singleton_service::destroy_instance();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id, 'name' => 'Miss choices']);
        $this->cmid = (int)$booking->cmid;
        $this->bookingid = (int)$booking->id;
    }

    /**
     * Create an option with one session.
     *
     * @param string $title
     * @param int $start
     * @return int
     */
    private function option(string $title, int $start): int {
        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $id = (int)$generator->create_option((object)[
            'bookingid' => $this->bookingid,
            'text' => $title,
            'importing' => 1,
            'optiondateid_0' => 0,
            'coursestarttime_0' => $start,
            'courseendtime_0' => $start + HOURSECS,
        ])->id;
        singleton_service::destroy_instance();
        return $id;
    }

    /**
     * The skills under test with an input that names the option by query.
     *
     * @param string $query
     * @return array<string,array{0:object,1:array}>
     */
    private function skills(string $query): array {
        return [
            'update_option_trainer' => [new update_option_trainer_skill(), ['optionquery' => $query, 'teacherids' => [2]]],
            'update_option' => [new update_option_skill(), ['optionquery' => $query, 'location' => 'Somewhere']],
        ];
    }

    /**
     * The issue that carries the choices.
     *
     * @param array $issues
     * @return array|null
     */
    private function choice_issue(array $issues): ?array {
        foreach ($issues as $issue) {
            if (is_array($issue) && !empty($issue['candidates'])) {
                return $issue;
            }
        }
        return null;
    }

    /**
     * A miss offers every option with its start and weekday; the text names no field.
     */
    public function test_a_miss_offers_the_options_with_their_weekday(): void {
        $tuesday = strtotime('next tuesday 18:00');
        $pilates = $this->option('Pilates am Abend', $tuesday);
        $this->option('Rooftop Yoga', strtotime('next friday 18:00'));
        foreach ($this->skills('the Friday session') as $name => [$skill, $input]) {
            $preflight = $skill->preflight($input, \context_module::instance($this->cmid)->id, (int)get_admin()->id);
            $this->assertNotSame('pass', $preflight->status, $name);
            $issue = $this->choice_issue($preflight->issues);
            $this->assertNotNull($issue, "$name: " . json_encode($preflight->to_array()));
            $this->assertSame('needs_clarification', (string)$issue['severity'], $name);
            $ids = array_map(static fn(array $c): int => (int)$c['id'], $issue['candidates']);
            $this->assertContains($pilates, $ids, $name);
            $byid = array_column($issue['candidates'], null, 'id');
            $this->assertSame(2, (int)$byid[$pilates]['isoweekday'], "$name: the weekday travels with the choice");
            foreach (['optionquery', 'optionid', 'No option matched'] as $internal) {
                $this->assertStringNotContainsString($internal, (string)$issue['message'], $name);
            }
        }
    }

    /**
     * Several hits offer the hits first; the text names no field.
     */
    public function test_several_hits_are_offered_first(): void {
        $this->option('Kochkurs', strtotime('+3 days'));
        $a = $this->option('Pilates am Morgen', strtotime('+5 days'));
        $b = $this->option('Pilates am Abend', strtotime('+6 days'));
        foreach ($this->skills('Pilates') as $name => [$skill, $input]) {
            $preflight = $skill->preflight($input, \context_module::instance($this->cmid)->id, (int)get_admin()->id);
            $issue = $this->choice_issue($preflight->issues);
            $this->assertNotNull($issue, "$name: " . json_encode($preflight->to_array()));
            $first = array_slice(array_map(static fn(array $c): int => (int)$c['id'], $issue['candidates']), 0, 2);
            sort($first);
            $this->assertSame([$a, $b], $first, "$name: the matching options lead the list");
            $this->assertStringNotContainsString('optionid', (string)$issue['message'], $name);
        }
    }

    /**
     * Non-success path: an activity without options has nothing to offer - still a question, never an error.
     */
    public function test_a_miss_in_an_empty_activity_is_a_question_without_choices(): void {
        foreach ($this->skills('the Friday session') as $name => [$skill, $input]) {
            $preflight = $skill->preflight($input, \context_module::instance($this->cmid)->id, (int)get_admin()->id);
            $this->assertNotSame('pass', $preflight->status, $name);
            $this->assertNull($this->choice_issue($preflight->issues), $name);
            $this->assertSame('needs_clarification', (string)($preflight->issues[0]['severity'] ?? ''), $name);
        }
    }
}
