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

declare(strict_types=1);

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\services\assistant_state_guidance_service;
use bookingextension_agent\local\wizard\services\planner_catalog_service;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry;
use context_course;

/**
 * The selector catalog is gated by the SAME executability verdicts the executor backstop
 * re-derives later, so the two gates can never disagree (issue #2223: an inactive
 * course.enrol_user was selectable, was actively offered, and only failed in governance).
 * A skill denied for any non-PRO reason is hidden from the planner entirely.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\services\planner_catalog_service::partition_prompt_contracts_by_executability
 */
final class catalog_availability_gate_test extends advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * The selectable partition equals the evaluator's executable set (the two-gate invariant),
     * and a skill an admin disabled in skill governance disappears from the catalog completely —
     * it is neither selectable nor advertised as UNAVAILABLE.
     */
    public function test_partition_matches_evaluator_and_hides_disabled_skills(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        $contextid = (int)context_course::instance($course->id)->id;
        $userid = (int)$USER->id;

        set_config('aiskillenableall', 1, 'bookingextension_agent');

        $registry = skill_registry::make_default();
        $evaluator = new skill_executability_evaluator($registry, new authorization_service());
        $catalogsvc = new planner_catalog_service(new assistant_state_guidance_service());

        $partition = static function () use ($registry, $evaluator, $catalogsvc, $userid, $contextid): array {
            return $catalogsvc->partition_prompt_contracts_by_executability(
                $registry->get_prompt_contracts_for_context($evaluator, $userid, $contextid, true),
                $evaluator->evaluate_all_skills($userid, $contextid)
            );
        };

        [$selectable, $locked] = $partition();
        $selectablenames = array_column($selectable, 'skill');
        $this->assertContains('course.enrol_user', $selectablenames, 'Enabled skill must be selectable.');

        // Two-gate invariant: the catalog gate exposes exactly the skills the executor
        // backstop would allow — never more, never fewer.
        $executable = $evaluator->get_executable_skill_names($userid, $contextid);
        sort($selectablenames);
        sort($executable);
        $this->assertSame($executable, $selectablenames, 'Catalog gate must equal the executor gate.');

        // PHPUnit always runs with full access (license override), so nothing is PRO-locked here.
        $this->assertSame([], $locked, 'With full access no skill may be PRO-locked.');

        // Disable exactly one skill in skill governance: it must vanish from the catalog —
        // hidden, not "unavailable" (issue #2223 repro shape).
        set_config('aiskillenableall', 0, 'bookingextension_agent');
        set_config('aiskillenabled_course_enrol_user', 0, 'bookingextension_agent');

        [$selectable, $locked] = $partition();
        $selectablenames = array_column($selectable, 'skill');
        $this->assertNotContains('course.enrol_user', $selectablenames, 'Disabled skill must not be selectable.');
        $this->assertNotContains(
            'course.enrol_user',
            array_column($locked, 'skill'),
            'A non-PRO deny must hide the skill, not advertise it as locked.'
        );

        // The invariant holds in the restricted state too.
        $executable = $evaluator->get_executable_skill_names($userid, $contextid);
        sort($selectablenames);
        sort($executable);
        $this->assertSame($executable, $selectablenames, 'Catalog gate must equal the executor gate after the toggle.');
    }
}
