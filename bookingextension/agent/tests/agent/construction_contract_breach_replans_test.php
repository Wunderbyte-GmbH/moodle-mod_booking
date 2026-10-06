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
 * A construction that breaks the output contract is re-planned through the selector and still ends as the card.
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
 * Baseline run 15 (threads 4699, 4808, 4829, 4830): the constructor described the mutation completely and still sent
 * `commands: []`. Until wave 30 a repair round called the constructor a second time with a repair text - a step the
 * flowchart does not know. The original fault stays pinned here as an OUTCOME: the turn ends as the confirmation card
 * with the command, whichever mechanism gets it there. Wave 30: the breach is re-planned once through the selector,
 * and the flow invariant of the architecture holds: every construction follows a selection, a constructor call never
 * follows a constructor call.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\services\planner_phase_service
 */
final class construction_contract_breach_replans_test extends abstract_agent_testcase {
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
     * The four run-15 threads with the constructor's real broken answer and a real correct answer of the same prompt.
     *
     * @return array
     */
    public static function run15_provider(): array {
        $broken = static fn(string $message, string $lang): string => json_encode([
            'response_type' => 'confirmation_request',
            'message' => $message,
            'next_step_intent' => '',
            'lang' => $lang,
            'user_lang' => $lang,
            'commands' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return [
            'thread 4829 (fr, recreate_skill_catalog)' => [
                "Reconstruis l'index d'embeddings du catalogue de skills, il manque des entrées.",
                'wizard.recreate_skill_catalog',
                $broken('Rebuild the embeddings index for the skill catalog to add missing entries. This will queue a '
                    . 'background job to regenerate skill embeddings for all skills, ensuring the catalog includes all '
                    . 'available entries.', 'fr'),
                [],
                '',
            ],
            'thread 4830 (de, recreate_skill_catalog)' => [
                'Bitte die Skill-Katalog-CSV neu erzeugen lassen.',
                'wizard.recreate_skill_catalog',
                $broken('Soll der Skill-Katalog-CSV neu erstellt werden?', 'de'),
                [],
                '',
            ],
            'thread 4699 (fr, update_option)' => [
                'Renomme « Rooftop Yoga » en « Yoga au coucher du soleil » et ajoute une date supplémentaire à la fin du '
                    . 'mois prochain.',
                'mod_booking.update_option',
                $broken('Ich werde "Rooftop Yoga" in "Yoga au coucher du soleil" umbenennen und einen Termin am '
                    . '31. Oktober 2026 um 09:00 Uhr hinzufügen.', 'de'),
                ['optionquery' => 'Rooftop Yoga', 'text' => 'Yoga au coucher du soleil'],
                'Rooftop Yoga',
            ],
            'thread 4808 (de, generate_questions)' => [
                'Ich diktiere: Frage "Hauptstadt von Frankreich?", Antwort "Paris" — mach daraus eine Quizfrage in Moodle.',
                'question.generate_questions',
                $broken('Möchtest du die Quizfrage "Hauptstadt von Frankreich?" mit der Antwort "Paris" in den '
                    . 'Moodle-Fragenpool des Kurses "ai" einfügen?', 'de'),
                ['content' => "Frage: Hauptstadt von Frankreich?\nAntwort: Paris", 'count' => 1, 'outputlang' => 'de'],
                '',
            ],
        ];
    }

    /**
     * Run one run-15 turn with the phase-aware script and return the result.
     *
     * @param string $prompt
     * @param string $skill
     * @param string $broken
     * @param array $parameters
     * @param string $option Booking option to create first ('' = none).
     * @return array
     */
    private function run_breach_turn(string $prompt, string $skill, string $broken, array $parameters, string $option): array {
        if ($skill === 'wizard.recreate_skill_catalog') {
            // Run 15 ran as a site manager: the catalogue rebuild is manager/admin only (audit CAP-03) and needs
            // moodle/site:config at execution. The acting user of this test gets exactly that standing.
            global $CFG;
            $CFG->siteadmins = $CFG->siteadmins . ',' . (int)$this->teacher->id;
        }
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        if ($option !== '') {
            $this->create_option($option);
        }
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call($skill), $this->selector_skill_call($skill)],
            [$broken, $this->good_card($skill, $parameters)]
        );
        return $this->chat($prompt, (int)$threadid, $store, $runtime);
    }

