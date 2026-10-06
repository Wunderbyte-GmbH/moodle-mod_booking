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
 * Preflight gate for low-confidence anonymizer tokens in person parameters (#2226 D3).
 *
 * @package    bookingextension_agent
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent;

use advanced_testcase;
use bookingextension_agent\local\wizard\conversation_store;
use bookingextension_agent\local\wizard\course\skills\enrol_user_skill;
use bookingextension_agent\local\wizard\dto\preflight_result_v2;
use bookingextension_agent\local\wizard\dto\skill_prompt_contract;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\privacy_anonymizer;
use bookingextension_agent\local\wizard\services\execution_observation_ledger;
use bookingextension_agent\local\wizard\services\preflight_pipeline;
use bookingextension_agent\local\wizard\skill_registry;
use context_course;

/**
 * Rules from ticket #2226 (refined 2026-08-23):
 *
 * - R3: a person-centric READ-ONLY skill whose only person evidence is a
 *   low-confidence (single-word) token, with no person context in the thread,
 *   must yield ANON_PERSON_REFERENCE_VALIDATION as needs_clarification — these
 *   skills execute without a confirmation preview, so the gate is the net.
 * - R1: explicit person mutations (enrol/book) never gate on person parameters.
 * - R2: a token in a NON-person slot passes through (de-anonymization restores
 *   the word); only an unresolvable target enriches the EXISTING clarification
 *   with the concrete word.
 * - A stored user decision ("person" or "word") ends the gate for that word.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \bookingextension_agent\local\wizard\services\preflight_pipeline
 */
final class preflight_anon_person_gate_test extends advanced_testcase {
    /** @var string Issue code of the gate (matches the VALIDATION taxonomy rule). */
    private const ISSUE = 'ANON_PERSON_REFERENCE_VALIDATION';

    /**
     * Skip when mod_booking is not installed (generated local_wizard plugin).
     */
    protected function setUp(): void {
        \bookingextension_agent\local\wizard\testing\mod_booking_dependency::require_installed();
        parent::setUp();
    }

    /**
     * Create collision user, course-scoped thread and a low-confidence token.
     *
     * @return array{store: conversation_store, anonymizer: privacy_anonymizer, threadid: int,
     *     contextid: int, userid: int, token: string}
     */
    private function prepare(): array {
        global $USER;
        $this->setAdminUser();
        $this->getDataGenerator()->create_user([
            'firstname' => 'Goduuara',
            'lastname' => 'Herbst',
            'email' => 'goduuara.herbst@example.com',
        ]);
        set_config('aiprivacymode', 'strict', 'bookingextension_agent');

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Ambient Course']);
        $contextid = (int)context_course::instance($course->id)->id;

        $store = new conversation_store();
        $threadid = (int)$store->get_or_create_thread((int)$USER->id, $contextid)->id;
        $anonymizer = new privacy_anonymizer($store);
        $result = $anonymizer->precheck_user_message($threadid, 'Was wird im Herbst angeboten?');
        preg_match('/ANON_USER_\d+_[a-z]+/', (string)$result['sanitizedmessage'], $m);

        return [
            'store' => $store,
            'anonymizer' => $anonymizer,
            'threadid' => $threadid,
            'contextid' => $contextid,
            'userid' => (int)$USER->id,
            'token' => (string)($m[0] ?? ''),
        ];
    }

    /**
     * Run the pipeline for one command.
     *
     * @param conversation_store $store
     * @param array $command
     * @param int $threadid
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    private function run_pipeline(
        conversation_store $store,
        array $command,
        int $threadid,
        int $contextid,
        int $userid
    ): array {
        $command['_structural_validated'] = true;
        $pipeline = new preflight_pipeline($this->build_registry(), $store);
        return $pipeline->run([$command], $threadid, $contextid, $userid);
    }

    /**
     * Collect issue codes of a pipeline result.
     *
     * @param array $result
     * @return string[]
     */
    private function issue_codes(array $result): array {
        return array_values(array_map('strval', (array)($result['issue_codes'] ?? [])));
    }

    /**
     * Find the first issue with the given code.
     *
     * @param array $result
     * @param string $code
     * @return array|null
     */
    private function find_issue(array $result, string $code): ?array {
        foreach ((array)($result['issues'] ?? []) as $issue) {
            if (is_array($issue) && (string)($issue['code'] ?? '') === $code) {
                return $issue;
            }
        }
        return null;
    }

    /**
     * R3: person-centric read-only skill + low-confidence token + no person context => clarification.
     */
    public function test_r3_gate_fires_for_person_centric_readonly_skill(): void {
        $this->resetAfterTest();
        $ctx = $this->prepare();
        $this->assertNotSame('', $ctx['token'], 'Precondition: a low-confidence token was minted.');

        $result = $this->run_pipeline(
            $ctx['store'],
            ['skill' => 'demo.persondiag', 'input' => ['userquery' => $ctx['token']]],
            $ctx['threadid'],
            $ctx['contextid'],
            $ctx['userid']
        );

        $this->assertContains(self::ISSUE, $this->issue_codes($result));
        $issue = $this->find_issue($result, self::ISSUE);
        $this->assertIsArray($issue, 'The gate must surface a structured issue.');
        $this->assertSame('needs_clarification', (string)($issue['severity'] ?? ''));
        // Spec reversed by the HARD RULE of 2026-09-11: the clarification is LLM input
        // (synchronizer, stored history), so it carries the TOKEN; the display resolves it
        // and only the UI-only decision chips name the word.
        $message = (string)($issue['message'] ?? '');
        $this->assertStringContainsString($ctx['token'], $message, 'The clarification carries the token.');
        $this->assertStringNotContainsString('Herbst', $message, 'No masked value may reach an LLM in clear text.');
        $this->assertSame('Herbst', (string)($issue['preview']['payload']['word'] ?? ''), 'The chips name the word.');
    }

    /**
     * The gate fires for the REAL diagnose_booking_issue skill when its person field
     * carries a masked low-confidence token.
     */
    public function test_r3_gate_fires_for_real_diagnose_booking_issue(): void {
        $this->resetAfterTest();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        $ctx = $this->prepare();
        $this->assertNotSame('', $ctx['token'], 'Precondition: a low-confidence token was minted.');

        $result = $this->run_real_skill_pipeline(
            $ctx,
            'mod_booking.diagnose_booking_issue',
            static fn(): skill_interface => new \mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill(),
            ['userquery' => $ctx['token'], 'optionquery' => 'Irrelevant']
        );

        $this->assertContains(self::ISSUE, $this->issue_codes($result));
    }

    /**
     * A masked word in the NON-person optionquery of the real diagnose_waitinglist skill is
     * restored to the original word before resolution (R2) — person-based option resolution
     * from the token is impossible.
     */
    public function test_masked_option_word_is_restored_for_waitinglist(): void {
        $this->resetAfterTest();
        \mod_booking\local\wizard\engine_component::ensure_engine_aliases();
        $ctx = $this->prepare();
        $this->assertNotSame('', $ctx['token'], 'Precondition: a low-confidence token was minted.');

        $result = $this->run_real_skill_pipeline(
            $ctx,
            'mod_booking.diagnose_waitinglist',
            static fn(): skill_interface => new \mod_booking\local\wizard\options\skills\diagnose_waitinglist_skill(),
            ['optionquery' => $ctx['token']]
        );

        $this->assertStringNotContainsString(
            $ctx['token'],
            json_encode($result, JSON_UNESCAPED_UNICODE),
            'the raw ANON token must never survive into resolution'
        );
        $this->assertNotContains(self::ISSUE, $this->issue_codes($result), 'no person gate for a non-person slot');
    }

    /**
     * Run the pipeline with one real skill wired into a mock registry.
     *
     * @param array $ctx
     * @param string $skillname
     * @param callable $factory
     * @param array $input
     * @return array
     */
    private function run_real_skill_pipeline(array $ctx, string $skillname, callable $factory, array $input): array {
        $registry = $this->getMockBuilder(skill_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_skill', 'get_skill_contract'])
            ->getMock();
        $registry->method('get_skill')->willReturnCallback(
            static fn(string $name): ?skill_interface => $name === $skillname ? $factory() : null
        );
        $registry->method('get_skill_contract')->willReturnCallback(
            static fn(string $name): ?array => ['skill' => $name, 'version' => 1]
        );

        $pipeline = new preflight_pipeline($registry, $ctx['store']);
        return $pipeline->run(
            [['skill' => $skillname, 'input' => $input, '_structural_validated' => true]],
            $ctx['threadid'],
            $ctx['contextid'],
            $ctx['userid']
        );
    }

    /**
     * R3 does not fire for high-confidence (full-name) tokens.
     */
    public function test_r3_gate_ignores_full_name_tokens(): void {
        $this->resetAfterTest();
        $ctx = $this->prepare();
        $result = $ctx['anonymizer']->precheck_user_message(
            $ctx['threadid'],
            'Was ist mit Goduuara Herbst?'
        );
        preg_match('/ANON_USER_\d+_both/', (string)$result['sanitizedmessage'], $m);
        $bothtoken = (string)($m[0] ?? '');
        $this->assertNotSame('', $bothtoken);

        $result = $this->run_pipeline(
            $ctx['store'],
            ['skill' => 'demo.persondiag', 'input' => ['userquery' => $bothtoken]],
            $ctx['threadid'],
            $ctx['contextid'],
            $ctx['userid']
        );

        $this->assertNotContains(self::ISSUE, $this->issue_codes($result));
    }

    /**
     * R3 does not fire when the thread already resolved the SAME word together with more identity
     * material (here the full name) — a later single-word mention is a plausible follow-up.
     * Spec narrowed by F23 (2026-09-11): person context is word-specific, not thread-wide.
     */
    public function test_r3_gate_suppressed_by_person_context(): void {
        $this->resetAfterTest();
        $ctx = $this->prepare();

        $ledger = new execution_observation_ledger($ctx['store']);
        $ledger->append_from_results($ctx['threadid'], [[
            'skill' => 'demo.persondiag',
            'status' => 'executed',
            'input' => ['userquery' => 'Goduuara Herbst'],
            'observation_full' => 'Found 1 user: userid=42.',
        ]]);

        $result = $this->run_pipeline(
            $ctx['store'],
            ['skill' => 'demo.persondiag', 'input' => ['userquery' => $ctx['token']]],
            $ctx['threadid'],
            $ctx['contextid'],
            $ctx['userid']
        );

        $this->assertNotContains(self::ISSUE, $this->issue_codes($result));
    }

    /**
     * F23: a lookup of ANOTHER person, or one that consisted of the suspect word alone, is no
     * person context for this word — the gate still fires.
     */
    public function test_r3_gate_not_suppressed_by_unrelated_or_single_word_context(): void {
        $this->resetAfterTest();
        $ctx = $this->prepare();

        $ledger = new execution_observation_ledger($ctx['store']);
        $ledger->append_from_results($ctx['threadid'], [
            [
                'skill' => 'demo.persondiag',
                'status' => 'executed',
                'input' => ['userquery' => 'Maria Muster'],
                'observation_full' => 'Found 1 user: userid=41.',
            ],
            [
                'skill' => 'demo.persondiag',
                'status' => 'executed',
                'input' => ['userquery' => 'Herbst'],
                'observation_full' => 'Found 1 user: userid=42.',
            ],
        ]);

        $result = $this->run_pipeline(
            $ctx['store'],
            ['skill' => 'demo.persondiag', 'input' => ['userquery' => $ctx['token']]],
            $ctx['threadid'],
            $ctx['contextid'],
            $ctx['userid']
        );

        $this->assertContains(self::ISSUE, $this->issue_codes($result));
    }

    /**
     * A stored user decision for the word ends the gate.
     */
    public function test_r3_gate_suppressed_by_stored_decision(): void {
        $this->resetAfterTest();
        $ctx = $this->prepare();
        $ctx['anonymizer']->record_anon_word_decision($ctx['userid'], 'Herbst', 'person');

        $result = $this->run_pipeline(
            $ctx['store'],
            ['skill' => 'demo.persondiag', 'input' => ['userquery' => $ctx['token']]],
            $ctx['threadid'],
            $ctx['contextid'],
            $ctx['userid']
        );

        $this->assertNotContains(self::ISSUE, $this->issue_codes($result));
    }

    /**
     * R1: explicit person mutations never gate on their person parameter.
     */
    public function test_r1_person_mutation_never_gates(): void {
        $this->resetAfterTest();
        $ctx = $this->prepare();

        $result = $this->run_pipeline(
            $ctx['store'],
            ['skill' => 'course.enrol_user', 'input' => ['userquery' => $ctx['token']]],
            $ctx['threadid'],
            $ctx['contextid'],
            $ctx['userid']
        );

        $this->assertNotContains(self::ISSUE, $this->issue_codes($result));
    }

    /**
     * R2 pass-through: token in a resolvable non-person slot executes without any gate.
     */
    public function test_r2_token_in_resolvable_nonperson_slot_passes(): void {
        $this->resetAfterTest();
        $ctx = $this->prepare();
        $this->getDataGenerator()->create_course(['fullname' => 'Herbst']);

        $result = $this->run_pipeline(
            $ctx['store'],
            [
                'skill' => 'course.enrol_user',
                'input' => ['userquery' => 'Goduuara Herbst', 'coursequery' => $ctx['token']],
            ],
            $ctx['threadid'],
            $ctx['contextid'],
            $ctx['userid']
        );

        $this->assertNotContains(self::ISSUE, $this->issue_codes($result));
        $this->assertNotContains('CONTEXT_TARGET_UNRESOLVED', $this->issue_codes($result));
    }

    /**
     * R2 enrichment: an unresolvable target clarification names the suspect word.
     */
    public function test_r2_unresolved_target_clarification_names_the_word(): void {
        $this->resetAfterTest();
        $ctx = $this->prepare();

        $result = $this->run_pipeline(
            $ctx['store'],
            [
                'skill' => 'course.enrol_user',
                'input' => ['userquery' => 'Goduuara Herbst', 'coursequery' => $ctx['token']],
            ],
            $ctx['threadid'],
            $ctx['contextid'],
            $ctx['userid']
        );

        $issue = $this->find_issue($result, 'CONTEXT_TARGET_UNRESOLVED');
        $this->assertIsArray($issue, 'Precondition: the course target cannot be resolved.');
        $this->assertStringContainsString(
            $ctx['token'],
            (string)($issue['message'] ?? ''),
            'The existing clarification is enriched with the suspect TOKEN; the display names the word '
                . '(#2226 R2, HARD RULE 2026-09-11).'
        );
    }

    /**
     * A stored "word" decision stops masking the word in later messages.
     */
    public function test_word_decision_stops_masking(): void {
        global $USER;
        $this->resetAfterTest();
        $ctx = $this->prepare();
        $ctx['anonymizer']->record_anon_word_decision($ctx['userid'], 'Herbst', 'word');

        $freshthreadid = (int)$ctx['store']->create_fresh_thread((int)$USER->id, $ctx['contextid'])->id;
        $result = $ctx['anonymizer']->precheck_user_message($freshthreadid, 'Was wird im Herbst angeboten?');
        $this->assertStringContainsString(
            'Herbst',
            (string)$result['sanitizedmessage'],
            'After a "word" decision the single-word hit must not be masked for this user.'
        );
    }

    /**
     * Registry with the demo person-diagnosis skill and the real enrol skill.
     *
     * @return skill_registry
     */
    private function build_registry(): skill_registry {
        $registry = $this->getMockBuilder(skill_registry::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get_skill', 'get_skill_contract'])
            ->getMock();

        $registry->method('get_skill')->willReturnCallback(
            static function (string $name): ?skill_interface {
                if ($name === 'demo.persondiag') {
                    return self::person_diag_skill();
                }
                if ($name === 'course.enrol_user') {
                    return new enrol_user_skill();
                }
                return null;
            }
        );
        $registry->method('get_skill_contract')->willReturnCallback(
            static fn(string $name): ?array => ['skill' => $name, 'version' => 1]
        );

        return $registry;
    }

    /**
     * Minimal person-centric READ-ONLY diagnosis skill (ambient context, passes preflight).
     *
     * @return skill_interface
     */
    private static function person_diag_skill(): skill_interface {
        return new class implements skill_interface {
            /**
             * Return the unique skill name.
             *
             * @return string
             */
            public function get_name(): string {
                return 'demo.persondiag';
            }

            /**
             * Declare the person-centric read-only attribute (#2226 R3).
             *
             * @return bool
             */
            public function is_person_centric_readonly(): bool {
                return true;
            }

            /**
             * Return the input schema.
             *
             * @return array
             */
            public function get_schema(): array {
                return ['version' => 1, 'properties' => []];
            }

            /**
             * Return an example input payload.
             *
             * @return array
             */
            public function get_example_input(): array {
                return [];
            }

            /**
             * Return the prompt contract describing this skill.
             *
             * @return skill_prompt_contract
             */
            public function get_prompt_contract(): skill_prompt_contract {
                return new skill_prompt_contract([
                    'intent' => 'demo person diagnosis',
                    'anchors' => [],
                    'minimal_input' => [],
                    'example_input' => [],
                    'namespace' => 'demo',
                    'version' => 1,
                    'capabilities' => [],
                    'context_scopes' => ['course', 'module', 'system'],
                    'risk_class' => skill_risk_class::R0,
                ]);
            }

            /**
             * Return the risk class of this skill.
             *
             * @return string
             */
            public function get_risk_class(): string {
                return skill_risk_class::R0;
            }

            /**
             * Validate the raw input structure.
             *
             * @param array $input
             * @return array
             */
            public function check_structure(array $input): array {
                return ['valid' => true, 'errors' => []];
            }

            /**
             * Pass and mirror the input as prepared input.
             *
             * @param array $input
             * @param int $contextid
             * @param int $userid
             * @return preflight_result_v2
             */
            public function preflight(array $input, int $contextid, int $userid): preflight_result_v2 {
                return preflight_result_v2::ok($input);
            }

            /**
             * Execute the skill against the prepared input.
             *
             * @param array $preparedinput
             * @param int $contextid
             * @param int $userid
             * @return array
             */
            public function execute(array $preparedinput, int $contextid, int $userid): array {
                return [];
            }

            /**
             * Report whether the skill is read-only.
             *
             * @return bool
             */
            public function is_read_only(): bool {
                return true;
            }
        };
    }
}
