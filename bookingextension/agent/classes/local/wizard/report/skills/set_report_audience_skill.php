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

namespace bookingextension_agent\local\wizard\report\skills;

use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\reportbuilder\audience_service;
use bookingextension_agent\local\wizard\services\reportbuilder\report_definition_service;
use bookingextension_agent\local\wizard\services\reportbuilder\report_preview_renderer;
use core_reportbuilder\local\models\report;
use core_reportbuilder\permission;

/**
 * Skill report.set_report_audience: who may see a custom report.
 *
 * Adds or removes one audience of any registered audience type (core: all users, site
 * administrators, users with a system role, cohort members, manually named users; plugin types
 * appear automatically). Configuration is resolved from structured input — roles by short name,
 * cohorts by id number, persons by id, address or a single name match — with candidates on a
 * miss. Person data never enters the observation: the skill reports how many users an audience
 * covers.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_report_audience_skill extends report_skill_base implements skill_trigger_provider_interface {
    /** Skill name. */
    public const SKILL_NAME = 'report.set_report_audience';

    /** Issue code: the audience type is unknown or unavailable here. */
    public const CODE_AUDIENCE_TYPE = 'AUDIENCE_TYPE_VALIDATION_ERROR';

    /** Issue code: the acting user may not add this audience type. */
    public const CODE_AUDIENCE_TYPE_DENIED = 'AUDIENCE_TYPE_PERMISSION_DENIED';

    /** Issue code: a role, cohort or person could not be resolved. */
    public const CODE_AUDIENCE_MEMBER = 'AUDIENCE_MEMBER_VALIDATION_ERROR';

    /** Issue code: several persons match a name. */
    public const CODE_AUDIENCE_MEMBER_AMBIGUOUS = 'AUDIENCE_MEMBER_AMBIGUOUS';

    /** Issue code: the audience to remove is not on this report. */
    public const CODE_AUDIENCE_NOT_FOUND = 'AUDIENCE_NOT_FOUND';

    /** Issue code: missing action. */
    public const CODE_MISSING_ACTION = 'MISSING_AUDIENCE_ACTION';

    /**
     * Constructor: broad write (R2).
     */
    public function __construct() {
        parent::__construct(false, skill_risk_class::R2);
    }

    /**
     * Skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Gate 2 (core's per-report edit rule and the audience type's own user_can_add() on top).
     *
     * @return string[]
     */
    public function get_required_native_capabilities(): array {
        return ['moodle/reportbuilder:edit'];
    }

    /**
     * The manual audience names persons: the anonymizer collision gate treats them as person fields.
     *
     * @return string[]
     */
    public function get_person_reference_fields(): array {
        return ['userqueries'];
    }

    /**
     * Left out, the manual audience names nobody, so the requester is named through a yes/no companion (#2569).
     *
     * @return array<string,string> field => description of its yes/no companion
     */
    public function get_requester_flag_fields(): array {
        return ['userqueries' => 'true when the requester belongs to the audience.'];
    }

    /**
     * Schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'Set who may see a custom report of the Moodle Report Builder: add an audience (all users, site '
                . 'administrators, users with a system role, members of a cohort, named persons, or a plugin-provided '
                . 'audience type) or remove one.',
            'is' => 'The visibility of one report: its audiences.',
            'not' => 'Its columns or conditions (update_report); sending it (schedule_report); who sees it today '
                . '(get_report_details).',
            'readonly' => false,
            'fallback_skillcall_string_key' => 'ai_status_skillcall_report_set_report_audience',
            'example_utterances' => [
                'make the report visible to all managers',
                'share the completion report with the members of the cohort Trainers',
                'let everyone on the site see this report',
                'give Anna access to the users report',
                'remove the all-users audience from the report',
                'only site administrators should see that report',
            ],
            'properties' => [
                'reportid' => [
                    'type' => 'integer',
                    'description' => 'Numeric id of the report when already known. Never guess.',
                    'required' => false,
                ],
                'reportquery' => [
                    'type' => 'string',
                    'description' => 'The report name exactly as the user wrote it, when no id is known.',
                    'required' => false,
                ],
                'action' => [
                    'type' => 'string',
                    'description' => 'add or remove (default add).',
                    'required' => false,
                ],
                'audience_type' => [
                    'type' => 'string',
                    'description' => 'The audience type as listed on this site, e.g. allusers, admins, systemrole, '
                        . 'cohortmember, manual, or the type\'s localised name. Required for add.',
                    'required' => false,
                ],
                'roles' => [
                    'type' => 'array',
                    'description' => 'For a system-role audience: role short names (manager, editingteacher, …) or ids.',
                    'required' => false,
                ],
                'cohorts' => [
                    'type' => 'array',
                    'description' => 'For a cohort audience: cohort id numbers, exact names or ids.',
                    'required' => false,
                ],
                'userqueries' => [
                    'type' => 'array',
                    'description' => 'For a manual audience: the persons exactly as the user named them (name, e-mail '
                        . 'address or id), one entry per person.',
                    'required' => false,
                ],
                'config' => [
                    'type' => 'object',
                    'description' => 'Raw configuration for a plugin-provided audience type, as its form expects it.',
                    'required' => false,
                ],
                'heading' => [
                    'type' => 'string',
                    'description' => 'Optional heading shown for the audience in the report editor.',
                    'required' => false,
                ],
                'audienceid' => [
                    'type' => 'integer',
                    'description' => 'For remove: the id of the audience (from the report details).',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
            'prompt_meta' => [
                'input_fields_for_prompt' => ['reportquery', 'audience_type'],
                'anchor_fields' => ['reportquery'],
                'context_scopes' => ['system'],
                'required_groups' => [['reportid', 'reportquery']],
            ],
        ];
    }

    /**
     * Example input.
     *
     * @return array
     */
    public function get_example_input(): array {
        return ['reportquery' => 'Active users', 'action' => 'add', 'audience_type' => 'systemrole', 'roles' => ['manager']];
    }

    /**
     * WHEN line of the card.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'report.set_report_audience_request',
                'description' => 'User wants a custom report shared with or hidden from a group of people: everyone, a '
                    . 'role, a cohort, named persons.',
            ],
        ];
    }

    /**
     * Grounding: the audience types that exist on this site.
     *
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function get_dynamic_construction_hints(int $contextid, int $userid): array {
        try {
            $types = (new audience_service())->types();
        } catch (\Throwable $e) {
            return [];
        }
        if (empty($types)) {
            return [];
        }
        $pairs = array_map(static fn(array $t): string => $t['name'] . ' → ' . $t['type'], $types);
        return [
            'guidance' => [
                '- audience_type takes one of these EXACT values (shown as "name → value"): ' . implode('; ', $pairs)
                    . '. Give roles as role short names, cohorts as id numbers or names, persons as the user wrote them.',
            ],
        ];
    }

    /**
     * Confirmation card (source B).
     *
     * @param array $input Prepared input.
     * @return array|null
     */
    public function describe_proposed_action(array $input): ?array {
        $lang = $this->get_output_language($input);
        $resolved = (array)($input['resolved'] ?? []);
        $rows = [[
            'label' => $this->localized_string('agent_report_card_report', null, $lang),
            'value' => (string)($resolved['reportname'] ?? '') . ' (#' . (int)($input['reportid'] ?? 0) . ')',
        ]];
        $rows[] = [
            'label' => $this->localized_string('agent_report_card_audience_type', null, $lang),
            'value' => (string)($resolved['typename'] ?? ''),
        ];
        $summary = (array)($resolved['summary'] ?? []);
        if (!empty($summary['labels'])) {
            $rows[] = [
                'label' => $this->localized_string('agent_report_card_audience_members', null, $lang),
                'value' => implode(', ', array_map('strval', (array)$summary['labels'])),
            ];
        } else if (isset($summary['count'])) {
            $rows[] = [
                'label' => $this->localized_string('agent_report_card_audience_members', null, $lang),
                'value' => $this->localized_string('agent_report_card_audience_persons', (int)$summary['count'], $lang),
            ];
        }
        $key = ($input['action'] ?? 'add') === 'remove'
            ? 'agent_report_card_audience_remove_title'
            : 'agent_report_card_audience_add_title';
        return [
            'title' => $this->localized_string($key, (string)($resolved['reportname'] ?? ''), $lang),
            'summary' => '',
            'rows' => $rows,
        ];
    }

    /**
     * Queue identity: report, action, type and configuration.
     *
     * @param array $input
     * @return array
     */
    public function build_queue_business_identity(array $input): array {
        return [
            'task' => self::SKILL_NAME,
            'report' => (int)($input['reportid'] ?? 0) ?: trim((string)($input['reportquery'] ?? '')),
            'action' => (string)($input['action'] ?? 'add'),
            'type' => (string)($input['audience_type'] ?? ''),
            'members' => sha1(json_encode([
                $input['roles'] ?? null, $input['cohorts'] ?? null, $input['userqueries'] ?? null,
                $input['config'] ?? null, $input['audienceid'] ?? null,
            ])),
        ];
    }

    /**
     * Shape: a target, and for add a type / for remove an id.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        if ((int)($input['reportid'] ?? 0) <= 0 && trim((string)($input['reportquery'] ?? '')) === '') {
            $errors[] = get_string('agent_report_report_missing', 'bookingextension_agent');
        }
        $action = strtolower(trim((string)($input['action'] ?? 'add')));
        if (!in_array($action, ['add', 'remove'], true)) {
            $errors[] = get_string('agent_report_clarify_audience_action', 'bookingextension_agent');
        }
        if ($action === 'add' && trim((string)($input['audience_type'] ?? '')) === '') {
            $errors[] = get_string('agent_report_clarify_audience_type', 'bookingextension_agent');
        }
        if ($action === 'remove' && (int)($input['audienceid'] ?? 0) <= 0) {
            $errors[] = get_string('agent_report_clarify_audience_id', 'bookingextension_agent');
        }
        foreach (['roles', 'cohorts', 'userqueries'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null && !is_array($input[$key])) {
                $errors[] = get_string('agent_report_clarify_list_shape', 'bookingextension_agent', $key);
            }
        }
        return ['valid' => empty($errors), 'errors' => $errors, 'ambiguities' => []];
    }

    /**
     * Resolve report, type and members; freeze the configuration.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    protected function run_preflight(array $input, int $contextid, int $userid): array {
        $lang = $this->get_output_language($input);

        if (!$this->can_author_reports($userid)) {
            return $this->permission_denied_preflight($lang);
        }
        $resolved = $this->resolve_report_target($input, $userid);
        if ($resolved['report'] === null) {
            return $this->report_target_clarification($resolved, $userid, $lang);
        }
        /** @var report $persistent */
        $persistent = $resolved['report'];
        if (!permission::can_edit_report($persistent, $userid)) {
            return $this->invalid([[
                'code' => self::CODE_PERMISSION_DENIED,
                'severity' => 'needs_clarification',
                'message' => $this->localized_string('agent_report_no_edit_access', null, $lang),
            ]]);
        }
        $reportid = (int)$persistent->get('id');
        $service = new audience_service();
        $definition = new report_definition_service($this->resolver(), $this->catalog());
        $snapshot = $definition->snapshot($persistent, $userid, false);
        $action = strtolower(trim((string)($input['action'] ?? 'add')));
        if (!in_array($action, ['add', 'remove'], true)) {
            return $this->invalid([[
                'code' => self::CODE_MISSING_ACTION,
                'severity' => 'needs_clarification',
                'message' => $this->localized_string('agent_report_clarify_audience_action', null, $lang),
                'options' => [['id' => 'add', 'label' => 'add'], ['id' => 'remove', 'label' => 'remove']],
            ]]);
        }

        if ($action === 'remove') {
            $audienceid = (int)($input['audienceid'] ?? 0);
            $existing = null;
            foreach ($snapshot['audiences'] as $audience) {
                if ((int)$audience['id'] === $audienceid) {
                    $existing = $audience;
                    break;
                }
            }
            if ($existing === null) {
                return $this->invalid([[
                    'code' => self::CODE_AUDIENCE_NOT_FOUND,
                    'severity' => 'needs_clarification',
                    'message' => $this->localized_string('agent_report_clarify_audience_id', null, $lang),
                    'options' => array_map(static fn(array $a): array => [
                        'id' => (string)(int)$a['id'],
                        'label' => $a['name'] . ($a['heading'] !== '' ? ' (' . $a['heading'] . ')' : ''),
                    ], $snapshot['audiences']),
                ]]);
            }
            return $this->pass([
                'reportid' => $reportid,
                'action' => 'remove',
                'audienceid' => $audienceid,
                'outputlang' => $lang,
                'resolved' => ['reportname' => $snapshot['name'], 'typename' => $existing['name'], 'summary' => []],
            ]);
        }

        $type = $service->resolve_type((string)($input['audience_type'] ?? ''));
        if ($type['classname'] === null) {
            return $this->invalid([[
                'code' => self::CODE_AUDIENCE_TYPE,
                'severity' => 'needs_clarification',
                'message' => $this->localized_string('agent_report_clarify_audience_type', null, $lang),
                'options' => $service->type_options($type['candidates']),
            ]]);
        }
        $config = $service->build_config($type['classname'], $input, $userid);
        if ($config['problem'] !== null) {
            return $this->audience_problem_clarification($config['problem'], $lang);
        }
        $typename = '';
        foreach ($service->types() as $entry) {
            if ($entry['classname'] === $type['classname']) {
                $typename = $entry['name'];
            }
        }

        return $this->pass([
            'reportid' => $reportid,
            'action' => 'add',
            'classname' => $type['classname'],
            'configdata' => $config['configdata'],
            'heading' => trim((string)($input['heading'] ?? '')),
            'outputlang' => $lang,
            'resolved' => ['reportname' => $snapshot['name'], 'typename' => $typename, 'summary' => $config['summary']],
        ]);
    }

    /**
     * Apply the frozen audience change.
     *
     * @param array $preparedinput
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $preparedinput, int $contextid, int $userid): array {
        $lang = $this->get_output_language($preparedinput);
        $debugbase = $this->build_skill_debug_message(self::SKILL_NAME, $preparedinput);
        $reportid = (int)($preparedinput['reportid'] ?? 0);
        $resolved = (array)($preparedinput['resolved'] ?? []);

        $persistent = $reportid > 0 ? report::get_record(['id' => $reportid]) : false;
        if (!$persistent || !permission::can_edit_report($persistent, $userid)) {
            return $this->permission_denied_result($lang, $debugbase . "\nDenied: cannot edit report " . $reportid);
        }

        $service = new audience_service();
        if (($preparedinput['action'] ?? 'add') === 'remove') {
            $service->remove($reportid, (int)$preparedinput['audienceid']);
            $key = 'agent_report_audience_removed';
        } else {
            $service->add(
                $reportid,
                (string)$preparedinput['classname'],
                (array)($preparedinput['configdata'] ?? []),
                (string)($preparedinput['heading'] ?? '')
            );
            $key = 'agent_report_audience_added';
        }

        $definition = new report_definition_service($this->resolver(), $this->catalog());
        $snapshot = $definition->snapshot(report::get_record(['id' => $reportid]), $userid, false);
        $usermessage = $this->localized_string($key, (object)[
            'name' => $snapshot['name'],
            'type' => (string)($resolved['typename'] ?? ''),
            'audiences' => count($snapshot['audiences']),
            'url' => $snapshot['url'],
        ], $lang);

        $lines = [$usermessage . ' [id ' . $snapshot['id'] . ']'];
        $lines[] = 'AUDIENCES (' . count($snapshot['audiences']) . '):';
        foreach ($snapshot['audiences'] as $audience) {
            $lines[] = '- id ' . $audience['id'] . ' | ' . $audience['type'] . ' | ' . $audience['name']
                . ' | users covered: ' . (int)($audience['usercount'] ?? 0);
        }

        return [
            'status' => 'executed',
            'detail' => $usermessage,
            'usermessage' => $usermessage,
            'resultid' => (int)$snapshot['id'],
            'report' => $snapshot,
            'observation_full' => implode("\n", $lines),
            'produced_outputs' => [
                'reportid' => (int)$snapshot['id'],
                'reportname' => (string)$snapshot['name'],
                'audienceids' => array_map('intval', array_column($snapshot['audiences'], 'id')),
            ],
            'debugmessage' => $debugbase . "\nReport: " . $reportid . "\nAudiences: " . count($snapshot['audiences']),
        ];
    }

    /**
     * Live view of the report after the change.
     *
     * @param array $resultentry
     * @param int $contextid
     * @param int $userid
     * @return array|null
     */
    public function get_result_preview(array $resultentry, int $contextid, int $userid): ?array {
        $snapshot = (array)($resultentry['report'] ?? []);
        $reportid = (int)($snapshot['id'] ?? 0);
        if ($reportid <= 0) {
            return null;
        }
        $persistent = report::get_record(['id' => $reportid]);
        if (!$persistent) {
            return null;
        }
        return (new report_preview_renderer())->build($persistent, $userid, $snapshot);
    }

    /**
     * Clarification for an audience configuration problem.
     *
     * @param array $problem {kind, value, options}
     * @param string $lang
     * @return array
     */
    private function audience_problem_clarification(array $problem, string $lang): array {
        $kind = (string)($problem['kind'] ?? '');
        $value = (string)($problem['value'] ?? '');
        switch ($kind) {
            case audience_service::PROBLEM_TYPE:
                $code = self::CODE_AUDIENCE_TYPE;
                $message = $this->localized_string('agent_report_clarify_audience_type', null, $lang);
                break;
            case audience_service::PROBLEM_TYPE_NOT_ADDABLE:
                $code = self::CODE_AUDIENCE_TYPE_DENIED;
                $message = $this->localized_string('agent_report_audience_cannot_add', $value, $lang);
                break;
            case audience_service::PROBLEM_ROLE:
                $code = self::CODE_AUDIENCE_MEMBER;
                $message = $this->localized_string('agent_report_clarify_role', $value, $lang);
                break;
            case audience_service::PROBLEM_COHORT:
                $code = self::CODE_AUDIENCE_MEMBER;
                $message = $this->localized_string('agent_report_clarify_cohort', $value, $lang);
                break;
            case audience_service::PROBLEM_USER_AMBIGUOUS:
                $code = self::CODE_AUDIENCE_MEMBER_AMBIGUOUS;
                $message = $this->localized_string('agent_report_clarify_user_ambiguous', $value, $lang);
                break;
            case audience_service::PROBLEM_USER_NOT_FOUND:
                $code = self::CODE_AUDIENCE_MEMBER;
                $message = $this->localized_string('agent_report_clarify_user_not_found', $value, $lang);
                break;
            default:
                $code = self::CODE_AUDIENCE_MEMBER;
                $message = $this->localized_string('agent_report_clarify_audience_config', $value, $lang);
                break;
        }
        $issue = ['code' => $code, 'severity' => 'needs_clarification', 'message' => $message];
        if (!empty($problem['options'])) {
            $issue['options'] = array_values((array)$problem['options']);
        }
        return $this->invalid([$issue]);
    }
}
