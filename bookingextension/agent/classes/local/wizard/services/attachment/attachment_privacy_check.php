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

namespace bookingextension_agent\local\wizard\services\attachment;

use bookingextension_agent\local\wizard\privacy_anonymizer;

/**
 * What the privacy check finds in the attachments that wait for a message.
 *
 * The text of an uploaded PDF becomes part of the user message. It is masked like the message
 * (full names of site users and e-mail addresses) when the message is sent. This class tells the
 * user beforehand what will be masked, and which attachments cannot be checked at all: an image,
 * or a PDF without readable text such as a scan. It changes nothing and stores nothing.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attachment_privacy_check {
    /** @var privacy_anonymizer */
    private privacy_anonymizer $anonymizer;

    /** @var attachment_token_service */
    private attachment_token_service $tokens;

    /** @var pdf_text_extractor */
    private pdf_text_extractor $extractor;

    /**
     * Constructor.
     *
     * @param privacy_anonymizer $anonymizer
     * @param attachment_token_service|null $tokens
     * @param pdf_text_extractor|null $extractor
     */
    public function __construct(
        privacy_anonymizer $anonymizer,
        ?attachment_token_service $tokens = null,
        ?pdf_text_extractor $extractor = null
    ) {
        $this->anonymizer = $anonymizer;
        $this->tokens = $tokens ?? new attachment_token_service();
        $this->extractor = $extractor ?? new pdf_text_extractor();
    }

    /**
     * Count what will be masked in the attachments, and the attachments that cannot be checked.
     *
     * With the anonymizer switched off nothing is checked and nothing is reported.
     *
     * @param array $attachments Items with 'token' and 'type', as the chat panel sends them.
     * @param int $userid
     * @param int $contextid
     * @param int $threadid
     * @return array{names:int, emails:int, unchecked:int}
     */
    public function report(array $attachments, int $userid, int $contextid, int $threadid): array {
        $report = ['names' => 0, 'emails' => 0, 'unchecked' => 0];
        if (!$this->anonymizer->should_anonymize_llm_backend_data()) {
            return $report;
        }
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $token = trim((string)($attachment['token'] ?? ''));
            $type = trim((string)($attachment['type'] ?? ''));
            if ($token === '' || !in_array($type, ['pdf', 'image'], true)) {
                continue;
            }
            try {
                $resolved = $this->tokens->resolve($token, $userid, $contextid);
            } catch (\moodle_exception $e) {
                continue;
            }
            if ($type === 'image') {
                $report['unchecked']++;
                continue;
            }
            try {
                $text = $this->extractor->extract((string)$resolved['path']);
            } catch (\moodle_exception $e) {
                $text = '';
            }
            if (trim($text) === '') {
                $report['unchecked']++;
                continue;
            }
            $masked = $this->anonymizer->anonymize_document_text($threadid, $text, false);
            $report['names'] += $masked['names'];
            $report['emails'] += $masked['emails'];
        }
        return $report;
    }
}
