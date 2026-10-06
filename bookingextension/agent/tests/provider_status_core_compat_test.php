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
use context_module;
use bookingextension_agent\local\wizard\aiready;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\skill_registry_factory;

/**
 * Readiness must not depend on core_ai APIs that this core version does not have (#2328).
 *
 * On Moodle 5.0, get_ai_fields_from_course_module() does not exist. The unguarded call ran
 * only for users WITHOUT the ignoreaiavailability bypass, so chat readiness collapsed for
 * every plain teacher/student while managers saw a healthy system. Missing core API must
 * mean "toggle assumed enabled", exactly like the course-toggle fallback above it.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\provider_status_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_status_core_compat_test extends advanced_testcase {
    /**
     * Provider status for an unprivileged user must never die on a missing core method.
     */
    public function test_status_survives_missing_core_module_ai_api(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $this->setUser($student);
        $registry = skill_registry_factory::get_default();
        $orchestrator = new orchestrator($registry, new interpreter($registry), new conversation_store());
        $status = $orchestrator->get_runtime_provider_status(
            (int)context_module::instance($booking->cmid)->id
        );

        $this->assertNotSame(
            'exception_thrown',
            (string)($status['failurereason'] ?? ''),
            'a missing core_ai API must degrade gracefully, never collapse all gates'
        );
        $this->assertTrue(
            (bool)($status['courseenabled'] ?? false),
            'without a module AI-fields API the toggle counts as enabled (course-toggle fallback policy)'
        );
    }

    /**
     * The aiready module-toggle helper treats a missing core API as "enabled", not "disabled".
     */
    public function test_module_toggle_helper_assumes_enabled_without_core_api(): void {
        $this->resetAfterTest();
        if (method_exists(\core_ai\manager::class, 'get_ai_fields_from_course_module')) {
            $this->markTestSkipped('core provides the module AI-fields API — nothing to pin here');
        }
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', ['course' => $course->id]);
        $this->setAdminUser();

        $aiready = new aiready((int)context_module::instance($booking->cmid)->id, (int)get_admin()->id);
        $method = new \ReflectionMethod($aiready, 'is_module_ai_toggle_enabled');

        $this->assertTrue(
            $method->invoke($aiready, (int)$booking->cmid),
            'missing core API must read as toggle-enabled, not silently disabled'
        );
    }
}
