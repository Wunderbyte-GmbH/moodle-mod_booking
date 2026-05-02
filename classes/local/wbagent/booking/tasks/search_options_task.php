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

use core_text;
use mod_booking\local\wbagent\booking\booking_task_support;
use mod_booking\local\wbagent\interfaces\task_trigger_provider_interface;

/**
 * Task definition for booking.search_options.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_options_task extends base_booking_task implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.search_options';

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
            'description' => 'Search booking options via the existing booking table fulltext/filter pipeline.',
            'readonly' => $this->is_read_only(),
            'fallback_confirm_string_key' => 'ai_status_confirm_booking_search_options',
            'fallback_taskcall_string_key' => 'ai_status_taskcall_booking_search_options',
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
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'booking.search_options_exact_title_match',
                'description' => 'User asks for exact-title matching instead of fuzzy search.',
            ],
            [
                'id' => 'booking.search_options_temporal_filter_applied',
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
                'id' => 'booking.search_options',
                'triggers' => [
                    'search', 'find options', 'show options', 'which options',
                    'suche', 'optionen', 'zeige optionen', 'wo finde', 'finde option',
                ],
                'guidance' => [
                    '- If the user asks to find booking options, use booking.search_options.',
                    '- Prefer exact title matches when the user mentions a quoted title or the word "title"/"titel".',
                    '- Return a short structured list with id, name and link for preview.',
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
     * Execute task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
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
            $exact = booking_task_support::find_existing_options_by_exact_title($cmid, $effectivequery);
            if (($exact['status'] ?? '') === 'single') {
                $optionid = (int)($exact['optionid'] ?? 0);
                if ($optionid > 0) {
                    $title = (string)$DB->get_field('booking_options', 'text', ['id' => $optionid]) ?: $effectivequery;
                    $link = booking_task_support::build_option_link_for_output($cmid, $optionid);
                    $structuredoptions = [[
                        'id' => $optionid,
                        'name' => $title,
                        'link' => $link,
                    ]];
                    $usermessage = get_string('searchoptionsfound', 'mod_booking', 1);

                    return [
                        'status' => 'executed',
                        'detail' => $usermessage,
                        'usermessage' => $usermessage,
                        'resultid' => $optionid,
                        'previewoptionids' => [$optionid],
                        'options' => $structuredoptions,
                        'debugmessage' => $debugbase . "\nResults: 1 (exact title match)",
                    ];
                }
            }
        }

        $rows = booking_task_support::search_option_candidates_for_preview($cmid, $effectivequery, $limit, $when);
        if ($exacttitlequery !== '' && !empty($rows)) {
            $rows = array_values(array_filter($rows, static function (array $row) use ($exacttitlequery): bool {
                $title = trim((string)($row['text'] ?? ''));
                return core_text::strtolower($title) === core_text::strtolower($exacttitlequery);
            }));
        }

        if (empty($rows)) {
            $usermessage = get_string('searchoptionsnotfound', 'mod_booking');
            return [
                'status' => 'executed',
                'detail' => $usermessage,
                'usermessage' => $usermessage,
                'resultid' => null,
                'options' => [],
                'debugmessage' => $debugbase . "\nResults: 0",
            ];
        }

        $structuredoptions = [];
        foreach ($rows as $row) {
            $optionid = (int)($row['optionid'] ?? 0);
            $name = (string)($row['text'] ?? '');
            $link = booking_task_support::build_option_link_for_output($cmid, $optionid);
            $structuredoptions[] = [
                'id' => $optionid,
                'name' => $name,
                'link' => $link,
            ];
        }

        $usermessage = get_string('searchoptionsfound', 'mod_booking', count($structuredoptions));

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
            'resultid' => (int)($rows[0]['optionid'] ?? 0),
            'previewoptionids' => $previewids,
            'options' => $structuredoptions,
            'debugmessage' => $debugbase . "\n" . implode("\n", $debugextra),
        ];
    }

    /**
     * Extract exact title intent from natural-language query.
     *
     * Examples:
     * - "zeig mir nur die, wo der titel \"von billy\" lautet"
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

        $hastitleintent = (strpos($normalized, 'titel') !== false)
            || (strpos($normalized, 'title') !== false);
        if (!$hastitleintent) {
            return '';
        }

        if (preg_match('/["\']([^"\']+)["\']/', $query, $m)) {
            return trim((string)($m[1] ?? ''));
        }

        if (preg_match('/(?:titel|title)\s*(?:ist|is|=|lautet)?\s+(.+)$/iu', $query, $m)) {
            return trim((string)($m[1] ?? ''));
        }

        return '';
    }
}
