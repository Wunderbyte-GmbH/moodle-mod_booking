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
 * No anonymizer token reaches the user through a display payload.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;

/**
 * Tests for the recursive display resolution of anonymizer tokens.
 *
 * The rule (HARD RULE, CLAUDE.md 2026-09-11): engine texts carry the token, the DISPLAY resolves it with a
 * marker. Baseline run 10 showed that only the scalar message was resolved: ambiguity options (rendered as
 * clickable buttons and pasted back into the input box) and preview rows still carried raw tokens, and the
 * client falls back to the unresolved message whenever the resolved one is empty.
 *
 * @covers \bookingextension_agent\local\wizard\privacy_anonymizer
 */
final class display_token_resolution_completeness_test extends \advanced_testcase {
    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * Create a thread whose token map holds one masked person.
     *
     * @return array [privacy_anonymizer, threadid, token]
     */
    private function thread_with_masked_person(): array {
        global $USER;

        $this->setAdminUser();
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$USER->id, (int)\context_system::instance()->id)->id;
        $store->set_thread_metadata_value($threadid, 'privacy_anon_map', [
            'nextid' => 2,
            'entries' => [
                'ANON_USER_1_firstname' => [
                    'identitykey' => 'user:4711',
                    'type' => 'firstname',
                    'value' => 'Ingeborg',
                    'original' => 'Ingeborg',
                    'variants' => ['firstname' => 'Ingeborg'],
                ],
            ],
        ]);

        return [new privacy_anonymizer($store), $threadid, 'ANON_USER_1_firstname'];
    }

    /**
     * A token inside a nested display payload is resolved, not left for the client to render.
     */
    public function test_nested_payload_is_resolved_for_display(): void {
        $this->resetAfterTest();

        [$anonymizer, $threadid, $token] = $this->thread_with_masked_person();
        $this->assertNotSame('', $token, 'the fixture did not produce a token');

        $payload = [
            'ambiguity_options' => [
                ['label' => "Option von $token", 'value' => $token],
                ['label' => 'Keine davon', 'value' => ''],
            ],
            'preview' => ['rows' => [['field' => 'Teilnehmer', 'value' => $token]]],
        ];

        $resolved = $anonymizer->deanonymize_value_for_display($threadid, $payload);
        $flat = json_encode($resolved, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('ANON_USER_', (string)$flat, 'a raw token survived into the display payload');
        $this->assertStringContainsString('Ingeborg', (string)$flat);
    }

    /**
     * Resolution keeps the shape: keys, nesting and non-string values are untouched.
     */
    public function test_resolution_preserves_structure(): void {
        $this->resetAfterTest();

        [$anonymizer, $threadid, $token] = $this->thread_with_masked_person();

        $payload = ['a' => ['b' => [$token, 7, true, null]], 'n' => 42];
        $resolved = $anonymizer->deanonymize_value_for_display($threadid, $payload);

        $this->assertSame(42, $resolved['n']);
        $this->assertSame(7, $resolved['a']['b'][1]);
        $this->assertTrue($resolved['a']['b'][2]);
        $this->assertNull($resolved['a']['b'][3]);
        $this->assertStringNotContainsString('ANON_USER_', (string)$resolved['a']['b'][0]);
    }

    /**
     * A token the thread map cannot resolve is redacted, never passed through.
     */
    public function test_unknown_token_is_redacted_not_leaked(): void {
        $this->resetAfterTest();

        [$anonymizer, $threadid] = $this->thread_with_masked_person();

        $resolved = $anonymizer->deanonymize_value_for_display($threadid, ['x' => 'ANON_USER_99_firstname']);

        $this->assertStringNotContainsString('ANON_USER_', (string)$resolved['x']);
    }
}
