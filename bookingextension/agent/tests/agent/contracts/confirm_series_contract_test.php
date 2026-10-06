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

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\services\proposed_action_preview;
use bookingextension_agent\local\wizard\services\synchronizer_prompt_builder;
use bookingextension_agent\local\wizard\skill_registry;
use PHPUnit\Framework\TestCase;

/**
 * A series confirm must say what one click does: step position, and how many follow.
 *
 * One confirmation executes exactly one queued step (deliberate design). The card and the
 * reply contract must state that — never imply that one click runs the whole batch.
 *
 * @covers \bookingextension_agent\local\wizard\services\proposed_action_preview
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_prompt_builder
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class confirm_series_contract_test extends TestCase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * A staged pair of commands.
     *
     * @return array[]
     */
    private function two_commands(): array {
        return [
            ['skill' => 'mod_booking.create_option',
                'input' => ['text' => 'Serie A', 'maxanswers' => 10, 'outputlang' => 'en']],
            ['skill' => 'mod_booking.create_option',
                'input' => ['text' => 'Serie B', 'maxanswers' => 12, 'outputlang' => 'en']],
        ];
    }

    /**
     * Multiple staged commands: every card title carries its position and the first card
     * states that confirming runs step 1 only.
     */
    public function test_multi_command_preview_numbers_the_steps(): void {
        $json = proposed_action_preview::build_preview_json($this->two_commands(), skill_registry::make_default());

        $decoded = json_decode($json, true);
        $actions = (array)($decoded['payload']['actions'] ?? []);
        $this->assertCount(2, $actions, $json);
        $this->assertStringStartsWith('(1/2)', (string)($actions[0]['title'] ?? ''), 'position must be visible');
        $this->assertStringStartsWith('(2/2)', (string)($actions[1]['title'] ?? ''));
        $this->assertStringContainsString(
            'step 1 of 2',
            (string)($actions[0]['summary'] ?? ''),
            'the card must say that confirming executes step 1 only'
        );
    }

    /**
     * A restaged single command mid-series: the card names how many confirmations follow.
     */
    public function test_restaged_command_names_the_remaining_confirmations(): void {
        $commands = [$this->two_commands()[0]];

        $json = proposed_action_preview::build_preview_json($commands, skill_registry::make_default(), 2);

        $decoded = json_decode($json, true);
        $actions = (array)($decoded['payload']['actions'] ?? []);
        $this->assertCount(1, $actions, $json);
        $this->assertStringContainsString(
            '2 more step(s)',
            (string)($actions[0]['summary'] ?? ''),
            'the remaining series length must be visible'
        );
    }

    /**
     * Wave 32 (frozen prompt spec): the one-step-per-confirmation truth is no longer restated as an engine policy. A step
     * still queued behind the waiting confirmation is named as open (synchronizer template rule 3); the engine adds
     * only the state of the turn.
     */
    public function test_awaiting_confirmation_contract_states_one_step_per_confirm(): void {
        $builder = new synchronizer_prompt_builder();

        $prompt = $builder->build_prompt(
            'SYSTEM PROMPT',
            [],
            ['some observation'],
            '',
            '',
            synchronizer_prompt_builder::CONTINUATION_AWAITING_CONFIRMATION
        );

        $this->assertStringNotContainsString('PENDING STEPS POLICY', $prompt);
        $this->assertStringContainsString('TURN STATE: this reply asks the user a question.', $prompt);
        $this->assertStringContainsString(
            "A planned step that is waiting behind the question of rule 1 is not a\n   failure: name it as still open",
            \bookingextension_agent\local\wizard\orchestrator::get_default_initial_prompt_template_for_action(
                \core_ai\aiactions\generate_text::class
            )
        );
    }
}
