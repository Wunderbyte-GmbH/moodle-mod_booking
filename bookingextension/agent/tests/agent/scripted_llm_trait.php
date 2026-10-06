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
 * Scripted-LLM test harness: drive run_loop deterministically without a live provider.
 *
 * Installs a scripted responder on llm_call_service so the discovery/selector/constructor/
 * synchronizer phases return pre-programmed content. This closes the biggest test-fidelity gap
 * (the real chat entry ai_send_message -> run_loop had NO deterministic driver, so the whole
 * mutating chat turn and the confirm re-entrancy were only exercisable with a stochastic,
 * CI-skipped real LLM). See DAY_AUDIT_2026-07-09_COMMITS_VS_THREADS.md, test-fidelity section.
 *
 * @package   bookingextension_agent
 * @category  test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use bookingextension_agent\local\wizard\services\llm\llm_call_service;
use bookingextension_agent\local\wizard\wb_action_names;

/**
 * Reusable scripted-planner installer for deterministic run_loop tests.
 */
trait scripted_llm_trait {
    /** @var array FIFO of planner_decide responses (selector, constructor, selector, ...). */
    private array $scriptedplannerqueue = [];

    /** @var array FIFO of synchronizer (generate_agent_reply) responses. */
    private array $scriptedsyncqueue = [];

    /** @var string[] Every planner_decide prompt, in call order (for prompt-contract assertions). */
    protected array $scriptedplannerprompts = [];

    /** @var string[] Every synchronizer (generate_agent_reply) prompt, in call order. */
    protected array $scriptedsyncprompts = [];

    /** @var array FIFO of selector responses for the phase-aware planner. */
    private array $scriptedselectorqueue = [];

    /** @var array FIFO of constructor responses for the phase-aware planner. */
    private array $scriptedconstructorqueue = [];

    /** @var string[] Phase of every planner_decide call in call order: 'S' selector, 'C' constructor. */
    protected array $scriptedphases = [];

    /**
     * Every scripted call in call order as [phase, actionclass]: 'N' query normalizer, 'S' selector,
     * 'C' constructor, 'Y' synchronizer, 'O' any other call. Lets a test state which provider action
     * each phase used.
     *
     * @var array
     */
    protected array $scriptedactions = [];

    /**
     * The planner action classes: the selector's and the constructor's own (phase model tiers).
     * A static property, not a constant: constants in traits need PHP 8.2.
     *
     * @var string[]
     */
    private static array $planneractionclasses = [
        'aiprovider_wunderbyte\\aiactions\\planner_decide',
        'aiprovider_wunderbyte\\aiactions\\planner_construct',
    ];

    /**
     * Engine marker that only a constructor prompt carries: the handoff of the selection phase.
     * Verified on the corpus 2026-09-25: 137 of 137 constructor prompts, 0 of 187 selector prompts.
     * A static property, not a constant: constants in traits need PHP 8.2 and Moodle 4.5 still runs on 8.1.
     *
     * @var string
     */
    private static string $constructorpromptmarker = 'phase_handoff.selection=';

