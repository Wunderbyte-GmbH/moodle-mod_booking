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

use mod_booking\tests\booking_advanced_testcase;
use context_module;
use mod_booking\local\wizard\engine_component;
use mod_booking\local\wizard\options\skills\bulk_update_options_skill;
use mod_booking\local\wizard\options\skills\update_option_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * A bare numeric price on update/bulk must reach the confirm card in its canonical shape.
 *
 * Baseline run 9 (2026-09-16, preview audit P3, Wunderbyte-GmbH#2409), thread 1945: the planner
 * sent {"prices": 25, "invisible": 0}; the card showed only "Visibility = Visible", while the
 * execute path normalized the scalar to {default: 25} and wrote it — the user confirmed a change
 * the card never showed. create_option canonicalizes the shape in preflight; update and bulk
 * must do the same (bulk mirrors update_option).
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\update_option_skill
 * @covers     \mod_booking\local\wizard\options\skills\bulk_update_options_skill
 */
final class wizard_update_option_scalar_price_preview_test extends booking_advanced_testcase {
    /** @var int Course-module id of the test instance. */
    private int $cmid = 0;

    /** @var int Module context id of the test instance. */
    private int $contextid = 0;

    /** @var int Id of the option to update. */
    private int $optionid = 0;

    /**
     * Setup: a booking instance with one option.
     */
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Price Test', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        // The default price category the bare numeric price resolves to.
        $gen->create_pricecategory((object)[
            'ordernum' => 1, 'identifier' => 'default', 'name' => 'Standard', 'defaultvalue' => 20,
        ]);
        $option = $gen->create_option([
            'bookingid' => (int)$booking->id, 'text' => 'Nähcafé', 'maxanswers' => 10, 'type' => 0,
        ]);
        $this->optionid = (int)$option->id;
    }

    /**
     * Assert that the prepared input carries the canonical price map and the card a price row.
     *
     * @param array $prepared Prepared input from preflight.
     * @param array|null $descriptor Preview descriptor.
     */
    private function assert_price_visible(array $prepared, ?array $descriptor): void {
        $this->assertSame(['default' => 25.0], $prepared['prices'] ?? null, 'bare numeric price must be canonicalized');
        $this->assertNotNull($descriptor);
        $values = array_map(static fn($row) => (string)($row['value'] ?? ''), (array)($descriptor['rows'] ?? []));
        $this->assertNotEmpty(
            array_filter($values, static fn($value) => str_contains($value, '25')),
            'the confirm card must show the price change: ' . json_encode($descriptor, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * update_option: scalar price reaches the card.
     */
    public function test_update_option_scalar_price_reaches_the_card(): void {
        global $USER;
        $skill = new update_option_skill();
        $dto = $skill->preflight(
            ['optionid' => $this->optionid, 'prices' => 25, 'invisible' => 0, 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $prepared = (array)$dto->preparedinput;
        $this->assert_price_visible($prepared, $skill->describe_proposed_action($prepared));
    }

    /**
     * bulk_update_options: scalar price reaches the card.
     */
    public function test_bulk_update_scalar_price_reaches_the_card(): void {
        global $USER;
        $skill = new bulk_update_options_skill();
        $dto = $skill->preflight(
            ['apply_to_all' => true, 'prices' => 25, 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );
        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $prepared = (array)$dto->preparedinput;
        $this->assert_price_visible($prepared, $skill->describe_proposed_action($prepared));
    }
}
