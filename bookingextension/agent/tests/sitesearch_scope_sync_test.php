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

declare(strict_types=1);

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_area_registry;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_resolver;
use bookingextension_agent\task\sitesearch_scope_sync_adhoc;

/**
 * Delta sync of the context-scoped governance (K2): the repository's mutation chokepoint queueing
 * the adhoc task with the correct backfill/prune diff, the adhoc task executing backfill + prune
 * end-to-end, the indexer's allowlist/blocklist read strategies, and the search service dropping
 * just-disabled (not yet pruned) hits.
 *
 * The chokepoint tests are pure governance (no provider); the indexing/search tests use the
 * injected deterministic embedder and skip when the provider CLASS is absent (readiness gate).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_resolver
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service
 * @covers     \bookingextension_agent\task\sitesearch_scope_sync_adhoc
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sitesearch_scope_sync_test extends advanced_testcase {
    /** The reference module area used throughout. */
    private const AREAKEY = 'mod_page-activity';

    /**
     * Reset the resolver's request-static caches before every test.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        sitesearch_scope_resolver::reset_request_cache();
    }

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
     * Enable the DB backend; skip if the provider class is absent.
     *
     * @return void
     */
    private function require_backend(): void {
        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            $this->markTestSkipped('embeddings provider not available');
        }
        set_config('embeddingsstore', 'db', 'bookingextension_agent');
    }

    /**
     * All queued scope-sync adhoc tasks, in queue (id) order.
     *
     * @return sitesearch_scope_sync_adhoc[]
     */
    private function queued_tasks(): array {
        $tasks = array_values(\core\task\manager::get_adhoc_tasks(sitesearch_scope_sync_adhoc::class));
        usort($tasks, static fn(\core\task\adhoc_task $a, \core\task\adhoc_task $b): int => $a->get_id() <=> $b->get_id());
        return $tasks;
    }

    /**
     * The customdata of the most recently queued scope-sync task, normalized.
     *
     * @return array ['area' => string, 'backfill' => int[], 'prune' => int[]]
     */
    private function latest_customdata(): array {
        $tasks = $this->queued_tasks();
        $this->assertNotEmpty($tasks, 'a scope-sync adhoc task should have been queued');
        $data = (array)end($tasks)->get_custom_data();
        return [
            'area' => (string)($data['area'] ?? ''),
            'backfill' => array_map('intval', (array)($data['backfill'] ?? [])),
            'prune' => array_map('intval', (array)($data['prune'] ?? [])),
        ];
    }

    /**
     * A scope-sync task wired to an index service with the deterministic embedder (the production
     * task builds its service itself; this overrides the seam so no provider call is ever made).
     *
     * @param array $customdata {area, backfill[], prune[]}.
     * @return sitesearch_scope_sync_adhoc
     */
    private function sync_task(array $customdata): sitesearch_scope_sync_adhoc {
        $service = new site_content_index_service($this->fake_embedder());
        $task = new class ($service) extends sitesearch_scope_sync_adhoc {
            /** @var site_content_index_service Injected fake-embedder service. */
            private site_content_index_service $service;

            /**
             * Constructor.
             *
             * @param site_content_index_service $service
             */
            public function __construct(site_content_index_service $service) {
                $this->service = $service;
            }

            /**
             * Return the injected service instead of building one.
             *
             * @return site_content_index_service
             */
            protected function create_index_service(): site_content_index_service {
                return $this->service;
            }
        };
        $task->set_custom_data($customdata);
        return $task;
    }

    /**
     * Execute a task while swallowing its mtrace output.
     *
     * @param sitesearch_scope_sync_adhoc $task
     * @return void
     */
    private function execute_silently(sitesearch_scope_sync_adhoc $task): void {
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Stored site_content row count for one course.
     *
     * @param int $courseid
     * @return int
     */
    private function course_rows(int $courseid): int {
        global $DB;
        return $DB->count_records('bx_agent_embeddings', ['area' => 'site_content', 'courseid' => $courseid]);
    }

    /**
     * The delta chokepoint queues the adhoc task with the correct diff for every mutation kind:
     * category enable → backfill its (path-covered) courses; course disable → prune; includefiles
     * flip → backfill the affected courses; rule deletion → re-derived coverage; and a no-change
     * write queues nothing.
     */
    public function test_chokepoint_queues_correct_diffs(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $cata = $gen->create_category();
        $catb = $gen->create_category(['parent' => $cata->id]);
        $c1 = $gen->create_course(['category' => $cata->id]);
        $c2 = $gen->create_course(['category' => $catb->id]);
        $repo = new sitesearch_scope_repository();

        // Enable category A: both its courses (path inheritance) are backfilled.
        $repo->set_enabled(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_CATEGORY, (int)$cata->id);
        $data = $this->latest_customdata();
        $this->assertSame(self::AREAKEY, $data['area']);
        $this->assertEqualsCanonicalizing([(int)$c1->id, (int)$c2->id], $data['backfill']);
        $this->assertSame([], $data['prune']);

        // Disable course c2: it leaves the allowed set → prune, no backfill.
        $repo->set_enabled(self::AREAKEY, false, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c2->id);
        $data = $this->latest_customdata();
        $this->assertSame([], $data['backfill']);
        $this->assertSame([(int)$c2->id], $data['prune']);

        // Includefiles flip on the category rule: the still-allowed course is backfilled (its
        // documents' chunk sets must be recomputed), nothing is pruned.
        $repo->set_includefiles(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_CATEGORY, (int)$cata->id);
        $data = $this->latest_customdata();
        $this->assertSame([(int)$c1->id], $data['backfill']);
        $this->assertSame([], $data['prune']);

        // A no-change write must not queue anything.
        $count = count($this->queued_tasks());
        $repo->set_enabled(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_CATEGORY, (int)$cata->id);
        $this->assertCount($count, $this->queued_tasks());

        // Deleting the blocking course row re-covers c2 through category A (with files).
        $repo->delete_rule(self::AREAKEY, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c2->id);
        $data = $this->latest_customdata();
        $this->assertSame([(int)$c2->id], $data['backfill']);
        $this->assertSame([], $data['prune']);
    }

    /**
     * Wildcard mutations (§3.0) fan the delta chokepoint out to EVERY covered area: one adhoc
     * per area whose coverage actually changed (existing customdata shape, never an '*' task),
     * nothing for areas whose coverage is unchanged, and the wildcard rule deletion prunes the
     * same set again.
     */
    public function test_chokepoint_wildcard_mutation_queues_per_area_deltas(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $courseid = (int)$course->id;
        $repo = new sitesearch_scope_repository();
        $covered = (new site_content_area_registry())->wildcard_covered_area_keys();
        $wildcard = site_content_area_registry::WILDCARD;

        // Pre-existing concrete rule: the page area already covers the course.
        $repo->set_enabled(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_COURSE, $courseid);
        $countbefore = count($this->queued_tasks());

        // Wildcard course rule ON: per-area adhocs for every covered area EXCEPT page (its
        // effective pair for the course is unchanged — the concrete row already wins there).
        $repo->set_enabled($wildcard, true, sitesearch_scope_repository::SCOPETYPE_COURSE, $courseid);
        $byarea = $this->customdata_by_area(array_slice($this->queued_tasks(), $countbefore));
        $this->assertArrayNotHasKey($wildcard, $byarea);
        $this->assertEqualsCanonicalizing(array_diff($covered, [self::AREAKEY]), array_keys($byarea));
        foreach ($byarea as $data) {
            $this->assertSame([$courseid], $data['backfill']);
            $this->assertSame([], $data['prune']);
        }

        // Deleting the wildcard rule prunes exactly the same per-area coverage again (page keeps
        // its concrete rule → again no task for it).
        $countbefore = count($this->queued_tasks());
        $repo->delete_rule($wildcard, sitesearch_scope_repository::SCOPETYPE_COURSE, $courseid);
        $byarea = $this->customdata_by_area(array_slice($this->queued_tasks(), $countbefore));
        $this->assertEqualsCanonicalizing(array_diff($covered, [self::AREAKEY]), array_keys($byarea));
        foreach ($byarea as $data) {
            $this->assertSame([], $data['backfill']);
            $this->assertSame([$courseid], $data['prune']);
        }
    }

    /**
     * Normalized customdata of a task list, keyed by area.
     *
     * @param array $tasks sitesearch_scope_sync_adhoc[]
     * @return array area => ['backfill' => int[], 'prune' => int[]]
     */
    private function customdata_by_area(array $tasks): array {
        $byarea = [];
        foreach ($tasks as $task) {
            $data = (array)$task->get_custom_data();
            $byarea[(string)($data['area'] ?? '')] = [
                'backfill' => array_map('intval', (array)($data['backfill'] ?? [])),
                'prune' => array_map('intval', (array)($data['prune'] ?? [])),
            ];
        }
        return $byarea;
    }

    /**
     * The adhoc task executes backfill and prune end-to-end: enabling one course rule queues a
     * backfill that indexes exactly that course; disabling it queues a prune that removes exactly
     * its rows (delete_owner_in_course).
     */
    public function test_adhoc_task_backfills_and_prunes(): void {
        $this->resetAfterTest();
        $this->require_backend();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $c1 = $gen->create_course();
        $c2 = $gen->create_course();
        $gen->create_module('page', ['course' => $c1->id, 'name' => 'One', 'content' => 'Enrolment guide.']);
        $gen->create_module('page', ['course' => $c2->id, 'name' => 'Two', 'content' => 'Other content.']);
        $repo = new sitesearch_scope_repository();

        // Enable course c1 only (allowlist): the chokepoint queues the backfill for c1.
        $repo->set_enabled(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c1->id);
        $data = $this->latest_customdata();
        $this->assertSame([(int)$c1->id], $data['backfill']);
        $this->execute_silently($this->sync_task($data));
        $this->assertGreaterThanOrEqual(1, $this->course_rows((int)$c1->id));
        $this->assertSame(0, $this->course_rows((int)$c2->id));

        // Disable it again: the queued prune removes exactly c1's rows.
        $repo->set_enabled(self::AREAKEY, false, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c1->id);
        $data = $this->latest_customdata();
        $this->assertSame([(int)$c1->id], $data['prune']);
        $this->assertSame([], $data['backfill']);
        $this->execute_silently($this->sync_task($data));
        $this->assertSame(0, $this->course_rows((int)$c1->id));
    }

    /**
     * Allowlist strategy: the scheduled incremental pass reads ONLY the allowed courses
     * (context-scoped recordsets) — documents of other courses are never touched.
     */
    public function test_allowlist_indexing_reads_only_allowed_courses(): void {
        $this->resetAfterTest();
        $this->require_backend();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $c1 = $gen->create_course();
        $c2 = $gen->create_course();
        $gen->create_module('page', ['course' => $c1->id, 'name' => 'One', 'content' => 'Allowed body.']);
        $gen->create_module('page', ['course' => $c2->id, 'name' => 'Two', 'content' => 'Forbidden body.']);
        (new sitesearch_scope_repository())
            ->set_enabled(self::AREAKEY, true, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c1->id);

        $result = (new site_content_index_service($this->fake_embedder()))->update();
        $this->assertSame('ok', $result['status']);
        $areastats = $result['areas'][self::AREAKEY];
        $this->assertSame('ok', $areastats['status']);
        // Exactly the allowed course's document was read — nothing was even skipped, because the
        // context-scoped recordset never yields the other course.
        $this->assertSame(1, $areastats['processed']);
        $this->assertSame(0, $areastats['skipped']);
        $this->assertGreaterThanOrEqual(1, $this->course_rows((int)$c1->id));
        $this->assertSame(0, $this->course_rows((int)$c2->id));
    }

    /**
     * Blocklist strategy: with the site row on and one course excluded, the global pass indexes
     * the allowed course and SKIPS the excluded one before any chunking/embedding.
     */
    public function test_blocklist_indexing_skips_excluded_courses(): void {
        $this->resetAfterTest();
        $this->require_backend();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $c1 = $gen->create_course();
        $c2 = $gen->create_course();
        $gen->create_module('page', ['course' => $c1->id, 'name' => 'One', 'content' => 'Allowed body.']);
        $gen->create_module('page', ['course' => $c2->id, 'name' => 'Two', 'content' => 'Excluded body.']);
        $repo = new sitesearch_scope_repository();
        $repo->set_enabled(self::AREAKEY, true);
        $repo->set_enabled(self::AREAKEY, false, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$c2->id);

        $embeds = 0;
        $counting = function (string $text, int $contextid, int $userid, int $dims) use (&$embeds): array {
            unset($text, $contextid, $userid);
            $embeds++;
            $vector = array_fill(0, max(1, $dims), 0.0);
            $vector[0] = 1.0;
            return $vector;
        };
        $result = (new site_content_index_service($counting))->update();
        $this->assertSame('ok', $result['status']);
        $areastats = $result['areas'][self::AREAKEY];
        $this->assertSame(1, $areastats['processed']);
        $this->assertSame(1, $areastats['skipped']);
        $this->assertGreaterThanOrEqual(1, $this->course_rows((int)$c1->id));
        $this->assertSame(0, $this->course_rows((int)$c2->id));
        // The skip happened BEFORE embedding: only the allowed document's chunk was embedded.
        $this->assertSame(1, $embeds);
    }

    /**
     * Transition hardening (§4.3): a course whose rule was just disabled — rows still in the
     * index, prune not yet executed — vanishes from search results immediately.
     */
    public function test_search_drops_just_disabled_hits(): void {
        $this->resetAfterTest();
        $this->require_backend();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_module('page', [
            'course' => $course->id, 'name' => 'Alpha', 'content' => 'Special enrolment instructions.',
        ]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);
        $repo = new sitesearch_scope_repository();
        $repo->set_enabled(self::AREAKEY, true);

        $this->setAdminUser();
        $result = (new site_content_index_service($this->fake_embedder()))->update();
        $this->assertSame('ok', $result['status']);

        $this->setUser($user);
        $searcher = new site_content_search_service($this->fake_embedder());
        $this->assertNotEmpty($searcher->search('enrolment', 0, 5));

        // Disable the course by rule; its rows are still stored (the prune adhoc has not run).
        $repo->set_enabled(self::AREAKEY, false, sitesearch_scope_repository::SCOPETYPE_COURSE, (int)$course->id);
        $this->assertGreaterThanOrEqual(1, $this->course_rows((int)$course->id));
        $this->assertSame([], $searcher->search('enrolment', 0, 5));
    }

    /**
     * The adhoc task self-guards: on a not-ready site (CSV backend) it degrades to a traced
     * no-op — no store writes, no exception.
     */
    public function test_adhoc_task_noop_when_not_ready(): void {
        $this->resetAfterTest();
        set_config('embeddingsstore', 'csv', 'bookingextension_agent');

        $task = $this->sync_task(['area' => self::AREAKEY, 'backfill' => [1], 'prune' => [2]]);
        $this->execute_silently($task);
        // Nothing indexed, nothing thrown.
        $this->assertSame(0, $this->course_rows(1));
    }
}
