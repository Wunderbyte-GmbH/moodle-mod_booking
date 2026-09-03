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

use context_module;
use context_system;
use stdClass;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill;
use mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill;
use mod_booking\local\wizard\options\skills\diagnose_user_booking_skill;
use mod_booking\local\wizard\options\skills\list_option_properties_skill;
use mod_booking\local\wizard\options\skills\search_options_skill;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Cross-context target resolution (WP1): the read-only option/instance skills must be reachable
 * from a non-module context (dashboard, MCP system context).
 *
 * The diagnose skills adopt option_targeted_skill (optionid/optionquery pins the operating
 * activity), search_options and list_option_properties adopt module_targeted_skill (activityquery
 * names the activity). When nothing is named, the no-instance guard stays as the final fallback
 * and answers with a clarification instead of crashing.
 *
 * @package    mod_booking
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_booking_issue_skill
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill
 * @covers     \mod_booking\local\wizard\options\skills\diagnose_user_booking_skill
 * @covers     \mod_booking\local\wizard\options\skills\search_options_skill
 * @covers     \mod_booking\local\wizard\options\skills\list_option_properties_skill
 */
final class agent_skill_system_context_resolution_test extends booking_advanced_testcase {
    use \mod_booking\tests\agent_extension_test_trait;

    /**
     * Skip the entire case when the optional bookingextension_agent subplugin is absent.
     */
    public function setUp(): void {
        parent::setUp();
        $this->skip_without_agent_extension();
    }

    /**
     * diagnose_booking_issue: an explicit optionid resolves the operating module context from a
     * system-context entry point, and the skill still resolves its option internally afterwards.
     *
     * @return void
     */
    public function test_diagnose_booking_issue_resolves_target_from_system_context(): void {
        $env = $this->setup_booking('Alpha booking', 'Alpha option');
        $skill = new diagnose_booking_issue_skill();
        $input = ['question' => 'Why am I not booked for Alpha option?', 'optionid' => (int)$env['option']->id];

        $this->assertTrue($skill->supports_target_context());
        $selector = $skill->get_target_selector($input);
        $this->assertNotNull($selector, 'An explicit optionid must yield a module target selector.');
        $this->assertTrue($selector->is_module_target());
        $this->assertSame((int)$env['booking']->cmid, (int)$selector->id());

        $operating = $this->resolve_operating_context($skill, $input);
        $this->assertSame((int)$env['modulecontext']->id, $operating->id());

        $result = $skill->execute($input, $operating->id(), (int)get_admin()->id);
        $this->assertSame('executed', $result['status']);
        $this->assertSame((int)$env['option']->id, (int)$result['diagnosis']['optionid']);
    }

    /**
     * diagnose_cancellation_issue: a site-wide unique optionquery resolves the operating module
     * context from a system-context entry point.
     *
     * @return void
     */
    public function test_diagnose_cancellation_issue_resolves_target_from_system_context(): void {
        $env = $this->setup_booking('Beta booking', 'Beta cancellation option');
        $skill = new diagnose_cancellation_issue_skill();
        $input = ['question' => 'Why can I not cancel my booking?', 'optionquery' => 'Beta cancellation option'];

        $this->assertTrue($skill->supports_target_context());
        $selector = $skill->get_target_selector($input);
        $this->assertNotNull($selector, 'A unique optionquery must yield a module target selector.');
        $this->assertTrue($selector->is_module_target());
        $this->assertSame((int)$env['booking']->cmid, (int)$selector->id());

        $operating = $this->resolve_operating_context($skill, $input);
        $this->assertSame((int)$env['modulecontext']->id, $operating->id());

        $result = $skill->execute($input, $operating->id(), (int)get_admin()->id);
        $this->assertSame('executed', $result['status']);
        $this->assertSame((int)$env['option']->id, (int)$result['diagnosis']['optionid']);
    }

