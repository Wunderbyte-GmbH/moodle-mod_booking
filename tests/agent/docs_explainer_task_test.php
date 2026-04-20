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

namespace mod_booking;

require_once __DIR__ . '/abstract_agent_testcase.php';

use mod_booking\local\wbagent\booking\tasks\explain_docs_topic_task;
use mod_booking\local\wbagent\services\docs_answering_service;
use mod_booking\local\wbagent\task_registry;

/**
 * TDD tests for a docs-based explanation task.
 *
 * @package    mod_booking
 * @category   test
 */
final class docs_explainer_task_test extends abstract_agent_testcase {

    /**
     * New docs explain task must be registered in the default task registry.
     */
    public function test_docs_explain_task_is_registered(): void {
        $task = task_registry::make_default()->get_task('booking.explain_docs_topic');

        $this->assertNotNull($task);
        $this->assertSame('booking.explain_docs_topic', $task->get_name());
        $this->assertTrue($task->is_read_only());
    }

    /**
     * Docs explain task requires a user question.
     */
    public function test_docs_explain_task_requires_question(): void {
        $task = task_registry::make_default()->get_task('booking.explain_docs_topic');

        $this->assertNotNull($task);
        $validation = $task->validate([], (int)$this->booking->cmid);

        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['errors']);
    }

    /**
     * Docs explain task finds the bookotheroptions documentation and returns source metadata.
     */
    public function test_docs_explain_task_finds_bookotheroptions_doc(): void {
        $result = $this->exec_command('booking.explain_docs_topic', [
            'question' => 'What does the bookotheroptions function mean?',
        ]);

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $this->assertArrayHasKey('docs', $result);
        $this->assertNotEmpty($result['docs']);

        $firstdoc = $result['docs'][0] ?? [];
        $this->assertSame('actions_after_booking/bookotheroptions.md', (string)($firstdoc['path'] ?? ''));
        $this->assertSame('Action After Booking: Book Other Options', (string)($firstdoc['title'] ?? ''));
        $this->assertStringContainsString(
            'automatically books',
            strtolower((string)($firstdoc['excerpt'] ?? ''))
        );
    }

    /**
     * Docs explain task returns a concise task-authored summary derived from the matched documentation.
     */
    public function test_docs_explain_task_returns_concise_summary(): void {
        $result = $this->exec_command('booking.explain_docs_topic', [
            'question' => 'Explain bookotheroptions briefly.',
        ]);

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $summary = (string)($result['usermessage'] ?? $result['detail'] ?? '');

        $this->assertNotSame('', trim($summary));
        $this->assertStringNotContainsString('```', $summary);
        $this->assertStringContainsString('book', strtolower($summary));
        $this->assertStringContainsString('other option', strtolower($summary));
    }

    /**
     * Docs explain task asks for clarification when a question matches multiple documentation files.
     */
    public function test_docs_explain_task_reports_ambiguity_for_generic_action_questions(): void {
        $task = task_registry::make_default()->get_task('booking.explain_docs_topic');

        $this->assertNotNull($task);
        $validation = $task->validate([
            'question' => 'Explain the actions after booking.',
        ], (int)$this->booking->cmid);

        $this->assertFalse($validation['valid']);
        $this->assertNotEmpty($validation['ambiguities']);
        $ambiguity = implode("\n", $validation['ambiguities']);
        $this->assertStringContainsString('book other options', strtolower($ambiguity));
        $this->assertStringContainsString('cancel booking', strtolower($ambiguity));

        $options = $validation['ambiguity_options'] ?? [];
        $this->assertIsArray($options);
        $this->assertNotEmpty($options);

        $first = $options[0] ?? [];
        $this->assertNotSame('', trim((string)($first['label'] ?? '')));
        $this->assertNotSame('', trim((string)($first['query'] ?? '')));
        $this->assertStringContainsString('documentation topic', strtolower((string)($first['query'] ?? '')));
    }

    /**
     * Clear doc hits should use the answering service and forward outputlang.
     */
    public function test_docs_explain_task_uses_answering_service_for_clear_match(): void {
        $captured = [];
        $task = new class ($captured) extends explain_docs_topic_task {
            /** @var array<string,mixed> */
            private array $captured;

            /**
             * @param array<string,mixed> $captured
             */
            public function __construct(array &$captured) {
                parent::__construct();
                $this->captured = &$captured;
            }

            /**
             * Create a fake answering service for the test.
             *
             * @return docs_answering_service
             */
            protected function create_docs_answering_service(): docs_answering_service {
                return new class ($this->captured) extends docs_answering_service {
                    /** @var array<string,mixed> */
                    private array $captured;

                    /**
                     * Create the fake answering service.
                     *
                     * @param array<string,mixed> $captured
                     */
                    public function __construct(array &$captured) {
                        $this->captured = &$captured;
                    }

                    /**
                     * Return a synthetic LLM answer and capture the request payload.
                     *
                     * @param array<string,mixed> $doc
                     * @return array<string,mixed>
                     */
                    public function answer_question(
                        string $question,
                        array $doc,
                        string $outputlang,
                        int $cmid,
                        int $userid
                    ): array {
                        $this->captured = [
                            'question' => $question,
                            'docpath' => (string)($doc['path'] ?? ''),
                            'outputlang' => $outputlang,
                            'cmid' => $cmid,
                            'userid' => $userid,
                        ];
                        return [
                            'answer' => 'LLM says: Book Other Options automatically books related options for the same user.',
                        ];
                    }
                };
            }
        };

        $result = $task->execute([
            'question' => 'Was bedeutet bookotheroptions?',
            'outputlang' => 'de',
        ], (int)$this->booking->cmid, (int)$this->teacher->id);

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $this->assertSame(
            'LLM says: Book Other Options automatically books related options for the same user.',
            (string)($result['usermessage'] ?? '')
        );
        $this->assertSame('Was bedeutet bookotheroptions?', (string)($captured['question'] ?? ''));
        $this->assertSame('actions_after_booking/bookotheroptions.md', (string)($captured['docpath'] ?? ''));
        $this->assertSame('de', (string)($captured['outputlang'] ?? ''));
    }

    /**
     * If the answering service fails, the task should fall back to the deterministic summary.
     */
    public function test_docs_explain_task_falls_back_when_answering_service_fails(): void {
        $task = new class extends explain_docs_topic_task {
            /**
             * Create a failing fake answering service for the test.
             *
             * @return docs_answering_service
             */
            protected function create_docs_answering_service(): docs_answering_service {
                return new class extends docs_answering_service {
                    /**
                     * Always fail to force deterministic fallback.
                     *
                     * @param array<string,mixed> $doc
                     * @return array<string,mixed>
                     */
                    public function answer_question(
                        string $question,
                        array $doc,
                        string $outputlang,
                        int $cmid,
                        int $userid
                    ): array {
                        throw new \RuntimeException('Synthetic answering failure');
                    }
                };
            }
        };

        $result = $task->execute([
            'question' => 'Explain bookotheroptions briefly.',
            'outputlang' => 'en',
        ], (int)$this->booking->cmid, (int)$this->teacher->id);

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $summary = strtolower((string)($result['usermessage'] ?? ''));
        $this->assertStringContainsString('book', $summary);
        $this->assertStringContainsString('other option', $summary);
        $this->assertStringNotContainsString('synthetic answering failure', $summary);
    }
}
