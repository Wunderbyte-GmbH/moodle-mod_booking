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
 * The sibling discrimination reaches the selector and never the constructor.
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
 * Wave 17 (#2453) moved the boundary against a sibling out of the description into the IS:/NOT: keys,
 * and said they are for the SELECTOR. The byte diff of the TSA-4 constructor prompt between runs 24 and
 * 25 showed the two keys travelling in the constructor's contract JSON as well — the only change in that
 * prompt. The constructor has the selection behind it: a sentence about what a NEIGHBOUR does cannot help
 * it fill fields, costs tokens on every call, and named the very "unit list" it then asked the user for.
 * Like minimal_input, the keys are dropped from the construction entry on purpose.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\planner_phase_service
 */
final class constructor_contract_carries_no_discrimination_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /**
     * Set up provider and capabilities.
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
     * The selector card prints the boundary; the constructor contract does not carry it.
     *
     * mod_booking.update_option fences itself off against bulk_update_options on its NOT: line.
     */
    public function test_selector_sees_the_boundary_and_the_constructor_does_not(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        $this->create_option('Boundary Target');
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.update_option'),
            $this->constructor_confirmation_request('mod_booking.update_option', [
                'optionquery' => 'Boundary Target',
                'text' => 'Renamed Target',
            ]),
        ]);

        $this->chat('Benenn die Option "Boundary Target" auf "Renamed Target" um.', (int)$threadid, $store, $runtime);

        $this->assertCount(2, $this->scriptedplannerprompts, 'One selector and one constructor call are consumed.');
        $selector = (string)$this->scriptedplannerprompts[0];
        $constructor = (string)$this->scriptedplannerprompts[1];

        // The clause may continue with further siblings (wave 20 made every fence mutual), so only its
        // start is pinned.
        $this->assertStringContainsString(
            'NOT: Many options at once (bulk_update_options)',
            $selector,
            'The selector card must print the boundary.'
        );
        $this->assertStringContainsString('"input_fields"', $constructor, 'This is the constructor prompt.');
        $this->assertStringNotContainsString('"is":', $constructor, 'The constructor contract must not carry is.');
        $this->assertStringNotContainsString('"not":', $constructor, 'The constructor contract must not carry not.');
        $this->assertStringNotContainsString(
            'bulk_update_options',
            $constructor,
            'The sibling named on the selector card must not reach the constructor.'
        );
    }
}
