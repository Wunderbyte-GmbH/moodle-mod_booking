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

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\queue\queue_manager;
use bookingextension_agent\local\wizard\services\queue_status_policy;

/**
 * Builds and normalizes completed command history for runtime prompt context.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
/**
 * Semantic definition:
 *   completed_commands = executed command intent (secondary evidence tier).
 *   Each entry represents what the system ATTEMPTED to execute (skill name + input),
 *   reconstructed from message history and queue state.
 *   Commands confirm intent was dispatched but do NOT verify domain outcome.
 *   Use completed_observations as authoritative source; commands only for reconstruction
 *   when observations are unavailable.
 *   Source-of-truth hierarchy: observations > completed_commands > assistant narrative.
 */
class completed_command_history_service {
    /** Result status of a command the executor ran successfully. */
    public const STATUS_EXECUTED = 'executed';

    /**
     * Compact normalization preset for the completed_commands prompt blob: drop noise keys,
     * cap strings/lists and drop empties to keep the blob small (audit 03-F03).
     */
    private const COMPACT_OPTS = [
        'dropkeys' => ['confirmed', 'outputlang', 'lang', 'user_lang', 'sessiontoken', 'sesskey'],
        'capstring' => 160,
        'caplist' => 20,
        'dropempty' => true,
    ];

    /** @var conversation_store */
    private conversation_store $store;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store) {
        $this->store = $store;
    }

    /**
     * Extract recently completed commands (skill + executed input) from assistant state.
     *
     * @param array $messages
     * @param bool $withcreated Add the internal key _created_at (time of the assistant message the row comes from).
     * @return array[]
     */
    public function extract_from_messages(array $messages, bool $withcreated = false): array {
        $completed = [];
        $latestassistantpayload = null;
        $fallbackassistantpayload = null;
        $latestcreated = 0;
        $fallbackcreated = 0;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i];
            if ((string)($msg->role ?? '') !== 'assistant') {
                continue;
            }

            $structured = json_decode((string)($msg->structuredjson ?? ''), true);
            if (!is_array($structured) || empty($structured)) {
                continue;
            }

            if (!is_array($fallbackassistantpayload)) {
                $fallbackassistantpayload = $structured;
                $fallbackcreated = (int)($msg->timecreated ?? 0);
            }

            $loopresults = (array)($structured['loop_results'] ?? []);
            $results = (array)($structured['results'] ?? []);
            if (!empty($loopresults) || !empty($results)) {
                $latestassistantpayload = $structured;
                $latestcreated = (int)($msg->timecreated ?? 0);
                break;
            }
        }

        if (!is_array($latestassistantpayload)) {
            $latestassistantpayload = $fallbackassistantpayload;
            $latestcreated = $fallbackcreated;
        }

        if (!is_array($latestassistantpayload) || empty($latestassistantpayload)) {
            return [];
        }

        $results = (array)($latestassistantpayload['loop_results'] ?? []);
        if (empty($results)) {
            $results = (array)($latestassistantpayload['results'] ?? []);
        }

        foreach ($results as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $status = trim((string)($entry['status'] ?? ''));
            if ($status !== self::STATUS_EXECUTED) {
                continue;
            }

            $skill = trim((string)($entry['skill'] ?? ''));
            if ($skill === '') {
                continue;
            }

            $input = (array)($entry['input'] ?? $entry['executed_input'] ?? []);
            $compact = ['skill' => $skill];
            $normalizedinput = input_normalizer::normalize($input, self::COMPACT_OPTS);
            if (!empty($normalizedinput)) {
                $compact['input'] = $normalizedinput;
            }
            if ($withcreated) {
                $compact['_created_at'] = $latestcreated;
            }
            $completed[] = $compact;
        }

        if (count($completed) > 12) {
            $completed = array_slice($completed, -12);
        }

        return $completed;
    }

    /**
     * Whether a skill has already run successfully in this thread's completed command history.
     *
     * Used to decide from engine state — not from the wording of a planner text — whether work has already
     * happened. A command that ended in an error does not count: the retry is a real next step.
     *
     * @param array[] $completed Completed command history as returned by extract_from_messages()/merge_from_queue().
     * @param string $skill Fully qualified skill name.
     * @return bool
     */
    public function contains_skill(array $completed, string $skill): bool {
        $skill = trim($skill);
        if ($skill === '') {
            return false;
        }

        foreach ($completed as $entry) {
            if (!is_array($entry) || trim((string)($entry['skill'] ?? '')) !== $skill) {
                continue;
            }
            // History rows are already filtered to successful work: extract_from_messages() keeps result
            // rows with status "executed", merge_from_queue() keeps succeeded queue items, and both drop
            // the status afterwards. A row that still carries a status is only completed when it says so.
            $status = trim((string)($entry['status'] ?? ''));
            if ($status === '' || $status === self::STATUS_EXECUTED || queue_status_policy::is_succeeded_status($status)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merge queue-sourced executed commands into completed command history.
     *
     * @param int $threadid
     * @param array[] $existing
     * @param bool $withcreated Add the internal key _created_at (creation time of the queue item).
     * @return array[]
     */
    public function merge_from_queue(int $threadid, array $existing, bool $withcreated = false): array {
        if ($threadid <= 0) {
            return $existing;
        }

        $manager = new queue_manager($this->store);
        $queueitems = $manager->get_queue_items($threadid);
        if (empty($queueitems)) {
            return $existing;
        }

        $queuecompleted = [];
        $seen = [];

        foreach ($queueitems as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((int)($item['thread_id'] ?? 0) !== $threadid) {
                continue;
            }

            if (!queue_status_policy::is_succeeded_status((string)($item['status'] ?? ''))) {
                continue;
            }

            $skill = trim((string)($item['skill'] ?? ''));
            // Placeholders are planning artifacts only — they were never executed.
            // Excluding them prevents the synchronizer from reporting unexecuted steps as done.
            if ($skill === '' || $skill === '__placeholder__') {
                continue;
            }

            $input = [];
            if (is_array($item['input'] ?? null)) {
                $input = (array)$item['input'];
            } else if (is_array($item['prepared_input'] ?? null)) {
                $input = (array)$item['prepared_input'];
            }

            $compact = ['skill' => $skill];
            $normalizedinput = input_normalizer::normalize($input, self::COMPACT_OPTS);
            if (!empty($normalizedinput)) {
                $compact['input'] = $normalizedinput;
            }

            $signature = $this->build_signature($compact);
            if ($signature === '' || isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            if ($withcreated) {
                $compact['_created_at'] = (int)($item['created_at'] ?? 0);
            }
            $queuecompleted[] = $compact;
        }

        // Queue is authoritative for completed mutation history in the current thread.
        // Only if no succeeded queue items exist, fall back to message-derived evidence.
        $merged = !empty($queuecompleted) ? $queuecompleted : $existing;

        if (count($merged) > 12) {
            $merged = array_slice($merged, -12);
        }

        return $merged;
    }

    /**
     * Build a deterministic signature for completed command deduplication.
     *
     * @param array $command
     * @return string
     */
    private function build_signature(array $command): string {
        $skill = trim((string)($command['skill'] ?? ''));
        if ($skill === '') {
            return '';
        }

        $input = [];
        if (is_array($command['input'] ?? null)) {
            $input = (array)$command['input'];
        }

        ksort($input);
        $json = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            $json = '{}';
        }

        return hash('sha256', $skill . '|' . $json);
    }
}
