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
 * Cross-language bridge for skill discovery: normalise the embedding query to English.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\llm;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\orchestrator_routing_service;
use bookingextension_agent\local\wizard\wb_action_names;
use core\di;
use core_ai\aiactions\generate_text;
use core_ai\manager as ai_manager;

/**
 * Translates the discovery query to English before it is embedded (SKILL_REWORK.md §5.7, "Weg B").
 *
 * Skill anchors (description + example_utterances) are English-only, but user queries can be in any
 * language. With a non-multilingual embedding model a German query like "Buche Anna fuer den Kurs ..."
 * mis-matches (the noun "Kurs" is pulled to course.search_courses) instead of the English book_users
 * anchors. Normalising the query to English first puts query and anchors in the same language, so the
 * cross-language gap disappears regardless of the embedding model.
 *
 * Scope: ONLY the embedding query is normalised — the conversation and the planner prompts stay in the
 * user's language (the synchronizer still answers in it). This is a pre-embedding normalisation step,
 * NOT lexical routing (it adds no keyword/substring matching).
 *
 * Fail-open: on any error, an empty/over-long result, or a missing provider, the original query is
 * returned unchanged, so discovery degrades to the previous behaviour rather than breaking.
 */
class query_english_normalizer {
    /** @var llm_call_service */
    private llm_call_service $llm;

    /**
     * Constructor.
     *
     * @param llm_call_service|null $llm
     */
    public function __construct(?llm_call_service $llm = null) {
        $this->llm = $llm ?? new llm_call_service(new conversation_store());
    }

    /**
     * Return an English version of the query for embedding. Idempotent for English input; fail-open.
     *
     * @param string $query   The raw user/discovery query.
     * @param int $contextid
     * @param int $userid
     * @param int $threadid    Thread id for debug logging (0 for internal lookups).
     * @return string English query, or the original query if normalisation is unavailable/failed.
     */
    public function to_english(string $query, int $contextid, int $userid, int $threadid = 0): string {
        $query = trim($query);
        if ($query === '') {
            return $query;
        }

        // Protect quoted titles / proper-name spans deterministically: the model otherwise sometimes
        // TRANSLATES them (e.g. "Erste Hilfe Grundkurs" -> "First Aid Basic Course"), which then no
        // longer resolves the real entity and makes discovery vary run-to-run. Replace each quoted span
        // with a stable sentinel, translate around it, then restore the original verbatim.
        $protected = [];
        $placeheld = preg_replace_callback(
            '/"[^"]*"|\x{201E}[^\x{201C}]*\x{201C}|\x{00AB}[^\x{00BB}]*\x{00BB}/u',
            static function (array $m) use (&$protected): string {
                $token = '<<KEEP' . count($protected) . '>>';
                $protected[] = $m[0];
                return $token;
            },
            $query
        );
        if (!is_string($placeheld)) {
            $placeheld = $query;
            $protected = [];
        }

        // Runs on the small planner action (flowchart QNORM), core generate_text without it. No model name is
        // hardcoded: the provider instance decides which model serves the action.
        $prompt = "You are a translation function inside a search system. Translate the text after 'TEXT:' "
            . "into English. Output ONLY the English translation as plain text — no quotes, no labels, no "
            . "commentary. If it is already English, output it unchanged. Keep proper names and numbers "
            . "verbatim, and keep any <<KEEP...>> token EXACTLY as-is.\n\nTEXT:\n" . $placeheld;

        try {
            $actionclass = (new orchestrator_routing_service(wb_action_names::PLANNER_DECIDE))
                ->resolve_query_normalizer_action_class(di::get(ai_manager::class));
            $result = $this->llm->invoke_for_context(
                $threadid,
                $contextid,
                $userid,
                'orc|p=disc|st=qnorm|ac=' . ($actionclass === generate_text::class ? 'txt' : 'wpl') . '|rt=wb',
                $prompt,
                $actionclass
            );
        } catch (\Throwable $e) {
            return $query;
        }

        if (empty($result['success'])) {
            return $query;
        }

        $out = trim((string)($result['rawcontent'] ?? ''));
        // Strip wrapping quotes a model may add despite the instruction.
        $out = trim($out, "\"'");
        $out = trim($out);
        if ($out === '') {
            return $query;
        }

        // Restore the protected quoted spans verbatim. If the model dropped/altered a sentinel, the
        // translation is unreliable -> fall back to the raw query (fail-open).
        foreach ($protected as $i => $original) {
            $token = '<<KEEP' . $i . '>>';
            if (strpos($out, $token) === false) {
                return $query;
            }
            $out = str_replace($token, $original, $out);
        }

        // Guard against a degenerate response (the model rambled instead of translating): fall back.
        if (\core_text::strlen($out) > \core_text::strlen($query) * 6 + 200) {
            return $query;
        }

        return $out;
    }
}
