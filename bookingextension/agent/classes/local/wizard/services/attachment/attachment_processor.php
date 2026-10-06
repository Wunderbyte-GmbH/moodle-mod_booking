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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\attachment;

/**
 * Augments a user message with attachment content before it is stored.
 *
 * This is the single injection point between the upload pipeline and the
 * agent's message store. The framework (orchestrator, runtime, LLM call)
 * needs no changes — it receives a regular string.
 *
 * Rules:
 *  - PDF attachments: text is extracted server-side and injected before the
 *    user's message. The token is consumed (invalidated) immediately.
 *  - Image attachments: a compact text hint is prepended so the LLM can
 *    reference the token as a skill parameter. The token stays alive so a
 *    skill can later resolve it via attachment_token_service::resolve().
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attachment_processor {
    /**
     * Augment a user message with content from attachments.
     *
     * @param string $message     Original user message text.
     * @param array  $attachments Array of ['token'=>string, 'type'=>string] entries.
     * @param int    $userid      Current user id (for token ownership check).
     * @param int    $contextid   Moodle context id.
     * @return string Augmented message ready to store.
     */
    public function augment_message(string $message, array $attachments, int $userid, int $contextid): string {
        if (empty($attachments)) {
            return $message;
        }

        $tokensvc  = new attachment_token_service();
        $extractor = new pdf_text_extractor();
        $prefixes  = [];

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $token = trim((string)($attachment['token'] ?? ''));
            $type  = trim((string)($attachment['type'] ?? ''));

            if ($token === '' || $type === '') {
                continue;
            }

            try {
                $resolved = $tokensvc->resolve($token, $userid, $contextid);
            } catch (\moodle_exception $e) {
                // Expired or invalid token — skip silently; the message still goes through.
                continue;
            }

            $filename = (string)$resolved['filename'];

            if ($type === 'pdf') {
                // Extract text and inject as document block.
                // Token is consumed here — the PDF file is not needed again.
                try {
                    $text = $extractor->extract((string)$resolved['path']);
                    $prefixes[] = "--- DOCUMENT: {$filename} ---\n{$text}\n--- END DOCUMENT ---";
                } catch (\moodle_exception $e) {
                    $prefixes[] = "--- DOCUMENT: {$filename} ---\n"
                        . "[Could not be processed: " . $e->getMessage() . "]\n--- END DOCUMENT ---";
                }
                $tokensvc->invalidate($token);
            } else if ($type === 'image') {
                // Prepend a compact text hint. Token stays alive for skill resolution.
                $prefixes[] = "[Attachment: {$filename} — Attachment-Token: {$token}]";
            }
            // Unknown types are silently ignored.
        }

        if (empty($prefixes)) {
            return $message;
        }

        // The request is the instruction, the attachment is material: material follows the request.
        // Baseline runs 25-32, AQ-1: with the document first, the selector had read the script before it
        // saw "a test with 15 questions from it" and chose the skill that generates questions from a
        // document instead of the one that creates the quiz. Consumers find the blocks by their markers.
        return $message . "\n\n" . implode("\n\n", $prefixes);
    }
}
