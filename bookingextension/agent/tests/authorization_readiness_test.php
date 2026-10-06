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
use bookingextension_agent\local\wizard\services\security\authorization_service;

/**
 * Tests the graceful readiness check that webservice entry points use instead of throwing.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\security\authorization_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class authorization_readiness_test extends advanced_testcase {
    /**
     * A permitted user (editing teacher) is ready; the check never throws.
     */
    public function test_ready(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $ctxid = (int)context_course::instance($course->id)->id;

        $authz = new authorization_service();
        $this->assertNull($authz->check_use_readiness((int)$teacher->id, $ctxid));
        $this->assertTrue($authz->can_use((int)$teacher->id, $ctxid));
    }

    /**
     * A user without the use capability gets a permission_denied problem (not an exception).
     */
    public function test_permission_denied(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $ctxid = (int)context_course::instance($course->id)->id;

        $problem = (new authorization_service())->check_use_readiness((int)$student->id, $ctxid);
        $this->assertIsArray($problem);
        $this->assertSame('permission_denied', $problem['code']);
        $this->assertNotSame('', trim((string)$problem['message']));
    }

    /**
     * An invalid context id yields a graceful context_invalid problem, never a thrown exception.
     */
    public function test_invalid_context(): void {
        $this->resetAfterTest();
        $admin = get_admin();
        $problem = (new authorization_service())->check_use_readiness((int)$admin->id, 999999999);
        $this->assertIsArray($problem);
        $this->assertSame('context_invalid', $problem['code']);
    }

    /**
     * The "agent unavailable" message is distinct from the permission-denied message — so a pending upgrade
     * is never reported as a permission problem.
     */
    public function test_unavailable_message_distinct_from_permission(): void {
        $this->assertNotSame(
            get_string('error_ai_permission_denied', 'bookingextension_agent'),
            get_string('error_ai_unavailable', 'bookingextension_agent')
        );
    }

    /**
     * Coexistence opt-out is a no-op while no higher-ranked engine plugin is present.
     *
     * This is the whole behaviour of Phase-1 coexistence prep until the primary engine ships:
     * the extra switch must not change anything. In the bundled engine the primary engine is not
     * installed in the test environment; in the generated primary engine the switch is a
     * compile-time false. Either way is_agent_engine_active() collapses to
     * is_agent_extension_installed().
     */
    public function test_optout_switch_is_noop_without_primary_engine(): void {
        $this->assertFalse(
            authorization_service::primary_engine_takes_over(),
            'No higher-ranked engine is present, so this engine must not defer.'
        );
        $this->assertSame(
            authorization_service::is_agent_extension_installed(),
            authorization_service::is_agent_engine_active(),
            'With no primary engine taking over, the engine-active gate must equal the installed check.'
        );
    }

    /**
     * The active-engine gate genuinely controls readiness: with this engine active a permitted
     * user is ready. (When a primary engine takes over, the same gate flips and every entry point
     * of the bundled engine yields.)
     */
    public function test_readiness_follows_active_engine_gate(): void {
        $this->resetAfterTest();
        $this->assertTrue(authorization_service::is_agent_engine_active());

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $ctxid = (int)context_course::instance($course->id)->id;

        $this->assertNull((new authorization_service())->check_use_readiness((int)$teacher->id, $ctxid));
    }
}
