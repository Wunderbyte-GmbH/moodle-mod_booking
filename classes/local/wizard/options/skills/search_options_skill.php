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

namespace mod_booking\local\wizard\options\skills;

use mod_booking\local\wizard\engine\skill_risk_class;
use core_text;
use mod_booking\local\wizard\booking\booking_skill_support;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;

/**
 * Task definition for booking.search_options.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_options_skill extends booking_skill_base implements skill_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.search_options';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
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
        $schema = [
            'version' => 1,
            'description' => 'Search and list the bookable OPTIONS (events, workshops, sessions) '
                . 'available in this booking instance.'
                . ' Use this when the user asks what they can book, register for, or attend'
                . ' — e.g. "show all options", "what can I book?", "list available bookings", '
                . '"show a list of all options", "list all bookings", "show all bookings". '
                . 'This lists bookable offerings, not Moodle course containers (use search_courses for those).',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_search_options',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_search_options',
            'example_utterances' => [
                'Show me all the options I can book',
                'List the bookable options in this activity',
                'Which workshops are happening next week?',
                'Find all options that contain the word "yoga"',
                'What can I register for?',
                'Find the booking option called "First Aid Basic Course"',
                'Look up the booking option for the cooking class',
                'Identify the option titled "Spring Workshop"',
            ],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional search text (title/description/location), e.g. "next monday". '
                        . 'If omitted, returns a short list of options in this booking instance.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of candidates to return (default 10).',
                    'required' => false,
                ],
                'when' => [
                    'type' => 'string',
                    'description' => 'Optional temporal hint (e.g. "next monday").',
                    'required' => false,
                ],
            ],
        ];

        return $this->enrich_schema_with_prompt_meta($schema);
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'mod_booking.search_options_exact_title_match',
                'description' => 'User asks for exact-title matching instead of fuzzy search.',
            ],
            [
                'id' => 'mod_booking.search_options_temporal_filter_applied',
                'description' => 'User asks to constrain option search by time/date hints.',
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
                'id' => 'mod_booking.search_options',
                'triggers' => [
                    'search', 'find options', 'show options', 'which options',
                    'options', 'where can i find', 'find option',
                    'preview', 'show preview',
                ],
                'guidance' => [
                    '- If the user asks to find booking options, use booking.search_options.',
                    '- Prefer exact title matches when the user mentions a quoted title or the word "title".',
                    '- Return a short structured list with id, name and link for preview.',
                    '- If the follow-up asks for specific option fields (trainer/teacher, sessions, times),',
                    '  use booking.get_option_details for the resolved option instead of re-running search.',
                    '- If observations already contain exactly one resolved option and the user asks for preview/details,
                      do not call booking.search_options again; answer directly from that resolved option context.',
                ],
            ],
        ];
    }

    /**
     * Check task input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $lang = $this->get_output_language($input);
        if (isset($input['query']) && !is_string($input['query'])) {
            $errors[] = $this->localized_string('agent_booking_search_options_query_must_be_string', null, $lang);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Explicit preflight for readonly task — validates structure and passes input unchanged.
     * DTO-free: returns a primitive result via pass()/invalid(); base_skill::preflight()
     * wraps it into the engine's preflight_result_v2.
     *
     * @param array $input
     * @param int   $cmid
     * @param int   $userid
     * @return array{status:string,prepared_input:array,issues:array}
     */
    protected function run_preflight(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        if ($guard = $this->require_booking_instance_scope($cmid)) {
            return $guard;
        }
        $structure = $this->check_structure($input);
        if (!($structure['valid'] ?? false)) {
            $issues = [];
            foreach ((array)($structure['errors'] ?? []) as $error) {
                $issues[] = [
                    'code' => 'VALIDATION_ERROR',
                    'severity' => 'needs_clarification',
                    'message' => (string)$error,
                ];
            }
            return $this->invalid($issues);
        }
        return $this->pass($input);
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
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        if ($scoperesult = $this->build_no_instance_scope_result($cmid)) {
            return $scoperesult;
        }
        global $DB;

        $query = trim((string)($input['query'] ?? ''));
        $question = trim((string)($input['question'] ?? ''));
        $when = trim((string)($input['when'] ?? ''));
        $outputlang = $this->get_output_language($input);
        $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : ($query === '' ? 50 : 10);

        $debugbase = $this->build_task_debug_message(self::TASK_NAME, $input);

        $exacttitlequery = self::extract_exact_title_query($query);
        $effectivequery = $exacttitlequery !== '' ? $exacttitlequery : $query;

        // Robust exact-title short-circuit: even if the LLM only passes query="von billy"
        // without explicit "title" wording, a unique exact title should win over fuzzy matches.
        if ($effectivequery !== '') {
            $exact = booking_skill_support::find_existing_options_by_exact_title($cmid, $effectivequery);
            if (($exact['status'] ?? '') === 'single') {
                $optionid = (int)($exact['optionid'] ?? 0);
                if ($optionid > 0) {
                    $title = (string)$DB->get_field('booking_options', 'text', ['id' => $optionid]) ?: $effectivequery;
                    $link = booking_skill_support::build_option_link_for_output($cmid, $optionid);
                    $structuredoptions = [[
                        'id' => $optionid,
                        'name' => $title,
                        'link' => $link,
                    ]];
                    $usermessage = get_string('searchoptionsfound', 'booking', 1);

                    return [
                        'status' => 'executed',
                        'detail' => $usermessage,
                        'usermessage' => $usermessage,
                        'observation_full' => $this->build_observation_full($usermessage, $structuredoptions),
                        'resultid' => $optionid,
                        'previewoptionids' => [$optionid],
                        'options' => $structuredoptions,
                        'debugmessage' => $debugbase . "\nResults: 1 (exact title match)",
                    ];
                }
            }
        }

        $rows = booking_skill_support::search_option_candidates_for_preview($cmid, $effectivequery, $limit, $when);
        if ($exacttitlequery !== '' && !empty($rows)) {
            $rows = array_values(array_filter($rows, static function (array $row) use ($exacttitlequery): bool {
                $title = trim((string)($row['text'] ?? ''));
                return core_text::strtolower($title) === core_text::strtolower($exacttitlequery);
            }));
        }

        if (empty($rows)) {
            $usermessage = get_string('searchoptionsnotfound', 'booking');
            return [
                'status' => 'executed',
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'observation_full' => $this->build_observation_full($usermessage, []),
                'resultid' => null,
                'options' => [],
                'debugmessage' => $debugbase . "\nResults: 0",
            ];
        }

        $structuredoptions = [];
        foreach ($rows as $row) {
            $optionid = (int)($row['optionid'] ?? 0);
            $name = (string)($row['text'] ?? '');
            $link = booking_skill_support::build_option_link_for_output($cmid, $optionid);
            $structuredoptions[] = [
                'id' => $optionid,
                'name' => $name,
                'link' => $link,
            ];
        }

        $usermessage = get_string('searchoptionsfound', 'booking', count($structuredoptions));

        $previewids = array_values(array_map(
            static fn(array $row): int => (int)($row['optionid'] ?? 0),
            $rows
        ));

        $debugextra = [
            'Results: ' . count($structuredoptions),
            'Top option: ' . (string)($structuredoptions[0]['name'] ?? ''),
            'Preview option ids: ' . implode(', ', $previewids),
        ];

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'observation_full' => $this->build_observation_full($usermessage, $structuredoptions),
            'resultid' => (int)($rows[0]['optionid'] ?? 0),
            'previewoptionids' => $previewids,
            'options' => $structuredoptions,
            'debugmessage' => $debugbase . "\n" . implode("\n", $debugextra),
        ];
    }

    /**
     * Build verbose observation payload for follow-up reasoning steps.
     *
     * @param string $usermessage
     * @param array $structuredoptions
     * @return string
     */
    private function build_observation_full(string $usermessage, array $structuredoptions): string {
        $normalizedoptions = array_map(static function (array $option): array {
            return [
                'optionid' => (int)($option['id'] ?? 0),
                'name' => (string)($option['name'] ?? ''),
                'link' => (string)($option['link'] ?? ''),
            ];
        }, $structuredoptions);

        $payload = [
            'options' => $normalizedoptions,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            return $usermessage;
        }

        return $usermessage . "\n\nDetailed options payload (JSON):\n" . $json;
    }

    /**
     * Extract exact title intent from natural-language query.
     *
     * Examples:
     * - "show me only the ones titled 'Code Swap'"
     * - "title is 'Code Swap'"
     *
     * @param string $query
     * @return string
     */
    private static function extract_exact_title_query(string $query): string {
        $normalized = core_text::strtolower(trim($query));
        if ($normalized === '') {
            return '';
        }

        $hastitleintent = strpos($normalized, 'title') !== false;
        if (!$hastitleintent) {
            return '';
        }

        if (preg_match('/["\']([^"\']+)["\']/', $query, $m)) {
            return trim((string)($m[1] ?? ''));
        }

        if (preg_match('/title\s*(?:is|=)?\s+(.+)$/iu', $query, $m)) {
            return trim((string)($m[1] ?? ''));
        }

        return '';
    }
}
