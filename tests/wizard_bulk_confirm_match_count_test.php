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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * A bulk confirm must know its match set: count in the preview, clarification on zero matches.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\bulk_update_options_skill
 */
final class wizard_bulk_confirm_match_count_test extends booking_advanced_testcase {
    /** @var int Course-module id of the test instance. */
    private int $cmid = 0;

    /** @var int Module context id of the test instance. */
    private int $contextid = 0;

    protected function setUp(): void {
        parent::setUp();
        engine_component::ensure_engine_aliases();
        $this->resetAfterTest();
        $this->setAdminUser();

        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $course->id, 'name' => 'Bulk Test', 'eventtype' => 'Webinar', 'bookingmanager' => 'admin',
        ]);
        $this->cmid = (int)$booking->cmid;
        $this->contextid = (int)context_module::instance($this->cmid)->id;
        $PAGE->set_url('/mod/booking/view.php', ['id' => $this->cmid]);

        $gen = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        foreach (['Kochkurs Anfänger', 'Kochkurs Fortgeschrittene', 'Kochkurs Profi', 'Töpfern am Abend'] as $title) {
            $gen->create_option([
                'bookingid' => (int)$booking->id, 'text' => $title, 'maxanswers' => 10, 'type' => 0,
            ]);
        }
    }

    /**
     * Zero matches must end in a clarification, never in a confirmable command.
     */
    public function test_zero_matches_yield_clarification_not_confirmation(): void {
        global $USER;
        $skill = new bulk_update_options_skill();

        $dto = $skill->preflight(
            ['optionquery' => 'Schwimmkurs', 'maxanswers' => 18, 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );

        $this->assertNotContains(
            (string)$dto->status,
            ['pass', 'soft_block'],
            'an empty match set must never reach the confirm card: ' . json_encode($dto->issues)
        );
        $issues = json_decode(json_encode($dto->issues), true);
        $this->assertSame(
            'needs_clarification',
            (string)($issues[0]['severity'] ?? ''),
            'zero matches are a clarification, not an error: ' . json_encode($issues)
        );
    }

    /**
     * A query match set is resolved at preflight and its count reaches the confirm preview.
     */
    public function test_query_match_count_reaches_the_preview(): void {
        global $USER;
        $skill = new bulk_update_options_skill();

        $dto = $skill->preflight(
            ['optionquery' => 'Kochkurs', 'maxanswers' => 18, 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );

        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $prepared = (array)$dto->preparedinput;
        $this->assertCount(3, (array)($prepared['optionids'] ?? []), 'the resolved match set must be frozen');

        $descriptor = $skill->describe_proposed_action($prepared);
        $flat = json_encode($descriptor, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('3', $flat, 'the match count must be part of the preview');
        $this->assertStringContainsString('Kochkurs Anfänger', $flat, 'matched titles must be listed');
        $this->assertStringContainsString('Kochkurs', $flat);
    }

    /**
     * apply_to_all resolves the full instance set and states its size.
     */
    public function test_apply_to_all_states_the_full_count(): void {
        global $USER;
        $skill = new bulk_update_options_skill();

        $dto = $skill->preflight(
            ['apply_to_all' => true, 'maxanswers' => 18, 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );

        $this->assertContains((string)$dto->status, ['pass', 'soft_block'], json_encode($dto->issues));
        $prepared = (array)$dto->preparedinput;
        $this->assertCount(4, (array)($prepared['optionids'] ?? []));

        $descriptor = $skill->describe_proposed_action($prepared);
        $flat = json_encode($descriptor, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('4', $flat, 'the total must be visible before the confirm');
    }

    /**
     * The zero-match clarification must tell the user that nothing matched the query.
     *
     * Write-path baseline W1 (thread 1638 BU-1, finding W8, #2398): the "no matches" sentence lived
     * only in message, while the engine shows user_question, so the user saw the generic
     * "all, subset or ids?" question, repeated the query and got the same question again.
     */
    public function test_zero_matches_question_names_the_query(): void {
        global $USER;
        $skill = new bulk_update_options_skill();

        $dto = $skill->preflight(
            ['optionquery' => 'Schwimmkurs', 'maxanswers' => 18, 'cmid' => $this->cmid],
            $this->contextid,
            (int)$USER->id
        );

        $issues = json_decode(json_encode($dto->issues), true);
        $this->assertSame('EMPTY_BULK_TARGET_SELECTION', (string)($issues[0]['code'] ?? ''), json_encode($issues));
        $this->assertSame('needs_clarification', (string)($issues[0]['severity'] ?? ''));
        $this->assertStringContainsString(
            'Schwimmkurs',
            (string)($issues[0]['user_question'] ?? ''),
            'the question the user sees must name the query that matched nothing'
        );
        $this->assertNotEmpty($issues[0]['remedy_options'] ?? [], 'the issue must offer remedies');
    }
}
