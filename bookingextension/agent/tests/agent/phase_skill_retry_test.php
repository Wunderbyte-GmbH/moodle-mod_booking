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
 * One re-plan round when construction escapes the selected skill (N-591a part 2).
 *
 * Thread 591: the selector mis-picked course.add_activity, the constructor "corrected" to
 * mod_booking.update_option — the phase guard rightly blocked the skill switch, but the turn
 * ended terminally although a re-plan round could recover it (and the raw CONTRACT_VIOLATION
 * string reached the user, fixed in part 1). CONTRACT_PHASE_SKILL_NOT_ALLOWED is now loop-
 * retryable (cap 1): selection gets one second chance, guided by the repair detail naming
 * the attempted skill and the allow-list.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Deterministic re-plan test via the scripted planner seam.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\interpreter
 */
final class phase_skill_retry_test extends abstract_agent_testcase {
    use scripted_llm_trait;

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

    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * A construction that switches to a foreign skill gets exactly ONE re-plan round; the
     * second selection picks the right skill and the corrected construction stages the
     * confirmable command. The 591 replay: no raw CONTRACT_VIOLATION reaches the user.
     */
    public function test_phase_skill_not_allowed_replans_once_and_heals(): void {
        $this->gen->create_pricecategory((object)[
            'ordernum' => 1,
            'identifier' => 'default',
            'name' => 'Standard',
            'defaultvalue' => 25,
        ]);

        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            // Round 1: the selector mis-picks (591 pattern).
            $this->selector_skill_call('course.add_activity'),
            // The constructor "corrects" by switching the skill — the guard blocks this.
            $this->constructor_confirmation_request('mod_booking.create_option', [
                'text' => 'Replan Workshop',
                'prices' => ['default' => 30],
                'coursestarttime' => '2045-11-10T10:00:00',
                'courseendtime' => '2045-11-10T12:00:00',
            ]),
            // Re-plan round: selection now picks the skill the constructor needed all along.
            $this->selector_skill_call('mod_booking.create_option'),
            $this->constructor_confirmation_request('mod_booking.create_option', [
                'text' => 'Replan Workshop',
                'prices' => ['default' => 30],
                'coursestarttime' => '2045-11-10T10:00:00',
                'courseendtime' => '2045-11-10T12:00:00',
            ]),
        ]);

        $result = $this->chat(
            'Erstelle den Workshop "Replan Workshop" am 10.11.2045 von 10 bis 12 Uhr, kostet 30 Euro.',
            (int)$threadid,
            $store,
            $runtime
        );

        $this->assertSame(
            'confirmation_request',
            (string)($result['response_type'] ?? ''),
            'The re-planned construction must stage the confirmable command: '
                . json_encode($result['issue_codes'] ?? [])
        );
        $command = $this->extract_command($result, 'mod_booking.create_option');
        $this->assertNotNull($command, 'The staged command must be present after the re-plan.');

        // No planner vocabulary may reach the user on any path (N-591a part 1 guard).
        $this->assertStringNotContainsString('CONTRACT_VIOLATION', (string)($result['message'] ?? ''));

        // The re-plan must have consumed a second selector/constructor round, and its prompts
        // must carry the retry hint plus the repair detail naming the attempted skill.
        $this->assertGreaterThanOrEqual(4, count($this->scriptedplannerprompts));
        $retryprompt = implode("\n", array_slice($this->scriptedplannerprompts, 2));
        $this->assertStringContainsString(
            'RETRY_HINT',
            $retryprompt,
            'The re-plan round must see the phase-skill retry hint.'
        );
        $this->assertStringContainsString(
            'mod_booking.create_option',
            $retryprompt,
            'The repair detail must name the attempted skill so selection can reconsider.'
        );
    }
}
