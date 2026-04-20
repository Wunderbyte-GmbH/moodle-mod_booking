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

use mod_booking\local\wbagent\interfaces\task_trigger_provider_interface;
use mod_booking\local\wbagent\services\docs_answering_service;
use mod_booking\local\wbagent\services\docs_lookup_service;

/**
 * Task definition for booking.explain_docs_topic.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class explain_docs_topic_task extends base_booking_task implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.explain_docs_topic';

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
            'description' => 'Explain booking plugin features by searching the local booking/docs markdown documentation.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'The user question about a plugin feature or function documented in booking/docs.',
                    'required' => true,
                ],
                'maxdocs' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of matched documentation files to return (default 3).',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for task-authored wrapper strings, e.g. de or en.',
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
                'id' => 'booking.explain_docs_topic_feature_help',
                'description' => 'User asks what a documented booking function, action, condition, shortcode, or extension means.',
                'examples' => [
                    'What does bookotheroptions mean?',
                    'Explain the cancel booking action.',
                    'Was bedeutet bookotheroptions?',
                    'Wie funktioniert die Funktion bookotheroptions?',
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
                'id' => 'booking.docs_explanations',
                'triggers' => [
                    'what does', 'how does', 'explain feature', 'documentation',
                    'was bedeutet', 'wie funktioniert', 'erklaere', 'doku',
                ],
                'guidance' => [
                    '- If the user asks for an explanation of a documented booking feature, use booking.explain_docs_topic.',
                    '- Prefer this task over guessing from internal class names when booking/docs contains the answer.',
                    '- Return a brief explanation grounded in the matched markdown file(s).',
                ],
            ],
        ];
    }

    /**
     * Validate task input.
     *
     * @param array<string,mixed> $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>,
     *      ambiguity_options?:array<int,array<string,mixed>>}
     */
    public function validate(array $input, int $cmid): array {
        $errors = [];
        $ambiguities = [];
        $ambiguityoptions = [];
        $lang = $this->get_output_language($input);

        $question = trim((string)($input['question'] ?? ''));
        if ($question === '') {
            $errors[] = $this->localized_string('ai_docs_explain_required_question', null, $lang);
        }

        if (isset($input['maxdocs']) && (int)$input['maxdocs'] <= 0) {
            $errors[] = $this->localized_string('ai_docs_explain_invalid_maxdocs', null, $lang);
        }

        if (empty($errors) && $question !== '') {
            $service = $this->create_docs_lookup_service();
            $docs = $service->search($question, 5);
            if ($service->is_ambiguous($docs)) {
                $candidates = $service->get_ambiguity_candidates($docs, 4);
                $ambiguities[] = $this->localized_string(
                    'ai_docs_explain_ambiguity_candidates',
                    implode('; ', $candidates),
                    $lang
                );
                $ambiguityoptions = $this->build_ambiguity_options($docs, $lang);
            }
        }

        $result = [
            'valid' => empty($errors) && empty($ambiguities),
            'errors' => $errors,
            'ambiguities' => $ambiguities,
        ];

        if (!empty($ambiguityoptions)) {
            $result['ambiguity_options'] = $ambiguityoptions;
        }

        return $result;
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
        $lang = $this->get_output_language($input);
        $question = trim((string)($input['question'] ?? ''));
        $maxdocs = isset($input['maxdocs']) ? max(1, (int)$input['maxdocs']) : 3;

        $service = $this->create_docs_lookup_service();
        $docs = $service->search($question, $maxdocs);
        if (empty($docs)) {
            $detail = $this->localized_string('ai_docs_explain_no_match', null, $lang);
            return [
                'status' => 'executed',
                'detail' => $detail,
                'usermessage' => $detail,
                'summary' => $detail,
                'resultid' => null,
                'docs' => [],
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Docs matched: 0']),
            ];
        }

        $firstdoc = $docs[0];
        $summary = $service->build_summary($firstdoc);
        $answersource = 'deterministic';
        if (!$service->is_ambiguous($docs)) {
            try {
                $answeringresult = $this->create_docs_answering_service()->answer_question(
                    $question,
                    $firstdoc,
                    $lang,
                    $cmid,
                    $userid
                );
                $llmanswer = trim((string)($answeringresult['answer'] ?? ''));
                if ($llmanswer !== '') {
                    $summary = $llmanswer;
                    $answersource = 'llm';
                }
            } catch (\Throwable $e) {
                $answersource = 'deterministic';
            }
        }
        $structureddocs = [];
        foreach ($docs as $doc) {
            $structureddocs[] = [
                'path' => (string)($doc['path'] ?? ''),
                'title' => (string)($doc['title'] ?? ''),
                'excerpt' => (string)($doc['excerpt'] ?? ''),
                'score' => (int)($doc['score'] ?? 0),
            ];
        }

        return [
            'status' => 'executed',
            'detail' => $summary,
            'usermessage' => $summary,
            'summary' => $summary,
            'resultid' => null,
            'docs' => $structureddocs,
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                [
                    'Answer source: ' . $answersource,
                    'Docs matched: ' . count($docs),
                    'Top doc: ' . (string)($firstdoc['path'] ?? ''),
                ]
            ),
        ];
    }

    /**
     * Create the docs lookup service.
     *
     * @return docs_lookup_service
     */
    protected function create_docs_lookup_service(): docs_lookup_service {
        return new docs_lookup_service();
    }

    /**
     * Create the docs answering service.
     *
     * @return docs_answering_service
     */
    protected function create_docs_answering_service(): docs_answering_service {
        return new docs_answering_service();
    }

    /**
     * Build structured ambiguity options for frontend selection.
     *
     * @param array<int,array<string,mixed>> $docs
     * @param string $lang
     * @return array<int,array<string,mixed>>
     */
    private function build_ambiguity_options(array $docs, string $lang): array {
        $options = [];
        foreach (array_slice($docs, 0, 4) as $doc) {
            $path = trim((string)($doc['path'] ?? ''));
            $title = trim((string)($doc['title'] ?? ''));
            if ($path === '' && $title === '') {
                continue;
            }

            $label = $title !== '' ? $title : $path;
            $query = $this->localized_string('ai_docs_explain_followup_query', $label, $lang);
            $options[] = [
                'id' => 'docs:' . ($path !== '' ? $path : md5($label)),
                'label' => $label,
                'query' => $query,
                'path' => $path,
                'title' => $title,
            ];
        }

        return $options;
    }
}
