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
use mod_booking\local\wizard\options\skills\update_rule_from_template_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Switching a rule on or off must reach the command: schema guidance and strict parsing.
 *
 * F36: "deaktiviere die Regel" dropped isactive entirely — the schema description gave the
 * constructor nothing to map the intent onto. The description must guide enable/disable,
 * and the override parse must never invert a quoted boolean ('false' via !empty === true).
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\update_rule_from_template_skill
 */
final class wizard_rule_isactive_intent_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
    }

    /**
     * The schema must tell the constructor what the field is FOR (switch on/off intent).
     */
    public function test_isactive_description_guides_the_disable_intent(): void {
        $schema = (new update_rule_from_template_skill())->get_schema();
        $description = strtolower((string)($schema['properties']['isactive']['description'] ?? ''));

        $this->assertStringContainsString('disable', $description, 'switch-off intent must be mappable');
        $this->assertStringContainsString('enable', $description, 'switch-on intent must be mappable');
    }

    /**
     * Numeric references steer to ruleid: both the field descriptions and the construction
     * guidance must carry the contrast (L6-C2 — "ID 3" landed in rulequery as "id:3").
     */
    public function test_numeric_reference_steering_is_present(): void {
        $skill = new update_rule_from_template_skill();
        $props = (array)($skill->get_schema()['properties'] ?? []);

        $this->assertStringContainsString('never', strtolower((string)($props['ruleid']['description'] ?? '')));
        $this->assertStringContainsString('name', strtolower((string)($props['rulequery']['description'] ?? '')));

        $guidance = '';
        foreach ((array)$skill->get_contextual_prompt_packs() as $pack) {
            $guidance .= implode("\n", (array)($pack['guidance'] ?? []));
        }
        $this->assertStringContainsString('"ruleid": 3', $guidance, 'the inline example must anchor the id flavour');
        $this->assertStringContainsString('"rulequery"', $guidance);
    }

    /**
     * The override parse must be strict: quoted booleans keep their meaning, junk is skipped.
     */
    public function test_isactive_override_parse_is_strict(): void {
        $this->assertFalse(update_rule_from_template_skill::parse_isactive_override(false));
        $this->assertFalse(update_rule_from_template_skill::parse_isactive_override('false'));
        $this->assertFalse(update_rule_from_template_skill::parse_isactive_override('0'));
        $this->assertFalse(update_rule_from_template_skill::parse_isactive_override(0));
        $this->assertTrue(update_rule_from_template_skill::parse_isactive_override(true));
        $this->assertTrue(update_rule_from_template_skill::parse_isactive_override('true'));
        $this->assertTrue(update_rule_from_template_skill::parse_isactive_override(1));
        $this->assertNull(update_rule_from_template_skill::parse_isactive_override('banana'));
    }
}
