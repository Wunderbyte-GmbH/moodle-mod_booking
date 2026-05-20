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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Tests for booking condition visibility warnings.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Tests for booking condition visibility warnings.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\bo_availability\condition_visibility_manager
 */
final class condition_visibility_manager_test extends advanced_testcase {
    /**
     * Reset state before each test.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

                /**
                 * Verify each frozen state inserts exactly one warning.
                 *
                 * @covers \mod_booking\bo_availability\condition_visibility_manager::freeze_fields_for_condition
                 * @dataProvider freeze_warning_provider
                 *
                 * @param bool $skipandfreeze
                 * @param string $expectedfragment
                 */
    public function test_freeze_fields_adds_one_warning_for_each_state(bool $skipandfreeze, string $expectedfragment): void {
        global $USER;

        $this->setAdminUser();
        $USER = get_admin();

        $warningcontent = null;

        $mform = $this->getMockBuilder(\MoodleQuickForm::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['elementExists', 'freeze', 'createElement', 'insertElementBefore'])
            ->getMock();

        $mform->method('elementExists')->willReturn(true);
        $mform->method('freeze')->willReturn(null);
        $mform->method('createElement')->willReturnCallback(function ($type, $name, $label, $text) use (&$warningcontent) {
            $warningcontent = $text;
            return (object)['type' => $type, 'name' => $name];
        });
        $mform->expects($this->once())->method('insertElementBefore');

        $manager = new condition_visibility_manager();
        $manager->freeze_fields_for_condition($mform, MOD_BOOKING_BO_COND_BOOKING_TIME, $skipandfreeze);

        $this->assertIsString($warningcontent);
        $this->assertStringContainsString($expectedfragment, $warningcontent);
    }

    /**
     * Data provider for freeze warning tests.
     *
     * @return array<string, array{0: bool, 1: string}>
     */
    public static function freeze_warning_provider(): array {
        return [
            'skip and freeze' => [true, 'turned off (skipped)'],
            'freeze only' => [false, 'frozen in the settings'],
        ];
    }
}