    /**
     * Install a phase-aware scripted planner: selector and constructor calls consume their own FIFO.
     *
     * A test written against this installer states WHAT each phase answers, not in which order the engine
     * asks - so it holds whether a second constructor answer comes from a repair round or from a re-plan via
     * the selector (wave 30). The phase sequence is recorded in $scriptedphases for flow invariants.
     *
     * @param array $selectorscript One entry per selector call, in order.
     * @param array $constructorscript One entry per constructor call, in order.
     * @param string $finalmessage User-facing message for terminal/synchronizer calls.
     * @param array $syncscript One entry per synchronizer call, in order.
     * @return void
     */
    protected function install_phase_scripted_planner(
        array $selectorscript,
        array $constructorscript,
        string $finalmessage = 'Done.',
        array $syncscript = []
    ): void {
        $normalize = static fn($entry) => is_array($entry) ? $entry : (string)$entry;
        $this->scriptedselectorqueue = array_values(array_map($normalize, $selectorscript));
        $this->scriptedconstructorqueue = array_values(array_map($normalize, $constructorscript));
        $this->scriptedsyncqueue = array_values(array_map($normalize, $syncscript));
        $this->scriptedphases = [];
        $this->scriptedactions = [];

        $sufficient = json_encode([
            'response_type' => 'sufficient',
            'message' => $finalmessage,
            'commands' => [],
            'user_lang' => 'en',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $responder = function (string $actionclass, string $prompt, string $source = '') use ($sufficient) {
            if (self::is_scripted_normalizer_call($source)) {
                $this->scriptedactions[] = ['N', $actionclass];
                return $sufficient;
            }
            if (self::is_scripted_planner_action($actionclass)) {
                $this->scriptedplannerprompts[] = $prompt;
                if (strpos($prompt, self::$constructorpromptmarker) !== false) {
                    $this->scriptedphases[] = 'C';
                    $this->scriptedactions[] = ['C', $actionclass];
                    return !empty($this->scriptedconstructorqueue) ? array_shift($this->scriptedconstructorqueue) : $sufficient;
                }
                $this->scriptedphases[] = 'S';
                $this->scriptedactions[] = ['S', $actionclass];
                return !empty($this->scriptedselectorqueue) ? array_shift($this->scriptedselectorqueue) : $sufficient;
            }
            if ($actionclass === wb_action_names::GENERATE_AGENT_REPLY) {
                $this->scriptedsyncprompts[] = $prompt;
                $this->scriptedactions[] = ['Y', $actionclass];
                return !empty($this->scriptedsyncqueue) ? array_shift($this->scriptedsyncqueue) : $sufficient;
            }
            $this->scriptedactions[] = ['O', $actionclass];
            return $sufficient;
        };
        llm_call_service::set_test_responder($responder);

        llm_call_service::set_test_embedding(array_fill(0, 8, 0.01));
    }

    /**
     * The recorded phase sequence as a string ("SCSC..."), for flow assertions.
     *
     * @return string
     */
    protected function scripted_phase_sequence(): string {
        return implode('', $this->scriptedphases);
    }

    /**
     * Install a scripted planner. Planner (planner_decide) calls consume $plannerscript in order;
     * once exhausted they fall back to a terminal 'sufficient' so the loop always converges. The
     * synchronizer (generate_agent_reply) consumes $syncscript in order, then returns a
     * 'sufficient' reply, as do generate_text calls. Discovery embeddings return a fixed vector
     * (inert — the selector output is scripted).
     *
     * A script entry is either a raw JSON string (a successful, completed provider answer) or a
     * structured provider result built with scripted_truncated_output() / scripted_provider_failure().
     *
     * @param array $plannerscript One entry per planner_decide call, in call order.
     * @param string $finalmessage User-facing message returned for terminal/synchronizer calls.
     * @param array $syncscript One entry per synchronizer call, in call order.
     * @return void
     */
    protected function install_scripted_planner(
        array $plannerscript,
        string $finalmessage = 'Done.',
        array $syncscript = []
    ): void {
        $normalize = static fn($entry) => is_array($entry) ? $entry : (string)$entry;
        $this->scriptedplannerqueue = array_values(array_map($normalize, $plannerscript));
        $this->scriptedsyncqueue = array_values(array_map($normalize, $syncscript));

        $sufficient = json_encode([
            'response_type' => 'sufficient',
            'message' => $finalmessage,
            'commands' => [],
            'user_lang' => 'en',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $responder = function (string $actionclass, string $prompt, string $source = '') use ($sufficient) {
            if (self::is_scripted_normalizer_call($source)) {
                // The query normalizer is not a planner turn: it never consumes a scripted planner answer.
                $this->scriptedactions[] = ['N', $actionclass];
                return $sufficient;
            }
            if (self::is_scripted_planner_action($actionclass)) {
                // Record the prompt so tests can assert prompt contracts (e.g. the
                // pending-step block the selector was shown — thread 589 regression).
                $this->scriptedplannerprompts[] = $prompt;
                $this->scriptedactions[] = [
                    strpos($prompt, self::$constructorpromptmarker) !== false ? 'C' : 'S',
                    $actionclass,
                ];
                if (!empty($this->scriptedplannerqueue)) {
                    return array_shift($this->scriptedplannerqueue);
                }
                return $sufficient;
            }
            if ($actionclass === wb_action_names::GENERATE_AGENT_REPLY) {
                $this->scriptedsyncprompts[] = $prompt;
                $this->scriptedactions[] = ['Y', $actionclass];
                if (!empty($this->scriptedsyncqueue)) {
                    return array_shift($this->scriptedsyncqueue);
                }
                return $sufficient;
            }
            // The generate_text and summarise_text calls.
            $this->scriptedactions[] = ['O', $actionclass];
            return $sufficient;
        };
        llm_call_service::set_test_responder($responder);

        // A tiny fixed vector; discovery still runs, but the scripted selector ignores its result.
        llm_call_service::set_test_embedding(array_fill(0, 8, 0.01));
    }

    /**
     * Remove the scripted planner (call in tearDown, always safe even if never installed).
     *
     * @return void
     */
    protected function clear_scripted_planner(): void {
        llm_call_service::set_test_responder(null);
        llm_call_service::set_test_embedding(null);
        $this->scriptedplannerqueue = [];
        $this->scriptedplannerprompts = [];
        $this->scriptedsyncqueue = [];
        $this->scriptedsyncprompts = [];
        $this->scriptedselectorqueue = [];
        $this->scriptedconstructorqueue = [];
        $this->scriptedphases = [];
        $this->scriptedactions = [];
    }

    /**
     * Whether a call goes to one of the planner actions (selector or constructor).
     *
     * @param string $actionclass
     * @return bool
     */
    private static function is_scripted_planner_action(string $actionclass): bool {
        return in_array(ltrim($actionclass, '\\'), self::$planneractionclasses, true);
    }

    /**
     * Whether a call is the discovery query normalizer, told apart by its debug source step (st=qnorm).
     *
     * @param string $source
     * @return bool
     */
    private static function is_scripted_normalizer_call(string $source): bool {
        return strpos($source, '|st=qnorm|') !== false;
    }

    /**
     * Convenience: a provider answer cut off at the output-token cap (finish_reason 'length').
     *
     * With merge_reasoning_content_in_choices the provider delivers the partial reasoning as
     * content, so a truncated answer is successful and non-empty but not a planner payload.
     *
     * @param string $partialcontent
     * @return array
     */
    protected function scripted_truncated_output(string $partialcontent): array {
        return [
            'content' => $partialcontent,
            'success' => true,
            'finishreason' => 'length',
        ];
    }

    /**
     * Convenience: a failed provider call with an HTTP status (e.g. 504 gateway timeout).
     *
     * @param int $httpcode
     * @return array
     */
    protected function scripted_provider_failure(int $httpcode): array {
        return [
            'content' => '',
            'success' => false,
            'errorcode' => $httpcode,
            'errormessage' => 'scripted provider failure',
        ];
    }

    /**
     * Convenience: a selector 'skill_call' for one skill (constructor stage follows).
     *
     * @param string $skill
     * @param array $plannedsteps Optional planned-step intents for a multi-step series.
     * @param string $nextstepintent
     * @return string
     */
    protected function selector_skill_call(
        string $skill,
        array $plannedsteps = [],
        string $nextstepintent = 'next'
    ): string {
        return json_encode([
            'response_type' => 'skill_call',
            'commands' => [['skill' => $skill, 'input' => []]],
            'planned_steps' => array_map(static fn($i): array => ['intent' => (string)$i], $plannedsteps),
            'next_step_intent' => $nextstepintent,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Convenience: a constructor 'confirmation_request' carrying one mutating command.
     *
     * @param string $skill
     * @param array $parameters
     * @param string $message
     * @return string
     */
    protected function constructor_confirmation_request(
        string $skill,
        array $parameters,
        string $message = 'Please confirm.'
    ): string {
        return json_encode([
            'response_type' => 'confirmation_request',
            'message' => $message,
            'next_step_intent' => '',
            'lang' => 'en',
            'user_lang' => 'en',
            'commands' => [['skill' => $skill, 'version' => 1, 'parameters' => $parameters]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Convenience: a constructor 'skill_call' carrying one read-only command.
     *
     * @param string $skill
     * @param array $parameters
     * @return string
     */
    protected function constructor_skill_call(string $skill, array $parameters): string {
        return json_encode([
            'response_type' => 'skill_call',
            'message' => '',
            'next_step_intent' => '',
            'lang' => 'de',
            'user_lang' => 'de',
            'commands' => [['skill' => $skill, 'version' => 1, 'parameters' => $parameters]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Convenience: a planner turn that answers from what it already observed.
     *
     * @param string $message
     * @return string
     */
    protected function planner_sufficient(string $message): string {
        return json_encode([
            'response_type' => 'sufficient',
            'message' => $message,
            'next_step_intent' => '',
            'lang' => 'de',
            'user_lang' => 'de',
            'commands' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Convenience: a bare constructor 'clarification' — a question with no command and no issue code.
     *
     * This is what the live constructor emits when it asks the user although its skill requires
     * nothing (baseline run 17: LR-2, TSA-4, UQ-1, UQ-4). Scripting it is the only way to exercise
     * the repair round at the phase seam; a unit test that hands the issue code in cannot.
     *
     * @param string $message
     * @return string
     */
    protected function constructor_clarification(string $message = 'Which value should I use?'): string {
        return json_encode([
            'response_type' => 'clarification',
            'message' => $message,
            'next_step_intent' => '',
            'lang' => 'en',
            'user_lang' => 'en',
            'commands' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