    /**
     * Without any option reference the diagnose skills stay ambient (no selector) and the
     * no-instance guard answers with a recoverable clarification at the system context.
     *
     * @return void
     */
    public function test_diagnose_skills_clarify_without_option_reference(): void {
        $this->setup_booking('Gamma booking', 'Gamma option');
        $systemcontextid = (int)context_system::instance()->id;

        foreach (
            [
                new diagnose_booking_issue_skill(),
                new diagnose_cancellation_issue_skill(),
            ] as $skill
        ) {
            $this->assertNull(
                $skill->get_target_selector(['question' => 'Why can I not book?']),
                get_class($skill) . ': no option reference must keep the skill in the ambient context.'
            );
            $result = $skill->execute(
                ['question' => 'Why can I not book?'],
                $systemcontextid,
                (int)get_admin()->id
            );
            $this->assertContains(
                'RECOVERABLE_INPUT_ERROR',
                (array)($result['issue_codes'] ?? []),
                get_class($skill) . ': the no-instance guard must clarify instead of crashing.'
            );
        }
    }

    /**
     * search_options: an activityquery resolves the operating module context from a system-context
     * entry point and the search then runs inside that instance.
     *
     * @return void
     */
    public function test_search_options_resolves_target_from_system_context(): void {
        // A second instance makes the site genuinely ambiguous without the activityquery.
        $this->setup_booking('Delta booking', 'Delta option');
        $env = $this->setup_booking('Epsilon booking', 'Epsilon option');

        $skill = new search_options_skill();
        $this->assertTrue($skill->supports_target_context());
        $this->assertArrayHasKey(
            'activityquery',
            (array)($skill->get_schema()['properties'] ?? []),
            'search_options must expose the activityquery targeting property.'
        );

        $input = ['query' => 'Epsilon option', 'activityquery' => 'Epsilon booking'];
        $selector = $skill->get_target_selector($input);
        $this->assertNotNull($selector);
        $this->assertTrue($selector->is_module_target());
        $this->assertSame('booking', $selector->modname());
        $this->assertSame('Epsilon booking', $selector->query());

        $operating = $this->resolve_operating_context($skill, $input);
        $this->assertSame((int)$env['modulecontext']->id, $operating->id());

        $result = $skill->execute($input, $operating->id(), (int)get_admin()->id);
        $this->assertSame('executed', $result['status']);
        $this->assertContains((int)$env['option']->id, (array)($result['previewoptionids'] ?? []));
    }

    /**
     * list_option_properties: an activityquery resolves the operating module context from a
     * system-context entry point and the property catalog is returned.
     *
     * @return void
     */
    public function test_list_option_properties_resolves_target_from_system_context(): void {
        $this->setup_booking('Zeta booking', 'Zeta option');
        $env = $this->setup_booking('Eta booking', 'Eta option');

        $skill = new list_option_properties_skill();
        $this->assertTrue($skill->supports_target_context());
        $this->assertArrayHasKey(
            'activityquery',
            (array)($skill->get_schema()['properties'] ?? []),
            'list_option_properties must expose the activityquery targeting property.'
        );

        $input = ['activityquery' => 'Eta booking'];
        $selector = $skill->get_target_selector($input);
        $this->assertNotNull($selector);
        $this->assertTrue($selector->is_module_target());
        $this->assertSame('booking', $selector->modname());

        $operating = $this->resolve_operating_context($skill, $input);
        $this->assertSame((int)$env['modulecontext']->id, $operating->id());

        $result = $skill->execute($input, $operating->id(), (int)get_admin()->id);
        $this->assertSame('executed', $result['status']);
        $this->assertNotEmpty($result['properties']);
    }

    /**
     * Without an activityquery, direct system-context execution of the module-targeted read
     * skills falls back to the no-instance guard's clarification.
     *
     * @return void
     */
    public function test_module_targeted_skills_clarify_without_activity_reference(): void {
        // Two instances: the target is genuinely ambiguous without an activityquery.
        $this->setup_booking('Theta booking', 'Theta option');
        $this->setup_booking('Iota booking', 'Iota option');
        $systemcontextid = (int)context_system::instance()->id;

        foreach (
            [
                new search_options_skill(),
                new list_option_properties_skill(),
            ] as $skill
        ) {
            $result = $skill->execute([], $systemcontextid, (int)get_admin()->id);
            $this->assertContains(
                'RECOVERABLE_INPUT_ERROR',
                (array)($result['issue_codes'] ?? []),
                get_class($skill) . ': the no-instance guard must clarify instead of crashing.'
            );
        }
    }

