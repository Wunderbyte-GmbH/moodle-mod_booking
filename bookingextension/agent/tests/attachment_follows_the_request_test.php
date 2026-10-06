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

use bookingextension_agent\local\wizard\services\attachment\attachment_processor;
use bookingextension_agent\local\wizard\services\attachment\attachment_token_service;

/**
 * The user's request comes first; attached material follows it.
 *
 * Baseline runs 25-32, AQ-1 (❌ in seven of eight runs): "... hätte ich gern einen Test mit 15 Fragen aus dem
 * hochgeladenen Skript" reached the selector as 300 characters of document text FIRST and the request at
 * byte 9291 of the prompt - the model had read the script before it saw what was asked, and chose the
 * skill that generates questions from a document instead of the one that creates the quiz. The request
 * is the instruction, the attachment is material: material follows the request.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\attachment\attachment_processor
 */
final class attachment_follows_the_request_test extends \advanced_testcase {
    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * The message opens the augmented text; every attachment block comes after it.
     */
    public function test_the_request_precedes_the_attachments(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $contextid = (int)\context_system::instance()->id;

        $tmp = make_request_directory() . '/skript.png';
        file_put_contents($tmp, 'not really a png');
        $tokens = new attachment_token_service();
        $token = $tokens->create((int)$USER->id, $contextid, $tmp, 'image/png', 'skript.png');

        $message = 'Zum Abschluss hätte ich gern einen Test mit 15 Fragen aus dem hochgeladenen Skript.';
        $augmented = (new attachment_processor())->augment_message(
            $message,
            [['token' => $token, 'type' => 'image']],
            (int)$USER->id,
            $contextid
        );

        $this->assertStringStartsWith($message, $augmented, 'the request opens the text');
        $this->assertStringContainsString('[Attachment: skript.png', $augmented, 'the material is still there');
        $this->assertGreaterThan(strlen($message), strpos($augmented, '[Attachment: skript.png'));
    }

    /**
     * Without attachments the message is untouched.
     */
    public function test_no_attachment_no_change(): void {
        $this->resetAfterTest();
        $this->assertSame('Hallo', (new attachment_processor())->augment_message('Hallo', [], 2, 1));
    }
}
