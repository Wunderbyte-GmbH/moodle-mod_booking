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
 * A constructor question is the answer of the construction: nothing is forced, invented or guessed after it.
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
 * Wave 30 removed the repair round that called the constructor a second time when it had asked a question
 * (constructor_command_repair, c4e65e1): 257 such rounds since 2026-09-19, 53 clean, 48 forced an unfit skill,
 * and several "successes" were wrong - DN-3 ("cet utilisateur") diagnosed the requester, UA-4 (baseline run 26)
 * staged an activity "ai" the user never named. The originals stay pinned here as outcomes: the question stands,
 * nothing runs, no card appears for a target the user did not name, and the constructor is asked exactly once.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\planner_phase_service
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 */
final class constructor_question_stands_test extends abstract_agent_testcase {
    use scripted_llm_trait;

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
     * The real questions of the originals; a second constructor answer is scripted as a trap - it must never be used.
     *
     * @return array
     */
    public static function question_provider(): array {
        return [
            'DN-3 (thread 11600): "cet utilisateur" names nobody' => [
                'Aucune notification de la plateforme n\'arrive plus chez cet utilisateur, quelle qu\'elle soit.',
                'core.diagnose_notifications',
                'Pour diagnostiquer le problème de notifications, j\'ai besoin de savoir quel utilisateur vous concerne.',
                [],
            ],
            'UA-4 (baseline run 26): the new description is not given' => [
                'Schieb die Seite mit den Übungsdaten einen Abschnitt nach unten und pass die Beschreibung an.',
                'course.update_activity',
                'Welche neue Beschreibung soll für die Aktivität verwendet werden?',
                ['activityquery' => 'ai', 'sectiondelta' => 1],
            ],
            'L16 pattern: an update without a new title' => [
                'Benenn die Option "Repair Target" um.',
                'mod_booking.update_option',
                'Auf welchen Titel soll die Option umbenannt werden?',
                ['optionquery' => 'Repair Target', 'text' => 'Repaired Title'],
            ],
        ];
    }

    /**
     * The question stands: one construction, a clarification, nothing staged or run.
     *
     * @dataProvider question_provider
     * @param string $prompt
     * @param string $skill
     * @param string $question
     * @param array $trapparameters A card a second constructor call would return.
     */
    public function test_the_question_is_the_answer(string $prompt, string $skill, string $question, array $trapparameters): void {
        global $DB;
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        $this->create_option('Repair Target');
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call($skill)],
            [
                $this->constructor_clarification($question),
                $this->constructor_confirmation_request($skill, $trapparameters),
            ]
        );

        $result = $this->chat($prompt, (int)$threadid, $store, $runtime);

        $this->assertSame('SC', $this->scripted_phase_sequence(), 'exactly one construction after one selection');
        $this->assertSame('clarification', (string)($result['response_type'] ?? ''), json_encode($result['issue_codes'] ?? []));
        $this->assertEmpty((array)($result['commands'] ?? []), 'no card');
        $this->assertSame(0, $DB->count_records('bx_agent_ai_runs', ['threadid' => (int)$threadid]), 'nothing ran');
    }
}
