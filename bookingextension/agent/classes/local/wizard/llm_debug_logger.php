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
 * Central helper to persist raw LLM exchanges in booking debug mode.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

/**
 * LLM debug logger.
 */
class llm_debug_logger {
    /**
     * Whether LLM debug logging is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        global $CFG;

        return !empty(get_config('bookingextension_agent', 'aidebugmode'));
    }

    /**
     * Persist one raw request/response exchange.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @param int $cmid
     * @param int $userid
     * @param string $source
     * @param string $requesttext
     * @param string $responsetext
     * @param bool $success
     * @param string $errormessage
     * @return void
     */
    public static function log_exchange(
        conversation_store $store,
        int $threadid,
        int $cmid,
        int $userid,
        string $source,
        string $requesttext,
        string $responsetext,
        bool $success,
        string $errormessage = ''
    ): void {
        // Self-gate: nothing is persisted unless aidebugmode is on (audit 15-F01). This is the only
        // path to bx_agent_ai_llm_debug from the engine, so the table stays empty in normal operation.
        if (!self::is_enabled()) {
            return;
        }

        // A debug log is a side channel: it must never decide the outcome of the user's turn. F72
        // (baseline runs 25-27) was exactly that — a column overflow in this insert surfaced as
        // "Fehler beim Schreiben der Datenbank" to the user, after the LLM had already answered.
        try {
            $store->add_llm_debug_entry(
                $threadid,
                $userid,
                $cmid,
                $source,
                $requesttext,
                $responsetext,
                $success ? 1 : 0,
                $errormessage
            );
        } catch (\Throwable $e) {
            debugging('bookingextension_agent: LLM debug entry not stored: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
