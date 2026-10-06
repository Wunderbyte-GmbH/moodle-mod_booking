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
 * Audit event facade for skill execution and denials.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\telemetry;

use core\context;
use bookingextension_agent\event\action_confirmed;
use bookingextension_agent\event\action_denied;
use bookingextension_agent\event\skill_executed;
use bookingextension_agent\event\skill_write_executed;

/**
 * Builds and triggers the agent's audit events from the executor chokepoint.
 *
 * Keeps event construction, argument redaction and the config gating out of the executor so it
 * stays skill-agnostic. Every method is fail-safe: audit logging must never break execution, so
 * all work is wrapped and any error is downgraded to a developer-debug notice.
 *
 * Gating:
 *  - `logskillexecution` (default on) is the master switch for both events.
 *  - `logreadskills` (default on) additionally suppresses {@see skill_executed} for read-only
 *    skills only; writes and denials are always logged while the master switch is on.
 */
class audit_logger {
    /** @var int Maximum characters kept per redacted string argument. */
    private const ARG_MAX_LEN = 200;

    /** @var int Maximum number of argument keys echoed into an event. */
    private const ARG_MAX_KEYS = 25;

    /**
     * Record that a skill executed (any outcome).
     *
     * @param object $skill      the skill instance that ran
     * @param string $skillname  registry name (e.g. course.update_activity)
     * @param array  $input      the raw input passed to execute()
     * @param mixed  $result     the skill's return value (used to derive the outcome)
     * @param int    $contextid  operating context the skill ran at
     * @param int    $userid     acting user
     * @param int    $threadid   conversation/channel thread id (0 when none)
     * @param int    $runid      run bookkeeping id
     * @param string $channel    entrypoint: chat | mcp | api
     * @param float  $durationms wall-clock duration of execute() in milliseconds
     */
    public static function skill_executed(
        object $skill,
        string $skillname,
        array $input,
        $result,
        int $contextid,
        int $userid,
        int $threadid,
        int $runid,
        string $channel,
        float $durationms
    ): void {
        try {
            if (!self::master_enabled()) {
                return;
            }
            $readonly = self::skill_is_read_only($skill);
            if ($readonly && !self::read_logging_enabled()) {
                return;
            }
            $context = context::instance_by_id($contextid, IGNORE_MISSING);
            if (!$context) {
                return;
            }
            $crud = self::skill_crud($skill, $readonly);
            $related = self::related_userids($skill, $input);
            $other = [
                'skill' => $skillname,
                'channel' => $channel,
                'readonly' => $readonly,
                'crud' => $crud,
                'riskclass' => self::skill_risk_class($skill),
                'outcome' => self::derive_outcome($result),
                'threadid' => $threadid,
                'runid' => $runid,
                'durationms' => (int)round($durationms),
                'args' => self::redact_args($skill, $input),
            ];
            if (!empty($related)) {
                $other['relateduserids'] = array_values($related);
            }
            $data = [
                'context' => $context,
                'userid' => $userid,
                'other' => $other,
            ];
            if (!empty($related)) {
                // Core carries a single relateduserid; the full list stays in other.
                $data['relateduserid'] = (int)reset($related);
            }
            // Reads and writes are distinct event classes so the log CRUD column separates them.
            $eventclass = $readonly ? skill_executed::class : skill_write_executed::class;
            $eventclass::create($data)->trigger();
        } catch (\Throwable $e) {
            debugging('bookingextension_agent audit_logger::skill_executed failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Record that a user confirmed a pending mutating action at the confirm gate.
     *
     * @param string $skillname registry name of the confirmed skill
     * @param int    $contextid context the confirmation was given at
     * @param int    $userid    acting (confirming) user
     * @param int    $threadid  conversation/channel thread id (0 when none)
     * @param int    $runid     run bookkeeping id
     * @param string $channel   entrypoint: chat | mcp | api
     */
    public static function action_confirmed(
        string $skillname,
        int $contextid,
        int $userid,
        int $threadid,
        int $runid,
        string $channel
    ): void {
        try {
            if (!self::master_enabled()) {
                return;
            }
            $context = context::instance_by_id($contextid, IGNORE_MISSING);
            if (!$context) {
                return;
            }
            action_confirmed::create([
                'context' => $context,
                'userid' => $userid,
                'other' => [
                    'skill' => $skillname,
                    'channel' => $channel,
                    'threadid' => $threadid,
                    'runid' => $runid,
                ],
            ])->trigger();
        } catch (\Throwable $e) {
            debugging('bookingextension_agent audit_logger::action_confirmed failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Record that a skill was denied at a security gate before it could run.
     *
     * @param string      $skillname registry name
     * @param string      $gate      gate identifier: governance | guard | native_capability
     * @param string      $reason    machine reason/deny code
     * @param int         $contextid context the denial happened at
     * @param int         $userid    acting user
     * @param int         $threadid  conversation/channel thread id (0 when none)
     * @param int         $runid     run bookkeeping id
     * @param string      $channel   entrypoint: chat | mcp | api
     * @param object|null $skill     the skill instance when available (for risk class)
     */
    public static function action_denied(
        string $skillname,
        string $gate,
        string $reason,
        int $contextid,
        int $userid,
        int $threadid,
        int $runid,
        string $channel,
        ?object $skill = null
    ): void {
        try {
            if (!self::master_enabled()) {
                return;
            }
            $context = context::instance_by_id($contextid, IGNORE_MISSING);
            if (!$context) {
                return;
            }
            action_denied::create([
                'context' => $context,
                'userid' => $userid,
                'other' => [
                    'skill' => $skillname,
                    'gate' => $gate,
                    'reason' => $reason,
                    'channel' => $channel,
                    'riskclass' => $skill ? self::skill_risk_class($skill) : '',
                    'threadid' => $threadid,
                    'runid' => $runid,
                ],
            ])->trigger();
        } catch (\Throwable $e) {
            debugging('bookingextension_agent audit_logger::action_denied failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Master switch: are agent audit events enabled at all?
     *
     * @return bool
     */
    private static function master_enabled(): bool {
        $value = get_config('bookingextension_agent', 'logskillexecution');
        // Default on when unset (fresh install before the setting is saved).
        return $value === false || (int)$value === 1;
    }

    /**
     * Whether read-only skill executions are logged (in addition to the master switch).
     *
     * @return bool
     */
    private static function read_logging_enabled(): bool {
        $value = get_config('bookingextension_agent', 'logreadskills');
        return $value === false || (int)$value === 1;
    }

    /**
     * Duck-typed read-only flag.
     *
     * @param object $skill
     * @return bool
     */
    private static function skill_is_read_only(object $skill): bool {
        return method_exists($skill, 'is_read_only') ? (bool)$skill->is_read_only() : false;
    }

    /**
     * Precise CRUD letter for the log payload (r|c|u|d).
     *
     * @param object $skill
     * @param bool   $readonly
     * @return string
     */
    private static function skill_crud(object $skill, bool $readonly): string {
        if (method_exists($skill, 'get_log_crud')) {
            $crud = strtolower(trim((string)$skill->get_log_crud()));
            if (in_array($crud, ['r', 'c', 'u', 'd'], true)) {
                return $crud;
            }
        }
        return $readonly ? 'r' : 'u';
    }

    /**
     * Declared risk class, or empty string when unavailable.
     *
     * @param object $skill
     * @return string
     */
    private static function skill_risk_class(object $skill): string {
        return method_exists($skill, 'get_risk_class') ? (string)$skill->get_risk_class() : '';
    }

    /**
     * Related user ids the action concerns (e.g. a booked/looked-up user), duck-typed.
     *
     * @param object $skill
     * @param array  $input
     * @return int[]
     */
    private static function related_userids(object $skill, array $input): array {
        if (!method_exists($skill, 'get_related_userids')) {
            return [];
        }
        $ids = [];
        foreach ((array)$skill->get_related_userids($input) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /**
     * Map a skill result to a coarse outcome label.
     *
     * @param mixed $result
     * @return string success | error | skipped | unknown
     */
    private static function derive_outcome($result): string {
        if (!is_array($result)) {
            return 'success';
        }
        $status = strtolower(trim((string)($result['status'] ?? '')));
        if ($status === 'error') {
            return 'error';
        }
        if ($status === 'skipped') {
            return 'skipped';
        }
        if (!empty($result['issue_codes']) || !empty($result['error'])) {
            return 'error';
        }
        return 'success';
    }

    /**
     * Produce a small, non-sensitive echo of the input for the event payload.
     *
     * Keeps only schema-declared keys, drops fields the skill marks sensitive, and truncates
     * long strings. Never emits raw prompts or credentials into the log store.
     *
     * @param object $skill
     * @param array  $input
     * @return array
     */
    private static function redact_args(object $skill, array $input): array {
        $allowedkeys = null;
        if (method_exists($skill, 'get_schema')) {
            $schema = (array)$skill->get_schema();
            $allowedkeys = array_fill_keys(array_keys((array)($schema['properties'] ?? [])), true);
        }
        $sensitive = [];
        if (method_exists($skill, 'get_sensitive_input_fields')) {
            foreach ((array)$skill->get_sensitive_input_fields() as $field) {
                $sensitive[(string)$field] = true;
            }
        }

        $safe = [];
        $count = 0;
        foreach ($input as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            if ($allowedkeys !== null && !isset($allowedkeys[$key])) {
                continue;
            }
            if (isset($sensitive[$key])) {
                continue;
            }
            if ($count >= self::ARG_MAX_KEYS) {
                $safe['_truncated'] = true;
                break;
            }
            $safe[$key] = self::redact_value($value);
            $count++;
        }
        return $safe;
    }

    /**
     * Redact a single argument value (scalars truncated, containers summarised).
     *
     * @param mixed $value
     * @return mixed
     */
    private static function redact_value($value) {
        if (is_string($value)) {
            return \core_text::strlen($value) > self::ARG_MAX_LEN
                ? \core_text::substr($value, 0, self::ARG_MAX_LEN) . '…'
                : $value;
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        if (is_array($value)) {
            return '[' . count($value) . ' items]';
        }
        return '[object]';
    }
}
