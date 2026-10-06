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
 * The LLM debug log is a side channel and never decides the outcome of a turn.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\llm_debug_logger;

/**
 * F72 (baseline runs 25-27, threads 7846, 7990, 8054, 8194, 8358, 8365): the call-site string of a
 * constructor repair call ("orc|p=cons|...|em=cached_applied|tk=1|rq=0|ex=0|rp=1") is up to 102 characters,
 * bx_agent_ai_llm_debug.source held 100. The insert threw dml_write_exception AFTER the LLM had answered,
 * nothing caught it, and the user read "Fehler beim Schreiben der Datenbank" instead of the answer. The
 * column is 255 since 2026092201, the store cuts anything longer, and the logger swallows a failing store.
 *
 * @covers \bookingextension_agent\local\wizard\llm_debug_logger
 * @covers \bookingextension_agent\local\wizard\conversation_store
 */
final class llm_debug_log_never_breaks_the_turn_test extends \advanced_testcase {
    /**
     * Set up the engine aliases.
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        parent::setUp();
    }

    /**
     * A store that fails to write does not propagate into the turn.
     */
    public function test_a_failing_store_does_not_propagate(): void {
        $this->resetAfterTest();
        set_config('aidebugmode', 1, 'bookingextension_agent');

        $store = new class extends conversation_store {
            /**
             * Fail like the overflowing column did.
             *
             * @param int $threadid
             * @param int $userid
             * @param int $contextid
             * @param string $source
             * @param string $requesttext
             * @param string $responsetext
             * @param int $success
             * @param string $errormessage
             * @return int
             */
            public function add_llm_debug_entry(
                int $threadid,
                int $userid,
                int $contextid,
                string $source,
                string $requesttext,
                string $responsetext,
                int $success,
                string $errormessage = ''
            ): int {
                throw new \dml_write_exception('Data too long for column source');
            }
        };

        llm_debug_logger::log_exchange($store, 1, 1, 2, str_repeat('x', 102), 'req', 'res', true);
        $this->assertDebuggingCalledCount(1);
    }

    /**
     * A call-site string of a repair round fits, and anything longer is cut, not thrown.
     */
    public function test_a_repair_round_source_is_stored_whole_and_longer_ones_are_cut(): void {
        global $DB;
        $this->resetAfterTest();
        $store = new conversation_store();

        $repairsource = 'orc|p=cons|st=sr|ac=wpl|rt=wb|fb=0|pv=na|hm=1|ob=3|cm=embed_topk|em=cached_applied|tk=1|rq=0|ex=0|rp=1';
        $this->assertGreaterThan(100, \core_text::strlen($repairsource), 'the case that overflowed the old column');
        $id = $store->add_llm_debug_entry(1, 2, 1, $repairsource, 'req', 'res', 1);
        $this->assertSame($repairsource, $DB->get_field('bx_agent_ai_llm_debug', 'source', ['id' => $id]));

        $long = str_repeat('s', conversation_store::LLM_DEBUG_SOURCE_MAXLENGTH + 40);
        $id = $store->add_llm_debug_entry(1, 2, 1, $long, 'req', 'res', 1);
        $this->assertSame(
            conversation_store::LLM_DEBUG_SOURCE_MAXLENGTH,
            \core_text::strlen((string)$DB->get_field('bx_agent_ai_llm_debug', 'source', ['id' => $id]))
        );
    }
}
