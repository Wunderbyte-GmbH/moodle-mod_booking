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
 * Shared base for matrix-style LLM skill smoke tests.
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

defined('MOODLE_INTERNAL') || die();

use bookingextension_agent\local\wizard\agent_runtime;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\interpreter;
use bookingextension_agent\local\wizard\orchestrator;
use bookingextension_agent\local\wizard\skill_executability_evaluator;
use bookingextension_agent\local\wizard\skill_registry;
use bookingextension_agent\local\wizard\skill_registry_factory;

require_once(__DIR__ . '/abstract_agent_testcase.php');
require_once(__DIR__ . '/llm_skill_matrix_scenario_provider.php');

/**
 * Common execution and assertion helpers for LLM skill matrix tests.
 */
abstract class abstract_llm_skill_matrix_testcase extends abstract_agent_testcase {
    /**
     * Extend the shared agent test setup with skill-matrix-specific capabilities.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        // Ensure each matrix test run rebuilds contracts from current provider code.
        skill_registry_factory::reset();
        $this->grant_local_entities_capabilities_to_editingteacher();
        $this->grant_optional_capability_to_editingteacher('moodle/site:config');
        $this->grant_optional_capability_to_editingteacher('mod/booking:updatebooking');
        // Pro-level capability not in the editingteacher archetype by default; required by
        // Gate 2 for the rule skills (create/update_rule_from_template).
        $this->grant_optional_capability_to_editingteacher('mod/booking:editbookingrules');
    }

    /**
     * Shared skill matrix for real and future simulated LLM suites.
     *
     * @return array
     */
    public static function skill_matrix_scenarios(): array {
        return llm_skill_matrix_scenario_provider::provide_registered_skill_scenarios();
    }

    /**
     * Execute one matrix scenario and assert the skill completed successfully.
     *
     * @param array $scenario
     * @return void
     */
    protected function assert_llm_skill_scenario_success(array $scenario): void {
        $skillname = (string)($scenario['skill'] ?? '');
        if (!empty($scenario['missing_definition'])) {
            $this->fail('Missing LLM smoke scenario definition for registered skill: ' . $skillname);
        }

        $skipreason = trim((string)($scenario['skip_reason'] ?? ''));
        if ($skipreason !== '') {
            $label = $skillname !== '' ? $skillname : 'unknown-skill';
            $this->markTestSkipped($label . ': ' . $skipreason);
        }

        $this->assertNotSame('', $skillname, 'Scenario must define a skill name.');
        $this->setUser($this->teacher);
        $this->assert_skill_is_executable_or_skip($skillname);

        $prepared = $this->prepare_scenario_runtime($scenario);
        $renderedprompt = $this->render_scenario_template(
            (string)($scenario['prompt'] ?? ''),
            (array)($prepared['replacements'] ?? [])
        );

        $result = $this->chat(
            $renderedprompt,
            (int)$prepared['threadid'],
            $prepared['store'],
            $prepared['runtime']
        );

        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $responsetype = (string)($result['response_type'] ?? '');
            $message = strtolower((string)($result['message'] ?? ''));
            $isprovidertransient = strpos($message, 'ai provider returned an error') !== false;
            $ismissingcommandtransient = strpos($message, 'requires at least one command but none were provided') !== false;
            if ($responsetype !== 'error' || (!$isprovidertransient && !$ismissingcommandtransient)) {
                break;
            }

            // Backoff helps reduce transient provider/rate-limit retries on long suites.
            $backoffmicros = min(5000000, 500000 * $attempt * $attempt);
            usleep($backoffmicros);

            $result = $this->chat(
                $renderedprompt,
                (int)$prepared['threadid'],
                $prepared['store'],
                $prepared['runtime']
            );
        }

        $finalresult = $this->resolve_skill_result_payload($result, $skillname) ?? $result;

