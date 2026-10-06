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

use advanced_testcase;
use bookingextension_agent\external\ai_privacy_precheck;

/**
 * An undecided low-confidence suspect blocks the precheck until the user decided.
 *
 * Thread 22045 (2026-09-29): the precheck answered "ok" and offered the decision chips while the
 * turn was already running. The chip click started a second turn on the same thread; the first
 * turn still ran into the person-reference gate and its reply read the answer of the second one.
 * Three overlapping turns, two final answers. The decision therefore belongs BEFORE the turn: the
 * precheck reports needs_decision, and the decision travels with the repeated precheck call.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\external\ai_privacy_precheck
 */
final class privacy_precheck_decision_test extends advanced_testcase {
    /** @var string Message whose single word collides with a user's last name. */
    private const MESSAGE = 'Der Kranich fliegt heute über den Kurs.';

    /**
     * Shared fixture: one user whose last name collides with an ordinary word.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user(['firstname' => 'Testa', 'lastname' => 'Kranich']);
        $this->getDataGenerator()->create_user(['firstname' => 'Paula', 'lastname' => 'Beispielfrau']);
        $this->setAdminUser();
        $_POST['sesskey'] = sesskey();
    }

    /**
     * Run the precheck web service in a fresh thread.
     *
     * @param string $message
     * @param array $decision Structured chip decision, empty for none.
     * @return array
     */
    private function precheck(string $message, array $decision = []): array {
        return ai_privacy_precheck::execute(
            (int)\context_system::instance()->id,
            $message,
            1,
            json_encode((object)$decision)
        );
    }

    /**
     * Number of anonymizer tokens in a text.
     *
     * @param string $text
     * @return int
     */
    private function token_count(string $text): int {
        return (int)preg_match_all('/ANON_USER_\d+/', $text);
    }

    /**
     * Without a decision the precheck blocks: needs_decision, the suspect is named, nothing is stored.
     */
    public function test_undecided_suspect_requires_a_decision(): void {
        global $DB;

        $result = $this->precheck(self::MESSAGE);

        $this->assertSame('needs_decision', (string)$result['status'], json_encode($result));
        $this->assertContains('Kranich', array_column((array)$result['suspects'], 'word'));
        $this->assertGreaterThan(0, (int)$result['threadid']);
        $this->assertSame(
            0,
            $DB->count_records('bx_agent_ai_messages', ['threadid' => (int)$result['threadid']]),
            'A blocked precheck must not store any message: no turn has started.'
        );
        $this->assertSame(1, $this->token_count((string)$result['sanitizedmessage']));
        $this->assertStringNotContainsString('Kranich', (string)$result['sanitizedmessage']);
        $this->assertNotSame('', trim((string)$result['message']), 'The user is told that a choice is needed.');
    }

    /**
     * The decision "person" in the same call unblocks the precheck and keeps the word masked.
     */
    public function test_person_decision_unblocks_and_keeps_the_mask(): void {
        $result = $this->precheck(self::MESSAGE, ['word' => 'Kranich', 'decision' => 'person']);

        $this->assertSame('ok', (string)$result['status'], json_encode($result));
        $this->assertSame([], (array)$result['suspects']);
        $this->assertSame(1, $this->token_count((string)$result['sanitizedmessage']));
        $this->assertStringNotContainsString('Kranich', (string)$result['sanitizedmessage']);
    }

    /**
     * The decision "word" in the same call unblocks the precheck and stops masking the word.
     */
    public function test_word_decision_unblocks_and_unmasks(): void {
        $result = $this->precheck(self::MESSAGE, ['word' => 'Kranich', 'decision' => 'word']);

        $this->assertSame('ok', (string)$result['status'], json_encode($result));
        $this->assertSame([], (array)$result['suspects']);
        $this->assertSame(0, $this->token_count((string)$result['sanitizedmessage']));
        $this->assertSame(self::MESSAGE, (string)$result['sanitizedmessage']);
    }

    /**
     * A decision for one word leaves the other suspect open: the precheck keeps blocking for it.
     */
    public function test_decision_for_one_word_keeps_blocking_for_the_other(): void {
        $this->getDataGenerator()->create_user(['firstname' => 'Testo', 'lastname' => 'Reiher']);

        $result = $this->precheck(
            'Der Kranich und der Reiher fliegen über den Kurs.',
            ['word' => 'Kranich', 'decision' => 'word']
        );

        $this->assertSame('needs_decision', (string)$result['status'], json_encode($result));
        $this->assertSame(['Reiher'], array_column((array)$result['suspects'], 'word'));
    }

    /**
     * A message without a low-confidence suspect is never blocked (full names are high confidence).
     */
    public function test_message_without_suspect_is_not_blocked(): void {
        $masked = $this->precheck('Bitte buche Paula Beispielfrau in den Kurs.');
        $this->assertSame('ok', (string)$masked['status'], json_encode($masked));
        $this->assertSame([], (array)$masked['suspects']);

        $plain = $this->precheck('Zeig mir alle Kurse im Herbst.');
        $this->assertSame('ok', (string)$plain['status'], json_encode($plain));
        $this->assertSame([], (array)$plain['suspects']);
    }

    /**
     * A word decided in an earlier message never blocks again.
     */
    public function test_earlier_decision_is_remembered(): void {
        $this->precheck(self::MESSAGE, ['word' => 'Kranich', 'decision' => 'person']);

        $result = $this->precheck('Wo ist der Kranich heute gebucht?');

        $this->assertSame('ok', (string)$result['status'], json_encode($result));
        $this->assertSame([], (array)$result['suspects']);
    }
}
