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
use bookingextension_agent\form\sitesearch_scope_rule_form;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_area_registry;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_resolver;

/**
 * The add-scope-rule dynamic form (context governance K4): submission writes the rule through the
 * repository (upsert), validation rejects bogus areas/scopes, and access is gated on the
 * configuresitesearch capability at system context.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\form\sitesearch_scope_rule_form
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sitesearch_scope_rule_form_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        // The resolver memoizes per request; in PHPUnit one request spans every test (#2339).
        \bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_resolver::reset_request_cache();
    }

    /** A file-less reference area. */
    private const AREAKEY = 'mod_page-activity';

    /** A file-capable reference area (uses_file_indexing() true). */
    private const FILEAREAKEY = 'mod_resource-activity';

    /**
     * Submitting a course rule persists the enabled rule row for exactly that scope (site row
     * untouched) and returns the pre-warmed per-scope estimate.
     */
    public function test_process_dynamic_submission_creates_course_rule(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_module('page', ['course' => $course->id, 'name' => 'One', 'content' => 'Body.']);

        $submitdata = sitesearch_scope_rule_form::mock_ajax_submit([
            'area' => self::AREAKEY,
            'scopetype' => sitesearch_scope_repository::SCOPETYPE_COURSE,
            'scopeid' => (int)$course->id,
            'enabled' => 1,
        ]);
        $form = new sitesearch_scope_rule_form(null, null, 'post', '', [], true, $submitdata, true);

        $this->assertTrue($form->is_validated());
        $result = $form->process_dynamic_submission();
        $this->assertTrue($result->saved);

        // The pre-warmed estimate is the course-scoped figure (one page document).
        $this->assertNotNull($result->estimate);
        $this->assertSame(1, $result->estimate['doccount']);

        $repository = new sitesearch_scope_repository();
        $this->assertTrue($repository->is_enabled(
            self::AREAKEY,
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course->id
        ));
        // The site default stays untouched (off).
        $this->assertFalse($repository->is_enabled(self::AREAKEY));
        // The file-less area never writes a file flag.
        $this->assertFalse($repository->is_includefiles(
            self::AREAKEY,
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course->id
        ));
    }

    /**
     * A category rule on a file-capable area persists both flags of the pair (enabled +
     * includefiles) on the category scope row; resubmitting the same scope UPDATES the row
     * (repository upsert — the form doubles as the rule editor).
     */
    public function test_process_dynamic_submission_creates_and_updates_category_rule(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $category = $this->getDataGenerator()->create_category();

        $submit = function (int $enabled, int $includefiles) use ($category): void {
            $submitdata = sitesearch_scope_rule_form::mock_ajax_submit([
                'area' => self::FILEAREAKEY,
                'scopetype' => sitesearch_scope_repository::SCOPETYPE_CATEGORY,
                'scopeid' => (int)$category->id,
                'enabled' => $enabled,
                'includefiles' => $includefiles,
            ]);
            $form = new sitesearch_scope_rule_form(null, null, 'post', '', [], true, $submitdata, true);
            $this->assertTrue($form->is_validated());
            $this->assertTrue($form->process_dynamic_submission()->saved);
        };

        $submit(1, 1);

        global $DB;
        $repository = new sitesearch_scope_repository();
        $this->assertTrue($repository->is_enabled(
            self::FILEAREAKEY,
            sitesearch_scope_repository::SCOPETYPE_CATEGORY,
            (int)$category->id
        ));
        $this->assertTrue($repository->is_includefiles(
            self::FILEAREAKEY,
            sitesearch_scope_repository::SCOPETYPE_CATEGORY,
            (int)$category->id
        ));
        $this->assertCount(1, $DB->get_records('bx_agent_search_scope', ['area' => self::FILEAREAKEY]));

        // Same scope again with flipped flags: still ONE row, values updated.
        $submit(0, 0);
        $this->assertFalse($repository->is_enabled(
            self::FILEAREAKEY,
            sitesearch_scope_repository::SCOPETYPE_CATEGORY,
            (int)$category->id
        ));
        $this->assertFalse($repository->is_includefiles(
            self::FILEAREAKEY,
            sitesearch_scope_repository::SCOPETYPE_CATEGORY,
            (int)$category->id
        ));
        $this->assertCount(1, $DB->get_records('bx_agent_search_scope', ['area' => self::FILEAREAKEY]));
    }

    /**
     * The wildcard '*' (§3.0) is a valid form area: submission persists the wildcard rule row
     * (enabled + includefiles pair — the file flag is always offered for the wildcard) and the
     * resolver then covers a concrete area without any own rule through it.
     */
    public function test_process_dynamic_submission_accepts_wildcard_area(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_module('page', ['course' => $course->id, 'name' => 'One', 'content' => 'Body.']);
        $wildcard = site_content_area_registry::WILDCARD;

        $submitdata = sitesearch_scope_rule_form::mock_ajax_submit([
            'area' => $wildcard,
            'scopetype' => sitesearch_scope_repository::SCOPETYPE_COURSE,
            'scopeid' => (int)$course->id,
            'enabled' => 1,
            'includefiles' => 1,
        ]);
        $form = new sitesearch_scope_rule_form(null, null, 'post', '', [], true, $submitdata, true);

        $this->assertTrue($form->is_validated());
        $result = $form->process_dynamic_submission();
        // The pre-warmed estimate sums every covered area; areas without context-restriction
        // support are skipped fail-soft with a developer debugging note.
        $this->resetDebugging();
        $this->assertTrue($result->saved);
        $this->assertNotNull($result->estimate);
        $this->assertGreaterThanOrEqual(1, $result->estimate['doccount']);

        global $DB;
        $repository = new sitesearch_scope_repository();
        $this->assertTrue($repository->is_enabled(
            $wildcard,
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course->id
        ));
        $this->assertTrue($repository->is_includefiles(
            $wildcard,
            sitesearch_scope_repository::SCOPETYPE_COURSE,
            (int)$course->id
        ));
        $this->assertCount(1, $DB->get_records('bx_agent_search_scope', ['area' => $wildcard]));

        // The wildcard rule covers a concrete area with no own rows (§3.0 pair semantics).
        $this->assertSame(
            ['enabled' => true, 'includefiles' => true],
            (new sitesearch_scope_resolver())->effective(self::AREAKEY, (int)$course->id)
        );
    }

    /**
     * Validation rejects unknown areas, bogus scope types and vanished scope targets.
     */
    public function test_validation_rejects_bogus_submissions(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        // Unknown area.
        $form = new sitesearch_scope_rule_form(null, null, 'post', '', [], true, sitesearch_scope_rule_form::mock_ajax_submit([
            'area' => 'mod_unknown-nowhere',
            'scopetype' => sitesearch_scope_repository::SCOPETYPE_COURSE,
            'scopeid' => (int)$course->id,
            'enabled' => 1,
        ]), true);
        $this->assertFalse($form->is_validated());

        // Vanished course.
        $missingid = (int)$DB->get_field_sql('SELECT MAX(id) + 1000 FROM {course}');
        $form = new sitesearch_scope_rule_form(null, null, 'post', '', [], true, sitesearch_scope_rule_form::mock_ajax_submit([
            'area' => self::AREAKEY,
            'scopetype' => sitesearch_scope_repository::SCOPETYPE_COURSE,
            'scopeid' => $missingid,
            'enabled' => 1,
        ]), true);
        $this->assertFalse($form->is_validated());

        // Site is not a rule scope type of this form.
        $form = new sitesearch_scope_rule_form(null, null, 'post', '', [], true, sitesearch_scope_rule_form::mock_ajax_submit([
            'area' => self::AREAKEY,
            'scopetype' => sitesearch_scope_repository::SCOPETYPE_SITE,
            'scopeid' => (int)$course->id,
            'enabled' => 1,
        ]), true);
        $this->assertFalse($form->is_validated());
    }

    /**
     * The AJAX access gate requires the configuresitesearch capability at system context: a
     * plain user is rejected, the admin passes.
     */
    public function test_check_access_requires_capability(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $args = [
            'area' => self::AREAKEY,
            'scopetype' => sitesearch_scope_repository::SCOPETYPE_COURSE,
            'scopeid' => (int)$course->id,
            'enabled' => 1,
        ];

        $this->setAdminUser();
        $submitdata = sitesearch_scope_rule_form::mock_ajax_submit($args);
        $form = new sitesearch_scope_rule_form(null, null, 'post', '', [], true, $submitdata, true);
        $method = new \ReflectionMethod($form, 'check_access_for_dynamic_submission');
        $method->invoke($form);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $submitdata = sitesearch_scope_rule_form::mock_ajax_submit($args);
        // The dynamic_form constructor already runs check_access_for_dynamic_submission for AJAX
        // submissions (dynamic_form.php:70), so the expectation must precede instantiation.
        $this->expectException(\required_capability_exception::class);
        new sitesearch_scope_rule_form(null, null, 'post', '', [], true, $submitdata, true);
    }
}
