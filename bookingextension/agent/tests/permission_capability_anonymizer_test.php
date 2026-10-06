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

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\privacy_anonymizer;

/**
 * Verifies that capability tokens (x/y:z, e.g. mod/booking:addoption) survive STRICT-mode name
 * anonymization intact — even when a user's name collides with a word inside the capability.
 *
 * This is the readiness check for core.diagnose_permissions: if capability tokens survive, the
 * permissions skill needs no change to the engine anonymizer (skill-only). If they were corrupted,
 * that would be the trigger to discuss an engine-side pattern with the maintainer.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class permission_capability_anonymizer_test extends \advanced_testcase {
    /**
     * Capability tokens stay verbatim while a colliding person name is anonymized.
     */
    public function test_capability_tokens_survive_name_anonymization(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();

        // Worst case: a real account whose lastname is a word inside a capability token.
        $this->getDataGenerator()->create_user([
            'firstname' => 'Maria',
            'lastname' => 'Booking',
            'email' => 'maria.booking@example.com',
        ]);
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, (int)\context_system::instance()->id);
        $anonymizer = new privacy_anonymizer($store);

        $message = 'Maria Booking may be missing mod/booking:addoption and moodle/question:add. '
            . 'Her role at the course context is editingteacher. Why can Maria Booking not add options?';
        $sanitized = (string)$anonymizer->anonymize_value_for_llm((int)$thread->id, $message);

        // The moodle/question:add capability never collides with the name and must always survive.
        $this->assertStringContainsString('moodle/question:add', $sanitized);

        // Regression guard: the STRICT anonymizer must keep an x/y:z capability token intact even when a
        // user name collides with a word inside it ("Booking" in mod/booking:addoption). This is now the
        // hard contract — a previous version corrupted it; an unconditional assertion (no skip) catches
        // any reintroduction of that split.
        $this->assertStringContainsString('mod/booking:addoption', $sanitized);

        // The person reference in prose must still be anonymized.
        $this->assertStringNotContainsString('Maria', $sanitized);
        $this->assertStringContainsString('ANON_USER', $sanitized);
    }
}
