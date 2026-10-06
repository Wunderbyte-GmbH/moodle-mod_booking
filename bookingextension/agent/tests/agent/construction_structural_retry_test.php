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
 * One framework retry for structural construction mismatches (W2 baseline fix, 2026-07-12).
 *
 * Measured baseline: in 5 of 7 wrong-key constructions NO in-turn retry fired — the
 * structural reject went terminal although the repair text names the canonical keys
 * (heal rate 0/7). The interpreter now tags CONTRACT_STRUCTURAL_MISMATCH (unless the
 * skill flagged RECOVERABLE_INPUT_ERROR = genuinely missing user input), and the loop
 * grants exactly one construction retry carrying the repair guidance.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use context_module;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Deterministic retry-doctrine tests via the scripted planner seam.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\agent_runtime
 * @covers \bookingextension_agent\local\wizard\interpreter
 */
final class construction_structural_retry_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    protected function setUp(): void {
        parent::setUp();
        $this->enforcegeneratetextassertion = false;
        $this->grant_agent_capabilities_to_editingteacher();
        $this->register_live_wunderbyte_provider(
            'test-dummy-key-not-used',
            'test-model',
            'test-model',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
    }

    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * A wrong-key construction (invented 'price_1') gets exactly ONE retry with the repair
     * guidance; the corrected second construction stages the confirmable command.
     */
    public function test_structural_mismatch_retries_once_and_heals(): void {
        $this->gen->create_pricecategory((object)[
            'ordernum' => 1,
            'identifier' => 'default',
            'name' => 'Standard',
            'defaultvalue' => 25,
        ]);

        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.create_option'),
            // Attempt 1: invented indexed price key — structurally rejected, not aliased.
            $this->constructor_confirmation_request('mod_booking.create_option', [
                'text' => 'Retry Workshop',
                'price_1' => 30,
                'coursestarttime' => '2045-11-10T10:00:00',
                'courseendtime' => '2045-11-10T12:00:00',
            ]),
            // Retry round: the framework re-plans; the corrected construction uses canonical keys.
            $this->selector_skill_call('mod_booking.create_option'),
            $this->constructor_confirmation_request('mod_booking.create_option', [
                'text' => 'Retry Workshop',
                'prices' => ['default' => 30],
                'coursestarttime' => '2045-11-10T10:00:00',
                'courseendtime' => '2045-11-10T12:00:00',
            ]),
        ]);

        $result = $this->chat(
            'Erstelle den Workshop "Retry Workshop" am 10.11.2045 von 10 bis 12 Uhr, kostet 30 Euro.',
            (int)$threadid,
            $store,
            $runtime
        );

        $this->assertSame(
            'confirmation_request',
            (string)($result['response_type'] ?? ''),
            'The corrected retry construction must stage the confirmable command: '
                . json_encode($result['issue_codes'] ?? [])
        );
        // Note: PLANNER_RETRY_DECISION marks the FAILED round's (discarded) result — the final
        // result of the healed round is clean by design. The retry evidence is the consumed
        // second selector/constructor round and the RETRY_HINT in its prompts (below).
        $command = $this->extract_command($result, 'mod_booking.create_option');
        $this->assertNotNull($command, 'The staged command must be present after the retry.');
        $this->assertSame(
            ['default' => 30],
            array_map('intval', (array)($command['input']['prices'] ?? [])),
            'The retried construction must carry the canonical prices object.'
        );

        // The retry must have consumed a second selector/constructor round.
        $this->assertGreaterThanOrEqual(4, count($this->scriptedplannerprompts));
        $retryprompt = implode("\n", array_slice($this->scriptedplannerprompts, 2));
        $this->assertStringContainsString(
            'RETRY_HINT',
            $retryprompt,
            'The retry round must see the structural retry hint.'
        );
    }

    /**
     * Genuinely missing user input (RECOVERABLE_INPUT_ERROR, F3-migrated skills) must NOT
     * burn a blind retry — it surfaces as a clarification turn immediately.
     */
    public function test_recoverable_input_error_skips_the_retry(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('course.scaffold_course_content'),
            // Missing topic: the migrated skill flags RECOVERABLE_INPUT_ERROR — a user question.
            $this->constructor_confirmation_request('course.scaffold_course_content', [
                'coursequery' => 'Irgendein Kurs',
                'chapters' => 4,
            ]),
        ]);

        $result = $this->chat(
            'Fülle den Kurs mit Inhalten.',
            (int)$threadid,
            $store,
            $runtime
        );

        $this->assertSame(
            'clarification',
            (string)($result['response_type'] ?? ''),
            'Missing user input must surface as a clarification, got: '
                . json_encode(['rt' => $result['response_type'] ?? '', 'ic' => $result['issue_codes'] ?? []])
        );
        $this->assertNotContains(
            'PLANNER_RETRY_DECISION',
            (array)($result['issue_codes'] ?? []),
            'No blind framework retry may fire for a genuine user question.'
        );
        $this->assertCount(
            2,
            $this->scriptedplannerprompts,
            'Exactly one selector + one constructor call — no retry round.'
        );
    }

    /**
     * Replay of Lauf 8 thread 1427 (BU-3): an unknown key, then a structurally invalid retry.
     * Since 3719ff9 (#2364) the exhausted construction retry ended as response_type=error and the
     * synchronizer relayed "Unknown input properties ... Supported properties: ..." to the user.
     * Nothing was executed, so the turn must end as an honest clarification whose user causes
     * carry neither the unknown key nor a field list.
     */
    public function test_exhausted_structural_retry_ends_as_clarification_bu3(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.bulk_update_options'),
            $this->constructor_confirmation_request('mod_booking.bulk_update_options', [
                'optionquery' => 'atelier',
                'endingtime' => 1735689599,
            ]),
            $this->selector_skill_call('mod_booking.bulk_update_options'),
            $this->constructor_confirmation_request('mod_booking.bulk_update_options', [
                'optionquery' => 'atelier',
                'optiondates' => [['timestart' => '2026-12-31T00:00:00+01:00', 'timeend' => '2026-12-31T23:59:59+01:00']],
            ]),
        ]);

        $result = $this->chat(
            'Mets la même date de fin (31 décembre) sur toutes les offres qui contiennent « atelier ».',
            (int)$threadid,
            $store,
            $runtime
        );

        $this->assert_exhausted_construction_clarifies($result, 'mod_booking.bulk_update_options', ['endingtime']);
    }

    /**
     * Replay of Lauf 8 thread 1438 (CBI-2): the retry round switches to the read-only sibling and
     * passes the ambient contextid as input. Same expectation: clarification, no key names.
     *
     * Since #2411 an unknown field NAME is a skill-side clarification with remedies, no longer a
     * structural error; the structural error of the first round here is the change without a value.
     */
    public function test_exhausted_structural_retry_ends_as_clarification_cbi2(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.configure_booking_instance'),
            $this->constructor_confirmation_request('mod_booking.configure_booking_instance', [
                'action' => 'update',
                'changes' => [['field' => 'sendmailtobooker']],
            ]),
            $this->selector_skill_call('mod_booking.list_instance_settings'),
            $this->constructor_confirmation_request('mod_booking.list_instance_settings', [
                'contextid' => $this->booking_contextid(),
            ]),
        ]);

        $result = $this->chat(
            'I keep getting copies of every confirmation mail — turn that off at the activity level.',
            (int)$threadid,
            $store,
            $runtime
        );

        $this->assert_exhausted_construction_clarifies($result, 'mod_booking.list_instance_settings', ['contextid']);
    }

    /**
     * Shared structural expectation for an exhausted construction retry.
     *
     * @param array $result Final runtime result.
     * @param string $skillname Skill of the failing round (its schema names must not leak).
     * @param string[] $unknownkeys Keys the planner invented.
     * @return void
     */
    private function assert_exhausted_construction_clarifies(array $result, string $skillname, array $unknownkeys): void {
        $codes = (array)($result['issue_codes'] ?? []);
        $this->assertContains('LOOP_RETRY_EXHAUSTED', $codes, json_encode($codes));
        $this->assertSame(
            'clarification',
            (string)($result['response_type'] ?? ''),
            'An exhausted construction retry executed nothing and must clarify: ' . json_encode($codes)
        );
        $this->assertEmpty((array)($result['commands'] ?? []), 'No command may be staged.');

        $skill = \bookingextension_agent\local\wizard\skill_registry::make_default()->get_skill($skillname);
        $schema = (array)$skill->get_schema();
        $properties = array_map('strval', array_keys((array)($schema['properties'] ?? [])));
        $usertexts = array_merge([(string)($result['message'] ?? '')], array_map('strval', (array)($result['errors'] ?? [])));
        foreach ($usertexts as $text) {
            foreach ($unknownkeys as $key) {
                $this->assertStringNotContainsString($key, $text, 'The invented key must not reach the user.');
            }
            $named = 0;
            foreach ($properties as $property) {
                if (strlen($property) > 3 && preg_match('/\\b' . preg_quote($property, '/') . '\\b/', $text)) {
                    $named++;
                }
            }
            $this->assertLessThanOrEqual(1, $named, 'No field list may reach the user: ' . $text);
            foreach ($codes as $code) {
                $this->assertStringNotContainsString((string)$code, $text, 'Issue codes must not reach the user.');
            }
        }
    }

    /**
     * Replay of Lauf 8 thread 1537 (EU-4): the constructor switched to a skill name that does not
     * exist (mod_booking.search_users). The turn ended at once with "this function is not enabled
     * here" instead of re-planning. An unregistered name must get the one framework retry.
     */
    public function test_unregistered_skill_name_is_retried_and_heals(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.book_users'),
            $this->constructor_confirmation_request('mod_booking.search_users', ['searchquery' => 'Frau Kraus']),
            $this->selector_skill_call('mod_booking.book_users'),
            $this->constructor_confirmation_request('mod_booking.book_users', [
                'optionquery' => 'Erste Hilfe',
                'userquery' => 'Frau Kraus',
            ]),
        ]);

        $result = $this->chat('Die Frau Kraus braucht Zugang zum Erste-Hilfe-Kurs.', (int)$threadid, $store, $runtime);

        $codes = (array)($result['issue_codes'] ?? []);
        $this->assertGreaterThanOrEqual(4, count($this->scriptedplannerprompts), 'A re-plan round must have run.');
        $this->assertNotContains(
            'SKILL_DENIED',
            $codes,
            'An invented skill name is no availability denial: ' . json_encode($codes)
        );
    }

    /**
     * If the planner insists on the unregistered name, the turn keeps the neutral availability
     * framing (SKILL_DENIED) after the one retry — never a technical error.
     */
    public function test_unregistered_skill_name_exhausted_keeps_availability_framing(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.book_users'),
            $this->constructor_confirmation_request('mod_booking.search_users', ['searchquery' => 'Frau Kraus']),
            $this->selector_skill_call('mod_booking.book_users'),
            $this->constructor_confirmation_request('mod_booking.search_users', ['searchquery' => 'Frau Kraus']),
        ]);

        $result = $this->chat('Die Frau Kraus braucht Zugang zum Erste-Hilfe-Kurs.', (int)$threadid, $store, $runtime);

        $codes = (array)($result['issue_codes'] ?? []);
        $this->assertGreaterThanOrEqual(4, count($this->scriptedplannerprompts), 'The retry must have run first.');
        $this->assertContains('SKILL_DENIED', $codes, json_encode($codes));
        $this->assertNotContains('SKILL_NOT_REGISTERED', $codes, 'The retry code is resolved before finalization.');
    }
}
