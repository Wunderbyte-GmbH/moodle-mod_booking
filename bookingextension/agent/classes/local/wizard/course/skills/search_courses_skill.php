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

namespace bookingextension_agent\local\wizard\course\skills;

use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;

/**
 * Skill definition for course.search_courses.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_courses_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'course.search_courses';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Return skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Resolve a Moodle course by its NAME: courseid, shortname, fullname, URL, active enrolment count. Use '
                . 'this to find Moodle courses by name or to list the courses of the platform.',
            'is' => 'Moodle course containers, by name.',
            'not' => 'Booking options (mod_booking.search_options), content by topic (core.find_content), categories '
                . '(list_categories).',
            'readonly' => $this->is_read_only(),
            'fallback_skillcall_string_key' => 'ai_status_skillcall_booking_search_courses',
            'example_utterances' => [
                'find the Moodle course called Biology 101',
                'what is the course id of Intro to Programming',
                'get the link to the Mathematics course',
                'which Moodle course matches the name Data Science',
            ],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search text for course full name, short name or id. '
                        . 'Leave empty or omit to list all available courses.',
                    'required' => false,
                ],
                'coursequery' => [
                    'type' => 'string',
                    'description' => 'Alias for query.',
                    'required' => false,
                ],
                'coursename' => [
                    'type' => 'string',
                    'description' => 'Alias for query when only a course name is provided.',
                    'required' => false,
                ],
                'course' => [
                    'type' => 'string',
                    'description' => 'Alias for query.',
                    'required' => false,
                ],
                'searchterm' => [
                    'type' => 'string',
                    'description' => 'Alias for query.',
                    'required' => false,
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
     * Return example input for planner contract rendering.
     *
     * @return array
     */
    public function get_example_input(): array {
        return [
            'query' => 'Informatik',
            'limit' => 5,
        ];
    }

    /**
     * Return skill-specific message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'course.search_courses_request',
                'description' => 'User asks to find/search courses by name, shortname or id, '
                    . 'or to list all courses available on the platform.',
            ],
            [
                'id' => 'course.search_courses_limit_request',
                'description' => 'User asks for a limited number of returned courses.',
            ],
        ];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'course.search_courses',
                'triggers' => [
                    'search courses', 'find course', 'find courses', 'course id',
                    'search course', 'search courses', 'find course',
                    'list courses', 'list all courses', 'which courses', 'all courses',
                    'all courses', 'which courses', 'show courses', 'list courses',
                ],
                'guidance' => [
                    '- Use course.search_courses as a FIRST STEP when you need a courseid to pass to',
                    '  a follow-up skill and only a course name is known.',
                    '- To list ALL courses on the platform (e.g. "which courses exist?"), call the',
                    '  skill with an empty or omitted input.query — do NOT ask the user for a search term.',
                    '- Execute this skill and wait for the observation; then use the resolved courseid.',
                    '- This skill already returns the course URL, so do not ask the model to invent or compose',
                    '  a Moodle course link itself.',
                    '- This skill also returns the active enrolment count, so use it before asking a second skill',
                    '  only to learn how many active users are currently enrolled in the course.',
                    '- Use input.query for the search term and optionally input.limit to cap results.',
                    '- If multiple courses match, ask the user to clarify before continuing.',
                ],
            ],
        ];
    }

    /**
     * Check skill input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        // An empty query is valid: it lists all courses visible to the user.
        return [
            'valid' => true,
            'errors' => [],
            'ambiguities' => [],
        ];
    }

    /**
     * Execute skill.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $query = $this->resolve_query($input);
        $outputlang = $this->get_output_language($input);
        $listall = ($query === '');
        $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : ($listall ? 50 : 10);

        $debugbase = $this->build_skill_debug_message(self::SKILL_NAME, $input);

        $total = 0;
        if ($listall) {
            // Empty query deliberately lists all courses visible to the user.
            $listing = $this->list_course_candidates_for_preview($limit);
            $courses = $listing['courses'];
            $total = (int)$listing['total'];
        } else {
            $courses = $this->search_course_candidates_for_preview($query, $limit);
        }

        if (empty($courses)) {
            $usermessage = $this->localized_string('agent_booking_search_courses_no_results', null, $outputlang);
            return [
                'status' => 'executed',
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'resultid' => null,
                'courses' => [],
                'observation_full' => $this->build_course_observation_full([], $outputlang),
                'debugmessage' => $debugbase . "\nResults: 0",
            ];
        }

        $usermessage = $listall
            ? $this->localized_string('agent_booking_search_courses_listed', $total, $outputlang)
            : $this->localized_string('agent_booking_search_courses_found', count($courses), $outputlang);

        $observationfull = $this->build_course_observation_full($courses, $outputlang, $listall ? $usermessage : '');
        if ($listall && $total > count($courses)) {
            $observationfull .= "\n" . $this->localized_string(
                'agent_booking_search_courses_listed_partial',
                (object)['shown' => count($courses), 'total' => $total],
                $outputlang
            );
        }

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)($courses[0]['courseid'] ?? 0),
            'courses' => $courses,
            'observation_full' => $observationfull,
            'debugmessage' => $debugbase
                . "\nResults: " . count($courses)
                . ($listall ? "\nTotal visible: " . $total : ''),
        ];
    }

    /**
     * Build a user-facing course observation string.
     *
     * @param array[] $courses
     * @param string $lang
     * @param string $headline Optional headline override (empty = default "found" line).
     * @return string
     */
    private function build_course_observation_full(array $courses, string $lang, string $headline = ''): string {
        if (empty($courses)) {
            return $this->localized_string('agent_booking_search_courses_no_results', null, $lang);
        }

        $lines = [];
        $lines[] = $headline !== ''
            ? $headline
            : $this->localized_string('agent_booking_search_courses_found', count($courses), $lang);

        foreach ($courses as $course) {
            $fullname = trim((string)($course['fullname'] ?? ''));
            $shortname = trim((string)($course['shortname'] ?? ''));
            $courseurl = trim((string)($course['courseurl'] ?? ''));
            $activecount = (int)($course['activeenrolledcount'] ?? 0);

            $label = $fullname !== '' ? $fullname : $shortname;
            if ($label === '') {
                $label = (string)($course['courseid'] ?? '');
            }

            $line = '- ' . $label;
            if ($shortname !== '' && $shortname !== $label) {
                $line .= ' (' . $shortname . ')';
            }
            if ($courseurl !== '') {
                $line .= ': ' . $courseurl;
            }
            $line .= ' | active enrolled: ' . $activecount;

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Resolve the course search query from canonical and legacy alias fields.
     *
     * @param array $input
     * @return string
     */
    private function resolve_query(array $input): string {
        $keys = [
            'query',
            'coursequery',
            'coursename',
            'course',
            'searchterm',
            'course_name',
            'fullname',
            'shortname',
            'name',
            'courseid',
        ];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $rawvalue = $input[$key];
            if (is_array($rawvalue) && array_key_exists('value', $rawvalue) && is_scalar($rawvalue['value'])) {
                $rawvalue = $rawvalue['value'];
            }

            if (!is_scalar($rawvalue)) {
                continue;
            }

            $value = trim((string)$rawvalue);
            if ($value !== '') {
                return $value;
            }
        }

        // Last-resort fallback: accept a single meaningful free-text scalar field.
        foreach ($input as $key => $rawvalue) {
            if (!is_string($key) || in_array($key, ['outputlang', 'limit', 'contextid', 'cmid'], true)) {
                continue;
            }
            if (!is_scalar($rawvalue)) {
                continue;
            }

            $value = trim((string)$rawvalue);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
