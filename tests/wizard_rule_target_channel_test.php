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
use mod_booking\local\wizard\booking\support\booking_rules_agent_service;
use mod_booking\local\wizard\options\skills\analyze_rules_skill;
use mod_booking\local\wizard\options\skills\update_rule_from_template_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Rule tools must be targetable and honest without an ambient activity (#2335, MCP-F5).
 *
 * Over MCP the ambient context is the system: analyze fell back to system rules ("0 rules"
 * while the activity holds 8) and update inferred a context only from EXACT rule names. Both
 * gain the activityquery module-target channel; the empty-context message hints at naming
 * the activity instead of dead-ending.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\booking\support\booking_rules_agent_service
 */
final class wizard_rule_target_channel_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Both rule tools expose the activityquery target channel.
     */
    public function test_rule_skills_expose_activityquery(): void {
        foreach ([new analyze_rules_skill(), new update_rule_from_template_skill()] as $skill) {
            $props = (array)($skill->get_schema()['properties'] ?? []);
            $this->assertArrayHasKey('activityquery', $props, get_class($skill));
        }
    }

    /**
     * An explicit activityquery wins over the exact-name inference: never stay-ambient.
     */
    public function test_rule_trait_honours_explicit_activityquery(): void {
        $selector = (new update_rule_from_template_skill())->get_target_selector([
            'rulequery' => 'fuzzy reminder wording',
            'activityquery' => 'ai',
        ]);

        $this->assertNotNull($selector, 'a named activity must produce a selector');
    }

    /**
     * The empty-context error hints at naming the activity — no silent dead end.
     */
    public function test_empty_context_message_hints_at_activity(): void {
        $result = (new booking_rules_agent_service())->resolve_rule(
            (int)\context_system::instance()->id,
            0,
            'irgendeine regel'
        );

        $this->assertSame('error', (string)($result['status'] ?? ''));
        $this->assertStringContainsString(
            'booking activity',
            strtolower((string)($result['message'] ?? '')),
            'the user must learn that naming the activity unblocks the search'
        );
    }

    /**
     * Guard: the exact-name context inference keeps working without any activity given.
     */
    public function test_exact_name_inference_unchanged(): void {
        global $DB;
        $contextid = (int)\context_system::instance()->id;
        $DB->insert_record('booking_rules', (object)[
            'contextid' => $contextid,
            'rulename' => 'rule_react_on_event',
            'rulejson' => json_encode(['name' => 'Exakter Testname', 'conditionname' => '', 'actionname' => '']),
            'eventname' => '',
            'useastemplate' => 0,
            'isactive' => 0,
        ]);

        $this->assertGreaterThan(
            0,
            \mod_booking\local\wizard\booking\booking_skill_support::context_for_rule(0, 'Exakter Testname')
        );
    }
}
