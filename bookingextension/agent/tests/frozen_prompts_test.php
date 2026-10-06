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
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\prompt_policy_builder;
use bookingextension_agent\local\wizard\services\synchronizer_input_builder;
use bookingextension_agent\local\wizard\services\turn_skill_exclusions;

/**
 * The consolidated prompts of wave 32 are frozen, and the engine pieces they rely on hold.
 *
 * Frozen prompt spec (secret_docs FROZEN_PROMPTS_SPEC_2026-09-25.md): one specification per phase, every rule exactly
 * once, no policy blocks that restate or contradict it. Top rule (George 2026-09-25): stable aiinitialprompts; a
 * change needs proven necessity, A/B, the asset prompts held and George's approval - never a single prompt going green.
 * The hash test below is the tripwire for that rule: it breaks on ANY change to the three templates.
 *
 * @package    bookingextension_agent
 * @category   test
 * @covers     \bookingextension_agent\local\wizard\orchestrator
 * @covers     \bookingextension_agent\local\wizard\prompt_policy_builder
 * @covers     \bookingextension_agent\local\wizard\services\synchronizer_input_builder
 * @covers     \bookingextension_agent\local\wizard\services\turn_skill_exclusions
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class frozen_prompts_test extends advanced_testcase {
    /** sha1 of the frozen templates. Change only with George's explicit approval (top rule), never to make a prompt pass. */
    private const FROZEN = [
        // Selector changed with George's approval on 2026-10-03 (Wunderbyte-GmbH/Wunderbyte-GmbH#2546, instruction lab:
        // contradictions between the static rules, the skill cards and the observations resolved). The constructor change of the
        // same ticket was reverted after baseline L67inst, so its hash is the previous one again.
        'selector' => '8e4274b2f5958163d2e60f2e79a4194518071bfc',
        'constructor' => '938fc1cd46a75addff9c26849c8132a1a178afd1',
        // Synchronizer changed on 2026-10-05: rule 5 names each item by its name and type, never by the type alone.
        'synchronizer' => 'c312cd5000823ace960a61ba0a638d9619ffe24f',
    ];

    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * The three templates are byte-identical to the frozen specification.
     */
    public function test_the_templates_are_frozen(): void {
        $actual = [
            'selector' => sha1(orchestrator::get_default_initial_prompt_template_for_action(
                \core_ai\aiactions\summarise_text::class
            )),
            'constructor' => sha1(orchestrator::get_default_constructor_prompt_template()),
            'synchronizer' => sha1(orchestrator::get_default_initial_prompt_template_for_action(
                \core_ai\aiactions\generate_text::class
            )),
        ];
        $this->assertSame(self::FROZEN, $actual, 'a frozen prompt changed - top rule: only with George\'s approval');
    }

    /**
     * The synchronizer names an item by its name and its type: told to name it by the type, it linked the word "user"
     * and left the person's name out.
     */
    public function test_the_synchronizer_names_items_by_name_and_type(): void {
        $prompt = orchestrator::get_default_initial_prompt_template_for_action(\core_ai\aiactions\generate_text::class);

        $this->assertStringContainsString(
            'Name each item by its name and by the type the observation gives it',
            $prompt
        );
        $this->assertStringContainsString('never by the type alone', $prompt);
        $this->assertStringContainsString('link its name with exactly that URL', $prompt);
        $this->assertStringNotContainsString('Name each item by the type the observation gives it', $prompt);
    }

    /**
     * Selection and construction get no policy blocks; the admin scope restriction is the only addition.
     */
    public function test_no_policy_blocks_restate_the_templates(): void {
        $this->resetAfterTest();
        $this->assertSame('', prompt_policy_builder::build_planner_policies('selection', true, false));
        $this->assertSame('', prompt_policy_builder::build_planner_policies('parameter_construction', true, false));
        set_config('restricttoscope', 1, 'bookingextension_agent');
        $scope = prompt_policy_builder::build_planner_policies('selection', false, true);
        $this->assertStringContainsString('SCOPE IS RESTRICTED', $scope);
        $this->assertStringNotContainsString('NON-OPTIONAL', $scope);
        $this->assertSame('', prompt_policy_builder::build_planner_policies('parameter_construction', true, false));
    }

    /**
     * A planner 'sufficient' reaches the synchronizer as planner text, never as a result; a question stays the result.
     */
    public function test_planner_text_is_not_a_result(): void {
        $method = new \ReflectionMethod(synchronizer_input_builder::class, 'build_source_observation');
        $method->setAccessible(true);
        $builder = (new \ReflectionClass(synchronizer_input_builder::class))->newInstanceWithoutConstructor();

        $sufficient = (string)$method->invoke($builder, ['response_type' => 'sufficient', 'message' => 'Stored for you.']);
        $this->assertStringStartsWith('PLANNER_TEXT (not a result)', $sufficient);
        $this->assertStringNotContainsString('FINAL_SOURCE_RESULT', $sufficient);

        $question = (string)$method->invoke($builder, ['response_type' => 'clarification', 'message' => 'Which course?']);
        $this->assertStringStartsWith('FINAL_SOURCE_RESULT', $question);
    }

    /**
     * An excluded skill stays out for the rest of the turn only, and carries the construction's reason.
     */
    public function test_an_unfit_skill_is_excluded_for_this_turn_only(): void {
        $this->resetAfterTest();
        $store = new conversation_store();
        $thread = $store->create_fresh_thread(2, (int)\context_system::instance()->id);
        $threadid = (int)$thread->id;
        $store->add_message($threadid, 'user', 'first request');

        turn_skill_exclusions::exclude($store, $threadid, 'mod_booking.get_option_details', 'It cannot print lists.');
        $reasons = turn_skill_exclusions::reasons($store, $threadid);
        $this->assertSame(['mod_booking.get_option_details' => 'It cannot print lists.'], $reasons);
        $catalog = [['skill' => 'mod_booking.get_option_details'], ['skill' => 'wizard.search_skills']];
        $excluded = turn_skill_exclusions::excluded($store, $threadid);
        $this->assertSame([['skill' => 'wizard.search_skills']], turn_skill_exclusions::filter_catalog($catalog, $excluded));

        $store->add_message($threadid, 'user', 'next request');
        $this->assertSame([], turn_skill_exclusions::excluded($store, $threadid), 'a new user message is a new turn');
    }
}
