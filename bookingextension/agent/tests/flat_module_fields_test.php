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
use bookingextension_agent\local\wizard\course\skills\update_activity_skill;
use context_course;

/**
 * The link of a URL and the text of a Page reach the module through the flat fields externalurl and content.
 *
 * Wunderbyte-GmbH/Wunderbyte-GmbH#2600: with the values nested in a settings object, Gemma's constructor wrote invalid
 * JSON at the deepest closing brackets (UA-3 valid 12 of 20, AA-3 1 of 10 in byte-exact replays); with two flat
 * fields every replay was valid. The settings object stays for every other module field.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\course\skills\add_activity_skill
 * @covers     \bookingextension_agent\local\wizard\course\skills\update_activity_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class flat_module_fields_test extends advanced_testcase {
    /**
     * Course with an editing teacher, acting as the teacher in the course context.
     *
     * @return array [course, teacher, coursecontextid]
     */
    private function course_as_teacher(): array {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $coursecontext = context_course::instance($course->id);
        $this->setUser($teacher);
        $PAGE->set_context($coursecontext);
        return [$course, $teacher, (int)$coursecontext->id];
    }

    /**
     * Both skills declare externalurl and content as plain string fields next to settings.
     */
    public function test_both_skills_declare_the_flat_fields(): void {
        foreach ([new add_activity_skill(), new update_activity_skill()] as $skill) {
            $properties = $skill->get_schema()["properties"];
            foreach (['externalurl', 'content'] as $field) {
                $this->assertArrayHasKey($field, $properties, $skill->get_name() . ' lacks ' . $field);
                $this->assertSame('string', $properties[$field]['type']);
                $this->assertFalse((bool)($properties[$field]['required'] ?? false));
            }
            $this->assertSame('object', $properties['settings']['type']);
        }
    }

    /**
     * Every field text touched by #2600 fits the 160-character window the constructor sees per field.
     */
    public function test_touched_field_texts_fit_the_constructor_window(): void {
        $fields = [
            [new add_activity_skill(), ['name', 'externalurl', 'content', 'settings']],
            [new update_activity_skill(), ['externalurl', 'content', 'settings']],
            [new \bookingextension_agent\local\wizard\course\skills\list_categories_skill(), ['limit']],
            [new \bookingextension_agent\local\wizard\report\skills\search_reports_skill(), ['limit']],
        ];
        foreach ($fields as [$skill, $names]) {
            $properties = $skill->get_schema()['properties'];
            foreach ($names as $name) {
                $text = trim((string)preg_replace('/\s+/u', ' ', (string)($properties[$name]['description'] ?? '')));
                $this->assertNotSame('', $text, $skill->get_name() . '.' . $name);
                $this->assertLessThanOrEqual(160, \core_text::strlen($text), $skill->get_name() . '.' . $name . ': ' . $text);
            }
        }
    }

    /**
     * update_activity changes the link of a URL given only as the flat externalurl field.
     */
    public function test_update_changes_the_link_of_a_url(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $ctxid] = $this->course_as_teacher();
        $url = $this->getDataGenerator()->create_module(
            'url',
            ['course' => $course->id, 'name' => 'Federation', 'externalurl' => 'https://old.example.org']
        );

        $skill = new update_activity_skill();
        $pf = $skill->preflight(['cmid' => (int)$url->cmid, 'externalurl' => 'https://new.example.org'], $ctxid, (int)$teacher->id);
        $this->assertSame('pass', $pf->status);

        $result = $skill->execute($pf->preparedinput, $ctxid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $this->assertSame('https://new.example.org', $DB->get_field('url', 'externalurl', ['id' => (int)$url->id]));
    }

    /**
     * update_activity changes the text of a Page given only as the flat content field.
     */
    public function test_update_changes_the_text_of_a_page(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $ctxid] = $this->course_as_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Exercises']);

        $skill = new update_activity_skill();
        $pf = $skill->preflight(
            ['cmid' => (int)$page->cmid, 'content' => 'The files are in the folder.'],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('pass', $pf->status);

        $result = $skill->execute($pf->preparedinput, $ctxid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $content = (string)$DB->get_field('page', 'content', ['id' => (int)$page->id]);
        $this->assertStringContainsString('The files are in the folder.', $content);
    }

    /**
     * add_activity creates a URL whose link came as the flat externalurl field.
     */
    public function test_add_creates_a_url_with_its_link(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $ctxid] = $this->course_as_teacher();

        $skill = new add_activity_skill();
        $pf = $skill->preflight(
            ['modname' => 'url', 'section' => 'top', 'name' => 'Federation', 'externalurl' => 'https://federation.example.net'],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('pass', $pf->status);

        $result = $skill->execute($pf->preparedinput, $ctxid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $cm = get_fast_modinfo($course)->get_cm((int)$result['created_cmid']);
        $this->assertSame('https://federation.example.net', $DB->get_field('url', 'externalurl', ['id' => (int)$cm->instance]));
    }

    /**
     * add_activity creates a Page whose text came as the flat content field.
     */
    public function test_add_creates_a_page_with_its_text(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $teacher, $ctxid] = $this->course_as_teacher();

        $skill = new add_activity_skill();
        $pf = $skill->preflight(
            ['modname' => 'page', 'section' => 'top', 'name' => 'Exercises week 3', 'content' => 'The files are in the folder.'],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('pass', $pf->status);

        $result = $skill->execute($pf->preparedinput, $ctxid, (int)$teacher->id);
        $this->assertSame('executed', $result['status']);
        $cm = get_fast_modinfo($course)->get_cm((int)$result['created_cmid']);
        $content = (string)$DB->get_field('page', 'content', ['id' => (int)$cm->instance]);
        $this->assertStringContainsString('The files are in the folder.', $content);
    }

    /**
     * A URL without any link is still blocked by the module's own required-field rule (a question, not an error).
     */
    public function test_add_url_without_link_still_asks(): void {
        $this->resetAfterTest();
        [, $teacher, $ctxid] = $this->course_as_teacher();

        $result = (new add_activity_skill())->preflight(
            ['modname' => 'url', 'section' => 'top', 'name' => 'Federation'],
            $ctxid,
            (int)$teacher->id
        );
        $this->assertSame('hard_block', $result->status);
        $this->assertNotEmpty(array_intersect(['ADD_ACTIVITY_FIELDS_INVALID', 'ADD_ACTIVITY_NAME_REQUIRED'], $result->issuecodes));
    }
}
