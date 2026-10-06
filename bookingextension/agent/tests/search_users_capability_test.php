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
use bookingextension_agent\local\wizard\core\skills\search_users_skill;

/**
 * Capability-fidelity tests for core.search_users (audit CAP-01 / 12-F01).
 *
 * Verifies that an under-privileged actor cannot enumerate unrelated users' identity/contact PII:
 *  - a user the actor shares no course with (and has no site-level view right for) is NOT returned;
 *  - identity fields are stripped unless the actor holds moodle/site:viewuseridentity (system or shared course);
 *  - an in-course teacher still sees their own student's identity.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\core\skills\search_users_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class search_users_capability_test extends advanced_testcase {
    /**
     * Return the userids contained in a search_users execute() result.
     *
     * @param array $result
     * @return int[]
     */
    private function result_userids(array $result): array {
        return array_map(
            static fn(array $u): int => (int)($u['userid'] ?? 0),
            (array)($result['users'] ?? [])
        );
    }

    /**
     * Return the single result payload for a given userid, or null.
     *
     * @param array $result
     * @param int $userid
     * @return array|null
     */
    private function result_for(array $result, int $userid): ?array {
        foreach ((array)($result['users'] ?? []) as $u) {
            if (is_array($u) && (int)($u['userid'] ?? 0) === $userid) {
                return $u;
            }
        }
        return null;
    }

    /**
     * A user the actor shares no course with (and has no site-level view right for) is NOT returned.
     */
    public function test_unrelated_user_is_hidden(): void {
        $this->resetAfterTest();
        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $coursea->id, 'editingteacher');
        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Zebra',
            'lastname' => 'Targetone',
            'email' => 'zebra.targetone@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($target->id, $courseb->id, 'student');

        $this->setUser($teacher);
        $result = (new search_users_skill())->execute(
            ['query' => 'Zebra Targetone'],
            (int)context_course::instance($coursea->id)->id,
            (int)$teacher->id
        );

        $this->assertNotContains(
            (int)$target->id,
            $this->result_userids($result),
            'A user the teacher shares no course with must not be returned.'
        );
    }

    /**
     * An in-course teacher (holds viewuseridentity at the course) sees their student's identity fields.
     */
    public function test_shared_course_teacher_sees_student_identity(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user([
            'firstname' => 'Yankee',
            'lastname' => 'Studentone',
            'email' => 'yankee.studentone@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $this->setUser($teacher);
        $result = (new search_users_skill())->execute(
            ['query' => 'Yankee Studentone'],
            (int)context_course::instance($course->id)->id,
            (int)$teacher->id
        );

        $payload = $this->result_for($result, (int)$student->id);
        $this->assertNotNull($payload, 'The teacher must see a student enrolled in their own course.');
        $this->assertSame(
            (string)$student->email,
            (string)($payload['email'] ?? ''),
            'A teacher with viewuseridentity in the shared course must see the student email.'
        );
    }

    /**
     * A related actor WITHOUT viewuseridentity (a fellow student) sees the coursemate but with
     * identity/contact fields stripped.
     */
    public function test_related_without_identity_cap_strips_contact_fields(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $actor = $this->getDataGenerator()->create_user();
        $mate = $this->getDataGenerator()->create_user([
            'firstname' => 'Xray',
            'lastname' => 'Mateone',
            'email' => 'xray.mateone@example.com',
            'phone1' => '0123456789',
        ]);
        $this->getDataGenerator()->enrol_user($actor->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($mate->id, $course->id, 'student');

        $this->setUser($actor);
        $result = (new search_users_skill())->execute(
            ['query' => 'Xray Mateone'],
            (int)context_course::instance($course->id)->id,
            (int)$actor->id
        );

        $payload = $this->result_for($result, (int)$mate->id);
        $this->assertNotNull($payload, 'A coursemate (shared course) must still be resolvable.');
        $this->assertArrayNotHasKey('email', $payload, 'Identity fields must be stripped without viewuseridentity.');
        $this->assertArrayNotHasKey('phone1', $payload);
        $this->assertArrayNotHasKey('idnumber', $payload);
        // Non-identity fields the agent needs to resolve the user remain present.
        $this->assertArrayHasKey('fullname', $payload);
        $this->assertArrayHasKey('userid', $payload);
    }

    /**
     * A site admin sees any user with full identity (the broad-right path).
     */
    public function test_admin_sees_unrelated_user_with_identity(): void {
        $this->resetAfterTest();
        $courseb = $this->getDataGenerator()->create_course();
        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Whisky',
            'lastname' => 'Targettwo',
            'email' => 'whisky.targettwo@example.com',
        ]);
        $this->getDataGenerator()->enrol_user($target->id, $courseb->id, 'student');

        $this->setAdminUser();
        $result = (new search_users_skill())->execute(
            ['query' => 'Whisky Targettwo'],
            (int)\context_system::instance()->id,
            (int)get_admin()->id
        );

        $payload = $this->result_for($result, (int)$target->id);
        $this->assertNotNull($payload, 'An admin must be able to resolve any user.');
        $this->assertSame((string)$target->email, (string)($payload['email'] ?? ''));
    }
}
