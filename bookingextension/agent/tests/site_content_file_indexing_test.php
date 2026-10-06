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
use bookingextension_agent\local\wizard\services\attachment\pdf_text_extractor;
use bookingextension_agent\local\wizard\services\retrieval\embeddings_store_factory;
use bookingextension_agent\local\wizard\services\sitesearch\index_scope_estimator;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_area_registry;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_chunk_pipeline;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_chunker;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository;

/**
 * PDF file indexing for the site-content index (§14.2, files v1) over mod_resource — whose search
 * area indexes only name + intro, so the attached PDF IS the content: the perfect test vehicle.
 *
 * Uses an injected deterministic embedder (no LLM provider, no API call); the embeddings provider
 * CLASS still has to exist for the readiness gate. Tests that depend on actual PDF extraction skip
 * when no extractor (pdftotext binary or bundled PHP parser) is available.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_chunk_pipeline
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_index_service
 * @covers     \bookingextension_agent\local\wizard\services\sitesearch\site_content_search_service
 * @covers     \bookingextension_agent\local\wizard\services\attachment\pdf_text_extractor
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class site_content_file_indexing_test extends advanced_testcase {
    /**
     * Reset the scope resolver's request-static caches before every test (#2339 pattern).
     *
     * The resolver caches effective rule pairs per course id; after the per-test database reset a new
     * course reuses an id, so a rule pair cached by an earlier test in the same process applied its
     * include-files flag here and the incremental run indexed the file chunk (full suite only).
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        \bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_resolver::reset_request_cache();
    }

    /** The file-indexing search area under test. */
    private const AREAKEY = 'mod_resource-activity';

    /** Distinctive phrase embedded in the fixture PDF. */
    private const PDF_PHRASE = 'Quantum gearbox maintenance schedule for llamas';

    /**
     * The content-derived document text of a fixture resource: title \n intro, exactly as the
     * shared assemble helper produces it from base_activity's get_document() mapping. Short
     * enough for one chunk, so the content part of the index is a single row with a known hash.
     */
    private const CONTENT_TEXT = "Handout\nHandout intro text.";

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
     * Enable the DB backend and the mod_resource area; skip if the provider class is absent.
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
     * Skip when no PDF extraction method (pdftotext or bundled parser) is available.
     *
     * @return void
     */
    private function require_extractor(): void {
        if (!(new pdf_text_extractor())->is_available()) {
            $this->markTestSkipped('no PDF extractor available');
        }
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
     * Stored site_content rows of one document, ordered by chunk number.
     *
     * @param int $docid
     * @return array
     */
    private function doc_rows(int $docid): array {
        global $DB;
        return array_values($DB->get_records(
            'bx_agent_embeddings',
            ['area' => 'site_content', 'docid' => $docid],
            'refindex ASC'
        ));
    }

    /**
     * Build a minimal, valid single-page PDF containing $text (plain ASCII, no ()\ characters).
     *
     * The cross-reference offsets are computed dynamically, so the output parses with both
     * pdftotext and the bundled pure-PHP parser (verified against both).
     *
     * @param string $text
     * @return string PDF bytes.
     */
    private function make_pdf(string $text): string {
        $stream = 'BT /F1 12 Tf 72 720 Td (' . $text . ') Tj ET';
        $objects = [
            1 => "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            2 => "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            3 => "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R"
                . " /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
            4 => "4 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n",
            5 => "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $body;
        }
        $xrefpos = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefpos . "\n%%EOF";
        return $pdf;
    }

    /**
     * Create a File resource whose content file has the given name and raw bytes.
     *
     * Fixed name + intro (the generator would replace an EMPTY intro with a counter-dependent
     * default) keep the content-derived text at exactly one known chunk: {@see CONTENT_TEXT}.
     *
     * @param int $courseid
     * @param string $filename E.g. 'handout.pdf' (the extension drives the stored mimetype).
     * @param string $filecontent Raw file bytes.
     * @return \stdClass The resource instance record (with cmid).
     */
    private function create_resource_with_file(int $courseid, string $filename, string $filecontent): \stdClass {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'component' => 'user',
            'filearea' => 'draft',
            'contextid' => \context_user::instance($USER->id)->id,
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $filename,
        ], $filecontent);
        return $this->getDataGenerator()->create_module('resource', [
            'course' => $courseid,
            'name' => 'Handout',
            'intro' => 'Handout intro text.',
            'introformat' => FORMAT_HTML,
            'files' => $draftid,
        ]);
    }

    /**
     * With the area's file flag OFF (the default — deliberately not set here) a file-carrying
     * resource is indexed with its content chunks only, byte-identical to the pre-files
     * behaviour, and the store fingerprint records the 'off' file mode.
     */
    public function test_files_flag_off_by_default_indexes_content_chunks_only(): void {
        $this->resetAfterTest();
        $this->enable_site_search();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->create_resource_with_file((int)$course->id, 'handout.pdf', $this->make_pdf(self::PDF_PHRASE));

        $result = (new site_content_index_service($this->fake_embedder()))->update();
        $this->assertSame('ok', $result['status']);

        // Exactly the one content chunk (title + intro) — no file chunk.
        [$model, $dims] = $this->variant();
        $rows = $this->doc_rows((int)$resource->id);
        $this->assertCount(1, $rows);
        $this->assertSame(site_content_chunker::content_hash(self::CONTENT_TEXT, $model, $dims), $rows[0]->contenthash);

        // The fingerprint carries only the pipeline version — file-flag changes run through the
        // scope delta sync (governance §4.1), never through the fingerprint.
        $this->assertSame(
            'chunker:' . site_content_chunker::VERSION,
            embeddings_store_factory::instance()->fingerprint('site_content', $model, $dims)
        );
    }

    /**
     * With the area's file flag ON the attached PDF is extracted, header-prefixed, chunked and
     * indexed; an enrolled user finds it, and the query-time snippet byte-reproduces the indexed
     * chunk (stale === false — the content-hash self-heal passes for file-derived chunks too).
     */
    public function test_pdf_chunks_indexed_and_reproduced_at_query_time(): void {
        $this->resetAfterTest();
        $this->enable_site_search();
        $this->require_extractor();
        (new sitesearch_scope_repository())->set_includefiles(self::AREAKEY, true);
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $resource = $this->create_resource_with_file((int)$course->id, 'handout.pdf', $this->make_pdf(self::PDF_PHRASE));
        $user = $gen->create_user();
        $gen->enrol_user((int)$user->id, (int)$course->id);

        $result = (new site_content_index_service($this->fake_embedder()))->update();
        $this->assertSame('ok', $result['status']);

        // Content chunk + at least one file-derived chunk; the fingerprint stays pipeline-only
        // (per-scope file flags are delta-synced, not fingerprinted).
        $rows = $this->doc_rows((int)$resource->id);
        $this->assertGreaterThanOrEqual(2, count($rows));
        [$model, $dims] = $this->variant();
        $this->assertSame(
            'chunker:' . site_content_chunker::VERSION,
            embeddings_store_factory::instance()->fingerprint('site_content', $model, $dims)
        );

        // The enrolled user finds the PDF text; the re-extracted chunk carries the FILE header
        // and PROVABLY matches the embedded chunk (stale false = content hash equality).
        $this->setUser($user);
        $hits = (new site_content_search_service($this->fake_embedder()))->search('llama maintenance', 0, 5);
        $filehits = array_values(array_filter(
            $hits,
            static fn(array $hit): bool => strpos($hit['chunktext'], self::PDF_PHRASE) !== false
        ));
        $this->assertNotEmpty($filehits);
        $this->assertSame((int)$resource->id, $filehits[0]['docid']);
        $this->assertStringStartsWith('FILE: handout.pdf', $filehits[0]['chunktext']);
        $this->assertStringContainsString('FILE: handout.pdf', $filehits[0]['snippet']);
        $this->assertFalse($filehits[0]['stale']);
    }

    /**
     * The governance flag op ({@see sitesearch_scope_repository::set_includefiles()}, the page's
     * POST handler delegates to it) persists the row flag and queues the DELTA-SYNC backfill for
     * the affected courses (governance §4.1 — the fingerprint no longer changes): running the
     * backfill re-chunks the unchanged resource, adding its file chunk on flagging and removing
     * it again on unflagging.
     */
    public function test_files_flag_flip_delta_syncs_both_ways(): void {
        $this->resetAfterTest();
        $this->enable_site_search();
        $this->require_extractor();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->create_resource_with_file((int)$course->id, 'handout.pdf', $this->make_pdf(self::PDF_PHRASE));

        $repository = new sitesearch_scope_repository();
        $indexer = new site_content_index_service($this->fake_embedder());
        $indexer->update();
        $this->assertCount(1, $this->doc_rows((int)$resource->id));

        // ON via the governance flag op: row flag persisted, and the chokepoint queues a
        // backfill for the flag-flipped course (the site row covers it).
        $repository->set_includefiles(self::AREAKEY, true);
        $this->assertTrue($repository->is_includefiles(self::AREAKEY));
        $this->assertSame([self::AREAKEY], $repository->files_enabled_site_areas());
        $queued = \core\task\manager::get_adhoc_tasks(\bookingextension_agent\task\sitesearch_scope_sync_adhoc::class);
        $latest = end($queued);
        $this->assertNotFalse($latest);
        $customdata = (array)$latest->get_custom_data();
        $this->assertSame(self::AREAKEY, $customdata['area']);
        $this->assertContains((int)$course->id, array_map('intval', (array)$customdata['backfill']));

        // A plain incremental run does NOT re-read documents OUTSIDE the cursor window — the flag
        // correction is the backfill's job. Push the cursor past the resource first: with a single
        // document in the index, the "-1" overlap window would otherwise legitimately re-read it
        // (boundary docs are always re-read) and apply the flag opportunistically.
        $resolved = (new embeddings_action_config_resolver())->resolve();
        (new \bookingextension_agent\local\wizard\services\sitesearch\site_content_state_repository())->set_cursor(
            self::AREAKEY,
            (string)$resolved['model'],
            (int)$resolved['dimensions'],
            time() + 100
        );
        $result = $indexer->update();
        $this->assertSame('ok', $result['status']);
        $this->assertCount(1, $this->doc_rows((int)$resource->id));

        // The backfill (what the queued adhoc executes per course) adds the file chunk.
        $stats = $indexer->update_course(self::AREAKEY, (int)$course->id);
        $this->assertSame('ok', $stats['status']);
        $this->assertGreaterThanOrEqual(2, count($this->doc_rows((int)$resource->id)));

        // OFF again: the backfill recomputes back to the byte-identical content-only chunk list.
        $repository->set_includefiles(self::AREAKEY, false);
        $this->assertFalse($repository->is_includefiles(self::AREAKEY));
        $stats = $indexer->update_course(self::AREAKEY, (int)$course->id);
        $this->assertSame('ok', $stats['status']);
        $this->assertCount(1, $this->doc_rows((int)$resource->id));
        [$model, $dims] = $this->variant();
        $this->assertSame(
            'chunker:' . site_content_chunker::VERSION,
            embeddings_store_factory::instance()->fingerprint('site_content', $model, $dims)
        );
    }

    /**
     * Flag governance semantics: the flag is settable before enabling (row created disabled),
     * only enabled + flagged areas become effective, and the registry filters areas that do not
     * use file indexing at all — file chunks exist only for flagged, file-capable areas.
     */
    public function test_files_flag_governance_semantics(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $repository = new sitesearch_scope_repository();
        $registry = new site_content_area_registry();

        // Default: flag off everywhere, effective set empty.
        $this->assertFalse($repository->is_includefiles(self::AREAKEY));
        $this->assertSame([], $repository->files_enabled_site_areas());

        // Settable pre-enable: the row is created DISABLED and the flag is not yet effective.
        $repository->set_includefiles(self::AREAKEY, true);
        $this->assertTrue($repository->is_includefiles(self::AREAKEY));
        $this->assertFalse($repository->is_enabled(self::AREAKEY));
        $this->assertSame([], $repository->files_enabled_site_areas());
        $this->assertSame([], $registry->files_enabled_area_keys());

        // Enabling the same row makes it effective (upsert: still exactly one row).
        $repository->set_enabled(self::AREAKEY, true);
        $this->assertCount(1, $DB->get_records('bx_agent_search_scope'));
        $this->assertSame([self::AREAKEY], $repository->files_enabled_site_areas());
        $this->assertSame([self::AREAKEY], $registry->files_enabled_area_keys());

        // A non-file-capable area (uses_file_indexing() false — the message areas are the core
        // examples; most others index files) never reaches the registry's effective set, even
        // enabled + flagged.
        $repository->set_enabled('core_message-message_received', true);
        $repository->set_includefiles('core_message-message_received', true);
        $this->assertContains('core_message-message_received', $repository->files_enabled_site_areas());
        $this->assertSame([self::AREAKEY], $registry->files_enabled_area_keys());
    }

    /**
     * A broken PDF fails soft: the file is skipped with a debugging note and the document is
     * still indexed with its content chunks — the area run never aborts.
     */
    public function test_broken_pdf_fails_soft(): void {
        $this->resetAfterTest();
        $this->enable_site_search();
        $this->require_extractor();
        (new sitesearch_scope_repository())->set_includefiles(self::AREAKEY, true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->create_resource_with_file((int)$course->id, 'broken.pdf', 'this is not a pdf at all');

        $result = (new site_content_index_service($this->fake_embedder()))->update();
        $this->assertDebuggingCalledCount(1);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('ok', $result['areas'][self::AREAKEY]['status']);

        [$model, $dims] = $this->variant();
        $rows = $this->doc_rows((int)$resource->id);
        $this->assertCount(1, $rows);
        $this->assertSame(site_content_chunker::content_hash(self::CONTENT_TEXT, $model, $dims), $rows[0]->contenthash);
    }

    /**
     * An oversized PDF (> MAX_FILE_BYTES) is skipped silently by policy — no extraction attempt,
     * no debugging, the document keeps its content chunks.
     */
    public function test_oversized_pdf_skipped_silently(): void {
        $this->resetAfterTest();
        $this->enable_site_search();
        $this->require_extractor();
        (new sitesearch_scope_repository())->set_includefiles(self::AREAKEY, true);
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->create_resource_with_file(
            (int)$course->id,
            'big.pdf',
            str_repeat('x', site_content_chunk_pipeline::MAX_FILE_BYTES + 1)
        );

        $result = (new site_content_index_service($this->fake_embedder()))->update();
        $this->assertSame('ok', $result['status']);
        $this->assertCount(1, $this->doc_rows((int)$resource->id));
        $this->assertDebuggingNotCalled();
    }

    /**
     * The effort estimator honestly includes file chunks: with the area's file flag on, the same
     * resource estimates more chunks than without (content-only), via the shared pipeline. The
     * flag op purges the estimate cache, so the fresh figure shows immediately — and PREVIEW
     * semantics apply: the area is deliberately NOT enabled here, because the estimate is the
     * decision number an admin looks at BEFORE enabling.
     */
    public function test_estimator_includes_file_chunks_when_area_flagged(): void {
        $this->resetAfterTest();
        $this->require_extractor();
        $this->setAdminUser();

        // The PDF text forms its own (header-prefixed) chunk on top of the content chunk.
        $course = $this->getDataGenerator()->create_course();
        $this->create_resource_with_file((int)$course->id, 'handout.pdf', $this->make_pdf(self::PDF_PHRASE));

        $estimator = new index_scope_estimator();
        $off = $estimator->estimate(self::AREAKEY);
        $this->assertNotNull($off);

        (new sitesearch_scope_repository())->set_includefiles(self::AREAKEY, true);
        $on = $estimator->estimate(self::AREAKEY);
        $this->assertNotNull($on);

        // Content-only: the tiny title + intro are one estimated chunk; file-inclusive: >= 2.
        $this->assertSame(1, $off['estchunks']);
        $this->assertGreaterThanOrEqual(2, $on['estchunks']);
    }
}
