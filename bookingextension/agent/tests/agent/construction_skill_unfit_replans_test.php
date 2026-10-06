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
 * A construction that states the selected skill does not fit lets selection choose again.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * SS-4, thread 11651 (baseline run 39): the selector chose mod_booking.get_option_details for "Ich will
 * Teilnehmerlisten drucken - gibt's dafür irgendeine verborgene Funktion bei dir?". The constructor saw that the skill
 * cannot do it and asked; a repair round then forced the unfit skill anyway (46 such cases since 2026-09-19). Wave 30
 * (E4): the construction states it structurally ("skill_fits": false) and selection chooses again once, told which
 * skill did not fit and why. The engine inspects the flag, never the wording.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\interpreter
 * @covers \bookingextension_agent\local\wizard\services\turn_skill_exclusions
 */
final class construction_skill_unfit_replans_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** The user turn of thread 11651. */
    private const PROMPT = 'Ich will Teilnehmerlisten drucken — gibt\'s dafür irgendeine verborgene Funktion bei dir?';

    /** The constructor's real reason in thread 11651, now with the structural flag. */
    private const UNFIT_REASON = 'Ich kann dir zwar Details zu einer Buchungsoption anzeigen, aber das Abrufen von '
        . 'Teilnehmerlisten zum Drucken gehört nicht dazu.';

    /**
     * Provider and capabilities.
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
    }

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * A constructor clarification that states the skill does not fit.
     *
     * @return string
     */
    private function unfit_clarification(): string {
        return json_encode([
            'response_type' => 'clarification',
            'message' => self::UNFIT_REASON,
            'next_step_intent' => '',
            'lang' => 'de',
            'user_lang' => 'de',
            'skill_fits' => false,
            'commands' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Selection chooses again with the unfit skill and the reason in view; the new skill runs.
     */
    public function test_selection_chooses_again_when_the_skill_does_not_fit(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            [
                $this->selector_skill_call('mod_booking.get_option_details'),
                $this->selector_skill_call('wizard.search_skills'),
                $this->planner_sufficient('Dafür gibt es keinen eigenen Skill.'),
            ],
            [
                $this->unfit_clarification(),
                $this->constructor_skill_call('wizard.search_skills', ['query' => 'Teilnehmerliste drucken']),
            ]
        );

        $result = $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        global $DB;
        $sequence = $this->scripted_phase_sequence();
        $this->assertSame('SCS', substr($sequence, 0, 3), $sequence . ' ' . json_encode($result['issue_codes'] ?? []));
        $this->assertNotSame('error', (string)($result['response_type'] ?? ''));
        $ran = [];
        foreach ($DB->get_records('bx_agent_ai_runs', ['threadid' => (int)$threadid]) as $run) {
            foreach ((array)json_decode((string)$run->commandsjson, true) as $command) {
                $ran[] = (string)($command['skill'] ?? '');
            }
        }
        $this->assertContains('wizard.search_skills', $ran, 'the newly selected skill ran');
        $this->assertNotContains('mod_booking.get_option_details', $ran, 'the unfit skill never ran');
        $selectorprompts = array_values(array_filter(
            $this->scriptedplannerprompts,
            static fn(string $p): bool => strpos($p, 'phase_handoff.selection=') === false
        ));
        $this->assertStringContainsString('UNFIT_SKILL: mod_booking.get_option_details', $selectorprompts[1]);
        $this->assertStringContainsString('Teilnehmerlisten', $selectorprompts[1], 'the reason reaches selection');
    }

    /**
     * No skill fits after the one re-plan: the turn ends as a question, never as an error, and never loops.
     */
    public function test_an_unfit_skill_twice_ends_as_a_question(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            array_fill(0, 4, $this->selector_skill_call('mod_booking.get_option_details')),
            array_fill(0, 4, $this->unfit_clarification())
        );

        $result = $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''), json_encode($result['issue_codes'] ?? []));
        $this->assertLessThanOrEqual(2, substr_count($this->scripted_phase_sequence(), 'S'), 'one re-plan, no loop');
        $this->assertStringNotContainsString('CONSTRUCTION_', (string)($result['message'] ?? ''));
    }

    /**
     * Wave 32 (frozen prompt spec, consultant test 3): a skill the construction found unfit stays excluded for the rest
     * of the turn. Selection picking it again never reaches a second construction (no A -> B -> A); the turn ends as
     * a question carrying the construction's own reason.
     */
    public function test_an_excluded_skill_is_not_constructed_again(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            array_fill(0, 4, $this->selector_skill_call('mod_booking.get_option_details')),
            array_fill(0, 4, $this->unfit_clarification())
        );

        $result = $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        $constructorprompts = array_values(array_filter(
            $this->scriptedplannerprompts,
            static fn(string $p): bool => strpos($p, 'phase_handoff.selection=') !== false
        ));
        $this->assertCount(1, $constructorprompts, $this->scripted_phase_sequence());
        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));
        $this->assertContains('CONSTRUCTION_SKILL_UNFIT', (array)($result['issue_codes'] ?? []));
    }

    /**
     * A plain clarification (a value only the user can give) is not a skill mismatch: no re-selection.
     */
    public function test_a_plain_question_does_not_trigger_reselection(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call('mod_booking.get_option_details')],
            [$this->constructor_clarification('Welche Buchungsoption meinst du?')]
        );

        $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        $this->assertSame(1, substr_count($this->scripted_phase_sequence(), 'S'), $this->scripted_phase_sequence());
    }
}
