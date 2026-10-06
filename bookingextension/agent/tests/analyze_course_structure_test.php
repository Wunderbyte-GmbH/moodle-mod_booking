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
use bookingextension_agent\local\wizard\course\skills\analyze_course_structure_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\services\activities\course_structure_service;
use bookingextension_agent\local\wizard\skill_discovery;
use context_course;

/**
 * Contract + per-user visibility tests for course.analyze_course_structure.
 *
 * The security-critical property: the analysis never surfaces anything the acting user could not see in
 * Moodle itself. A student must not see a hidden activity or a hidden section; a teacher (with viewhidden*)
 * sees them, flagged hidden.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\course\skills\analyze_course_structure_skill
 * @covers     \bookingextension_agent\local\wizard\services\activities\course_structure_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class analyze_course_structure_test extends advanced_testcase {
    /**
     * The skill is auto-discovered and declares itself read-only / R0.
     */
    public function test_skill_is_discovered_and_is_readonly_r0(): void {
        $skills = skill_discovery::get_skill_instances('bookingextension_agent');
        $this->assertArrayHasKey('course.analyze_course_structure', $skills);
        $skill = $skills['course.analyze_course_structure'];
        $this->assertTrue($skill->is_read_only());
        $this->assertSame(skill_risk_class::R0, $skill->get_risk_class());
    }

    /**
     * A student does not see a hidden activity nor a hidden section.
     */
    public function test_student_does_not_see_hidden_activity_or_section(): void {
        $this->resetAfterTest();
        [$course, , $student] = $this->build_course();

        $structure = (new course_structure_service())->analyze($course, (int)$student->id);
        $names = $this->activity_names($structure);

        $this->assertContains('Visible page', $names);
        $this->assertNotContains('Secret page', $names, 'a hidden activity must not leak to a student');
        $this->assertNotContains('In hidden section', $names, 'an activity in a hidden section must not leak');
        $this->assertNotContains(
            2,
            array_column($structure['sections'], 'number'),
            'a hidden section must not be listed for a student'
        );
    }

    /**
     * A teacher (viewhiddenactivities/-sections) sees the hidden items, flagged hidden.
     */
    public function test_teacher_sees_hidden_items_with_flags(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->build_course();

        $structure = (new course_structure_service())->analyze($course, (int)$teacher->id);

        $secret = $this->find_activity($structure, 'Secret page');
        $this->assertNotNull($secret, 'teacher sees the hidden activity');
        $this->assertTrue($secret['hidden']);
        $this->assertTrue($secret['accessible']);

        $section2 = $this->find_section($structure, 2);
        $this->assertNotNull($section2, 'teacher sees the hidden section');
        $this->assertTrue($section2['hidden']);
    }

    /**
     * execute(): teacher gets the full structure + observation (incl. the HIDDEN marker) and a preview.
     */
    public function test_execute_returns_structure_and_preview_for_teacher(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->build_course();

        $skill = new analyze_course_structure_skill();
        $contextid = (int)context_course::instance($course->id)->id;
        $result = $skill->execute(['courseid' => (int)$course->id], $contextid, (int)$teacher->id);

        $this->assertSame('executed', $result['status']);
        $this->assertSame((int)$course->id, $result['resultid']);
        $this->assertStringContainsString('Visible page', $result['observation_full']);
        $this->assertStringContainsString('Secret page', $result['observation_full']);
        $this->assertStringContainsString('HIDDEN', $result['observation_full']);

        $preview = $skill->get_result_preview($result, $contextid, (int)$teacher->id);
        $this->assertIsArray($preview);
        $this->assertSame('course_structure', $preview['type']);
        $this->assertNotEmpty($preview['html']);
    }

    /**
     * execute(): the student's observation never carries the hidden items.
     */
    public function test_execute_observation_has_no_leak_for_student(): void {
        $this->resetAfterTest();
        [$course, , $student] = $this->build_course();

        $skill = new analyze_course_structure_skill();
        $contextid = (int)context_course::instance($course->id)->id;
        $result = $skill->execute(['courseid' => (int)$course->id], $contextid, (int)$student->id);

        $this->assertSame('executed', $result['status']);
        $this->assertStringContainsString('Visible page', $result['observation_full']);
        $this->assertStringNotContainsString('Secret page', $result['observation_full']);
        $this->assertStringNotContainsString('In hidden section', $result['observation_full']);
    }

    /**
     * A user who cannot access the course at all is denied (no structure leak).
     */
    public function test_execute_denies_user_without_course_access(): void {
        $this->resetAfterTest();
        [$course] = $this->build_course();
        // A separate, non-enrolled user on a hidden course must not get the structure.
        $outsider = $this->getDataGenerator()->create_user();
        course_change_visibility($course->id, false);

        $skill = new analyze_course_structure_skill();
        $contextid = (int)context_course::instance($course->id)->id;
        $result = $skill->execute(['courseid' => (int)$course->id], $contextid, (int)$outsider->id);

        $this->assertSame('error', $result['status']);
        $this->assertSame('permission_denied', $result['error_class']);
    }

    /**
     * Regression: an R0 skill skips preflight, so cross-context resolution must happen in execute().
     * A NAMED course must be analysed — not the ambient/current one.
     */
    public function test_execute_resolves_a_named_course_not_the_ambient_one(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        // Ambient ("current") course A and a distinct, named target course B.
        $coursea = $gen->create_course(['fullname' => 'Ambient Course A']);
        $gen->create_module('page', ['course' => $coursea->id, 'section' => 0, 'name' => 'A-only page']);
        $courseb = $gen->create_course(['fullname' => 'Zielkurs Mathematik XYZ']);
        $gen->create_module('page', ['course' => $courseb->id, 'section' => 0, 'name' => 'B-only page']);

        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $coursea->id, 'editingteacher');
        $gen->enrol_user($teacher->id, $courseb->id, 'editingteacher');
        rebuild_course_cache($coursea->id, true);
        rebuild_course_cache($courseb->id, true);

        $skill = new analyze_course_structure_skill();
        // Ambient context is course A, but the user names course B.
        $ambientcontextid = (int)context_course::instance($coursea->id)->id;
        $result = $skill->execute(['coursequery' => 'Zielkurs Mathematik XYZ'], $ambientcontextid, (int)$teacher->id);

        $this->assertSame('executed', $result['status']);
        $this->assertSame((int)$courseb->id, $result['resultid'], 'must analyse the NAMED course, not the ambient one');
        $this->assertStringContainsString('B-only page', $result['observation_full']);
        $this->assertStringNotContainsString('A-only page', $result['observation_full']);
    }

    /**
     * A named course that resolves to no (or several) matches yields a clean error, not the ambient course.
     */
    public function test_execute_errors_on_unresolvable_named_course(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->build_course();

        $skill = new analyze_course_structure_skill();
        $contextid = (int)context_course::instance($course->id)->id;
        $result = $skill->execute(['coursequery' => 'NoSuchCourseNameZZZ'], $contextid, (int)$teacher->id);

        $this->assertSame('error', $result['status']);
        $this->assertSame('course_not_found', $result['error_class']);
    }

    /**
     * Regression (thread 515): a common course name the site-wide resolver cannot pin to a single match
     * — but which IS the current course — must fall back to the operating course, not give up with
     * "course not found". Mirrors the user naming the course they are already in (e.g. "booking").
     */
    public function test_execute_falls_back_to_operating_course_when_named_course_is_current(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        // Two courses share the fullname "Booking" so a site-wide name search cannot resolve a single one.
        $current = $gen->create_course(['fullname' => 'Booking']);
        $gen->create_course(['fullname' => 'Booking']);
        $gen->create_module('page', ['course' => $current->id, 'section' => 0, 'name' => 'Current-course page']);

        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $current->id, 'editingteacher');
        rebuild_course_cache($current->id, true);

        $skill = new analyze_course_structure_skill();
        // We are in $current and the user names it by its (ambiguous) fullname.
        $contextid = (int)context_course::instance($current->id)->id;
        $result = $skill->execute(['coursequery' => 'Booking'], $contextid, (int)$teacher->id);

        $this->assertSame('executed', $result['status'], 'must analyse the current course instead of giving up');
        $this->assertSame((int)$current->id, $result['resultid']);
        $this->assertStringContainsString('Current-course page', $result['observation_full']);
    }

    /**
     * Build a course with: a visible activity + a hidden activity in section 1, and a hidden section 2.
     *
     * @return array
     */
    private function build_course(): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['numsections' => 3]);
        $teacher = $gen->create_user();
        $student = $gen->create_user();
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $gen->enrol_user($student->id, $course->id, 'student');

        $gen->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Visible page']);
        $gen->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Secret page', 'visible' => 0]);
        $gen->create_module('page', ['course' => $course->id, 'section' => 2, 'name' => 'In hidden section']);
        set_section_visible($course->id, 2, 0);

        rebuild_course_cache($course->id, true);
        return [$course, $teacher, $student];
    }

    /**
     * Flat list of all activity names in a structure.
     *
     * @param array $structure
     * @return string[]
     */
    private function activity_names(array $structure): array {
        $names = [];
        foreach ((array)$structure['sections'] as $section) {
            foreach ((array)$section['activities'] as $activity) {
                $names[] = (string)$activity['name'];
            }
        }
        return $names;
    }

    /**
     * Find an activity node by name.
     *
     * @param array $structure
     * @param string $name
     * @return array|null
     */
    private function find_activity(array $structure, string $name): ?array {
        foreach ((array)$structure['sections'] as $section) {
            foreach ((array)$section['activities'] as $activity) {
                if ((string)$activity['name'] === $name) {
                    return $activity;
                }
            }
        }
        return null;
    }

    /**
     * Find a section node by number.
     *
     * @param array $structure
     * @param int $number
     * @return array|null
     */
    private function find_section(array $structure, int $number): ?array {
        foreach ((array)$structure['sections'] as $section) {
            if ((int)$section['number'] === $number) {
                return $section;
            }
        }
        return null;
    }

    /**
     * Wave 32 (ACS-2/ACS-4, L30/L33/L38/L40): the card says inside the selector window that a named course
     * needs no lookup, and the field no longer sends the model to course.search_courses first.
     */
    public function test_a_named_course_needs_no_lookup(): void {
        $schema = (new analyze_course_structure_skill())->get_schema();
        $window = \core_text::substr((string)$schema['description'], 0, 240);
        $this->assertStringContainsString('coursequery', $window);
        $this->assertStringContainsString('no lookup needed', $window);
        $this->assertStringNotContainsString(
            'search_courses',
            (string)$schema['properties']['coursequery']['description']
        );
        // Review: the field text fits the constructor's field window, so no half of it is cut off.
        $this->assertLessThanOrEqual(159, \core_text::strlen((string)$schema['properties']['coursequery']['description']));
    }
}
