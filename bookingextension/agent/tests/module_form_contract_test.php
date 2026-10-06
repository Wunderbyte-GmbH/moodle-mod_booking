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
use bookingextension_agent\local\wizard\services\activities\activity_creation_service;
use bookingextension_agent\local\wizard\services\activities\module_form_contract;
use context_course;

/**
 * Pins the headless mod_form contract for each whitelisted module (the documented brittleness).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\activities\module_form_contract
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class module_form_contract_test extends advanced_testcase {
    /**
     * Minimal valid inputs per whitelist module.
     *
     * @return array
     */
    public static function valid_inputs_provider(): array {
        return [
            'page' => ['page', 'A page', 'Some intro', ['content' => 'The page body.']],
            'url' => ['url', 'A link', '', ['externalurl' => 'https://moodle.org']],
            'label' => ['label', '', 'The label text.', []],
            'book' => ['book', 'A book', 'Book intro', []],
            'folder' => ['folder', 'A folder', 'Folder intro', []],
            'forum' => ['forum', 'A forum', 'Forum intro', []],
        ];
    }

    /**
     * Each whitelisted module's form builds headless and validates with minimal valid inputs.
     *
     * @dataProvider valid_inputs_provider
     * @param string $modname
     * @param string $name
     * @param string $intro
     * @param array $settings
     */
    public function test_form_builds_and_validates(string $modname, string $name, string $intro, array $settings): void {
        global $PAGE;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);
        $this->setAdminUser();
        $PAGE->set_context(context_course::instance($course->id));

        $contract = new module_form_contract();
        $result = $contract->validate($course, $modname, 0, $name, $intro, $settings);

        $this->assertTrue($result['built'], "mod_form for {$modname} must build headless.");
        $this->assertTrue(
            $result['ok'],
            "Minimal valid input for {$modname} should validate; errors: " . json_encode($result['errors'])
        );

        // The prepared moduleinfo is complete enough for add_moduleinfo().
        $moduleinfo = $contract->build_prepared_moduleinfo($course, $modname, 0, $name, $intro, $settings);
        $this->assertSame($modname, $moduleinfo->modulename);
        $this->assertSame(0, (int)$moduleinfo->section);
    }

    /**
     * A URL without externalurl is reported as a real, missing required field.
     */
    public function test_required_field_detected(): void {
        global $PAGE;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);
        $this->setAdminUser();
        $PAGE->set_context(context_course::instance($course->id));

        $result = (new module_form_contract())->validate($course, 'url', 0, 'A link', '', []);
        $this->assertTrue($result['built']);
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('externalurl', $result['errors']);
    }

    /**
     * Create a course with one page module and return [course, cm record, page instance].
     *
     * @param string $content Stored page content.
     * @return array
     */
    private function build_page_course(string $content = '<p>Stored body</p>'): array {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Welcome page',
            'content' => $content,
            'contentformat' => FORMAT_HTML,
        ]);
        $this->setAdminUser();
        $PAGE->set_context(context_course::instance($course->id));
        $cm = get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST);
        return [$course, $cm, $page];
    }

    /**
     * A rename-only update of a page validates — the required 'page' content editor is untouched —
     * and the execute path preserves the stored content (regression: "page: Required" killed every
     * mod_page update and could wipe the content).
     */
    public function test_update_page_rename_only_validates_and_preserves_content(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cm] = $this->build_page_course();

        $contract = new module_form_contract();
        $result = $contract->validate_update($course, $cm, ['name' => 'Renamed page']);
        $this->assertTrue($result['built']);
        $this->assertTrue($result['ok'], 'Rename-only must validate; errors: ' . json_encode($result['errors']));

        // Execute path: the prepared moduleinfo must carry the stored content through the update.
        $moduleinfo = $contract->build_prepared_update_moduleinfo($course, $cm, ['name' => 'Renamed page']);
        $this->assertStringContainsString('Stored body', (string)($moduleinfo->page['text'] ?? ''));

        (new activity_creation_service())->update($cm, $moduleinfo, $course);
        $record = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame('Renamed page', $record->name);
        $this->assertStringContainsString('Stored body', (string)$record->content);
    }

    /**
     * A rename-only update validates even when the stored content is already empty: an untouched,
     * pre-existing required-field violation must not block an unrelated change (the audit case that
     * made course.update_activity dead for mod_page).
     */
    public function test_update_page_rename_only_with_empty_content_validates(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cm] = $this->build_page_course();
        $DB->set_field('page', 'content', '', ['id' => $cm->instance]);
        rebuild_course_cache($course->id, true);

        $result = (new module_form_contract())->validate_update($course, $cm, ['name' => 'Renamed page']);
        $this->assertTrue($result['built']);
        $this->assertTrue($result['ok'], 'Errors: ' . json_encode($result['errors']));
    }

    /**
     * Updating the page content via settings.content validates against the CANDIDATE content (not the
     * stored one) and the execute path writes the new content.
     */
    public function test_update_page_content_via_settings(): void {
        global $DB;
        $this->resetAfterTest();
        // Empty stored content: only the candidate content can satisfy the required 'page' editor.
        [$course, $cm] = $this->build_page_course('');

        $contract = new module_form_contract();
        $changes = ['settings' => ['content' => '<p>New body</p>']];
        $result = $contract->validate_update($course, $cm, $changes);
        $this->assertTrue($result['built']);
        $this->assertTrue($result['ok'], 'Errors: ' . json_encode($result['errors']));

        $moduleinfo = $contract->build_prepared_update_moduleinfo($course, $cm, $changes);
        (new activity_creation_service())->update($cm, $moduleinfo, $course);
        $record = $DB->get_record('page', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertStringContainsString('New body', (string)$record->content);
    }

    /**
     * Explicitly emptying the page content IS the change — the required-field error must still fire.
     */
    public function test_update_page_emptying_content_is_rejected(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->build_page_course();

        $result = (new module_form_contract())->validate_update($course, $cm, ['settings' => ['content' => '']]);
        $this->assertTrue($result['built']);
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('page', $result['errors']);
    }

    /**
     * Rename-only regression for a module without extra required editors (forum): still validates and
     * the execute path applies the new name.
     */
    public function test_update_forum_rename_regression(): void {
        global $DB, $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['format' => 'topics', 'numsections' => 2]);
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'name' => 'Old forum']);
        $this->setAdminUser();
        $PAGE->set_context(context_course::instance($course->id));
        $cm = get_coursemodule_from_id('forum', $forum->cmid, 0, false, MUST_EXIST);

        $contract = new module_form_contract();
        $result = $contract->validate_update($course, $cm, ['name' => 'New forum']);
        $this->assertTrue($result['built']);
        $this->assertTrue($result['ok'], 'Errors: ' . json_encode($result['errors']));

        $moduleinfo = $contract->build_prepared_update_moduleinfo($course, $cm, ['name' => 'New forum']);
        (new activity_creation_service())->update($cm, $moduleinfo, $course);
        $this->assertSame('New forum', $DB->get_field('forum', 'name', ['id' => $cm->instance]));
    }
}
