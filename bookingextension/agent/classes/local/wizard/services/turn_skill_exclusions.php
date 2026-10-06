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

/**
 * Skills excluded for the rest of the current turn.
 *
 * Wave 32 (frozen prompt spec, code prerequisite 1): when the construction states `"skill_fits": false`, the rejected
 * skill must not be selected again in this turn - the old retry hint only asked for "a DIFFERENT skill" in words while
 * the skill stayed in the catalog (A -> B -> A was possible). The exclusion is bound to the latest user message, so it
 * ends with the turn; the skill stays in the available catalog.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class turn_skill_exclusions {
    /** Thread metadata key. */
    public const METADATA_KEY = 'turn_excluded_skills';

    /**
     * Exclude a skill for the rest of the current turn.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @param string $skill
     * @param string $reason The construction's one-sentence reason (shown if the skill is picked again).
     * @return void
     */
    public static function exclude(conversation_store $store, int $threadid, string $skill, string $reason = ''): void {
        $skill = trim($skill);
        if ($skill === '') {
            return;
        }
        $messageid = self::latest_user_message_id($store->get_messages($threadid));
        $reasons = self::reasons($store, $threadid);
        $reasons[$skill] = trim($reason);
        $store->set_thread_metadata_value(
            $threadid,
            self::METADATA_KEY,
            (string)json_encode(['message' => $messageid, 'reasons' => $reasons])
        );
    }

    /**
     * The skills excluded for the current turn (the latest user message).
     *
     * @param conversation_store $store
     * @param int $threadid
     * @return string[]
     */
    public static function excluded(conversation_store $store, int $threadid): array {
        return array_keys(self::reasons($store, $threadid));
    }

    /**
     * The excluded skills of the current turn with the construction's reason for each.
     *
     * @param conversation_store $store
     * @param int $threadid
     * @return array<string,string>
     */
    public static function reasons(conversation_store $store, int $threadid): array {
        $decoded = json_decode((string)$store->get_thread_metadata_value($threadid, self::METADATA_KEY), true);
        if (!is_array($decoded)) {
            return [];
        }
        $messageid = self::latest_user_message_id($store->get_messages($threadid));
        if ($messageid <= 0 || (int)($decoded['message'] ?? 0) !== $messageid) {
            return [];
        }
        $reasons = [];
        foreach ((array)($decoded['reasons'] ?? []) as $skill => $reason) {
            if (trim((string)$skill) !== '') {
                $reasons[(string)$skill] = (string)$reason;
            }
        }
        return $reasons;
    }

    /**
     * The catalog without the excluded skills.
     *
     * @param array $catalog
     * @param string[] $excluded
     * @return array
     */
    public static function filter_catalog(array $catalog, array $excluded): array {
        if (empty($excluded)) {
            return $catalog;
        }
        return array_values(array_filter(
            $catalog,
            static fn($entry): bool => !in_array(trim((string)($entry['skill'] ?? '')), $excluded, true)
        ));
    }

    /**
     * Id of the latest user message, or 0.
     *
     * @param array $messages Oldest first.
     * @return int
     */
    private static function latest_user_message_id(array $messages): int {
        foreach (array_reverse($messages) as $message) {
            if (($message->role ?? '') === 'user') {
                return (int)($message->id ?? 0);
            }
        }
        return 0;
    }
}
