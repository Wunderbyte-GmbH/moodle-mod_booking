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
use bookingextension_agent\local\wizard\core\skills\diagnose_permissions_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;

/**
 * Tests for the core.diagnose_permissions skill.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\core\skills\diagnose_permissions_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class diagnose_permissions_skill_test extends advanced_testcase {
    /**
     * Metadata: read-only R0.
     */
    public function test_metadata(): void {
        $skill = new diagnose_permissions_skill();
        $this->assertSame('core.diagnose_permissions', $skill->get_name());
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(skill_risk_class::R0, $skill->get_risk_class());
    }

    /**
     * Role mode: a student's role is listed at the course context (self-diagnosis).
     */
    public function test_role_mode_self(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($student);

        $result = (new diagnose_permissions_skill())->execute(
            ['courseid' => (int)$course->id],
            $coursecontextid,
            (int)$student->id
        );
        $this->assertSame('executed', $result['status']);
        $this->assertSame('roles', $result['diagnosis']['mode']);
        $this->assertStringContainsString('student', $result['observation_full']);
    }

    /**
     * Capability mode: teacher HAS manageactivities, student does NOT — with person-correct
     * verbs: second person for self checks ("You HAVE / do NOT have"), third person for
     * cross-user checks ("<Name> HAS / does NOT have").
     */
    public function test_capability_mode(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $skill = new diagnose_permissions_skill();

        $this->setUser($teacher);
        $teacherresult = $skill->execute(
            ['courseid' => (int)$course->id, 'capability' => 'moodle/course:manageactivities'],
            $coursecontextid,
            (int)$teacher->id
        );
        $this->assertSame('capability', $teacherresult['diagnosis']['mode']);
        $this->assertStringContainsString(
            'You HAVE moodle/course:manageactivities',
            $teacherresult['observation_full']
        );

        $this->setUser($student);
        $studentresult = $skill->execute(
            ['courseid' => (int)$course->id, 'capability' => 'moodle/course:manageactivities'],
            $coursecontextid,
            (int)$student->id
        );
        $this->assertStringContainsString(
            'You do NOT have moodle/course:manageactivities',
            $studentresult['observation_full']
        );

        // Cross-user check (third person) — as admin, holding moodle/role:review everywhere.
        $this->setAdminUser();
        global $USER;
        $adminresult = $skill->execute(
            [
                'courseid' => (int)$course->id,
                'userid' => (int)$student->id,
                'capability' => 'moodle/course:manageactivities',
            ],
            $coursecontextid,
            (int)$USER->id
        );
        $this->assertStringContainsString(
            fullname($student) . ' does NOT have moodle/course:manageactivities',
            $adminresult['observation_full']
        );
    }

    /**
     * Unknown capability with look-alikes: a recoverable lookup (never a hard failure, never a finished
     * check) whose candidates include the real name — and nothing for the preview to render.
     *
     * The invented names are the ones a planner actually produces: a typo, and the 2026-09-23 chat turn's
     * `moodle/activity:manage` for "edit activities", whose real name the old substring ranking never listed
     * ("activity" is not a substring of "manageactivities").
     */
    public function test_unknown_capability_is_recoverable_with_candidates(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($teacher);
        $skill = new diagnose_permissions_skill();

        foreach (['moodle/course:managactivities', 'moodle/activity:manage'] as $invented) {
            $start = microtime(true);
            $result = $skill->execute(
                ['courseid' => (int)$course->id, 'capability' => $invented],
                $coursecontextid,
                (int)$teacher->id
            );
            $elapsedms = (int)round((microtime(true) - $start) * 1000);

            $this->assertSame('error', $result['status'], $invented);
            $this->assertSame('unknown_capability', $result['error_class'], $invented);
            $this->assertContains('RECOVERABLE_INPUT_ERROR', (array)($result['issue_codes'] ?? []), $invented);
            $this->assertContains(
                'moodle/course:manageactivities',
                (array)($result['capability_candidates'] ?? []),
                $invented . ': the real capability must be offered'
            );
            $this->assertStringContainsString('moodle/course:manageactivities', $result['observation_full'], $invented);
            $this->assertArrayNotHasKey('checklist_rows', $result, $invented);
            $this->assertNull($skill->get_result_preview($result, $coursecontextid, (int)$teacher->id), $invented);
            $this->assertLessThan(2000, $elapsedms, $invented . ': candidate ranking must stay inside the preflight budget');
        }
    }

    /**
     * Wave 32 (A3, DP-3): an unknown name inside an existing component offers that component's whole family, so the
     * capability that means the action is on the list even when its identifier shares no token with the guess.
     *
     * The planner's guess in all ten runs L30-L41 was moodle/course:manage; the look-alike ranking alone offered
     * managefiles, manageactivities, managegroups, ... but not moodle/course:update.
     */
    public function test_unknown_capability_offers_its_component_family(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($teacher);
        $skill = new diagnose_permissions_skill();

        $result = $skill->execute(
            ['courseid' => (int)$course->id, 'capability' => 'moodle/course:manage'],
            $coursecontextid,
            (int)$teacher->id
        );

        $this->assertSame('error', $result['status']);
        $this->assertContains('RECOVERABLE_INPUT_ERROR', (array)($result['issue_codes'] ?? []));
        $candidates = (array)($result['capability_candidates'] ?? []);
        $this->assertContains('moodle/course:update', $candidates, 'the family member that means the action is offered');
        $this->assertContains('moodle/course:manageactivities', $candidates, 'look-alikes stay on the list');
        $this->assertLessThanOrEqual(50, count($candidates), 'the candidate list is capped');
        $this->assertLessThan(
            array_search('moodle/course:update', $candidates, true),
            array_search('moodle/course:manageactivities', $candidates, true),
            'look-alikes inside the component come first, the rest of the family follows'
        );
        foreach ($candidates as $candidate) {
            $this->assertArrayHasKey($candidate, get_all_capabilities(), 'only real capabilities are offered');
        }
    }

    /**
     * Wave 32 (A3, L43 re-check, DP-2 thread 13239): a component the ranking finds no look-alike in is not grounded,
     * so its family is not offered and the look-alikes from other components stay the whole list.
     *
     * The planner guessed mod/booking:grades (not defined; mod/booking has far more than 50 capabilities, none of
     * them about grading). The names that mean the action live elsewhere (mod/assign:grade, ...). Ranking the whole
     * mod/booking family in would have filled the list with unrelated names.
     */
    public function test_unknown_capability_in_an_ungrounded_component_offers_only_lookalikes(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($teacher);
        $skill = new diagnose_permissions_skill();

        $result = $skill->execute(
            ['courseid' => (int)$course->id, 'capability' => 'mod/booking:grades'],
            $coursecontextid,
            (int)$teacher->id
        );

        $this->assertSame('error', $result['status']);
        $this->assertContains('RECOVERABLE_INPUT_ERROR', (array)($result['issue_codes'] ?? []));
        $candidates = (array)($result['capability_candidates'] ?? []);
        $this->assertContains('mod/assign:grade', $candidates, 'the foreign look-alike is offered');
        $this->assertLessThanOrEqual(8, count($candidates), 'only the look-alikes, no family');
        foreach ($candidates as $candidate) {
            $this->assertStringStartsNotWith('mod/booking:', $candidate, 'the ungrounded family is not offered');
        }
    }

    /**
     * Unknown capability without any look-alike: nothing to retry with, so the skill completes with the
     * role picture (a finished result the planner can answer from) and no retry marker.
     */
    public function test_unknown_capability_without_candidates_falls_back_to_roles(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($teacher);

        $result = (new diagnose_permissions_skill())->execute(
            ['courseid' => (int)$course->id, 'capability' => 'moodle/qqzzxx:yyvvww'],
            $coursecontextid,
            (int)$teacher->id
        );
        $this->assertSame('executed', $result['status']);
        $this->assertSame('roles', $result['diagnosis']['mode']);
        $this->assertNotContains('RECOVERABLE_INPUT_ERROR', (array)($result['issue_codes'] ?? []));
        $this->assertArrayNotHasKey('capability_candidates', $result);
        $this->assertStringContainsString('editingteacher', $result['observation_full']);
    }

    /**
     * Cross-user gate: a student cannot review another user's permissions.
     */
    public function test_cross_user_gate(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $a = $this->getDataGenerator()->create_user();
        $b = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($a->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($b->id, $course->id, 'student');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $this->setUser($a);

        $result = (new diagnose_permissions_skill())->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$b->id],
            $coursecontextid,
            (int)$a->id
        );
        $this->assertSame('error', $result['status']);
        $this->assertSame('permission_denied', $result['error_class']);
    }

    /**
     * Two courses: the person is a student in B, the question is asked from A, without a course.
     *
     * @return array{manager:\stdClass,student:\stdClass,coursea:\stdClass,courseb:\stdClass}
     */
    private function seed_student_elsewhere(): array {
        $this->resetAfterTest();
        $coursea = $this->getDataGenerator()->create_course(['fullname' => 'Course A']);
        $courseb = $this->getDataGenerator()->create_course(['fullname' => 'Biologie']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $courseb->id, 'student');
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign(
            (int)$this->getDataGenerator()->create_role(['shortname' => 'reviewer']),
            $manager->id,
            \context_system::instance()->id
        );
        global $DB;
        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'reviewer']);
        assign_capability('moodle/role:review', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        return ['manager' => $manager, 'student' => $student, 'coursea' => $coursea, 'courseb' => $courseb];
    }

    /**
     * Role mode without a course names the chain it checked and the roles the person holds elsewhere.
     */
    public function test_role_mode_names_the_chain_and_the_roles_elsewhere(): void {
        ['manager' => $manager, 'student' => $student, 'coursea' => $coursea, 'courseb' => $courseb]
            = $this->seed_student_elsewhere();
        $this->setUser($manager);

        $result = (new diagnose_permissions_skill())->execute(
            ['userid' => (int)$student->id],
            (int)context_course::instance($coursea->id)->id,
            (int)$manager->id
        );

        $this->assertSame('executed', $result['status']);
        $observation = (string)$result['observation_full'];
        $this->assertStringContainsString('No role assignments along this chain', $observation);
        $this->assertStringContainsString('Checked: System, ', $observation);
        $this->assertStringContainsString('Course: Course A', $observation);
        $this->assertStringContainsString('not part of this chain', $observation);
        $this->assertStringContainsString('Elsewhere on this site: Course: Biologie', $observation);
        $this->assertStringContainsString('Roles: student', $observation);
        $this->assertStringNotContainsString('no roles along this context chain', $observation);

        $elsewhere = array_values(array_filter(
            (array)$result['checklist_rows'],
            static fn(array $r): bool => str_starts_with((string)$r['check'], 'Elsewhere')
        ));
        $this->assertCount(1, $elsewhere);
        $this->assertSame('ok', $elsewhere[0]['status']);
        $this->assertStringContainsString('Biologie', (string)$elsewhere[0]['check']);
        $this->assertSame('Roles: student', (string)$elsewhere[0]['finding']);
        $this->assertStringNotContainsString('Biologie', (string)$result['checklist_title']);
    }

    /**
     * A requester who may not review roles in the other course gets a count, never the course.
     */
    public function test_roles_elsewhere_are_counted_where_the_requester_may_not_review(): void {
        ['student' => $student, 'coursea' => $coursea, 'courseb' => $courseb] = $this->seed_student_elsewhere();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $coursea->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $coursea->id, 'student');
        $this->setUser($teacher);

        $result = (new diagnose_permissions_skill())->execute(
            ['userid' => (int)$student->id],
            (int)context_course::instance($coursea->id)->id,
            (int)$teacher->id
        );

        $this->assertSame('executed', $result['status']);
        $observation = (string)$result['observation_full'];
        $this->assertStringContainsString('Course: Course A — Roles: student', $observation);
        $this->assertStringContainsString('1 more role assignment in a context you may not review', $observation);
        $this->assertStringNotContainsString('Biologie', $observation);
    }

    /**
     * A person asking about themselves sees their own roles elsewhere without a review capability.
     */
    public function test_self_sees_own_roles_elsewhere(): void {
        ['student' => $student, 'coursea' => $coursea] = $this->seed_student_elsewhere();
        $this->getDataGenerator()->enrol_user($student->id, $coursea->id, 'student');
        $this->setUser($student);

        $result = (new diagnose_permissions_skill())->execute(
            [],
            (int)context_course::instance($coursea->id)->id,
            (int)$student->id
        );

        $this->assertStringContainsString(
            'Elsewhere on this site: Course: Biologie — Roles: student',
            $result['observation_full']
        );
    }

    /**
     * A role at another person's user context is listed without that person's name.
     */
    public function test_role_at_a_user_context_names_no_third_person(): void {
        ['manager' => $manager, 'student' => $student, 'coursea' => $coursea] = $this->seed_student_elsewhere();
        $child = $this->getDataGenerator()->create_user(['firstname' => 'Thirdperson', 'lastname' => 'Secret']);
        $this->getDataGenerator()->role_assign(
            (int)$this->getDataGenerator()->create_role(['shortname' => 'parent']),
            $student->id,
            \context_user::instance($child->id)->id
        );
        $this->setUser($manager);

        $result = (new diagnose_permissions_skill())->execute(
            ['userid' => (int)$student->id],
            (int)context_course::instance($coursea->id)->id,
            (int)$manager->id
        );

        $observation = (string)$result['observation_full'];
        $this->assertStringContainsString('Elsewhere on this site: a user profile — Roles: parent', $observation);
        $this->assertStringNotContainsString('Thirdperson', $observation);
        $this->assertStringNotContainsString('Secret', json_encode($result['checklist_rows']));
    }

    /**
     * #2625: a site administrator without any role assignment, role mode. The result states the admin status
     * as a fact (first row, diagnosis.siteadmin) instead of a bare "no roles" warning.
     */
    public function test_siteadmin_without_roles_role_mode(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $admin = get_admin();

        $result = (new diagnose_permissions_skill())->execute(
            ['courseid' => (int)$course->id],
            (int)context_course::instance($course->id)->id,
            (int)$admin->id
        );

        $this->assertSame('executed', $result['status']);
        $this->assertSame('roles', $result['diagnosis']['mode']);
        $this->assertTrue($result['diagnosis']['siteadmin'] ?? null, 'the admin status is a structured fact');
        $rows = (array)$result['checklist_rows'];
        $this->assertSame(self::siteadmin_label(), $rows[0]['check'], 'the admin row comes before the role rows');
        $this->assertSame('ok', $rows[0]['status']);
        $this->assertSame(
            get_string('agent_diagnose_permissions_siteadmin_allcaps', 'bookingextension_agent'),
            $rows[0]['finding']
        );
        $this->assertNotContains('warn', array_column($rows, 'status'), 'no role is not a problem for an admin');
        $this->assertStringContainsString('No role assignments along this chain', $result['observation_full']);
        $this->assertStringContainsString(self::siteadmin_label(), $result['observation_full']);
    }

    /**
     * #2625: a site administrator without any role assignment, capability mode. The capability is granted
     * only through the admin status, and the result says so.
     */
    public function test_siteadmin_without_roles_capability_mode(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $admin = get_admin();

        $result = (new diagnose_permissions_skill())->execute(
            ['courseid' => (int)$course->id, 'capability' => 'moodle/course:manageactivities'],
            (int)context_course::instance($course->id)->id,
            (int)$admin->id
        );

        $this->assertSame('capability', $result['diagnosis']['mode']);
        $this->assertTrue($result['diagnosis']['siteadmin'] ?? null);
        $this->assertSame('siteadmin', $result['diagnosis']['granted_by'] ?? null);
        $rows = (array)$result['checklist_rows'];
        $this->assertSame('You HAVE moodle/course:manageactivities', $rows[0]['check']);
        $this->assertSame(self::siteadmin_label(), $rows[1]['check'], 'the admin row follows the verdict');
        $this->assertSame('ok', $rows[1]['status']);
        $this->assertSame(
            get_string('agent_diagnose_permissions_siteadmin_onlyadmin', 'bookingextension_agent'),
            $rows[1]['finding']
        );
        $this->assertCount(2, $rows, 'no role, so no override rows');
    }

    /**
     * #2625: a site administrator whose role grants the capability, then a PROHIBIT on that role. PROHIBIT
     * does not apply to admins, so the capability stays granted, now only through the admin status.
     */
    public function test_siteadmin_with_role_and_prohibit_capability_mode(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $coursecontext = context_course::instance($course->id);
        $this->setAdminUser();
        $admin = get_admin();
        $this->getDataGenerator()->enrol_user($admin->id, $course->id, 'editingteacher');
        $skill = new diagnose_permissions_skill();
        $input = ['courseid' => (int)$course->id, 'capability' => 'moodle/course:manageactivities'];

        $result = $skill->execute($input, (int)$coursecontext->id, (int)$admin->id);
        $this->assertSame('roles', $result['diagnosis']['granted_by'] ?? null, 'the role grants it as well');
        $this->assertSame(
            get_string('agent_diagnose_permissions_siteadmin_alsoroles', 'bookingextension_agent'),
            $result['checklist_rows'][1]['finding']
        );

        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability('moodle/course:manageactivities', CAP_PROHIBIT, $roleid, $coursecontext->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $result = $skill->execute($input, (int)$coursecontext->id, (int)$admin->id);
        $this->assertTrue($result['diagnosis']['siteadmin']);
        $this->assertSame('siteadmin', $result['diagnosis']['granted_by']);
        $this->assertStringContainsString('You HAVE moodle/course:manageactivities', $result['observation_full']);
        $this->assertStringContainsString('Override: role "editingteacher" → PROHIBIT', $result['observation_full']);
        $this->assertSame(
            get_string('agent_diagnose_permissions_siteadmin_onlyadmin', 'bookingextension_agent'),
            $result['checklist_rows'][1]['finding']
        );
    }

    /**
     * #2625 control: for a person who is not a site administrator the rows stay as they were; only the
     * structured facts siteadmin=false and granted_by are new.
     */
    public function test_non_admin_result_is_unchanged(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Control course']);
        $teacher = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $skill = new diagnose_permissions_skill();

        $this->setUser($teacher);
        $roles = $skill->execute(['courseid' => (int)$course->id], $coursecontextid, (int)$teacher->id);
        $this->assertFalse($roles['diagnosis']['siteadmin'] ?? null);
        $this->assertSame(['Course: Control course'], array_column($roles['checklist_rows'], 'check'));
        $this->assertSame(['ok'], array_column($roles['checklist_rows'], 'status'));
        $this->assertSame(['Roles: editingteacher'], array_column($roles['checklist_rows'], 'finding'));

        $capability = $skill->execute(
            ['courseid' => (int)$course->id, 'capability' => 'moodle/course:manageactivities'],
            $coursecontextid,
            (int)$teacher->id
        );
        $this->assertFalse($capability['diagnosis']['siteadmin'] ?? null);
        $this->assertSame('roles', $capability['diagnosis']['granted_by'] ?? null);
        $this->assertSame(
            ['You HAVE moodle/course:manageactivities', 'Override: role "editingteacher" → ALLOW'],
            array_column($capability['checklist_rows'], 'check')
        );

        $this->setUser($student);
        $denied = $skill->execute(
            ['courseid' => (int)$course->id, 'capability' => 'moodle/course:manageactivities'],
            $coursecontextid,
            (int)$student->id
        );
        $this->assertFalse($denied['diagnosis']['siteadmin'] ?? null);
        $this->assertSame('none', $denied['diagnosis']['granted_by'] ?? null);
        $this->assertSame(
            ['You do NOT have moodle/course:manageactivities'],
            array_column($denied['checklist_rows'], 'check')
        );
        foreach ([$roles, $capability, $denied] as $result) {
            $this->assertStringNotContainsString(self::siteadmin_label(), $result['observation_full']);
        }
    }

    /**
     * #2625 cross-user: a manager holding moodle/role:review checks a site administrator without roles.
     */
    public function test_manager_checks_siteadmin(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $manager = $this->getDataGenerator()->create_user();
        $roleid = (int)$this->getDataGenerator()->create_role(['shortname' => 'reviewer']);
        assign_capability('moodle/role:review', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        $this->getDataGenerator()->role_assign($roleid, $manager->id, \context_system::instance()->id);
        $admin = get_admin();
        $this->setUser($manager);
        $skill = new diagnose_permissions_skill();

        $roles = $skill->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$admin->id],
            $coursecontextid,
            (int)$manager->id
        );
        $this->assertSame('executed', $roles['status']);
        $this->assertTrue($roles['diagnosis']['siteadmin'] ?? null);
        $this->assertSame(self::siteadmin_label(), $roles['checklist_rows'][0]['check']);

        $capability = $skill->execute(
            ['courseid' => (int)$course->id, 'userid' => (int)$admin->id, 'capability' => 'moodle/course:manageactivities'],
            $coursecontextid,
            (int)$manager->id
        );
        $this->assertTrue($capability['diagnosis']['siteadmin'] ?? null);
        $this->assertSame('siteadmin', $capability['diagnosis']['granted_by'] ?? null);
        $this->assertStringContainsString(
            fullname($admin) . ' HAS moodle/course:manageactivities',
            $capability['observation_full']
        );
    }

    /**
     * The label of the site administrator row.
     *
     * @return string
     */
    private static function siteadmin_label(): string {
        return get_string('agent_diagnose_permissions_siteadmin', 'bookingextension_agent');
    }
}
