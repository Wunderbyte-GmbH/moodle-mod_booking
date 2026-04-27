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
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Explain booking plugin features by searching the local booking/docs markdown '
                . 'documentation and using the two best matches.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'The user question about a plugin feature or function documented in booking/docs.',
                    'required' => true,
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
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
        $errors = [];
        $question = trim((string)($input['question'] ?? ''));
        $lang = $this->get_output_language($input);

        if ($question === '') {
            $errors[] = $this->localized_string('ai_docs_explain_required_question', null, $lang);
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
        $question = trim((string)($input['question'] ?? ''));
        $outputlang = $this->get_output_language($input);

        $service = $this->create_docs_lookup_service();
        $docs = $service->search($question, 2);
        if (empty($docs)) {
            return [
                'status' => 'executed',
                'detail' => '',
                'usermessage' => '',
                'resultid' => null,
                'docs' => [],
                'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, $input, ['Docs matched: 0']),
            ];
        }

        $selecteddocs = array_slice($docs, 0, 2);
        $firstdoc = $selecteddocs[0];

        $usermessage = '';
        $answersource = 'none';
        try {
            $answeringresult = $this->create_docs_answering_service()->answer_question(
                $question,
                $selecteddocs,
                $outputlang,
                $cmid,
                $userid
            );
            $llmanswer = trim((string)($answeringresult['answer'] ?? ''));
            if ($llmanswer !== '') {
                $usermessage = $this->enforce_max_chars($llmanswer, 650);
                $answersource = 'llm';
            }
        } catch (\Throwable $e) {
            $answersource = 'error';
        }

        $structureddocs = [];
        foreach ($selecteddocs as $doc) {
            $structureddocs[] = [
                'path' => (string)($doc['path'] ?? ''),
                'title' => (string)($doc['title'] ?? ''),
                'excerpt' => (string)($doc['excerpt'] ?? ''),
                'score' => (int)($doc['score'] ?? 0),
            ];
        }

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => null,
            'docs' => $structureddocs,
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                [
                    'Docs matched: ' . count($selecteddocs),
                    'Top doc: ' . (string)($firstdoc['path'] ?? ''),
                    'Answer source: ' . $answersource,
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
}
