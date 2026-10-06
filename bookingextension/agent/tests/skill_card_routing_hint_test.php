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
 * Every skill card carries a WHEN line: the situation in which the selector routes to it.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * The selector prompt says "follow skill-level routing hints from the SKILL CATALOG (WHEN, REQUIRED,
 * TRIGGERS)". WHEN is rendered from the first message trigger of a skill. Baseline runs 21-30
 * (2026-09-23): 39 of 83 cards had no WHEN line at all - every local_taskflow skill and five
 * mod_booking skills - because none of them declared a message trigger. LRP-4 went to explain_docs in
 * nine of ten runs: explain_docs says "User asks how something works ...", list_rule_properties said only
 * what it contains. The selector follows the card that describes the situation. A trigger is card
 * material only (nothing else in the engine consumes it), so every skill declares one.
 *
 * @covers \bookingextension_agent\local\wizard\services\planner_catalog_service
 */
final class skill_card_routing_hint_test extends \advanced_testcase {
    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /** @var int What the renderer keeps of a WHEN line (planner_catalog_service, WHEN: substr 0..180). */
    private const WHEN_CAP = 180;

    /**
     * Every registered skill renders a WHEN line, and none of them is cut by the renderer.
     *
     * F75 (2026-09-23, wave 23): 18 triggers (mod_booking, core, course, question) were 191-495 characters
     * long and the selector saw them cut mid-sentence; several carried phrase examples and construction
     * instructions the selector cannot use. A WHEN line is the situation, in one sentence.
     */
    public function test_every_card_carries_a_when_line(): void {
        $this->resetAfterTest();

        $service = new planner_catalog_service(new assistant_state_guidance_service());
        $registry = skill_registry_factory::get_default();
        $contracts = $registry->get_all_prompt_contracts();
        $this->assertNotEmpty($contracts);

        $missing = [];
        $cut = [];
        foreach ($contracts as $contract) {
            $name = trim((string)($contract['skill'] ?? ''));
            if ($name === '') {
                continue;
            }
            $triggers = (array)($contract['message_triggers'] ?? []);
            $first = !empty($triggers) && is_array($triggers[0]) ? (array)$triggers[0] : [];
            $when = trim(preg_replace('/\s+/', ' ', (string)($first['description'] ?? '')) ?? '');
            if ($when === '') {
                $missing[] = $name;
                continue;
            }
            if (\core_text::strlen($when) > self::WHEN_CAP) {
                $cut[] = $name . ' (' . \core_text::strlen($when) . ')';
            }
            $this->assertMatchesRegularExpression(
                '/^WHEN: /m',
                $service->render_catalog_as_text([$contract]),
                $name . ' renders no WHEN line'
            );
        }

        $this->assertSame([], $missing, "cards without a WHEN line:\n" . implode("\n", $missing));
        $this->assertSame([], $cut, "WHEN lines the renderer cuts:\n" . implode("\n", $cut));
    }
}
