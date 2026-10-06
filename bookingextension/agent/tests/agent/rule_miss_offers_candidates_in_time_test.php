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
 * A rule query that finds nothing turns into a question that lists the rules, within the turn budget.
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
 * CBI-4, thread 11681 (baseline run 39): the constructor answered with a confirmation for update_rule_from_template
 * carrying rulequery "Erinnerung" and days 5 while the only reminder rule is named "Email reminder 2 days before
 * course start". The preflight of that card found no rule and, since wave 29, offers every rule of the context as a
 * RULE_CANDIDATE line. The turn has to end - thread 11681 produced no run and no answer for five minutes. Since wave 30
 * the offered rules go to the selector for one re-plan (preflight_choices_replan_test); a selector that still cannot
 * decide asks, and the turn ends as that question within the budget.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 */
final class rule_miss_offers_candidates_in_time_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /**
     * Provider, capabilities and rule templates.
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
     * The card's preflight misses the rule; the turn ends as a question listing the rules, well inside the budget.
     */
    public function test_a_missed_rule_query_ends_as_a_question_with_the_rules(): void {
        global $DB;
        $this->setAdminUser();
        $service = new booking_rules_agent_service();
        $contextid = $this->booking_contextid();
        $reminder = $service->create_rule_from_template($contextid, -ruletemplate_daysbeforestart::$templateid, ['days' => 3]);
        $service->create_rule_from_template($contextid, -ruletemplate_bookingoption_booked::$templateid, []);
        // Two active days-before rules, so the days value alone does not name the rule (wave 30 narrowing).
        $service->create_rule_from_template($contextid, -ruletemplate_daysbeforestart::$templateid, ['days' => 7]);
        $this->assertSame('ok', (string)($reminder['status'] ?? ''), json_encode($reminder));

        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_phase_scripted_planner(
            [
                $this->selector_skill_call('mod_booking.update_rule_from_template'),
                // After the re-plan with the offered rules the selector still cannot decide and asks.
                $this->constructor_clarification('Welche der aufgelisteten Regeln ist gemeint?'),
            ],
            [
                // Thread 11681: the model names the rule in the user's language, the rule carries an English name.
                $this->constructor_confirmation_request(
                    'mod_booking.update_rule_from_template',
                    ['rulequery' => 'Erinnerung', 'days' => 5],
                    'Soll die Regel "Erinnerung" auf 5 Tage vor Kursbeginn geändert werden?'
                ),
            ]
        );

        $start = microtime(true);
        $prompt = 'Die Erinnerung soll künftig fünf Tage vor Kursbeginn rausgehen, nicht drei.';
        $result = $this->chat($prompt, (int)$threadid, $store, $runtime);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(30, $elapsed, 'the turn ends inside the budget (thread 11681 hung for five minutes)');
        $this->assertNotSame('error', (string)($result['response_type'] ?? ''), json_encode($result));
        $this->assertSame('clarification', (string)($result['response_type'] ?? ''), json_encode($result));
        $this->assertSame('SCS', $this->scripted_phase_sequence(), 'the offered rules went to the selector once');
        $this->assertSame(0, $DB->count_records('bx_agent_ai_runs', ['threadid' => (int)$threadid]), 'nothing ran');
    }
}