    /**
     * diagnose_user_booking: a named option resolves the operating module context from a
     * system-context entry point and the report runs option-focused.
     *
     * @return void
     */
    public function test_diagnose_user_booking_resolves_target_from_system_context(): void {
        $env = $this->setup_booking('Kappa booking', 'Kappa option');
        $skill = new diagnose_user_booking_skill();
        $input = [
            'userid' => (int)$env['student']->id,
            'optionquery' => 'Kappa option',
            'includemessages' => false,
        ];

        $this->assertTrue($skill->supports_target_context());
        $selector = $skill->get_target_selector($input);
        $this->assertNotNull($selector, 'A unique optionquery must yield a module target selector.');
        $this->assertTrue($selector->is_module_target());
        $this->assertSame((int)$env['booking']->cmid, (int)$selector->id());

        $operating = $this->resolve_operating_context($skill, $input);
        $this->assertSame((int)$env['modulecontext']->id, $operating->id());

        $result = $skill->execute($input, $operating->id(), (int)get_admin()->id);
        $this->assertSame('executed', $result['status']);
        $report = $this->decode_diagnosis_report($result);
        $this->assertSame('option', $report['mode']);
        $this->assertSame((int)$env['option']->id, (int)$report['optionid']);
    }

    /**
     * diagnose_user_booking: an optionquery that does not resolve degrades to the instance-wide
     * overview and flags that explicitly instead of silently pretending nothing was asked.
     *
     * @return void
     */
    public function test_diagnose_user_booking_flags_unresolved_optionquery(): void {
        $env = $this->setup_booking('Lambda booking', 'Lambda option');
        $skill = new diagnose_user_booking_skill();
        $input = [
            'userid' => (int)$env['student']->id,
            'optionquery' => 'No such option anywhere',
            'includemessages' => false,
        ];

        $this->assertNull(
            $skill->get_target_selector($input),
            'An unresolvable optionquery must keep the skill in the ambient context.'
        );

        $result = $skill->execute($input, (int)context_system::instance()->id, (int)get_admin()->id);
        $this->assertSame('executed', $result['status']);
        $report = $this->decode_diagnosis_report($result);
        $this->assertSame('instance_wide', $report['mode']);
        $this->assertSame('No such option anywhere', $report['optionquery_unresolved']);
        $this->assertStringContainsString('No such option anywhere', (string)$result['detail']);
    }

