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
use mod_booking\local\wbagent\services\search_options_answering_service;

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
     * Validate task input.
     *
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
        $errors = [];
        if (isset($input['query']) && !is_string($input['query'])) {
            $errors[] = 'Field "query" must be a string when provided for search_options.';
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
                    $messagedata = $this->generate_user_message(
                        $query,
                        $query,
                        $when,
                        $structuredoptions,
                        $outputlang,
                        $cmid,
                        $userid
                    );

                    return [
                        'status' => 'executed',
                        'detail' => $messagedata['message'],
                        'summary' => $messagedata['message'],
                        'usermessage' => $messagedata['message'],
                        'resultid' => $optionid,
                        'previewoptionids' => [$optionid],
                        'options' => $structuredoptions,
                        'debugmessage' => $debugbase
                            . "\nResults: 1 (exact title match)"
                            . "\nAnswer source: " . $messagedata['source'],
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
            $messagedata = $this->generate_user_message(
                $query,
                $effectivequery,
                $when,
                [],
                $outputlang,
                $cmid,
                $userid
            );
            return [
                'status' => 'executed',
                'detail' => $messagedata['message'],
                'summary' => $messagedata['message'],
                'usermessage' => $messagedata['message'],
                'resultid' => null,
                'options' => [],
                'debugmessage' => $debugbase
                    . "\nResults: 0"
                    . "\nAnswer source: " . $messagedata['source'],
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

        $messagedata = $this->generate_user_message(
            $query,
            $effectivequery,
            $when,
            $structuredoptions,
            $outputlang,
            $cmid,
            $userid
        );

        return [
            'status' => 'executed',
            'detail' => $messagedata['message'],
            'summary' => $messagedata['message'],
            'usermessage' => $messagedata['message'],
            'resultid' => (int)($rows[0]['optionid'] ?? 0),
            'previewoptionids' => array_values(array_map(
                static fn(array $row): int => (int)($row['optionid'] ?? 0),
                $rows
            )),
            'options' => $structuredoptions,
            'debugmessage' => $debugbase
                . "\nResults: " . count($structuredoptions)
                . "\nAnswer source: " . $messagedata['source'],
        ];
    }

    /**
     * Generate user-facing message via LLM with deterministic fallback.
     *
     * @param string $question
     * @param string $query
     * @param string $when
     * @param array $options
     * @param string $outputlang
     * @param int $cmid
     * @param int $userid
     * @return array{message:string,source:string}
     */
    private function generate_user_message(
        string $question,
        string $query,
        string $when,
        array $options,
        string $outputlang,
        int $cmid,
        int $userid
    ): array {
        $message = '';
        $source = 'none';
        try {
            $answeringresult = $this->create_search_options_answering_service()->answer_question(
                $question,
                $query,
                $when,
                $options,
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
            $message = $this->build_fallback_user_message($options);
            $source = $source === 'error' ? 'fallback_after_error' : 'fallback';
        }

        return ['message' => $message, 'source' => $source];
    }

    /**
     * Build deterministic fallback text when LLM output is unavailable.
     *
     * @param array $options
     * @return string
     */
    private function build_fallback_user_message(array $options): string {
        $count = count($options);
        if ($count === 0) {
            return 'No matching booking options found.';
        }

        return 'Found ' . $count . ' option(s).';
    }

    /**
     * Create the search-options answering service.
     *
     * @return search_options_answering_service
     */
    protected function create_search_options_answering_service(): search_options_answering_service {
        return new search_options_answering_service();
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
