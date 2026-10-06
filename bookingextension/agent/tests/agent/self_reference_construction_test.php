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
 * Self-reference resolution in the engine (#2246): contract line, requester identity, structural strip.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\requester_reference;
use bookingextension_agent\local\wizard\services\runtime_context_block_builder;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Taskflow baseline threads 1225/1226 ("ich möchte meine Zuweisungen aufrufen"): the constructor
 * filled userquery with "me" and the skill asked who was meant. The omit rule lived only in the
 * schema text of book_users (a3e97ec). Now the construction contract carries the rule for every
 * skill, the runtime state names the requester as anonymized identity, and the decision service
 * drops a person parameter bound to that identity before the skill runs — no skill needs an
 * omit sentence of its own.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 * @covers \bookingextension_agent\local\wizard\services\runtime_context_block_builder
 * @covers \bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder
 */
final class self_reference_construction_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        $this->grant_agent_capabilities_to_editingteacher();
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
    }

    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * Constructor 'skill_call' for a read-only skill with the given parameters.
     *
     * @param string $skill
     * @param array $parameters
     * @return string
     */
    private function constructor_skill_call(string $skill, array $parameters): string {
        return json_encode([
            'response_type' => 'skill_call',
            'message' => '',
            'commands' => [['skill' => $skill, 'version' => 1, 'parameters' => $parameters]],
            'next_step_intent' => '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Input of the last run's first command of a thread (what actually reached the skill).
     *
     * @param int $threadid
     * @return array
     */
    private function executed_input(int $threadid): array {
        global $DB;
        $runs = $DB->get_records('bx_agent_ai_runs', ['threadid' => $threadid], 'id DESC', '*', 0, 1);
        $run = reset($runs);
        $this->assertNotFalse($run, 'the read-only command must have executed');
        $commands = json_decode((string)$run->commandsjson, true);
        return (array)(($commands[0]['input'] ?? []));
    }

    /**
     * The constructor sees the rule and the requester's anonymized identity; a person parameter
     * bound to that identity carries the requester marker so the skill acts for the current user.
     */
    public function test_person_parameter_bound_to_requester_carries_the_marker(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;

        $tokens = runtime_context_block_builder::current_user_identity_tokens(
            new privacy_anonymizer($store),
            $threadid,
            $store
        );
        $this->assertNotEmpty($tokens, 'the requester identity must be expressible as anonymized tokens');
        $nametoken = $tokens[0];

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.diagnose_user_booking'),
            // The model bound the self-reference to the requester's own identity token (as the
            // runtime state suggests) instead of omitting the field.
            $this->constructor_skill_call('mod_booking.diagnose_user_booking', [
                'userquery' => $nametoken,
                'optionquery' => 'Sprechstunde',
            ]),
        ]);

        $this->chat('Was habe ich bei der Sprechstunde gebucht?', $threadid, $store, $runtime);

        // Contract + identity reached the constructor prompt (second planner call; the loop may
        // re-plan once more after the read-only execution).
        $this->assertGreaterThanOrEqual(2, count($this->scriptedplannerprompts));
        $constructorprompt = $this->scriptedplannerprompts[1];
        // Wave 32 (frozen prompt spec): the requester rule stands once, in the constructor template (rule 4).
        $this->assertStringContainsString('When the request is about the requester themselves, leave every', $constructorprompt);
        $this->assertStringContainsString('current_user: ' . $nametoken, $constructorprompt);
        $this->assertStringNotContainsString(fullname($this->teacher), $constructorprompt);

        // The requester's identity never reached the skill; the requester marker did (#2569), which every person
        // resolver reads as the acting user.
        $input = $this->executed_input($threadid);
        $this->assertSame(requester_reference::MARKER, (string)($input['userquery'] ?? ''));
        $this->assertSame('Sprechstunde', (string)($input['optionquery'] ?? ''));
    }

    /**
     * A person parameter naming somebody else is left untouched — the marking is identity equality only.
     */
    public function test_person_parameter_naming_another_person_is_kept(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.diagnose_user_booking'),
            $this->constructor_skill_call('mod_booking.diagnose_user_booking', [
                'userquery' => 'ANON_USER_7_both',
                'optionquery' => 'Sprechstunde',
            ]),
        ]);

        $this->chat('Was hat sie bei der Sprechstunde gebucht?', $threadid, $store, $runtime);

        $input = $this->executed_input($threadid);
        $this->assertSame('ANON_USER_7_both', (string)($input['userquery'] ?? ''));
    }
}