    /**
     * A correct constructor card as the provider sends it: empty parameters are a JSON object (thread 10052).
     *
     * @param string $skill
     * @param array $parameters
     * @return string
     */
    private function good_card(string $skill, array $parameters): string {
        return json_encode([
            'response_type' => 'confirmation_request',
            'message' => 'Please confirm.',
            'next_step_intent' => '',
            'lang' => 'en',
            'user_lang' => 'en',
            'commands' => [['skill' => $skill, 'version' => 1, 'parameters' => (object)$parameters]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The original fault stays fixed: the turn ends as the confirmation card carrying the command.
     *
     * @dataProvider run15_provider
     * @param string $prompt
     * @param string $skill
     * @param string $broken
     * @param array $parameters
     * @param string $option
     */
    public function test_a_card_without_commands_still_ends_as_the_card(
        string $prompt,
        string $skill,
        string $broken,
        array $parameters,
        string $option
    ): void {
        $result = $this->run_breach_turn($prompt, $skill, $broken, $parameters, $option);

        $this->assertSame('confirmation_request', (string)($result['response_type'] ?? ''), json_encode($result));
        $skills = array_map(static fn($c): string => (string)($c['skill'] ?? ''), (array)($result['commands'] ?? []));
        $this->assertContains($skill, $skills, 'the card carries the command');
    }

    /**
     * The re-plan runs through the selector: the selector is asked again, the next construction carries the
     * command, and the turn ends as the card (S C S C). Exactly one re-plan.
     */
    public function test_a_breach_is_replanned_once_through_the_selector(): void {
        [$prompt, $skill, $broken, $parameters, $option] = self::run15_provider()['thread 4699 (fr, update_option)'];
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        $this->create_option($option);
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call($skill), $this->selector_skill_call($skill)],
            [$broken, $this->good_card($skill, $parameters)]
        );

        $result = $this->chat($prompt, (int)$threadid, $store, $runtime);

        $this->assertSame(
            'confirmation_request',
            (string)($result['response_type'] ?? ''),
            $this->scripted_phase_sequence() . ' ' . json_encode($result['issue_codes'] ?? [])
        );
        $this->assertSame('SCSC', $this->scripted_phase_sequence(), 'the selector re-planned');
    }

    /**
     * Unhealed after the one re-plan, the turn ends as the honest question the constructor wrote - never as an error.
     */
    public function test_an_unhealed_breach_ends_as_a_question_not_an_error(): void {
        [$prompt, $skill, $broken, $parameters, $option] = self::run15_provider()['thread 4699 (fr, update_option)'];
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        $this->create_option($option);
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            array_fill(0, 4, $this->selector_skill_call($skill)),
            array_fill(0, 4, $broken)
        );

        $result = $this->chat($prompt, (int)$threadid, $store, $runtime);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''), json_encode($result));
        $this->assertLessThanOrEqual(2, substr_count($this->scripted_phase_sequence(), 'S'), 'one re-plan, no loop');
        $this->assertStringNotContainsString('CONTRACT_', (string)($result['message'] ?? ''), 'no code in the user text');
    }

    /**
     * Flow invariant: a constructor call never follows a constructor call; every construction follows a selection.
     *
     * @dataProvider run15_provider
     * @param string $prompt
     * @param string $skill
     * @param string $broken
     * @param array $parameters
     * @param string $option
     */
    public function test_every_construction_follows_a_selection(
        string $prompt,
        string $skill,
        string $broken,
        array $parameters,
        string $option
    ): void {
        $this->run_breach_turn($prompt, $skill, $broken, $parameters, $option);

        $sequence = $this->scripted_phase_sequence();
        $this->assertStringNotContainsString('CC', $sequence, 'phase sequence ' . $sequence);
        $this->assertStringStartsWith('SC', $sequence);
    }
}
