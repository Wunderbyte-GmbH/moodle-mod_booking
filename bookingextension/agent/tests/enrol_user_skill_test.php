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
use bookingextension_agent\local\wizard\course\skills\enrol_user_skill;
use bookingextension_agent\local\wizard\skill_registry;
use context_course;

/**
 * Contract and behaviour tests for course.enrol_user.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\course\skills\enrol_user_skill
 */
final class enrol_user_skill_test extends advanced_testcase {
    /**
     * Contract: R2 mutating skill at course level with the native enrol/manual:enrol gate
     * and explicit course context scope (mandatory for R2 activatability).
     */
    public function test_contract_shape(): void {
        $skill = new enrol_user_skill();

        $this->assertSame('course.enrol_user', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(CONTEXT_COURSE, $skill->get_required_context_level());
        $this->assertSame(['enrol/manual:enrol'], $skill->get_required_native_capabilities());

        $schema = $skill->get_schema();
        $this->assertArrayHasKey('userquery', (array)$schema['properties']);
        $this->assertArrayHasKey('coursequery', (array)$schema['properties']);
        $this->assertSame(['course'], (array)($schema['prompt_meta']['context_scopes'] ?? []));

        // Wave 32 (EU-3): an empty person is the requester (F81), not a structural error.
        $this->assertTrue($skill->check_structure([])['valid']);
        $this->assertFalse($skill->check_structure(['userquery' => str_repeat('x', 300)])['valid']);
        $this->assertFalse((bool)($schema['properties']['userquery']['required'] ?? false));
        $this->assertStringNotContainsString('"me"', (string)$schema['properties']['userquery']['description']);
        $this->assertLessThanOrEqual(159, \core_text::strlen((string)$schema['properties']['userquery']['description']));
    }

    /**
     * Happy path: preflight resolves user/instance/role, execute enrols with the default
     * (student) role and reports link material plus produced outputs.
     */
    public function test_preflight_and_execute_happy_path(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $DB, $USER;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'First Aid']);
        $target = $gen->create_user(['firstname' => 'Anna', 'lastname' => 'Muster', 'email' => 'anna@example.com']);
        $coursecontext = context_course::instance($course->id);

        $skill = new enrol_user_skill();
        $dto = $skill->preflight(
            ['userquery' => 'anna@example.com'],
            (int)$coursecontext->id,
            (int)$USER->id
        );

        $this->assertSame('pass', $dto->to_array()['status'], json_encode($dto->issues));
        $prepared = $dto->preparedinput;
        $this->assertSame((int)$target->id, (int)$prepared['userid']);
        $this->assertGreaterThan(0, (int)$prepared['enrolinstanceid']);
        $this->assertGreaterThan(0, (int)$prepared['roleid']);

