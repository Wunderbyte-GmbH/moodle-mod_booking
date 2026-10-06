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
 * Discard a thread's pending confirmation and skip its actionable mutating queue items.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\queue\queue_manager;

/**
 * Encapsulates the "discard pending" business logic so the webservice stays a thin gate→delegate
 * endpoint (mirrors how ai_confirm_run delegates to confirm_run_service).
 */
class discard_pending_service {
    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param conversation_store|null $store
     */
    public function __construct(?conversation_store $store = null) {
        $this->store = $store ?? new conversation_store();
    }

    /**
     * Consume any pending confirmation intent and skip all actionable mutating queue items.
     *
     * @param int $threadid
     * @param int $userid
     * @param int $contextid
     * @return array{discardedcount:int, message:string}
     */
    public function discard(int $threadid, int $userid, int $contextid): array {
        (new pending_intent_service($this->store))->consume($threadid, $userid, $contextid);

        $queuesvc = new queue_manager($this->store);
        $queuetransitionsvc = new queue_transition_service();
        $discardedcount = 0;

        foreach ($queuesvc->get_queue_items($threadid) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $queueitemid = trim((string)($item['queue_item_id'] ?? ''));
            if ($queueitemid === '') {
                continue;
            }
            if ((string)($item['mutability'] ?? '') !== 'mutating') {
                continue;
            }

            $status = trim((string)($item['status'] ?? ''));
            if (!queue_status_policy::is_actionable_mutating_status($status)) {
                continue;
            }

            $queuetransitionsvc->to_skipped(
                $queuesvc,
                $threadid,
                $queueitemid,
                'USER_DISCARDED_PENDING_CONFIRMATION',
                ['USER_DISCARDED', 'LOGICAL_SKIP'],
                'user_discarded',
                'Skipped because the user discarded the pending confirmation.'
            );
            $discardedcount++;
        }

        $message = $discardedcount > 0
            ? 'Pending confirmation and active queue items were discarded.'
            : 'No actionable mutating queue items to discard.';

        return ['discardedcount' => $discardedcount, 'message' => $message];
    }
}
