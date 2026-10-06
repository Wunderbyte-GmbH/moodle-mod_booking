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
use mod_booking\local\wizard\options\skills\create_option_skill;
use mod_booking\local\wizard\options\skills\create_selflearning_option_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Capacity is optional: a missing or zero maxanswers means "unlimited" and never blocks creation.
 *
 * Write-path baseline W1 (2026-09-15, findings W3/W4, Wunderbyte-GmbH#2413; threads 1621 CO-2, 1626 CO-3,
 * 1627 CO-4, 1629 CSL-4): the placeholder gate demanded maxanswers, the model invented 15/20/30 seats,
 * and "unlimited, create anyway" looped because only the literal override token ended the gate.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\create_option_skill
 * @covers     \mod_booking\local\wizard\options\skills\create_selflearning_option_skill
 */
final class wizard_create_option_unlimited_capacity_test extends booking_advanced_testcase {
    /** @var int Course-module id of the test instance. */
    private int $cmid = 0;

    /** @var int Module context id of the test instance. */
    private int $contextid = 0;

    /**
     * Setup: a booking instance.
     */
    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Capacity Test', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);
    }

    /**
     * Input of a normal option with the date pair filled, capacity omitted (location left out: with
     * local_entities installed a free-text location is entity-managed, see #2414).
     *
     * @return array
     */
    private function normal_input(): array {
        return [
            'text' => 'Yoga ohne Limit',
            'coursestarttime' => '2046-09-30 18:00',
            'courseendtime' => '2046-09-30 20:00',
            'cmid' => $this->cmid,
        ];
    }

    /**
     * Issue codes of a preflight dto.
     *
     * @param object $dto Preflight result.
     * @return string[]
     */
    private function codes(object $dto): array {
        return array_map(static fn($issue) => (string)($issue['code'] ?? ''), json_decode(json_encode($dto->issues), true) ?: []);
    }

    /**
     * Value of the seats row on the confirm card, null when absent.
     *
     * @param array|null $descriptor Preview descriptor.
     * @return string|null
     */
    private function seats_row(?array $descriptor): ?string {
        $label = get_string('previewlabel_seats', 'booking');
        foreach ((array)($descriptor['rows'] ?? []) as $row) {
            if ((string)($row['label'] ?? '') === $label) {
                return (string)$row['value'];
            }
        }
        return null;
    }

    /**
     * (a)+(b) create_option without maxanswers: no placeholder gate, card says unlimited.
     */
    public function test_missing_capacity_passes_and_card_says_unlimited(): void {
        global $USER;
        $skill = new create_option_skill();
        $dto = $skill->preflight($this->normal_input(), $this->contextid, (int)$USER->id);
        $this->assertNotContains('PLACEHOLDER_VALUE', $this->codes($dto), json_encode($dto->issues));
        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $descriptor = $skill->describe_proposed_action((array)$dto->preparedinput);
        $this->assertSame(get_string('previewvalue_unlimited', 'booking'), $this->seats_row($descriptor), json_encode($descriptor));
    }

    /**
     * (a2) an explicit zero (the model's rendering of "unlimited") is not a placeholder either.
     */
    public function test_zero_capacity_is_unlimited_not_a_placeholder(): void {
        global $USER;
        $skill = new create_option_skill();
        $dto = $skill->preflight($this->normal_input() + ['maxanswers' => 0], $this->contextid, (int)$USER->id);
        $this->assertNotContains('PLACEHOLDER_VALUE', $this->codes($dto), json_encode($dto->issues));
        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $descriptor = $skill->describe_proposed_action((array)$dto->preparedinput);
        $this->assertSame(get_string('previewvalue_unlimited', 'booking'), $this->seats_row($descriptor), json_encode($descriptor));
    }

    /**
     * (c) execute writes maxanswers 0 for an omitted capacity.
     */
    public function test_execute_writes_zero_capacity(): void {
        global $USER, $DB;
        $skill = new create_option_skill();
        $dto = $skill->preflight($this->normal_input(), $this->contextid, (int)$USER->id);
        $result = $skill->execute((array)$dto->preparedinput, $this->contextid, (int)$USER->id);
        $this->assertSame('executed', (string)($result['status'] ?? ''), json_encode($result));
        $this->assertSame(0, (int)$DB->get_field('booking_options', 'maxanswers', ['id' => (int)$result['resultid']]));
    }

    /**
     * (d) an explicit capacity is still shown as given.
     */
    public function test_explicit_capacity_is_shown(): void {
        global $USER;
        $skill = new create_option_skill();
        $dto = $skill->preflight($this->normal_input() + ['maxanswers' => 12], $this->contextid, (int)$USER->id);
        $this->assertSame('12', $this->seats_row($skill->describe_proposed_action((array)$dto->preparedinput)));
    }

    /**
     * (e) create_selflearning_option: same contract.
     */
    public function test_selflearning_missing_capacity_passes_and_card_says_unlimited(): void {
        global $USER;
        $skill = new create_selflearning_option_skill();
        $dto = $skill->preflight([
            'text' => 'Selbstlernkurs ohne Limit', 'duration' => 30, 'cmid' => $this->cmid,
        ], $this->contextid, (int)$USER->id);
        $this->assertNotContains('PLACEHOLDER_VALUE', $this->codes($dto), json_encode($dto->issues));
        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $descriptor = $skill->describe_proposed_action((array)$dto->preparedinput);
        $this->assertSame(get_string('previewvalue_unlimited', 'booking'), $this->seats_row($descriptor), json_encode($descriptor));
    }

    /**
     * The remaining placeholder pairs keep their gate: an explicitly empty location AND address still ask.
     */
    public function test_other_placeholder_pairs_keep_their_gate(): void {
        global $USER;
        $skill = new create_option_skill();
        $input = $this->normal_input();
        $input['location'] = '';
        $input['address'] = '';
        $dto = $skill->preflight($input, $this->contextid, (int)$USER->id);
        $this->assertContains('PLACEHOLDER_VALUE', $this->codes($dto), json_encode($dto->issues));
    }
}
