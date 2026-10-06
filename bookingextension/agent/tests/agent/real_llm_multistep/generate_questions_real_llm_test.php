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

/**
 * Real-LLM end-to-end test for question.generate_questions.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../abstract_agent_testcase.php');

use bookingextension_agent\external\ai_send_message;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\services\questions\question_bank_target_resolver;

/**
 * Drives the full PDF->questions flow with a live model.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @coversNothing
 */
final class generate_questions_real_llm_test extends abstract_agent_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->require_real_llm();
        $this->enforcegeneratetextassertion = false;
    }

    /**
     * A document plus "make questions" produces questions in the course question bank.
     */
    public function test_generates_questions_from_document_into_course_qbank(): void {
        global $DB;

        $this->setUser($this->teacher);

        // Enable AI tools on the course + module (as the other real-LLM tests do).
        $this->course->enableaitools = 1;
        $DB->update_record('course', $this->course);
        $cmrecord = $DB->get_record('course_modules', ['id' => (int)$this->booking->cmid], '*', MUST_EXIST);
        $cmrecord->enableaitools = 1;
        $DB->update_record('course_modules', $cmrecord);
        rebuild_course_cache((int)$this->course->id, true);

        $contextid = (int)\context_module::instance((int)$this->booking->cmid)->id;

        $store = new conversation_store();
        $thread = $store->create_fresh_thread((int)$this->teacher->id, $contextid);
        $threadid = (int)$thread->id;
        $store->allow_confirmation_for_thread((int)$this->teacher->id, $contextid, $threadid);

        // Simulate the uploaded PDF: the attachment processor injects this DOCUMENT block.
        $document = "--- DOCUMENT: photosynthesis.pdf ---\n"
            . "Photosynthesis is the process by which green plants convert sunlight, water and carbon dioxide "
            . "into glucose and oxygen. It takes place in the chloroplasts, mostly in the leaves. The green "
            . "pigment chlorophyll absorbs the light energy that drives the reaction.\n"
            . "--- END DOCUMENT ---";
        $prompt = $document . "\n\nPlease create 3 multiple-choice questions of medium difficulty "
            . "from this document and add them to the question bank in the default category.";

        $_POST['sesskey'] = sesskey();
        $response = ai_send_message::execute($contextid, $prompt, $threadid);

        // A mutating R2 skill should request confirmation; confirm it.
        if ((string)($response['response_type'] ?? '') === 'confirmation_request') {
            $response = $this->confirm_pending_result($response, $threadid, $store, false);
        }

        // Ground truth: questions exist in the course question bank context.
        $target = (new question_bank_target_resolver())
            ->resolve_for_context(\context_module::instance((int)$this->booking->cmid));

        $count = $DB->count_records_sql(
            "SELECT COUNT(qbe.id)
               FROM {question_bank_entries} qbe
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE qc.contextid = :contextid",
            ['contextid' => (int)$target['context']->id]
        );

        $this->assertGreaterThan(
            0,
            $count,
            "Expected at least one question in the course question bank.\n" . $this->payload_text($response)
        );
    }
}
