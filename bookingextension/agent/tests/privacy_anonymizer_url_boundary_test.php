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
 * Regression test: a name is masked only where it stands alone, never inside a URL, host name or file name.
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
 * L45 UA-3, thread 14951 (George 2026-09-27: "absolutely should not happen"): the site has a user "geo org". The URL
 * https://www.federation-sportive.example.org reached discovery, selector, constructor and synchronizer as
 * "...example.ANON_USER_1_lastname": the code-token span protected "sportive.example" but not the ".org" after it, and
 * any non-letter counted as a word boundary. Emails, URLs and host names are protected as a whole now; a name is masked
 * where it stands alone - after the start, whitespace or an opening bracket or quote, before the end, whitespace,
 * punctuation, a closing bracket or quote, a possessive, or a dot that ends the sentence. In a URL the host stays
 * intact; a path or query segment that is exactly a name is still masked. Both the LLM path and the storage path.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer
 */
final class privacy_anonymizer_url_boundary_test extends \advanced_testcase {
    /** @var privacy_anonymizer */
    private privacy_anonymizer $anonymizer;

    /** @var int */
    private int $threadid = 0;

    /**
     * Strict privacy, the colliding account of the VM ("geo org") and a second person for lists.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->create_user(['firstname' => 'Geo', 'lastname' => 'Org', 'email' => 'geo.org@example.com']);
        $this->getDataGenerator()->create_user(['firstname' => 'Anna', 'lastname' => 'Zeta', 'email' => 'anna@example.com']);
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');
        $store = new conversation_store();
        $this->threadid = (int)$store->get_or_create_thread((int)$USER->id, (int)\context_system::instance()->id)->id;
        $this->anonymizer = new privacy_anonymizer($store);
    }

    /**
     * The request of thread 14951 keeps its URL on the user-input path.
     */
    public function test_the_url_of_thread_14951_survives_the_user_input(): void {
        $message = "Le lien vers la fédération pointe vers la mauvaise adresse, corrige l'URL : la bonne adresse est "
            . 'https://www.federation-sportive.example.org.';
        $sanitized = (string)$this->anonymizer->precheck_user_message($this->threadid, $message)['sanitizedmessage'];
        $this->assertStringContainsString('https://www.federation-sportive.example.org', $sanitized);
        $this->assertStringNotContainsString('ANON_USER', $sanitized);
    }

    /**
     * URLs, host names and file names are never cut; a name as a whole path or query segment is still masked.
     */
    public function test_urls_host_names_and_file_names_stay_intact(): void {
        $intact = [
            'https://www.federation-sportive.example.org',
            'see www.example.org/docs/page for details',
            'the host example.org answers',
            'mail.intern.example.org.at is reachable',
            'open bericht.org.pdf please',
        ];
        foreach ($intact as $text) {
            $sanitized = (string)$this->anonymizer->anonymize_value_for_llm($this->threadid, $text);
            $this->assertSame($text, $sanitized, $text);
        }

        $sanitized = (string)$this->anonymizer->anonymize_value_for_llm(
            $this->threadid,
            'https://example.org/people/Org and https://10.111.0.2/user/view.php?id=5&name=Org'
        );
        $this->assertStringContainsString('https://example.org/people/ANON_USER_', $sanitized, 'host intact, segment masked');
        $this->assertStringContainsString('view.php?id=5&name=ANON_USER_', $sanitized, 'query value masked');
        $this->assertStringNotContainsString('/Org', $sanitized);

        // An email is masked as a whole and never split at its domain.
        $sanitized = (string)$this->anonymizer->anonymize_value_for_llm($this->threadid, 'write to tom@example.org today');
        $this->assertStringNotContainsString('example.ANON', $sanitized);
        $this->assertStringNotContainsString('@example.org', $sanitized);
        $this->assertMatchesRegularExpression('/ANON_USER_\d+@anon\.invalid/', $sanitized);
    }

    /**
     * A name that stands alone is masked in every position of a sentence.
     */
    public function test_a_standalone_name_is_masked_in_every_position(): void {
        $standalone = [
            'Org, please check this.',
            'Please ask Org.',
            'Please ask Org. Then continue.',
            'Is it (Org) or someone else?',
            'Ask „Org“ about it.',
            'Ask «Org» about it.',
            "Org's team is small.",
            'Org: done; Org! Org? Org;',
            'Anna/Org share the task.',
            'Geo Org asked for help.',
        ];
        foreach ($standalone as $text) {
            $sanitized = (string)$this->anonymizer->anonymize_value_for_llm($this->threadid, $text);
            $this->assertDoesNotMatchRegularExpression('/(?<![\p{L}_])Org(?![\p{L}_])/u', $sanitized, "$text -> $sanitized");
            $this->assertStringContainsString('ANON_USER_', $sanitized, $text);
        }
        // A hyphenated word is one word: a name part inside it is not a person on its own.
        $this->assertSame(
            'The Org-Einheit is new.',
            (string)$this->anonymizer->anonymize_value_for_llm($this->threadid, 'The Org-Einheit is new.')
        );
    }

    /**
     * The storage path follows the same rules once the token map knows the name.
     */
    public function test_the_storage_path_keeps_urls_and_masks_standalone_names(): void {
        // The name enters the token map through the user input, as in a real thread.
        $this->anonymizer->precheck_user_message($this->threadid, 'Please ask Org.');
        $stored = (string)$this->anonymizer->anonymize_value_for_storage(
            $this->threadid,
            'Org changed https://www.federation-sportive.example.org and example.org.'
        );
        $this->assertStringContainsString('https://www.federation-sportive.example.org', $stored);
        $this->assertStringContainsString(' example.org.', $stored);
        $this->assertStringStartsWith('ANON_USER_', $stored);
    }
}
