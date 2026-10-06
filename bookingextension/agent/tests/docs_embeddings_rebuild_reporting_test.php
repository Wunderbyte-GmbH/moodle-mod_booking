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
use bookingextension_agent\local\wizard\services\lookup\docs_corpus_registry;
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_index_service;

/**
 * The rebuild must report what it did per corpus and emit progress (#2343).
 *
 * The task log is the operator's only window: a run that prints nothing for ten minutes and
 * fails without a recorded cause is indistinguishable from a hang. The service reports a
 * per-corpus breakdown and feeds an injectable progress sink; the task mtraces both.
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\services\lookup\docs_embeddings_index_service
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class docs_embeddings_rebuild_reporting_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('aiskillenableall', 1, 'bookingextension_agent');
        set_config('embeddingsstore', 'db', 'bookingextension_agent');
    }

    protected function tearDown(): void {
        docs_corpus_registry::set_corpora_for_testing(null);
        parent::tearDown();
    }

    /**
     * The summary carries a per-corpus breakdown and the progress sink receives corpus lines.
     */
    public function test_rebuild_reports_per_corpus_and_emits_progress(): void {
        $dir = make_request_directory();
        file_put_contents($dir . '/otter.md', "# Otter\n\nFischotter jagen Forellen.\n");
        docs_corpus_registry::set_corpora_for_testing(['testcorpus' => $dir]);

        $lines = [];
        $summary = (new docs_embeddings_index_service())->rebuild(
            null,
            'test-model',
            4,
            false,
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            }
        );

        $corpora = (array)($summary['corpora'] ?? []);
        $this->assertArrayHasKey('testcorpus', $corpora, 'the summary must break figures down per corpus');
        $this->assertSame(1, (int)($corpora['testcorpus']['files'] ?? -1));

        $this->assertNotEmpty(
            preg_grep('/testcorpus/', $lines),
            'the progress sink must receive at least the corpus summary line'
        );
    }
}
