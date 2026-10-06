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
 * Centralized LLM invocation wrapper with mandatory debug logging.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\llm;

use core\context;
use core\di;
use core_ai\aiactions\explain_text;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use core_ai\manager as ai_manager;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\llm_debug_logger;
use bookingextension_agent\local\wizard\wb_action_names;

/**
 * Provides one entry point for all model calls in the booking agent.
 */
class llm_call_service {
    /** Wunderbyte planner action class name. */
    private const WB_ACTION_PLANNER_DECIDE = wb_action_names::PLANNER_DECIDE;

    /** Wunderbyte final reply action class name. */
    private const WB_ACTION_GENERATE_AGENT_REPLY = wb_action_names::GENERATE_AGENT_REPLY;

    /** Wunderbyte embedding action class name. */
    private const WB_ACTION_GENERATE_EMBEDDINGS = wb_action_names::GENERATE_EMBEDDINGS;

    /** @var conversation_store */
    private conversation_store $store;

    /** @var callable|null TEST-ONLY scripted responder for text actions; null (real core_ai path) in production. */
    private static $testresponder = null;

    /** @var array|null TEST-ONLY fixed embedding vector for the discovery phase; null in production. */
    private static ?array $testembedding = null;

    /**
     * Constructor.
     *
     * @param conversation_store $store
     */
    public function __construct(conversation_store $store) {
        $this->store = $store;
    }

    /**
     * Install a scripted responder so run_loop (selector/constructor/synchronizer) runs
     * deterministically without a live LLM.
     *
     * TEST-ONLY. Production never calls this, so the static stays null and invoke_for_context()
     * always takes the real core_ai path. Installing it outside a test run is a coding error.
     * The responder receives ($actionclass, $prompt, $source) and returns either the raw generated content
     * the phase would otherwise receive from the provider, or a structured provider result
     * (keys content, success, errorcode, errormessage, finishreason) to script provider failures
     * and truncated output.
     *
     * @param callable|null $responder
     * @return void
     */
    public static function set_test_responder(?callable $responder): void {
        if ($responder !== null && !defined('PHPUNIT_TEST') && !defined('BEHAT_SITE_RUNNING')) {
            throw new \coding_exception('llm_call_service test responder may only be installed in tests.');
        }
        self::$testresponder = $responder;
    }

    /**
     * Install a fixed embedding vector for the discovery phase in tests (see set_test_responder).
     *
     * @param array|null $vector
     * @return void
     */
    public static function set_test_embedding(?array $vector): void {
        if ($vector !== null && !defined('PHPUNIT_TEST') && !defined('BEHAT_SITE_RUNNING')) {
            throw new \coding_exception('llm_call_service test embedding may only be installed in tests.');
        }
        self::$testembedding = $vector;
    }

