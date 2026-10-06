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
use bookingextension_agent\local\wizard\services\security\native_capability_guard;

/**
 * Central Gate-2 enforcement: the engine (preflight pipeline + executor) denies a skill whose
 * declared native Moodle capabilities the user does not hold at the OPERATING context — regardless
 * of the skill's own (possibly missing or mis-scoped) checks. The key case is cross-context
 * privilege escalation: holding a capability in course A must NOT let the user act on course B.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \bookingextension_agent\local\wizard\services\security\native_capability_guard
 */
final class native_capability_guard_test extends advanced_testcase {
    /**
     * A minimal skill double that only declares native capabilities (and deliberately performs NO
     * capability check of its own), so the test exercises the ENGINE's central enforcement.
     *
     * @param array $caps
     * @return object
     */
    private function stub_skill(array $caps): object {
        return new class ($caps) {
            /** @var array */
            private array $caps;
            /**
             * Store the declared native capabilities for this stub skill.
             *
             * @param array $caps
             */
            public function __construct(array $caps) {
                $this->caps = $caps;
            }
            /**
             * Return the native capabilities this stub skill requires.
             *
             * @return array
             */
            public function get_required_native_capabilities(): array {
                return $this->caps;
            }
        };
    }

    /**
     * A read-only skill (no declared caps) or an object without the method is always allowed.
     */
    public function test_no_declared_capabilities_is_allowed(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $ctxid = (int)context_system::instance()->id;

        $this->assertSame([], native_capability_guard::missing_capabilities($this->stub_skill([]), $ctxid, (int)$user->id));
        $this->assertSame([], native_capability_guard::missing_capabilities(new \stdClass(), $ctxid, (int)$user->id));
    }

    /**
     * A user lacking the declared capability at the context is reported as missing it (deny).
     */
    public function test_missing_when_user_lacks_capability(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $ctxid = (int)context_course::instance($course->id)->id;
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $missing = native_capability_guard::missing_capabilities(
            $this->stub_skill(['moodle/course:manageactivities']),
            $ctxid,
            (int)$student->id
        );
        $this->assertSame(['moodle/course:manageactivities'], $missing);
    }

    /**
     * A user holding the declared capability at the context is allowed.
     */
    public function test_allowed_when_user_holds_capability(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $ctxid = (int)context_course::instance($course->id)->id;
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $missing = native_capability_guard::missing_capabilities(
            $this->stub_skill(['moodle/course:manageactivities']),
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame([], $missing);
    }

    /**
     * THE cross-context escalation guard: an editing teacher in course A holds the capability in A
     * but NOT in course B. The guard must allow A and deny B — even though the user is privileged
     * somewhere. This is what stops "a teacher in course A acting on course B".
     */
    public function test_cross_context_capability_does_not_leak_between_courses(): void {
        $this->resetAfterTest();
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $ctxa = (int)context_course::instance($coursea->id)->id;
        $ctxb = (int)context_course::instance($courseb->id)->id;

        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $coursea->id, 'editingteacher');

        $skill = $this->stub_skill(['moodle/course:manageactivities']);

        // Allowed in the course where the teacher is enrolled.
        $this->assertSame([], native_capability_guard::missing_capabilities($skill, $ctxa, (int)$teacher->id));

        // DENIED in the other course — the capability does not leak across contexts.
        $this->assertSame(
            ['moodle/course:manageactivities'],
            native_capability_guard::missing_capabilities($skill, $ctxb, (int)$teacher->id),
            'A capability held in course A must not authorise acting on course B.'
        );
    }

    /**
     * An admin (site-wide capability) is allowed in any course context.
     */
    public function test_admin_allowed_everywhere(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;
        $course = $this->getDataGenerator()->create_course();
        $ctxid = (int)context_course::instance($course->id)->id;

        $this->assertSame(
            [],
            native_capability_guard::missing_capabilities(
                $this->stub_skill(['moodle/course:manageactivities']),
                $ctxid,
                (int)$USER->id
            )
        );
    }

    /**
     * An unresolvable operating context fails closed: every declared capability is reported missing.
     */
    public function test_fail_closed_on_unresolvable_context(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $missing = native_capability_guard::missing_capabilities(
            $this->stub_skill(['moodle/course:manageactivities']),
            99999999,
            (int)$user->id
        );
        $this->assertSame(['moodle/course:manageactivities'], $missing);
    }

    // Note: there is deliberately NO "every mutating skill must declare a native capability" invariant
    // test. The mandatory authorization for every skill is its name-derived capability
    // (<plugintype>/<pluginname>:skill_<name>), enforced by skill_executability_evaluator + the
    // executor backstop. Self-declared native (Gate-2) capabilities are an ADDITIVE, opt-in
    // defence-in-depth layer — useful where a skill maps to a native Moodle action / cross-context
    // target, but not required (e.g. external-action skills like oneclick.* or the wizard.* meta
    // skills legitimately declare none). The guard's real behaviour — that DECLARED caps are enforced
    // at the operating context — is covered by the tests above.
}
