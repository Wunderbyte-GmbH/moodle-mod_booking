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
use bookingextension_agent\local\wizard\services\sitesearch\index_scope_estimator;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_area_registry;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_readiness_service;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository;

/**
 * Site-search governance foundation: scope persistence (+ lazy legacy-config migration), the
 * registry reading enablement from the scope table, the chunk-count estimator with its traffic
 * light, and the hard feature-readiness gate of the governance page.
 *
 * The estimator tests run WITHOUT the embeddings provider (estimating never embeds); only the
 * readiness-gate test branches on the provider class.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_area_registry
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\index_scope_estimator
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\sitesearch_readiness_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sitesearch_governance_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        // The resolver memoizes per request; in PHPUnit one request spans every test (#2339).
        \bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_resolver::reset_request_cache();
    }

    /** The reference module area used throughout. */
    private const AREAKEY = 'mod_page-activity';

    /**
     * CRUD over the scope table: default off, upsert (never duplicate rows), audit columns, and
     * the staged course/category scope rows being independent of the v1 site-wide toggle.
     */
    public function test_scope_repository_crud(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $repository = new sitesearch_scope_repository();

        // Default: everything off, no rows.
        $this->assertFalse($repository->is_enabled(self::AREAKEY));
        $this->assertSame([], $repository->enabled_site_areas());

        $repository->set_enabled(self::AREAKEY, true);
        $this->assertTrue($repository->is_enabled(self::AREAKEY));
        $this->assertSame([self::AREAKEY], $repository->enabled_site_areas());

        $rows = $DB->get_records('bx_agent_search_scope');
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame(self::AREAKEY, $row->area);
        $this->assertSame(sitesearch_scope_repository::SCOPETYPE_SITE, $row->scopetype);
        $this->assertSame(0, (int)$row->scopeid);
        $this->assertSame(1, (int)$row->enabled);
        $this->assertSame((int)$USER->id, (int)$row->usermodified);
        $this->assertGreaterThan(0, (int)$row->timemodified);

        // Toggling off UPDATES the same row instead of inserting a second one.
        $repository->set_enabled(self::AREAKEY, false);
        $this->assertFalse($repository->is_enabled(self::AREAKEY));
        $this->assertSame([], $repository->enabled_site_areas());
        $this->assertCount(1, $DB->get_records('bx_agent_search_scope'));

        // Staged data model (§5b.3): course/category rows are accepted and independent of the
        // site-wide toggle — the v1 UI/indexer simply do not consume them yet.
        $repository->set_enabled(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_COURSE, 42);
        $repository->set_enabled(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_CATEGORY, 7);
        $this->assertTrue($repository->is_enabled(self::AREAKEY, sitesearch_scope_repository::SCOPETYPE_COURSE, 42));
        $this->assertTrue($repository->is_enabled(self::AREAKEY, sitesearch_scope_repository::SCOPETYPE_CATEGORY, 7));
        $this->assertSame([], $repository->enabled_site_areas());
        $this->assertCount(3, $repository->get_scopes(self::AREAKEY));

        $repository->delete_scope(self::AREAKEY, sitesearch_scope_repository::SCOPETYPE_COURSE, 42);
        $this->assertFalse($repository->is_enabled(self::AREAKEY, sitesearch_scope_repository::SCOPETYPE_COURSE, 42));
        $this->assertCount(2, $repository->get_scopes(self::AREAKEY));
    }

    /**
     * Invalid scope tuples are rejected before they hit the table.
     */
    public function test_scope_repository_rejects_invalid_scopes(): void {
        $this->resetAfterTest();
        $repository = new sitesearch_scope_repository();

        $invalid = [
            ['bogus', 0],
            [sitesearch_scope_repository::SCOPETYPE_SITE, 5],
            [sitesearch_scope_repository::SCOPETYPE_COURSE, 0],
            [sitesearch_scope_repository::SCOPETYPE_CATEGORY, -1],
        ];
        foreach ($invalid as [$scopetype, $scopeid]) {
            try {
                $repository->set_enabled(self::AREAKEY, true, $scopetype, $scopeid);
                $this->fail('coding_exception expected for scope ' . $scopetype . '/' . $scopeid);
            } catch (\coding_exception $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    /**
     * Lazy legacy migration: a non-empty `sitesearchareas` config seeds site-scope rows once
     * (table empty) and is unset afterwards; the registry then serves the migrated enablement.
     */
    public function test_legacy_config_seeds_scope_table_once(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('sitesearchareas', self::AREAKEY . ', mod_forum-post', 'bookingextension_agent');

        $repository = new sitesearch_scope_repository();
        // First read triggers the seed ('area ASC': forum sorts before page).
        $this->assertSame(['mod_forum-post', self::AREAKEY], $repository->enabled_site_areas());
        $this->assertCount(2, $DB->get_records('bx_agent_search_scope'));
        // The legacy config is gone.
        $this->assertFalse(get_config('bookingextension_agent', 'sitesearchareas'));

        // Idempotent: a second read does not duplicate anything.
        $this->assertSame(['mod_forum-post', self::AREAKEY], $repository->enabled_site_areas());
        $this->assertCount(2, $DB->get_records('bx_agent_search_scope'));

        // Both migrated keys are real enumerated areas, so both surface through the registry.
        $this->assertSame(['mod_forum-post', self::AREAKEY], (new site_content_area_registry())->enabled_area_keys());
    }

    /**
     * A stale legacy config must never overwrite an existing scope table (new model already in
     * use); it is only cleaned up.
     */
    public function test_legacy_config_does_not_overwrite_existing_rows(): void {
        $this->resetAfterTest();

        $repository = new sitesearch_scope_repository();
        // The admin explicitly disabled the area through the new governance model.
        $repository->set_enabled(self::AREAKEY, false);

        set_config('sitesearchareas', self::AREAKEY, 'bookingextension_agent');
        $this->assertSame([], $repository->enabled_site_areas());
        $this->assertFalse($repository->is_enabled(self::AREAKEY));
        // The stale config was removed without seeding.
        $this->assertFalse(get_config('bookingextension_agent', 'sitesearchareas'));
    }

    /**
     * The registry's enablement flows from the scope table (site rows ∩ enumerated areas), not
     * from any plugin config.
     */
    public function test_registry_reads_scope_table(): void {
        $this->resetAfterTest();

        $registry = new site_content_area_registry();
        $repository = new sitesearch_scope_repository();

        $this->assertSame([], $registry->enabled_area_keys());
        $this->assertSame([], $registry->enabled_modnames());

        $repository->set_enabled(self::AREAKEY, true);
        $this->assertSame([self::AREAKEY], $registry->enabled_area_keys());
        $this->assertSame(['page'], $registry->enabled_modnames());

        $repository->set_enabled(self::AREAKEY, false);
        $this->assertSame([], $registry->enabled_area_keys());

        // Any enumerated area is enableable (dynamic enumeration, §11.27).
        $repository->set_enabled('mod_forum-post', true);
        $this->assertSame(['mod_forum-post'], $registry->enabled_area_keys());
        $this->assertSame(['forum'], $registry->enabled_modnames());

        // A key that is no installed search area stays inert.
        $repository->set_enabled('mod_nonexistent-bogus', true);
        $this->assertSame(['mod_forum-post'], $registry->enabled_area_keys());
    }

    /**
     * Dynamic enumeration (§11.27): the registry lists every installed core_search area with the
     * correct contextsupport classification, without any hardcoded whitelist.
     */
    public function test_registry_enumerates_areas_dynamically(): void {
        $areas = site_content_area_registry::all_areas();

        // Module areas: classified 'module', carrying their module name.
        $this->assertArrayHasKey('mod_page-activity', $areas);
        $this->assertSame('module', $areas['mod_page-activity']['contextsupport']);
        $this->assertSame('page', $areas['mod_page-activity']['modname']);
        $this->assertArrayHasKey('mod_forum-post', $areas);
        $this->assertSame('module', $areas['mod_forum-post']['contextsupport']);
        $this->assertSame('forum', $areas['mod_forum-post']['modname']);

        // Course-context areas: classified 'course', no module name.
        $this->assertArrayHasKey('core_course-course', $areas);
        $this->assertSame('course', $areas['core_course-course']['contextsupport']);
        $this->assertNull($areas['core_course-course']['modname']);
        $this->assertArrayHasKey('core_course-section', $areas);
        $this->assertSame('course', $areas['core_course-section']['contextsupport']);

        // Any other context level: 'other' (fail-closed in the prefilter, but enumerated).
        $this->assertArrayHasKey('core_user-user', $areas);
        $this->assertSame('other', $areas['core_user-user']['contextsupport']);

        // Every descriptor is complete and consistent.
        foreach ($areas as $areakey => $descriptor) {
            $this->assertSame($areakey, $descriptor['areaid']);
            $this->assertInstanceOf(\core_search\base::class, $descriptor['instance']);
            $this->assertContains($descriptor['contextsupport'], ['module', 'course', 'other']);
        }

        // The instance accessor stays engine-free and returns fresh instances of the enumerated class.
        $registry = new site_content_area_registry();
        $instance = $registry->area_instance('mod_page-activity');
        $this->assertInstanceOf(\mod_page\search\activity::class, $instance);
        $this->assertNotSame($areas['mod_page-activity']['instance'], $instance);
        $this->assertNull($registry->area_instance('mod_nonexistent-bogus'));
    }

    /**
     * The access descriptor for the context lister: enabled module names + whether any enabled
     * area needs course-level contexts ('other' areas contribute neither).
     */
    public function test_registry_access_descriptor(): void {
        $this->resetAfterTest();

        $registry = new site_content_area_registry();
        $repository = new sitesearch_scope_repository();

        $this->assertSame(['modnames' => [], 'includecourselevel' => false], $registry->enabled_access_descriptor());

        $repository->set_enabled(self::AREAKEY, true);
        $this->assertSame(
            ['modnames' => ['page'], 'includecourselevel' => false],
            $registry->enabled_access_descriptor()
        );

        $repository->set_enabled('core_course-course', true);
        $this->assertSame(
            ['modnames' => ['page'], 'includecourselevel' => true],
            $registry->enabled_access_descriptor()
        );

        // An 'other'-support area alone flips neither switch (fail-closed prefilter).
        $repository->set_enabled(self::AREAKEY, false);
        $repository->set_enabled('core_course-course', false);
        $repository->set_enabled('core_user-user', true);
        $this->assertSame(['modnames' => [], 'includecourselevel' => false], $registry->enabled_access_descriptor());
        $this->assertNull($registry->modname_for('core_user-user'));
        $this->assertSame('other', $registry->contextsupport_for('core_user-user'));
    }

    /**
     * Estimator basics: exact recordset doc count, >= 1 chunk per short document, green light on
     * default thresholds, null for unknown areas.
     */
    public function test_estimator_counts_documents(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        foreach (['One', 'Two', 'Three'] as $name) {
            $generator->create_module('page', ['course' => $course->id, 'name' => $name, 'content' => 'Short body.']);
        }

        $estimator = new index_scope_estimator();
        $estimate = $estimator->estimate(self::AREAKEY);
        $this->assertNotNull($estimate);
        $this->assertSame(3, $estimate['doccount']);
        $this->assertFalse($estimate['capped']);
        $this->assertSame(3, $estimate['estchunks']);
        $this->assertSame('green', $estimate['ampel']);

        $this->assertNull($estimator->estimate('mod_unknown-nowhere'));
    }

    /**
     * Long content raises the sampled Ø-chunks: estchunks grows beyond the document count.
     */
    public function test_estimator_long_content_raises_chunks(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        // Roughly 6500 characters of content → ceil(6500 / 2000) chunks for the single document.
        $generator->create_module('page', [
            'course' => $course->id,
            'name' => 'Long',
            'content' => str_repeat('lorem ipsum dolor sit amet ', 240),
        ]);

        $estimate = (new index_scope_estimator())->estimate(self::AREAKEY);
        $this->assertNotNull($estimate);
        $this->assertSame(1, $estimate['doccount']);
        $this->assertGreaterThanOrEqual(3, $estimate['estchunks']);
        $this->assertGreaterThan($estimate['doccount'], $estimate['estchunks']);
    }

    /**
     * Counting aborts at the red threshold: doccount is capped at N (display ">N") and the light
     * is red regardless of the average.
     */
    public function test_estimator_aborts_counting_at_red_threshold(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('sitesearchampelred', '2', 'bookingextension_agent');

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        for ($i = 1; $i <= 5; $i++) {
            $generator->create_module('page', ['course' => $course->id, 'name' => 'P' . $i, 'content' => 'Body.']);
        }

        $estimate = (new index_scope_estimator())->estimate(self::AREAKEY);
        $this->assertNotNull($estimate);
        // Aborted at the red threshold: reported as ">2", never 5.
        $this->assertSame(2, $estimate['doccount']);
        $this->assertTrue($estimate['capped']);
        $this->assertSame('red', $estimate['ampel']);
    }

    /**
     * The traffic light follows the two admin thresholds; a changed green threshold is applied
     * instantly (the light is derived fresh from the cached counts).
     */
    public function test_estimator_ampel_thresholds(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        foreach (['One', 'Two', 'Three'] as $name) {
            $generator->create_module('page', ['course' => $course->id, 'name' => $name, 'content' => 'Short body.']);
        }

        // With estchunks = 3 and green threshold 2 → not green, below red → yellow.
        set_config('sitesearchampelgreen', '2', 'bookingextension_agent');
        $estimate = (new index_scope_estimator())->estimate(self::AREAKEY);
        $this->assertNotNull($estimate);
        $this->assertSame(3, $estimate['estchunks']);
        $this->assertSame('yellow', $estimate['ampel']);

        // Raising the green threshold flips the same (cached) counts to green immediately.
        set_config('sitesearchampelgreen', '100', 'bookingextension_agent');
        $estimate = (new index_scope_estimator())->estimate(self::AREAKEY);
        $this->assertNotNull($estimate);
        $this->assertSame('green', $estimate['ampel']);
    }

    /**
     * The governance page's hard gate (§16): provider class, Moodle 5+, DB backend — in that order.
     */
    public function test_readiness_gate(): void {
        global $CFG;
        $this->resetAfterTest();

        $service = new sitesearch_readiness_service();

        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            $result = $service->is_ready();
            $this->assertFalse($result['ready']);
            $this->assertSame('embeddings_provider_unavailable', $result['reason']);
            return;
        }

        // Provider present, but the store is not the DB backend (default) → gated.
        unset_config('embeddingsstore', 'bookingextension_agent');
        $result = $service->is_ready();
        $this->assertFalse($result['ready']);
        $this->assertSame('requires_db_backend', $result['reason']);

        set_config('embeddingsstore', 'db', 'bookingextension_agent');
        $result = $service->is_ready();
        $this->assertTrue($result['ready']);
        $this->assertSame('', $result['reason']);

        // Moodle < 5 wins over the backend check ($CFG is restored by resetAfterTest).
        $CFG->branch = '404';
        $result = $service->is_ready();
        $this->assertFalse($result['ready']);
        $this->assertSame('requires_moodle_5', $result['reason']);
    }
}
