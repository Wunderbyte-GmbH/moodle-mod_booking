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
 * A forget query that matches no memory offers the stored memories as choices.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\user_memory_service;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * FOR-3, run 40 thread 12319: the constructor built query "ma préférence pour les créneaux du matin" for the stored
 * memory "Je préfère les créneaux du matin pour tous mes rendez-vous". The substring search found nothing and the
 * skill listed the memories only inside its question text, so the turn ended as that question. Wave 30 choice
 * contract: the issue carries the memories as `candidates` for the field `id`; the selector re-plans once and the
 * construction picks the memory by its id. The code never matches the words.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\wizard\skills\forget_skill
 */
final class forget_offers_memory_choices_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** The user turn of thread 12319. */
    private const PROMPT = 'Oublie ma préférence pour les créneaux du matin, ce n\'est plus d\'actualité.';

    /** The query the constructor built in thread 12319. */
    private const QUERY = 'ma préférence pour les créneaux du matin';

    /** @var int */
    private int $morningid = 0;

    /**
     * Provider, capabilities and the two memories of the acting user.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        $this->grant_agent_capabilities_to_editingteacher();
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
        $service = new user_memory_service();
        $service->add((int)$this->teacher->id, 'Meine Kostenstelle ist 4711');
        $morning = $service->add((int)$this->teacher->id, 'Je préfère les créneaux du matin pour tous mes rendez-vous');
        $this->morningid = (int)$morning['id'];
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
    }

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * The selector sees the stored memories and the next construction picks the memory by its id.
     */
    public function test_a_missed_memory_is_chosen_from_the_offered_memories(): void {
        [$store, $runtime, $threadid] = $this->build_runtime();
        $skill = 'wizard.forget';
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call($skill), $this->selector_skill_call($skill)],
            [
                $this->constructor_confirmation_request($skill, ['query' => self::QUERY]),
                $this->constructor_confirmation_request($skill, ['id' => $this->morningid]),
            ]
        );

        $result = $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        $sequence = $this->scripted_phase_sequence();
        $this->assertSame('confirmation_request', (string)($result['response_type'] ?? ''), $sequence . ' '
            . json_encode($result['issue_codes'] ?? []));
        $this->assertSame('SCSC', substr($sequence, 0, 4), 'the re-plan starts at the selector');
        $input = (array)(((array)($result['commands'] ?? []))[0]['input'] ?? []);
        $this->assertSame($this->morningid, (int)($input['id'] ?? 0), 'the chosen memory is on the card');

        $selectorprompts = array_values(array_filter(
            $this->scriptedplannerprompts,
            static fn(string $p): bool => strpos($p, 'phase_handoff.selection=') === false
        ));
        $this->assertCount(2, $selectorprompts);
        $this->assertStringContainsString('CHOICES for id', $selectorprompts[1], 'the choices name the field');
        $this->assertStringContainsString('id=' . $this->morningid, $selectorprompts[1], 'the choices reach the selector');
        $this->assertCount(
            2,
            (new user_memory_service())->get_all((int)$this->teacher->id),
            'nothing was deleted before the confirmation'
        );
    }

    /**
     * No memory fits: after the one re-plan the turn ends as the question, never as an error, without codes.
     */
    public function test_an_unmatched_memory_ends_as_the_question(): void {
        [$store, $runtime, $threadid] = $this->build_runtime();
        $skill = 'wizard.forget';
        $miss = $this->constructor_confirmation_request($skill, ['query' => self::QUERY]);
        $this->install_phase_scripted_planner(array_fill(0, 4, $this->selector_skill_call($skill)), array_fill(0, 4, $miss));

        $result = $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''), json_encode($result['issue_codes'] ?? []));
        $this->assertLessThanOrEqual(2, substr_count($this->scripted_phase_sequence(), 'S'), 'one re-plan, no loop');
        $message = (string)($result['message'] ?? '');
        $this->assertStringNotContainsString('RECOVERABLE_', $message, 'no issue code in the user text');
        $this->assertStringNotContainsString('PREFLIGHT_', $message, 'no issue code in the user text');
        $this->assertCount(2, (new user_memory_service())->get_all((int)$this->teacher->id), 'nothing was deleted');
    }
}
