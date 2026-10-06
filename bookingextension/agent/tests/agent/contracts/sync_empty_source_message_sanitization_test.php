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

namespace bookingextension_agent\agent\contracts;

use bookingextension_agent\local\wizard\services\synchronizer_output_contract;
use PHPUnit\Framework\TestCase;

/**
 * A sufficient source without a message must keep the sync reply even on a bad envelope.
 *
 * The placeholder may only appear when the synchronizer also delivers nothing: with an
 * empty source message there is no wording to protect, so a wrong sync envelope is
 * sanitized instead of rejected. Non-empty success messages keep the strict rejection.
 *
 * @covers \bookingextension_agent\local\wizard\services\synchronizer_output_contract
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sync_empty_source_message_sanitization_test extends TestCase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Empty-message sufficient source + sync error envelope: the sync text survives.
     */
    public function test_sync_text_survives_error_envelope_on_empty_source_message(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'sufficient',
            'message'       => '',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [['status' => 'succeeded', 'skill' => 'core.find_content']],
        ];
        $sync = [
            'response_type' => 'error',
            'message'       => 'Ich habe dazu keine Inhalte gefunden. Versuchen Sie einen anderen Suchbegriff.',
            'commands'      => [],
        ];

        $result = $contract->merge($source, $sync);

        $this->assertSame(
            'Ich habe dazu keine Inhalte gefunden. Versuchen Sie einen anderen Suchbegriff.',
            (string)($result['message'] ?? ''),
            'the composed sync reply must survive; a placeholder would replace a valid answer: '
                . json_encode($result)
        );
        $this->assertSame('sufficient', (string)($result['response_type'] ?? ''));
        $this->assertSame([], (array)($result['commands'] ?? []));
    }

    /**
     * Stray sync commands on an empty-message sufficient source are stripped, not fatal.
     */
    public function test_stray_sync_commands_are_stripped_on_empty_source_message(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'sufficient',
            'message'       => '',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [['status' => 'succeeded', 'skill' => 'core.find_content']],
        ];
        $sync = [
            'response_type' => 'sufficient',
            'message'       => 'Keine Treffer gefunden.',
            'commands'      => [['skill' => 'core.find_content', 'input' => []]],
        ];

        $result = $contract->merge($source, $sync);

        $this->assertSame('Keine Treffer gefunden.', (string)($result['message'] ?? ''));
        $this->assertSame([], (array)($result['commands'] ?? []));
    }

    /**
     * Guard stays: a sufficient source WITH a real message still rejects a sync error envelope.
     */
    public function test_nonempty_source_message_still_rejects_error_envelope(): void {
        $contract = new synchronizer_output_contract();
        $source = [
            'response_type' => 'sufficient',
            'message'       => 'Die Buchung wurde erfolgreich angelegt.',
            'commands'      => [],
            'issue_codes'   => [],
            'results'       => [['status' => 'succeeded', 'skill' => 'mod_booking.create_option']],
        ];
        $sync = [
            'response_type' => 'error',
            'message'       => 'Es ist ein Fehler aufgetreten.',
            'commands'      => [],
        ];

        $result = $contract->merge($source, $sync);

        $this->assertSame(
            'Die Buchung wurde erfolgreich angelegt.',
            (string)($result['message'] ?? ''),
            'a real success message must never be overwritten by a sync error envelope'
        );
        $this->assertContains('SYNC_RESPONSE_TYPE_ERROR_REJECTED', (array)($result['issue_codes'] ?? []));
    }
}
