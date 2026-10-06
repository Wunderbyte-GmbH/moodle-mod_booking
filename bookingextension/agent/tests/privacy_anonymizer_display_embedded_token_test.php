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
 * Display de-anonymization pin for tokens embedded in surrounding text (#2226).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;

/**
 * Baseline run 1 (SKILL_TEST_BASELINE.md, SO-4) surfaced answers whose stored form
 * carries variant tokens inside option titles ("Herbstwanderung Wienerwald
 * (ANON_USER_2_firstname)"). This pins the display path: every such token must
 * resolve to its original for the user, never survive as a raw placeholder.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer
 */
final class privacy_anonymizer_display_embedded_token_test extends \advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Variant tokens inside titles/parentheses resolve to their originals for display.
     */
    public function test_embedded_variant_tokens_resolve_for_display(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$USER->id, (int)\context_system::instance()->id)->id;
        $store->set_thread_metadata_value($threadid, 'privacy_anon_map', [
            'nextid' => 3,
            'entries' => [
                'ANON_USER_1_lastname' => [
                    'identitykey' => 'user:2956',
                    'type' => 'lastname',
                    'value' => 'Herbst',
                    'original' => 'Herbst',
                    'variants' => ['lastname' => 'Herbst'],
                ],
                'ANON_USER_2_firstname' => [
                    'identitykey' => 'literal:abc',
                    'type' => 'firstname',
                    'value' => 'Baseline',
                    'original' => 'Baseline',
                    'variants' => ['firstname' => 'Baseline'],
                ],
            ],
        ]);

        $anonymizer = new privacy_anonymizer($store);
        $display = $anonymizer->deanonymize_message_for_display(
            $threadid,
            'Gefunden: [Herbstwanderung Wienerwald (ANON_USER_2_firstname)](https://example.com) '
                . 'sowie Optionen zu ANON_USER_1_lastname.'
        );

        $message = (string)$display['message'];
        $this->assertStringContainsString('(Baseline', $message);
        $this->assertStringContainsString('Herbst', $message);
        $this->assertStringNotContainsString('ANON_USER', $message, 'No raw placeholder may reach the user.');
        $this->assertSame(0, (int)$display['redactedcount']);
    }
}