    /**
     * Invoke a core_ai action class by context id (context-level-agnostic).
     *
     * Resolves the context directly from a context id, so the agent can run at
     * course/system level, not only inside a module. The debug log records the
     * real context id.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param string $source
     * @param string $prompt
     * @param string $actionclass
     * @return array{success:bool,rawcontent:string,errormessage:string,errorcode:int,errorname:string,finishreason:string}
     */
    public function invoke_for_context(
        int $threadid,
        int $contextid,
        int $userid,
        string $source,
        string $prompt,
        string $actionclass = generate_text::class
    ): array {
        // TEST-ONLY deterministic path: return scripted content instead of calling the provider,
        // so run_loop (selector/constructor/synchronizer) can be driven without a live LLM.
        if (self::$testresponder !== null) {
            $scripted = (self::$testresponder)($actionclass, $prompt, $source);
            $scripted = is_array($scripted) ? $scripted : ['content' => (string)$scripted];
            $scriptedcontent = (string)($scripted['content'] ?? '');
            $scriptedsuccess = (bool)($scripted['success'] ?? true);
            $scriptederror = (string)($scripted['errormessage'] ?? '');
            llm_debug_logger::log_exchange(
                $this->store,
                $threadid,
                (int)$contextid,
                $userid,
                $source,
                $prompt,
                $scriptedcontent,
                $scriptedsuccess,
                $scriptederror
            );
            return [
                'success' => $scriptedsuccess,
                'rawcontent' => $scriptedcontent,
                'errormessage' => $scriptederror,
                'errorcode' => (int)($scripted['errorcode'] ?? 0),
                'errorname' => '',
                'finishreason' => (string)($scripted['finishreason'] ?? 'stop'),
            ];
        }

        $rawcontent = '';
        $errormessage = '';
        $errorcode = 0;
        $errorname = '';
        $finishreason = '';
        $success = false;

        try {
            $context = context::instance_by_id($contextid, MUST_EXIST);
            $manager = di::get(ai_manager::class);

            $action = $this->build_prompt_action($actionclass, (int)$context->id, $userid, $prompt);

            $response = $manager->process_action($action);
            $responsedata = (array)$response->get_response_data();
            $rawcontent = (string)($responsedata['generatedcontent'] ?? '');
            // Structural completion signal of the provider (OpenAI-compatible enum, e.g. 'stop',
            // 'length'); never derived from the generated text.
            $finishreason = (string)($responsedata['finishreason'] ?? '');
            $success = (bool)$response->get_success();
            $errormessage = (string)($response->get_errormessage() ?? '');
            $errorcode = (int)$response->get_errorcode();
            // Moodle 4.5/5.0 core_ai responses have no get_error(); it exists from 5.1 on.
            $errorname = method_exists($response, 'get_error') ? (string)$response->get_error() : '';
        } catch (\Throwable $e) {
            $success = false;
            $errormessage = $e->getMessage();
            $errorcode = (int)$e->getCode();
            $errorname = '';
        }

        // Raw exchange persisted ONLY when aidebugmode is on (audit 15-F01): log_exchange self-gates
        // on llm_debug_logger::is_enabled(), so no prompts/responses are stored in normal operation;
        // when debug mode is on, cleanup_old_llm_debug_task additionally prunes rows past the
        // llm_debug_retention_days TTL.
        llm_debug_logger::log_exchange(
            $this->store,
            $threadid,
            (int)$contextid,
            $userid,
            $source,
            $prompt,
            $rawcontent,
            $success,
            $errormessage
        );

        return [
            'success' => $success,
            'rawcontent' => $rawcontent,
            'errormessage' => $errormessage,
            'errorcode' => $errorcode,
            'errorname' => $errorname,
            'finishreason' => $finishreason,
        ];
    }

    /**
     * Invoke a phase call and repeat it once when the provider cut the output off.
     *
     * A reasoning runaway of the upstream model ends at the output-token cap: the call succeeds
     * with finish_reason 'length' and the partial reasoning as content, which is not a usable
     * answer. Runaways are stochastic, so the SAME call is repeated once (no retry observation —
     * there is nothing to repair). The decision is taken from the structural finish reason only.
     * If the repeated call is cut off again, 'truncated' is true and the caller must discard the
     * content instead of parsing, storing or relaying it.
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param string $source
     * @param string $prompt
     * @param string $actionclass
     * @return array The invoke_for_context() result plus 'truncated' (bool) and 'truncationretries' (int).
     */
    public function invoke_for_context_retrying_truncation(
        int $threadid,
        int $contextid,
        int $userid,
        string $source,
        string $prompt,
        string $actionclass = generate_text::class
    ): array {
        $call = $this->invoke_for_context($threadid, $contextid, $userid, $source, $prompt, $actionclass);
        $retries = 0;
        if (self::is_truncated_call($call)) {
            $retries = 1;
            // Same source on purpose: the debug log shows the retry as a second row of the same call
            // site (the source column is length-limited).
            $call = $this->invoke_for_context($threadid, $contextid, $userid, $source, $prompt, $actionclass);
        }
        $call['truncated'] = self::is_truncated_call($call);
        $call['truncationretries'] = $retries;
        return $call;
    }

    /**
     * Whether a provider call succeeded but was cut off at the output-token limit.
     *
     * @param array $call
     * @return bool
     */
    public static function is_truncated_call(array $call): bool {
        return !empty($call['success']) && (string)($call['finishreason'] ?? '') === 'length';
    }

