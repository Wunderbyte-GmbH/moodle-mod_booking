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
use bookingextension_agent\external\ai_privacy_precheck;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\attachment\attachment_processor;
use bookingextension_agent\local\wizard\services\attachment\attachment_token_service;
use bookingextension_agent\local\wizard\services\attachment\pdf_text_extractor;

/**
 * The text of an uploaded PDF is masked like the user message, and the user is told about it.
 *
 * The text of an uploaded PDF becomes part of the user message. The message itself was masked
 * before it was stored, the PDF text was appended afterwards and reached the LLM as it was. Now
 * full names of site users and e-mail addresses in it are replaced by tokens of the thread, the
 * privacy precheck says how many, and it names the attachments it cannot check.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\attachment\attachment_privacy_check
 * @covers     \bookingextension_agent\local\wizard\services\attachment\attachment_processor
 * @covers     \bookingextension_agent\external\ai_privacy_precheck
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer
 */
final class attachment_privacy_check_test extends advanced_testcase {
    /** @var string Message that names nobody. */
    private const MESSAGE = 'Please summarise the attached document.';

    /** @var string Document text with two full names, one e-mail address and one single last name. */
    private const TEXT = 'Present were Marlene Zwickelhuber and Ottokar Brandstaetter. '
        . 'Write to marlene.z@example.org. The Kranich flew over the course.';

    /**
     * Shared fixture: the agent is on, three users exist, PDF text can be read.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        if (!(new pdf_text_extractor())->is_available()) {
            $this->markTestSkipped('no PDF extractor available');
        }
        set_config('agent_enabled', 1, 'bookingextension_agent');
        $this->getDataGenerator()->create_user(['firstname' => 'Marlene', 'lastname' => 'Zwickelhuber']);
        $this->getDataGenerator()->create_user(['firstname' => 'Ottokar', 'lastname' => 'Brandstaetter']);
        $this->getDataGenerator()->create_user(['firstname' => 'Testa', 'lastname' => 'Kranich']);
        $this->setAdminUser();
        $_POST['sesskey'] = sesskey();
    }

    /**
     * Build a minimal single-page PDF with one line of text (plain ASCII, no ()\ characters).
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
     * Upload a file as the current user and return the attachment as the chat panel sends it.
     *
     * @param string $content File content.
     * @param string $type 'pdf' or 'image'.
     * @return array
     */
    private function upload(string $content, string $type = 'pdf'): array {
        global $USER;
        $filename = $type === 'pdf' ? 'participants.pdf' : 'photo.png';
        $path = make_request_directory() . '/' . $filename;
        file_put_contents($path, $content);
        $token = (new attachment_token_service())->create(
            (int)$USER->id,
            (int)\context_system::instance()->id,
            $path,
            $type === 'pdf' ? 'application/pdf' : 'image/png',
            $filename
        );
        return ['token' => $token, 'type' => $type];
    }

    /**
     * Run the precheck web service in a fresh thread.
     *
     * @param array $attachments
     * @return array
     */
    private function precheck(array $attachments): array {
        return ai_privacy_precheck::execute(
            (int)\context_system::instance()->id,
            self::MESSAGE,
            1,
            '{}',
            json_encode($attachments)
        );
    }

    /**
     * The precheck says how much will be masked, with numbers and without a name, and holds nothing back.
     */
    public function test_the_precheck_reports_what_will_be_masked(): void {
        global $DB;
        $result = $this->precheck([$this->upload($this->make_pdf(self::TEXT))]);

        $this->assertSame('ok', (string)$result['status'], json_encode($result));
        $this->assertSame(2, (int)$result['attachmentnames']);
        $this->assertSame(1, (int)$result['attachmentemails']);
        $this->assertSame(0, (int)$result['attachmentsunchecked']);
        $this->assertSame(
            get_string('ai_attachment_privacy_masked', 'bookingextension_agent', (object)['names' => 2, 'emails' => 1]),
            (string)$result['message']
        );
        $encoded = json_encode($result);
        $this->assertStringNotContainsString('Zwickelhuber', $encoded);
        $this->assertStringNotContainsString('Brandstaetter', $encoded);
        $this->assertStringNotContainsString('marlene.z@example.org', $encoded);
        $this->assertSame(0, $DB->count_records('bx_agent_ai_messages', ['threadid' => (int)$result['threadid']]));
    }

