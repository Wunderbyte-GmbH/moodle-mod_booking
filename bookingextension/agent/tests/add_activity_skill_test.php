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
use bookingextension_agent\local\wizard\course\skills\add_activity_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use context_course;
use context_user;

/**
 * Contract + behaviour tests for the course.add_activity skill.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\course\skills\add_activity_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class add_activity_skill_test extends advanced_testcase {
    /**
     * Metadata reflects a mutating, course-scoped, capability-gated, cross-context skill.
     */
    public function test_metadata(): void {
        $skill = new add_activity_skill();
        $this->assertSame('course.add_activity', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(skill_risk_class::R2, $skill->get_risk_class());
        $this->assertSame(['moodle/course:manageactivities'], $skill->get_required_native_capabilities());
        $this->assertSame(CONTEXT_COURSE, $skill->get_required_context_level());
        $this->assertTrue($skill->supports_target_context());
        $this->assertSame(CONTEXT_COURSE, $skill->get_target_context_level());
    }

    /**
     * Structural validation rejects a non-object settings payload.
     */
    public function test_check_structure(): void {
        $skill = new add_activity_skill();
        $this->assertTrue($skill->check_structure([])['valid']);
        $this->assertTrue($skill->check_structure(['modname' => 'page', 'settings' => ['externalurl' => 'x']])['valid']);
        $this->assertFalse($skill->check_structure(['settings' => 'not-an-object'])['valid']);
    }

    /**
     * Outside any course (e.g. the navbar/user context) the skill asks which course.
     */
    public function test_preflight_requires_course(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $usercontextid = (int)context_user::instance($user->id)->id;

        $result = (new add_activity_skill())->preflight(['modname' => 'page'], $usercontextid, (int)$user->id);
        $this->assertSame('hard_block', $result->status);
        $this->assertContains('ADD_ACTIVITY_NO_COURSE', $result->issuecodes);
    }

    /**
     * Gate 2: a plain student cannot manage activities and is blocked.
     */
    public function test_preflight_gate2_blocks_student(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $coursecontextid = (int)context_course::instance($course->id)->id;
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');

        $result = (new add_activity_skill())->preflight(['modname' => 'page'], $coursecontextid, (int)$student->id);
        $this->assertSame('hard_block', $result->status);
        $this->assertContains('NO_NATIVE_CAPABILITY', $result->issuecodes);
    }

    /**
     * Gate 2 on the front page: a user without manage-activities on the SITE course context is
     * blocked, exactly like in any other course. Full front-page support must not weaken the
     * capability check.
     */
    public function test_preflight_gate2_blocks_user_on_site_course(): void {
        $this->resetAfterTest();
        $sitecoursecontextid = (int)context_course::instance(SITEID)->id;
        $user = $this->getDataGenerator()->create_user();

        $result = (new add_activity_skill())->preflight(
            ['modname' => 'label', 'intro' => 'Hi'],
            $sitecoursecontextid,
            (int)$user->id
        );
        $this->assertSame('hard_block', $result->status);
        $this->assertContains('NO_NATIVE_CAPABILITY', $result->issuecodes);
    }

    /**
     * A user allowed to manage activities can add a label to the Moodle front page (site course,
     * id 1) — the activity really lands on the site course. Course/section resolution is covered by
     * the resolver tests; here we prove creation targets the site course.
     */
    public function test_execute_creates_label_on_site_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;
        $sitecoursecontextid = (int)context_course::instance(SITEID)->id;

        $prepared = [
            'courseid' => (int)SITEID,
            'modname' => 'label',
            'sectionnum' => 0,
            'name' => '',
            'intro' => 'Hello!',
            'settings' => [],
        ];
        $result = (new add_activity_skill())->execute($prepared, $sitecoursecontextid, (int)$USER->id);
        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $this->assertGreaterThan(0, (int)$result['created_cmid']);

        $cm = get_coursemodule_from_id('label', (int)$result['created_cmid'], 0, false, MUST_EXIST);
        $this->assertSame((int)SITEID, (int)$cm->course);
    }

    /**
     * Regression (thread 66): on the site front page, section resolution ignores the requested
     * placement and always yields section 1 — section 0 is not rendered there, so "top"/section 0
     * would leave the activity invisible.
     */
    public function test_preflight_site_front_page_forces_section_1(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $site = get_site();
        course_create_sections_if_missing($site, [0, 1]);
        $ctxid = (int)context_course::instance(SITEID)->id;
        $PAGE->set_context(context_course::instance(SITEID));

        // The user asks for "top" (section 0); on the front page it must resolve to section 1.
        $pf = (new add_activity_skill())->preflight(
            ['modname' => 'label', 'intro' => 'hallo', 'section' => 'top'],
            $ctxid,
            (int)get_admin()->id
        );
        $this->assertSame('pass', $pf->status, implode(',', (array)$pf->issuecodes));
        $this->assertSame(1, (int)$pf->preparedinput['sectionnum']);

        $result = (new add_activity_skill())->execute($pf->preparedinput, $ctxid, (int)get_admin()->id);
        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $cm = get_fast_modinfo($site)->get_cm((int)$result['created_cmid']);
        $this->assertSame(1, (int)$cm->sectionnum);
    }

    /**
     * No module type given → list the addable types as a clarification with options.
     */
    public function test_preflight_asks_for_module(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_teacher();
        $coursecontextid = (int)context_course::instance($course->id)->id;

        $result = (new add_activity_skill())->preflight([], $coursecontextid, (int)$teacher->id);
        $this->assertSame('hard_block', $result->status);
        $this->assertContains('ADD_ACTIVITY_MODULE_AMBIGUOUS', $result->issuecodes);
        $this->assertNotEmpty($result->issues[0]['options']);
    }

    /**
     * Module given but no placement → list the sections as a clarification with options.
     */
    public function test_preflight_asks_for_section(): void {
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_teacher(['numsections' => 3]);
        $coursecontextid = (int)context_course::instance($course->id)->id;

        $result = (new add_activity_skill())->preflight(['modname' => 'page'], $coursecontextid, (int)$teacher->id);
        $this->assertSame('hard_block', $result->status);
        $this->assertContains('ADD_ACTIVITY_SECTION_AMBIGUOUS', $result->issuecodes);
        $this->assertNotEmpty($result->issues[0]['options']);
    }

    /**
     * A page without content is blocked by the module's own required-field rule.
     */
    public function test_preflight_reports_missing_required_field(): void {
        global $PAGE;
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_teacher();
        $coursecontext = context_course::instance($course->id);
        $coursecontextid = (int)$coursecontext->id;

        // The real mod_form is built as the acting user; mirror that so its required-field rules apply.
        $this->setUser($teacher);
        $PAGE->set_context($coursecontext);

        $result = (new add_activity_skill())->preflight(
            ['modname' => 'page', 'section' => 'top', 'name' => 'Intro'],
            $coursecontextid,
            (int)$teacher->id
        );
        $this->assertSame('hard_block', $result->status);
        $this->assertNotContains('pass', [$result->status]);
        // Either the real form error path or the minimal guard path, both are acceptable.
        $this->assertTrue(
            in_array('ADD_ACTIVITY_FIELDS_INVALID', $result->issuecodes, true)
            || in_array('ADD_ACTIVITY_NAME_REQUIRED', $result->issuecodes, true)
        );
    }

    /**
     * A fully specified page passes preflight — and preflight creates NOTHING (read-only proof).
     */
    public function test_preflight_passes_and_is_read_only(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_teacher();
        $coursecontext = context_course::instance($course->id);
        $coursecontextid = (int)$coursecontext->id;

        $this->setUser($teacher);
        $PAGE->set_context($coursecontext);

        $before = $DB->count_records('course_modules', ['course' => $course->id]);

        $result = (new add_activity_skill())->preflight(
            ['modname' => 'page', 'section' => 'top', 'name' => 'Welcome', 'settings' => ['content' => 'Hello.']],
            $coursecontextid,
            (int)$teacher->id
        );

        $this->assertSame('pass', $result->status);
        $this->assertSame('page', $result->preparedinput['modname']);
        $this->assertSame(0, $result->preparedinput['sectionnum']);
        $this->assertSame((int)$course->id, $result->preparedinput['courseid']);

        $after = $DB->count_records('course_modules', ['course' => $course->id]);
        $this->assertSame($before, $after, 'preflight must not create any course module');
    }

    /**
     * Execute creates a real page module in the chosen section.
     */
    public function test_execute_creates_page(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_teacher();
        $coursecontext = context_course::instance($course->id);
        $coursecontextid = (int)$coursecontext->id;

        $this->setUser($teacher);
        $PAGE->set_context($coursecontext);

        $skill = new add_activity_skill();
        $preflight = $skill->preflight(
            ['modname' => 'page', 'section' => 'top', 'name' => 'Welcome', 'settings' => ['content' => 'Hello world.']],
            $coursecontextid,
            (int)$teacher->id
        );
        $this->assertSame('pass', $preflight->status);

        $result = $skill->execute($preflight->preparedinput, $coursecontextid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $this->assertGreaterThan(0, (int)$result['created_cmid']);
        $this->assertSame('page', $result['created_modname']);

        // The module really exists in the course, in section 0.
        $cm = get_fast_modinfo($course)->get_cm((int)$result['created_cmid']);
        $this->assertSame('page', $cm->modname);
        $this->assertSame(0, (int)$cm->sectionnum);
        $this->assertSame('Welcome', $cm->name);

        // Regression guard (Wunderbyte-GmbH#2201): the page body must be persisted, not just the
        // module created — page_add_instance() drops the editor content on the headless create.
        $content = (string)$DB->get_field('page', 'content', ['id' => $cm->instance], MUST_EXIST);
        $this->assertStringContainsString(
            'Hello world.',
            $content,
            'the page body must contain the provided content'
        );

        // The preview is a self-contained data block.
        $preview = $skill->get_result_preview($result, $coursecontextid, (int)$teacher->id);
        $this->assertIsArray($preview);
        $this->assertSame('created_activity', $preview['type']);
        $this->assertNotSame('', trim((string)$preview['html']));
    }

    /**
     * Create a course with an enrolled editing teacher.
     *
     * @param array $courseopts
     * @return array
     */
    private function course_with_teacher(array $courseopts = []): array {
        $course = $this->getDataGenerator()->create_course($courseopts + ['format' => 'topics']);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        return [$course, $teacher];
    }

    /**
     * Wave 32 (AA-1, L31/L39/L41): a missing required field is named by the form's own label.
     *
     * "- page: Erforderlich" named mod_page's editor element; the reply then asked for the page's title or
     * URL. The label ("Page content" in English) travels with the message.
     */
    public function test_a_missing_required_field_carries_the_form_label(): void {
        global $PAGE;
        $this->resetAfterTest();
        [$course, $teacher] = $this->course_with_teacher();
        $coursecontext = context_course::instance($course->id);
        $this->setUser($teacher);
        $PAGE->set_context($coursecontext);

        $result = (new add_activity_skill())->preflight(
            ['modname' => 'page', 'section' => 'top', 'name' => 'Intro'],
            (int)$coursecontext->id,
            (int)$teacher->id
        );
        if (!in_array('ADD_ACTIVITY_FIELDS_INVALID', $result->issuecodes, true)) {
            $this->markTestSkipped('The page form did not build headless here; the minimal guard answered.');
        }
        $message = (string)$result->issues[0]['message'];
        $this->assertStringContainsString(get_string('content', 'page'), $message);
        // Review: no new hard-coded wording around it - the line keeps its shape, only the label is added.
        $this->assertStringContainsString('- page: ' . get_string('content', 'page') . ': ' . get_string('required'), $message);
    }

    /**
     * Wave 32 (L42sol AA-4 "Ordner", AA-3 "lien"): the module type is a closed set the constructor picks from.
     */
    public function test_modname_is_offered_as_a_closed_set(): void {
        $schema = (new add_activity_skill())->get_schema();
        $modname = (array)$schema['properties']['modname'];
        $this->assertSame(
            \bookingextension_agent\local\wizard\services\activities\module_catalog_service::WHITELIST,
            $modname['enum']
        );
        $this->assertLessThanOrEqual(159, \core_text::strlen((string)$modname['description']));
    }
}
