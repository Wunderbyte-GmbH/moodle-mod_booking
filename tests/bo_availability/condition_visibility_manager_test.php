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
require_once($CFG->libdir . '/formslib.php'); // Needed so MoodleQuickForm can be mocked.

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
     * By default (setting off) the warning keeps the standard behaviour: it is added above the
     * condition's fields, as the first element.
     *
     * @covers \mod_booking\bo_availability\condition_visibility_manager::freeze_fields_for_condition
     * @dataProvider freeze_warning_provider
     *
     * @param bool $skipandfreeze
     * @param string $expectedfragment
     */
    public function test_freeze_warning_is_above_the_fields_by_default(
        bool $skipandfreeze,
        string $expectedfragment
    ): void {
        $this->setAdminUser();

        $mform = $this->build_condition_form();

        $manager = new condition_visibility_manager();
        $manager->freeze_fields_for_condition(
            $mform,
            $this->make_condition(['bo_cond_demo_restrict', 'bo_cond_demo_handling']),
            $skipandfreeze
        );

        // Standard behaviour: the warning is the very first element, before the checkbox.
        $first = array_values($mform->_elements)[0];
        $this->assertSame('bo_cond_demo_restrict_frozenwarning', $first->getName());
        $this->assertSame('static', $first->getType());
        $this->assertStringContainsString($expectedfragment, $first->toHtml());
    }

    /**
     * With the setting enabled the warning is placed at the bottom of the condition's fields,
     * just above the trailing <hr> divider.
     *
     * @covers \mod_booking\bo_availability\condition_visibility_manager::freeze_fields_for_condition
     * @dataProvider freeze_warning_provider
     *
     * @param bool $skipandfreeze
     * @param string $expectedfragment
     */
    public function test_freeze_warning_is_above_the_divider_when_enabled(
        bool $skipandfreeze,
        string $expectedfragment
    ): void {
        $this->setAdminUser();
        set_config('conditionwarningatbottom', 1, 'booking');

        $mform = $this->build_condition_form();

        $manager = new condition_visibility_manager();
        $manager->freeze_fields_for_condition(
            $mform,
            $this->make_condition(['bo_cond_demo_restrict', 'bo_cond_demo_handling']),
            $skipandfreeze
        );

        $names = [];
        $dividerpos = null;
        $warningpos = null;
        foreach (array_values($mform->_elements) as $position => $element) {
            $names[$position] = $element->getName();
            if ($element->getName() === 'bo_cond_demo_restrict_frozenwarning') {
                $warningpos = $position;
            } else if ($element->getType() === 'html' && strpos($element->toHtml(), '<hr') !== false) {
                $dividerpos = $position;
            }
        }

        $this->assertNotNull($warningpos, 'A frozen warning element should have been added.');
        $this->assertNotNull($dividerpos, 'The divider should still be present.');
        $this->assertGreaterThan(
            array_search('bo_cond_demo_handling', $names, true),
            $warningpos,
            'The warning must come after the last field of the condition.'
        );
        $this->assertLessThan($dividerpos, $warningpos, 'The warning must sit above the divider.');
        $this->assertStringContainsString($expectedfragment, $mform->getElement($names[$warningpos])->toHtml());
    }

    /**
     * With the setting enabled and no divider present, the warning is the last element.
     *
     * @covers \mod_booking\bo_availability\condition_visibility_manager::freeze_fields_for_condition
     */
    public function test_freeze_warning_is_last_when_enabled_and_no_divider(): void {
        $this->setAdminUser();
        set_config('conditionwarningatbottom', 1, 'booking');

        $mform = new \MoodleQuickForm('test', 'post', '');
        $mform->addElement('advcheckbox', 'bo_cond_demo_restrict', 'Demo restrict');

        $manager = new condition_visibility_manager();
        $manager->freeze_fields_for_condition(
            $mform,
            $this->make_condition(['bo_cond_demo_restrict']),
            false
        );

        $elements = array_values($mform->_elements);
        $last = end($elements);
        $this->assertSame('bo_cond_demo_restrict_frozenwarning', $last->getName());
        $this->assertStringContainsString('frozen in the settings', $last->toHtml());
    }

    /**
     * Builds a form mimicking a condition: a checkbox, a field and the trailing <hr> divider.
     *
     * @return \MoodleQuickForm
     */
    private function build_condition_form(): \MoodleQuickForm {
        $mform = new \MoodleQuickForm('test', 'post', '');
        $mform->addElement('advcheckbox', 'bo_cond_demo_restrict', 'Demo restrict');
        $mform->addElement('select', 'bo_cond_demo_handling', 'Demo handling', [1 => 'One', 2 => 'Two']);
        $mform->addElement(
            'html',
            '<div id="bo_cond_demo_restrict_hr" class="d-flex justify-content-end"><hr class="w-75"/></div>'
        );
        return $mform;
    }

    /**
     * Builds a minimal freezable condition that controls the given form elements.
     *
     * @param string[] $elements
     * @return freezable_condition
     */
    private function make_condition(array $elements): freezable_condition {
        return new class ($elements) implements freezable_condition {
            /** @var string[] Form elements controlled by this condition. */
            private array $elements;

            /**
             * Stores the form elements this condition controls.
             *
             * @param string[] $elements
             */
            public function __construct(array $elements) {
                $this->elements = $elements;
            }

            /**
             * Return the form elements controlled by this condition.
             *
             * @return string[]
             */
            public function get_condition_form_elements(): array {
                return $this->elements;
            }
        };
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
