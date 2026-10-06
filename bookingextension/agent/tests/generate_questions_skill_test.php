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

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\question\skills\generate_questions_skill;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\services\attachment\pdf_text_extractor;

/**
 * Contract tests for the generate_questions core skill (deterministic parts).
 *
 * @package    bookingextension_agent
 * @covers     \bookingextension_agent\local\wizard\question\skills\generate_questions_skill
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class generate_questions_skill_test extends advanced_testcase {
    /** Document block as injected by attachment_processor. */
    private const DOC_MESSAGE =
        "--- DOCUMENT: lecture.pdf ---\nMitochondria are the powerhouse of the cell.\n--- END DOCUMENT ---\n\nMake questions.";

    /**
     * Metadata reflects a mutating, course-scoped, capability-gated skill.
     */
    public function test_metadata(): void {
        $skill = new generate_questions_skill();
        $this->assertSame('question.generate_questions', $skill->get_name());
        $this->assertFalse($skill->is_read_only());
        $this->assertSame(skill_risk_class::R2, $skill->get_risk_class());
        $this->assertSame(['moodle/question:add'], $skill->get_required_native_capabilities());
        $this->assertSame(CONTEXT_COURSE, $skill->get_required_context_level());
    }

    /**
     * Structural validation accepts sane input and rejects bad count/qtypes.
     */
    public function test_check_structure(): void {
        $skill = new generate_questions_skill();
        // B4 (Georg 2026-07-14): no count => clarify, never a silent default.
        $this->assertFalse($skill->check_structure([])['valid']);
        $this->assertContains('RECOVERABLE_INPUT_ERROR', (array)($skill->check_structure([])['issue_codes'] ?? []));
        $this->assertTrue($skill->check_structure(['count' => 5, 'qtypes' => ['multichoice', 'truefalse']])['valid']);
        $this->assertFalse($skill->check_structure(['count' => 0])['valid']);
        $this->assertFalse($skill->check_structure(['count' => 9999])['valid']);
        $this->assertFalse($skill->check_structure(['qtypes' => ['essay']])['valid']);
    }

    /**
     * Preflight blocks only when neither an uploaded document nor inline content is available.
     */
    public function test_preflight_requires_a_source(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$contextid, $userid] = $this->make_context_and_user();
        $store = new conversation_store();
        $thread = $store->get_or_create_thread($userid, $contextid);
        $store->add_message((int)$thread->id, 'user', 'Please make questions.');

        $result = (new generate_questions_skill())->preflight([], $contextid, $userid)->to_array();

        $this->assertSame('hard_block', $result['status']);
        $this->assertContains('GENERATE_QUESTIONS_NO_SOURCE', $result['issue_codes']);
    }

    /**
     * Preflight passes with inline content and the capability present, without any uploaded document.
     */
    public function test_preflight_passes_with_inline_content(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid] = $this->make_context_and_user();
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', 'Make a question. Correct answer: Bretagne.');

        $input = ['content' => 'Where are we going on holiday this year? The correct answer is Bretagne.'];
        $result = (new generate_questions_skill())->preflight($input, $contextid, (int)$USER->id)->to_array();

        $this->assertSame('pass', $result['status']);
    }

    /**
     * Preflight blocks (Gate 2) when the user lacks moodle/question:add, even with a document.
     */
    public function test_preflight_requires_native_capability(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $contextid = (int)\context_module::instance($page->cmid)->id;

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$student->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $result = (new generate_questions_skill())->preflight([], $contextid, (int)$student->id)->to_array();

        $this->assertSame('hard_block', $result['status']);
        $this->assertContains('NO_NATIVE_CAPABILITY', $result['issue_codes']);
    }

    /**
     * Preflight passes with a document and the capability present.
     */
    public function test_preflight_passes_with_document_and_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid, $userid] = $this->make_context_and_user();
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $result = (new generate_questions_skill())->preflight([], $contextid, (int)$USER->id)->to_array();

        $this->assertSame('pass', $result['status']);
    }

    /**
     * With more than one writable category and no single default, preflight asks where to create the questions.
     *
     * Until wave 21 one bank with its default plus two further categories was enough to ask; now the bank's
     * single default is taken (test_preflight_takes_the_single_default_category), so the ambiguity needs
     * two banks, each with a default of its own.
     */
    public function test_preflight_asks_when_multiple_targets(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();
        $this->add_writable_categories($course, 2);
        $this->add_writable_categories($course, 1);

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $result = (new generate_questions_skill())->preflight([], $contextid, (int)$USER->id)->to_array();

        $this->assertSame('hard_block', $result['status']);
        $this->assertContains('GENERATE_QUESTIONS_TARGET_AMBIGUOUS', $result['issue_codes']);
    }

    /**
     * Choosing one of the offered categories lets preflight pass and threads the id into prepared input.
     */
    public function test_preflight_passes_with_chosen_target(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();
        $categoryids = $this->add_writable_categories($course, 2);

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $chosen = (int)$categoryids[0];
        $result = (new generate_questions_skill())->preflight(['target_categoryid' => $chosen], $contextid, (int)$USER->id);

        $this->assertSame('pass', $result->to_array()['status']);
        $this->assertSame($chosen, (int)$result->preparedinput['target_categoryid']);
    }

    /**
     * Naming a category in plain text resolves to its id (the planner never knows the id).
     */
    public function test_preflight_resolves_target_by_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();
        $categoryids = $this->add_writable_categories($course, 2);

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        // The add_writable_categories helper names them "Agent category 0", "Agent category 1".
        $result = (new generate_questions_skill())
            ->preflight(['target_category' => 'Agent category 1'], $contextid, (int)$USER->id);

        $this->assertSame('pass', $result->to_array()['status']);
        $this->assertSame((int)$categoryids[1], (int)$result->preparedinput['target_categoryid']);
    }

    /**
     * An unknown category name re-asks with the full list rather than failing silently.
     */
    public function test_preflight_unknown_name_reasks(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();
        $this->add_writable_categories($course, 2);

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $result = (new generate_questions_skill())
            ->preflight(['target_category' => 'Does not exist'], $contextid, (int)$USER->id)->to_array();

        $this->assertSame('hard_block', $result['status']);
        $this->assertContains('GENERATE_QUESTIONS_TARGET_AMBIGUOUS', $result['issue_codes']);
    }

    /**
     * A target id that is not one of the writable categories is rejected with a fresh clarification.
     */
    public function test_preflight_rejects_unknown_target(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();
        $this->add_writable_categories($course, 2);

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $result = (new generate_questions_skill())
            ->preflight(['target_categoryid' => 99999999], $contextid, (int)$USER->id)->to_array();

        $this->assertSame('hard_block', $result['status']);
        $this->assertContains('GENERATE_QUESTIONS_TARGET_AMBIGUOUS', $result['issue_codes']);
    }

    /**
     * Create a run (page module) context plus its course, and return [contextid, course].
     *
     * @return array{0:int,1:\stdClass}
     */
    private function make_run_context_with_course(): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        return [(int)\context_module::instance($page->cmid)->id, $course];
    }

    /**
     * Add a question bank module to the course with $count writable categories; return their ids.
     *
     * @param \stdClass $course
     * @param int       $count
     * @return int[]
     */
    private function add_writable_categories(\stdClass $course, int $count): array {
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $bankcontext = \context_module::instance($qbank->cmid);
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $category = $questiongenerator->create_question_category([
                'contextid' => $bankcontext->id,
                'name' => 'Agent category ' . $i,
            ]);
            $ids[] = (int)$category->id;
        }
        return $ids;
    }

    /**
     * No questions / no bank context => no preview block.
     */
    public function test_get_result_preview_returns_null_without_questions(): void {
        $skill = new generate_questions_skill();
        $this->assertNull($skill->get_result_preview([], 1, 1));
        $this->assertNull($skill->get_result_preview(
            ['created_question_ids' => [], 'question_bank_contextid' => 0],
            1,
            1
        ));
    }

    /**
     * A created question is rendered inline (native question rendering) into the preview block.
     */
    public function test_get_result_preview_renders_questions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $qbank = $generator->create_module('qbank', ['course' => $course->id]);
        $bankcontext = \context_module::instance($qbank->cmid);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $generator->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => $bankcontext->id]);
        $question = $questiongenerator->create_question('truefalse', null, [
            'category' => $category->id,
            'name' => 'Powerhouse of the cell',
        ]);

        $entry = [
            'created_question_ids' => [(int)$question->id],
            'question_bank_contextid' => (int)$bankcontext->id,
            'question_bank_url' => 'https://example.com/question/edit.php?cmid=' . $qbank->cmid,
        ];

        $preview = (new generate_questions_skill())->get_result_preview($entry, (int)$bankcontext->id, (int)$USER->id);

        $this->assertIsArray($preview);
        $this->assertSame('generated_questions', $preview['type']);
        $this->assertNotEmpty($preview['html']);
        // Option A: render-time JS is shipped as a separate string for the client to execute.
        $this->assertArrayHasKey('js', $preview);
        $this->assertIsString($preview['js']);
        // Native question rendering wraps each question in a div.que.
        $this->assertStringContainsString('que ', $preview['html']);
        $this->assertStringContainsString('bookingextension_agent-question-preview', $preview['html']);
        $this->assertSame([(int)$question->id], $preview['payload']['question_ids']);
    }

    /**
     * Create a course + module context and return [contextid, current user id].
     *
     * @return array{0:int,1:int}
     */
    private function make_context_and_user(): array {
        global $USER;
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        return [(int)\context_module::instance($page->cmid)->id, (int)$USER->id];
    }

    /**
     * Skip when no PDF extraction method (pdftotext or bundled parser) is available.
     *
     * @return void
     */
    private function require_extractor(): void {
        if (!(new pdf_text_extractor())->is_available()) {
            $this->markTestSkipped('no PDF extractor available');
        }
    }

    /**
     * Build a minimal, valid single-page PDF containing $text (plain ASCII, no ()\ characters);
     * xref offsets computed dynamically so it parses with pdftotext AND the bundled parser.
     *
     * @param string $text
     * @return string PDF bytes.
     */
    private function make_pdf(string $text): string {
        $stream = 'BT /F1 12 Tf 72 720 Td (' . $text . ') Tj ET';
        $objects = [
            1 => "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            2 => "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            3 => "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R"
                . " /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n",
            4 => "4 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n",
            5 => "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $body;
        }
        $xrefpos = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xrefpos . "\n%%EOF";
        return $pdf;
    }

    /**
     * Create a File resource in the course whose content file has the given name and bytes.
     *
     * @param int $courseid
     * @param string $name Activity name.
     * @param string $filename E.g. 'handout.pdf'.
     * @param string $filecontent Raw file bytes.
     * @return \stdClass The resource instance record (with cmid).
     */
    private function create_resource_with_file(int $courseid, string $name, string $filename, string $filecontent): \stdClass {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'component' => 'user',
            'filearea' => 'draft',
            'contextid' => \context_user::instance($USER->id)->id,
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $filename,
        ], $filecontent);
        return $this->getDataGenerator()->create_module('resource', [
            'course' => $courseid,
            'name' => $name,
            'intro' => 'Intro.',
            'introformat' => FORMAT_HTML,
            'files' => $draftid,
        ]);
    }

    /**
     * usecoursepdfs sources the text from the course's PDF resources and WINS over an uploaded
     * conversation document; the used files (with cmid) are threaded into the prepared input.
     */
    public function test_preflight_course_pdfs_beat_conversation_document(): void {
        $this->resetAfterTest();
        $this->require_extractor();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();
        $resource = $this->create_resource_with_file(
            (int)$course->id,
            'Handout',
            'handout.pdf',
            $this->make_pdf('Quantum gearbox maintenance basics')
        );

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $result = (new generate_questions_skill())->preflight(['usecoursepdfs' => true], $contextid, (int)$USER->id);

        $this->assertSame('pass', $result->to_array()['status']);
        $sourcetext = (string)$result->preparedinput['sourcetext'];
        $this->assertStringContainsString('--- DOCUMENT: handout.pdf ---', $sourcetext);
        $this->assertStringContainsString('Quantum gearbox maintenance basics', $sourcetext);
        // The chat-uploaded document loses against the explicit course-PDF source.
        $this->assertStringNotContainsString('Mitochondria', $sourcetext);
        $this->assertSame([(int)$resource->cmid], array_column($result->preparedinput['sourcefiles'], 'cmid'));
        $this->assertSame(['handout.pdf'], array_column($result->preparedinput['sourcefiles'], 'filename'));
    }

    /**
     * Explicit inline content still WINS over the course-PDF source (highest priority).
     */
    public function test_preflight_content_beats_course_pdfs(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();
        $this->create_resource_with_file((int)$course->id, 'Handout', 'handout.pdf', $this->make_pdf('Gearbox'));

        $input = ['content' => 'The capital of France is Paris.', 'usecoursepdfs' => true];
        $result = (new generate_questions_skill())->preflight($input, $contextid, (int)$USER->id);

        $this->assertSame('pass', $result->to_array()['status']);
        $this->assertSame('The capital of France is Paris.', (string)$result->preparedinput['sourcetext']);
        $this->assertSame([], $result->preparedinput['sourcefiles']);
    }

    /**
     * resourcecmid picks exactly ONE course PDF, even when others exist.
     */
    public function test_preflight_resourcecmid_selects_specific_pdf(): void {
        $this->resetAfterTest();
        $this->require_extractor();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();
        $this->create_resource_with_file((int)$course->id, 'Alpha', 'alpha.pdf', $this->make_pdf('Alpha wolf pack basics'));
        $beta = $this->create_resource_with_file((int)$course->id, 'Beta', 'beta.pdf', $this->make_pdf('Beta decay physics'));

        $result = (new generate_questions_skill())
            ->preflight(['resourcecmid' => (int)$beta->cmid], $contextid, (int)$USER->id);

        $this->assertSame('pass', $result->to_array()['status']);
        $sourcetext = (string)$result->preparedinput['sourcetext'];
        $this->assertStringContainsString('Beta decay physics', $sourcetext);
        $this->assertStringNotContainsString('Alpha wolf pack basics', $sourcetext);
        $this->assertSame([(int)$beta->cmid], array_column($result->preparedinput['sourcefiles'], 'cmid'));
    }

    /**
     * usecoursepdfs in a course without any (visible) PDF resource blocks with the localized
     * "no PDFs found" message instead of a raw exception.
     */
    public function test_preflight_usecoursepdfs_without_pdfs_blocks(): void {
        $this->resetAfterTest();
        $this->require_extractor();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();

        $result = (new generate_questions_skill())->preflight(['usecoursepdfs' => true], $contextid, (int)$USER->id);

        $this->assertSame('hard_block', $result->to_array()['status']);
        $this->assertContains('GENERATE_QUESTIONS_NO_COURSE_PDFS', $result->to_array()['issue_codes']);
        $this->assertSame(
            get_string('ai_generatequestions_nopdfsincourse', 'bookingextension_agent', format_string($course->fullname)),
            $result->issues[0]['message']
        );
    }

    /**
     * A resourcecmid pointing at a non-PDF resource blocks with the localized "no PDF" message.
     */
    public function test_preflight_resource_without_pdf_blocks(): void {
        $this->resetAfterTest();
        $this->require_extractor();
        $this->setAdminUser();
        global $USER;

        [$contextid, $course] = $this->make_run_context_with_course();
        $txt = $this->create_resource_with_file((int)$course->id, 'Notes', 'notes.txt', 'plain text, no pdf');

        $result = (new generate_questions_skill())
            ->preflight(['resourcecmid' => (int)$txt->cmid], $contextid, (int)$USER->id);

        $this->assertSame('hard_block', $result->to_array()['status']);
        $this->assertContains('GENERATE_QUESTIONS_RESOURCE_NO_PDF', $result->to_array()['issue_codes']);
        $this->assertSame(
            get_string('ai_generatequestions_resourcenopdf', 'bookingextension_agent', 'Notes'),
            $result->issues[0]['message']
        );
    }

    /**
     * A resourcecmid that does not exist in the target course blocks with the localized
     * "not found" message.
     */
    public function test_preflight_resourcecmid_not_found_blocks(): void {
        $this->resetAfterTest();
        $this->require_extractor();
        $this->setAdminUser();
        global $USER;

        [$contextid] = $this->make_run_context_with_course();

        $result = (new generate_questions_skill())
            ->preflight(['resourcecmid' => 99999999], $contextid, (int)$USER->id);

        $this->assertSame('hard_block', $result->to_array()['status']);
        $this->assertContains('GENERATE_QUESTIONS_RESOURCE_NOT_FOUND', $result->to_array()['issue_codes']);
        $this->assertSame(
            get_string('ai_generatequestions_resourcenotfound', 'bookingextension_agent', 99999999),
            $result->issues[0]['message']
        );
    }

    /**
     * Add a question bank whose DEFAULT category exists, plus $extra further categories.
     *
     * @param \stdClass $course
     * @param int       $extra
     * @return array{0:int,1:int[]} default category id, extra category ids
     */
    private function add_bank_with_default(\stdClass $course, int $extra): array {
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $bankcontext = \context_module::instance($qbank->cmid);
        $default = question_get_default_category($bankcontext->id, true);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $ids = [];
        for ($i = 0; $i < $extra; $i++) {
            $ids[] = (int)$questiongenerator->create_question_category([
                'contextid' => $bankcontext->id, 'name' => 'Extra category ' . $i,
            ])->id;
        }
        return [(int)$default->id, $ids];
    }

    /**
     * The bank's default category is taken without asking when it is the only default among the targets.
     *
     * GQ-2, GQ-3, GQ-4 and AQ-1 ended as a question in every baseline run since run 8 although none of the
     * prompts names a category: the gate asked between "Default for ..." and a second category. Moodle's own
     * default is not an invented value (decision George 2026-09-23, wave 21).
     */
    public function test_preflight_takes_the_single_default_category(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;
        [$contextid, $course] = $this->make_run_context_with_course();
        [$defaultid, $extra] = $this->add_bank_with_default($course, 1);
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $result = (new generate_questions_skill())->preflight([], $contextid, (int)$USER->id);

        $this->assertSame('pass', $result->to_array()['status'], json_encode($result->to_array()['issue_codes'] ?? []));
        $this->assertSame($defaultid, (int)$result->preparedinput['target_categoryid']);
        $this->assertNotContains($defaultid, $extra);
        // The confirmation card reads the prepared input and must say where the questions will land.
        $this->assertStringContainsString('(default category)', (string)$result->preparedinput['target_categorylabel']);
        $card = (new generate_questions_skill())->describe_proposed_action($result->preparedinput);
        $labels = array_column($card['rows'], 'value', 'label');
        $this->assertStringContainsString('(default category)', (string)($labels['Category'] ?? ''));
    }

    /**
     * Two banks with a default each: nothing is "the" default, the gate still asks.
     */
    public function test_preflight_still_asks_between_two_defaults(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;
        [$contextid, $course] = $this->make_run_context_with_course();
        $this->add_bank_with_default($course, 0);
        $this->add_bank_with_default($course, 0);
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $result = (new generate_questions_skill())->preflight([], $contextid, (int)$USER->id)->to_array();

        $this->assertSame('hard_block', $result['status']);
        $this->assertContains('GENERATE_QUESTIONS_TARGET_AMBIGUOUS', $result['issue_codes']);
    }

    /**
     * A category the user named still wins over the default.
     */
    public function test_a_named_category_beats_the_default(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        global $USER;
        [$contextid, $course] = $this->make_run_context_with_course();
        [$defaultid, $extra] = $this->add_bank_with_default($course, 1);
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, $contextid);
        $store->add_message((int)$thread->id, 'user', self::DOC_MESSAGE);

        $input = ['target_category' => 'Extra category 0'];
        $result = (new generate_questions_skill())->preflight($input, $contextid, (int)$USER->id);

        $this->assertSame('pass', $result->to_array()['status']);
        $this->assertSame($extra[0], (int)$result->preparedinput['target_categoryid']);
    }
}
