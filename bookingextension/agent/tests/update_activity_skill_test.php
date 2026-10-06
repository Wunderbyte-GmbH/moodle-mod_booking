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
use bookingextension_agent\local\wizard\course\skills\update_activity_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;

/**
 * Tests for the course.update_activity skill.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\course\skills\update_activity_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class update_activity_skill_test extends advanced_testcase {
    /**
     * Create a course + editing teacher + one page activity, acting as the teacher with the course context.
     *
     * @param array $pageopts
     * @return array [course, teacher, page cm record, coursecontextid]
     */
    private function setup_page(array $pageopts = []): array {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Welcome page'] + $pageopts
        );
        $coursecontext = context_course::instance($course->id);
        $this->setUser($teacher);
        $PAGE->set_context($coursecontext);
        return [$course, $teacher, $page, (int)$coursecontext->id];
    }

    /**
     * Metadata: mutating, R2, course-scoped, cross-context, manageactivities.
     */
    public function test_metadata(): void {
        $skill = new update_activity_skill();
        $this->assertSame('course.update_activity', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(skill_risk_class::R2, $skill->get_risk_class());
        $this->assertSame(['moodle/course:manageactivities'], $skill->get_required_native_capabilities());
        $this->assertTrue($skill->supports_target_context());
    }

    /**
     * Rename via cmid: preflight passes and writes nothing; execute renames the activity.
     */
    public function test_rename_via_cmid(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $page, $ctxid] = $this->setup_page();

        $skill = new update_activity_skill();
        $pf = $skill->preflight(['cmid' => (int)$page->cmid, 'name' => 'Renamed page'], $ctxid, (int)$teacher->id);
        $this->assertSame('pass', $pf->status);
        $this->assertSame((int)$page->cmid, $pf->preparedinput['cmid']);

        // Read-only preflight: name still the original until execute runs.
        $this->assertSame('Welcome page', $DB->get_field('page', 'name', ['id' => (int)$page->id]));

        $result = $skill->execute($pf->preparedinput, $ctxid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $cm = get_fast_modinfo($course)->get_cm((int)$page->cmid);
        $this->assertSame('Renamed page', $cm->name);
    }

    /**
     * Hiding via cmid sets the activity invisible.
     */
    public function test_hide_activity(): void {
        $this->resetAfterTest();
        [$course, $teacher, $page, $ctxid] = $this->setup_page();

        $skill = new update_activity_skill();
        $pf = $skill->preflight(['cmid' => (int)$page->cmid, 'visible' => false], $ctxid, (int)$teacher->id);
        $this->assertSame('pass', $pf->status);
        $result = $skill->execute($pf->preparedinput, $ctxid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $this->assertSame(0, (int)get_fast_modinfo($course)->get_cm((int)$page->cmid)->visible);
    }

    /**
     * Resolving by name works; an empty change set asks what to change.
     */
    public function test_resolve_by_name_and_no_changes(): void {
        $this->resetAfterTest();
        [$course, $teacher, $page, $ctxid] = $this->setup_page();
        $skill = new update_activity_skill();

        $nochanges = $skill->preflight(['activityquery' => 'Welcome'], $ctxid, (int)$teacher->id);
        $this->assertSame('hard_block', $nochanges->status);
        $this->assertContains('UPDATE_ACTIVITY_NO_CHANGES', $nochanges->issuecodes);

        $ok = $skill->preflight(['activityquery' => 'Welcome', 'name' => 'New name'], $ctxid, (int)$teacher->id);
        $this->assertSame('pass', $ok->status);
        $this->assertSame((int)$page->cmid, $ok->preparedinput['cmid']);
    }

    /**
     * An activity named from another course is found instead of reported missing.
     *
     * Run-23 finding (UA-2): "hide the Vorstellungs-Forum" answered "no editable activity called
     * that in this course" - about a forum that existed one course over. The right to edit it is
     * decided in the course it actually lives in, not in the one the session happens to sit in.
     */
    public function test_an_activity_in_another_course_is_found_and_judged_there(): void {
        $this->resetAfterTest();
        [$course, $teacher, $page, $ctxid] = $this->setup_page();
        unset($course, $page);

        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $other->id, 'editingteacher');
        $forum = $this->getDataGenerator()->create_module(
            'forum',
            ['course' => $other->id, 'name' => 'Vorstellungsforum']
        );

        $skill = new update_activity_skill();
        $pf = $skill->preflight(
            ['activityquery' => 'Vorstellungsforum', 'visible' => false],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('pass', $pf->status);

        $result = $skill->execute($pf->preparedinput, $ctxid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $this->assertSame(0, (int)get_fast_modinfo($other->id)->get_cm((int)$forum->cmid)->visible);
    }

    /**
     * Without the right in the course the activity lives in, the search does not help.
     */
    public function test_the_search_does_not_grant_what_the_target_course_denies(): void {
        $this->resetAfterTest();
        [$course, $teacher, $page, $ctxid] = $this->setup_page();
        unset($course, $page);

        // Enrolled, but only as a student: may see the forum, may not edit it.
        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $other->id, 'student');
        $this->getDataGenerator()->create_module('forum', ['course' => $other->id, 'name' => 'Fremdforum']);

        $skill = new update_activity_skill();
        $pf = $skill->preflight(
            ['activityquery' => 'Fremdforum', 'visible' => false],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertNotSame('pass', $pf->status);
        $this->assertContains('NO_NATIVE_CAPABILITY', $pf->issuecodes);
    }

    /**
     * An ambiguous name lists candidates with options.
     */
    public function test_ambiguous_name(): void {
        $this->resetAfterTest();
        [$course, $teacher, $page, $ctxid] = $this->setup_page();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Welcome again']);

        $result = (new update_activity_skill())->preflight(
            ['activityquery' => 'Welcome', 'name' => 'X'],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('hard_block', $result->status);
        $this->assertContains('UPDATE_ACTIVITY_AMBIGUOUS', $result->issuecodes);
        $this->assertNotEmpty($result->issues[0]['options']);
    }

    /**
     * Move an activity to another section (course-structure op, not a mod_form field).
     */
    public function test_move_to_section(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['numsections' => 3, 'format' => 'topics']);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Welcome page', 'section' => 0]
        );
        $ctxid = (int)context_course::instance($course->id)->id;
        $this->setUser($teacher);
        $PAGE->set_context(context_course::instance($course->id));

        $this->assertSame(0, (int)get_fast_modinfo($course)->get_cm((int)$page->cmid)->sectionnum);

        $skill = new update_activity_skill();
        $pf = $skill->preflight(['cmid' => (int)$page->cmid, 'section' => 2], $ctxid, (int)$teacher->id);
        $this->assertSame('pass', $pf->status);
        $this->assertSame(2, (int)$pf->preparedinput['section_move']);

        $result = $skill->execute($pf->preparedinput, $ctxid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $this->assertSame(2, (int)get_fast_modinfo($course)->get_cm((int)$page->cmid)->sectionnum);
        $this->assertStringContainsString('section 2', (string)$result['detail']);
    }

    /**
     * A move to a non-existent section asks rather than silently failing.
     */
    public function test_move_to_missing_section_clarifies(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['numsections' => 1, 'format' => 'topics']);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Welcome page', 'section' => 0]
        );
        $ctxid = (int)context_course::instance($course->id)->id;
        $this->setUser($teacher);
        $PAGE->set_context(context_course::instance($course->id));

        $pf = (new update_activity_skill())->preflight(
            ['cmid' => (int)$page->cmid, 'section' => 9],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('hard_block', $pf->status);
        $this->assertContains('UPDATE_ACTIVITY_SECTION_INVALID', $pf->issuecodes);
    }

    /**
     * Regression (thread 66): on the site front page every section maps to section 1 — a requested
     * section 0 move is normalised to 1 (section 0 is not rendered there), so the activity becomes visible.
     */
    public function test_move_on_site_front_page_forces_section_1(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $site = get_site();
        course_create_sections_if_missing($site, [0, 1]);
        $label = $this->getDataGenerator()->create_module('label', ['course' => $site->id, 'section' => 0]);
        $ctxid = (int)context_course::instance($site->id)->id;
        $PAGE->set_context(context_course::instance($site->id));

        $this->assertSame(0, (int)get_fast_modinfo($site)->get_cm((int)$label->cmid)->sectionnum);

        $skill = new update_activity_skill();
        // The user names section 0, but on the front page that must resolve to section 1.
        $pf = $skill->preflight(['cmid' => (int)$label->cmid, 'section' => 0], $ctxid, (int)get_admin()->id);
        $this->assertSame('pass', $pf->status);
        $this->assertSame(1, (int)$pf->preparedinput['section_move']);

        $result = $skill->execute($pf->preparedinput, $ctxid, (int)get_admin()->id);
        $this->assertSame('executed', $result['status']);
        $this->assertSame(1, (int)get_fast_modinfo($site)->get_cm((int)$label->cmid)->sectionnum);
    }

    /**
     * Gate 2: a student without manageactivities is blocked.
     */
    public function test_gate_blocks_student(): void {
        $this->resetAfterTest();
        [$course, $teacher, $page, $ctxid] = $this->setup_page();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $result = (new update_activity_skill())->preflight(
            ['cmid' => (int)$page->cmid, 'name' => 'X'],
            $ctxid,
            (int)$student->id
        );
        $this->assertSame('hard_block', $result->status);
        $this->assertContains('NO_NATIVE_CAPABILITY', $result->issuecodes);
    }

    /**
     * Wave 32 (UA-2, ten of ten runs): the user's spelling differs from the name only in shape.
     *
     * The run-23 test above names the forum exactly; the baseline prompt writes "Vorstellungs-Forum" and the
     * LIKE search never met "Vorstellungsforum". Hyphens, spaces and case are not part of a name.
     */
    public function test_a_name_spelled_with_a_hyphen_is_found_in_another_course(): void {
        $this->resetAfterTest();
        [$course, $teacher, $page, $ctxid] = $this->setup_page();
        unset($course, $page);

        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $other->id, 'editingteacher');
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $other->id, 'name' => 'Vorstellungsforum']);

        $pf = (new update_activity_skill())->preflight(
            ['activityquery' => 'Vorstellungs-Forum', 'visible' => false],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('pass', $pf->status);
        $this->assertSame((int)$forum->cmid, (int)$pf->preparedinput['cmid']);
    }

    /**
     * Wave 32 (UA-4, L31/L38/L41): the user's words wrap the activity name.
     *
     * "die Seite mit den Übungsdaten" names the page "Übungsdaten" - every token of the name stands in the
     * query. The test uses its own words on purpose; no word of the query is known to the resolver.
     */
    public function test_a_name_wrapped_in_the_users_words_is_found(): void {
        $this->resetAfterTest();
        [$course, $teacher, $page, $ctxid] = $this->setup_page();
        unset($course, $page);

        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $other->id, 'editingteacher');
        $target = $this->getDataGenerator()->create_module('page', ['course' => $other->id, 'name' => 'Glossary']);

        $pf = (new update_activity_skill())->preflight(
            ['activityquery' => 'the resource with the Glossary in it', 'visible' => false],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('pass', $pf->status);
        $this->assertSame((int)$target->cmid, (int)$pf->preparedinput['cmid']);
    }

    /**
     * The whole-query match outranks a short name that merely stands inside the query.
     */
    public function test_name_match_strength_prefers_the_whole_query(): void {
        $this->assertSame(
            update_activity_skill::MATCH_WHOLE_QUERY,
            update_activity_skill::name_match_strength('Vorstellungsforum', 'Vorstellungs-Forum')
        );
        $this->assertSame(
            update_activity_skill::MATCH_NAME_TOKENS,
            update_activity_skill::name_match_strength('Forum', 'Vorstellungs-Forum')
        );
        $this->assertSame(0, update_activity_skill::name_match_strength('Quiz 3', 'Vorstellungs-Forum'));

        $winners = update_activity_skill::strongest_matches(
            ['Forum', 'Vorstellungsforum', 'Quiz 3'],
            'Vorstellungs-Forum',
            static fn(string $name): string => $name
        );
        $this->assertSame(['Vorstellungsforum'], $winners);
    }

    /**
     * The ambient course never answers with a mere token match before the other courses were searched.
     */
    public function test_a_short_name_in_the_ambient_course_does_not_capture_the_query(): void {
        $this->resetAfterTest();
        [$course, $teacher, $page, $ctxid] = $this->setup_page();
        unset($page);
        $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'name' => 'Forum']);

        $other = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $other->id, 'editingteacher');
        $meant = $this->getDataGenerator()->create_module('forum', ['course' => $other->id, 'name' => 'Vorstellungsforum']);

        $pf = (new update_activity_skill())->preflight(
            ['activityquery' => 'Vorstellungs-Forum', 'visible' => false],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('pass', $pf->status);
        $this->assertSame((int)$meant->cmid, (int)$pf->preparedinput['cmid']);
    }

    /**
     * The activity reference stays inside the constructor's 160-character field window (wave 32, UA-3).
     */
    public function test_the_activityquery_description_fits_the_constructor_window(): void {
        $schema = (new update_activity_skill())->get_schema();
        $description = (string)$schema['properties']['activityquery']['description'];
        $this->assertLessThanOrEqual(159, \core_text::strlen($description));
    }

    /**
     * The two move fields stay inside the constructor's 160-character field window: cut at 160, "to the top section"
     * was built as position "top", the first place inside the activity's own section.
     */
    public function test_the_move_field_descriptions_fit_the_constructor_window(): void {
        $schema = (new update_activity_skill())->get_schema();
        foreach (['section', 'position'] as $field) {
            $description = (string)$schema['properties'][$field]['description'];
            $this->assertLessThanOrEqual(159, \core_text::strlen($description), $field);
        }
    }
}
