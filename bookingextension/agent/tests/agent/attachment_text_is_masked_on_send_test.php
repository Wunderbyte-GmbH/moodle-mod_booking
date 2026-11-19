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
 * The chat entry masks the text of an uploaded PDF before it is stored and sent.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\external\ai_send_message;
use bookingextension_agent\local\wizard\services\attachment\attachment_token_service;
use bookingextension_agent\local\wizard\services\attachment\pdf_text_extractor;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * What the model reads and what the thread stores carry tokens, not the names from the document.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\external\ai_send_message
 * @covers \bookingextension_agent\local\wizard\services\attachment\attachment_processor
 */
final class attachment_text_is_masked_on_send_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** @var string Message that names nobody. */
    private const MESSAGE = 'Please summarise the attached document.';

    /**
     * A provider is registered, the model behind it is scripted.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        $this->grant_agent_capabilities_to_editingteacher();
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
    }

    /**
     * Remove the scripted model.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
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
     * A PDF with a full name: neither the stored message nor any prompt carries the name.
     */
    public function test_the_name_from_the_document_never_reaches_the_model(): void {
        global $DB, $USER;

        if (!(new pdf_text_extractor())->is_available()) {
            $this->markTestSkipped('no PDF extractor available');
        }
        $this->getDataGenerator()->create_user(['firstname' => 'Marlene', 'lastname' => 'Zwickelhuber']);
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [, , $threadid] = $this->build_runtime();
        $threadid = (int)$threadid;
        $contextid = (int)context_module::instance((int)$this->booking->cmid)->id;

        $path = make_request_directory() . '/participants.pdf';
        file_put_contents($path, $this->make_pdf('The list was written by Marlene Zwickelhuber in May.'));
        $token = (new attachment_token_service())->create((int)$USER->id, $contextid, $path, 'application/pdf', 'participants.pdf');

        $this->install_scripted_planner([$this->planner_sufficient('Here is the summary.')]);
        ai_send_message::execute($contextid, self::MESSAGE, $threadid, json_encode([['token' => $token, 'type' => 'pdf']]));

        $stored = implode("\n", $DB->get_fieldset_select('bx_agent_ai_messages', 'content', 'threadid = ?', [$threadid]));
        $prompts = implode("\n", $this->scriptedplannerprompts);
        $this->assertNotEmpty($this->scriptedplannerprompts, 'The turn reaches the model.');
        $this->assertStringContainsString('DOCUMENT: participants.pdf', $stored);
        $this->assertStringContainsString('DOCUMENT: participants.pdf', $prompts);
        $this->assertStringNotContainsString('Zwickelhuber', $stored);
        $this->assertStringNotContainsString('Zwickelhuber', $prompts);
        $this->assertMatchesRegularExpression('/The list was written by ANON_USER_\d+\S*\s+in May/', $prompts);
    }
}
