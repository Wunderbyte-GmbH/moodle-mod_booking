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
 * Real-LLM regression tests for rule-derived targeting (thread 584).
 *
 * Scenario: renaming a SYSTEM booking rule from inside a booking activity.
 * Before the rule_targeted_skill contract the agent asked "which activity?"
 * (unanswerable for a system rule — three identical activity candidate lists,
 * the user's answers never consumable). Now:
 * - a unique rule name goes straight to a confirmable update command;
 * - an ambiguous rule name is disambiguated by RULE ID, never by activity.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

/**
 * Rule rename targeting with a real LLM.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class rule_update_targeting_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
    }

    /**
     * Thread-584 wording: rename a unique SYSTEM rule from inside a booking activity.
     * The agent must produce an update_rule_from_template confirmation (no activity
     * question), and confirming it must rename the rule while it STAYS a system rule.
     */
    public function test_rename_unique_system_rule_without_activity_question(): void {
        global $DB;

        $this->grant_system_rule_editing($this->teacher->id);
        $this->setUser($this->teacher);

        $oldname = 'Systemregel Real LLM ' . substr(sha1(uniqid('', true)), 0, 8);
        $newname = $oldname . ' NEU';
        $ruleid = $this->seed_system_rule($oldname);

        [$store, $runtime, $threadid] = $this->build_runtime();

        $result = $this->chat(
            'Benenne die Buchungsregel "' . $oldname . '" um in "' . $newname . '".',
            $threadid,
            $store,
            $runtime
        );
        if (($result['response_type'] ?? '') !== 'confirmation_request') {
            $result = $this->chat(
                'Please rename the existing booking rule "' . $oldname . '" to "' . $newname . '". '
                    . 'Do not create a new rule.',
                $threadid,
                $store,
                $runtime
            );
        }

        $this->assertSame(
            'confirmation_request',
            (string)($result['response_type'] ?? ''),
            'Renaming a unique system rule must go straight to a confirmation, got: '
                . $this->payload_text($result)
        );
        $command = $this->extract_command($result, 'mod_booking.update_rule_from_template')
            ?? $this->extract_command($result, 'booking.update_rule_from_template');
        $this->assertNotNull($command, 'The agent must plan an update_rule_from_template command.');
        // The thread-584 defect: the skill demanded a booking activity for a system rule.
        // The schema no longer even has the field — the command must not carry one.
        $this->assertArrayNotHasKey('activityquery', (array)($command['input'] ?? []));

        $confirm = $this->confirm_pending_result($result, (int)$threadid, $store, false);
        $this->assertTrue((bool)($confirm['success'] ?? false), (string)($confirm['message'] ?? ''));

        $saved = $DB->get_record('booking_rules', ['id' => $ruleid], '*', MUST_EXIST);
        $this->assertSame(
            (int)\context_system::instance()->id,
            (int)$saved->contextid,
            'The renamed rule must STAY a system rule.'
        );
        $json = json_decode((string)$saved->rulejson);
        $this->assertSame($newname, (string)($json->name ?? ''), 'The rule must carry the new display name.');
    }

    /**
     * Two SYSTEM rules share a display name: the agent must ask which RULE (by id),
     * never which activity — and the user's id answer must be consumable: turn 2
     * confirms and renames exactly the chosen rule.
     */
    public function test_ambiguous_system_rules_disambiguate_by_rule_id(): void {
        global $DB;

        $this->grant_system_rule_editing($this->teacher->id);
        $this->setUser($this->teacher);

        $twinname = 'Zwillingsregel Real LLM ' . substr(sha1(uniqid('', true)), 0, 8);
        $newname = $twinname . ' UMBENANNT';
        $ruleid1 = $this->seed_system_rule($twinname);
        $ruleid2 = $this->seed_system_rule($twinname);

        [$store, $runtime, $threadid] = $this->build_runtime();

        $result = $this->chat(
            'Benenne die Buchungsregel "' . $twinname . '" um in "' . $newname . '".',
            $threadid,
            $store,
            $runtime
        );

        // The 584 failure mode was an ACTIVITY candidate list here. Correct behavior is a
        // clarification carrying both RULE ids (from the RULE_CANDIDATE preflight issues).
        $this->assertSame(
            'clarification',
            (string)($result['response_type'] ?? ''),
            'Two same-name rules must trigger a rule clarification, got: ' . $this->payload_text($result)
        );
        $text = $this->payload_text($result);
        $this->assertMatchesRegularExpression(
            '/\b' . $ruleid1 . '\b/',
            $text,
            'The clarification must offer the first rule id.'
        );
        $this->assertMatchesRegularExpression(
            '/\b' . $ruleid2 . '\b/',
            $text,
            'The clarification must offer the second rule id.'
        );

        $result2 = $this->chat(
            'Nimm die Regel mit der ID ' . $ruleid1 . '.',
            $threadid,
            $store,
            $runtime
        );
        if (($result2['response_type'] ?? '') !== 'confirmation_request') {
            $result2 = $this->chat(
                'Please rename the booking rule with ruleid ' . $ruleid1 . ' to "' . $newname . '".',
                $threadid,
                $store,
                $runtime
            );
        }
        $this->assertSame(
            'confirmation_request',
            (string)($result2['response_type'] ?? ''),
            'The rule-id answer must be consumable and lead to a confirmation, got: '
                . $this->payload_text($result2)
        );

        $confirm = $this->confirm_pending_result($result2, (int)$threadid, $store, false);
        $this->assertTrue((bool)($confirm['success'] ?? false), (string)($confirm['message'] ?? ''));

        $saved1 = $DB->get_record('booking_rules', ['id' => $ruleid1], '*', MUST_EXIST);
        $json1 = json_decode((string)$saved1->rulejson);
        $this->assertSame($newname, (string)($json1->name ?? ''), 'The CHOSEN rule must be renamed.');

        $saved2 = $DB->get_record('booking_rules', ['id' => $ruleid2], '*', MUST_EXIST);
        $json2 = json_decode((string)$saved2->rulejson);
        $this->assertSame($twinname, (string)($json2->name ?? ''), 'The OTHER twin rule must stay untouched.');
    }

    /**
     * Editing a system rule requires the capability where the rule lives: grant
     * mod/booking:editbookingrules at the SYSTEM context via a dedicated role.
     *
     * @param int $userid
     * @return void
     */
    private function grant_system_rule_editing(int $userid): void {
        $systemcontext = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('mod/booking:editbookingrules', CAP_ALLOW, $roleid, $systemcontext->id);
        role_assign($roleid, $userid, $systemcontext->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Seed a realistic SYSTEM-context react-on-event rule via the mod_booking generator
     * (the full rulejson shape survives the rules_info save pipeline on update).
     *
     * @param string $displayname
     * @return int The rule id.
     */
    private function seed_system_rule(string $displayname): int {
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $rule = $plugingenerator->create_rule([
            'name' => $displayname,
            'conditionname' => 'select_user_from_event',
            'contextid' => (int)\context_system::instance()->id,
            'conditiondata' => '{"userfromeventtype":"userid"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"",'
                . '"subject":"' . $displayname . '","template":"Real LLM rule body","templateformat":1}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_booked",'
                . '"aftercompletion":0,"cancelrules":[],"condition":"0"}',
        ]);
        return (int)$rule->id;
    }
}
