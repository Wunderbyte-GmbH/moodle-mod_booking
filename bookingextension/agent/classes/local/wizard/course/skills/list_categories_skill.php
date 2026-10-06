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
use core_course_category;
use moodle_url;

/**
 * Skill definition for course.list_categories.
 *
 * Lists the course categories visible to the acting user. Deliberately distinct from the
 * writable-only category resolution inside course.create_course (which passes
 * 'moodle/course:create' to select a write target): a listing skill must show everything the
 * user may SEE, so make_categories_list() runs without a capability argument here.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_categories_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'course.list_categories';

    /** @var int Default cap on returned categories, so huge sites stay readable. */
    private const DEFAULT_LIMIT = 100;

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
            'description' => 'List the course categories of this Moodle site: every category visible to the user with its id, full '
                . 'hierarchical path (parent / subcategory), direct course count and hidden flag. Use this to enumerate the '
                . 'category tree, answer which categories exist on the platform, or find a category id before creating or '
                . 'organising courses. Supports an optional name filter. Read-only.',
            'is' => 'The category tree.',
            'not' => 'The courses themselves (search_courses); creating, moving or deleting a category.',
            'readonly' => $this->is_read_only(),
            'fallback_skillcall_string_key' => 'ai_status_skillcall_booking_list_categories',
            'example_utterances' => [
                'list all course categories on this server',
                'what categories exist on this platform',
                'show me the category tree with ids',
                'find the id of the Marketing category',
                'how many courses are in each category',
                'which category should I put my new course into',
            ],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional substring to filter categories by name or hierarchical path. '
                        . 'Leave empty or omit to list ALL categories.',
                    'required' => false,
                ],
                'categoryquery' => [
                    'type' => 'string',
                    'description' => 'Alias for query.',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of categories to return (default 100).',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['query'],
                'anchor_fields' => ['query'],
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
            'query' => '',
            'limit' => 20,
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
                'id' => 'course.list_categories_request',
                'description' => 'User asks to list or show the course categories, the category tree, '
                    . 'or all categories available on the site.',
            ],
            [
                'id' => 'course.list_categories_lookup_request',
                'description' => 'User asks for the id of a specific course category or how many courses '
                    . 'a category contains.',
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
                'id' => 'course.list_categories',
                'triggers' => [
                    'categor', 'kategorie', 'category tree', 'list categories',
                    'course categories', 'all categories', 'which categories',
                ],
                'guidance' => [
                    '- Use course.list_categories as a FIRST STEP when you need a categoryid and only a',
                    '  category name is known (e.g. before course.create_course).',
                    '- To list ALL categories (e.g. "which categories exist?"), call the skill with an',
                    '  empty or omitted input.query — do NOT ask the user for a search term.',
                    '- The observation already contains the category ids and hierarchical paths; never',
                    '  invent or guess a categoryid yourself.',
                    '- The course count shown is the DIRECT (non-recursive) number of courses in each',
                    '  category, excluding courses in its subcategories.',
                    '- For listing or resolving COURSES, use course.search_courses instead.',
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
        // An empty query is valid: it lists all categories visible to the user.
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
        global $DB;

        $query = $this->resolve_query($input);
        $outputlang = $this->get_output_language($input);
        $listall = ($query === '');
        $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : self::DEFAULT_LIMIT;

        $debugbase = $this->build_skill_debug_message(self::SKILL_NAME, $input);

        // Visibility-filtered (what the user may SEE), id => hierarchical path, cached by core.
        $all = core_course_category::make_categories_list();
        if (!$listall) {
            $needle = \core_text::strtolower($query);
            $all = array_filter(
                $all,
                fn(string $path): bool => str_contains(\core_text::strtolower($path), $needle)
            );
        }

        $total = count($all);
        $slice = array_slice($all, 0, $limit, true);

        if (empty($slice)) {
            $usermessage = $this->localized_string('agent_booking_list_categories_no_results', null, $outputlang);
            return [
                'status' => 'executed',
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'resultid' => null,
                'categories' => [],
                'observation_full' => $usermessage,
                'debugmessage' => $debugbase . "\nResults: 0",
            ];
        }

        // Enrich only the returned slice, one query: coursecount/visible are real columns.
        $records = $DB->get_records_list(
            'course_categories',
            'id',
            array_keys($slice),
            '',
            'id, coursecount, visible'
        );

        $categories = [];
        foreach ($slice as $id => $path) {
            $record = $records[$id] ?? null;
            $categories[] = [
                'categoryid' => (int)$id,
                'path' => (string)$path,
                'coursecount' => (int)($record->coursecount ?? 0),
                'visible' => (int)($record->visible ?? 1),
                'url' => (new moodle_url('/course/index.php', ['categoryid' => $id]))->out(false),
            ];
        }

        $usermessage = $listall
            ? $this->localized_string('agent_booking_list_categories_listed', $total, $outputlang)
            : $this->localized_string('agent_booking_list_categories_found', count($categories), $outputlang);

        $lines = [$usermessage];
        foreach ($categories as $category) {
            $line = '- ' . $category['path'] . ' (id ' . $category['categoryid'] . ')'
                . ' | direct courses: ' . $category['coursecount'];
            if ($category['visible'] === 0) {
                $line .= ' | hidden';
            }
            $lines[] = $line;
        }
        if ($total > count($categories)) {
            $lines[] = $this->localized_string(
                'agent_booking_list_categories_listed_partial',
                (object)['shown' => count($categories), 'total' => $total],
                $outputlang
            );
        }

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)$categories[0]['categoryid'],
            'categories' => $categories,
            'observation_full' => implode("\n", $lines),
            'debugmessage' => $debugbase
                . "\nResults: " . count($categories)
                . "\nTotal visible: " . $total,
        ];
    }

    /**
     * Resolve the category filter query from canonical and alias fields.
     *
     * @param array $input
     * @return string
     */
    private function resolve_query(array $input): string {
        foreach (['query', 'categoryquery', 'category', 'categoryname'] as $key) {
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
        return '';
    }
}
