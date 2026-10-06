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

use core_text;

/**
 * Prompt profile helper for explicit planner phases and config-key handling.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class orchestrator_prompt_profile_service {
    /** Selection planner phase. */
    public const PHASE_SELECTION = 'selection';

    /** Parameter construction planner phase. */
    public const PHASE_PARAMETER_CONSTRUCTION = 'parameter_construction';

    /**
     * Number of most-recent conversation messages kept in the planner/synchronizer prompt.
     *
     * The first user message (original request) is preserved on top of this tail when it would
     * otherwise drop out — see {@see self::select_history_messages()}. ~7 user/assistant turns;
     * single tunable point.
     */
    public const HISTORY_TAIL_LIMIT = 14;

    /**
     * Detect whether observations only contain framework-authored retry hints.
     *
     * @param array $observations
     * @return bool
     */
    public function observations_are_framework_retry_hints(array $observations): bool {
        $seen = false;

        foreach ($observations as $observation) {
            $text = trim((string)$observation);
            if ($text === '') {
                continue;
            }

            $seen = true;
            if (!str_starts_with($text, 'RETRY_HINT:')) {
                return false;
            }
        }

        return $seen;
    }

    /**
     * Resolve admin setting key per explicit planner phase.
     *
     * @param string $phase
     * @return string
     */
    public function get_planner_initial_prompt_config_key_for_phase(string $phase): string {
        $normalizedphase = $this->normalize_phase($phase);
        if ($normalizedphase === self::PHASE_SELECTION) {
            return 'aiinitialprompt_selection';
        }

        return 'aiinitialprompt_parameter_construction';
    }

    /**
     * Return the recent-history tail size for a planner phase.
     *
     * Phase-independent for now; kept as a seam so a per-phase tail size can be introduced
     * here without touching call sites. The actual message selection (which also preserves
     * the original request) lives in {@see self::select_history_messages()}.
     *
     * @param string $phase
     * @return int
     */
    public function get_history_limit_for_phase(string $phase): int {
        return self::HISTORY_TAIL_LIMIT;
    }

    /**
     * Select the conversation messages to inject into a planner/synchronizer prompt.
     *
     * Keeps the most recent {@see self::get_history_limit_for_phase()} messages and additionally
     * preserves the very first user message (the original request) when it would otherwise fall
     * outside that tail window — so long clarification threads never lose the originating ask.
     * Tool results/observations are injected separately and are unaffected by this windowing.
     *
     * @param \stdClass[] $messages Conversation messages, oldest-first, without 'step' rows.
     * @param string $phase
     * @return \stdClass[]
     */
    public function select_history_messages(array $messages, string $phase): array {
        $messages = array_values($messages);
        $limit = $this->get_history_limit_for_phase($phase);
        if ($limit <= 0 || count($messages) <= $limit) {
            return $messages;
        }

        $tail = array_slice($messages, -$limit);

        // Find the original request: the first user-authored message.
        $firstuserindex = null;
        foreach ($messages as $index => $msg) {
            if ((string)($msg->role ?? '') === 'user') {
                $firstuserindex = $index;
                break;
            }
        }

        // No user message, or it already sits inside the tail window: tail is complete as-is.
        if ($firstuserindex === null || $firstuserindex >= count($messages) - $limit) {
            return $tail;
        }

        return array_merge([$messages[$firstuserindex]], $tail);
    }

    /**
     * Treat empty or legacy full-template values as unset config for prompt fallback.
     *
     * @param string $template
     * @param string $legacydefault
     * @return string
     */
    public function normalize_config_prompt_template(string $template, string $legacydefault): string {
        // Values saved through the admin form carry CRLF line endings while the
        // heredoc defaults use LF — a seeded default must still count as default.
        $trimmed = trim(str_replace(["\r\n", "\r"], "\n", $template));
        if ($trimmed === '') {
            return '';
        }
        if ($trimmed === trim(str_replace(["\r\n", "\r"], "\n", $legacydefault))) {
            return '';
        }
        return $template;
    }

    /**
     * Normalize external phase labels to supported planner phases.
     *
     * @param string $phase
     * @return string
     */
    private function normalize_phase(string $phase): string {
        $normalized = trim(core_text::strtolower($phase));
        if ($normalized === self::PHASE_SELECTION) {
            return self::PHASE_SELECTION;
        }
        if ($normalized === self::PHASE_PARAMETER_CONSTRUCTION) {
            return self::PHASE_PARAMETER_CONSTRUCTION;
        }
        return self::PHASE_SELECTION;
    }
}
