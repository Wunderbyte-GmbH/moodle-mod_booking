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
 * Each planner phase calls the provider action of its model tier.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\services\llm\query_english_normalizer;
use bookingextension_agent\local\wizard\services\orchestrator_prompt_profile_service;
use bookingextension_agent\local\wizard\services\orchestrator_routing_service;
use bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\wb_action_names;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use core_ai\manager as ai_manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/scripted_llm_trait.php');

/**
 * Phase model tiers (plan PHASE_MODEL_TIERS_PLAN.md, George 2026-09-30). The query normalizer and the selector run on
 * the small planner action (planner_decide), the constructor on its own action (planner_construct) and the synchronizer
 * on generate_agent_reply. Before, the constructor shared planner_decide with the selector and the normalizer ran on
 * core generate_text, so the hardest phase sat on the small model and the easiest on the large one.
 *
 * Asserts are structural only: the action class each phase called, the routing result, prompt bytes.
 *
 * @group bookingextension_agent
 * @group bookingextension_agent_agent
 * @covers \bookingextension_agent\local\wizard\services\orchestrator_routing_service
 * @covers \bookingextension_agent\local\wizard\services\llm\query_english_normalizer
 * @covers \bookingextension_agent\local\wizard\services\phase_prompt_bundle_builder
 * @covers \bookingextension_agent\local\wizard\services\planner_phase_service
 */
final class phase_model_tiers_test extends abstract_agent_testcase {
    use scripted_llm_trait;

    /** @var string The selector's (small) planner action. */
    private const DECIDE = 'aiprovider_wunderbyte\\aiactions\\planner_decide';