        if ((string)$scenario['mode'] === 'mutating') {
            $command = $this->extract_command($result, $skillname);
            $responsetype = (string)($result['response_type'] ?? '');
            $hasqueueitem = trim((string)($result['queueitemid'] ?? '')) !== '';
            $canconfirmwithoutcommand = $responsetype === 'confirmation_request' || $hasqueueitem;

            $this->assertTrue(
                $command !== null || $canconfirmwithoutcommand,
                'Expected confirmation command or queue-backed confirmation for ' . $skillname . '. Response type: '
                    . $responsetype
                    . ' Message: ' . (string)($result['message'] ?? '')
            );

            $confirm = $this->confirm_pending_result(
                $result,
                (int)$prepared['threadid'],
                $prepared['store'],
                false
            );
            $nopendingconfirmation = (string)($confirm['message'] ?? '')
                === 'No pending confirmation is available for this action. Please ask the assistant again.';

            $this->assertTrue(
                (bool)($confirm['success'] ?? false) || $nopendingconfirmation,
                'Confirmation failed for ' . $skillname . ': ' . (string)($confirm['message'] ?? '')
            );

            if ((bool)($confirm['success'] ?? false)) {
                $confirmresult = $this->resolve_skill_result_payload($confirm, $skillname);
                if ($confirmresult !== null) {
                    $this->assertSame(
                        'executed',
                        (string)($confirmresult['status'] ?? ''),
                        'Confirmation payload missing executed status for ' . $skillname
                    );
                    $finalresult = $confirmresult;
                } else {
                    $this->assertNotNull(
                        $command,
                        'Confirmation succeeded but no skill result was returned for ' . $skillname
                    );
                    $executed = $this->execute_command($command);
                    $this->assertSame(
                        'executed',
                        (string)($executed['status'] ?? ''),
                        'Fallback execute_command failed for ' . $skillname . ': ' . (string)($executed['detail'] ?? '')
                    );
                    $finalresult = $executed;
                }
            } else {
                $this->assertNotNull(
                    $command,
                    'No pending confirmation for ' . $skillname . ' without executable command evidence. Response type: '
                        . $responsetype
                        . ' Message: ' . (string)($result['message'] ?? '')
                );
                $executed = $this->execute_command($command);
                $this->assertSame(
                    'executed',
                    (string)($executed['status'] ?? ''),
                    'Fallback execute_command failed for ' . $skillname . ': ' . (string)($executed['detail'] ?? '')
                );
                $finalresult = $executed;
            }
        } else {
            $skillresult = $this->resolve_skill_result_payload($result, $skillname);
            if ($skillresult !== null) {
                $this->assertSame('executed', (string)($skillresult['status'] ?? ''));
                $finalresult = $skillresult;
            } else {
                $allowdirectanswer = (bool)($scenario['allow_direct_answer'] ?? false);
                $allowclarificationresponse = (bool)($scenario['allow_clarification_response'] ?? false);
                $responsetype = (string)($result['response_type'] ?? '');
                $issuecodes = $result['issue_codes'] ?? [];
                $hasrecoverable = is_array($issuecodes)
                    ? in_array('RECOVERABLE_INPUT_ERROR', $issuecodes, true)
                    : strpos((string)$issuecodes, 'RECOVERABLE_INPUT_ERROR') !== false;
                $hastoolcallevidence = $this->first_loop_has_expected_tool_call($result, $skillname);
                // The planner may legitimately close a turn with "sufficient" AFTER the
                // skill already executed (the final reply summarises the observation, the
                // WS payload then carries no result entries). The queue is the durable
                // ground truth for that case — accept a succeeded queue item for the
                // expected skill as execution evidence instead of failing the scenario.
                $hasqueueevidence = $this->thread_has_succeeded_skill_queue_item(
                    $prepared['store'],
                    (int)$prepared['threadid'],
                    $skillname
                );
                if (
                    in_array($responsetype, ['sufficient', 'execution_result'], true)
                    && ($allowdirectanswer || $hastoolcallevidence || $hasqueueevidence)
                ) {
                    $finalresult = $result;
                    $finalresult['status'] = 'executed';
                } else if (
                    $allowclarificationresponse
                    && $responsetype === 'clarification'
                    && $hasrecoverable
                ) {
                    $finalresult = $result;
                } else {
                    $this->assertNotNull(
                        $skillresult,
                        'Expected executed result entry for ' . $skillname . '. Response type: '
                            . $responsetype
                            . ' Message: ' . (string)($result['message'] ?? '')
                    );
                }
            }
        }

