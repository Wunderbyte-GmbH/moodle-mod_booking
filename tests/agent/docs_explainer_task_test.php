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
     * Task does not call any answering service internally.
     * The returned docs provide raw context for agent-layer LLM narration.
     */
    public function test_docs_explain_task_returns_raw_docs_without_llm_call(): void {
        $result = $this->exec_command('booking.explain_docs_topic', [
            'question' => 'Was bedeutet bookotheroptions?',
            'outputlang' => 'de',
        ]);

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $this->assertArrayHasKey('docs', $result);
        $this->assertNotEmpty($result['docs']);
        // The task-authored usermessage must be a deterministic summary, not an LLM answer.
        $usermessage = trim((string)($result['usermessage'] ?? ''));
        $this->assertNotSame('', $usermessage);
        $this->assertLessThanOrEqual(500, \core_text::strlen($usermessage));
    }

    /**
     * Task-authored user message stays within 500 characters without any LLM call.
     */
    public function test_docs_explain_task_limits_answer_to_500_characters(): void {
        $result = $this->exec_command('booking.explain_docs_topic', [
            'question' => 'What does bookotheroptions do?',
            'outputlang' => 'en',
        ]);

        $this->assertSame('executed', $result['status'], (string)($result['detail'] ?? ''));
        $summary = (string)($result['usermessage'] ?? '');
        $this->assertLessThanOrEqual(500, \core_text::strlen($summary));
    }
}
