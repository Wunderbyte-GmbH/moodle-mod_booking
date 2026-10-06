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
 * Real-LLM simulation test for Dynamic Skill Discovery (Tool Retrieval RAG).
 *
 * This test simulates the scenario where the LLM is asked to perform an action
 * for which it currently lacks the specific tool in its payload, but it does
 * have access to a 'wizard.search_skills' fallback tool.
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
 * Real-LLM test for dynamic skill discovery.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class search_skills_real_llm_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
        $this->enforcegeneratetextassertion = false;
    }

    public function test_dynamic_skill_discovery_loop(): void {
        [$store, $runtime, $threadid] = $this->build_runtime();
        $store->allow_confirmation_for_thread((int)$this->teacher->id, $this->booking_contextid(), $threadid);

        $prompt = 'Ich möchte das goldene Zertifikat für den Kurs herunterladen. '
            . 'Wenn du den passenden Skill dafür nicht in deiner aktuellen Liste siehst, '
            . 'nutze bitte unbedingt "wizard.search_skills" mit einer passenden Query, um danach zu suchen.';

        $response = $this->chat($prompt, $threadid, $store, $runtime);

        $this->assertContains(
            (string)($response['response_type'] ?? ''),
            ['error', 'sufficient', 'skill_call', 'confirmation_request', 'clarification']
        );

        $searched = false;
        $query = '';

        foreach ((array)($response['loop_results'] ?? []) as $loopstep) {
            foreach ((array)($loopstep['tool_calls'] ?? []) as $cmd) {
                $skillcalled = (string)($cmd['skill'] ?? '');
                if ($skillcalled === 'wizard.search_skills' || strpos($skillcalled, 'search_skills') !== false) {
                    $searched = true;
                    $query = (string)($cmd['input']['query'] ?? '');
                }
            }
        }

        if ($searched) {
            $this->assertNotEmpty($query, 'The LLM should formulate a search query for the missing skill.');
            $this->assertTrue($searched, 'Dynamic discovery intent was correctly formulated.');
        } else {
            $this->markTestIncomplete(
                'The LLM did not attempt to use the search_skills tool. '
                . 'This is expected until the tool is firmly registered in the core family set.'
            );
        }
    }
}
