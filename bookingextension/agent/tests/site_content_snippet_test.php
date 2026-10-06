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
use bookingextension_agent\local\wizard\services\sitesearch\site_content_area_registry;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_chunk_pipeline;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_chunker;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service;

/**
 * Site-content snippets: the overlap chunker, query-time re-extraction, self-heal + fail-soft
 * behaviour and the chunker-version fingerprint.
 *
 * Uses injected deterministic embedders so the whole path runs without the LLM provider; the
 * embeddings provider CLASS still has to exist for the readiness gates, so the suite skips where
 * it is absent.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_chunker
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class site_content_snippet_test extends advanced_testcase {
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
     * A deterministic embedder that separates texts containing the marker word 'zebraword'
     * (unit vector A) from all others (unit vector B, cosine 0.6 against A) — so a query with
     * the marker ranks the marker chunk strictly first.
     *
     * @return callable
     */
    private function marker_embedder(): callable {
        return function (string $text, int $contextid, int $userid, int $dims): array {
            unset($contextid, $userid);
            $vector = array_fill(0, max(2, $dims), 0.0);
            if (str_contains($text, 'zebraword')) {
                $vector[0] = 1.0;
            } else {
                $vector[0] = 0.6;
                $vector[1] = 0.8;
            }
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
        (new \bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository())
            ->set_enabled('mod_page-activity', true);
    }

    /**
     * Assert the engine session left no seeded manager singleton behind.
     *
     * The phpunit reset clears the singleton between tests, so after a bracketed
     * begin()/end() it must be null again.
     *
     * @return void
     */
    private function assert_search_session_closed(): void {
        $prop = new \ReflectionProperty(\core_search\manager::class, 'instance');
        $prop->setAccessible(true);
        $this->assertNull($prop->getValue(), 'engine-session manager singleton must be cleaned up');
    }

    /**
     * A long deterministic plain text with line breaks, sentences and multibyte characters.
     *
     * @return string
     */
    private function long_text(): string {
        $text = '';
        for ($i = 0; $i < 300; $i++) {
            $text .= "Sentence number {$i} talks about the glossary of alpine plants and their bloom. ";
            if ($i % 9 === 4) {
                $text .= "Käse für die Prüfung — größere Übersicht. ";
            }
            if ($i % 7 === 6) {
                $text .= "\n";
            }
        }
        return $text;
    }

    /**
     * Same input must produce byte-identical chunks; the budget is respected and consecutive
     * chunks share an overlap region.
     */
    public function test_chunker_is_deterministic_overlapping_and_bounded(): void {
        $text = $this->long_text();

        $first = site_content_chunker::chunk($text);
        $second = site_content_chunker::chunk($text);
        $this->assertSame($first, $second, 'chunking must be byte-identical across runs');
        $this->assertGreaterThan(1, count($first));

        foreach ($first as $chunk) {
            $this->assertLessThanOrEqual(site_content_chunker::TARGET_CHARS, strlen($chunk['text']));
            $this->assertNotSame('', trim($chunk['text']));
        }

        // Overlap: every chunk starts inside the tail of its predecessor (the ~200-char overlap
        // region), so the prefix of chunk i+1 must literally occur in chunk i.
        for ($i = 0; $i < count($first) - 1; $i++) {
            $prefix = substr($first[$i + 1]['text'], 0, 80);
            $this->assertStringContainsString(
                $prefix,
                $first[$i]['text'],
                "chunk {$i} must overlap into chunk " . ($i + 1)
            );
        }
    }

    /**
     * Short content yields exactly one chunk equal to the input; blank input yields none.
     */
    public function test_chunker_short_content_single_chunk(): void {
        $this->assertSame([['text' => 'Short text.']], site_content_chunker::chunk('Short text.'));
        $this->assertSame([], site_content_chunker::chunk("   \n  "));
    }

    /**
     * Roundtrip: an indexed page's hit carries the re-extracted snippet and the content hash
     * proves it is exactly the embedded chunk (stale === false).
     */
    public function test_snippet_roundtrip_not_stale(): void {
        $this->resetAfterTest();
        $this->enable_site_search();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', [
            'course' => $course->id,
            'name' => 'Alpha Page',
            'content' => 'Special enrolment instructions for the seminar.',
        ]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $this->setAdminUser();
        $result = (new site_content_index_service($this->fake_embedder()))->update();
        $this->assertSame('ok', $result['status']);

        $this->setUser($user);
        $hits = (new site_content_search_service($this->fake_embedder()))->search('enrolment', 0, 5);
        $match = array_values(array_filter($hits, static fn(array $r): bool => (int)$r['docid'] === (int)$page->id));
        $this->assertNotEmpty($match);
        $this->assertNotSame('', $match[0]['snippet']);
        $this->assertStringContainsString('enrolment instructions', $match[0]['snippet']);
        $this->assertFalse($match[0]['stale']);
        $this->assertStringContainsString('/mod/page/view.php', $match[0]['url']);
        $this->assert_search_session_closed();
    }

    /**
     * Self-heal: content changed in the DB after indexing → the snippet shows the CURRENT text
     * (best effort) and the result is flagged stale. No persistence happens — the changed source
     * has a newer timemodified, so the incremental cursor re-indexes it on the next run.
     */
    public function test_changed_content_marks_result_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_site_search();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $page = $gen->create_module('page', [
            'course' => $course->id,
            'name' => 'Beta Page',
            'content' => 'Original enrolment guidance for participants.',
        ]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $this->setAdminUser();
        (new site_content_index_service($this->fake_embedder()))->update();

        // Mutate the source behind the index's back.
        $DB->set_field('page', 'content', '<p>Completely different fresh content now.</p>', ['id' => $page->id]);

        $this->setUser($user);
        $hits = (new site_content_search_service($this->fake_embedder()))->search('enrolment', 0, 5);
        $match = array_values(array_filter($hits, static fn(array $r): bool => (int)$r['docid'] === (int)$page->id));
        $this->assertNotEmpty($match);
        $this->assertStringContainsString('different fresh content', $match[0]['snippet']);
        $this->assertTrue($match[0]['stale']);
        $this->assert_search_session_closed();
    }

    /**
     * Fail-soft: the hit points at chunk 1, but the content shrank to a single chunk → the
     * snippet falls back to chunk 0 (flagged stale), and no exception escapes search().
     */
    public function test_vanished_refindex_falls_back_to_first_chunk(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_site_search();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $filler = '';
        for ($i = 0; $i < 60; $i++) {
            $filler .= "Paragraph {$i} describes ordinary course logistics and room allocations in detail. ";
        }
        $page = $gen->create_module('page', [
            'course' => $course->id,
            'name' => 'Long Page',
            'content' => '<p>' . $filler . 'Finally the zebraword appears at the very end.</p>',
        ]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $this->setAdminUser();
        $result = (new site_content_index_service($this->marker_embedder()))->update();
        $this->assertSame('ok', $result['status']);
        $this->assertGreaterThanOrEqual(2, $result['embedded'], 'the page must produce at least two chunks');

        // Shrink the content: the marker chunk number no longer exists at re-extraction time.
        $DB->set_field('page', 'content', '<p>Tiny now.</p>', ['id' => $page->id]);

        $this->setUser($user);
        $hits = (new site_content_search_service($this->marker_embedder()))->search('zebraword', 0, 1);
        $this->assertCount(1, $hits);
        $this->assertSame((int)$page->id, $hits[0]['docid']);
        // The top hit was the marker chunk (refindex >= 1); its snippet now comes from chunk 0.
        $this->assertStringContainsString('Tiny now', $hits[0]['snippet']);
        $this->assertTrue($hits[0]['stale']);
        $this->assert_search_session_closed();
    }

    /**
     * Fail-soft: an area that throws during the snippet phase costs only the snippet — the hit is
     * still returned — and the engine session is cleaned up regardless.
     */
    public function test_area_throw_in_snippet_phase_keeps_hit_and_cleans_session(): void {
        $this->resetAfterTest();
        $this->enable_site_search();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $gen->create_module('page', [
            'course' => $course->id,
            'name' => 'Gamma Page',
            'content' => 'Enrolment guidance for the excursion.',
        ]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $this->setAdminUser();
        (new site_content_index_service($this->fake_embedder()))->update();

        // A registry serving an area that explodes exactly on the snippet phase's context-scoped
        // recordset call (check_access and the cursorless index path are untouched).
        $registry = new class extends site_content_area_registry {
            /**
             * Wrap the real area with a snippet-phase bomb.
             *
             * @param string $areakey
             * @return \core_search\base|null
             */
            public function area_instance(string $areakey): ?\core_search\base {
                if (parent::area_instance($areakey) === null) {
                    return null;
                }
                return new class extends \mod_page\search\activity {
                    /**
                     * Throw only for the context-scoped call the snippet phase makes.
                     *
                     * @param int $modifiedfrom
                     * @param \context|null $context
                     * @return \moodle_recordset|false
                     */
                    public function get_document_recordset($modifiedfrom = 0, ?\context $context = null) {
                        if ($context !== null) {
                            throw new \RuntimeException('snippet-phase failure');
                        }
                        return parent::get_document_recordset($modifiedfrom, $context);
                    }
                };
            }
        };

        $searcher = new site_content_search_service($this->fake_embedder());
        $prop = new \ReflectionProperty(site_content_search_service::class, 'registry');
        $prop->setAccessible(true);
        $prop->setValue($searcher, $registry);

        $this->setUser($user);
        $hits = $searcher->search('enrolment', 0, 5);
        $this->assertNotEmpty($hits, 'a snippet failure must never cost the hit itself');
        $this->assertSame('', $hits[0]['snippet']);
        $this->assertFalse($hits[0]['stale']);
        $this->assertStringContainsString('/mod/page/view.php', $hits[0]['url']);
        $this->assert_search_session_closed();
    }

    /**
     * Chunker-version fingerprint (§11.22): a mismatching stored fingerprint resets every area
     * cursor, so the next run re-reads everything; unchanged chunks are kept via their content
     * hash and the fingerprint is stamped to the current chunker version.
     */
    public function test_chunker_version_mismatch_forces_full_reindex(): void {
        global $DB;
        $this->resetAfterTest();
        $this->enable_site_search();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $pageone = $gen->create_module('page', [
            'course' => $course->id, 'name' => 'One', 'content' => 'First enrolment page.',
        ]);
        $pagetwo = $gen->create_module('page', [
            'course' => $course->id, 'name' => 'Two', 'content' => 'Second enrolment page.',
        ]);
        // Spread the modification times so the cursor semantics are deterministic: after an
        // incremental run only the boundary document (page two) is re-read.
        $DB->set_field('page', 'timemodified', time() - 900, ['id' => $pageone->id]);
        $DB->set_field('page', 'timemodified', time() - 300, ['id' => $pagetwo->id]);

        $resolved = (new embeddings_action_config_resolver())->resolve();
        $model = (string)$resolved['model'];
        $dims = (int)$resolved['dimensions'];
        $store = embeddings_store_factory::instance();
        $area = site_content_row_mapper::AREA;
        $indexer = new site_content_index_service($this->fake_embedder());

        $this->setAdminUser();
        $first = $indexer->update();
        $this->assertSame('ok', $first['status']);
        $this->assertSame(2, $first['processed']);
        // The fingerprint carries ONLY the pipeline version ('chunker:<v>'): scope-dependent file
        // flags run through the delta sync, never through the fingerprint (governance §4.1).
        $this->assertSame(site_content_chunk_pipeline::fingerprint(), $store->fingerprint($area, $model, $dims));
        $this->assertSame('chunker:' . site_content_chunker::VERSION, $store->fingerprint($area, $model, $dims));

        // Incremental run: only the cursor-boundary document is re-read (and kept unchanged).
        $second = $indexer->update();
        $this->assertSame(1, $second['processed']);
        $this->assertSame(0, $second['embedded']);

        // Simulate a deploy with an older chunker fingerprint: cursors must reset → full re-read.
        $store->set_fingerprint($area, $model, $dims, 'chunker:v0');
        $third = $indexer->update();
        $this->assertSame('ok', $third['status']);
        $this->assertSame(2, $third['processed'], 'a fingerprint mismatch must reset the cursors');
        // Content unchanged → identical content hashes → rows kept, nothing re-embedded.
        $this->assertSame(0, $third['embedded']);
        $this->assertGreaterThanOrEqual(2, $third['kept']);
        $this->assertSame(site_content_chunk_pipeline::fingerprint(), $store->fingerprint($area, $model, $dims));
    }
}
