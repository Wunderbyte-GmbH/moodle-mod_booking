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

/**
 * A preflight that offers the existing choices hands them to the selector for one re-plan.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use mod_booking\booking_rules\rules\templates\ruletemplate_bookingoption_booked;
use mod_booking\booking_rules\rules\templates\ruletemplate_daysbeforestart;
use mod_booking\local\wizard\booking\support\booking_rules_agent_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * CBI-4, thread 11716 (Nachlauf 28): the constructor built rulequery "Erinnerung" for a rule named "Email reminder
 * 2 days before course start". The preflight found no rule and offered the rules of the context (wave 29), but a
 * preflight rejection ended the turn: only the synchronizer saw the choices and could merely ask. Wave 30: an issue
 * that carries `candidates` is an offered choice; the step is re-planned once through the SELECTOR with the choices
 * as an observation, so selection and construction can pick the rule by its id. The code never matches a name.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 */
final class preflight_choices_replan_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** The user turn of thread 11716. */
    private const PROMPT = 'Die Erinnerung soll künftig fünf Tage vor Kursbeginn rausgehen, nicht drei.';

    /** @var int */
    private int $reminderid = 0;

    /**
     * Provider, capabilities and the two rules of the booking context.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        $this->grant_agent_capabilities_to_editingteacher();
        $roleid = (int)$this->getDataGenerator()->create_role();
        assign_capability('mod/booking:editbookingrules', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, (int)$this->teacher->id, \context_system::instance()->id);
        set_config('bookingruletemplatesactive', 1, 'booking');
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
    }

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * Create the reminder rule (optionally under a given name) and a booking-confirmation rule.
     *
     * @param string $remindername
     */
    private function seed_rules(string $remindername = ''): void {
        $this->setAdminUser();
        $service = new booking_rules_agent_service();
        $contextid = $this->booking_contextid();
        $overrides = ['days' => 3];
        if ($remindername !== '') {
            $overrides['rulename'] = $remindername;
        }
        $reminder = $service->create_rule_from_template($contextid, -ruletemplate_daysbeforestart::$templateid, $overrides);
        $service->create_rule_from_template($contextid, -ruletemplate_bookingoption_booked::$templateid, []);
        // A second ACTIVE days-before rule: with only one, the days value and the active flag already name the rule
        // (wave 30, mod_booking 7a62d57e4) and no choice is offered. This test is about the choice path.
        $service->create_rule_from_template($contextid, -ruletemplate_daysbeforestart::$templateid, ['days' => 7]);
        $this->assertSame('ok', (string)($reminder['status'] ?? ''), json_encode($reminder));
        $this->reminderid = (int)$reminder['rule']['id'];
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
    }

    /**
     * The selector sees the offered rules and the next construction picks the rule by its id.
     */
    public function test_a_missed_rule_is_chosen_from_the_offered_rules(): void {
        $this->seed_rules();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $skill = 'mod_booking.update_rule_from_template';
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call($skill), $this->selector_skill_call($skill)],
            [
                $this->constructor_confirmation_request($skill, ['rulequery' => 'Erinnerung', 'days' => 5]),
                $this->constructor_confirmation_request($skill, ['ruleid' => $this->reminderid, 'days' => 5]),
            ]
        );

        $result = $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        $sequence = $this->scripted_phase_sequence();
        $this->assertSame('confirmation_request', (string)($result['response_type'] ?? ''), $sequence . ' '
            . json_encode($result['issue_codes'] ?? []));
        $this->assertSame('SCSC', substr($sequence, 0, 4), 'the re-plan starts at the selector');
        $input = (array)(((array)($result['commands'] ?? []))[0]['input'] ?? []);
        $this->assertSame($this->reminderid, (int)($input['ruleid'] ?? 0), 'the chosen rule is on the card');

        $selectorprompts = array_values(array_filter(
            $this->scriptedplannerprompts,
            static fn(string $p): bool => strpos($p, 'phase_handoff.selection=') === false
        ));
        $this->assertCount(2, $selectorprompts);
        $this->assertStringContainsString('id=' . $this->reminderid, $selectorprompts[1], 'the choices reach the selector');
        $this->assertStringNotContainsString('id=' . $this->reminderid, $selectorprompts[0]);
    }

    /**
     * No choice fits: after the one re-plan the turn ends as the question with the choices, never as an error.
     */
    public function test_an_unmatched_choice_ends_as_the_question(): void {
        $this->seed_rules();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $skill = 'mod_booking.update_rule_from_template';
        $miss = $this->constructor_confirmation_request($skill, ['rulequery' => 'Erinnerung', 'days' => 5]);
        $this->install_phase_scripted_planner(array_fill(0, 4, $this->selector_skill_call($skill)), array_fill(0, 4, $miss));

        $result = $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''), json_encode($result['issue_codes'] ?? []));
        $this->assertLessThanOrEqual(2, substr_count($this->scripted_phase_sequence(), 'S'), 'one re-plan, no loop');
        $message = (string)($result['message'] ?? '');
        $this->assertStringNotContainsString('RULE_', $message, 'no issue code in the user text');
        $this->assertStringNotContainsString('PREFLIGHT_', $message, 'no issue code in the user text');
    }

    /**
     * The choices are backend data: with the privacy mode on, a person's name inside them never reaches the selector.
     */
    public function test_the_offered_choices_are_masked_for_the_selector(): void {
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');
        // A fixed name: the generator's random names include one- and two-character CJK names, which the
        // anonymizer's word detection (three letters minimum) does not catch - a separate finding (2026-09-25),
        // not part of this path; the choices are masked by the same function as every observation.
        $person = $this->getDataGenerator()->create_user(['firstname' => 'Hedwig', 'lastname' => 'Kranich']);
        $fullname = fullname($person);
        $this->seed_rules('Erinnerung an ' . $fullname);
        [$store, $runtime, $threadid] = $this->build_runtime();
        $skill = 'mod_booking.update_rule_from_template';
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call($skill), $this->selector_skill_call($skill)],
            [
                $this->constructor_confirmation_request($skill, ['rulequery' => 'Zahnarzt', 'days' => 5]),
                $this->constructor_clarification('Welche Regel ist gemeint?'),
            ]
        );

        $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        $selectorprompts = array_values(array_filter(
            $this->scriptedplannerprompts,
            static fn(string $p): bool => strpos($p, 'phase_handoff.selection=') === false
        ));
        $this->assertCount(2, $selectorprompts, 'the re-plan happened');
        $this->assertStringContainsString('id=' . $this->reminderid, $selectorprompts[1]);
        $this->assertStringNotContainsString($fullname, $selectorprompts[1], 'no plaintext name in the choices');
        // The name WAS in the rule (not a vacuous pass): its line carries the anonymizer's token instead.
        $this->assertMatchesRegularExpression(
            '/- id=' . $this->reminderid . ' label="[^"]*ANON_[A-Z0-9_]+/',
            $selectorprompts[1]
        );
    }
}
