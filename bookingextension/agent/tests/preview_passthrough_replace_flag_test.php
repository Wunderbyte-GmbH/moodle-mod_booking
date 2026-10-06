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
 * Tests for the `replace` flag of the preview data contract.
 *
 * Across a multi-step confirm chain the passthrough accumulates previews of the same type by
 * concatenating their HTML and JS, so a chain shows every affected item. A preview that renders
 * the CURRENT STATE of one object (e.g. a report after each editing step) must instead supersede
 * the accumulated one; it declares that with `replace => true`. The old behaviour stays pinned
 * for previews without the flag.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\services\preview_passthrough
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_passthrough_replace_flag_test extends advanced_testcase {
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
     * Wrap a preview block into the result shape the executor produces.
     *
     * @param array $preview
     * @return array
     */
    private function result_with_preview(array $preview): array {
        return [['status' => 'executed', 'preview' => $preview]];
    }

    /**
     * Without the flag, same-type previews accumulate (pins the pre-existing behaviour).
     */
    public function test_same_type_without_flag_accumulates(): void {
        $threadid = $this->create_thread();

        preview_passthrough::resolve_preview_json($this->result_with_preview([
            'type' => 'state_card',
            'html' => '<div id="first"></div>',
            'js' => 'first();',
        ]), $threadid);

        $json = preview_passthrough::resolve_preview_json($this->result_with_preview([
            'type' => 'state_card',
            'html' => '<div id="second"></div>',
            'js' => 'second();',
        ]), $threadid);
        $decoded = json_decode($json, true);

        $this->assertSame('<div id="first"></div><div id="second"></div>', $decoded['html']);
        $this->assertSame("first();\nsecond();", $decoded['js']);
    }

    /**
     * With `replace => true`, the new preview supersedes the accumulated one of the same type:
     * only the new HTML and JS ship, and the stored accumulator holds only the new block.
     */
    public function test_same_type_with_replace_flag_supersedes(): void {
        $threadid = $this->create_thread();

        preview_passthrough::resolve_preview_json($this->result_with_preview([
            'type' => 'state_card',
            'html' => '<div id="first"></div>',
            'js' => 'first();',
            'payload' => ['ids' => [1]],
        ]), $threadid);

        $json = preview_passthrough::resolve_preview_json($this->result_with_preview([
            'type' => 'state_card',
            'html' => '<div id="second"></div>',
            'js' => 'second();',
            'replace' => true,
            'payload' => ['ids' => [2]],
        ]), $threadid);
        $decoded = json_decode($json, true);

        $this->assertSame('<div id="second"></div>', $decoded['html']);
        $this->assertSame('second();', $decoded['js']);
        $this->assertSame([2], $decoded['payload']['ids'], 'Payload must not be merged with the superseded block.');

        // A later turn without a fresh preview replays the accumulator: it must be the replacing block only.
        $replayed = json_decode(preview_passthrough::resolve_preview_json([], $threadid), true);
        $this->assertSame('<div id="second"></div>', $replayed['html']);
    }

    /**
     * A replacing preview followed by a plain same-type preview accumulates again from the
     * replaced state (the flag is per block, not sticky).
     */
    public function test_replace_flag_is_not_sticky(): void {
        $threadid = $this->create_thread();

        preview_passthrough::resolve_preview_json($this->result_with_preview([
            'type' => 'state_card',
            'html' => '<div id="second"></div>',
            'replace' => true,
        ]), $threadid);

        $json = preview_passthrough::resolve_preview_json($this->result_with_preview([
            'type' => 'state_card',
            'html' => '<div id="third"></div>',
        ]), $threadid);
        $decoded = json_decode($json, true);

        $this->assertSame('<div id="second"></div><div id="third"></div>', $decoded['html']);
    }
}
