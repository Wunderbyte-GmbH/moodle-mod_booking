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
 * Text with a broken multibyte character must not crash the anonymizer.
 *
 * A provider that cuts its output mid-character delivers invalid UTF-8; the /u regexes then return null
 * and the next chained call fails with a TypeError, and the user gets no answer at all (thread 2095, 2026-10-01).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer
 */
final class privacy_anonymizer_invalid_utf8_test extends \advanced_testcase {
    /**
     * Build a thread and the anonymizer in strict mode.
     *
     * @return array{privacy_anonymizer,int}
     */
    private function setup_strict(): array {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');
        $store = new conversation_store();
        $thread = $store->get_or_create_thread((int)$USER->id, (int)\context_system::instance()->id);
        return [new privacy_anonymizer($store), (int)$thread->id];
    }

    /**
     * Anonymizing a message with a cut-off multibyte character returns text, not a crash.
     */
    public function test_anonymize_for_llm_survives_invalid_utf8(): void {
        [$anonymizer, $threadid] = $this->setup_strict();
        $broken = "Das Nähcafé wird sichtbar geschaltet und der Preis erh\xC3";

        $out = $anonymizer->anonymize_value_for_llm($threadid, $broken);

        $this->assertIsString($out);
        $this->assertStringContainsString('sichtbar geschaltet', $out);
    }

    /**
     * The same for the display and storage paths.
     */
    public function test_display_and_storage_paths_survive_invalid_utf8(): void {
        [$anonymizer, $threadid] = $this->setup_strict();
        $broken = "firstname=J\xC3rg, email=j.k@example.com \xE2\x82";

        $display = $anonymizer->deanonymize_message_for_display($threadid, $broken);
        $this->assertIsString($display['message']);

        $stored = $anonymizer->anonymize_value_for_storage($threadid, $broken);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('j.k@example.com', $stored);
    }
}
