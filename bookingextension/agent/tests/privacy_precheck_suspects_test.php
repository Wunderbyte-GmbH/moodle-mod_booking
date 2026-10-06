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
 * The precheck must SHOW its low-confidence suspects, not only count them.
 *
 * A single word colliding with some user's name is masked silently; the chip UI (the
 * designed tiebreaker, #2226 D2) can only render when the response says WHICH word was
 * a suspect. Without it the sentence degrades and nobody ever learns why (L6-P1).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\external\ai_privacy_precheck
 */
final class privacy_precheck_suspects_test extends advanced_testcase {
    /**
     * A colliding single word must come back as a suspect {token, word} pair.
     */
    public function test_low_confidence_suspects_are_returned(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user(['firstname' => 'Testa', 'lastname' => 'Kranich']);
        $this->setAdminUser();
        $_POST['sesskey'] = sesskey();

        $result = ai_privacy_precheck::execute(
            (int)\context_system::instance()->id,
            'Der Kranich fliegt heute über den Kurs.',
            1
        );

        $this->assertArrayHasKey('suspects', $result, 'the precheck must name its low-confidence suspects');
        $words = array_column((array)$result['suspects'], 'word');
        $this->assertContains('Kranich', $words, json_encode($result['suspects']));
        $this->assertNotEmpty((array)$result['suspects'][0]['token'] ?? '');
    }

    /**
     * A high-confidence full name is masked but never offered as a chip suspect.
     */
    public function test_full_names_are_not_suspects(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user(['firstname' => 'Paula', 'lastname' => 'Beispielfrau']);
        $this->setAdminUser();
        $_POST['sesskey'] = sesskey();

        $result = ai_privacy_precheck::execute(
            (int)\context_system::instance()->id,
            'Bitte buche Paula Beispielfrau in den Kurs.',
            1
        );

        $this->assertArrayHasKey('suspects', $result);
        $this->assertSame([], (array)$result['suspects'], 'full-name matches are high confidence, no chip');
    }
}
