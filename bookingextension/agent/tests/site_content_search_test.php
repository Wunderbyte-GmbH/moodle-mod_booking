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
use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;
use bookingextension_agent\local\wizard\services\retrieval\site_content_row_mapper;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository;

/**
 * Site-content search: indexing + the two-gate access model (Stage 1, mod_page).
 *
 * Uses an injected deterministic embedder so the whole path runs without the LLM provider or any API
 * call; the embeddings provider CLASS still has to exist for the readiness gate, so the suite skips
 * where it is absent.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class site_content_search_test extends advanced_testcase {
    /**
     * A deterministic embedder returning a fixed unit vector of the requested dimensionality.
     *
     * @return callable
     */
    private function fake_embedder(): callable {
        return function (string $text, int $contextid, int $userid, int $dims): array {
            unset($text, $contextid, $userid);
            $vector = array_fill(0, max(1, $dims), 0.0);
            $vector[0] = 1.0;
            return $vector;
        };
    }

    /**
     * Enable the DB backend and one search area; skip if the provider class is absent.
     *
     * @param string $areakey The search area to enable.
     * @return void
     */
    private function enable_site_search(string $areakey = 'mod_page-activity'): void {
        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            $this->markTestSkipped('embeddings provider not available');
        }
        set_config('embeddingsstore', 'db', 'bookingextension_agent');
        (new sitesearch_scope_repository())->set_enabled($areakey, true);
    }

    /**
     * An index service wired with the deterministic embedder.
     *
     * @return site_content_index_service
     */
    private function indexer(): site_content_index_service {
        return new site_content_index_service($this->fake_embedder());
    }

    /**
     * A search service wired with the deterministic embedder.
     *
     * @return site_content_search_service
     */
    private function searcher(): site_content_search_service {
        return new site_content_search_service($this->fake_embedder());
    }

    /**
     * An enrolled user finds an indexed page; a non-enrolled user finds nothing.
     */
    public function test_enrolled_user_finds_page_others_do_not(): void {
        $this->resetAfterTest();
        $this->enable_site_search();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', [
            'course' => $course->id,
            'name' => 'Alpha Page',
            'content' => 'Special enrolment instructions for the seminar.',
        ]);
        $usera = $gen->create_user();
        $userb = $gen->create_user();
        $gen->enrol_user($usera->id, $course->id);

        $this->setAdminUser();
        $result = $this->indexer()->update();
        $this->assertSame('ok', $result['status']);
        $this->assertGreaterThanOrEqual(1, $result['embedded']);

        // Enrolled user: finds the page, with a working deep link.
        $this->setUser($usera);
        $hits = $this->searcher()->search('enrolment', 0, 5);
        $docids = array_map(static fn(array $r): int => $r['docid'], $hits);
        $this->assertContains((int)$page->id, $docids);
        $match = array_values(array_filter($hits, static fn(array $r): bool => (int)$r['docid'] === (int)$page->id))[0];
        $this->assertStringContainsString('/mod/page/view.php', $match['url']);

        // Non-enrolled user: nothing (empty prefilter AND check_access would deny).
        $this->setUser($userb);
        $this->assertSame([], $this->searcher()->search('enrolment', 0, 5));
    }

    /**
     * End-to-end over a course-level area (§11.27/§7-B): with core_course-course enabled, an
     * enrolled user AND a non-enrolled user with course visibility find a course-summary hit
     * (course deep link included); a hidden course is found by neither.
     */
    public function test_course_summary_area_end_to_end(): void {
        $this->resetAfterTest();
        $this->enable_site_search('core_course-course');

        $gen = $this->getDataGenerator();
        $coursea = $gen->create_course([
            'fullname' => 'Summer Seminar',
            'summary' => 'Public enrolment guidance for the summer seminar.',
        ]);
        $courseb = $gen->create_course([
            'fullname' => 'Hidden Planning',
            'summary' => 'Confidential enrolment plans.',
            'visible' => 0,
        ]);
        $usera = $gen->create_user();
        $userb = $gen->create_user();
        $gen->enrol_user($usera->id, $coursea->id);

        $this->setAdminUser();
        $result = $this->indexer()->update();
        $this->assertSame('ok', $result['status']);
        $this->assertSame('ok', $result['areas']['core_course-course']['status']);
        $this->assertGreaterThanOrEqual(1, $result['embedded']);

        // Enrolled user: finds the visible course, with the course deep link, never the hidden one.
        $this->setUser($usera);
        $hits = $this->searcher()->search('enrolment', 0, 5);
        $docids = array_map(static fn(array $r): int => $r['docid'], $hits);
        $this->assertContains((int)$coursea->id, $docids);
        $this->assertNotContains((int)$courseb->id, $docids);
        $match = array_values(array_filter($hits, static fn(array $r): bool => (int)$r['docid'] === (int)$coursea->id))[0];
        $this->assertStringContainsString('/course/view.php', $match['url']);
        $this->assertSame((int)\context_course::instance($coursea->id)->id, $match['contextid']);

        // Non-enrolled user: the visible course is reachable through the §7-B
        // visible-not-enrolled branch; the hidden course stays invisible.
        $this->setUser($userb);
        $hits = $this->searcher()->search('enrolment', 0, 5);
        $docids = array_map(static fn(array $r): int => $r['docid'], $hits);
        $this->assertContains((int)$coursea->id, $docids);
        $this->assertNotContains((int)$courseb->id, $docids);
    }

    /**
     * A hidden module is indexed (content exists) but never returned to a user who cannot see it.
     */
    public function test_hidden_module_is_denied(): void {
        $this->resetAfterTest();
        $this->enable_site_search();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', [
            'course' => $course->id,
            'name' => 'Hidden Page',
            'content' => 'Confidential enrolment details.',
            'visible' => 0,
        ]);
        $usera = $gen->create_user();
        $gen->enrol_user($usera->id, $course->id);

        // Admin can see hidden content, so it gets indexed.
        $this->setAdminUser();
        $result = $this->indexer()->update();
        $this->assertSame('ok', $result['status']);
        $this->assertGreaterThanOrEqual(1, $result['embedded']);

        // The enrolled but non-privileged user cannot see the hidden page → zero hits.
        $this->setUser($usera);
        $hits = $this->searcher()->search('enrolment', 0, 5);
        $docids = array_map(static fn(array $r): int => $r['docid'], $hits);
        $this->assertNotContains((int)$page->id, $docids);
    }

    /**
     * The CSV backend must never serve site content (it ignores the access filter). Even with data
     * already indexed in the DB, flipping to CSV returns nothing.
     */
    public function test_csv_backend_serves_nothing(): void {
        $this->resetAfterTest();
        $this->enable_site_search();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_module('page', [
            'course' => $course->id,
            'name' => 'Guarded Page',
            'content' => 'Enrolment guidance.',
        ]);
        $usera = $gen->create_user();
        $gen->enrol_user($usera->id, $course->id);

        $this->setAdminUser();
        $this->indexer()->update();

        // Sanity: on the DB backend the enrolled user finds content.
        $this->setUser($usera);
        $this->assertNotEmpty($this->searcher()->search('enrolment', 0, 5));

        // Flip to CSV: the filter-less backend must be hard-guarded off → nothing served.
        set_config('embeddingsstore', 'csv', 'bookingextension_agent');
        $this->assertSame([], $this->searcher()->search('enrolment', 0, 5));

        // And the indexer refuses to run on CSV.
        $this->setAdminUser();
        $skipped = $this->indexer()->update();
        $this->assertSame('skipped', $skipped['status']);
        $this->assertSame('requires_db_backend', $skipped['reason']);
    }

    /**
     * With no area enabled (the default), the indexer indexes nothing and search returns nothing.
     */
    public function test_all_off_by_default(): void {
        $this->resetAfterTest();
        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            $this->markTestSkipped('embeddings provider not available');
        }
        set_config('embeddingsstore', 'db', 'bookingextension_agent');
        // Deliberately do NOT enable any area.

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_module('page', ['course' => $course->id, 'name' => 'Page', 'content' => 'text']);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $this->setAdminUser();
        $result = $this->indexer()->update();
        $this->assertSame('ok', $result['status']);
        $this->assertSame('no_areas_enabled', $result['reason']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame('disabled_pruned', $result['areas']['mod_page-activity']['status']);

        $this->setUser($user);
        $this->assertSame([], $this->searcher()->search('page', 0, 5));
    }

    /**
     * Disabling every area stops serving immediately — for regular users AND site admins (the
     * admin gets the unfiltered prefilter, so the query-time enablement gate is what protects
     * them) — and the next update() prunes the stored rows via delete_owner.
     */
    public function test_disabling_areas_stops_serving_and_prunes(): void {
        $this->resetAfterTest();
        $this->enable_site_search();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_module('page', [
            'course' => $course->id,
            'name' => 'Retained Page',
            'content' => 'Enrolment information for the course.',
        ]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $this->setAdminUser();
        $this->indexer()->update();

        // Baseline: the enrolled user finds it.
        $this->setUser($user);
        $this->assertNotEmpty($this->searcher()->search('enrolment', 0, 5));

        // Disable every area → immediately not served (before any update prunes).
        (new sitesearch_scope_repository())->set_enabled('mod_page-activity', false);
        $this->assertSame([], $this->searcher()->search('enrolment', 0, 5));

        // The admin filter bypass: the site admin gets no context prefilter, so only the
        // query-time enablement gate stands between them and the still-indexed rows.
        $this->setAdminUser();
        $this->assertSame([], $this->searcher()->search('enrolment', 0, 5));

        // The next update prunes the disabled area's rows.
        $result = $this->indexer()->update();
        $this->assertSame('ok', $result['status']);
        $this->assertSame('disabled_pruned', $result['areas']['mod_page-activity']['status']);

        $resolved = (new embeddings_action_config_resolver())->resolve();
        $store = embeddings_store_factory::instance();
        $this->assertSame(0, $store->count_rows(
            site_content_row_mapper::AREA,
            (string)$resolved['model'],
            (int)$resolved['dimensions']
        ));
        $this->assertSame([], $this->searcher()->search('enrolment', 0, 5));
    }
}
