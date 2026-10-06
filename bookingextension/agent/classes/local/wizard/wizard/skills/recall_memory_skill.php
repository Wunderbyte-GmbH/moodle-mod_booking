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

namespace bookingextension_agent\local\wizard\wizard\skills;

use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\observation_time;

/**
 * Skill definition for wizard.recall_memory.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recall_memory_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'wizard.recall_memory';

    /** @var int Current (target) thread id, injected by the executor before execute(). */
    private int $runtimethreadid = 0;

    /**
     * @var int Most messages a date window may recall.
     *
     * Run-22 finding F68: the window merged every message of every thread in it. One day of
     * baseline runs produced 2272 messages (853 KB) in a single observation, which the anonymizer
     * could not process inside max_execution_time - the user got an HTTP 500 instead of an answer.
     * A busy day is normal, so the skill keeps the most recent messages and reports that it cut.
     */
    public const WINDOW_MESSAGE_LIMIT = 200;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Receive the current thread id from the executor (duck-typed).
     *
     * Recalled memory carries placeholders minted under the SOURCE thread's token map; we need the
     * current thread id to re-anchor them into this thread's map so they de-anonymize on display.
     *
     * @param int $threadid
     * @return void
     */
    public function set_runtime_threadid(int $threadid): void {
        $this->runtimethreadid = $threadid;
    }

    /**
     * Return skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        $schema = [
            'version' => 1,
            'description' => 'Recall previous user-only conversation memory. Use mode="last_thread" for requests like "last '
                . 'time/yesterday" and mode="date_window" only for date-specific requests. When mode="date_window", '
                // Wave 32 (A3): e08b63b left the old tail "mandatory." glued to the new sentence, so every card
                // since L36 read "date_from is an ISO date.mandatory." (constructor request of thread 10894).
                . 'date_from is a mandatory ISO date. User isolation is strict and userid is never accepted from input.',
            'is' => 'Previous conversation.',
            'not' => 'Facts the user asked the agent to remember (list_memories, remember, forget).',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_recall_memory',
            'fallback_skillcall_string_key' => 'ai_status_skillcall_booking_recall_memory',
            'example_utterances' => [
                'what did we talk about last time',
                'what did we discuss yesterday',
                'remind me what we covered last Friday',
                'show me that document we looked at earlier',
                'did we discuss user X before',
            ],
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'description' => 'Retrieval mode. Set to "last_thread" for "last discussion" requests. '
                        . 'Set to "date_window" only when the user asks for a specific day/date range.',
                    'enum' => ['last_thread', 'date_window'],
                    'required' => true,
                ],
                'date_from' => [
                    'type' => 'string',
                    'description' => 'First day of the window as an ISO date (YYYY-MM-DD), computed from now_iso '
                        . '("last Friday" is a date, not a phrase). Required when mode="date_window"; omit for '
                        . 'mode="last_thread".',
                    'required' => false,
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'Last day of the window as an ISO date (YYYY-MM-DD); omit for a single day.',
                    'required' => false,
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional keyword/topic filter for message content or structured payload.',
                    'required' => false,
                ],
                'include_structured' => [
                    'type' => 'boolean',
                    'description' => 'Include decoded structured payloads in returned messages.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'intent' => 'Recall earlier conversation memory for the same user. '
                    . 'Choose mode="last_thread" for generic "remember last discussion" requests. '
                    . 'Choose mode="date_window" only when a concrete day or range is meant and then always include '
                    . 'date_from as an ISO date.',
                'input_fields_for_prompt' => ['mode'],
                'anchor_fields' => ['date_from', 'query'],
                'capabilities' => ['conversation_memory_recall', 'date_window_lookup'],
                // Reads the USER's own conversation history; was implicitly defaulting
                // to ['module'] via base_skill — declared honestly as user-scoped.
                'context_scopes' => ['user'],
            ],
        ];

        return $this->enrich_schema_with_prompt_meta($schema);
    }

    /**
     * Return deterministic example input for planner contract rendering.
     *
     * @return array
     */
    public function get_example_input(): array {
        return [
            'mode' => 'last_thread',
        ];
    }

    /**
     * Check skill input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $issuecodes = [];
        $mode = trim((string)($input['mode'] ?? ''));
        if (!in_array($mode, ['last_thread', 'date_window'], true)) {
            $errors[] = get_string('agent_booking_recall_memory_invalid_mode', 'bookingextension_agent');
            $issuecodes[] = 'RECOVERABLE_INPUT_ERROR';
        }
        if ($mode === 'date_window') {
            // F80 (wave 26): dates arrive as ISO fields, not as a phrase in some language the skill would
            // have to understand ("vendredi dernier" failed three runs in a row against an English parser).
            $from = trim((string)($input['date_from'] ?? ''));
            $to = trim((string)($input['date_to'] ?? ''));
            if ($from === '') {
                $errors[] = get_string('agent_booking_recall_memory_date_from_required', 'bookingextension_agent');
                $issuecodes[] = 'RECOVERABLE_INPUT_ERROR';
            } else if (!self::is_iso_date($from) || ($to !== '' && !self::is_iso_date($to))) {
                $errors[] = get_string('agent_booking_recall_memory_invalid_date', 'bookingextension_agent');
                $issuecodes[] = 'RECOVERABLE_INPUT_ERROR';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
            'issue_codes' => array_values(array_unique($issuecodes)),
        ];
    }

    /**
     * Return skill-specific message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'wizard.recall_memory_last_time',
                'description' => 'User asks what was discussed previously.',
                'examples' => [
                    'what did we talk about last time',
                    'can you remember what we discussed yesterday?',
                    'didn\'t we talk about user x',
                ],
            ],
            [
                'id' => 'wizard.recall_memory_date_window',
                'description' => 'User asks for memory in a date window.',
                'examples' => [
                    'what did we talk about last friday',
                    'show me that document again',
                ],
            ],
        ];
    }

    /**
     * Execute skill.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $store = new conversation_store();
        $mode = trim((string)($input['mode'] ?? 'last_thread'));
        $query = trim((string)($input['query'] ?? ''));
        $includestructured = !empty($input['include_structured']);

        $threadid = 0;
        $fromtimestamp = null;
        $totimestamp = null;
        $messages = [];

        if ($mode === 'last_thread') {
            $thread = $store->get_last_thread_for_user($userid, $contextid);
            $threadid = (int)($thread->id ?? 0);
            if ($threadid > 0) {
                $messages = $store->get_user_messages_for_thread($userid, $threadid, null, null, $query);
                foreach ($messages as $message) {
                    $message->sourcethreadid = $threadid;
                }
            }
        } else {
            $window = $this->resolve_date_window(
                $userid,
                trim((string)($input['date_from'] ?? '')),
                trim((string)($input['date_to'] ?? ''))
            );
            if ($window === null) {
                return [
                    'status' => 'error',
                    'detail' => get_string('agent_booking_recall_memory_invalid_date', 'bookingextension_agent'),
                    'resultid' => null,
                    'threadid' => null,
                    'from_timestamp' => null,
                    'to_timestamp' => null,
                    'messages' => [],
                    'memory_observation_text' => '',
                ];
            }

            $fromtimestamp = (int)$window['from_timestamp'];
            $totimestamp = (int)$window['to_timestamp'];
            $threadids = $store->get_user_threads_by_date_window($userid, $contextid, $fromtimestamp, $totimestamp);
            foreach ($threadids as $candidateid) {
                $threadmessages = $store->get_user_messages_for_thread(
                    $userid,
                    (int)$candidateid,
                    $fromtimestamp,
                    $totimestamp,
                    $query
                );
                if (!empty($threadmessages)) {
                    if ($threadid === 0) {
                        $threadid = (int)$candidateid;
                    }
                    foreach ($threadmessages as $message) {
                        $message->sourcethreadid = (int)$candidateid;
                    }
                    $messages = array_merge($messages, $threadmessages);
                }
            }
        }

        // Keep the newest end of the window: a recall question is about what was said, and the
        // most recent exchanges carry it. The count before cutting is kept for the answer.
        $totalfound = count($messages);
        $truncated = false;
        if ($totalfound > self::WINDOW_MESSAGE_LIMIT) {
            $messages = array_slice($messages, -self::WINDOW_MESSAGE_LIMIT);
            $truncated = true;
        }

        if (empty($messages)) {
            return [
                'status' => 'executed',
                'detail' => get_string('agent_booking_recall_memory_no_memory_found', 'bookingextension_agent'),
                'resultid' => null,
                'threadid' => $threadid > 0 ? $threadid : null,
                'from_timestamp' => $fromtimestamp,
                'to_timestamp' => $totimestamp,
                'messages' => [],
                'observation_full' => '',
                'memory_observation_text' => '',
            ];
        }

        // Recalled content was anonymized under its own (source) thread's token map. Re-anchor those
        // placeholders into the current thread's map so they resolve on display; this is token-to-token
        // only (no clear-text PII), and recall is strictly user-isolated so it never crosses users.
        $anonymizer = new privacy_anonymizer($store);

        $normalizedmessages = [];
        foreach ($messages as $message) {
            $sourcethreadid = (int)($message->sourcethreadid ?? 0);
            $reanchor = $this->runtimethreadid > 0 && $sourcethreadid > 0;

            $content = (string)($message->content ?? '');
            if ($reanchor) {
                $content = (string)$anonymizer->reanchor_value_for_thread($this->runtimethreadid, $sourcethreadid, $content);
            }

            $structured = null;
            if ($includestructured) {
                $decoded = json_decode((string)($message->structuredjson ?? ''), true);
                if (is_array($decoded)) {
                    if ($reanchor) {
                        $decoded = $anonymizer->reanchor_value_for_thread($this->runtimethreadid, $sourcethreadid, $decoded);
                    }
                    $structured = $decoded;
                }
            }

            $normalizedmessages[] = [
                'role' => (string)($message->role ?? ''),
                'content' => $content,
                'time' => (int)($message->timecreated ?? 0),
                'structured' => $structured,
            ];
        }

        $observation = $this->build_memory_observation_text($normalizedmessages, $fromtimestamp, $totimestamp);

        return [
            'status' => 'executed',
            'detail' => $truncated
                ? get_string('agent_booking_recall_memory_summary_truncated', 'bookingextension_agent', (object)[
                    'shown' => count($normalizedmessages),
                    'total' => $totalfound,
                ])
                : get_string('agent_booking_recall_memory_summary', 'bookingextension_agent', count($normalizedmessages)),
            'resultid' => null,
            'threadid' => $threadid > 0 ? $threadid : null,
            'from_timestamp' => $fromtimestamp,
            'to_timestamp' => $totimestamp,
            'messages' => $normalizedmessages,
            'observation_full' => $observation,
            'memory_observation_text' => $observation,
        ];
    }

    /**
     * Whether a value is a plain ISO date (YYYY-MM-DD).
     *
     * @param string $value
     * @return bool
     */
    private static function is_iso_date(string $value): bool {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            && \DateTimeImmutable::createFromFormat('!Y-m-d', $value) !== false;
    }

    /**
     * The day window [from 00:00, to 23:59:59] in the user's timezone, from ISO dates only.
     *
     * No phrase is parsed here: the planner knows now_iso and turns "last Friday", "vendredi dernier"
     * or "letzten Freitag" into a date before the skill is called (F80, wave 26).
     *
     * @param int $userid
     * @param string $from ISO date.
     * @param string $to ISO date or '' for a single day.
     * @return array{from_timestamp:int,to_timestamp:int}|null
     */
    private function resolve_date_window(int $userid, string $from, string $to = ''): ?array {
        if (!self::is_iso_date($from) || ($to !== '' && !self::is_iso_date($to))) {
            return null;
        }
        $timezone = $this->resolve_user_timezone($userid);
        try {
            $first = new \DateTimeImmutable($from, $timezone);
            $last = new \DateTimeImmutable($to !== '' ? $to : $from, $timezone);
        } catch (\Throwable $e) {
            return null;
        }
        if ($last < $first) {
            [$first, $last] = [$last, $first];
        }
        return [
            'from_timestamp' => $first->setTime(0, 0, 0)->getTimestamp(),
            'to_timestamp' => $last->setTime(23, 59, 59)->getTimestamp(),
        ];
    }

    /**
     * Resolve timezone for a specific user.
     *
     * @param int $userid
     * @return \DateTimeZone
     */
    private function resolve_user_timezone(int $userid): \DateTimeZone {
        $timezonename = '';

        try {
            $user = \core_user::get_user($userid, 'id,timezone');
            $timezonename = trim((string)($user->timezone ?? ''));
        } catch (\Throwable $e) {
            $timezonename = '';
        }

        if ($timezonename === '' || $timezonename === '99') {
            $timezonename = (string)(get_config('core', 'timezone') ?? '');
        }
        if ($timezonename === '' || $timezonename === '99') {
            $timezonename = date_default_timezone_get();
        }

        try {
            return new \DateTimeZone($timezonename);
        } catch (\Throwable $e) {
            return new \DateTimeZone(date_default_timezone_get());
        }
    }

    /**
     * Fields in the input that must be omitted from executed_input result echoes for privacy.
     *
     * Duck-typed by executor — skills that carry sensitive input fields declare them here
     * so the executor stays skill-agnostic.
     *
     * @return string[]
     */
    public function get_sensitive_input_fields(): array {
        return ['query'];
    }

    /**
     * Build planner-friendly previous-message observation text.
     *
     * @param array[] $messages
     * @param int|null $fromtimestamp
     * @param int|null $totimestamp
     * @return string
     */
    private function build_memory_observation_text(
        array $messages,
        ?int $fromtimestamp = null,
        ?int $totimestamp = null
    ): string {
        // Give the model temporal context: the recalled window (date_window mode) plus a readable,
        // timezone-adjusted timestamp per message, so "yesterday"/"last friday" recalls can be
        // answered with the actual when, not just the what.
        $header = '[MEMORY_CONTEXT] Historical messages from earlier discussion, not the current turn.';
        if (!empty($fromtimestamp) && !empty($totimestamp)) {
            $header .= ' (period: ' . observation_time::format((int)$fromtimestamp)
                . ' - ' . observation_time::format((int)$totimestamp) . ')';
        }
        $lines = [$header];
        $userindex = 1;
        $assistantindex = 1;

        foreach ($messages as $message) {
            $role = (string)($message['role'] ?? '');
            $content = trim((string)($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $time = (int)($message['time'] ?? 0);
            $stamp = $time > 0 ? (' · ' . observation_time::format($time)) : '';

            if ($role === 'user') {
                $lines[] = '[USER_PREVIOUS ' . $userindex . $stamp . '] ' . $content;
                $userindex++;
                continue;
            }

            if ($role === 'assistant') {
                $lines[] = '[ASSISTANT_PREVIOUS ' . $assistantindex . $stamp . '] ' . $content;
                $assistantindex++;
            }
        }

        return implode("\n", $lines);
    }
}
