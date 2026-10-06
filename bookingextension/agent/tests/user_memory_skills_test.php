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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\wizard\skills\forget_skill;
use bookingextension_agent\local\wizard\wizard\skills\recall_memory_skill;
use bookingextension_agent\local\wizard\wizard\skills\list_memories_skill;
use bookingextension_agent\local\wizard\wizard\skills\remember_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\services\user_memory_service;
use bookingextension_agent\local\wizard\skill_contract_validator;
use bookingextension_agent\local\wizard\skill_discovery;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Contract + behaviour tests for the user-memory skills.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\wizard\skills\remember_skill
 * @covers     \bookingextension_agent\local\wizard\wizard\skills\forget_skill
 * @covers     \bookingextension_agent\local\wizard\wizard\skills\list_memories_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_memory_skills_test extends advanced_testcase {
    /**
     * The three skills are auto-discovered (no manual provider registration needed).
     */
    public function test_skills_are_discovered(): void {
        $names = array_keys(skill_discovery::get_skill_instances('bookingextension_agent'));
        $this->assertContains('wizard.remember', $names);
        $this->assertContains('wizard.forget', $names);
        $this->assertContains('wizard.list_memories', $names);
    }

    /**
     * recall_memory surfaces a readable, timezone-adjusted timestamp per message in its observation,
     * so temporal recalls keep the "when", not just the "what".
     *
     * @covers \bookingextension_agent\local\wizard\wizard\skills\recall_memory_skill
     */
    public function test_recall_memory_observation_includes_readable_timestamps(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $contextid = (int)\context_system::instance()->id;

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$user->id, $contextid);

        $ts = make_timestamp(2026, 6, 1, 10, 0);
        $DB->insert_record('bx_agent_ai_messages', (object)[
            'threadid' => (int)$thread->id,
            'userid' => (int)$user->id,
            'role' => 'user',
            'content' => 'How many options are there?',
            'structuredjson' => null,
            'timecreated' => $ts,
        ]);
        $DB->insert_record('bx_agent_ai_messages', (object)[
            'threadid' => (int)$thread->id,
            'userid' => (int)$user->id,
            'role' => 'assistant',
            'content' => 'There are three options.',
            'structuredjson' => null,
            'timecreated' => $ts + 60,
        ]);
        // The recall_memory skill returns a PREVIOUS conversation, not the current active one; mark it archived.
        $DB->set_field('bx_agent_ai_threads', 'status', 'archived', ['id' => (int)$thread->id]);

        $result = (new recall_memory_skill())->execute(['mode' => 'last_thread'], $contextid, (int)$user->id);

        $this->assertSame('executed', $result['status']);
        $observation = (string)$result['observation_full'];
        $this->assertStringContainsString('USER_PREVIOUS', $observation);
        // The readable, timezone-adjusted timestamp of the message appears in the observation.
        $this->assertStringContainsString(userdate($ts, get_string('strftimedatetimeshort', 'langconfig')), $observation);
    }

    /**
     * Each skill's auto-generated Moodle capability is defined in db/access.php and the
     * real executability gate allows it (guards against the silent runtime-deny that
     * happens when a new skill's capability is forgotten in access.php).
     */
    public function test_skills_pass_real_executability_gate(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Skills are default-off until explicitly enabled; isolate the capability/context gate.
        set_config('aiskillenableall', '1', 'bookingextension_agent');

        $context = \context_system::instance();
        $registry = skill_registry_factory::get_default();
        $evaluator = new skill_executability_evaluator($registry, new authorization_service());

        foreach (['wizard.remember', 'wizard.forget', 'wizard.list_memories'] as $name) {
            $capability = skill_contract_validator::build_skill_capability_name('bookingextension/agent', $name);
            $this->assertNotEmpty(
                get_capability_info($capability),
                "Capability {$capability} for {$name} is not defined in db/access.php."
            );

            $evaluation = $evaluator->evaluate_skill($name, (int)$USER->id, (int)$context->id);
            $this->assertSame(
                'allow',
                $evaluation['executable_state'],
                $name . ' blocked by gate: ' . (string)$evaluation['deny_reason']
            );
        }
    }

    /**
     * Risk-class contract: remember is R0 (readonly-treated, no confirmation —
     * decision 2026-06-11, write only to the user's own preference store),
     * forget R2 (explicit confirm), list R0.
     */
    public function test_risk_class_contract(): void {
        $remember = new remember_skill();
        $this->assertSame('wizard.remember', $remember->get_name());
        $this->assertTrue($remember->is_read_only());
        $this->assertSame(skill_risk_class::R0, $remember->get_risk_class());

        $forget = new forget_skill();
        $this->assertSame('wizard.forget', $forget->get_name());
        $this->assertFalse($forget->is_read_only());
        $this->assertSame(skill_risk_class::R2, $forget->get_risk_class());

        $list = new list_memories_skill();
        $this->assertSame('wizard.list_memories', $list->get_name());
        $this->assertTrue($list->is_read_only());
        $this->assertSame(skill_risk_class::R0, $list->get_risk_class());
    }

    /**
     * The three memory skills separate themselves from recall_memory on their CARD.
     *
     * Wave 17 (#2453) moved the boundary out of the description: the description is embedding anchor #0,
     * and a vector carries no negation, so "NOT for previous conversation" landed right next to "previous
     * conversation" and named the competitor on top of it — it attracted exactly the recall requests it was
     * written to repel. The NOT: card line says the same thing where the selector, which does read
     * negation, sees it, and the anchor builder does not.
     */
    public function test_descriptions_disambiguate_from_recall_memory(): void {
        foreach ([new remember_skill(), new list_memories_skill(), new forget_skill()] as $skill) {
            $schema = (array)$skill->get_schema();
            $this->assertStringContainsStringIgnoringCase(
                'conversation',
                (string)($schema['not'] ?? ''),
                $skill->get_name() . ' must fence itself off against past-conversation recall'
            );
            $this->assertStringNotContainsStringIgnoringCase(
                'recall_memory',
                (string)($schema['description'] ?? ''),
                $skill->get_name() . ' must not carry the competitor into its own embedding anchor'
            );
        }
    }

    /**
     * remember persists via the service and reports the limit message at threshold.
     */
    public function test_remember_executes_and_reports_limit(): void {
        $this->resetAfterTest();
        $userid = (int)$this->getDataGenerator()->create_user()->id;
        $skill = new remember_skill();

        $result = $skill->execute(['memory' => 'I prefer morning bookings'], 0, $userid);
        $this->assertSame('executed', $result['status']);
        $this->assertSame('ok', $result['memory_status']);
        $this->assertCount(1, (new user_memory_service())->get_all($userid));

        // Duplicate is reported but not stored again.
        $dup = $skill->execute(['memory' => 'I prefer morning bookings'], 0, $userid);
        $this->assertSame('duplicate', $dup['memory_status']);
        $this->assertCount(1, (new user_memory_service())->get_all($userid));
    }

    /**
     * remember structural validation rejects empty and over-long input.
     */
    public function test_remember_check_structure(): void {
        $skill = new remember_skill();
        $this->assertTrue($skill->check_structure(['memory' => 'ok'])['valid']);
        $this->assertFalse($skill->check_structure(['memory' => '   '])['valid']);
        $this->assertFalse(
            $skill->check_structure(['memory' => str_repeat('a', user_memory_service::MAX_CHARS_PER_MEMORY + 1)])['valid']
        );
    }

    /**
     * list_memories returns ids in the observation for a follow-up forget.
     */
    public function test_list_memories_empty_and_populated(): void {
        $this->resetAfterTest();
        $userid = (int)$this->getDataGenerator()->create_user()->id;
        $skill = new list_memories_skill();

        $empty = $skill->execute([], 0, $userid);
        $this->assertSame([], $empty['memories']);

        $service = new user_memory_service();
        $id = (int)$service->add($userid, 'My room is B12')['id'];

        $populated = $skill->execute([], 0, $userid);
        $this->assertCount(1, $populated['memories']);
        $this->assertSame($id, $populated['memories'][0]['id']);
        $this->assertArrayHasKey('relevant_for', $populated['memories'][0]);
        $this->assertStringContainsString('id=' . $id, $populated['observation_full']);
    }

    /**
     * remember forwards the relevant_for channels to the service.
     */
    public function test_remember_persists_scopes(): void {
        $this->resetAfterTest();
        $userid = (int)$this->getDataGenerator()->create_user()->id;
        $skill = new remember_skill();

        $skill->execute(
            ['memory' => 'Address me as Dr X', 'relevant_for' => [user_memory_service::SCOPE_SYNCHRONIZATION]],
            0,
            $userid
        );

        $record = (new user_memory_service())->get_all($userid)[0];
        $this->assertSame(
            [user_memory_service::SCOPE_SYNCHRONIZATION],
            user_memory_service::parse_scopes($record->scopes)
        );
    }

    /**
     * forget requires a query or an id.
     */
    public function test_forget_requires_query_or_id(): void {
        $skill = new forget_skill();
        $this->assertFalse($skill->check_structure([])['valid']);
        $this->assertTrue($skill->check_structure(['query' => 'morning'])['valid']);
        $this->assertTrue($skill->check_structure(['id' => 5])['valid']);
    }

    /**
     * forget by single-match query prepares a confirmable delete (does not delete yet).
     */
    public function test_forget_single_match_prepares_delete(): void {
        $this->resetAfterTest();
        $userid = (int)$this->getDataGenerator()->create_user()->id;
        $service = new user_memory_service();
        $id = (int)$service->add($userid, 'I prefer morning bookings')['id'];

        $skill = new forget_skill();
        $preflight = $skill->preflight(['query' => 'morning'], 0, $userid);

        $this->assertSame('pass', $preflight->status);
        $this->assertSame($id, (int)$preflight->preparedinput['id']);
        // Nothing deleted during preflight.
        $this->assertCount(1, $service->get_all($userid));
    }

    /**
     * forget with zero or multiple matches returns a clarification, never deletes.
     */
    public function test_forget_zero_and_multi_match_clarify(): void {
        $this->resetAfterTest();
        $userid = (int)$this->getDataGenerator()->create_user()->id;
        $service = new user_memory_service();
        $service->add($userid, 'I prefer morning bookings');
        $service->add($userid, 'Morning meetings are fine');

        $skill = new forget_skill();

        $nomatch = $skill->preflight(['query' => 'nonexistent'], 0, $userid);
        $this->assertSame('hard_block', $nomatch->status);
        $this->assertSame('needs_clarification', $nomatch->issues[0]['severity']);

        $multi = $skill->preflight(['query' => 'morning'], 0, $userid);
        $this->assertSame('hard_block', $multi->status);
        $this->assertSame('needs_clarification', $multi->issues[0]['severity']);

        // No deletions from a clarification.
        $this->assertCount(2, $service->get_all($userid));
    }

    /**
     * forget by id is ownership-checked in preflight and deletes on execute.
     */
    public function test_forget_by_id_ownership_and_delete(): void {
        $this->resetAfterTest();
        $owner = (int)$this->getDataGenerator()->create_user()->id;
        $other = (int)$this->getDataGenerator()->create_user()->id;
        $service = new user_memory_service();
        $id = (int)$service->add($owner, 'owned memory')['id'];

        $skill = new forget_skill();

        // Another user cannot resolve the id.
        $foreign = $skill->preflight(['id' => $id], 0, $other);
        $this->assertSame('hard_block', $foreign->status);

        // Owner resolves and then executes the delete.
        $ok = $skill->preflight(['id' => $id], 0, $owner);
        $this->assertSame('pass', $ok->status);

        $result = $skill->execute($ok->preparedinput, 0, $owner);
        $this->assertSame('executed', $result['status']);
        $this->assertCount(0, $service->get_all($owner));
    }
}
