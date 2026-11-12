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
 * A site administrator without any role assignment is diagnosed as a site administrator, in the chat turn.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\agent_runtime;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_registry;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Replay of thread 17 (2026-10-27, local reproduction of Wunderbyte-GmbH/Wunderbyte-GmbH#2625).
 *
 * The site administrator, with no role assignment in the course, asked on a booking page "Which roles do I have in
 * this course, may I add activities?". The planner ran core.get_current_user, then core.diagnose_permissions with
 * moodle/course:manageactivities (R0 chat path, no preflight). The skill never asked is_siteadmin(): the check said
 * "You HAVE …" without any reason, and in role mode (the turn reported from demo2) the only row was the warning
 * "No role assignments along this chain", so the answer became "no roles, therefore not allowed".
 *
 * The planner outputs below are the ones of thread 17, verbatim (bx_agent_ai_llm_debug ids 442, 443, 446, 447, 448).
 * The demo2 trace is not on this site, so the role-mode replay keeps thread 17's outputs and lets the constructor
 * send no capability, which is what selects role mode.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\core\skills\diagnose_permissions_skill
 */
final class diagnose_permissions_siteadmin_replay_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** The user turn of thread 17. */
    private const QUESTION = 'Which roles do I have in this course, may I add activities?';

    /** Selector, step 1 (id 442). */
    private const SELECTOR_CURRENT_USER = '{"response_type": "skill_call", "commands": [{"skill": "core.get_current_user", '
        . '"input": {}}], "planned_steps": [{"intent": "check capabilities to add activities"}], '
        . '"next_step_intent": "check capabilities", "message": "", "lang": "en", "user_lang": "en"}';

    /** Constructor, step 1 (id 443). */
    private const CONSTRUCTOR_CURRENT_USER = '{"response_type": "skill_call", "commands": [{"skill": "core.get_current_user", '
        . '"version": 1, "parameters": {}}], "message": "", "next_step_intent": "", "lang": "en", "user_lang": "en"}';

    /** Selector, step 2 (id 446). */
    private const SELECTOR_DIAGNOSE = '{ "response_type": "skill_call", "commands": [ { "skill": "core.diagnose_permissions", '
        . '"input": {} } ], "planned_steps": [], "next_step_intent": "", "message": "", "lang": "en", "user_lang": "en" }';

    /** Constructor, step 2 (id 447). */
    private const CONSTRUCTOR_DIAGNOSE = '{"response_type":"skill_call","commands":[{"skill":"core.diagnose_permissions",'
        . '"version":1,"parameters":{"capability":"moodle/course:manageactivities"}}],"next_step_intent":"","lang":"en",'
        . '"user_lang":"en"}';

    /** Constructor, step 2 without a capability: role mode (the demo2 turn). */
    private const CONSTRUCTOR_DIAGNOSE_ROLES = '{"response_type":"skill_call","commands":[{"skill":"core.diagnose_permissions",'
        . '"version":1,"parameters":{}}],"next_step_intent":"","lang":"en","user_lang":"en"}';

    /** Selector, step 3 (id 448). */
    private const SELECTOR_SUFFICIENT = '{ "response_type": "sufficient", "commands": [], "planned_steps": [], '
        . '"next_step_intent": "", "message": "You have the editing teacher role in several courses. Regarding this booking '
        . 'instance, you have the permission to manage activities, so you may add them.", "lang": "en", "user_lang": "en" }';

    /**
     * Set up provider and capabilities.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
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
     * Thread 17 as it ran (capability mode): the capability comes only from the admin status, the result says so as a
     * structured fact, and that fact reaches the planner and the synchronizer.
     */
    public function test_thread_17_capability_mode(): void {
        $result = $this->run_admin_turn(self::CONSTRUCTOR_DIAGNOSE);

        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''), json_encode($result['issue_codes'] ?? []));
        $row = $this->diagnosis_row($result);
        $this->assertSame('executed', (string)($row['status'] ?? ''));
        $this->assertSame('capability', (string)($row['diagnosis']['mode'] ?? ''));
        $this->assertTrue($row['diagnosis']['siteadmin'] ?? null, 'the admin status is a structured fact');
        $this->assertSame('siteadmin', $row['diagnosis']['granted_by'] ?? null, 'granted only through the admin status');

        $rows = (array)($row['diagnosis']['checklist'] ?? []);
        $this->assertSame(['ok', 'ok'], array_column($rows, 'status'), 'verdict and admin row, nothing else');
        $this->assertSame(self::label('agent_diagnose_permissions_siteadmin'), (string)$rows[1]['check']);
        $this->assertSame(self::label('agent_diagnose_permissions_siteadmin_onlyadmin'), (string)$rows[1]['finding']);

        $this->assert_reaches_the_model(self::label('agent_diagnose_permissions_siteadmin_onlyadmin'));
        $this->assert_preview_shows_the_admin_row($row);
    }

    /**
     * The demo2 turn (role mode): the admin row comes first and "no roles here" is no longer a warning.
     */
    public function test_role_mode(): void {
        $result = $this->run_admin_turn(self::CONSTRUCTOR_DIAGNOSE_ROLES);

        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''), json_encode($result['issue_codes'] ?? []));
        $row = $this->diagnosis_row($result);
        $this->assertSame('executed', (string)($row['status'] ?? ''));
        $this->assertSame('roles', (string)($row['diagnosis']['mode'] ?? ''));
        $this->assertTrue($row['diagnosis']['siteadmin'] ?? null, 'the admin status is a structured fact');

        $rows = (array)($row['diagnosis']['checklist'] ?? []);
        $this->assertNotEmpty($rows);
        $this->assertSame(self::label('agent_diagnose_permissions_siteadmin'), (string)$rows[0]['check']);
        $this->assertSame(self::label('agent_diagnose_permissions_siteadmin_allcaps'), (string)$rows[0]['finding']);
        $this->assertNotContains('warn', array_column($rows, 'status'), 'no role is not a problem for an admin');
        $this->assertNotContains('fail', array_column($rows, 'status'));

        $this->assert_reaches_the_model(self::label('agent_diagnose_permissions_siteadmin_allcaps'));
        $this->assert_preview_shows_the_admin_row($row);
    }

    /**
     * Run the turn of thread 17 as the site administrator, on the booking page, with the given step-2 constructor.
     *
     * @param string $constructordiagnose
     * @return array AgentRuntime result.
     */
    private function run_admin_turn(string $constructordiagnose): array {
        $this->setAdminUser();
        $_POST['sesskey'] = sesskey();
        $adminid = (int)get_admin()->id;
        $this->assertEmpty(
            get_user_roles(\context_module::instance((int)$this->booking->cmid), $adminid, true),
            'fixture: the admin holds no role along the chain, as in thread 17'
        );

        $store = new conversation_store();
        $registry = skill_registry::make_default();
        $runtime = new agent_runtime(
            $registry,
            new orchestrator($registry, new interpreter($registry), $store),
            $store,
            new authorization_service()
        );
        $threadid = (int)$store->create_fresh_thread($adminid, $this->booking_contextid())->id;
        $this->trackedllmthreadids[] = $threadid;

        $this->install_scripted_planner([
            self::SELECTOR_CURRENT_USER,
            self::CONSTRUCTOR_CURRENT_USER,
            self::SELECTOR_DIAGNOSE,
            $constructordiagnose,
            self::SELECTOR_SUFFICIENT,
        ]);

        $precheck = (new privacy_anonymizer($store))->precheck_user_message($threadid, self::QUESTION);
        $store->add_message($threadid, 'user', (string)($precheck['sanitizedmessage'] ?? self::QUESTION));
        return $runtime->run_loop($threadid, $this->booking_contextid(), $adminid);
    }

    /**
     * The one core.diagnose_permissions result row of the turn.
     *
     * @param array $result
     * @return array
     */
    private function diagnosis_row(array $result): array {
        $found = [];
        foreach ((array)($result['loop_results'] ?? []) as $step) {
            foreach ((array)($step['results'] ?? []) as $row) {
                if (is_array($row) && isset($row['diagnosis']['mode'])) {
                    $found[] = $row;
                }
            }
        }
        $this->assertCount(1, $found, 'one permissions diagnosis in the turn: ' . json_encode($result['loop_results'] ?? []));
        return $found[0];
    }

    /**
     * The admin finding is in what the planner reads after the check and in what the synchronizer phrases from.
     *
     * @param string $finding
     */
    private function assert_reaches_the_model(string $finding): void {
        $this->assertCount(5, $this->scriptedplannerprompts, 'the five planner calls of thread 17');
        $this->assertStringContainsString($finding, (string)end($this->scriptedplannerprompts), 'planner after the check');
        $this->assertNotEmpty($this->scriptedsyncprompts, 'the synchronizer phrases the answer');
        $this->assertStringContainsString($finding, (string)end($this->scriptedsyncprompts), 'synchronizer input');
    }

    /**
     * The preview block the executor attached to the result (what the panel shows) carries the admin row.
     *
     * @param array $row
     */
    private function assert_preview_shows_the_admin_row(array $row): void {
        $this->assertNotEmpty($row['preview']['html'] ?? '', 'the result carries its preview block');
        $this->assertStringContainsString(
            s(self::label('agent_diagnose_permissions_siteadmin')),
            (string)$row['preview']['html']
        );
    }

    /**
     * An agent string.
     *
     * @param string $identifier
     * @return string
     */
    private static function label(string $identifier): string {
        return get_string($identifier, 'bookingextension_agent');
    }
}
