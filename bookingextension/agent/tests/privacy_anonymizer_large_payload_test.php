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
 * Regression test: anonymizing a large observation must stay within the request budget.
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
 * Run-22 finding F68: wizard.recall_memory returned 2272 messages in one observation
 * (853 KB). Anonymizing it never finished - the protected-span check scanned every
 * span for every word, so cost grew with the product of the two, and PHP hit
 * max_execution_time. The user got an HTTP 500 instead of an answer.
 *
 * The budget below is deliberately generous: the point is the growth rate, not a
 * millisecond figure. Before the fix this payload took minutes.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\privacy_anonymizer
 */
final class privacy_anonymizer_large_payload_test extends \advanced_testcase {
    /** @var int Blocks in the synthetic observation; each carries two protected spans. */
    private const BLOCKS = 2000;

    /** @var float Seconds the whole anonymization may take. */
    private const BUDGET_SECONDS = 5.0;

    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * A recall-sized observation is anonymized correctly and within the budget.
     */
    public function test_large_observation_stays_within_the_request_budget(): void {
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
        $thread = $store->get_or_create_thread((int)$USER->id, (int)\context_system::instance()->id);
        $anonymizer = new privacy_anonymizer($store);

        // The shape recall_memory produces: many steps, each with a skill name and a JSON
        // key (both protected spans) plus prose that carries a real person reference.
        $block = '[STEP %d] mod_booking.search_options returned {"status": "ok"} '
            . 'while Estorgan Forget reviewed the autumn programme. ';
        $message = '';
        for ($i = 1; $i <= self::BLOCKS; $i++) {
            $message .= sprintf($block, $i);
        }

        $started = microtime(true);
        $sanitized = (string)$anonymizer->anonymize_value_for_llm((int)$thread->id, $message);
        $elapsed = microtime(true) - $started;

        // Correctness is unchanged: code tokens survive, the person reference does not.
        $this->assertStringContainsString('mod_booking.search_options', $sanitized);
        $this->assertStringContainsString('{"status": "ok"}', $sanitized);
        $this->assertStringNotContainsString('Estorgan', $sanitized);
        $this->assertStringContainsString('ANON_USER', $sanitized);

        $this->assertLessThan(
            self::BUDGET_SECONDS,
            $elapsed,
            sprintf(
                'Anonymizing %d blocks took %.1fs. The protected-span check must not grow '
                . 'with words x spans (run-22 finding F68).',
                self::BLOCKS,
                $elapsed
            )
        );
    }
}
