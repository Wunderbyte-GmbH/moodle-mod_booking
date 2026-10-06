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
 * Orphaned realizing placeholders need a TTL sweep (audit C7, F5 edge case).
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\queue_status_policy;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

/**
 * A realizing placeholder settles ONLY through its bound command's terminal transition (F5).
 *
 * If that realizing command vanishes (crash between save points, GC, corrupted metadata),
 * nothing can ever settle the placeholder again: the blocked-confirmation TTL sweep
 * (fail_expired_blocked_items) only visits blocked_confirmation items and the stale-running
 * reaper (fail_stale_running_items, audit 554) only visits running items. The orphan stays
 * `realizing` forever — an open, undead step in every pending-list computation.
 *
 * This test builds exactly that orphan on the bind/settle model (commit 876a25c) and expects
 * the engine's sweep mechanisms to move it to a terminal state.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\queue\queue_manager
 */
final class realizing_placeholder_sweep_test extends abstract_agent_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
    }

    /**
     * Return one queue item by id (assertion helper).
     *
     * @param queue_manager $queuesvc
     * @param int $threadid
     * @param string $queueitemid
     * @return array
     */
    private function item(queue_manager $queuesvc, int $threadid, string $queueitemid): array {
        $item = $queuesvc->get_queue_item($threadid, $queueitemid);
        $this->assertIsArray($item, 'Queue item ' . $queueitemid . ' must exist.');
        return $item;
    }

    /**
     * An hours-old realizing placeholder whose realizing command vanished must be swept terminal.
     */
    public function test_orphaned_realizing_placeholder_is_swept_to_terminal_state(): void {
        $this->setUser($this->teacher);
        [$store, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $queuesvc = new queue_manager($store);

        // Bind/settle model (F5): a planned placeholder is bound to the real command that
        // starts realizing its step and settles with that command's terminal state.
        $queuesvc->enqueue_placeholder($threadid, 0, 0, 'Zweiter Schritt: Option aktualisieren');
        $real = $queuesvc->enqueue_command($threadid, 0, 0, [
            'skill' => 'mod_booking.create_option',
            'input' => ['text' => 'Wikinger Option'],
        ], 'mutating', 'queued');
        $realid = (string)$real['queue_item_id'];

        $placeholderid = (string)$queuesvc->bind_next_placeholder($threadid, $realid);
        $this->assertSame(
            queue_status_policy::realizing_status(),
            (string)$this->item($queuesvc, $threadid, $placeholderid)['status'],
            'Setup: binding must move the placeholder to realizing.'
        );

        // Orphan the placeholder: the realizing command disappears without ever reaching a
        // terminal transition, so no settle_bound_placeholder() call will ever happen for it.
        // Backdate the placeholder far beyond any reasonable in-flight window (hours).
        $backdated = time() - (6 * HOURSECS);
        $items = [];
        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            if ((string)($item['queue_item_id'] ?? '') === $realid) {
                continue;
            }
            if ((string)($item['queue_item_id'] ?? '') === $placeholderid) {
                $item['created_at'] = $backdated;
                $item['updated_at'] = $backdated;
            }
            $items[] = $item;
        }
        $queuesvc->save_queue_items($threadid, $items);

        // Sanity: the orphan is still an open realizing item before the sweeps run.
        $this->assertSame(
            queue_status_policy::realizing_status(),
            (string)$this->item($queuesvc, $threadid, $placeholderid)['status']
        );

        // Run EVERY sweep/reaper the engine has (both run on each confirm entry).
        $queuesvc->fail_expired_blocked_items($threadid);
        $queuesvc->fail_stale_running_items($threadid);

        $status = (string)$this->item($queuesvc, $threadid, $placeholderid)['status'];
        $this->assertTrue(
            queue_status_policy::is_terminal_status($status),
            'Orphaned realizing placeholder must not stay open forever: its realizing command'
            . ' vanished hours ago and can never settle it, yet no sweep covers the realizing state'
            . ' (blocked-TTL sweep only visits blocked_confirmation, stale-running reaper only'
            . ' visits running). Actual status: \'' . $status . '\'.'
        );
    }
}
