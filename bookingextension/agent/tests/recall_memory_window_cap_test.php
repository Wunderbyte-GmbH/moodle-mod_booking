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
 * Regression test: a date window recalls a bounded number of messages.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\wizard\skills\recall_memory_skill;

/**
 * Run-22 finding F68: mode "date_window" merged every message of every thread in the
 * window. For one day of baseline runs that was 2272 messages in a single observation,
 * and nothing downstream could carry it.
 *
 * A day with more history than fits is a normal day, not an error - so the skill takes
 * the most recent messages and says that it did.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\wizard\skills\recall_memory_skill
 */
final class recall_memory_window_cap_test extends \advanced_testcase {
    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * More messages in the window than the cap: the newest survive and the count is honest.
     */
    public function test_date_window_keeps_the_most_recent_messages_and_says_so(): void {
        global $DB;
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $contextid = (int)\context_system::instance()->id;

        $store = new conversation_store();
        $limit = recall_memory_skill::WINDOW_MESSAGE_LIMIT;
        $total = $limit + 25;

        // One day of real use, more than the window can carry.
        $day = make_timestamp(2026, 9, 18, 9, 0, 0);
        $thread = $store->get_or_create_thread((int)$user->id, $contextid);
        $DB->set_field('bx_agent_ai_threads', 'timecreated', $day, ['id' => $thread->id]);
        for ($i = 0; $i < $total; $i++) {
            $id = $store->add_message((int)$thread->id, 'user', 'message number ' . $i . ' of the day');
            $DB->set_field('bx_agent_ai_messages', 'timecreated', $day + $i, ['id' => $id]);
        }

        $skill = new recall_memory_skill();
        $result = $skill->execute(
            ['mode' => 'date_window', 'date_from' => '2026-09-18'],
            $contextid,
            (int)$user->id
        );

        $this->assertSame('executed', $result['status']);
        $this->assertCount($limit, $result['messages'], 'the window must be capped');

        // The newest are the ones worth recalling, and the oldest are the ones dropped.
        $contents = array_column($result['messages'], 'content');
        $joined = implode("\n", $contents);
        $this->assertStringContainsString('message number ' . ($total - 1) . ' of the day', $joined);
        $this->assertStringNotContainsString('message number 0 of the day', $joined);

        // The answer must not claim to carry more than it does, nor hide that it cut.
        $this->assertStringContainsString((string)$limit, (string)$result['detail']);
        $this->assertStringContainsString((string)$total, (string)$result['detail']);
    }
}
