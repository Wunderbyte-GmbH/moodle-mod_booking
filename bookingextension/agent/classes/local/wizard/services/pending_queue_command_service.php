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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\queue\queue_manager;

/**
 * Builds command payloads from queue-backed pending intents.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pending_queue_command_service {
    /** @var queue_manager */
    private queue_manager $queuesvc;

    /**
     * Constructor.
     *
     * @param queue_manager $queuesvc
     */
    public function __construct(queue_manager $queuesvc) {
        $this->queuesvc = $queuesvc;
    }

    /**
     * Build mutating command payloads from queue item ids in a pending intent.
     *
     * @param array $pendingintent
     * @param int $threadid
     * @return array[]
     */
    public function build_mutating_commands_from_pending_intent(array $pendingintent, int $threadid): array {
        $queueitemids = $this->normalize_queue_item_ids($pendingintent['queue_item_ids'] ?? []);
        if (empty($queueitemids)) {
            return [];
        }

        $items = [];
        foreach ($queueitemids as $queueitemid) {
            $item = $this->queuesvc->get_queue_item($threadid, $queueitemid);
            if (!is_array($item) || (string)($item['mutability'] ?? '') !== 'mutating') {
                continue;
            }

            $status = trim((string)($item['status'] ?? ''));
            if (!queue_status_policy::is_actionable_mutating_status($status)) {
                continue;
            }

            $items[] = $item;
        }

        return queue_command_mapper::from_queue_items($items);
    }

    /**
     * Normalize queue item ids into non-empty string list.
     *
     * @param mixed $queueitemids
     * @return string[]
     */
    private function normalize_queue_item_ids($queueitemids): array {
        if (!is_array($queueitemids)) {
            return [];
        }

        $ids = [];
        foreach ($queueitemids as $id) {
            $normalized = trim((string)$id);
            if ($normalized !== '') {
                $ids[] = $normalized;
            }
        }

        return array_values(array_unique($ids));
    }
}