    /**
     * Invoke Wunderbyte embeddings action by context id (context-level-agnostic).
     *
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @param string $source
     * @param string $inputtext
     * @param int|null $dimensions
     * @return array{success:bool,embedding:array<int,float|int>,model:string,dimensions:int,errormessage:string,errorcode:int,errorname:string}
     */
    public function invoke_embeddings_for_context(
        int $threadid,
        int $contextid,
        int $userid,
        string $source,
        string $inputtext,
        ?int $dimensions = null
    ): array {
        // TEST-ONLY deterministic path: a fixed embedding so the discovery phase runs without a
        // live provider (the selector output is scripted separately, so the exact vector is inert).
        if (self::$testembedding !== null) {
            return [
                'success' => true,
                'embedding' => self::$testembedding,
                'model' => 'test-embedding',
                'dimensions' => count(self::$testembedding),
                'errormessage' => '',
                'errorcode' => 0,
                'errorname' => '',
            ];
        }

        $embedding = [];
        $model = '';
        $useddimensions = 0;
        $errormessage = '';
        $errorcode = 0;
        $errorname = '';
        $success = false;

        try {
            if (!class_exists(self::WB_ACTION_GENERATE_EMBEDDINGS)) {
                throw new \moodle_exception('wunderbyte embeddings action class is missing.');
            }

            $context = context::instance_by_id($contextid, MUST_EXIST);
            $manager = di::get(ai_manager::class);

            $actionclass = self::WB_ACTION_GENERATE_EMBEDDINGS;
            $action = new $actionclass(
                contextid: (int)$context->id,
                userid: $userid,
                inputtext: $inputtext,
                dimensions: $dimensions,
            );

            $response = $manager->process_action($action);
            $responsedata = (array)$response->get_response_data();

            $embedding = (array)($responsedata['embedding'] ?? []);
            $model = (string)($responsedata['model'] ?? '');
            $useddimensions = (int)($responsedata['dimensions'] ?? count($embedding));
            $success = (bool)$response->get_success() && !empty($embedding);
            $errormessage = (string)($response->get_errormessage() ?? '');
            $errorcode = (int)$response->get_errorcode();
            // Moodle 4.5/5.0 core_ai responses have no get_error(); it exists from 5.1 on.
            $errorname = method_exists($response, 'get_error') ? (string)$response->get_error() : '';
        } catch (\Throwable $e) {
            $success = false;
            $errormessage = $e->getMessage();
            $errorcode = (int)$e->getCode();
            $errorname = '';
        }

        // Raw exchange persisted ONLY when aidebugmode is on (audit 15-F01): log_exchange self-gates
        // on llm_debug_logger::is_enabled(), so nothing is stored in normal operation.
        llm_debug_logger::log_exchange(
            $this->store,
            $threadid,
            0,
            $userid,
            $source,
            $inputtext,
            $success ? '[embedding:' . count($embedding) . ']' : '',
            $success,
            $errormessage
        );

        return [
            'success' => $success,
            'embedding' => $embedding,
            'model' => $model,
            'dimensions' => $useddimensions,
            'errormessage' => $errormessage,
            'errorcode' => $errorcode,
            'errorname' => $errorname,
        ];
    }

    /**
     * Build the prompt-based AI action object from the requested action class.
     *
     * @param string $actionclass
     * @param int $contextid
     * @param int $userid
     * @param string $prompt
     * @return object
     */
    private function build_prompt_action(string $actionclass, int $contextid, int $userid, string $prompt): object {
        if ($actionclass === summarise_text::class) {
            return new summarise_text(
                contextid: $contextid,
                userid: $userid,
                prompttext: $prompt,
            );
        }

        if ($actionclass === explain_text::class) {
            return new explain_text(
                contextid: $contextid,
                userid: $userid,
                prompttext: $prompt,
            );
        }

        $wunderbyteactionclass = $this->resolve_wunderbyte_prompt_action_class($actionclass);
        if ($wunderbyteactionclass !== '') {
            return new $wunderbyteactionclass(
                contextid: $contextid,
                userid: $userid,
                prompttext: $prompt,
            );
        }

        return new generate_text(
            contextid: $contextid,
            userid: $userid,
            prompttext: $prompt,
        );
    }

    /**
     * Resolve supported Wunderbyte prompt action classes when available.
     *
     * @param string $actionclass
     * @return string
     */
    private function resolve_wunderbyte_prompt_action_class(string $actionclass): string {
        $normalizedactionclass = ltrim($actionclass, '\\');
        $supported = [
            self::WB_ACTION_GENERATE_AGENT_REPLY,
            self::WB_ACTION_PLANNER_DECIDE,
            wb_action_names::PLANNER_CONSTRUCT,
        ];
        $normalizedsupported = array_map(static fn(string $fqcn): string => ltrim($fqcn, '\\'), $supported);
        if (!in_array($normalizedactionclass, $normalizedsupported, true)) {
            return '';
        }

        if (!class_exists($normalizedactionclass)) {
            return '';
        }

        return $normalizedactionclass;
    }
}
