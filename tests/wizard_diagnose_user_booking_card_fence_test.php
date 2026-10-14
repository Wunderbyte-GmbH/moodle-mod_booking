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

namespace mod_booking;

use advanced_testcase;
use core_text;
use mod_booking\local\wizard\options\skills\diagnose_user_booking_skill;

/**
 * The person's booking diagnosis fences off the taskflow message diagnosis and says when to choose it.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\local\wizard\options\skills\diagnose_user_booking_skill
 */
final class wizard_diagnose_user_booking_card_fence_test extends advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /** @var int Characters the selector card shows of a NOT line. */
    private const NOT_CAP = 160;

    /** @var int Characters the selector card shows of a WHEN line. */
    private const WHEN_CAP = 180;

    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * A question whether a person's mails for an option arrived reads like the taskflow message diagnosis;
     * without a mutual fence the selector follows the card that names the case.
     */
    public function test_the_card_fences_off_the_taskflow_message_diagnosis(): void {
        $not = (string)((new diagnose_user_booking_skill())->get_schema()['not'] ?? '');

        $this->assertStringContainsString('local_taskflow.diagnose_message_delivery', $not);
        $this->assertStringContainsString('course.diagnose_user_in_course', $not);
        $this->assertLessThanOrEqual(self::NOT_CAP, core_text::strlen($not), $not);
    }

    /**
     * The card declares its own situation instead of borrowing the first message trigger.
     */
    public function test_the_card_declares_when_to_choose_it(): void {
        $when = trim((string)((new diagnose_user_booking_skill())->get_schema()['when'] ?? ''));

        $this->assertNotSame('', $when);
        $this->assertMatchesRegularExpression('/confirmation|reminder/i', $when);
        $this->assertLessThanOrEqual(self::WHEN_CAP, core_text::strlen($when), $when);
    }
}
