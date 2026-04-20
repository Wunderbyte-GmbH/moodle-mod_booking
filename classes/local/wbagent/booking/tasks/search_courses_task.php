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

/**
 * Task definition for booking.search_courses.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_courses_task extends base_booking_task {
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
     * @return array<string,mixed>
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
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of courses to return (default 10).',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Validate task input.
     *
     * @param array<string,mixed> $input
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
     * @param array<string,mixed> $input
     * @param int $cmid
     * @param int $userid
     * @return array<string,mixed>
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $query = trim((string)($input['query'] ?? ''));
        $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : 10;

        if ($query === '') {
            return ['status' => 'error', 'detail' => 'Field "query" is required.', 'resultid' => null];
        }

        $debugbase = $this->build_task_debug_message(self::TASK_NAME, $input);

        $courses = booking_task_support::search_course_candidates_for_preview($query, $limit);
        if (empty($courses)) {
            return [
                'status' => 'executed',
                'detail' => 'No matching courses found.',
                'resultid' => null,
                'courses' => [],
                'debugmessage' => $debugbase . "\nResults: 0",
            ];
        }

        return [
            'status' => 'executed',
            'detail' => 'Found ' . count($courses) . ' matching course(s).',
            'resultid' => (int)($courses[0]['courseid'] ?? 0),
            'courses' => $courses,
            'debugmessage' => $debugbase . "\nResults: " . count($courses),
        ];
    }
}
