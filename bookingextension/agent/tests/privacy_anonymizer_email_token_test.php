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
 * Tests for the email-shaped anonymization token (ANON_USER_<n>@anon.invalid).
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
 * An anonymized email must stay email-SHAPED so the LLM still treats the value as an address
 * (full-run 2026-07-14: "teacheremail ANON_USER_1_email" made the model demand a numeric id).
 * The reserved RFC-2606 domain anon.invalid keeps the mask undeliverable, and the mask must
 * round-trip (mint -> LLM echo -> de-anonymize) and be idempotent on re-anonymization passes.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer
 */
final class privacy_anonymizer_email_token_test extends \advanced_testcase {
    /**
     * A masked email keeps email shape, round-trips back to the original, and is display-safe.
     */
    public function test_email_token_is_email_shaped_and_round_trips(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $teacher = $this->getDataGenerator()->create_user([
            'firstname' => 'Estorgan',
            'lastname' => 'Forget',
            'email' => 'estorgan.forget@example.com',
        ]);
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $store = new conversation_store();
        $anonymizer = new privacy_anonymizer($store);
        $threadid = (int)$this->fresh_thread($store, (int)$USER->id)->id;

        // Mint: the mask is email-shaped on the reserved domain, and the real address is gone.
        $sanitized = (string)$anonymizer->anonymize_value_for_llm(
            $threadid,
            'Assign teacheremail estorgan.forget@example.com to the option.'
        );
        $this->assertStringNotContainsString($teacher->email, $sanitized);
        $this->assertSame(1, preg_match('/ANON_USER_\d+@anon\.invalid/', $sanitized, $m));
        $token = $m[0];

        // Round-trip: the planner echoes the token into a command parameter -> original email.
        $resolved = $anonymizer->deanonymize_command_input($threadid, ['teacheremail' => $token]);
        $this->assertSame($teacher->email, $resolved['teacheremail']);
        $this->assertFalse($anonymizer->has_unresolved_anon_tokens($resolved));

        // Display: the token de-masks to the original address (never surfaces raw).
        $display = $anonymizer->deanonymize_message_for_display($threadid, "Trainer is {$token}.");
        $this->assertStringContainsString($teacher->email, $display['message']);
        $this->assertStringNotContainsString('ANON_USER', $display['message']);
    }

    /**
     * Re-anonymizing text that already carries the email-shaped mask must not re-tokenize it
     * (history and backend payloads pass through anonymize_value_for_llm repeatedly).
     */
    public function test_email_token_is_not_re_tokenized(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $teacher = $this->getDataGenerator()->create_user([
            'firstname' => 'Estorgan',
            'lastname' => 'Forget',
            'email' => 'estorgan.forget@example.com',
        ]);
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $store = new conversation_store();
        $anonymizer = new privacy_anonymizer($store);
        $threadid = (int)$this->fresh_thread($store, (int)$USER->id)->id;

        $sanitized = (string)$anonymizer->anonymize_value_for_llm($threadid, 'Mail: estorgan.forget@example.com');
        $this->assertSame(1, preg_match('/ANON_USER_\d+@anon\.invalid/', $sanitized, $m));
        $token = $m[0];

        // Idempotence: a second pass leaves the mask untouched (same token, no new map entry).
        $repass = (string)$anonymizer->anonymize_value_for_llm($threadid, $sanitized);
        $this->assertSame($sanitized, $repass);

        // The untouched mask still resolves to the real address afterwards.
        $resolved = $anonymizer->deanonymize_command_input($threadid, ['teacheremail' => $token]);
        $this->assertSame($teacher->email, $resolved['teacheremail']);
    }

    /**
     * Token maps persisted before the email-shaped form still resolve their legacy _email tokens.
     */
    public function test_legacy_email_suffix_token_still_resolves(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $store = new conversation_store();
        $anonymizer = new privacy_anonymizer($store);
        $threadid = (int)$this->fresh_thread($store, (int)$USER->id)->id;

        // Seed a legacy-form map entry as an old thread would have persisted it.
        $legacymap = [
            'nextid' => 2,
            'entries' => [
                'ANON_USER_1_email' => [
                    'identitykey' => 'email:old.address@example.com',
                    'type' => 'email',
                    'value' => 'old.address@example.com',
                    'original' => 'old.address@example.com',
                    'variants' => ['email' => 'old.address@example.com'],
                ],
            ],
        ];
        $setmap = new \ReflectionMethod(privacy_anonymizer::class, 'set_token_map');
        $setmap->setAccessible(true);
        $setmap->invoke($anonymizer, $threadid, $legacymap);

        $resolved = $anonymizer->deanonymize_command_input($threadid, ['teacheremail' => 'ANON_USER_1_email']);
        $this->assertSame('old.address@example.com', $resolved['teacheremail']);

        $display = $anonymizer->deanonymize_message_for_display($threadid, 'Trainer: ANON_USER_1_email');
        $this->assertStringContainsString('old.address@example.com', $display['message']);
    }

    /**
     * Create a thread in a fresh course context.
     *
     * @param conversation_store $store
     * @param int $userid
     * @return \stdClass
     */
    private function fresh_thread(conversation_store $store, int $userid): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        return $store->get_or_create_thread($userid, (int)\context_course::instance($course->id)->id);
    }
}
