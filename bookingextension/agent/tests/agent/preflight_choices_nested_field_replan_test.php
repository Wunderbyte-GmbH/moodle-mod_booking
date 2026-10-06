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
 * Offered choices for a value inside an array field reach the selector and the re-planned command carries the id.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use mod_booking\utils\wb_payment;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * The offered-choices path (wave 30) was pinned for a top-level field (rulequery -> ruleid, preflight_choices_replan_test).
 * mod_booking.configure_booking_instance offers the views for the value of one entry of its changes array
 * (field "changes[0].value", Wunderbyte-GmbH/Wunderbyte-GmbH#2495): the choices must reach the selector for the one
 * re-plan, the second construction carries the chosen id on the card, and an unmatched choice ends as the question.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\services\decision\agent_decision_service
 */
final class preflight_choices_nested_field_replan_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** The user turn. */
    private const PROMPT = 'Zeig die Buchungsoptionen hier als Kacheln an.';

    /** The skill under test. */
    private const SKILL = 'mod_booking.configure_booking_instance';

    /**
     * Provider and Booking PRO (the cards view needs it).
     */
    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        wb_payment::override_pro_version_for_tests(true);
        $roleid = (int)$this->getDataGenerator()->create_role();
        assign_capability('mod/booking:updatebooking', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, (int)$this->teacher->id, \context_system::instance()->id);
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
    }

    /**
     * Release the scripted planner and the PRO override.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        wb_payment::override_pro_version_for_tests(null);
        parent::tearDown();
    }

    /**
     * Construction parameters for one viewparam change.
     *
     * @param mixed $value
     * @return array
     */
    private static function view_change($value): array {
        return ['action' => 'update', 'changes' => [['field' => 'viewparam', 'value' => $value]]];
    }

    /**
     * The views reach the selector for the value path; the re-planned construction puts the chosen id on the card.
     */
    public function test_a_view_word_is_chosen_from_the_offered_views(): void {
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call(self::SKILL), $this->selector_skill_call(self::SKILL)],
            [
                $this->constructor_confirmation_request(self::SKILL, self::view_change('Kacheln')),
                $this->constructor_confirmation_request(self::SKILL, self::view_change((string)MOD_BOOKING_VIEW_PARAM_CARDS)),
            ]
        );

        $result = $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        $sequence = $this->scripted_phase_sequence();
        $this->assertSame('confirmation_request', (string)($result['response_type'] ?? ''), $sequence . ' '
            . json_encode($result['issue_codes'] ?? []));
        $this->assertSame('SCSC', substr($sequence, 0, 4), 'the re-plan starts at the selector');
        $input = (array)(((array)($result['commands'] ?? []))[0]['input'] ?? []);
        $this->assertSame(
            MOD_BOOKING_VIEW_PARAM_CARDS,
            (int)($input['changes'][0]['value'] ?? -1),
            'the chosen view is on the card: ' . json_encode($input)
        );

        $selectorprompts = array_values(array_filter(
            $this->scriptedplannerprompts,
            static fn(string $p): bool => strpos($p, 'phase_handoff.selection=') === false
        ));
        $this->assertCount(2, $selectorprompts);
        $this->assertStringContainsString('CHOICES for changes[0].value', $selectorprompts[1], 'the choices reach the selector');
        $this->assertStringContainsString('id=' . MOD_BOOKING_VIEW_PARAM_CARDS, $selectorprompts[1]);
        $this->assertStringNotContainsString('CHOICES for', $selectorprompts[0]);
    }

    /**
     * No choice fits: after the one re-plan the turn ends as the question with the views, never as an error, and the
     * user text names neither the field identifier nor an issue code.
     */
    public function test_an_unmatched_view_ends_as_the_question(): void {
        [$store, $runtime, $threadid] = $this->build_runtime();
        $miss = $this->constructor_confirmation_request(self::SKILL, self::view_change('Kacheln'));
        $this->install_phase_scripted_planner(
            array_fill(0, 4, $this->selector_skill_call(self::SKILL)),
            array_fill(0, 4, $miss)
        );

        $result = $this->chat(self::PROMPT, (int)$threadid, $store, $runtime);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''), json_encode($result['issue_codes'] ?? []));
        $this->assertContains('PREFLIGHT_CHOICES_OFFERED', (array)($result['issue_codes'] ?? []), 'the choices path, not a gate');
        $this->assertLessThanOrEqual(2, substr_count($this->scripted_phase_sequence(), 'S'), 'one re-plan, no loop');
        $message = (string)($result['message'] ?? '');
        $this->assertStringNotContainsString('viewparam', $message, 'no field identifier in the user text');
        $this->assertStringNotContainsString('changes[', $message, 'no field path in the user text');
        $this->assertStringNotContainsString('CONFIGURE_', $message, 'no issue code in the user text');
        $this->assertStringNotContainsString('PREFLIGHT_', $message, 'no issue code in the user text');
    }
}
