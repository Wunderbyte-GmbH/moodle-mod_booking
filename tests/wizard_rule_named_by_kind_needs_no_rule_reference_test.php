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
use mod_booking\local\wizard\options\skills\update_rule_from_template_skill;
use mod_booking\booking_rules\rules\templates\ruletemplate_bookingoption_booked;
use mod_booking\booking_rules\rules\templates\ruletemplate_daysbeforestart;
use ReflectionMethod;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once(__DIR__ . '/classes/booking_advanced_testcase.php');

/**
 * A rule named only by its kind needs no rule reference from the constructor (wave 32, L43).
 *
 * Under the frozen prompts the constructor reminder prints the declared requirement ("Its required values: one of
 * ruleid | rulequery"). CBI-4 ("Die Erinnerung soll künftig fünf Tage vor Kursbeginn rausgehen, nicht drei", thread
 * 13330, cons 83955) and URT-3 (thread 13333, cons 83968) ended as CONSTRUCTION_INPUT_REQUIRED asking "which rule?",
 * although the preflight resolves both from DB facts: one active days-before rule for a days value, otherwise every
 * rule as a choice. The skill declares no rule reference any more; a request without one is the preflight's case and
 * ends in the one rule or a choice - never in an error, never with schema field names in the text.
 *
 * Original fault kept pinned: {days: 5} without a rule still resolves to the single active days-before rule
 * (wizard_rule_single_days_rule_default_test, CBI-4 runs 31-37).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\update_rule_from_template_skill
 */
