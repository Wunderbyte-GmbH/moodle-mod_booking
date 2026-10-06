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

namespace mod_booking;

use advanced_testcase;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\configure_booking_instance_skill;
use mod_booking\local\wizard\options\skills\list_instance_settings_skill;

/**
 * The selector sees only the first 240 characters of a skill description.
 *
 * Run 9 (2026-09-16, CBI-2 "turn off the confirmation mail copies at the activity level") was routed to
 * the read-only list_instance_settings. The engine's planner catalog truncates descriptions
 * sentence-aware at 240 characters (planner_catalog_service::compact_catalog_description), so the
 * mutation/read-only discrimination of the two instance skills must sit inside that window (#2411).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\configure_booking_instance_skill
 * @covers     \mod_booking\local\wizard\options\skills\list_instance_settings_skill
 */
final class wizard_instance_skill_description_budget_test extends advanced_testcase {
    /** @var int Character budget of the planner catalog description. */
    private const BUDGET = 240;

    /**
     * Setup: engine aliases for the skill base classes.
     */
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
    }

    /**
     * Replica of the engine rule: whitespace-normalised, cut at the last sentence boundary within 240.
     *
     * @param string $description Raw description.
     * @return string Retained text.
     */
    private static function retained(string $description): string {
        $normalized = trim(preg_replace('/\s+/', ' ', $description) ?? $description);
        if (\core_text::strlen($normalized) <= self::BUDGET) {
            return $normalized;
        }
        $window = \core_text::substr($normalized, 0, self::BUDGET);
        if (preg_match('/^(.*[.!?]["\'\)\]]*)(?:\s|$)/us', $window, $matches)) {
            return rtrim($matches[1]);
        }
        return '';
    }

    /**
     * Both instance skills name their sibling and their own mode inside the window.
     */
    public function test_instance_skills_discriminate_inside_the_window(): void {
        $configureschema = (array)(new configure_booking_instance_skill())->get_schema();
        $listschema = (array)(new list_instance_settings_skill())->get_schema();
        $configure = self::retained((string)$configureschema['description']);
        $list = self::retained((string)$listschema['description']);
        $this->assertNotSame('', $configure);
        $this->assertNotSame('', $list);
        // The mutation skill names the read-only sibling and the input it takes; the read-only skill
        // names the mutation sibling — the selector must see the pair on both cards. Since wave 17
        // (#2453) the sibling's name sits on the NOT: card line, not in the embedded description.
        $this->assertStringContainsString('list_instance_settings', (string)$configureschema['not'], $configure);
        $this->assertStringContainsString('changes', $configure, $configure);
        $this->assertStringContainsString('configure_booking_instance', (string)$listschema['not'], $list);
    }
}
