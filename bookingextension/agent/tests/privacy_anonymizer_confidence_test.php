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
 * Name-collision confidence contract of the privacy anonymizer (#2226 D0).
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
 * Token-map entries must carry a deterministic confidence marker: adjacent
 * first+last name hits (pass 1) are high confidence, single-word fallback hits
 * (pass 2, the "Herbst" collision class) are low confidence. The engine exposes
 * the low-confidence tokens per thread so planner contract and preflight gate
 * can act on engine state instead of language heuristics.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer
 */
final class privacy_anonymizer_confidence_test extends \advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Create the collision user, a thread and the anonymizer under strict mode.
     *
     * @return array{0: privacy_anonymizer, 1: conversation_store, 2: int}
     */
    private function prepare(): array {
        global $USER;
        $this->setAdminUser();
        $this->getDataGenerator()->create_user([
            'firstname' => 'Goduuara',
            'lastname' => 'Herbst',
            'email' => 'goduuara.herbst@example.com',
        ]);
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$USER->id, (int)\context_system::instance()->id)->id;

        return [new privacy_anonymizer($store), $store, $threadid];
    }

    /**
     * Pass-2 single-word hits are marked low confidence and exposed as suspects.
     */
    public function test_single_word_hit_is_low_confidence_suspect(): void {
        $this->resetAfterTest();
        [$anonymizer, $store, $threadid] = $this->prepare();

        $this->assertTrue(
            method_exists($anonymizer, 'get_low_confidence_suspects'),
            'privacy_anonymizer must expose get_low_confidence_suspects(int $threadid): array (#2226 D0).'
        );

        $result = $anonymizer->precheck_user_message($threadid, 'Was wird im Herbst alles angeboten?');
        $this->assertStringNotContainsString('Herbst', (string)$result['sanitizedmessage']);

        $map = (array)$store->get_thread_metadata_value($threadid, 'privacy_anon_map');
        $entries = (array)($map['entries'] ?? []);
        $this->assertNotEmpty($entries);
        $entry = null;
        foreach ($entries as $candidate) {
            if (is_array($candidate) && (string)($candidate['original'] ?? '') === 'Herbst') {
                $entry = $candidate;
                break;
            }
        }
        $this->assertIsArray($entry, 'The single-word "Herbst" hit must be present in the token map.');
        $this->assertSame('low', (string)($entry['confidence'] ?? ''), 'Pass-2 hits carry confidence=low.');

        $suspects = $anonymizer->get_low_confidence_suspects($threadid);
        $this->assertContains('Herbst', array_values($suspects), 'Suspects map token => original word.');

        // Precheck stays non-blocking: the demoted D1 chip must not re-introduce a blocking status.
        $this->assertFalse((bool)$result['blocked']);
    }

    /**
     * Pass-1 full-name hits are high confidence and never listed as suspects.
     */
    public function test_full_name_hit_is_high_confidence(): void {
        $this->resetAfterTest();
        [$anonymizer, $store, $threadid] = $this->prepare();

        $anonymizer->precheck_user_message($threadid, 'Schreibe Goduuara Herbst in den Kurs ein.');

        $map = (array)$store->get_thread_metadata_value($threadid, 'privacy_anon_map');
        $entry = null;
        foreach ((array)($map['entries'] ?? []) as $candidate) {
            if (is_array($candidate) && (string)($candidate['type'] ?? '') === 'both') {
                $entry = $candidate;
                break;
            }
        }
        $this->assertIsArray($entry, 'The adjacent full-name hit must mint a "both" entry.');
        $this->assertSame('high', (string)($entry['confidence'] ?? ''), 'Pass-1 hits carry confidence=high.');

        $this->assertSame([], $anonymizer->get_low_confidence_suspects($threadid));
    }

    /**
     * Legacy map entries without the confidence field are treated as high confidence.
     */
    public function test_legacy_entries_without_confidence_are_high(): void {
        $this->resetAfterTest();
        [$anonymizer, $store, $threadid] = $this->prepare();

        $store->set_thread_metadata_value($threadid, 'privacy_anon_map', [
            'nextid' => 2,
            'entries' => [
                'ANON_USER_1_lastname' => [
                    'identitykey' => 'user:999',
                    'type' => 'lastname',
                    'value' => 'Herbst',
                    'original' => 'Herbst',
                    'variants' => ['lastname' => 'Herbst'],
                ],
            ],
        ]);

        $this->assertSame(
            [],
            $anonymizer->get_low_confidence_suspects($threadid),
            'Entries persisted before the confidence field existed must be treated as high confidence.'
        );
    }
}
