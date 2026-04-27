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
use mod_booking\local\wbagent\services\search_users_answering_service;

/**
 * Task definition for booking.search_users.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_users_task extends base_booking_task {
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
     * Validate task input.
     *
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
        $errors = [];
        if (empty($input['query']) || !is_string($input['query'])) {
            $errors[] = 'Field "query" is required for search_users.';
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
        $outputlang = $this->get_output_language($input);
        $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : 10;

        if ($query === '') {
            return ['status' => 'error', 'detail' => 'Field "query" is required.', 'resultid' => null];
        }

        $debugbase = $this->build_task_debug_message(self::TASK_NAME, $input);

        $users = booking_task_support::search_user_candidates_for_preview($query, $limit);
        if (empty($users)) {
            $messagedata = $this->generate_user_message($query, $query, [], $outputlang, $cmid, $userid);
            return [
                'status' => 'executed',
                'detail' => $messagedata['message'],
                'summary' => $messagedata['message'],
                'usermessage' => $messagedata['message'],
                'resultid' => null,
                'users' => [],
                'debugmessage' => $debugbase
                    . "\nResults: 0"
                    . "\nAnswer source: " . $messagedata['source'],
            ];
        }

        $messagedata = $this->generate_user_message($query, $query, $users, $outputlang, $cmid, $userid);

        return [
            'status' => 'executed',
            'detail' => $messagedata['message'],
            'summary' => $messagedata['message'],
            'usermessage' => $messagedata['message'],
            'resultid' => (int)($users[0]['userid'] ?? 0),
            'users' => $users,
            'debugmessage' => $debugbase
                . "\nResults: " . count($users)
                . "\nAnswer source: " . $messagedata['source'],
        ];
    }

    /**
     * Generate user-facing message via LLM with deterministic fallback.
     *
     * @param string $question
     * @param string $query
     * @param array $users
     * @param string $outputlang
     * @param int $cmid
     * @param int $userid
     * @return array{message:string,source:string}
     */
    private function generate_user_message(
        string $question,
        string $query,
        array $users,
        string $outputlang,
        int $cmid,
        int $userid
    ): array {
        $message = '';
        $source = 'none';
        try {
            $answeringresult = $this->create_search_users_answering_service()->answer_question(
                $question,
                $query,
                $users,
                $outputlang,
                $cmid,
                $userid
            );
            $llmanswer = trim((string)($answeringresult['answer'] ?? ''));
            if ($llmanswer !== '') {
                $message = $this->enforce_max_chars($llmanswer, 650);
                $source = 'llm';
            }
        } catch (\Throwable $e) {
            $source = 'error';
        }

        if ($message === '') {
            $message = $this->build_fallback_user_message($users);
            $source = $source === 'error' ? 'fallback_after_error' : 'fallback';
        }

        return ['message' => $message, 'source' => $source];
    }

    /**
     * Build deterministic fallback text when LLM output is unavailable.
     *
     * @param array $users
     * @return string
     */
    private function build_fallback_user_message(array $users): string {
        $count = count($users);
        if ($count === 0) {
            return 'No matching users found.';
        }

        return 'Found ' . $count . ' matching user(s).';
    }

    /**
     * Create the search-users answering service.
     *
     * @return search_users_answering_service
     */
    protected function create_search_users_answering_service(): search_users_answering_service {
        return new search_users_answering_service();
    }
}