        $result = $skill->execute($prepared, (int)$coursecontext->id, (int)$USER->id);

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $this->assertTrue(is_enrolled($coursecontext, $target->id));
        $ue = $DB->get_records_sql(
            "SELECT ue.id FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND e.enrol = 'manual' AND ue.userid = :userid",
            ['courseid' => (int)$course->id, 'userid' => (int)$target->id]
        );
        $this->assertCount(1, $ue);
        $ra = $DB->get_records('role_assignments', [
            'contextid' => (int)$coursecontext->id,
            'userid' => (int)$target->id,
            'component' => '',
        ]);
        $this->assertCount(1, $ra);
        $this->assertStringContainsString('/course/view.php', (string)$result['observation_full']);
        $this->assertSame((int)$target->id, (int)($result['produced_outputs']['userid'] ?? 0));
        $this->assertSame((int)$course->id, (int)($result['produced_outputs']['courseid'] ?? 0));
    }

    /**
     * Several matching people must produce a clarification listing the candidates, never a pick.
     */
    public function test_preflight_clarifies_on_ambiguous_user(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_user(['firstname' => 'Anna', 'lastname' => 'Muster', 'email' => 'anna1@example.com']);
        $gen->create_user(['firstname' => 'Anna', 'lastname' => 'Musterfrau', 'email' => 'anna2@example.com']);

        $dto = (new enrol_user_skill())->preflight(
            ['userquery' => 'Anna'],
            (int)context_course::instance($course->id)->id,
            (int)$USER->id
        );
        $result = $dto->to_array();

        $this->assertNotSame('pass', $result['status']);
        $this->assertContains('ENROL_USER_AMBIGUOUS', (array)($result['issue_codes'] ?? []));
        $message = implode(' ', array_map(static fn($i) => (string)($i['message'] ?? ''), (array)$dto->issues));
        $this->assertStringContainsString('anna1@example.com', $message);
        $this->assertStringContainsString('anna2@example.com', $message);
    }

    /**
     * Nobody matching the query is reported as not-found, not as ambiguous.
     */
    public function test_preflight_reports_unknown_user(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $course = $this->getDataGenerator()->create_course();

        $result = (new enrol_user_skill())->preflight(
            ['userquery' => 'nobody-with-this-name@example.com'],
            (int)context_course::instance($course->id)->id,
            (int)$USER->id
        )->to_array();

        $this->assertNotSame('pass', $result['status']);
        $this->assertContains('ENROL_USER_NOT_FOUND', (array)($result['issue_codes'] ?? []));
    }

    /**
     * A course whose manual enrolment method is disabled cannot accept enrolments.
     */
    public function test_preflight_requires_manual_enrol_instance(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $DB, $USER;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_user(['email' => 'bob@example.com']);
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['courseid' => (int)$course->id, 'enrol' => 'manual']);

        $result = (new enrol_user_skill())->preflight(
            ['userquery' => 'bob@example.com'],
            (int)context_course::instance($course->id)->id,
            (int)$USER->id
        )->to_array();

        $this->assertNotSame('pass', $result['status']);
        $this->assertContains('ENROL_NO_MANUAL_INSTANCE', (array)($result['issue_codes'] ?? []));
    }

    /**
     * An already-enrolled target stops in preflight with an honest "nothing to do".
     */
    public function test_preflight_stops_when_already_enrolled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $target = $gen->create_user(['email' => 'carla@example.com']);
        $gen->enrol_user($target->id, $course->id, 'student');

        $result = (new enrol_user_skill())->preflight(
            ['userquery' => 'carla@example.com'],
            (int)context_course::instance($course->id)->id,
            (int)$USER->id
        )->to_array();

        $this->assertNotSame('pass', $result['status']);
        $this->assertContains('ENROL_ALREADY_ENROLLED', (array)($result['issue_codes'] ?? []));
    }

    /**
     * Enrolment between confirm and execute must make execute a truthful no-op, not a failure
     * and not a duplicate enrolment.
     */
    public function test_execute_is_idempotent_when_already_enrolled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $DB, $USER;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $target = $gen->create_user(['email' => 'dora@example.com']);
        $coursecontext = context_course::instance($course->id);

        $skill = new enrol_user_skill();
        $dto = $skill->preflight(['userquery' => 'dora@example.com'], (int)$coursecontext->id, (int)$USER->id);
        $this->assertSame('pass', $dto->to_array()['status']);

        // Simulate the confirm-time race: someone enrols the user first.
        $gen->enrol_user($target->id, $course->id, 'student');

        $result = $skill->execute($dto->preparedinput, (int)$coursecontext->id, (int)$USER->id);

        $this->assertSame('executed', $result['status']);
        $this->assertStringContainsString('already enrolled', (string)$result['usermessage']);
        $ue = $DB->get_records_sql(
            "SELECT ue.id FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => (int)$course->id, 'userid' => (int)$target->id]
        );
        $this->assertCount(1, $ue);
    }

    /**
     * A named role must match an assignable role; a teacher asking for "manager" is refused
     * with the assignable list.
     */
    public function test_preflight_rejects_unassignable_role(): void {
        $this->resetAfterTest();
        global $USER;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $gen->create_user(['email' => 'emil@example.com']);
        $this->setUser($teacher);

        $result = (new enrol_user_skill())->preflight(
            ['userquery' => 'emil@example.com', 'role' => 'manager'],
            (int)context_course::instance($course->id)->id,
            (int)$USER->id
        )->to_array();

        $this->assertNotSame('pass', $result['status']);
        $this->assertContains('ENROL_ROLE_NOT_ASSIGNABLE', (array)($result['issue_codes'] ?? []));
    }

    /**
     * A named assignable role (by shortname) resolves and is used for the enrolment.
     */
    public function test_named_role_resolves_and_is_assigned(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $DB, $USER;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $target = $gen->create_user(['email' => 'frida@example.com']);
        $coursecontext = context_course::instance($course->id);

        $skill = new enrol_user_skill();
        $dto = $skill->preflight(
            ['userquery' => 'frida@example.com', 'role' => 'editingteacher'],
            (int)$coursecontext->id,
            (int)$USER->id
        );
        $this->assertSame('pass', $dto->to_array()['status'], json_encode($dto->issues));

        $teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher'], '*', MUST_EXIST);
        $this->assertSame((int)$teacherrole->id, (int)$dto->preparedinput['roleid']);

        $result = $skill->execute($dto->preparedinput, (int)$coursecontext->id, (int)$USER->id);
        $this->assertSame('executed', $result['status']);
        $ra = $DB->get_records('role_assignments', [
            'contextid' => (int)$coursecontext->id,
            'userid' => (int)$target->id,
            'roleid' => (int)$teacherrole->id,
        ]);
        $this->assertCount(1, $ra);
    }

    /**
     * Wave 32 review (EU-2): several roles carrying the query with the same token count end as a
     * clarification with exactly those roles as options - never as an error, never a guess. The question
     * comes from the language pack and carries no issue code.
     */
    public function test_two_equally_qualified_roles_end_as_a_role_question(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_user(['email' => 'greta@example.com']);
        $first = create_role('Tutor North', 'tutornorth', '');
        $second = create_role('Tutor South', 'tutorsouth', '');
        set_role_contextlevels($first, [CONTEXT_COURSE]);
        set_role_contextlevels($second, [CONTEXT_COURSE]);

        $dto = (new enrol_user_skill())->preflight(
            ['userquery' => 'greta@example.com', 'role' => 'Tutor'],
            (int)context_course::instance($course->id)->id,
            (int)$USER->id
        );
        $result = $dto->to_array();

        $this->assertNotSame('pass', $result['status']);
        $this->assertContains('ENROL_ROLE_AMBIGUOUS', (array)($result['issue_codes'] ?? []));
        $issue = (array)$dto->issues[0];
        $this->assertSame('needs_clarification', $issue['severity']);
        $offered = array_column((array)($issue['options'] ?? []), 'roleid');
        sort($offered);
        $this->assertSame([(int)$first, (int)$second], array_map('intval', $offered));
        $this->assertStringNotContainsString('ENROL_ROLE_AMBIGUOUS', (string)$issue['message']);
        $this->assertSame(
            get_string('agent_enrol_role_ambiguous', 'bookingextension_agent', (object)[
                'query' => 'Tutor',
                'candidates' => 'Tutor North (tutornorth); Tutor South (tutorsouth)',
            ]),
            (string)$issue['message']
        );
    }

    /**
     * Without enrol/manual:enrol the preflight stops at Gate 2.
     */
    public function test_user_without_capability_is_stopped(): void {
        $this->resetAfterTest();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->create_user(['email' => 'greta@example.com']);
        $this->setUser($student);

        $result = (new enrol_user_skill())->preflight(
            ['userquery' => 'greta@example.com'],
            (int)context_course::instance($course->id)->id,
            (int)$student->id
        )->to_array();

        $this->assertNotSame('pass', $result['status']);
        $this->assertContains('NO_NATIVE_CAPABILITY', (array)($result['issue_codes'] ?? []));
    }

    /**
     * Discovery picks the skill up from the directory with a valid, activatable contract.
     */
    public function test_registry_discovers_the_skill(): void {
        $this->resetAfterTest();

        $registry = skill_registry::make_default();
        $skill = $registry->get_skill('course.enrol_user');

        $this->assertInstanceOf(enrol_user_skill::class, $skill);
    }

    /**
     * Wave 32 (EU-3, L36-L41): "add me to the course" - the constructor leaves the person empty, and the
     * requester is enrolled. Before, the card asked for "me", which was searched as a name on the site.
     */
    public function test_an_empty_person_enrols_the_requester(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $course = $this->getDataGenerator()->create_course();
        $dto = (new enrol_user_skill())->preflight(
            [],
            (int)context_course::instance($course->id)->id,
            (int)$USER->id
        );

        $this->assertSame('pass', $dto->to_array()['status'], json_encode($dto->issues));
        $this->assertSame((int)$USER->id, (int)$dto->preparedinput['userid']);
    }

    /**
     * Wave 32 (EU-2, L42sol thread 13095): a role named by one of its tokens resolves to the least
     * qualified role carrying it; the names below mirror the German Moodle role names by shape only.
     */
    public function test_a_role_named_by_its_token_resolves_to_the_unqualified_role(): void {
        $assignable = [3 => 'Trainer/in', 4 => 'Trainer/in ohne Bearbeitungsrecht', 5 => 'Teilnehmer/in'];
        $shortnames = [
            3 => (object)['shortname' => 'editingteacher'],
            4 => (object)['shortname' => 'teacher'],
            5 => (object)['shortname' => 'student'],
        ];
        $this->assertSame([3 => 'Trainer/in'], enrol_user_skill::roles_covering_query('Trainer', $assignable, $shortnames));
        // Two roles carrying the query with the same token count stay ambiguous: the caller asks.
        $tie = [7 => 'Tutor A', 8 => 'Tutor B'];
        $this->assertCount(2, enrol_user_skill::roles_covering_query('Tutor', $tie, []));
        // A token no role carries resolves nothing: the caller reports it as not assignable.
        $this->assertSame([], enrol_user_skill::roles_covering_query('Manager', $assignable, $shortnames));
    }
}
