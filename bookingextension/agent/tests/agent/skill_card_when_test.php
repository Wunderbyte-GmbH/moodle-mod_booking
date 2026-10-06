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
 * A skill's declarative "when" reaches the selector card; the constructor does not get it.
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
 * The selector card's WHEN line was always the description of the skill's FIRST message trigger. For skills
 * whose first trigger is an edge case the card described that edge case instead of when to choose the skill:
 * mod_booking.update_option printed only the header-image case, although its description lists title, dates,
 * seats, price, location, visibility and trainers (instruction lab, Wunderbyte-GmbH/Wunderbyte-GmbH#2546).
 * A skill may now declare "when" in its schema, like "is" and "not"; without it the first trigger is used
 * as before. Like IS:/NOT:, the line is for the selector only.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\planner_catalog_service
 * @covers \bookingextension_agent\local\wizard\skill_registry
 */
final class skill_card_when_test extends abstract_agent_testcase {
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
     * The card prints the schema's "when" instead of the first trigger; the constructor contract carries neither.
     */
    public function test_selector_card_uses_the_declared_when(): void {
        $entry = null;
        foreach (\bookingextension_agent\local\wizard\skill_registry::make_default()->get_all_prompt_contracts() as $candidate) {
            if (($candidate['skill'] ?? '') === 'mod_booking.update_option') {
                $entry = $candidate;
            }
        }
        $this->assertNotNull($entry, 'mod_booking.update_option is registered.');
        $when = (string)($entry['when'] ?? '');
        $this->assertNotSame('', $when, 'The registry entry carries the declared when.');
        $firsttrigger = (string)(((array)($entry['message_triggers'][0] ?? []))['description'] ?? '');
        $this->assertNotSame('', $firsttrigger, 'The skill still has a first trigger with a description.');
        $this->assertNotSame($firsttrigger, $when, 'The declared when differs from the first trigger.');

        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        $this->create_option('When Target');
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.update_option'),
            $this->constructor_confirmation_request('mod_booking.update_option', [
                'optionquery' => 'When Target',
                'text' => 'Renamed When Target',
            ]),
        ]);

        $this->chat('Benenn die Option "When Target" auf "Renamed When Target" um.', (int)$threadid, $store, $runtime);

        $this->assertCount(2, $this->scriptedplannerprompts, 'One selector and one constructor call are consumed.');
        $selector = (string)$this->scriptedplannerprompts[0];
        $constructor = (string)$this->scriptedplannerprompts[1];

        $this->assertStringContainsString('WHEN: ' . $when, $selector, 'The selector card prints the declared when.');
        $this->assertStringNotContainsString(
            'WHEN: ' . preg_replace('/\s+/', ' ', $firsttrigger),
            $selector,
            'The first trigger no longer stands in for the declared when.'
        );
        $this->assertGreaterThan(1, substr_count($selector, "\nWHEN: "), 'Cards without a declared when keep a WHEN line.');
        $this->assertStringContainsString('"input_fields"', $constructor, 'This is the constructor prompt.');
        $this->assertStringNotContainsString('"when":', $constructor, 'The constructor contract must not carry when.');
    }
}
