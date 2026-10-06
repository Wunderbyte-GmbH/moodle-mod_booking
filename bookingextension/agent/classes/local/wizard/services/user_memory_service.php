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

/**
 * Central persistence + budget service for user-stated agent memories.
 *
 * Memories are global per user (no contextid). All persistence for the
 * bx_agent_user_memory table goes through this service — skills never
 * touch $DB directly.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_memory_service {
    /** Database table name. */
    public const TABLE = 'bx_agent_user_memory';

    /** Maximum number of memories a single user may store. */
    public const MAX_MEMORIES = 15;

    /** Maximum length (characters) of a single memory. */
    public const MAX_CHARS_PER_MEMORY = 500;

    /** Maximum total length (characters) of all memories for one user. */
    public const MAX_TOTAL_CHARS = 4096;

    /** Injection channel: planner skill selection. */
    public const SCOPE_SELECTION = 'selection';

    /** Injection channel: planner parameter construction. */
    public const SCOPE_CONSTRUCTION = 'construction';

    /** Injection channel: synchronizer / final user-facing reply. */
    public const SCOPE_SYNCHRONIZATION = 'synchronization';

    /**
     * Return the valid injection channels.
     *
     * @return string[]
     */
    public static function valid_scopes(): array {
        return [self::SCOPE_SELECTION, self::SCOPE_CONSTRUCTION, self::SCOPE_SYNCHRONIZATION];
    }

    /**
     * Add a memory for a user after normalization, dedupe and all budget checks.
     *
     * @param int $userid
     * @param string $text
     * @param string[] $scopes Injection channels this memory is relevant for
     *   (selection/construction/synchronization). Empty = all channels.
     * @return array{status:string,message:string,id:?int}
     *   status is one of: ok | empty | too_long | limit_count | limit_total | duplicate
     */
    public function add(int $userid, string $text, array $scopes = []): array {
        global $DB;

        $normalized = $this->normalize($text);
        if ($normalized === '') {
            return [
                'status' => 'empty',
                'message' => get_string('agent_memory_add_empty', 'bookingextension_agent'),
                'id' => null,
            ];
        }

        $length = \core_text::strlen($normalized);
        if ($length > self::MAX_CHARS_PER_MEMORY) {
            return [
                'status' => 'too_long',
                'message' => get_string('agent_memory_add_too_long', 'bookingextension_agent', self::MAX_CHARS_PER_MEMORY),
                'id' => null,
            ];
        }

        $existing = $this->get_all($userid);

        // Case-insensitive duplicate rejection so the budget is not wasted on near-duplicates.
        $needle = \core_text::strtolower($normalized);
        foreach ($existing as $record) {
            if (\core_text::strtolower($this->normalize((string)$record->memory)) === $needle) {
                return [
                    'status' => 'duplicate',
                    'message' => get_string('agent_memory_add_duplicate', 'bookingextension_agent'),
                    'id' => (int)$record->id,
                ];
            }
        }

        if (count($existing) >= self::MAX_MEMORIES) {
            return [
                'status' => 'limit_count',
                'message' => get_string('agent_memory_add_limit_count', 'bookingextension_agent', self::MAX_MEMORIES),
                'id' => null,
            ];
        }

        $totalchars = 0;
        foreach ($existing as $record) {
            $totalchars += \core_text::strlen((string)$record->memory);
        }
        if (($totalchars + $length) > self::MAX_TOTAL_CHARS) {
            return [
                'status' => 'limit_total',
                'message' => get_string('agent_memory_add_limit_total', 'bookingextension_agent', self::MAX_TOTAL_CHARS),
                'id' => null,
            ];
        }

        $now = time();
        $record = (object)[
            'userid' => $userid,
            'memory' => $normalized,
            'scopes' => implode(',', $this->normalize_scopes($scopes)),
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $id = (int)$DB->insert_record(self::TABLE, $record);

        return [
            'status' => 'ok',
            'message' => get_string('agent_memory_add_ok', 'bookingextension_agent', $normalized),
            'id' => $id,
        ];
    }

    /**
     * Return all memory records for a user, oldest first.
     *
     * @param int $userid
     * @return \stdClass[] records with id, userid, memory, timecreated, timemodified
     */
    public function get_all(int $userid): array {
        global $DB;

        if ($userid <= 0) {
            return [];
        }

        return array_values($DB->get_records(self::TABLE, ['userid' => $userid], 'timecreated ASC, id ASC'));
    }

    /**
     * Delete a single memory by id, ownership-checked.
     *
     * @param int $userid
     * @param int $id
     * @return bool true if a record was deleted
     */
    public function delete(int $userid, int $id): bool {
        global $DB;

        if ($userid <= 0 || $id <= 0) {
            return false;
        }

        if (!$DB->record_exists(self::TABLE, ['id' => $id, 'userid' => $userid])) {
            return false;
        }

        $DB->delete_records(self::TABLE, ['id' => $id, 'userid' => $userid]);
        return true;
    }

    /**
     * Find candidate memories matching a query (case-insensitive substring).
     *
     * Used only to propose deletions — never deletes directly.
     *
     * @param int $userid
     * @param string $query
     * @return \stdClass[] matching records
     */
    public function find(int $userid, string $query): array {
        $needle = \core_text::strtolower($this->normalize($query));
        if ($needle === '') {
            return [];
        }

        $matches = [];
        foreach ($this->get_all($userid) as $record) {
            if (\core_text::strpos(\core_text::strtolower((string)$record->memory), $needle) !== false) {
                $matches[] = $record;
            }
        }

        return $matches;
    }

    /**
     * Return the memories relevant for a given injection channel.
     *
     * A memory matches when its scopes list is empty (= all channels) or contains the channel.
     *
     * @param int $userid
     * @param string $scope One of the valid_scopes() values.
     * @return \stdClass[]
     */
    public function get_for_scope(int $userid, string $scope): array {
        $scope = trim($scope);
        $matches = [];
        foreach ($this->get_all($userid) as $record) {
            $recordscopes = self::parse_scopes($record->scopes ?? null);
            if (empty($recordscopes) || in_array($scope, $recordscopes, true)) {
                $matches[] = $record;
            }
        }

        return $matches;
    }

    /**
     * Parse a stored comma-separated scopes string into a clean array.
     *
     * @param string|null $scopes
     * @return string[]
     */
    public static function parse_scopes(?string $scopes): array {
        $scopes = trim((string)$scopes);
        if ($scopes === '') {
            return [];
        }

        $parts = array_filter(array_map('trim', explode(',', $scopes)), static fn($s): bool => $s !== '');
        return array_values(array_intersect(self::valid_scopes(), array_unique($parts)));
    }

    /**
     * Normalize requested scopes against the valid channel set (preserving canonical order).
     *
     * Unknown entries are dropped; an empty/invalid result means "all channels".
     *
     * @param string[] $scopes
     * @return string[]
     */
    private function normalize_scopes(array $scopes): array {
        $requested = [];
        foreach ($scopes as $scope) {
            $scope = \core_text::strtolower(trim((string)$scope));
            if ($scope !== '') {
                $requested[$scope] = true;
            }
        }

        return array_values(array_intersect(self::valid_scopes(), array_keys($requested)));
    }

    /**
     * Normalize a memory string: trim and collapse internal whitespace.
     *
     * @param string $text
     * @return string
     */
    private function normalize(string $text): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $collapsed = preg_replace('/\s+/u', ' ', $text);
        return is_string($collapsed) ? trim($collapsed) : $text;
    }
}
