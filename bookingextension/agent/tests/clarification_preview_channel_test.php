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
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\preview_passthrough;
use context_system;

/**
 * Tests for the clarification preview channel (preview source C).
 *
 * A skill's needs_clarification preflight issue may carry a self-contained preview
 * block; it travels via thread metadata (stash/consume in preview_passthrough) because
 * a preflight fail never reaches the executor and the result dict is rebuilt on its way
 * to the webservice response.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\services\preview_passthrough
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class clarification_preview_channel_test extends advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Create an active thread and return its id.
     *
     * @return int
     */
    private function create_thread(): int {
        $user = $this->getDataGenerator()->create_user();
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$user->id, (int)context_system::instance()->id);
        return (int)$thread->id;
    }

    /**
     * A minimal valid preview block as a skill would attach it to an issue.
     *
     * @return array
     */
    private function preview_block(): array {
        return [
            'type' => 'test_claim_form',
            'js_module' => 'fakeextension_test/preview',
            'payload' => ['sitename' => 'My Club'],
        ];
    }

    /**
     * Extraction honours the contract: only needs_clarification issues with a
     * non-empty preview type qualify; the first match wins.
     */
    public function test_extract_from_issues(): void {
        $preview = $this->preview_block();

        // No issues / wrong severity / preview without type → null.
        $this->assertNull(preview_passthrough::extract_clarification_preview_from_issues([]));
        $this->assertNull(preview_passthrough::extract_clarification_preview_from_issues([
            ['severity' => 'blocking', 'message' => 'x', 'preview' => $preview],
        ]));
        $this->assertNull(preview_passthrough::extract_clarification_preview_from_issues([
            ['severity' => 'needs_clarification', 'message' => 'x', 'preview' => ['payload' => []]],
        ]));

        // First clarification issue with a valid block wins.
        $found = preview_passthrough::extract_clarification_preview_from_issues([
            ['severity' => 'needs_clarification', 'message' => 'no preview here'],
            ['severity' => 'needs_clarification', 'message' => 'x', 'preview' => $preview],
            ['severity' => 'needs_clarification', 'message' => 'y', 'preview' => ['type' => 'other']],
        ]);
        $this->assertSame($preview, $found);
    }

    /**
     * The stash is delivered exactly once for a clarification response, then cleared.
     */
    public function test_stash_and_consume_for_clarification(): void {
        $store = new conversation_store();
        $threadid = $this->create_thread();

        preview_passthrough::stash_clarification_preview($store, $threadid, $this->preview_block());

        $json = preview_passthrough::consume_clarification_preview_json($store, $threadid, 'clarification', '');
        $decoded = json_decode($json, true);
        $this->assertSame('test_claim_form', $decoded['type']);
        $this->assertSame('My Club', $decoded['payload']['sitename']);

        // Consumed: a second read yields nothing.
        $this->assertSame(
            '',
            preview_passthrough::consume_clarification_preview_json($store, $threadid, 'clarification', '')
        );
    }

    /**
     * A non-clarification response never attaches the stash — but still clears it, so a
     * stale preview cannot leak into a later, unrelated clarification.
     */
    public function test_non_clarification_clears_without_attaching(): void {
        $store = new conversation_store();
        $threadid = $this->create_thread();

        preview_passthrough::stash_clarification_preview($store, $threadid, $this->preview_block());

        $this->assertSame(
            '',
            preview_passthrough::consume_clarification_preview_json($store, $threadid, 'sufficient', '')
        );
        // Cleared by the sufficient turn: the next clarification gets nothing.
        $this->assertSame(
            '',
            preview_passthrough::consume_clarification_preview_json($store, $threadid, 'clarification', '')
        );
    }

    /**
     * An already-resolved preview (executed results / proposed actions) always wins;
     * the stash is still cleared.
     */
    public function test_existing_preview_takes_precedence(): void {
        $store = new conversation_store();
        $threadid = $this->create_thread();

        preview_passthrough::stash_clarification_preview($store, $threadid, $this->preview_block());

        $existing = json_encode(['type' => 'executed_result_preview']);
        $this->assertSame(
            $existing,
            preview_passthrough::consume_clarification_preview_json($store, $threadid, 'clarification', $existing)
        );
        $this->assertSame(
            '',
            preview_passthrough::consume_clarification_preview_json($store, $threadid, 'clarification', '')
        );
    }

    /**
     * An expired stash (older than the TTL) is ignored — it can only originate from a
     * request that died between stash and consume.
     */
    public function test_expired_stash_is_ignored(): void {
        $store = new conversation_store();
        $threadid = $this->create_thread();

        // Write the stash with an ancient timestamp directly via the metadata API.
        $store->set_thread_metadata_value($threadid, '_clarification_preview', [
            'preview' => $this->preview_block(),
            'stashedat' => time() - 3600,
        ]);

        $this->assertSame(
            '',
            preview_passthrough::consume_clarification_preview_json($store, $threadid, 'clarification', '')
        );
    }
}
