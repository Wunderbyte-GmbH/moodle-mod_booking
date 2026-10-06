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

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\conversation_store;
use core_text;

/**
 * Central language authority for runtime/decision framework responses.
 *
 * The turn language is determined by the selector LLM call (it emits user_lang, derived from the
 * latest user message) and carried in the planner result. The REPLY language of the synchronizer additionally has
 * gravity (George 2026-09-27): it is kept per thread, an answer to a waiting question keeps it and only a new request
 * may change it - see resolve_reply_language().
 *
 * Policy order:
 * 1) selector-emitted user_lang (from the latest user request)
 * 2) model lang
 * 3) current UI language
 * 4) technical fallback en
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class language_policy_service {
    /** @var string */
    private const TECHNICAL_FALLBACK_LANG = 'en';

    /**
     * Normalize a language value to ISO-639-1 lowercase or empty string.
     *
     * @param string $value
     * @return string
     */
    public function normalize_iso_language(string $value): string {
        $value = trim(core_text::strtolower($value));
        if ($value === '') {
            return '';
        }

        $value = substr($value, 0, 2);
        return preg_match('/^[a-z]{2}$/', $value) === 1 ? $value : '';
    }

    /**
     * Resolve output language via the shared authority order.
     *
     * @param array $result Planner/selection result carrying the selector's user_lang.
     * @return string
     */
    public function resolve_output_language(array $result): string {
        $candidates = [
            $this->normalize_iso_language((string)($result['user_lang'] ?? '')),
            $this->normalize_iso_language((string)($result['lang'] ?? '')),
            $this->normalize_iso_language((string)current_language()),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return self::TECHNICAL_FALLBACK_LANG;
    }

    /** @var string Thread metadata key of the conversation's reply language. */
    public const THREAD_REPLY_LANGUAGE_KEY = 'reply_language';

    /** @var string[] Response types after which the user's next message answers something waiting. */
    private const WAITING_RESPONSE_TYPES = ['clarification', 'confirmation_request'];

    /**
     * The selector's language of the user's message: an ISO code Moodle knows, or ''.
     *
     * Only the selection phase counts: the constructor's user_lang is not a judgement of the user's message (L45 URT-3:
     * selector fr, constructor de).
     *
     * @param array $result Planner result.
     * @return string
     */
    public function selector_language(array $result): string {
        $selection = (array)($result['planner_result']['selection'] ?? []);
        // The selection state keeps the selector's parsed output under phase_output (thread 15788); a top-level value
        // is used where a path sets one.
        $output = (array)($selection['phase_output'] ?? []);
        $candidates = [$selection['user_lang'] ?? '', $output['user_lang'] ?? '', $selection['lang'] ?? '', $output['lang'] ?? ''];
        foreach ($candidates as $value) {
            $code = $this->known_language((string)$value);
            if ($code !== '') {
                return $code;
            }
        }
        return '';
    }

    /**
     * The language the synchronizer writes this turn's reply in, or '' when nothing is known.
     *
     * The first request sets the conversation's language. A message that answers something waiting - the previous reply
     * asked a question or a confirmation - keeps it, whatever the selector says about a short "Yes". Any other message
     * is a new request: the selector's language applies and becomes the conversation's language. Engine state only.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @param array $result Planner result of this turn.
     * @return string ISO 639-1 code or ''.
     */
    public function resolve_reply_language(conversation_store $store, int $threadid, array $result): string {
        $threadlanguage = $this->known_language((string)$store->get_thread_metadata_value(
            $threadid,
            self::THREAD_REPLY_LANGUAGE_KEY
        ));
        if ($threadlanguage !== '' && $this->message_answers_waiting($store, $threadid)) {
            return $threadlanguage;
        }
        $selector = $this->selector_language($result);
        if ($selector === '') {
            return $threadlanguage;
        }
        if ($selector !== $threadlanguage) {
            $store->set_thread_metadata_value($threadid, self::THREAD_REPLY_LANGUAGE_KEY, $selector);
        }
        return $selector;
    }

    /**
     * The line the synchronizer's runtime state carries for a reply language, or '' for none.
     *
     * @param string $code ISO 639-1 code.
     * @return string
     */
    public function reply_language_line(string $code): string {
        $code = $this->known_language($code);
        if ($code === '') {
            return '';
        }
        $names = get_string_manager()->get_list_of_languages('en');
        return 'REPLY LANGUAGE: ' . $code . ' (' . ($names[$code] ?? $code) . '), the language of this conversation.';
    }

    /**
     * Whether the user's latest message answers a question or confirmation of the previous reply.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @return bool
     */
    private function message_answers_waiting(conversation_store $store, int $threadid): bool {
        $messages = $store->get_messages($threadid);
        usort($messages, static fn($a, $b): int => (int)$a->id <=> (int)$b->id);
        $seenuser = false;
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $role = (string)($messages[$i]->role ?? '');
            if (!$seenuser) {
                $seenuser = $role === 'user';
                continue;
            }
            if ($role === 'assistant') {
                $structured = json_decode((string)($messages[$i]->structuredjson ?? ''), true);
                return in_array((string)($structured['response_type'] ?? ''), self::WAITING_RESPONSE_TYPES, true);
            }
            if ($role === 'user') {
                return false;
            }
        }
        return false;
    }

    /**
     * An ISO 639-1 code Moodle lists as a language, or ''.
     *
     * @param string $value
     * @return string
     */
    private function known_language(string $value): string {
        $code = $this->normalize_iso_language($value);
        if ($code === '') {
            return '';
        }
        return array_key_exists($code, get_string_manager()->get_list_of_languages('en')) ? $code : '';
    }

    /**
     * Resolve framework fallback string id by response type.
     *
     * @param string $responsetype
     * @return string
     */
    public function fallback_string_id_for_response_type(string $responsetype): string {
        $responsetype = trim($responsetype);
        if ($responsetype === 'error') {
            return 'ai_fallback_error';
        }
        if ($responsetype === 'confirmation_request') {
            return 'ai_fallback_confirmation_request';
        }
        if ($responsetype === 'skill_call') {
            return 'ai_fallback_skill_call';
        }

        return 'ai_fallback_summary';
    }

    /**
     * String id for deterministic preflight retry hint text.
     *
     * @return string
     */
    public function preflight_retry_hint_string_id(): string {
        return 'ai_preflight_retry_hint';
    }
}
