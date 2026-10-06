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
 * The constructor is told to carry a target name exactly as the user wrote it.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\orchestrator;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Wave 18 (baseline runs 25/26). The constructor changed target names before any resolver saw them:
 * "cours de statistiques" became coursequery "statistics" although the course is "Statistiques avancées"
 * (EU-3), "eine Bestätigung" became templatequery "booking confirmation" (CRT-2), "Madame Duvernay" became
 * userquery "Madame <e-mail>" (DUA-3), "Der Buchdruck-Kurs" travelled with its article (SCC-1). None of
 * these are search defects: the resolver never got the name the user used. The constructor prompt carried
 * no rule about it. This is a prompt instruction, which the HARD RULE allows; no lexical detection is
 * involved.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\orchestrator
 * @covers \bookingextension_agent\local\wizard\services\planner_phase_service
 */
final class constructor_prompt_keeps_target_names_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /**
     * Set up provider and capabilities.
     */
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

    /**
     * Release the scripted planner.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        parent::tearDown();
    }

    /**
     * The default constructor template states the rule; the selector template does not need it.
     */
    public function test_the_default_constructor_template_states_the_rule(): void {
        $template = orchestrator::get_default_constructor_prompt_template();
        $this->assertStringContainsString('TARGET NAMES', $template);
        // Wave 32 (frozen prompt spec, rule 3).
        $this->assertStringContainsString("carries the user's own words for the target", $template);
        $this->assertStringContainsString('Never translate it, shorten it or complete it', $template);
    }

    /**
     * Wave 30 (rules 8 and 20): a kind or role reference goes to the skill instead of a question, a value only the user
     * can give is asked for, and an unfit skill is stated structurally ("skill_fits": false) - never mixed.
     */
    public function test_the_template_separates_missing_input_from_an_unfit_skill(): void {
        $template = orchestrator::get_default_constructor_prompt_template();
        $this->assertStringContainsString('"skill_fits": false', $template);
        $this->assertStringContainsString('only by its kind or role', $template);
        // Wave 32 (frozen prompt spec, rules 2 and 5): only a declared required value is asked for; a missing value is
        // never an unfit skill.
        $this->assertStringContainsString(
            'required_input and required_groups are the ONLY source of what you may ask for',
            $template
        );
        $this->assertStringContainsString('A missing or unclear value is never this case', $template);
        // F79 stays: a value never comes from an example.
        $this->assertStringContainsString('Never take a value from example_parameters', $template);
        // The rule that made the constructor ask for "die Erinnerung" (CBI-4) is gone.
        $this->assertStringNotContainsString('If the user named no target, ask for it.', $template);
        $this->assertStringNotContainsString('cannot be fulfilled with grounded input', $template);
    }

    /**
     * Wave 30: the construction reminder states what the selected skill needs, from its declared contract - the
     * statement the removed repair round made in a second call (and wrongly for skills with required groups).
     */
    public function test_the_construction_reminder_states_the_skills_input_contract(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        $cases = [
            // No required field, no group: the skill resolves or asks itself.
            'wizard.list_memories' => 'Its required values: none - ask for nothing.',
            // A declared group: the group is named and a kind or role reference counts.
            // update_rule_from_template lost its required group in wave 32 (George decision b); update_option keeps one.
            'mod_booking.update_option' => 'one of optionid | optionquery',
        ];
        foreach ($cases as $skill => $expected) {
            [$store, $runtime, $threadid] = $this->build_runtime();
            $this->install_phase_scripted_planner(
                [$this->selector_skill_call($skill)],
                [$this->constructor_clarification('Welche?')]
            );
            $this->chat('Bitte erledigen.', (int)$threadid, $store, $runtime);
            $constructorprompts = array_values(array_filter(
                $this->scriptedplannerprompts,
                static fn(string $p): bool => strpos($p, 'phase_handoff.selection=') !== false
            ));
            $selectorprompts = array_values(array_filter(
                $this->scriptedplannerprompts,
                static fn(string $p): bool => strpos($p, 'phase_handoff.selection=') === false
            ));
            $this->assertCount(1, $constructorprompts, $skill);
            $this->assertStringContainsString($expected, $constructorprompts[0], $skill);
            // Wave 32 (frozen prompt spec): the reminder states facts only; the value rule stands once, in the template.
            $this->assertSame(1, substr_count($constructorprompts[0], 'VALUES COME FROM THE USER'), $skill);
            $this->assertStringNotContainsString('Its required values', $selectorprompts[0], 'construction only');
            $this->clear_scripted_planner();
            $this->scriptedplannerprompts = [];
        }
    }

    /**
     * Wave 30 (UO-3): the example parameters are preceded by the rule that they show the shape, never a value.
     */
    public function test_the_example_parameters_carry_the_shape_only_rule(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call('mod_booking.update_option')],
            [$this->constructor_clarification('Um welche Uhrzeit?')]
        );
        $this->chat('Füge Rooftop Yoga eine Sitzung Ende nächsten Monats hinzu.', (int)$threadid, $store, $runtime);
        $constructor = (string)array_values(array_filter(
            $this->scriptedplannerprompts,
            static fn(string $p): bool => strpos($p, 'phase_handoff.selection=') !== false
        ))[0];
        // Wave 32: the rule stands once, in the constructor template (rule 1), above the catalog.
        $this->assertSame(1, substr_count($constructor, 'Never take a value from example_parameters'));
        $rule = strpos($constructor, 'Never take a value from example_parameters');
        $example = strpos($constructor, '"example_parameters"');
        $this->assertNotFalse($rule, 'the rule is in the prompt');
        $this->assertNotFalse($example, 'the example is in the prompt');
        $this->assertLessThan($example, $rule, 'the rule stands before the example');
    }

    /**
     * Wave 30, run 40 (CSB-4 thread 12131, CSB-1 12126, CSL-1 12119): "never fill in a value the user did not give
     * (a time, a date, a number, a name)" made the constructor ask for the title of "Beratungsgespräche: Mo-Fr je
     * 10-16 Uhr ..." - a title the request gives in the user's own words. The value rules protect times, dates and
     * numbers; a title or name comes from the user's words; a question is only for a field the skill needs.
     */
    public function test_the_value_rules_leave_titles_to_the_users_words(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call('mod_booking.create_slotbooking_option')],
            [$this->constructor_clarification('Ich benötige noch den Titel für die Beratungsgespräch-Option.')]
        );
        $this->chat(
            'Beratungsgespräche: Mo-Fr je 10-16 Uhr, 45-Minuten-Fenster, immer nur eine Person gleichzeitig.',
            (int)$threadid,
            $store,
            $runtime
        );
        $constructor = (string)array_values(array_filter(
            $this->scriptedplannerprompts,
            static fn(string $p): bool => strpos($p, 'phase_handoff.selection=') !== false
        ))[0];

        // Wave 32 (frozen prompt spec, rule 1): one value rule, once, in the template. A value the user gave - a title
        // in the user's own words included - may be filled; only an invented one is forbidden. The reminder and the
        // catalog no longer restate it (the two restatements differed from each other: S3 in the inventory).
        $this->assertSame(1, substr_count($constructor, 'VALUES COME FROM THE USER'));
        $this->assertStringContainsString('Fill a field only with a value the user gave', $constructor);
        $this->assertStringContainsString('Never invent a time, a date, a number, an id, a URL or a name.', $constructor);
        $this->assertStringContainsString('required_input and required_groups are the ONLY source', $constructor);
    }

    /**
     * The live constructor prompt carries the rule, the live selector prompt does not.
     */
    public function test_the_live_constructor_prompt_carries_the_rule(): void {
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        $this->create_option('Name Target');
        [$store, $runtime, $threadid] = $this->build_runtime();

        $this->install_scripted_planner([
            $this->selector_skill_call('mod_booking.update_option'),
            $this->constructor_confirmation_request('mod_booking.update_option', [
                'optionquery' => 'Name Target',
                'text' => 'Renamed Target',
            ]),
        ]);

        $this->chat('Benenn die Option "Name Target" auf "Renamed Target" um.', (int)$threadid, $store, $runtime);

        $this->assertCount(2, $this->scriptedplannerprompts);
        $this->assertStringNotContainsString('TARGET NAMES', (string)$this->scriptedplannerprompts[0]);
        $this->assertStringContainsString('TARGET NAMES', (string)$this->scriptedplannerprompts[1]);
    }
}
