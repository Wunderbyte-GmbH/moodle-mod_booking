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

use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\bulk_update_options_skill;
use mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * An option name that matches nothing offers the options of the activity as a choice (wave 32).
 *
 * BU-1 ("Alle Kochkurse kriegen ab sofort 18 statt 12 Plätze", 0/10) and DBI-4 ("beim Excel-Kurs", 1/10) ended with a
 * bare "no option matched" in every run: "Kochkurse" and "Excel-Kurs" are in no option name, "Kochkurs Italienisch"
 * and "Excel-Schulung Buchhaltung" are. The code never stems or guesses; it lists what exists (name hits first, then
 * visible options, then hidden ones) and the model picks the ids. The names below are test data only.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\booking_skill_support
 * @covers     \mod_booking\local\wizard\options\skills\bulk_update_options_skill
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill
 */
final class wizard_option_miss_offers_choices_test extends booking_advanced_testcase {
    /** @var int */
    private int $cmid = 0;
    /** @var int */
    private int $contextid = 0;
    /** @var array<string,int> Option ids by title. */
    private array $ids = [];

    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();

        global $PAGE, $DB;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Choices', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);

        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        foreach (['Pottery Evening', 'Cooking Italian', 'Cooking Veggie', 'Sheet Training Accounts', 'Sheet Basics Old'] as $t) {
            $option = $gen->create_option(['bookingid' => (int)$booking->id, 'text' => $t, 'maxanswers' => 12, 'type' => 0]);
            $this->ids[$t] = (int)$option->id;
        }
        $DB->set_field('booking_options', 'invisible', 1, ['id' => $this->ids['Sheet Basics Old']]);
    }

    /**
     * The candidates of one issue by code.
     *
     * @param array $issues
     * @param string $code
     * @return array
     */
    private function issue(array $issues, string $code): array {
        foreach (json_decode(json_encode($issues), true) as $issue) {
            if ((string)($issue['code'] ?? '') === $code) {
                return $issue;
            }
        }
        return [];
    }

    /**
     * Visible options come first, hidden ones last; the given ids lead.
     */
    public function test_choices_are_ordered_hits_visible_hidden(): void {
        $choices = booking_skill_support::option_choices($this->cmid, [$this->ids['Sheet Training Accounts']]);
        $this->assertSame($this->ids['Sheet Training Accounts'], $choices[0]['id']);
        $this->assertSame($this->ids['Sheet Basics Old'], end($choices)['id'], 'hidden options come last');
        $this->assertSame(1, end($choices)['invisible']);
        $this->assertCount(5, $choices);
    }

    /**
     * A bulk query that is in no name offers the options (field optionids); the ids then pass.
     */
    public function test_bulk_miss_offers_the_options_and_the_ids_pass(): void {
        global $USER;
        $skill = new bulk_update_options_skill();
        $dto = $skill->preflight(
            ['optionquery' => 'Cookings', 'maxanswers' => 18, 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertNotContains((string)$dto->status, ['pass', 'soft_block']);
        $issue = $this->issue($dto->issues, 'EMPTY_BULK_TARGET_SELECTION');
        $this->assertSame('optionids', (string)($issue['field'] ?? ''), json_encode($dto->issues));
        $offered = array_column((array)($issue['candidates'] ?? []), 'id');
        $this->assertContains($this->ids['Cooking Italian'], $offered);
        $this->assertContains($this->ids['Cooking Veggie'], $offered);
        $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''));
        $this->assertStringNotContainsString('optionids', (string)($issue['message'] ?? ''), 'no schema field in the text');

        $retry = $skill->preflight(
            ['optionids' => [$this->ids['Cooking Italian'], $this->ids['Cooking Veggie']], 'maxanswers' => 18,
                'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertContains((string)$retry->status, ['pass', 'soft_block'], json_encode($retry->issues));
    }

    /**
     * A diagnosis for an option name that is in no title offers the options (field optionid).
     */
    public function test_diagnosis_miss_offers_the_options(): void {
        global $USER;
        $dto = (new diagnose_booking_issue_skill())->preflight(
            ['question' => 'Why am I not on the list?', 'optionquery' => 'Sheet-Course', 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertNotSame('pass', (string)$dto->status);
        $issue = $this->issue($dto->issues, 'OPTION_RESOLUTION_FAILED');
        $this->assertSame('optionid', (string)($issue['field'] ?? ''), json_encode($dto->issues));
        $offered = array_column((array)($issue['candidates'] ?? []), 'id');
        $this->assertContains($this->ids['Sheet Training Accounts'], $offered);
        // L43 (thread 13182): the miss text reached the synchronizer; it names no schema field (HARD RULE 2026-09-14).
        $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''));
        foreach (['optionquery', 'optionid', 'OPTION_RESOLUTION_FAILED'] as $internal) {
            $this->assertStringNotContainsString($internal, (string)($issue['message'] ?? ''));
        }
    }

    /**
     * Review w32s-b1: a participant is never offered an option the booking list hides from them (diagnose_booking_issue
     * is read-only and runs for participants too).
     */
    public function test_choices_hide_invisible_options_from_participants(): void {
        global $DB;
        $course = $DB->get_field('course_modules', 'course', ['id' => $this->cmid]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user((int)$student->id, (int)$course, 'student');
        $this->setUser($student);

        $offered = array_column(booking_skill_support::option_choices($this->cmid), 'id');
        $this->assertNotContains($this->ids['Sheet Basics Old'], $offered);
        $this->assertCount(4, $offered, 'the visible options stay');
        $this->assertNotContains(
            $this->ids['Sheet Basics Old'],
            array_column(booking_skill_support::option_choices($this->cmid, [$this->ids['Sheet Basics Old']]), 'id'),
            'not even when a caller names it first'
        );
    }

    /**
     * Several name hits: the hits lead the choices, the rest follows.
     */
    public function test_diagnosis_ambiguity_lists_the_hits_first(): void {
        global $USER;
        $dto = (new diagnose_booking_issue_skill())->preflight(
            ['question' => 'Why am I not on the list?', 'optionquery' => 'Sheet', 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );
        $issue = $this->issue($dto->issues, 'OPTION_RESOLUTION_AMBIGUOUS');
        $this->assertNotEmpty($issue, json_encode($dto->issues));
        $offered = array_column((array)($issue['candidates'] ?? []), 'id');
        $this->assertEqualsCanonicalizing(
            [$this->ids['Sheet Training Accounts'], $this->ids['Sheet Basics Old']],
            array_slice($offered, 0, 2)
        );
        $this->assertCount(5, $offered);
    }
}