        $this->assert_scenario_assertions(
            $scenario,
            (array)$prepared['replacements'],
            $result,
            $finalresult,
            (int)$prepared['threadid']
        );
    }

    /**
     * Best-effort grant for local_entities skills used by this matrix.
     *
     * @return void
     */
    protected function grant_local_entities_capabilities_to_editingteacher(): void {
        $roles = get_archetype_roles('editingteacher');
        if (empty($roles)) {
            return;
        }

        $role = reset($roles);
        $roleid = (int)$role->id;
        $systemcontext = \context_system::instance();

        assign_capability('local/entities:edit', CAP_ALLOW, $roleid, (int)$systemcontext->id, true);
        accesslib_clear_all_caches(true);
        accesslib_reset_role_cache();
    }

    /**
     * Best-effort grant for an optional capability used by selected matrix skills.
     *
     * @param string $capability
     * @return void
     */
    protected function grant_optional_capability_to_editingteacher(string $capability): void {
        if ($capability === '' || !get_capability_info($capability)) {
            return;
        }

        $roles = get_archetype_roles('editingteacher');
        if (empty($roles)) {
            return;
        }

        $role = reset($roles);
        $roleid = (int)$role->id;
        $systemcontext = \context_system::instance();
        role_assign($roleid, (int)$this->teacher->id, (int)$systemcontext->id);
        assign_capability($capability, CAP_ALLOW, $roleid, (int)$systemcontext->id, true);
        accesslib_clear_all_caches(true);
        accesslib_reset_role_cache();
    }

    /**
     * Assert the skill is executable in the test context.
     *
     * @param string $skillname
     * @return void
     */
    protected function assert_skill_is_executable_or_skip(string $skillname): void {
        $registry = skill_registry_factory::get_default();
        $contract = $registry->get_skill_contract($skillname);
        $this->assertNotNull($contract, 'Skill must exist in registry: ' . $skillname);

        foreach ((array)($contract['capabilities'] ?? []) as $capability) {
            $capability = (string)$capability;
            $this->sync_capability_definition_from_component($capability);
            if ($capability !== '' && get_capability_info($capability)) {
                $this->grant_optional_capability_to_editingteacher($capability);
            }
        }

        foreach ((array)($contract['capabilities'] ?? []) as $capability) {
            if (!get_capability_info((string)$capability)) {
                $this->fail(
                    'Skill ' . $skillname . ' requires unknown capability ' . (string)$capability . '.'
                );
            }
        }

        // The contract carries only the governance capability (Gate 1). The skill's NATIVE
        // capabilities (Gate 2, e.g. moodle/course:create) are enforced at the operating context
        // at runtime — grant them too, or a scenario dies in preflight on a permission the matrix
        // teacher was never meant to lack (create_course surfaced this once the model stopped
        // clarifying and actually staged the command).
        $skillinstance = $registry->get_skill($skillname);
        if ($skillinstance !== null && method_exists($skillinstance, 'get_required_native_capabilities')) {
            foreach ((array)$skillinstance->get_required_native_capabilities() as $nativecapability) {
                $nativecapability = (string)$nativecapability;
                $this->sync_capability_definition_from_component($nativecapability);
                if ($nativecapability !== '' && get_capability_info($nativecapability)) {
                    $this->grant_optional_capability_to_editingteacher($nativecapability);
                }
            }
        }

        $contextid = (int)\context_module::instance((int)$this->booking->cmid)->id;
        $evaluator = new skill_executability_evaluator($registry, new authorization_service());
        $evaluation = $evaluator->evaluate_skill($skillname, (int)$this->teacher->id, $contextid);
        if ((string)($evaluation['executable_state'] ?? '') !== 'allow') {
            $this->fail(
                'Skill ' . $skillname . ' is not executable in this test context: '
                    . (string)($evaluation['deny_reason'] ?? 'unknown')
            );
        }
    }

    /**
     * Ensure capability definitions are loaded from the owning component before checks.
     *
     * @param string $capability
     * @return void
     */
    protected function sync_capability_definition_from_component(string $capability): void {
        if ($capability === '' || get_capability_info($capability) || strpos($capability, ':') === false) {
            return;
        }

        $component = str_replace('/', '_', (string)substr($capability, 0, strpos($capability, ':')));
        if ($component === '' || !function_exists('update_capabilities')) {
            return;
        }

        update_capabilities($component);
        accesslib_clear_all_caches(true);
        accesslib_reset_role_cache();
    }

    /**
     * Prepare runtime, thread and placeholder replacements for a scenario.
     *
     * @param array $scenario
     * @return array
     */
    protected function prepare_scenario_runtime(array $scenario): array {
        $prepared = [
            'store' => null,
            'runtime' => null,
            'threadid' => 0,
            'replacements' => $this->default_scenario_replacements(),
        ];

        $setupmethod = (string)($scenario['setup'] ?? '');
        if ($setupmethod !== '') {
            $setupresult = $this->{$setupmethod}();
            if (isset($setupresult['store']) && $setupresult['store'] instanceof conversation_store) {
                $prepared['store'] = $setupresult['store'];
            }
            if (isset($setupresult['runtime']) && $setupresult['runtime'] instanceof agent_runtime) {
                $prepared['runtime'] = $setupresult['runtime'];
            }
            if (!empty($setupresult['threadid'])) {
                $prepared['threadid'] = (int)$setupresult['threadid'];
            }
            if (!empty($setupresult['replacements']) && is_array($setupresult['replacements'])) {
                $prepared['replacements'] = array_merge($prepared['replacements'], $setupresult['replacements']);
            }
        }

        $missingstore = !$prepared['store'] instanceof conversation_store;
        $missingruntime = !$prepared['runtime'] instanceof agent_runtime;
        $missingthread = $prepared['threadid'] <= 0;

        if ($missingstore || $missingruntime || $missingthread) {
            [$store, $runtime, $threadid] = $this->build_runtime();
            $prepared['store'] = $store;
            $prepared['runtime'] = $runtime;
            $prepared['threadid'] = $threadid;
        }

        return $prepared;
    }

    /**
     * Build common dynamic replacements used by prompts.
     *
     * @return array
     */
    protected function default_scenario_replacements(): array {
        return [
            'teacher_id' => (string)$this->teacher->id,
            'teacher_email' => (string)$this->teacher->email,
            'teacher_fullname' => fullname($this->teacher),
            'course_fullname' => (string)$this->course->fullname,
            'search_user_fullname' => fullname($this->teacher),
            'example_query' => 'matrix-example-' . substr(sha1(uniqid('', true)), 0, 8),
            'example_objective' => 'Matrix objective ' . substr(sha1(uniqid('', true)), 0, 6),
            'example_step_one' => 'validate',
            'example_step_two' => 'execute',
            'example_step_three' => 'summarize',
            'child_label' => 'child-1',
            'batch_label' => 'matrix-batch-' . substr(sha1(uniqid('', true)), 0, 8),
            'ticket_id' => 'MX-' . strtoupper(substr(sha1(uniqid('', true)), 0, 8)),
            'entity_name' => 'Matrix Entity ' . substr(sha1(uniqid('', true)), 0, 8),
            'entity_search_query' => 'Matrix Entity',
        ];
    }

    /**
     * Seed a same-thread memory snippet for wizard.recall_memory.
     *
     * @return array
     */
    protected function prepare_recall_memory_scenario(): array {
        $token = 'matrix-memory-' . substr(sha1(uniqid('', true)), 0, 8);
        $contextid = (int)\context_module::instance((int)$this->booking->cmid)->id;
        $store = new conversation_store();
        $thread1 = $store->get_or_create_thread((int)$this->teacher->id, $contextid);
        $thread1id = (int)$thread1->id;

        $store->add_message($thread1id, 'user', 'Please remember the token ' . $token . '.');
        $store->add_message($thread1id, 'assistant', 'I will remember the token ' . $token . '.', [
            'response_type' => 'sufficient',
        ]);

        $thread2 = $store->create_fresh_thread((int)$this->teacher->id, $contextid);
        $thread2id = (int)$thread2->id;

        $registry = skill_registry::make_default();
        $runtime = new agent_runtime(
            $registry,
            new orchestrator($registry, new interpreter($registry), $store),
            $store,
            new authorization_service()
        );

        return [
            'store' => $store,
            'runtime' => $runtime,
            'threadid' => $thread2id,
            'replacements' => [
                'memory_token' => $token,
            ],
        ];
    }

    /**
     * Seed one stored user memory (user_memory table) for wizard.forget / wizard.list_memories.
     *
     * Distinct from prepare_recall_memory_scenario, which seeds past CONVERSATION
     * content — user memories are explicit stored facts, global per user.
     *
     * @return array
     */
    protected function prepare_user_memory_scenario(): array {
        $token = 'matrix-fact-' . substr(sha1(uniqid('', true)), 0, 8);
        (new \bookingextension_agent\local\wizard\services\user_memory_service())->add(
            (int)$this->teacher->id,
            'Always mention the token ' . $token . ' when summarising bookings.'
        );

        return [
            'replacements' => [
                'memory_token' => $token,
            ],
        ];
    }

    /**
     * Grant the native question-bank capability question.generate_questions checks in Gate 2.
     *
     * @return array
     */
    protected function prepare_generate_questions_scenario(): array {
        $this->grant_optional_capability_to_editingteacher('moodle/question:add');

        return [];
    }

    /**
     * Provide deterministic placeholders for entities scenarios.
     *
     * @return array
     */
    protected function prepare_entity_scenario(): array {
        $entityname = 'Matrix Entity ' . substr(sha1(uniqid('', true)), 0, 8);
        $seedresult = $this->exec_command('entities.create_entity', [
            'name' => $entityname,
            'shortname' => $entityname,
            'description' => 'Seed entity for skill matrix smoke tests.',
        ]);

        $store = new conversation_store();
        $registry = skill_registry::make_default();
        $runtime = new agent_runtime(
            $registry,
            new orchestrator($registry, new interpreter($registry), $store),
            $store,
            new authorization_service()
        );
        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            $this->booking_contextid()
        );

        return [
            'store' => $store,
            'runtime' => $runtime,
            'threadid' => (int)$thread->id,
            'seedresult' => $seedresult,
            'replacements' => [
                'entity_name' => $entityname,
                'entity_search_query' => $entityname,
            ],
        ];
    }

    /**
     * Seed one existing booking option and expose its id for update-skill scenarios.
     *
     * @return array
     */
    protected function prepare_update_option_scenario(): array {
        $optionname = 'Matrix Update Target ' . substr(sha1(uniqid('', true)), 0, 8);
        $seedoption = $this->gen->create_option([
            'bookingid' => (int)$this->booking->id,
            'text' => $optionname,
            'maxanswers' => 7,
            'type' => 0,
        ]);
        if (empty($seedoption->id)) {
            throw new \coding_exception('prepare_update_option_scenario failed: seed option was not created.');
        }

        $store = new conversation_store();
        $registry = skill_registry::make_default();
        $runtime = new agent_runtime(
            $registry,
            new orchestrator($registry, new interpreter($registry), $store),
            $store,
            new authorization_service()
        );
        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            $this->booking_contextid()
        );

        return [
            'store' => $store,
            'runtime' => $runtime,
            'threadid' => (int)$thread->id,
            'replacements' => [
                'existing_option_id' => (string)$seedoption->id,
                'existing_option_name' => $optionname,
            ],
        ];
    }

    /**
     * Seed an EMPTY course as scaffold target (the ambient matrix course carries the harness
     * booking activity, which trips the scaffold's not-empty soft-block on every run).
     *
     * @return array
     */
    protected function prepare_empty_course_scenario(): array {
        $fullname = 'Empty Matrix Course ' . substr(sha1(uniqid('', true)), 0, 8);
        $course = $this->getDataGenerator()->create_course(['fullname' => $fullname]);
        $this->getDataGenerator()->enrol_user((int)$this->teacher->id, (int)$course->id, 'editingteacher');

        return [
            'replacements' => [
                'empty_course_fullname' => $fullname,
            ],
        ];
    }

    /**
     * Seed a distinctly named course category so the create-course scenario can name it
     * verbatim and the category resolves uniquely regardless of the site's category count.
     *
     * @return array
     */
    protected function prepare_create_course_scenario(): array {
        $categoryname = 'Matrix Category ' . substr(sha1(uniqid('', true)), 0, 8);
        $this->getDataGenerator()->create_category(['name' => $categoryname]);

        return [
            'replacements' => [
                'matrix_category_name' => $categoryname,
            ],
        ];
    }

    /**
     * Seed one standard course activity (page) and expose its name for update/diagnose scenarios.
     *
     * @return array
     */
    protected function prepare_course_activity_scenario(): array {
        $activityname = 'Matrix Activity ' . substr(sha1(uniqid('', true)), 0, 8);
        $this->getDataGenerator()->create_module('page', [
            'course' => (int)$this->course->id,
            'name' => $activityname,
        ]);

        return [
            'replacements' => [
                'existing_activity_name' => $activityname,
            ],
        ];
    }

    /**
     * Seed one quiz activity and expose its name for the update-quiz scenario.
     *
     * @return array
     */
    protected function prepare_course_quiz_scenario(): array {
        $quizname = 'Matrix Quiz ' . substr(sha1(uniqid('', true)), 0, 8);
        $this->getDataGenerator()->create_module('quiz', [
            'course' => (int)$this->course->id,
            'name' => $quizname,
        ]);

        return [
            'replacements' => [
                'existing_quiz_name' => $quizname,
            ],
        ];
    }

    /**
     * Assert scenarios depending on booking rules service are executable.
     *
     * @return array
     */
    protected function prepare_booking_rules_service_scenario(): array {
        $candidates = [
            '\\mod_booking\\local\\wizard\\booking\\support\\booking_rules_agent_service',
            '\\bookingextension_agent\\local\\wizard\\booking\\support\\booking_rules_agent_service',
        ];

        foreach ($candidates as $classname) {
            if (!class_exists($classname)) {
                continue;
            }
            try {
                $service = new $classname();
                if (is_object($service)) {
                    return [];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        $this->fail('Booking rules service is unavailable in this installation.');
    }

    /**
     * Seed a deterministic booking rule for update-rule scenarios.
     *
     * @return array
     */
    protected function prepare_booking_rule_update_scenario(): array {
        $serviceclass = '';
        $candidates = [
            '\\mod_booking\\local\\wizard\\booking\\support\\booking_rules_agent_service',
            '\\bookingextension_agent\\local\\wizard\\booking\\support\\booking_rules_agent_service',
        ];
        foreach ($candidates as $classname) {
            if (class_exists($classname)) {
                $serviceclass = $classname;
                break;
            }
        }

        if ($serviceclass === '') {
            $this->fail('Booking rules service is unavailable in this installation.');
        }

        $service = new $serviceclass();
        $contextid = $service->get_module_contextid((int)$this->booking->cmid);
        $templates = $service->list_templates();
        if (empty($templates)) {
            $this->fail('No rule templates available for update-rule scenario seeding.');
        }

        $template = (array)reset($templates);
        $seedrulename = 'Seeded booking rule ' . substr(sha1(uniqid('', true)), 0, 8);
        $created = $service->create_rule_from_template(
            $contextid,
            (int)($template['templateid'] ?? 0),
            ['rulename' => $seedrulename]
        );
        if ((string)($created['status'] ?? '') !== 'ok') {
            $this->fail(
                'Could not seed booking rule for update-rule scenario: '
                    . (string)($created['message'] ?? 'unknown error')
            );
        }

        $store = new conversation_store();
        $registry = skill_registry::make_default();
        $runtime = new agent_runtime(
            $registry,
            new orchestrator($registry, new interpreter($registry), $store),
            $store,
            new authorization_service()
        );
        $thread = $store->get_or_create_thread(
            (int)$this->teacher->id,
            $this->booking_contextid()
        );

        return [
            'store' => $store,
            'runtime' => $runtime,
            'threadid' => (int)$thread->id,
            'replacements' => [
                'existing_rule_name' => $seedrulename,
            ],
        ];
    }

    /**
     * Evaluate the scenario-specific assertion contract.
     *
     * @param array $scenario
     * @param array $replacements
     * @param array $chatresult
     * @param array $finalresult
     * @param int $threadid
     * @return void
     */
    protected function assert_scenario_assertions(
        array $scenario,
        array $replacements,
        array $chatresult,
        array $finalresult,
        int $threadid
    ): void {
        foreach ((array)($scenario['assertions'] ?? []) as $assertion) {
            if (!is_array($assertion)) {
                continue;
            }

            $target = (string)($assertion['target'] ?? 'final');
            $payload = $target === 'chat' ? $chatresult : $finalresult;
            $type = (string)($assertion['type'] ?? '');
            $value = $this->render_assertion_value((string)($assertion['value'] ?? ''), $replacements);
            $field = (string)($assertion['field'] ?? '');

            switch ($type) {
                case 'field_equals':
                    $actual = $this->payload_field_value($payload, $field);
                    $this->assertSame(
                        $value,
                        $this->stringify_assertion_value($actual),
                        'Scenario assertion failed for field_equals on ' . $field . ' in ' . (string)($scenario['skill'] ?? '')
                    );
                    break;

                case 'field_contains':
                    $actual = $this->stringify_assertion_value($this->payload_field_value($payload, $field));
                    $this->assertStringContainsString(
                        $value,
                        $actual,
                        'Scenario assertion failed for field_contains on ' . $field . ' in ' . (string)($scenario['skill'] ?? '')
                    );
                    break;

                case 'field_count_gte':
                    $this->assertGreaterThanOrEqual(
                        (int)$value,
                        $this->payload_field_count($payload, $field),
                        'Scenario assertion failed for field_count_gte on ' . $field . ' in ' . (string)($scenario['skill'] ?? '')
                    );
                    break;

                case 'field_count_equals':
                    $this->assertSame(
                        (int)$value,
                        $this->payload_field_count($payload, $field),
                        'Scenario assertion failed for field_count_equals on ' . $field . ' in ' .
                        (string)($scenario['skill'] ?? '')
                    );
                    break;

                case 'step_count_gte':
                    $this->assertGreaterThanOrEqual(
                        (int)$value,
                        $this->payload_step_count($payload),
                        'Scenario assertion failed for step_count_gte in ' . (string)($scenario['skill'] ?? '')
                    );
                    break;

                case 'step_count_equals':
                    $this->assertSame(
                        (int)$value,
                        $this->payload_step_count($payload),
                        'Scenario assertion failed for step_count_equals in ' . (string)($scenario['skill'] ?? '')
                    );
                    break;

                case 'response_type_equals':
                    $this->assertSame(
                        $value,
                        (string)($payload['response_type'] ?? ''),
                        'Scenario assertion failed for response_type_equals in ' . (string)($scenario['skill'] ?? '')
                    );
                    break;

                case 'debug_source_contains':
                    $this->assertTrue(
                        $this->thread_has_debug_source_fragment($threadid, $value),
                        'Scenario assertion failed for debug_source_contains in '
                            . (string)($scenario['skill'] ?? '')
                            . '. Latest source: '
                            . $this->get_latest_debug_source($threadid)
                    );
                    break;

                case 'result_contains':
                    $this->assertStringContainsString(
                        $value,
                        $this->payload_text($payload),
                        'Scenario assertion failed for result_contains in ' . (string)($scenario['skill'] ?? '')
                    );
                    break;

                default:
                    $this->fail('Unknown scenario assertion type: ' . $type . ' for ' . (string)($scenario['skill'] ?? ''));
            }
        }
    }

    /**
     * Resolve a dotted field path from a payload.
     *
     * @param array $payload
     * @param string $field
     * @return mixed
     */
    protected function payload_field_value(array $payload, string $field) {
        if ($field === '') {
            return null;
        }

        $value = $payload;
        foreach (explode('.', $field) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
                continue;
            }

            if (is_object($value) && isset($value->{$segment})) {
                $value = $value->{$segment};
                continue;
            }

            return null;
        }

        return $value;
    }

    /**
     * Count a resolved payload field when possible.
     *
     * @param array $payload
     * @param string $field
     * @return int
     */
    protected function payload_field_count(array $payload, string $field): int {
        $value = $this->payload_field_value($payload, $field);
        if (is_array($value) || $value instanceof \Countable) {
            return count($value);
        }

        if ($value === null || $value === '') {
            return 0;
        }

        return 1;
    }

    /**
     * Determine the best available step count from a response payload.
     *
     * @param array $payload
     * @return int
     */
    protected function payload_step_count(array $payload): int {
        if (isset($payload['loop_step']) && is_numeric($payload['loop_step'])) {
            return (int)$payload['loop_step'];
        }

        $loopresults = (array)($payload['loop_results'] ?? []);
        if (!empty($loopresults)) {
            return count($loopresults);
        }

        return 0;
    }

    /**
     * Return the latest debug source for the given thread.
     *
     * @param int $threadid
     * @return string
     */
    protected function get_latest_debug_source(int $threadid): string {
        global $DB;

        $record = $DB->get_record_sql(
            'SELECT source FROM {bx_agent_ai_llm_debug} WHERE threadid = ? ORDER BY id DESC',
            [$threadid],
            IGNORE_MULTIPLE
        );

        if (!$record) {
            return '';
        }

        return (string)($record->source ?? '');
    }

    /**
     * True when any debug source row in a thread contains the expected fragment.
     *
     * @param int $threadid
     * @param string $fragment
     * @return bool
     */
    protected function thread_has_debug_source_fragment(int $threadid, string $fragment): bool {
        global $DB;

        $fragment = trim($fragment);
        if ($fragment === '') {
            return false;
        }

        $records = $DB->get_records_sql(
            'SELECT source FROM {bx_agent_ai_llm_debug} WHERE threadid = ? ORDER BY id DESC',
            [$threadid]
        );

        foreach ($records as $record) {
            $source = (string)($record->source ?? '');
            if (strpos($source, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render a placeholder-bearing assertion value.
     *
     * @param string $value
     * @param array $replacements
     * @return string
     */
    protected function render_assertion_value(string $value, array $replacements): string {
        return $this->render_scenario_template($value, $replacements);
    }

    /**
     * Convert a payload value to a stable string for assertions.
     *
     * @param mixed $value
     * @return string
     */
    protected function stringify_assertion_value($value): string {
        if (is_scalar($value) || $value === null) {
            return (string)$value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded !== false ? $encoded : '[complex]';
    }

    /**
     * Normalize a response payload to the concrete skill result when it is wrapped in an execution response.
     *
     * @param array $payload
     * @param string $skillname
     * @return array|null
     */
    protected function resolve_skill_result_payload(array $payload, string $skillname): ?array {
        $direct = $this->extract_skill_result($payload, $skillname);
        if (is_array($direct)) {
            return $direct;
        }

        foreach ($this->skill_result_candidate_names($skillname) as $candidatename) {
            if ($candidatename === $skillname) {
                continue;
            }
            $direct = $this->extract_skill_result($payload, $candidatename);
            if (is_array($direct)) {
                return $direct;
            }
        }

        // Some real-LLM responses carry executed results inside loop_results[].results[].
        foreach ((array)($payload['loop_results'] ?? []) as $loopentry) {
            if (!is_array($loopentry)) {
                continue;
            }
            foreach ((array)($loopentry['results'] ?? []) as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $entryskill = (string)($entry['skill'] ?? '');
                foreach ($this->skill_result_candidate_names($skillname) as $candidatename) {
                    if ($candidatename !== '' && $entryskill === $candidatename) {
                        return $entry;
                    }
                }

                if ($skillname === '' && !empty($entry)) {
                    return $entry;
                }
            }
        }

        $resultsjson = (string)($payload['resultsjson'] ?? '');
        if ($resultsjson === '') {
            return null;
        }

        $decoded = json_decode($resultsjson, true);
        if (!is_array($decoded)) {
            return null;
        }

        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ($skillname !== '' && (string)($entry['skill'] ?? '') === $skillname) {
                return $entry;
            }

            if ($skillname !== '') {
                foreach ($this->skill_result_candidate_names($skillname) as $candidatename) {
                    if ($candidatename !== '' && (string)($entry['skill'] ?? '') === $candidatename) {
                        return $entry;
                    }
                }
            }

            if ($skillname === '' && !empty($entry)) {
                return $entry;
            }
        }

        foreach ($decoded as $entry) {
            if (is_array($entry) && !empty($entry)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Render {{placeholder}} tokens inside scenario prompts.
     *
     * @param string $template
     * @param array $replacements
     * @return string
     */
    protected function render_scenario_template(string $template, array $replacements): string {
        $rendered = $template;
        foreach ($replacements as $key => $value) {
            $rendered = str_replace('{{' . $key . '}}', (string)$value, $rendered);
        }
        return $rendered;
    }

    /**
     * Check the thread queue for a succeeded item of the expected skill.
     *
     * Durable execution evidence for turns whose final WS payload no longer
     * carries result entries (e.g. a closing "sufficient" reply).
     *
     * @param conversation_store $store
     * @param int $threadid
     * @param string $skillname
     * @return bool
     */
    protected function thread_has_succeeded_skill_queue_item(
        conversation_store $store,
        int $threadid,
        string $skillname
    ): bool {
        if ($threadid <= 0) {
            return false;
        }

        $candidates = $this->skill_result_candidate_names($skillname);
        $queue = new \bookingextension_agent\local\wizard\queue\queue_manager($store);
        foreach ($queue->get_queue_items($threadid) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemskill = trim((string)($item['skill'] ?? ''));
            $status = (string)($item['status'] ?? '');
            $succeeded = \bookingextension_agent\local\wizard\services\queue_status_policy::is_succeeded_status($status);
            if ($itemskill === '' || !$succeeded) {
                continue;
            }
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && $itemskill === $candidate) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check whether the first loop turn contains a tool call for the expected skill.
     *
     * @param array $result
     * @param string $skillname
     * @return bool
     */
    protected function first_loop_has_expected_tool_call(array $result, string $skillname): bool {
        $loopresults = (array)($result['loop_results'] ?? []);
        if (empty($loopresults) || !is_array($loopresults[0] ?? null)) {
            return false;
        }

        $toolcalls = (array)($loopresults[0]['tool_calls'] ?? []);
        if (empty($toolcalls)) {
            return false;
        }

        $candidates = $this->skill_result_candidate_names($skillname);
        foreach ($toolcalls as $toolcall) {
            if (!is_array($toolcall)) {
                continue;
            }

            $names = [];
            foreach (['skill', 'name', 'tool', 'tool_name', 'function_name'] as $field) {
                if (isset($toolcall[$field]) && is_string($toolcall[$field])) {
                    $names[] = (string)$toolcall[$field];
                }
            }

            if (isset($toolcall['function']) && is_array($toolcall['function'])) {
                if (isset($toolcall['function']['name']) && is_string($toolcall['function']['name'])) {
                    $names[] = (string)$toolcall['function']['name'];
                }
            }

            foreach ($names as $name) {
                foreach ($candidates as $candidate) {
                    if ($name === $candidate || str_contains($name, $candidate)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Find the first result entry for a skill in the final payload.
     *
     * @param array $payload
     * @param string $skillname
     * @return array|null
     */
    protected function find_skill_result_entry(array $payload, string $skillname): ?array {
        foreach ((array)($payload['results'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ((string)($entry['skill'] ?? '') === $skillname) {
                return $entry;
            }

            foreach ($this->skill_result_candidate_names($skillname) as $candidatename) {
                if ($candidatename !== '' && (string)($entry['skill'] ?? '') === $candidatename) {
                    return $entry;
                }
            }
        }

        foreach ((array)($payload['loop_results'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if ((string)($entry['skill'] ?? '') === $skillname) {
                return $entry;
            }
            foreach ($this->skill_result_candidate_names($skillname) as $candidatename) {
                if ($candidatename !== '' && (string)($entry['skill'] ?? '') === $candidatename) {
                    return $entry;
                }
            }

            foreach ((array)($entry['results'] ?? []) as $nested) {
                if (!is_array($nested)) {
                    continue;
                }

                if ((string)($nested['skill'] ?? '') === $skillname) {
                    return $nested;
                }
                foreach ($this->skill_result_candidate_names($skillname) as $candidatename) {
                    if ($candidatename !== '' && (string)($nested['skill'] ?? '') === $candidatename) {
                        return $nested;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Return skill-name candidates for legacy alias/canonical mappings.
     *
     * @param string $skillname
     * @return string[]
     */
    protected function skill_result_candidate_names(string $skillname): array {
        $candidates = [$skillname];

        $aliasmap = [
            'mod_booking.create_selflearning_option' => [
                'mod_booking.create_selflearning_option',
                'mod_booking.create_option',
            ],
            'mod_booking.create_slotbooking_option' => [
                'mod_booking.create_slotbooking_option',
                'mod_booking.create_option',
            ],
        ];

        if (isset($aliasmap[$skillname])) {
            $candidates = array_merge($candidates, $aliasmap[$skillname]);
        }

        return array_values(array_unique(array_filter($candidates, static fn($value): bool => is_string($value) && $value !== '')));
    }
}
