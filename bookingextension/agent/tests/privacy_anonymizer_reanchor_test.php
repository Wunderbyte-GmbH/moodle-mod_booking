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
 * Tests for cross-thread token re-anchoring and the fail-closed display gate.
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
 * Thread-358 regression: anonymization placeholders surfaced from another thread (via
 * recall_memory) must be re-anchored into the current thread's map (token-to-token, never
 * clear text), and any still-unresolved placeholder must be redacted before display.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer::reanchor_value_for_thread
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer::deanonymize_message_for_display
 */
final class privacy_anonymizer_reanchor_test extends \advanced_testcase {
    /**
     * A token minted in a source thread resolves to the real name once re-anchored into the
     * current thread, and clear text never appears in the LLM-bound (re-anchored) text.
     */
    public function test_reanchor_maps_foreign_token_into_current_thread(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->getDataGenerator()->create_user([
            'firstname' => 'Estorgan',
            'lastname' => 'Forget',
            'email' => 'estorgan.forget@example.com',
        ]);
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $store = new conversation_store();
        $anonymizer = new privacy_anonymizer($store);

        $sourceid = (int)$this->fresh_thread($store, (int)$USER->id)->id;
        $targetid = (int)$this->fresh_thread($store, (int)$USER->id)->id;

        // Anonymize under the source thread's map (as a previous conversation would have been stored).
        $sourcetext = (string)$anonymizer->anonymize_value_for_llm(
            $sourceid,
            'Why can Estorgan Forget not enrol into taskflow?'
        );
        $this->assertStringContainsString('ANON_USER', $sourcetext);
        $this->assertStringNotContainsString('Estorgan', $sourcetext);

        // The target thread starts empty - its map cannot resolve the foreign token yet.
        $rawdisplay = $anonymizer->deanonymize_message_for_display($targetid, $sourcetext);
        $this->assertStringNotContainsString('Estorgan', $rawdisplay['message']);

        // Re-anchor: still token-only (no clear text leaks toward the LLM)...
        $reanchored = $anonymizer->reanchor_value_for_thread($targetid, $sourceid, $sourcetext);
        $this->assertIsString($reanchored);
        $this->assertStringContainsString('ANON_USER', $reanchored);
        $this->assertStringNotContainsString('Estorgan', $reanchored);

        // ...but now the current thread's map resolves it for display.
        $display = $anonymizer->deanonymize_message_for_display($targetid, $reanchored);
        $this->assertStringContainsString('Estorgan', $display['message']);
        $this->assertStringNotContainsString('ANON_USER', $display['message']);
        $this->assertSame(0, $display['redactedcount']);
    }

    /**
     * The same person re-anchored from different source threads merges to one identity, while a
     * distinct person stays distinct - all resolving to their real names with no token left.
     */
    public function test_reanchor_merges_same_person_and_keeps_distinct(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->getDataGenerator()->create_user([
            'firstname' => 'Estorgan',
            'lastname' => 'Forget',
            'email' => 'estorgan.forget@example.com',
        ]);
        $this->getDataGenerator()->create_user([
            'firstname' => 'Brunhilde',
            'lastname' => 'Other',
            'email' => 'brunhilde.other@example.com',
        ]);
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $store = new conversation_store();
        $anonymizer = new privacy_anonymizer($store);

        $source1 = (int)$this->fresh_thread($store, (int)$USER->id)->id;
        $source2 = (int)$this->fresh_thread($store, (int)$USER->id)->id;
        $target = (int)$this->fresh_thread($store, (int)$USER->id)->id;

        $s1text = (string)$anonymizer->anonymize_value_for_llm($source1, 'Estorgan Forget asked first.');
        $s2text = (string)$anonymizer->anonymize_value_for_llm($source2, 'Estorgan Forget and Brunhilde Other asked.');

        $r1 = $anonymizer->reanchor_value_for_thread($target, $source1, $s1text);
        $r2 = $anonymizer->reanchor_value_for_thread($target, $source2, $s2text);

        $d1 = $anonymizer->deanonymize_message_for_display($target, $r1)['message'];
        $d2 = $anonymizer->deanonymize_message_for_display($target, $r2)['message'];

        // The same person resolves consistently across both re-anchored texts; the distinct person too.
        $this->assertStringContainsString('Estorgan', $d1);
        $this->assertStringContainsString('Estorgan', $d2);
        $this->assertStringContainsString('Brunhilde', $d2);
        $this->assertStringNotContainsString('ANON_USER', $d1 . $d2);
    }

