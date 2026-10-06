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

/**
 * A docs rebuild whose embedding calls partly fail is not a complete index.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\embeddings_action_config_resolver;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\services\lookup\docs_corpus_registry;
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_index_service;
use bookingextension_agent\local\wizard\services\lookup\docs_embeddings_readiness_service;
use bookingextension_agent\task\rebuild_docs_embeddings_adhoc;

/**
 * A docs rebuild whose embedding calls partly fail is not a complete index (#2549).
 *
 * On training.wunderbyte.at (2026-10-03) 1047 of 1567 embedding calls of a full rebuild failed on
 * the provider's rate limit. The rebuild dropped those chunks, reported "ok", stamped the source
 * fingerprint and the readiness said "ready", so nothing ever filled the gaps. A rebuild with
 * failed chunks must report them, leave the index not ready and fail the task, so that Moodle
 * retries it with backoff; the next run reuses what exists and embeds only what is missing.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\lookup\docs_embeddings_index_service
 * @covers     \bookingextension_agent\task\rebuild_docs_embeddings_adhoc
 */
final class docs_embeddings_rebuild_partial_test extends advanced_testcase {
    /** @var string Directory of the test corpus. */
    private string $dir;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('aiskillenableall', 1, 'bookingextension_agent');
        set_config('embeddingsstore', 'db', 'bookingextension_agent');

        $this->dir = make_request_directory();
        file_put_contents($this->dir . '/otter.md', "# Otter\n\nOtters hunt trout.\n");
        file_put_contents($this->dir . '/heron.md', "# Heron\n\nHerons wait in the reeds.\n");
        docs_corpus_registry::set_corpora_for_testing(['testcorpus' => $this->dir]);
    }

    protected function tearDown(): void {
        llm_call_service::set_test_embedding(null);
        docs_corpus_registry::set_corpora_for_testing(null);
        parent::tearDown();
    }

    /**
     * Make every embedding call succeed with a vector of the active dimensions.
     */
    private function embeddings_succeed(): void {
        $dims = (int)(new embeddings_action_config_resolver())->resolve()['dimensions'];
        llm_call_service::set_test_embedding(array_fill(0, max(1, $dims), 0.01));
    }

    /**
     * Failed chunks leave the index not ready and fail the task; the next run fills only the gap.
     */
    public function test_failed_chunks_are_reported_and_healed(): void {
        $service = new docs_embeddings_index_service();
        $readiness = new docs_embeddings_readiness_service();

        // Run 1: every call succeeds, the index is complete.
        $this->embeddings_succeed();
        $first = $service->rebuild();
        $this->assertSame('ok', $first['status']);
        $this->assertSame(0, (int)($first['failed'] ?? 0));
        $this->assertTrue($readiness->get_status()['ready'], 'Precondition failed: a complete rebuild is ready.');

        // Run 2: a new page arrives while the provider refuses every call.
        file_put_contents($this->dir . '/beaver.md', "# Beaver\n\nBeavers build dams.\n");
        llm_call_service::set_test_embedding(null);
        $second = $service->rebuild();

        $this->assertSame('partial', $second['status'], 'a rebuild with failed chunks is not ok');
        $this->assertSame(1, (int)($second['failed'] ?? 0));
        $this->assertSame(2, (int)$second['reused'], 'the pages embedded before stay in the index');
        $this->assertTrue($readiness->is_index_ready(), 'the partial index stays searchable');
        $this->assertFalse($readiness->get_status()['ready'], 'a partial index must not report ready');

        // The task fails, so that Moodle retries it with backoff instead of leaving the gap.
        try {
            ob_start();
            (new rebuild_docs_embeddings_adhoc())->execute();
            $this->fail('the task must fail while chunks are missing');
        } catch (\moodle_exception $e) {
            $this->assertSame('embeddingsdocsrebuildfailed', $e->errorcode);
        } finally {
            ob_end_clean();
        }

        // Run 3: the provider answers again; only the missing page is embedded.
        $this->embeddings_succeed();
        $third = $service->rebuild();
        $this->assertSame('ok', $third['status']);
        $this->assertSame(0, (int)($third['failed'] ?? 0));
        $this->assertSame(1, (int)$third['embedded']);
        $this->assertSame(2, (int)$third['reused']);
        $this->assertTrue($readiness->get_status()['ready']);
    }
}
