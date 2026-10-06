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

use context_module;
use mod_booking\local\wizard\engine_component;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\wizard\booking\support\booking_rules_agent_service;
use mod_booking\local\wizard\options\skills\create_rule_from_template_skill;
use mod_booking\booking_rules\rules\templates\ruletemplate_daysbeforestart;
use mod_booking\booking_rules\rules\templates\ruletemplate_sessionreminders;
use mod_booking\booking_rules\rules\templates\ruletemplate_trainercancellation;
use mod_booking\booking_rules\rules\templates\ruletemplate_trainerreminderbeforestart;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/classes/booking_advanced_testcase.php');

/**
 * A template the resolver cannot pin is chosen from EVERY template, each with what the rule does (wave 32).
 *
 * CRT-2 (threads 12196, 12619): the constructor passed its own word "reminder"; the name hits were the per-session
 * reminder and the TEACHER reminder, and only those two were offered. The template the request meant ("Notification n
 * days before start", participants, days before the course start) was not on the list: L40 staged the teacher template,
 * L41 asked. The hits stay first, every other template follows, and each choice carries rule type, trigger event,
 * recipients, date field and days - facts of the template record, never words of the request.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\support\booking_rules_agent_service
 * @covers     \mod_booking\local\wizard\options\skills\create_rule_from_template_skill
 */
final class wizard_template_choices_carry_every_template_test extends booking_advanced_testcase {
    /** @var int */
    private int $cmid = 0;