    /**
     * Fail closed: an ANON_USER token with no entry in the current thread's map (even an empty map)
     * must be redacted to a neutral label, never shown verbatim - this is the thread-358 leak.
     */
    public function test_display_redacts_unresolved_token_fail_closed(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $store = new conversation_store();
        $anonymizer = new privacy_anonymizer($store);

        // A brand-new thread with an empty token map (previously hit the early-return leak path).
        $threadid = (int)$this->fresh_thread($store, (int)$USER->id)->id;

        $leak = 'Why can ANON_USER_7_firstname not enrol into taskflow?';
        $display = $anonymizer->deanonymize_message_for_display($threadid, $leak);

        $this->assertStringNotContainsString('ANON_USER', $display['message']);
        $this->assertStringContainsString(
            get_string('ai_privacy_redacted_user', 'bookingextension_agent'),
            $display['message']
        );
        $this->assertSame(1, $display['redactedcount']);
    }

    /**
     * Command input with a token that resolves in this thread is clean; a token minted in another
     * thread stays unresolved after de-anonymization and is detected (so the executor can fail closed).
     *
     * @covers \bookingextension_agent\local\wizard\privacy_anonymizer::has_unresolved_anon_tokens
     */
    public function test_unresolved_command_input_token_is_detected(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->getDataGenerator()->create_user([
            'firstname' => 'Estorgan',
            'lastname' => 'Forget',
            'email' => 'estorgan.forget@example.com',
        ]);
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $store = new conversation_store();
        $anonymizer = new privacy_anonymizer($store);

        $sourceid = (int)$this->fresh_thread($store, (int)$USER->id)->id;
        $targetid = (int)$this->fresh_thread($store, (int)$USER->id)->id;

        // Anonymize a name in the source thread to obtain a real token.
        $sanitized = (string)$anonymizer->anonymize_value_for_llm($sourceid, 'Estorgan Forget asked.');
        $this->assertSame(1, preg_match('/\bANON_USER_\d+(?:_[a-z]+)?\b/', $sanitized, $m));
        $token = $m[0];

        // In the source thread the token resolves to the real value -> nothing unresolved.
        $resolved = $anonymizer->deanonymize_command_input($sourceid, ['userquery' => $token]);
        $this->assertFalse($anonymizer->has_unresolved_anon_tokens($resolved));

        // In another thread (empty map) the token cannot resolve and must be detected as unresolved.
        $unresolved = $anonymizer->deanonymize_command_input($targetid, ['userquery' => $token]);
        $this->assertTrue($anonymizer->has_unresolved_anon_tokens($unresolved));
    }

    /**
     * Thread-440 regression: the de-mask marker must be separated from the resolved value by a
     * space for every identity type. The email branch previously glued the marker directly onto
     * the address (e.g. "billy.teachy(at)example.com👤 pf2432"); no de-masked value may sit flush
     * against the marker.
     *
     * @covers \bookingextension_agent\local\wizard\privacy_anonymizer::deanonymize_message_for_display
     */
    public function test_display_marker_is_space_separated_for_email(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->getDataGenerator()->create_user([
            'firstname' => 'Billy',
            'lastname' => 'Teachy',
            'email' => 'billy.teachy@example.com',
        ]);
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $store = new conversation_store();
        $anonymizer = new privacy_anonymizer($store);
        $threadid = (int)$this->fresh_thread($store, (int)$USER->id)->id;

        // Obtain a real email token from this thread's map (email-shaped, see ANON_EMAIL_DOMAIN).
        $sanitized = (string)$anonymizer->anonymize_value_for_llm($threadid, 'billy.teachy@example.com');
        $this->assertSame(
            1,
            preg_match('/\bANON_USER_\d+@anon\.invalid\b/', $sanitized, $m),
            'The email address must map to an email-shaped token.'
        );
        $emailtoken = $m[0];

        // Reproduce the thread-440 shape: "Der Benutzer <emailtoken> pf2432 ...".
        $display = $anonymizer->deanonymize_message_for_display(
            $threadid,
            'Der Benutzer ' . $emailtoken . ' pf2432 wurde nicht gefunden.'
        )['message'];

        // The real value is shown, space-separated from the marker, and never glued to any character.
        $this->assertStringContainsString('billy.teachy@example.com 👤', $display);
        $this->assertStringNotContainsString('ANON_USER', $display);
        $this->assertDoesNotMatchRegularExpression('/\S👤/u', $display);
    }

    /**
     * Create a fresh active thread (own course context) so source/target maps are distinct.
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