final class wizard_rule_named_by_kind_needs_no_rule_reference_test extends booking_advanced_testcase {
    /** @var int */
    private int $cmid = 0;
    /** @var int */
    private int $contextid = 0;

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
            'course' => $course->id, 'name' => 'Rule By Kind', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);
    }

    /**
     * The prompt contract of the skill (protected in the engine base class).
     *
     * @return array
     */
    private function contract(): array {
        $skill = new update_rule_from_template_skill();
        $method = new ReflectionMethod($skill, 'prompt_contract_payload');
        $method->setAccessible(true);
        return (array)$method->invoke($skill);
    }

    /**
     * The contract requires nothing, so the constructor reminder has no value to ask for (red before: one group).
     */
    public function test_the_contract_declares_no_rule_reference(): void {
        $contract = $this->contract();
        $this->assertSame([], array_values((array)($contract['required_groups'] ?? [])), json_encode($contract));
        $this->assertSame([], array_values((array)($contract['required_input'] ?? [])), json_encode($contract));
        $this->assertTrue((bool)($contract['accepts_empty_input'] ?? false), json_encode($contract));
        $this->assertTrue((bool)((new update_rule_from_template_skill())->check_structure([])['valid'] ?? false));
    }

    /**
     * No rule named, no days value, several rules: a choice of the rules, as a question - not an error (red before:
     * the structure gate rejected the input before the preflight).
     */
    public function test_no_rule_named_offers_the_rules_as_a_choice(): void {
        global $USER;
        $service = new booking_rules_agent_service();
        $a = $service->create_rule_from_template($this->contextid, -ruletemplate_daysbeforestart::$templateid, ['days' => 3]);
        $b = $service->create_rule_from_template($this->contextid, -ruletemplate_bookingoption_booked::$templateid, []);

        $skill = new update_rule_from_template_skill();
        $this->assertTrue((bool)($skill->check_structure(['isactive' => false])['valid'] ?? false));
        $dto = $skill->preflight(['isactive' => false], $this->contextid, (int)$USER->id);

        $this->assertNotSame('pass', (string)$dto->status, json_encode($dto->to_array()));
        $this->assertNotSame('error', (string)$dto->status, json_encode($dto->to_array()));
        $this->assertContains('RULE_RESOLUTION_FAILED', $dto->issuecodes, json_encode($dto->issuecodes));
        $offering = array_values(array_filter($dto->issues, static fn(array $i): bool => !empty($i['candidates'])));
        $this->assertCount(1, $offering, json_encode($dto->issues));
        $this->assertSame('ruleid', (string)($offering[0]['field'] ?? ''));
        $this->assertSame('needs_clarification', (string)($offering[0]['severity'] ?? ''));
        $ids = array_column($offering[0]['candidates'], 'id');
        $this->assertContains((int)$a['rule']['id'], $ids);
        $this->assertContains((int)$b['rule']['id'], $ids);
        // The text a user may see names no schema field (HARD RULE 2026-09-14, point 3).
        $message = (string)($offering[0]['message'] ?? '');
        $this->assertNotSame('', $message);
        foreach (['ruleid', 'rulequery'] as $field) {
            $this->assertStringNotContainsString($field, $message);
        }
    }

    /**
     * The field descriptions this wave wrote reach the constructor whole: skill_input_schema_projection cuts every
     * description at 160 characters, so a sentence behind the cut never arrives (agent test
     * field_descriptions_fit_the_prompt_test pins the cap).
     */
    public function test_the_changed_field_descriptions_fit_the_prompt(): void {
        $fields = [
            'mod_booking\local\wizard\options\skills\update_rule_from_template_skill' => ['rulequery', 'mailsubject', 'mailbody'],
            'mod_booking\local\wizard\options\skills\create_rule_from_template_skill' => ['templatequery', 'question'],
        ];
        foreach ($fields as $class => $names) {
            $properties = (array)((new $class())->get_schema()['properties'] ?? []);
            foreach ($names as $name) {
                $text = trim((string)preg_replace('/\s+/u', ' ', (string)($properties[$name]['description'] ?? '')));
                $this->assertNotSame('', $text, $class . '.' . $name);
                // Review w32s-b1: <= 159 as the integration brief sets it (one below the cut, never at it).
                $this->assertLessThanOrEqual(159, \core_text::strlen($text), $class . '.' . $name . ': ' . $text);
            }
        }
    }

    /**
     * Review w32s-b1: no rule named and no rule to offer - a question without schema fields, not an error. Unreachable
     * before (the structure gate), the service's text for it says "ruleid or rulequery".
     */
    public function test_no_rule_named_and_no_rule_exists_asks_without_field_names(): void {
        global $USER;
        $dto = (new update_rule_from_template_skill())->preflight(['isactive' => false], $this->contextid, (int)$USER->id);
        $this->assertNotSame('pass', (string)$dto->status, json_encode($dto->to_array()));
        $this->assertNotSame('error', (string)$dto->status, json_encode($dto->to_array()));
        $this->assertContains('RULE_RESOLUTION_FAILED', $dto->issuecodes, json_encode($dto->issuecodes));
        foreach (json_decode(json_encode($dto->issues), true) as $issue) {
            $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''));
            foreach (['ruleid', 'rulequery', 'RULE_RESOLUTION_FAILED'] as $internal) {
                $this->assertStringNotContainsString($internal, (string)($issue['message'] ?? ''));
            }
        }
    }

    /**
     * CBI-4 as the constructor would build it without a rule reference: the single active days-before rule.
     */
    public function test_days_without_a_rule_still_resolve_the_single_days_rule(): void {
        global $USER;
        $service = new booking_rules_agent_service();
        $daysrule = $service->create_rule_from_template(
            $this->contextid,
            -ruletemplate_daysbeforestart::$templateid,
            ['days' => 3]
        );
        $service->create_rule_from_template($this->contextid, -ruletemplate_bookingoption_booked::$templateid, []);

        $skill = new update_rule_from_template_skill();
        $this->assertTrue((bool)($skill->check_structure(['days' => 5])['valid'] ?? false));
        $dto = $skill->preflight(['days' => 5], $this->contextid, (int)$USER->id);
        $this->assertSame('pass', (string)$dto->status, json_encode($dto->to_array()));
        $this->assertSame((int)$daysrule['rule']['id'], (int)($dto->preparedinput['ruleid'] ?? 0));
    }
}
