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
use context_module;
use bookingextension_agent\external\ai_get_doc_content;
use bookingextension_agent\local\wizard\doc_markdown_preview_renderer;
use bookingextension_agent\local\wizard\services\lookup\docs_corpus_registry;
use bookingextension_agent\local\wizard\services\lookup\docs_lookup_service;

/**
 * End-to-end resolution of documentation across multiple corpora.
 *
 * Proves the corpus_id → root authority (registry) is honoured by the lookup service, the
 * preview renderer and the ai_get_doc_content webservice, so a doc is always resolved against the
 * correct plugin's docs tree (Fall B: previews from different docs).
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers \bookingextension_agent\local\wizard\services\lookup\docs_corpus_registry
 * @covers \bookingextension_agent\local\wizard\services\lookup\docs_lookup_service
 * @covers \bookingextension_agent\external\ai_get_doc_content
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class docs_multi_corpus_test extends advanced_testcase {
    /** @var string Absolute root of test corpus A. */
    private string $roota;

    /** @var string Absolute root of test corpus B. */
    private string $rootb;

    /**
     * Set up two temp-dir corpora and register them.
     */
    public function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
        $this->resetAfterTest(true);

        $base = make_request_directory();
        $this->roota = $base . '/corpa';
        $this->rootb = $base . '/corpb';
        mkdir($this->roota . '/sub', 0777, true);
        mkdir($this->rootb, 0777, true);

        file_put_contents($this->roota . '/README.md', "# Corpus A Home\n\nAlpha overview document.\n");
        file_put_contents($this->roota . '/sub/widget.md', "# Widget A\n\nThe alpha widget feature.\n");
        file_put_contents($this->rootb . '/README.md', "# Corpus B Home\n\nThe bravo zebra feature lives here.\n");

        docs_corpus_registry::set_corpora_for_testing([
            'corpa' => $this->roota,
            'corpb' => $this->rootb,
        ]);
    }

    /**
     * Restore corpus discovery after each test.
     */
    public function tearDown(): void {
        docs_corpus_registry::set_corpora_for_testing(null);
        parent::tearDown();
    }

    /**
     * The registry resolves each known corpus to its root and rejects unknown ids.
     */
    public function test_registry_resolves_known_corpora_only(): void {
        $registry = new docs_corpus_registry();

        $this->assertSame($this->roota, $registry->resolve_root('corpa'));
        $this->assertSame($this->rootb, $registry->resolve_root('corpb'));
        $this->assertNull($registry->resolve_root('does_not_exist'));
        $this->assertTrue($registry->is_known('corpa'));
        $this->assertFalse($registry->is_known('nope'));
        $this->assertSame('corpa', $registry->primary());
    }

    /**
     * read_doc_by_path resolves a relpath against the named corpus only — a relpath that exists in
     * corpus B must NOT be readable under corpus A.
     */
    public function test_lookup_reads_per_corpus_and_does_not_cross(): void {
        $svc = new docs_lookup_service();

        $a = $svc->read_doc_by_path('corpa', 'sub/widget.md');
        $this->assertNotNull($a);
        $this->assertSame('corpa', $a['corpus_id']);
        $this->assertStringContainsString('alpha widget', (string)$a['content']);

        $b = $svc->read_doc_by_path('corpb', 'README.md');
        $this->assertNotNull($b);
        $this->assertSame('corpb', $b['corpus_id']);
        $this->assertStringContainsString('bravo zebra', (string)$b['content']);

        // The file sub/widget.md exists only in corpus A; reading it under corpus B must fail.
        $this->assertNull($svc->read_doc_by_path('corpb', 'sub/widget.md'));
        // Unknown corpus never resolves.
        $this->assertNull($svc->read_doc_by_path('unknown', 'README.md'));
    }

    /**
     * read_doc_any_corpus finds a file in whichever corpus actually holds it.
     */
    public function test_lookup_any_corpus_finds_owning_corpus(): void {
        $svc = new docs_lookup_service();

        $doc = $svc->read_doc_any_corpus('sub/widget.md');
        $this->assertNotNull($doc);
        $this->assertSame('corpa', $doc['corpus_id']);
    }

    /**
     * Lexical search spans all corpora and tags every hit with its corpus_id.
     */
    public function test_lexical_search_spans_all_corpora(): void {
        $svc = new docs_lookup_service();

        $results = $svc->search_multi(['zebra'], 5);
        $this->assertNotEmpty($results);
        $this->assertSame('corpb', $results[0]['corpus_id']);
        $this->assertSame('README.md', $results[0]['path']);
    }

    /**
     * The webservice renders content from the requested corpus, rejects unknown corpora and never
     * crosses corpus roots.
     */
    public function test_ws_renders_per_corpus_and_confines(): void {
        $this->setAdminUser();
        $contextid = $this->contextid($this->create_booking_cmid());

        $a = ai_get_doc_content::execute($contextid, 'corpa', 'README.md');
        $this->assertTrue($a['success']);
        $this->assertStringContainsString('Alpha overview', $a['html']);
        $this->assertSame('Corpus A Home', $a['title']);

        $b = ai_get_doc_content::execute($contextid, 'corpb', 'README.md');
        $this->assertTrue($b['success']);
        $this->assertStringContainsString('bravo zebra', $b['html']);

        // Unknown corpus → error, no content.
        $unknown = ai_get_doc_content::execute($contextid, 'ghost', 'README.md');
        $this->assertFalse($unknown['success']);

        // A file only in corpus A must not be readable through corpus B (corpus confinement).
        $cross = ai_get_doc_content::execute($contextid, 'corpb', 'sub/widget.md');
        $this->assertFalse($cross['success']);

        // A traversal attempt is rejected outright at the PARAM_PATH validation layer.
        try {
            ai_get_doc_content::execute($contextid, 'corpa', '../corpb/README.md');
            $this->fail('Traversal path should have been rejected.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\core\exception\invalid_parameter_exception::class, $e);
        }
    }

    /**
     * The preview renderer resolves through the corpus_id; without a corpus_id it renders nothing.
     */
    public function test_preview_renderer_requires_corpus(): void {
        $this->setAdminUser();
        $contextid = $this->contextid($this->create_booking_cmid());
        $renderer = new doc_markdown_preview_renderer();

        global $USER;
        $html = $renderer->render(['corpus_id' => 'corpb', 'path' => 'README.md'], $contextid, (int)$USER->id);
        $this->assertStringContainsString('bravo zebra', $html);

        // No corpus_id → no preview (a bare relpath is not addressable).
        $this->assertSame('', $renderer->render(['path' => 'README.md'], $contextid, (int)$USER->id));
    }

    /**
     * Create a booking instance and return its course-module id (for a valid module context).
     *
     * @return int
     */
    private function create_booking_cmid(): int {
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id,
            'name' => 'Docs corpus test',
        ]);
        return (int)$booking->cmid;
    }

    /**
     * Resolve the module context id for a cmid.
     *
     * @param int $cmid
     * @return int
     */
    private function contextid(int $cmid): int {
        return (int)context_module::instance($cmid)->id;
    }
}