    /** @var string The constructor's (large) planner action. */
    private const CONSTRUCT = 'aiprovider_wunderbyte\\aiactions\\planner_construct';

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
            'test-model-mini',
            'test-embedding',
            'https://llm.wunderbyte.at/v1/chat/completions',
            'https://llm.wunderbyte.at/v1/embeddings'
        );
    }

    /**
     * Release the scripted planner and any manager double.
     */
    protected function tearDown(): void {
        $this->clear_scripted_planner();
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * The provider class of the constructor action must exist for the end-to-end cases.
     */
    private function require_construct_action(): void {
        if (!class_exists(self::CONSTRUCT)) {
            $this->markTestSkipped('aiprovider_wunderbyte without planner_construct');
        }
    }

    /**
     * An ai_manager double that reports only the given action classes as available.
     *
     * @param string[] $available
     * @return ai_manager
     */
    private function manager_with(array $available): ai_manager {
        global $DB;
        return new class ($DB, $available) extends ai_manager {
            /** @var string[] */
            private array $available;

            /**
             * Constructor.
             *
             * @param \moodle_database $db
             * @param string[] $available
             */
            public function __construct(\moodle_database $db, array $available) {
                parent::__construct($db);
                $this->available = $available;
            }

            #[\Override]
            public function is_action_available(string $actionclass): bool {
                return in_array(ltrim($actionclass, '\\'), $this->available, true);
            }

            #[\Override]
            public function is_action_enabled_in_context(\context $context, string $actionclass): bool {
                return $this->is_action_available($actionclass);
            }
        };
    }

    /**
     * Build the routing service as the orchestrator builds it.
     *
     * @return orchestrator_routing_service
     */
    private function routing(): orchestrator_routing_service {
        return new orchestrator_routing_service(self::DECIDE, self::CONSTRUCT);
    }

    /**
     * The constructor routes to its own action when the provider offers it; the selector stays on planner_decide.
     */
    public function test_construction_routes_to_construct_action_when_available(): void {
        $manager = $this->manager_with([self::DECIDE, self::CONSTRUCT]);
        $context = \context_system::instance();

        $construction = $this->routing()->resolve_action_class_for_phase(
            $manager,
            $context,
            orchestrator_routing_service::PHASE_PARAMETER_CONSTRUCTION
        );
        $selection = $this->routing()->resolve_action_class_for_phase(
            $manager,
            $context,
            orchestrator_routing_service::PHASE_SELECTION
        );

        $this->assertSame(self::CONSTRUCT, $construction['actionclass']);
        $this->assertFalse($construction['routingfallback']);
        $this->assertSame(self::DECIDE, $selection['actionclass']);
        $this->assertFalse($selection['routingfallback']);
    }

    /**
     * Without the constructor action (older provider, action disabled) the constructor keeps planner_decide.
     */
    public function test_construction_falls_back_to_decide_when_construct_unavailable(): void {
        $construction = $this->routing()->resolve_action_class_for_phase(
            $this->manager_with([self::DECIDE]),
            \context_system::instance(),
            orchestrator_routing_service::PHASE_PARAMETER_CONSTRUCTION
        );

        $this->assertSame(self::DECIDE, $construction['actionclass']);
        $this->assertFalse($construction['routingfallback']);
    }

    /**
     * Without any Wunderbyte action the core chain stays as it was (pin of the existing behaviour).
     */
    public function test_construction_without_wunderbyte_provider_keeps_core_chain(): void {
        $context = \context_system::instance();

        $summarise = $this->routing()->resolve_action_class_for_phase(
            $this->manager_with([summarise_text::class]),
            $context,
            orchestrator_routing_service::PHASE_PARAMETER_CONSTRUCTION
        );
        $none = $this->routing()->resolve_action_class_for_phase(
            $this->manager_with([]),
            $context,
            orchestrator_routing_service::PHASE_PARAMETER_CONSTRUCTION
        );

        $this->assertSame(summarise_text::class, $summarise['actionclass']);
        $this->assertFalse($summarise['routingfallback']);
        $this->assertSame(generate_text::class, $none['actionclass']);
        $this->assertTrue($none['routingfallback']);
    }

    /**
     * The debug source names the constructor action with its own code, so llm_debug separates the two tiers.
     */
    public function test_construct_action_has_own_debug_source_code(): void {
        $build = fn(string $actionclass, string $phase): string => $this->routing()->build_debug_source(
            $actionclass,
            'wunderbyte',
            false,
            $phase,
            'aiprovider_wunderbyte',
            3,
            1,
            'embed_topk',
            'applied',
            6,
            false,
            false
        );

        $construct = $build(self::CONSTRUCT, orchestrator_routing_service::PHASE_PARAMETER_CONSTRUCTION);
        $decide = $build(self::DECIDE, orchestrator_routing_service::PHASE_SELECTION);

        $this->assertStringContainsString('|ac=wpc|', $construct);
        $this->assertStringContainsString('|ac=wpl|', $decide);
        $this->assertLessThanOrEqual(100, \core_text::strlen($construct));
    }

    /**
     * The query normalizer runs on the small planner action when the provider offers it (flowchart QNORM).
     */
    public function test_normalizer_uses_the_planner_action_when_available(): void {
        $calls = [];
        llm_call_service::set_test_responder(static function (string $actionclass) use (&$calls): string {
            $calls[] = $actionclass;
            return 'Book Anna for the course';
        });

        $out = (new query_english_normalizer())->to_english(
            'Buche Anna für den Kurs',
            (int)\context_system::instance()->id,
            (int)$this->teacher->id
        );

        $this->assertSame([self::DECIDE], $calls);
        $this->assertSame('Book Anna for the course', $out);
    }

    /**
     * Without the planner action the normalizer keeps core generate_text (installations without the Wunderbyte provider).
     */
    public function test_normalizer_keeps_generate_text_without_the_planner_action(): void {
        \core\di::set(ai_manager::class, $this->manager_with([generate_text::class]));
        $calls = [];
        llm_call_service::set_test_responder(static function (string $actionclass) use (&$calls): string {
            $calls[] = $actionclass;
            return 'Book Anna for the course';
        });

        (new query_english_normalizer())->to_english(
            'Buche Anna für den Kurs',
            (int)\context_system::instance()->id,
            (int)$this->teacher->id
        );

        $this->assertSame([generate_text::class], $calls);
    }

    /**
     * A failing small action never breaks discovery: the normalizer returns the raw query (fail-open).
     */
    public function test_normalizer_is_fail_open_on_small_action_error(): void {
        llm_call_service::set_test_responder(static function (): array {
            return ['content' => '', 'success' => false, 'errorcode' => 503, 'errormessage' => 'scripted failure'];
        });

        $out = (new query_english_normalizer())->to_english(
            'Buche Anna für den Kurs',
            (int)\context_system::instance()->id,
            (int)$this->teacher->id
        );

        $this->assertSame('Buche Anna für den Kurs', $out);
    }

    /**
     * A full turn: the selector calls planner_decide, the constructor planner_construct, the synchronizer
     * generate_agent_reply. The constructor's answer is a clarification, so the turn also covers the question path.
     */
    public function test_each_phase_invokes_its_action(): void {
        $this->require_construct_action();
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call('mod_booking.update_option')],
            [$this->constructor_clarification('Welche Option?')]
        );

        $result = $this->chat('Ändere die Option.', (int)$threadid, $store, $runtime);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));
        $byphase = [];
        foreach ($this->scriptedactions as [$phase, $actionclass]) {
            $byphase[$phase][] = ltrim((string)$actionclass, '\\');
        }
        $this->assertNotEmpty($byphase['S'] ?? [], 'the selector ran');
        $this->assertNotEmpty($byphase['C'] ?? [], 'the constructor ran');
        $this->assertSame([self::DECIDE], array_values(array_unique($byphase['S'])));
        $this->assertSame([self::CONSTRUCT], array_values(array_unique($byphase['C'])));
        foreach ($byphase['Y'] ?? [] as $actionclass) {
            $this->assertSame(wb_action_names::GENERATE_AGENT_REPLY, $actionclass);
        }
    }

    /**
     * The planner prompts are byte-identical whichever planner action serves a phase: only the model changes.
     * Without a shared planner-action check the constructor would lose its construction template.
     */
    public function test_prompts_are_byte_identical_across_planner_actions(): void {
        $this->setUser($this->teacher);
        $builder = new phase_prompt_bundle_builder(skill_registry::make_default(), new orchestrator_prompt_profile_service());
        $contextid = $this->booking_contextid();
        $userid = (int)$this->teacher->id;

        foreach ([orchestrator::PHASE_PARAMETER_CONSTRUCTION, orchestrator_prompt_profile_service::PHASE_SELECTION] as $phase) {
            $decide = $builder->build_system_prompt($userid, $contextid, $phase, self::DECIDE, true);
            $construct = $builder->build_system_prompt($userid, $contextid, $phase, self::CONSTRUCT, true);
            $this->assertSame(hash('sha256', $decide), hash('sha256', $construct), $phase);
        }
        $this->assertSame(
            orchestrator::get_default_initial_prompt_template_for_action(self::DECIDE),
            orchestrator::get_default_initial_prompt_template_for_action(self::CONSTRUCT)
        );
    }

    /**
     * A full turn with and without the constructor action yields the same constructor prompt bytes.
     */
    public function test_live_constructor_prompt_is_identical_with_and_without_construct_action(): void {
        $this->require_construct_action();
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();

        $constructorprompt = function (): string {
            [$store, $runtime, $threadid] = $this->build_runtime();
            $this->install_phase_scripted_planner(
                [$this->selector_skill_call('mod_booking.update_option')],
                [$this->constructor_clarification('Welche Option?')]
            );
            $this->chat('Ändere die Option.', (int)$threadid, $store, $runtime);
            $prompts = array_values(array_filter(
                $this->scriptedplannerprompts,
                static fn(string $p): bool => strpos($p, 'phase_handoff.selection=') !== false
            ));
            $this->assertCount(1, $prompts);
            $actions = array_values(array_filter($this->scriptedactions, static fn(array $a): bool => $a[0] === 'C'));
            $this->clear_scripted_planner();
            return $actions[0][1] . "\n" . $prompts[0];
        };

        [$withaction, $withprompt] = explode("\n", $constructorprompt(), 2);
        $this->disable_construct_action();
        [$withoutaction, $withoutprompt] = explode("\n", $constructorprompt(), 2);

        $this->assertSame(self::CONSTRUCT, ltrim($withaction, '\\'));
        $this->assertSame(self::DECIDE, ltrim($withoutaction, '\\'));
        $this->assertSame(hash('sha256', $withoutprompt), hash('sha256', $withprompt));
    }

    /**
     * A truncated constructor answer is retried once with the SAME action (#2395 stays pinned).
     */
    public function test_truncated_construction_retries_with_the_same_action(): void {
        $this->require_construct_action();
        $this->setUser($this->teacher);
        $_POST['sesskey'] = sesskey();
        [$store, $runtime, $threadid] = $this->build_runtime();
        $this->install_phase_scripted_planner(
            [$this->selector_skill_call('mod_booking.create_option')],
            [
                $this->scripted_truncated_output('partial reasoning'),
                $this->scripted_truncated_output('partial reasoning'),
            ]
        );

        $result = $this->chat('Erstelle den Workshop "Tier Workshop" am 10.11.2045.', (int)$threadid, $store, $runtime);

        $this->assertSame('clarification', (string)($result['response_type'] ?? ''));
        $constructor = array_values(array_filter($this->scriptedactions, static fn(array $a): bool => $a[0] === 'C'));
        $this->assertCount(2, $constructor, 'one retry of the construction call');
        $this->assertSame(self::CONSTRUCT, ltrim((string)$constructor[0][1], '\\'));
        $this->assertSame(self::CONSTRUCT, ltrim((string)$constructor[1][1], '\\'));
    }

    /**
     * A provisioned trial instance puts the constructor on the large model and the selector on the small one.
     */
    public function test_trial_provisioner_seeds_both_planner_tiers(): void {
        $provisioner = new \bookingextension_agent\local\wizard\services\trial\trial_provisioner();
        $method = new \ReflectionMethod($provisioner, 'build_actionconfig');
        $config = $method->invoke($provisioner, 'wunderbyte', 'https://llm.wunderbyte.at');

        $this->assertSame('wunderbyte-privat-mini', $config[self::DECIDE]['settings']['model']);
        $this->assertSame('wunderbyte-privat', $config[self::CONSTRUCT]['settings']['model']);
        $this->assertTrue($config[self::CONSTRUCT]['enabled']);
        $this->assertSame(
            $config[self::DECIDE]['settings']['endpoint'],
            $config[self::CONSTRUCT]['settings']['endpoint']
        );
        // No action carries an instruction of its own: every phase brings its complete prompt.
        foreach ($config as $actionclass => $entry) {
            $this->assertSame('', (string)($entry['settings']['systeminstruction'] ?? ''), $actionclass);
        }
    }

    /**
     * A trial without the Wunderbyte provider runs role-less on the core OpenAI provider: it gets the role-less alias.
     *
     * The role-aware alias (wunderbyte-privat) may carry JSON mode per deployment and role (#2537); a request without a
     * role must never land there (Wunderbyte-GmbH/Wunderbyte-GmbH#2541).
     */
    public function test_trial_provisioner_openai_strategy_uses_the_roleless_alias(): void {
        $provisioner = new \bookingextension_agent\local\wizard\services\trial\trial_provisioner();
        $method = new \ReflectionMethod($provisioner, 'build_actionconfig');
        $config = $method->invoke($provisioner, 'openai', 'https://llm.wunderbyte.at');

        // Both core actions the agent routes to on the OpenAI provider (summarise_text carries selection and
        // construction there, #2569) use the role-less alias.
        $this->assertSame(
            ['core_ai\\aiactions\\generate_text', 'core_ai\\aiactions\\summarise_text'],
            array_keys($config)
        );
        foreach ($config as $actionclass => $entry) {
            $this->assertSame('wunderbyte-trial', $entry['settings']['model'], $actionclass);
        }
        // The Wunderbyte strategy keeps the role-aware tiers.
        $wb = $method->invoke($provisioner, 'wunderbyte', 'https://llm.wunderbyte.at');
        $this->assertSame('wunderbyte-privat', $wb['core_ai\\aiactions\\generate_text']['settings']['model']);
    }

    /**
     * Cloning a source that points at the Wunderbyte LLM gives every Wunderbyte action its standard tier.
     *
     * The source's single chat model (here the role-less trial alias) must not be copied into the role-aware actions.
     */
    public function test_clone_from_wunderbyte_endpoint_uses_the_standard_tiers(): void {
        $provisioner = new \bookingextension_agent\local\wizard\services\trial\trial_provisioner();
        $method = new \ReflectionMethod($provisioner, 'build_cloned_actionconfig');
        $config = $method->invoke($provisioner, 'https://llm.wunderbyte.at/v1/chat/completions', 'wunderbyte-trial');

        $this->assertSame('wunderbyte-privat-mini', $config[self::DECIDE]['settings']['model']);
        $this->assertSame('wunderbyte-privat', $config[self::CONSTRUCT]['settings']['model']);
        $this->assertSame('wunderbyte-privat', $config[wb_action_names::GENERATE_AGENT_REPLY]['settings']['model']);
        $this->assertSame('wunderbyte-privat', $config['core_ai\\aiactions\\generate_text']['settings']['model']);
        $this->assertSame('wunderbyte-embeddings', $config[wb_action_names::GENERATE_EMBEDDINGS]['settings']['model']);
        $this->assertSame(3584, $config[wb_action_names::GENERATE_EMBEDDINGS]['settings']['dimensions']);
    }

    /**
     * Cloning a third-party source keeps its own chat model: the standard tiers exist only on the Wunderbyte LLM.
     */
    public function test_clone_from_third_party_endpoint_keeps_the_source_model(): void {
        $provisioner = new \bookingextension_agent\local\wizard\services\trial\trial_provisioner();
        $method = new \ReflectionMethod($provisioner, 'build_cloned_actionconfig');
        $config = $method->invoke($provisioner, 'https://api.openai.com/v1/chat/completions', 'gpt-4.1-mini');

        $this->assertSame('gpt-4.1-mini', $config[self::DECIDE]['settings']['model']);
        $this->assertSame('gpt-4.1-mini', $config[self::CONSTRUCT]['settings']['model']);
        $this->assertSame('gpt-4.1-mini', $config['core_ai\\aiactions\\generate_text']['settings']['model']);
        $this->assertSame('text-embedding-3-small', $config[wb_action_names::GENERATE_EMBEDDINGS]['settings']['model']);
    }

    /**
     * A benchmark override sets the model of every chat action the agent calls, generate_text included.
     */
    public function test_benchmark_override_covers_every_chat_action(): void {
        global $DB;
        $saved = [];
        $env = [
            'BOOKING_TEST_AI_KEY' => 'bench-key',
            'BOOKING_TEST_AI_MODEL' => 'bench-large',
            'BOOKING_TEST_AI_MODEL_MINI' => 'bench-small',
            'BOOKING_TEST_AI_MODEL_CONSTRUCT' => 'bench-construct',
        ];
        foreach ($env as $name => $value) {
            $saved[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        try {
            $providers = (new \bookingextension_agent\local\wizard\benchmark\benchmark_envkey_manager($DB))
                ->get_sorted_providers();
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }

        $wunderbyte = array_values(array_filter(
            $providers,
            static fn($p): bool => $p instanceof \aiprovider_wunderbyte\provider
        ));
        $this->assertNotEmpty($wunderbyte);
        $actionconfig = $wunderbyte[0]->actionconfig;
        $this->assertSame('bench-small', $actionconfig[self::DECIDE]['settings']['model']);
        $this->assertSame('bench-construct', $actionconfig[self::CONSTRUCT]['settings']['model']);
        $this->assertSame('bench-large', $actionconfig[wb_action_names::GENERATE_AGENT_REPLY]['settings']['model']);
        $this->assertSame('bench-large', $actionconfig[generate_text::class]['settings']['model']);
    }

    /**
     * Disable planner_construct on every Wunderbyte provider instance.
     */
    private function disable_construct_action(): void {
        global $DB;
        foreach ($DB->get_records('ai_providers', ['provider' => 'aiprovider_wunderbyte\\provider']) as $record) {
            $config = json_decode((string)$record->actionconfig, true) ?: [];
            if (isset($config[self::CONSTRUCT])) {
                $config[self::CONSTRUCT]['enabled'] = false;
            }
            $record->actionconfig = json_encode($config);
            $DB->update_record('ai_providers', $record);
        }
    }
}