    /**
     * The executor chokepoint (the seam the chat read-only path actually traverses): a command
     * WITHOUT a preflight-resolved operating_contextid still resolves its named target late in
     * executor::execute_commands — the trait must not be inert in chat R0 (threads 542/539).
     *
     * @return void
     */
    public function test_executor_resolves_readonly_target_late_from_system_context(): void {
        $this->setup_booking('Mu booking', 'Mu option');
        $env = $this->setup_booking('Nu booking', 'Nu option');

        $results = $this->execute_via_executor([
            ['skill' => 'mod_booking.search_options', 'input' => [
                'query' => 'Nu option',
                'activityquery' => 'Nu booking',
            ]],
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('executed', $results[0]['status']);
        $this->assertNotContains(
            'RECOVERABLE_INPUT_ERROR',
            (array)($results[0]['issue_codes'] ?? []),
            'The no-instance guard must not fire when the executor can resolve the named activity late.'
        );
        $this->assertContains(
            (int)$env['option']->id,
            array_map('intval', (array)($results[0]['previewoptionids'] ?? [])),
            'The search must run inside the late-resolved activity.'
        );
    }

    /**
     * The executor chokepoint keeps thread-515 semantics: an unresolvable read-only target falls
     * back to the ambient context and clarifies via the no-instance guard — it never blocks.
     *
     * @return void
     */
    public function test_executor_falls_back_to_ambient_for_unresolvable_readonly_target(): void {
        $this->setup_booking('Xi booking', 'Xi option');
        $this->setup_booking('Omikron booking', 'Omikron option');

        $results = $this->execute_via_executor([
            ['skill' => 'mod_booking.search_options', 'input' => [
                'query' => 'anything',
                'activityquery' => 'No such activity anywhere',
            ]],
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('executed', $results[0]['status']);
        $this->assertContains(
            'RECOVERABLE_INPUT_ERROR',
            (array)($results[0]['issue_codes'] ?? []),
            'An unresolvable read-only target must clarify at the ambient context, never block.'
        );
    }

    /**
     * Run commands through the real executor seam at the system context, as the chat read-only
     * path does (no preflight pipeline, no operating_contextid on the command).
     *
     * @param array $commands Commands in executor shape (skill + input).
     * @return array The executor results.
     */
    private function execute_via_executor(array $commands): array {
        $this->setAdminUser();
        $executor = new \bookingextension_agent\local\wizard\executor(
            \bookingextension_agent\local\wizard\skill_registry::make_default(),
            new \bookingextension_agent\local\wizard\conversation_store(),
            new \bookingextension_agent\local\wizard\services\security\authorization_service()
        );
        return $executor->execute_commands(
            $commands,
            (int)context_system::instance()->id,
            (int)get_admin()->id,
            'phpunit-late-resolution-' . sha1(json_encode($commands)),
            0
        );
    }

    /**
     * rule_targeted_skill: a rule living in a booking activity resolves that activity's module
     * context from any entry point (thread 584 fix).
     *
     * @return void
     */
    public function test_update_rule_resolves_instance_rule_context(): void {
        $env = $this->setup_booking('Rho booking', 'Rho option');
        $modulectxid = (int)$env['modulecontext']->id;
        $this->seed_rule('rho_instance_rule', 'Rho Instance Rule', $modulectxid);

        $skill = new \mod_booking\local\wizard\options\skills\update_rule_from_template_skill();
        $this->assertTrue($skill->supports_target_context());

        $selector = $skill->get_target_selector(['rulequery' => 'Rho Instance Rule']);
        $this->assertNotNull($selector, 'A rule living in an activity must yield that module selector.');
        $this->assertTrue($selector->is_module_target());
        $this->assertSame((int)$env['booking']->cmid, (int)$selector->id());
    }

    /**
     * rule_targeted_skill: a SYSTEM rule stays ambient (no module selector — the activity
     * question is unanswerable for it), and the preflight disambiguates by RULE id, never by
     * activity (thread 584: three identical activity lists).
     *
     * @return void
     */
    public function test_update_rule_system_rules_disambiguate_by_rule_id(): void {
        $this->setup_booking('Sigma booking', 'Sigma option');
        $this->setAdminUser();
        $systemctxid = (int)context_system::instance()->id;

        $ruleid1 = $this->seed_rule('sigma_rule', 'Sigma Twin Rule', $systemctxid);
        $ruleid2 = $this->seed_rule('sigma_rule', 'Sigma Twin Rule', $systemctxid);

        $skill = new \mod_booking\local\wizard\options\skills\update_rule_from_template_skill();
        $this->assertNull(
            $skill->get_target_selector(['rulequery' => 'Sigma Twin Rule']),
            'Ambiguous rules must stay ambient — the preflight disambiguates by rule id.'
        );

        $result = $skill->preflight(
            ['rulequery' => 'Sigma Twin Rule', 'rulename' => 'Renamed Twin'],
            $systemctxid,
            (int)get_admin()->id
        );
        $issuecodes = array_map(static fn(array $i): string => (string)($i['code'] ?? ''), $result->issues);
        $this->assertContains('RULE_RESOLUTION_AMBIGUOUS', $issuecodes);
        $this->assertNotContains('MISSING_TARGET_ACTIVITY', $issuecodes);
        $this->assertNotContains('CONTEXT_TARGET_UNRESOLVED', $issuecodes);
        $candidates = implode(' ', array_map(static fn(array $i): string => (string)($i['message'] ?? ''), $result->issues));
        $this->assertStringContainsString('id=' . $ruleid1, $candidates);
        $this->assertStringContainsString('id=' . $ruleid2, $candidates);
    }

    /**
     * rule_targeted_skill: a unique SYSTEM rule passes preflight at the system context —
     * no activity question, the rule id is resolved and prepared.
     *
     * @return void
     */
    public function test_update_rule_unique_system_rule_passes_at_system_context(): void {
        $this->setup_booking('Tau booking', 'Tau option');
        $this->setAdminUser();
        $systemctxid = (int)context_system::instance()->id;
        $ruleid = $this->seed_rule('tau_rule', 'Tau Unique Rule', $systemctxid);

        $skill = new \mod_booking\local\wizard\options\skills\update_rule_from_template_skill();
        $result = $skill->preflight(
            ['rulequery' => 'Tau Unique Rule', 'rulename' => 'Tau Renamed Rule'],
            $systemctxid,
            (int)get_admin()->id
        );

        $this->assertSame('pass', (string)$result->status);
        $this->assertSame($ruleid, (int)($result->preparedinput['ruleid'] ?? 0));
    }

    /**
     * Executor fail-closed is selector-aware: a mutating rule command whose rule lives at the
     * SYSTEM context passes the module-target check at a non-module operating context (it then
     * fails at the guard-token stage — proving the target gate no longer blocks it).
     *
     * @return void
     */
    public function test_executor_allows_system_rule_mutation_at_system_context(): void {
        $this->setup_booking('Ypsilon booking', 'Ypsilon option');
        $this->setAdminUser();
        $this->seed_rule('ypsilon_rule', 'Ypsilon System Rule', (int)context_system::instance()->id);
        // Open the governance skill gate for the mutating skill (same baseline as the agent suite).
        set_config('aiskillenableall', 1, 'bookingextension_agent');

        $results = $this->execute_via_executor([
            ['skill' => 'mod_booking.update_rule_from_template', 'input' => [
                'rulequery' => 'Ypsilon System Rule',
                'rulename' => 'Ypsilon Renamed',
            ]],
        ]);

        $this->assertCount(1, $results);
        $this->assertNotContains(
            'CONTEXT_TARGET_UNRESOLVED',
            (array)($results[0]['issue_codes'] ?? []),
            'A system-scoped rule must not be blocked by the module-target gate.'
        );
        // Without a preflight-issued guard token the mutation stops at the guard stage —
        // which is exactly the proof that the target gate let it through.
        $this->assertContains('EXECUTION_GUARD_MISSING', (array)($results[0]['issue_codes'] ?? []));
    }

    /**
     * Full execute path for the thread-584 shape: a SYSTEM rule renamed from inside a booking
     * activity. Preflight resolves the rule via the context path; execute must pass the rule's
     * OWN contextid to the service (which refuses foreign contextids) — the rule stays a
     * system rule after the rename.
     *
     * @return void
     */
    public function test_update_rule_executes_system_rule_rename_from_module_ambient(): void {
        global $DB;
        $env = $this->setup_booking('Phi booking', 'Phi option');
        $this->setAdminUser();

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $rule = $plugingenerator->create_rule([
            'name' => 'Phi System Reminder',
            'conditionname' => 'select_user_from_event',
            'contextid' => 1,
            'conditiondata' => '{"userfromeventtype":"userid"}',
            'actionname' => 'send_mail',
            'actiondata' => '{"sendical":0,"sendicalcreateorcancel":"","subject":"Phi subject",'
                . '"template":"Phi body","templateformat":1}',
            'rulename' => 'rule_react_on_event',
            'ruledata' => '{"boevent":"\\\\mod_booking\\\\event\\\\bookingoption_booked",'
                . '"aftercompletion":0,"cancelrules":[],"condition":"0"}',
        ]);

        $modulectxid = (int)$env['modulecontext']->id;
        $adminid = (int)get_admin()->id;

        $skill = new \mod_booking\local\wizard\options\skills\update_rule_from_template_skill();
        $result = $skill->preflight(
            ['rulequery' => 'Phi System Reminder', 'rulename' => 'Phi Renamed Reminder'],
            $modulectxid,
            $adminid
        );
        $this->assertSame('pass', (string)$result->status, json_encode($result->issues));

        $execresult = $skill->execute($result->preparedinput, $modulectxid, $adminid);
        $this->assertSame('executed', (string)($execresult['status'] ?? ''), (string)($execresult['detail'] ?? ''));

        $saved = $DB->get_record('booking_rules', ['id' => (int)$rule->id], '*', MUST_EXIST);
        $this->assertSame(1, (int)$saved->contextid, 'The renamed rule must STAY a system rule.');
        $json = json_decode((string)$saved->rulejson);
        $this->assertSame('Phi Renamed Reminder', (string)($json->name ?? ''));
    }

    /**
     * Insert a booking rule row for targeting tests.
     *
     * @param string $rulename Technical rule name.
     * @param string $displayname Display name (rulejson name).
     * @param int $contextid Context the rule is bound to.
     * @return int The rule id.
     */
    private function seed_rule(string $rulename, string $displayname, int $contextid): int {
        global $DB;
        return (int)$DB->insert_record('booking_rules', (object)[
            'rulename' => $rulename,
            'rulejson' => json_encode([
                'name' => $displayname,
                'conditionname' => 'select_teacher_in_bo',
                'actionname' => 'send_mail',
                'rulename' => $rulename,
            ]),
            'contextid' => $contextid,
            'eventname' => '',
            'useastemplate' => 0,
            'isactive' => 1,
        ]);
    }

    /**
     * Decode the JSON diagnosis report embedded in diagnose_user_booking's observation_full.
     *
     * @param array $result The skill execute() result.
     * @return array The decoded report.
     */
    private function decode_diagnosis_report(array $result): array {
        $obs = (string)($result['observation_full'] ?? '');
        $pos = strpos($obs, '(JSON):');
        $json = $pos !== false ? substr($obs, $pos + strlen('(JSON):')) : $obs;
        $decoded = json_decode(trim($json), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Simulate the engine seam: resolve the operating context for a skill command from a
     * system-context ambient, exactly as skill_operating_context_resolver does at runtime.
     *
     * @param object $skill The skill under test.
     * @param array $input The command input.
     * @return object The resolved bookingextension_agent agent_context.
     */
    private function resolve_operating_context(object $skill, array $input): object {
        $ambient = \bookingextension_agent\local\wizard\dto\agent_context::from_context(context_system::instance());
        $resolver = new \bookingextension_agent\local\wizard\services\security\skill_operating_context_resolver();
        return $resolver->resolve($skill, $input, $ambient, (int)get_admin()->id);
    }

    /**
     * Build a course + booking instance (given name) + one option (given title).
     *
     * @param string $bookingname Name of the booking activity.
     * @param string $optiontitle Title of the booking option.
     * @return array Keys: course, booking, modulecontext, option, student.
     */
    private function setup_booking(string $bookingname, string $optiontitle): array {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $bookingmanager = $this->getDataGenerator()->create_user();

        $bdata = [
            'name' => $bookingname,
            'eventtype' => 'Test event',
            'bookedtext' => ['text' => 'text'],
            'waitingtext' => ['text' => 'text'],
            'notifyemail' => ['text' => 'text'],
            'statuschangetext' => ['text' => 'text'],
            'deletedtext' => ['text' => 'text'],
            'pollurltext' => ['text' => 'text'],
            'pollurlteacherstext' => ['text' => 'text'],
            'notificationtext' => ['text' => 'text'],
            'userleave' => ['text' => 'text'],
            'course' => $course->id,
            'bookingmanager' => $bookingmanager->username,
        ];
        $booking = $this->getDataGenerator()->create_module('booking', $bdata);

        $this->getDataGenerator()->enrol_user($student->id, $course->id);

        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = $optiontitle;
        $record->chooseorcreatecourse = 1;
        $record->courseid = $course->id;
        $record->useprice = 0;
        $record->maxanswers = 4;
        $record->optiondateid_0 = "0";
        $record->daystonotify_0 = "0";
        $record->coursestarttime_0 = strtotime('now + 3 days');
        $record->courseendtime_0 = strtotime('now + 6 days');
        $option = $plugingenerator->create_option($record);

        return [
            'course' => $course,
            'booking' => $booking,
            'modulecontext' => context_module::instance($booking->cmid),
            'option' => $option,
            'student' => $student,
        ];
    }
}
