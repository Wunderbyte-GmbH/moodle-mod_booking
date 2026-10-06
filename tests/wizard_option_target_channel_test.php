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
use mod_booking\local\wizard\options\skills\book_users_skill;
use mod_booking\local\wizard\options\skills\bulk_update_options_skill;
use mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill;
use mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill;
use mod_booking\local\wizard\options\skills\update_option_skill;
use mod_booking\local\wizard\options\skills\update_option_trainer_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Option-scoped skills must be targetable and honest without an ambient activity (#2334, MCP-F2/F3).
 *
 * Over MCP the ambient context is the system: when the named option matches nothing anywhere,
 * the old path fell back to "please open a booking activity" — impossible for an API client and
 * hiding the real cause. The skills expose activityquery (the ch. 02 module-target channel the
 * create skills already use), the trait honours it, and the not-found stays a not-found.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\booking_skill_base
 */
final class wizard_option_target_channel_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * All six option-scoped skills expose the activityquery target channel.
     */
    public function test_option_scoped_skills_expose_activityquery(): void {
        $skills = [
            new update_option_skill(),
            new update_option_trainer_skill(),
            new bulk_update_options_skill(),
            new book_users_skill(),
            new diagnose_booking_issue_skill(),
            new diagnose_cancellation_issue_skill(),
        ];
        foreach ($skills as $skill) {
            $props = (array)($skill->get_schema()['properties'] ?? []);
            $this->assertArrayHasKey('activityquery', $props, get_class($skill)
                . ' must offer the module-target channel (ch. 02)');
        }
    }

    /**
     * An explicit activityquery wins over stay-ambient: the trait returns a module selector.
     */
    public function test_trait_honours_explicit_activityquery(): void {
        $selector = (new update_option_skill())->get_target_selector([
            'optionquery' => 'does not exist anywhere',
            'activityquery' => 'ai',
        ]);

        $this->assertNotNull($selector, 'a named activity must produce a selector, never stay-ambient');
    }

    /**
     * Not-found stays a not-found: at a non-module context an optionquery matching nothing
     * yields the honest message, never the open-a-page instruction (MCP dead end).
     */
    public function test_unmatched_optionquery_yields_honest_not_found(): void {
        $skill = new book_users_skill();
        $method = new \ReflectionMethod($skill, 'resolve_option_operating_context');

        $result = $method->invoke(
            $skill,
            ['optionquery' => 'zzz definitiv nirgends vorhanden'],
            (int)\context_system::instance()->id,
            'mod/booking:updatebooking',
            (int)get_admin()->id,
            'en'
        );

        $this->assertArrayHasKey('clarification', $result);
        $message = json_encode($result['clarification']);
        $this->assertStringNotContainsString(
            'open a booking activity',
            $message,
            'an API client cannot open pages — the instruction is a dead end'
        );
        $this->assertStringContainsString(
            'zzz definitiv nirgends vorhanden',
            $message,
            'the honest cause names the query that matched nothing'
        );
    }
}
