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

namespace mod_booking\local\wbagent\booking\tasks;

use mod_booking\local\wbagent\booking\booking_task_support;
use mod_booking\local\wbagent\interfaces\task_trigger_provider_interface;

/**
 * Task definition for booking.search_users.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_users_task extends base_booking_task implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.search_users';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true);
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Search users via mod_booking external search_users functionality.',
            'readonly' => $this->is_read_only(),
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_search_users',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search text for first name, last name, email or user id.',
                    'required' => true,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of users to return (default 10).',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'booking.search_users_request',
                'description' => 'User asks to find users by name, email or id.',
                'examples' => [
                    'Find users called John',
                    'Suche Benutzer nach E‑Mail',
                    'Find user with id 42',
                ],
            ],
        ];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'booking.search_users',
                'triggers' => [
                    'find user', 'search user', 'suche benutzer', 'suche nutzer', 'finde benutzer',
                    'find users', 'search users', 'finde nutzer', 'user lookup',
                ],
                'guidance' => [
                    '- Use booking.search_users for queries that look for people by name, email or id.',
                    '- Return a short preview list of matching users, including userid and fullname.',
                ],
            ],
        ];
    }

    /**
     * Validate task input.
     *
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
        $errors = [];
        $lang = $this->get_output_language($input);
        if (empty($input['query']) || !is_string($input['query'])) {
            $errors[] = $this->localized_string('agent_booking_search_users_required_query', null, $lang);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $query = trim((string)($input['query'] ?? ''));
        $question = trim((string)($input['question'] ?? ''));
        $outputlang = $this->get_output_language($input);
        $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : 10;

        if ($query === '') {
            return [
                'status' => 'error',
                'detail' => $this->localized_string('agent_booking_search_users_required_query', null, $outputlang),
                'resultid' => null,
            ];
        }

        $debugbase = $this->build_task_debug_message(self::TASK_NAME, $input);

        $users = booking_task_support::search_user_candidates_for_preview($query, $limit);
        if (empty($users)) {
            $usermessage = $this->localized_string('agent_booking_search_users_no_results', null, $outputlang);
            return [
                'status' => 'executed',
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'resultid' => null,
                'users' => [],
                'debugmessage' => $debugbase . "\nResults: 0",
            ];
        }

        $usermessage = $this->localized_string(
            'agent_booking_search_users_found',
            count($users),
            $outputlang
        );
        $previewids = array_values(array_map(static fn(array $u): int => (int)($u['userid'] ?? 0), $users));
        $debugextra = [
            'Results: ' . count($users),
            'Top user: ' . ((string)($users[0]['fullname'] ?? '') ?: (string)($users[0]['username'] ?? '')) . ' ',
            'Preview user ids: ' . implode(', ', $previewids),
        ];

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)($users[0]['userid'] ?? 0),
            'users' => $users,
            'previewuserids' => $previewids,
            'debugmessage' => $debugbase . "\n" . implode("\n", $debugextra),
        ];
    }
}
