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
 * Regression test: name anonymization must never rewrite namespaced code tokens.
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
 * Thread-288 regression: a user whose lastname collides with a skill-name word
 * ("forget") must not corrupt skill names / trigger ids in LLM-bound text.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer
 */
final class privacy_anonymizer_code_token_test extends \advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }
    /**
     * Skill names and trigger ids stay intact while prose names are anonymized.
     */
    public function test_code_tokens_survive_name_anonymization(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        // The thread-288 collision: a real account whose lastname is a skill verb.
        $this->getDataGenerator()->create_user([
            'firstname' => 'Estorgan',
            'lastname' => 'Forget',
            'email' => 'estorgan.forget@example.com',
        ]);
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, (int)\context_system::instance()->id);
        $anonymizer = new privacy_anonymizer($store);

        $message = 'Use wizard.forget (trigger wizard.forget_request) or mod_booking.book_users. '
            . 'Command payload: {"forget": true, "memory": "x"}. '
            . 'Estorgan Forget asked us to forget his stored preference.';
        $sanitized = (string)$anonymizer->anonymize_value_for_llm((int)$thread->id, $message);

        // Code tokens must survive verbatim.
        $this->assertStringContainsString('wizard.forget', $sanitized);
        $this->assertStringContainsString('wizard.forget_request', $sanitized);
        $this->assertStringContainsString('mod_booking.book_users', $sanitized);
        $this->assertStringContainsString('{"forget": true', $sanitized);
        $this->assertStringNotContainsString('core.ANON_USER', $sanitized);

        // The person reference in prose must still be anonymized.
        $this->assertStringNotContainsString('Estorgan', $sanitized);
        $this->assertStringContainsString('ANON_USER', $sanitized);
    }
}