    /**
     * The message that is stored carries tokens instead of the full names and the address.
     */
    public function test_the_document_text_is_masked_in_the_message(): void {
        global $USER;
        $contextid = (int)\context_system::instance()->id;
        $store = new conversation_store();
        $threadid = (int)$store->create_fresh_thread((int)$USER->id, $contextid)->id;
        $anonymizer = new privacy_anonymizer($store);

        $message = (new attachment_processor())->augment_message(
            self::MESSAGE,
            [$this->upload($this->make_pdf(self::TEXT))],
            (int)$USER->id,
            $contextid,
            $anonymizer,
            $threadid
        );

        $this->assertStringContainsString('DOCUMENT: participants.pdf', $message);
        $this->assertStringNotContainsString('Zwickelhuber', $message);
        $this->assertStringNotContainsString('Brandstaetter', $message);
        $this->assertStringNotContainsString('marlene.z@example.org', $message);
        $this->assertSame(3, (int)preg_match_all('/ANON_USER_\d+/', $message));
        // The tokens belong to the thread: what a skill writes from the document carries the names again.
        $resolved = (string)$anonymizer->deanonymize_command_input($threadid, ['text' => $message])['text'];
        $this->assertStringContainsString('Marlene Zwickelhuber', $resolved);
        $this->assertStringContainsString('Ottokar Brandstaetter', $resolved);
        $this->assertStringContainsString('marlene.z@example.org', $resolved);
    }

    /**
     * The question skill prepares its command with the original text of the document.
     *
     * The executor resolves tokens in the input of a command before it checks the execution guard. A
     * source text that still carried tokens made every confirmed run end in EXECUTION_GUARD_MISMATCH.
     */
    public function test_the_question_skill_reads_the_document_with_the_names(): void {
        global $USER;
        $contextid = (int)\context_system::instance()->id;
        $store = new conversation_store();
        $threadid = (int)$store->create_fresh_thread((int)$USER->id, $contextid)->id;
        $anonymizer = new privacy_anonymizer($store);
        $store->add_message($threadid, 'user', (new attachment_processor())->augment_message(
            self::MESSAGE,
            [$this->upload($this->make_pdf(self::TEXT))],
            (int)$USER->id,
            $contextid,
            $anonymizer,
            $threadid
        ));

        $skill = new \bookingextension_agent\local\wizard\question\skills\generate_questions_skill();
        $skill->set_runtime_threadid($threadid);
        $method = new \ReflectionMethod($skill, 'extract_document_text');
        $method->setAccessible(true);
        $text = (string)$method->invoke($skill, $contextid, (int)$USER->id);

        $this->assertStringContainsString('Marlene Zwickelhuber', $text);
        $this->assertStringContainsString('marlene.z@example.org', $text);
        $this->assertSame(0, (int)preg_match_all('/ANON_USER_\d+/', $text));
        $this->assertSame(['text' => $text], $anonymizer->deanonymize_command_input($threadid, ['text' => $text]));
    }

    /**
     * A single word that is also a last name on this site is neither counted nor masked.
     */
    public function test_a_single_word_is_left_alone(): void {
        global $USER;
        $contextid = (int)\context_system::instance()->id;
        $store = new conversation_store();
        $threadid = (int)$store->create_fresh_thread((int)$USER->id, $contextid)->id;
        $text = 'The Kranich flew over the course while the seminar room was empty.';

        $result = $this->precheck([$this->upload($this->make_pdf($text))]);
        $message = (new attachment_processor())->augment_message(
            self::MESSAGE,
            [$this->upload($this->make_pdf($text))],
            (int)$USER->id,
            $contextid,
            new privacy_anonymizer($store),
            $threadid
        );

        $this->assertSame(0, (int)$result['attachmentnames']);
        $this->assertSame(
            get_string('ai_privacy_precheck_summary_none', 'bookingextension_agent'),
            (string)$result['message'],
            'Nothing to tell about the attachment.'
        );
        $this->assertStringContainsString('The Kranich flew', $message);
        $this->assertSame(0, (int)preg_match_all('/ANON_USER_\d+/', $message));
    }

    /**
     * An image and a PDF without readable text cannot be checked, and the user is told so.
     */
    public function test_attachments_that_cannot_be_checked_are_named(): void {
        $result = $this->precheck([
            $this->upload('not really a png', 'image'),
            $this->upload('this is not a pdf'),
        ]);

        $this->assertSame('ok', (string)$result['status'], json_encode($result));
        $this->assertSame(2, (int)$result['attachmentsunchecked']);
        $this->assertStringContainsString(
            get_string('ai_attachment_privacy_unchecked', 'bookingextension_agent', 2),
            (string)$result['message']
        );
    }

    /**
     * With the anonymizer switched off nothing is checked, reported or masked.
     */
    public function test_nothing_happens_with_the_anonymizer_off(): void {
        global $USER;
        set_config('aiprivacymode', 'off', 'bookingextension_agent');
        $contextid = (int)\context_system::instance()->id;
        $store = new conversation_store();
        $threadid = (int)$store->create_fresh_thread((int)$USER->id, $contextid)->id;

        $result = $this->precheck([$this->upload($this->make_pdf(self::TEXT)), $this->upload('x', 'image')]);
        $message = (new attachment_processor())->augment_message(
            self::MESSAGE,
            [$this->upload($this->make_pdf(self::TEXT))],
            (int)$USER->id,
            $contextid,
            new privacy_anonymizer($store),
            $threadid
        );

        $this->assertSame(0, (int)$result['attachmentnames'] + (int)$result['attachmentsunchecked']);
        $this->assertStringContainsString('Marlene Zwickelhuber', $message);
    }
}
