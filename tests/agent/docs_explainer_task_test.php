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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');

use mod_booking\local\wbagent\booking\tasks\explain_docs_topic_task;
use mod_booking\local\wbagent\services\docs_answering_service;
use mod_booking\local\wbagent\task_registry;

/**
 * TDD tests for a docs-based explanation task.
 *
 * @package    mod_booking
 * @category   test
 * @coversNothing
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
        $this->assertStringNotContainsString(str_repeat(chr(96), 3), $summary);
        $this->assertStringContainsString('book', strtolower($summary));
        $this->assertStringContainsString('other option', strtolower($summary));
    }

    /**
     * Generic questions no longer require user disambiguation.
     */
    public function test_docs_explain_task_auto_selects_top_docs_without_ambiguity_prompt(): void {
        $task = task_registry::make_default()->get_task('booking.explain_docs_topic');

        $this->assertNotNull($task);
        $validation = $task->validate([
            'question' => 'Explain the actions after booking.',
        ], (int)$this->booking->cmid);

        $this->assertTrue($validation['valid']);
        $this->assertEmpty($validation['ambiguities']);
    }

    /**
     * Clear doc hits should use the answering service and forward outputlang.
     */
    public function test_docs_explain_task_uses_answering_service_for_clear_match(): void {
        $captured = [];
        $task = new class ($captured) extends explain_docs_topic_task {
            /** @var array */
            private array $captured;

            /**
             * Create the fake explain task.
             *
             * @param array $captured
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
                    /** @var array */
                    private array $captured;

                    /**
                     * Create the fake answering service.
                     *
                     * @param array $captured
                     */
                    public function __construct(array &$captured) {
                        $this->captured = &$captured;
                    }

                    /**
                     * Return a synthetic LLM answer and capture the request payload.
                     *
                     * @param string $question
                     * @param array $docs
                     * @param string $outputlang
                     * @param int $cmid
                     * @param int $userid
                     * @return array
                     */
                    public function answer_question(
                        string $question,
                        array $docs,
                        string $outputlang,
                        int $cmid,
                        int $userid
                    ): array {
                        $docpaths = [];
                        foreach ($docs as $doc) {
                            $docpaths[] = (string)($doc['path'] ?? '');
                        }

                        $this->captured = [
                            'question' => $question,
                            'docpaths' => $docpaths,
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
        $docpaths = (array)($captured['docpaths'] ?? []);
        $this->assertNotEmpty($docpaths);
        $this->assertLessThanOrEqual(2, count($docpaths));
        $this->assertContains('actions_after_booking/bookotheroptions.md', $docpaths);
        $this->assertSame('de', (string)($captured['outputlang'] ?? ''));
    }

    /**
     * If the answering service fails, the task should fall back to a localized safe message.
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
                     * Always fail to force fallback handling.
                     *
                     * @param string $question
                     * @param array $docs
                     * @param string $outputlang
                     * @param int $cmid
                     * @param int $userid
                     * @return array
                     */
                    public function answer_question(
                        string $question,
                        array $docs,
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
        $summary = (string)($result['usermessage'] ?? '');
        $this->assertNotSame('', trim($summary));
        $this->assertStringContainsString('could not generate', strtolower($summary));
        $this->assertStringNotContainsString('synthetic answering failure', $summary);
    }

    /**
     * Docs explain task enforces a hard maximum of 500 characters for the user message.
     */
    public function test_docs_explain_task_limits_answer_to_500_characters(): void {
        $task = new class extends explain_docs_topic_task {
            /**
             * Create a fake answering service that returns an overly long answer.
             *
             * @return docs_answering_service
             */
            protected function create_docs_answering_service(): docs_answering_service {
                return new class extends docs_answering_service {
                    /**
                     * Return an answer that is intentionally longer than the allowed limit.
                     *
                     * @param string $question
                     * @param array $docs
                     * @param string $outputlang
                     * @param int $cmid
                     * @param int $userid
                     * @return array
                     */
                    public function answer_question(
                        string $question,
                        array $docs,
                        string $outputlang,
                        int $cmid,
                        int $userid
                    ): array {
                        return [
                            'answer' => str_repeat('A', 650),
                        ];
                    }
                };
            }
        };

        $result = $task->execute([
            'question' => 'What does bookotheroptions do?',
            'outputlang' => 'en',
        ], (int)$this->booking->cmid, (int)$this->teacher->id);

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $summary = (string)($result['usermessage'] ?? '');
        $this->assertLessThanOrEqual(500, \core_text::strlen($summary));
    }
}