    /**
     * Booking instance with rule templates active.
     */
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('bookingruletemplatesactive', 1, 'booking');
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Template Choices', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);
    }

    /**
     * The candidates of one preflight issue by its code.
     *
     * @param array $issues Preflight issues.
     * @param string $code Issue code.
     * @return array
     */
    private function candidates_of(array $issues, string $code): array {
        foreach ($issues as $issue) {
            if ((string)($issue['code'] ?? '') === $code) {
                return (array)($issue['candidates'] ?? []);
            }
        }
        return [];
    }

    /**
     * Number of built-in templates (negative ids) - the only ones the rule skills can apply.
     *
     * @param booking_rules_agent_service $service
     * @return int
     */
    private function builtin_count(booking_rules_agent_service $service): int {
        return count(array_filter($service->list_templates(), static fn(array $t): bool => (int)$t['templateid'] < 0));
    }

    /**
     * A single FRAGMENT hit is no choice: it leads the list, it does not resolve (L42sol thread 12933 staged the
     * waiting-list template for "a confirmation after every booking" through the fragment "Bestätigung").
     */
    public function test_a_single_fragment_hit_leads_the_list_but_does_not_resolve(): void {
        $service = new booking_rules_agent_service();
        // Test data: "waiting list" is part of exactly one English template name.
        $resolved = $service->resolve_template(0, 'waiting list');
        $this->assertSame('ambiguity', (string)($resolved['status'] ?? ''), json_encode($resolved));
        $candidates = (array)$resolved['candidates'];
        $this->assertSame(1, (int)($candidates[0]['namematch'] ?? 0));
        $this->assertCount($this->builtin_count($service), $candidates);

        // The template's own name still resolves.
        $own = (string)$candidates[0]['name'];
        $this->assertSame('ok', (string)($service->resolve_template(0, $own)['status'] ?? ''));
    }

    /**
     * Several name hits: the hits come first, every other template follows (the meant one included).
     */
    public function test_several_name_hits_offer_every_template_hits_first(): void {
        global $USER;
        $service = new booking_rules_agent_service();
        $hits = [
            -ruletemplate_sessionreminders::$templateid,
            -ruletemplate_trainerreminderbeforestart::$templateid,
        ];
        // Test data: "reminder" is part of exactly the two English names above (phpunit runs in English).
        $names = array_filter(
            array_column($service->list_templates(), 'name', 'templateid'),
            static fn($id): bool => (int)$id < 0,
            ARRAY_FILTER_USE_KEY
        );
        $this->assertArrayHasKey($hits[1], $names);

        $resolved = $service->resolve_template(0, 'reminder');
        $this->assertSame('ambiguity', (string)($resolved['status'] ?? ''), json_encode($resolved));
        $flagged = array_filter((array)$resolved['candidates'], static fn(array $c): bool => !empty($c['namematch']));
        $this->assertEqualsCanonicalizing($hits, array_column($flagged, 'templateid'));
        $candidates = (array)$resolved['candidates'];
        $this->assertCount(count($names), $candidates, 'every template is a choice, not only the name hits');
        $namematches = array_values(array_filter($candidates, static fn(array $c): bool => !empty($c['namematch'])));
        $this->assertSame(
            array_column($namematches, 'templateid'),
            array_slice(array_column($candidates, 'templateid'), 0, count($namematches)),
            'the name hits are listed first'
        );
        $this->assertContains(-ruletemplate_daysbeforestart::$templateid, array_column($candidates, 'templateid'));

        $dto = (new create_rule_from_template_skill())->preflight(
            ['templatequery' => 'reminder', 'days' => 2, 'cmid' => $this->cmid],
            (int)\context_module::instance($this->cmid)->id,
            (int)$USER->id
        );
        $this->assertNotSame('pass', (string)$dto->status);
        $choices = $this->candidates_of($dto->issues, 'TEMPLATE_RESOLUTION_AMBIGUOUS');
        $this->assertCount(count($names), $choices, json_encode($dto->issues));
    }

    /**
     * Every choice carries what the rule does: rule type, recipients, date field and days, or the trigger event.
     */
    public function test_choices_carry_the_template_attributes(): void {
        $service = new booking_rules_agent_service();
        $byid = [];
        foreach (create_rule_from_template_skill::template_choices($service->template_candidates()) as $choice) {
            $byid[(int)$choice['id']] = $choice;
        }

        $daysbefore = $byid[-ruletemplate_daysbeforestart::$templateid] ?? [];
        $this->assertSame('rule_daysbefore', $daysbefore['ruletype'] ?? null, json_encode($daysbefore));
        $this->assertSame('select_student_in_bo', $daysbefore['recipients'] ?? null);
        $this->assertSame('coursestarttime', $daysbefore['datefield'] ?? null);
        $this->assertSame(3, $daysbefore['defaultdays'] ?? null);

        $teacherreminder = $byid[-ruletemplate_trainerreminderbeforestart::$templateid] ?? [];
        $this->assertSame('select_teacher_in_bo', $teacherreminder['recipients'] ?? null, json_encode($teacherreminder));

        $cancellation = $byid[-ruletemplate_trainercancellation::$templateid] ?? [];
        $this->assertSame('rule_react_on_event', $cancellation['ruletype'] ?? null, json_encode($cancellation));
        $this->assertSame('bookingoption_cancelled', $cancellation['event'] ?? null);
        $this->assertSame('select_teacher_in_bo', $cancellation['recipients'] ?? null);
    }

    /**
     * A query that hits nothing lists every template - no alphabetical cut at twelve.
     */
    public function test_a_miss_lists_every_template(): void {
        $service = new booking_rules_agent_service();
        $resolved = $service->resolve_template(0, 'Zz no template carries this 421337');
        $this->assertSame('ambiguity', (string)($resolved['status'] ?? ''), json_encode($resolved));
        $this->assertCount($this->builtin_count($service), (array)$resolved['candidates']);
        $this->assertGreaterThan(12, count((array)$resolved['candidates']), 'the built-ins alone are more than twelve');
    }

    /**
     * No template named: the request sentence and the rule name are not used as a lookup; every template is offered.
     */
    public function test_no_template_named_offers_every_template_without_guessing(): void {
        global $USER;
        $service = new booking_rules_agent_service();
        $dto = (new create_rule_from_template_skill())->preflight(
            [
                'question' => 'Template - Confirm booking',
                'rulename' => 'Template - Confirm booking',
                'cmid' => $this->cmid,
            ],
            (int)\context_module::instance($this->cmid)->id,
            (int)$USER->id
        );
        $this->assertNotSame('pass', (string)$dto->status, 'a sentence or a rule name never picks a template');
        $this->assertContains('TEMPLATE_SELECTION_REQUIRED', $dto->issuecodes, json_encode($dto->issuecodes));
        $choices = $this->candidates_of($dto->issues, 'TEMPLATE_SELECTION_REQUIRED');
        $this->assertCount($this->builtin_count($service), $choices);
        $this->assertArrayHasKey('ruletype', $choices[0]);
        // Review w32s-b1: the question text names no schema field and no issue code (HARD RULE 2026-09-14, point 3).
        foreach ($dto->issues as $issue) {
            if ((string)($issue['code'] ?? '') !== 'TEMPLATE_SELECTION_REQUIRED') {
                continue;
            }
            $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''));
            foreach (['templateid', 'templatequery', 'TEMPLATE_SELECTION_REQUIRED'] as $internal) {
                $this->assertStringNotContainsString($internal, (string)($issue['message'] ?? ''));
            }
        }
    }

    /**
     * Review w32s-b1: a fragment hit and a miss carry the same localized text, without the field name (the miss text
     * was English and named templateid; the hit text said "multiple" for a single hit).
     */
    public function test_the_choice_text_names_no_field(): void {
        $service = new booking_rules_agent_service();
        foreach (['waiting list', 'Zz no template carries this 421337'] as $query) {
            $resolved = $service->resolve_template(0, $query);
            $this->assertSame('ambiguity', (string)($resolved['status'] ?? ''), json_encode($resolved));
            $this->assertSame(get_string('agent_booking_rules_choose_template', 'mod_booking'), (string)$resolved['message']);
            $this->assertStringNotContainsString('templateid', (string)$resolved['message']);
        }
    }

    /**
     * The templatequery field carries no example value the constructor would copy.
     *
     * L43 (frozen prompts): the engine already hides example VALUES of query fields (F79), yet both German requests
     * were built with the English "booking confirmation" (13173 cons 83007, 13176 cons 83025) - the example quoted in
     * this field's description. A query field's description names no value in quotes.
     */
    public function test_the_templatequery_description_quotes_no_example_value(): void {
        $properties = (array)((new create_rule_from_template_skill())->get_schema()['properties'] ?? []);
        $description = (string)($properties['templatequery']['description'] ?? '');
        $this->assertNotSame('', $description);
        $this->assertDoesNotMatchRegularExpression('/"[^"]+"/', $description, $description);
    }
}
