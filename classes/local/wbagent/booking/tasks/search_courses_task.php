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
use mod_booking\local\wbagent\services\answering\search_courses_answering_service;

/**
 * Task definition for booking.search_courses.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_courses_task extends base_booking_task implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.search_courses';

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
            'description' => 'Search courses via mod_booking external search_courses functionality.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search text for course full name, short name or id.',
                    'required' => true,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of courses to return (default 10).',
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
                'id' => 'booking.search_courses_request',
                'description' => 'User asks to find/search courses by name, shortname or id.',
            ],
            [
                'id' => 'booking.search_courses_limit_request',
                'description' => 'User asks for a limited number of returned courses.',
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
                'id' => 'booking.search_courses',
                'triggers' => [
                    'search courses', 'find course', 'find courses', 'course id',
                    'suche kurs', 'suche kurse', 'finde kurs', 'kurs finden',
                ],
                'guidance' => [
                    '- If the user asks to find courses, use booking.search_courses.',
                    '- Use input.query for the course term and optionally input.limit for result size.',
                    '- Return short course candidates suitable for follow-up selection.',
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
            $errors[] = 'Field "query" is required for search_courses.';
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
            return ['status' => 'error', 'detail' => 'Field "query" is required.', 'resultid' => null];
        }

        $debugbase = $this->build_task_debug_message(self::TASK_NAME, $input);

        $courses = booking_task_support::search_course_candidates_for_preview($query, $limit);
        if (empty($courses)) {
            $messagedata = $this->generate_user_message($question, $query, [], $outputlang, $cmid, $userid);
            return [
                'status' => 'executed',
                'detail' => $messagedata['message'],
                'summary' => $messagedata['message'],
                'usermessage' => $messagedata['message'],
                'resultid' => null,
                'courses' => [],
                'debugmessage' => $debugbase
                    . "\nResults: 0"
                    . "\nAnswer source: " . $messagedata['source'],
            ];
        }

        $messagedata = $this->generate_user_message($question, $query, $courses, $outputlang, $cmid, $userid);

        return [
            'status' => 'executed',
            'detail' => $messagedata['message'],
            'summary' => $messagedata['message'],
            'usermessage' => $messagedata['message'],
            'resultid' => (int)($courses[0]['courseid'] ?? 0),
            'courses' => $courses,
            'debugmessage' => $debugbase
                . "\nResults: " . count($courses)
                . "\nAnswer source: " . $messagedata['source'],
        ];
    }

    /**
     * Generate user-facing message via LLM with deterministic fallback.
     *
     * @param string $question
     * @param string $query
     * @param array $courses
     * @param string $outputlang
     * @param int $cmid
     * @param int $userid
     * @return array{message:string,source:string}
     */
    private function generate_user_message(
        string $question,
        string $query,
        array $courses,
        string $outputlang,
        int $cmid,
        int $userid
    ): array {
        $message = '';
        $source = 'none';
        try {
            $answeringresult = $this->create_search_courses_answering_service()->answer_question(
                $question,
                $query,
                $courses,
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
            $message = $this->build_fallback_user_message($courses);
            $source = $source === 'error' ? 'fallback_after_error' : 'fallback';
        }

        return ['message' => $message, 'source' => $source];
    }

    /**
     * Build deterministic fallback text when LLM output is unavailable.
     *
     * @param array $courses
     * @return string
     */
    private function build_fallback_user_message(array $courses): string {
        $count = count($courses);
        if ($count === 0) {
            return 'No matching courses found.';
        }

        return 'Found ' . $count . ' matching course(s).';
    }

    /**
     * Create the search-courses answering service.
     *
     * @return search_courses_answering_service
     */
    protected function create_search_courses_answering_service(): search_courses_answering_service {
        return new search_courses_answering_service();
    }
}
