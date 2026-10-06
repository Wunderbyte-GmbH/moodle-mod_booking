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
use mod_booking\local\wizard\options\skills\create_option_skill;
use bookingextension_agent\local\wizard\course\skills\create_course_skill;

/**
 * B1/P7 on the RIGHT channel: the confirmation card's deterministic value block is the
 * proposed-action preview (describe_proposed_action -> build_preview_json), NOT the LLM message.
 *
 * The engine already renders every proposed field as a label/value row, independent of the LLM
 * wording — proven for the generic default, tier-3 overrides, wrapping and the empty case by
 * proposed_action_preview_test. This guard pins the two compound-authoring skills specifically:
 * a create_option confirmation must carry its concrete values, and a create_course confirmation
 * must not be bare (thread 586/1517: the create_course MESSAGE read only "… carried out in:
 * System." — the preview must still carry the course name and category).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_option_skill::describe_proposed_action
 */
final class confirm_preview_data_block_test extends advanced_testcase {
    /**
     * A create_option confirmation preview must carry the proposed values (title, capacity, price).
     */
    public function test_create_option_preview_carries_the_proposed_values(): void {
        $this->resetAfterTest();

        $descriptor = (new create_option_skill())->describe_proposed_action([
            'text' => 'Yoga am Morgen',
            'maxanswers' => 23,
            'prices' => ['default' => 37],
        ]);

        $this->assertNotNull(
            $descriptor,
            'A create_option confirmation must carry a deterministic proposed-action preview (the '
            . 'confirm card data block), not rely on the LLM message.'
        );
        $flat = (string)json_encode($descriptor, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Yoga am Morgen', $flat, 'Preview must name the option title.');
        $this->assertStringContainsString('23', $flat, 'Preview must carry the max participants (23).');
        $this->assertStringContainsString('37', $flat, 'Preview must carry the price (37).');
    }

    /**
     * A create_course confirmation preview must not be bare (thread 586/1517).
     */
    public function test_create_course_preview_is_not_bare(): void {
        $this->resetAfterTest();

        $descriptor = (new create_course_skill())->describe_proposed_action([
            'fullname' => 'Das Leben der Wikinger',
            'categoryname' => 'Category 1',
        ]);

        $this->assertNotNull(
            $descriptor,
            'B1/P7 (thread 586/1517): a create_course confirmation must not be bare — the deterministic '
            . 'preview must carry the course data even when the message reads only "… carried out in: System".'
        );
        $flat = (string)json_encode($descriptor, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Das Leben der Wikinger', $flat, 'Preview must name the course.');
        $this->assertStringContainsString('Category 1', $flat, 'Preview must name the target category.');
    }
}
