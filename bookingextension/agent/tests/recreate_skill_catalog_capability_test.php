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

namespace bookingextension_agent;

use advanced_testcase;
use context_course;
use context_system;
use bookingextension_agent\local\wizard\wizard\skills\recreate_skill_catalog_skill;

/**
 * Capability-fidelity tests for wizard.recreate_skill_catalog (audit CAP-03).
 *
 * The global, cost-bearing embeddings rebuild is gated by its manager-only skill-use capability
 * (Gate 1) — a teacher must not hold it. It is an external/meta action with no native Moodle
 * capability to declare, so it relies on the name-derived capability (like the other wizard.* meta
 * skills), not on Gate 2.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\wizard\skills\recreate_skill_catalog_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recreate_skill_catalog_capability_test extends advanced_testcase {
    /** Skill-use capability name. */
    private const SKILL_CAP = 'bookingextension/agent:skill_wizard_recreate_skill_catalog';

    /**
     * A teacher does NOT hold the skill-use capability (it is manager-only, not teacher-grantable).
     */
    public function test_teacher_does_not_hold_the_skill_capability(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $this->assertFalse(
            has_capability(self::SKILL_CAP, context_course::instance($course->id), $teacher->id),
            'A teacher must not be able to trigger the global catalog rebuild.'
        );
    }

    /**
     * A manager holds the skill-use capability.
     */
    public function test_manager_holds_the_skill_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $managerroleid = (int)$DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerroleid, $manager->id, context_system::instance()->id);

        $this->assertTrue(
            has_capability(self::SKILL_CAP, context_system::instance(), $manager->id),
            'A manager must be able to trigger the catalog rebuild.'
        );
    }

    /**
     * The skill declares no native capability — it is authorised by its name-derived skill capability
     * (there is no native Moodle action capability for an embeddings rebuild).
     */
    public function test_declares_no_native_capability(): void {
        $this->assertSame([], (new recreate_skill_catalog_skill())->get_required_native_capabilities());
    }
}
