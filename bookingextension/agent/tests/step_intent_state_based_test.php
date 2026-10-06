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
 * The step bubble is suppressed from engine state, never from the wording of the intent.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\services\completed_command_history_service;

/**
 * Tests for the replacement of the lexical completed-action guard.
 *
 * The interpreter used to drop a planner step intent when its text matched one of seven German and English
 * regexes ("ich habe…", "i have … provided"). That is lexical detection on LLM text and therefore forbidden
 * (HARD RULE, CLAUDE.md 2026-07-10): it covers two languages out of three the baseline uses and silently does
 * nothing for the rest. The protection it was meant to give — do not announce work that already happened — is
 * derived from the thread's completed commands instead.
 *
 * @covers \bookingextension_agent\local\wizard\interpreter
 * @covers \bookingextension_agent\local\wizard\services\completed_command_history_service
 */
final class step_intent_state_based_test extends \advanced_testcase {
    /**
     * Set up the engine aliases the interpreter needs.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * A step intent survives interpretation whatever its wording: the phrasing carries no engine meaning.
     *
     * @dataProvider completed_sounding_intent_provider
     * @param string $intent
     */
    public function test_intent_survives_regardless_of_wording(string $intent): void {
        $this->resetAfterTest();

        $raw = json_encode([
            'response_type' => 'skill_call',
            'message' => 'Working on it.',
            'next_step_intent' => $intent,
            'commands' => [['skill' => 'wizard.list_skills', 'version' => 1, 'input' => []]],
            'lang' => 'de',
        ]);

        $result = (new interpreter(skill_registry::make_default()))->interpret((string)$raw, 0, 0);

        $this->assertSame($intent, (string)($result['next_step_intent'] ?? ''), json_encode($result));
    }

    /**
     * Wordings the removed regex list used to swallow, in the three baseline languages.
     *
     * @return array[]
     */
    public static function completed_sounding_intent_provider(): array {
        return [
            'english perfect' => ['I have provided the option details'],
            'german perfect' => ['Ich habe die Optionsdetails gegeben'],
            'french perfect' => ["J'ai fourni les détails de l'offre"],
            'plain next step' => ['Buchungsoption aktualisieren'],
        ];
    }

    /**
     * Engine state decides: a skill that already ran in this thread is not announced as the next step again.
     */
    public function test_completed_skill_is_recognised_from_state(): void {
        $this->resetAfterTest();

        $completed = [
            ['skill' => 'mod_booking.search_options', 'status' => 'executed'],
            ['skill' => 'mod_booking.get_option_details', 'status' => 'executed'],
        ];
        $service = new completed_command_history_service(new conversation_store());

        $this->assertTrue($service->contains_skill($completed, 'mod_booking.get_option_details'));
        $this->assertFalse($service->contains_skill($completed, 'mod_booking.update_option'));
        $this->assertFalse($service->contains_skill($completed, ''));
        $this->assertFalse($service->contains_skill([], 'mod_booking.get_option_details'));
    }

    /**
     * A skill that failed is not "completed": announcing the retry as a step is correct.
     */
    public function test_failed_skill_does_not_count_as_completed(): void {
        $this->resetAfterTest();

        $completed = [['skill' => 'mod_booking.update_option', 'status' => 'error']];
        $service = new completed_command_history_service(new conversation_store());

        $this->assertFalse($service->contains_skill($completed, 'mod_booking.update_option'));
    }
}
