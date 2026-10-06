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
use bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_state_repository;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository;
use bookingextension_agent\local\wizard\services\sitesearch\task_search_session;

/**
 * Strictly incremental site-content indexing: zero-write no-op runs, per-doc diffs, cursor
 * persistence, disable-pruning, and engine-session hygiene.
 *
 * Uses an injected deterministic embedder so the whole path runs without the LLM provider or any
 * API call; the embeddings provider CLASS still has to exist for the readiness gate, so the suite
 * skips where it is absent.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_state_repository
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\task_search_session
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\null_search_engine
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class site_content_incremental_test extends advanced_testcase {
    /** The one whitelisted area of stage 1. */
    private const AREAKEY = 'mod_page-activity';

    /**
     * A deterministic embedder returning a fixed unit vector; increments the given call counter.
     *
     * @param int $calls Call counter, passed by reference.
     * @return callable
     */
    private function counting_embedder(int &$calls): callable {
        return function (string $text, int $contextid, int $userid, int $dims) use (&$calls): array {
            unset($text, $contextid, $userid);
            $calls++;
            $vector = array_fill(0, max(1, $dims), 0.0);
            $vector[0] = 1.0;
            return $vector;
        };
    }

    /**
     * Enable the DB backend and the mod_page area; skip if the provider class is absent.
     *
     * @return void
     */
    private function enable_site_search(): void {
        if (!class_exists('\\aiprovider_wunderbyte\\aiactions\\generate_embeddings')) {
            $this->markTestSkipped('embeddings provider not available');
        }
        set_config('embeddingsstore', 'db', 'bookingextension_agent');
        (new sitesearch_scope_repository())->set_enabled(self::AREAKEY, true);
    }

    /**
     * The resolved (model, dims) embedding variant.
     *
     * @return array [string $model, int $dims]
     */
    private function variant(): array {
        $resolved = (new embeddings_action_config_resolver())->resolve();
        return [(string)$resolved['model'], (int)$resolved['dimensions']];
    }

    /**
     * All stored site_content rows, keyed by row id.
     *
     * @return array
     */
    private function stored_rows(): array {
        global $DB;
        return $DB->get_records('bx_agent_embeddings', ['area' => 'site_content'], 'id ASC');
    }

    /**
     * The current in-memory core_search manager singleton (protected static) via reflection.
     *
     * @return \core_search\manager|null
     */
    private function manager_singleton(): ?\core_search\manager {
        $property = new \ReflectionProperty(\core_search\manager::class, 'instance');
        return $property->getValue();
    }

    /**
     * A second run with no content change performs ZERO row writes and zero embed calls.
     */
    public function test_second_run_performs_zero_writes(): void {
        $this->resetAfterTest();
        $this->enable_site_search();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_module('page', ['course' => $course->id, 'name' => 'Alpha', 'content' => 'First page body.']);
        $gen->create_module('page', ['course' => $course->id, 'name' => 'Bravo', 'content' => 'Second page body.']);

        $calls = 0;
        $indexer = new site_content_index_service($this->counting_embedder($calls));

        $first = $indexer->update();
        $this->assertSame('ok', $first['status']);
        $this->assertSame(2, $first['processed']);
        $this->assertGreaterThanOrEqual(2, $calls);
        $before = $this->stored_rows();
        $this->assertNotEmpty($before);
        $callsafterfirst = $calls;

        $second = $indexer->update();
        $this->assertSame('ok', $second['status']);
        // No embed calls (all chunk hashes unchanged) ...
        $this->assertSame($callsafterfirst, $calls);
        // ... and no physical writes: the diff left every row untouched.
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(0, $second['deleted']);
        $after = $this->stored_rows();
        $this->assertSame(array_keys($before), array_keys($after));
        foreach ($before as $id => $row) {
            $this->assertSame($row->contenthash, $after[$id]->contenthash);
            $this->assertSame($row->timemodified, $after[$id]->timemodified);
        }
    }

    /**
     * Editing one page re-writes only that document's rows; the cursor advances and persists.
     */
    public function test_edit_changes_only_that_doc_and_advances_cursor(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_site_search();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $pagea = $gen->create_module('page', ['course' => $course->id, 'name' => 'Alpha', 'content' => 'Stable body.']);
        $pageb = $gen->create_module('page', ['course' => $course->id, 'name' => 'Bravo', 'content' => 'Old body.']);

        $calls = 0;
        $indexer = new site_content_index_service($this->counting_embedder($calls));
        $indexer->update();

        [$model, $dims] = $this->variant();
        $state = new site_content_state_repository();
        $cursorafterfirst = $state->get_cursor(self::AREAKEY, $model, $dims);
        $this->assertGreaterThan(0, $cursorafterfirst);

        $rowsa = $DB->get_records('bx_agent_embeddings', ['area' => 'site_content', 'docid' => (int)$pagea->id]);
        $rowsb = $DB->get_records('bx_agent_embeddings', ['area' => 'site_content', 'docid' => (int)$pageb->id]);
        $this->assertNotEmpty($rowsa);
        $this->assertNotEmpty($rowsb);

        // Edit page B: new content, clearly-later timemodified.
        $newmodified = $cursorafterfirst + 100;
        $DB->set_field('page', 'content', 'Completely new body about seminars.', ['id' => $pageb->id]);
        $DB->set_field('page', 'timemodified', $newmodified, ['id' => $pageb->id]);

        $second = $indexer->update();
        $this->assertSame('ok', $second['status']);

        // Page A's rows are physically identical.
        $rowsaafter = $DB->get_records('bx_agent_embeddings', ['area' => 'site_content', 'docid' => (int)$pagea->id]);
        $this->assertSame(array_keys($rowsa), array_keys($rowsaafter));
        foreach ($rowsa as $id => $row) {
            $this->assertSame($row->contenthash, $rowsaafter[$id]->contenthash);
            $this->assertSame($row->timemodified, $rowsaafter[$id]->timemodified);
        }

        // Page B's chunk content changed.
        $rowsbafter = $DB->get_records('bx_agent_embeddings', ['area' => 'site_content', 'docid' => (int)$pageb->id]);
        $this->assertNotEmpty($rowsbafter);
        $hashesbefore = array_values(array_map(static fn(\stdClass $r): string => $r->contenthash, $rowsb));
        $hashesafter = array_values(array_map(static fn(\stdClass $r): string => $r->contenthash, $rowsbafter));
        $this->assertNotSame($hashesbefore, $hashesafter);

        // The cursor advanced to the edited doc's modified time — and is persisted in the DB.
        $this->assertSame($newmodified, $state->get_cursor(self::AREAKEY, $model, $dims));
        $staterow = $DB->get_record(
            'bx_agent_search_state',
            ['areakey' => self::AREAKEY, 'emodel' => $model, 'edims' => $dims],
            '*',
            MUST_EXIST
        );
        $this->assertSame($newmodified, (int)$staterow->indexcursor);
    }

    /**
     * Disabling an area prunes its rows (delete_owner path) and removes its cursor state.
     */
    public function test_disable_prunes_rows_and_cursor_state(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_site_search();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_module('page', ['course' => $course->id, 'name' => 'Page', 'content' => 'Body.']);

        $calls = 0;
        $indexer = new site_content_index_service($this->counting_embedder($calls));
        $indexer->update();
        $this->assertNotEmpty($this->stored_rows());
        $this->assertGreaterThan(0, $DB->count_records('bx_agent_search_state', ['areakey' => self::AREAKEY]));

        (new sitesearch_scope_repository())->set_enabled(self::AREAKEY, false);
        $result = $indexer->update();
        $this->assertSame('ok', $result['status']);
        $this->assertSame('disabled_pruned', $result['areas'][self::AREAKEY]['status']);
        $this->assertSame([], $this->stored_rows());
        $this->assertSame(0, $DB->count_records('bx_agent_search_state', ['areakey' => self::AREAKEY]));
    }

    /**
     * Session hygiene: update() leaves the core_search manager singleton exactly as it found it
     * (null), `manager::instance()` still throws without an engine, and no config is ever written
     * (the enableglobalsearch pitfall of Core's fixture).
     */
    public function test_update_leaves_search_singleton_and_config_untouched(): void {
        $this->resetAfterTest();
        $this->enable_site_search();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_module('page', ['course' => $course->id, 'name' => 'Page', 'content' => 'Body.']);

        // Make sure manager::instance() would throw: no engine configured, no singleton yet.
        set_config('searchengine', '');
        $this->assertNull($this->manager_singleton());
        $globalsearchbefore = get_config('core', 'enableglobalsearch');

        $calls = 0;
        $result = (new site_content_index_service($this->counting_embedder($calls)))->update();
        $this->assertSame('ok', $result['status']);
        $this->assertGreaterThanOrEqual(1, $result['processed']);

        // Singleton restored to null; a real instance() call still fails (no engine configured).
        $this->assertNull($this->manager_singleton());
        // The session never calls set_config — enableglobalsearch is untouched.
        $this->assertSame($globalsearchbefore, get_config('core', 'enableglobalsearch'));
        $this->expectException(\core_search\engine_exception::class);
        \core_search\manager::instance();
    }

    /**
     * An embedder that throws mid-run aborts only that area, leaves the manager singleton clean
     * (finally), closes the recordset, and never advances the cursor past the failed document.
     */
    public function test_throwing_embedder_leaves_clean_state_and_partial_cursor(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_site_search();
        $this->setAdminUser();
        // The per-area error handler mtraces the failure.
        $this->expectOutputRegex('/site_content indexing failed/');

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $pagea = $gen->create_module('page', ['course' => $course->id, 'name' => 'Alpha', 'content' => 'Good body.']);
        $pageb = $gen->create_module('page', ['course' => $course->id, 'name' => 'Bravo', 'content' => 'Poison body.']);
        // Deterministic order: A is clearly older than B.
        $amodified = time() - 200;
        $DB->set_field('page', 'timemodified', $amodified, ['id' => $pagea->id]);

        // Succeeds for page A, throws for page B (its title is part of the embed input).
        $embedder = function (string $text, int $contextid, int $userid, int $dims): array {
            unset($contextid, $userid);
            if (strpos($text, 'Bravo') !== false) {
                throw new \RuntimeException('provider down');
            }
            $vector = array_fill(0, max(1, $dims), 0.0);
            $vector[0] = 1.0;
            return $vector;
        };

        $result = (new site_content_index_service($embedder))->update();
        $this->assertSame('error', $result['areas'][self::AREAKEY]['status']);

        // Page A made it into the store; page B did not.
        $rowsa = $DB->get_records('bx_agent_embeddings', ['area' => 'site_content', 'docid' => (int)$pagea->id]);
        $this->assertNotEmpty($rowsa);
        $rowsb = $DB->get_records('bx_agent_embeddings', ['area' => 'site_content', 'docid' => (int)$pageb->id]);
        $this->assertSame([], $rowsb);

        // The cursor stopped at the last successful doc — NOT past the failed one.
        [$model, $dims] = $this->variant();
        $state = new site_content_state_repository();
        $this->assertSame($amodified, $state->get_cursor(self::AREAKEY, $model, $dims));

        // The engine session was ended in the finally: the singleton is clean.
        $this->assertNull($this->manager_singleton());
    }

    /**
     * The session primitive itself: begin() seeds a working manager singleton (so
     * `document_factory` resolves), end() restores whatever was there before — including a
     * pre-existing instance across nested sessions.
     */
    public function test_task_search_session_restores_previous_singleton(): void {
        $this->resetAfterTest();
        $this->assertNull($this->manager_singleton());

        task_search_session::begin();
        $outer = \core_search\manager::instance();
        $this->assertInstanceOf(task_search_session::class, $outer);

        // Nested session: displaces and then restores the outer one.
        task_search_session::begin();
        $this->assertNotSame($outer, \core_search\manager::instance());
        task_search_session::end();
        $this->assertSame($outer, \core_search\manager::instance());

        task_search_session::end();
        $this->assertNull($this->manager_singleton());

        // An unmatched end() is harmless.
        task_search_session::end();
        $this->assertNull($this->manager_singleton());
    }
}
