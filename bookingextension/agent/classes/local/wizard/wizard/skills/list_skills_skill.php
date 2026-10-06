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

namespace bookingextension_agent\local\wizard\wizard\skills;

use bookingextension_agent\local\wizard\core\skills\core_skill_base;
use bookingextension_agent\local\wizard\dto\skill_risk_class;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\interfaces\skill_introspection_provider_interface;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\introspection\skill_introspection_service;
use bookingextension_agent\local\wizard\skill_contract_validator;

/**
 * Skill definition for wizard.list_skills.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_skills_skill extends core_skill_base implements skill_trigger_provider_interface {
    /** Skill name constant. */
    public const SKILL_NAME = 'wizard.list_skills';

    /** Detail level: skill names only, grouped (the default overview). */
    public const DETAIL_NAMES = 'names';

    /** Detail level: every skill with its complete description. */
    public const DETAIL_FULL = 'full';

    /** @var skill_introspection_provider_interface|null Engine-injected introspection provider. */
    private ?skill_introspection_provider_interface $introspection = null;

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0);
    }

    /**
     * Inject the engine introspection provider (duck-typed; called by the executor before execute).
     *
     * @param skill_introspection_provider_interface $provider
     * @return void
     */
    public function set_introspection_provider(skill_introspection_provider_interface $provider): void {
        $this->introspection = $provider;
    }

    /**
     * Return skill name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::SKILL_NAME;
    }

    /**
     * Return skill schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'The menu: list everything this assistant can do (all skills/commands), no query needed. Use this '
                . 'when the user asks what the agent CAN DO or which agent skills/commands exist.',
            'is' => 'The full menu of agent skills.',
            'not' => 'One specific capability question (search_skills); listing data or entities.',
            'readonly' => true,
            'example_utterances' => [
                'what can you do',
                'what are you capable of',
                'list the actions this agent supports',
                'which commands do you understand',
                'show me everything you can help with',
            ],
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'Optional original user question for language detection and phrasing.',
                    'required' => false,
                    'from_user_message' => true,
                ],
                'scope' => [
                    'type' => 'string',
                    'description' => 'Filter scope: all (default), readonly, or mutating.',
                    'required' => false,
                ],
                'detail' => [
                    'type' => 'string',
                    'description' => 'names (default): the short overview, one line per skill with its name only, grouped '
                        . 'by provider and read/write. full: every skill with its complete description - when the user '
                        . 'wants to know what the skills do, or one of them is to be chosen from the list next.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code override for the user-facing summary, e.g. de or en.',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Return example input for planner contract rendering.
     *
     * @return array
     */
    public function get_example_input(): array {
        return [
            'scope' => 'all',
            'question' => 'What can you do?',
        ];
    }

    /**
     * Return skill-specific message triggers.
     *
     * @return array[]
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'wizard.list_skills_request',
                'description' => 'User asks which actions/skills the booking agent can perform.',
            ],
            [
                'id' => 'wizard.list_skills_scope_filter',
                'description' => 'User asks for only readonly or only mutating actions.',
            ],
            [
                'id' => 'wizard.list_skills_full_detail',
                'description' => 'User wants every skill explained, not just the list of names.',
            ],
        ];
    }

    /**
     * Check skill input structure.
     *
     * @param array $input
     * @return array{valid:bool,errors:string[],ambiguities:string[]}
     */
    public function check_structure(array $input): array {
        $errors = [];
        $issuecodes = [];
        $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
        $allowed = ['all', 'readonly', 'mutating'];
        if (!in_array($scope, $allowed, true)) {
            $errors[] = get_string('agent_booking_list_actions_scope_invalid', 'bookingextension_agent');
            $issuecodes[] = 'RECOVERABLE_INPUT_ERROR';
        }
        $detail = strtolower(trim((string)($input['detail'] ?? self::DETAIL_NAMES)));
        if (!in_array($detail, [self::DETAIL_NAMES, self::DETAIL_FULL], true)) {
            $errors[] = get_string('agent_booking_list_actions_detail_invalid', 'bookingextension_agent');
            $issuecodes[] = 'RECOVERABLE_INPUT_ERROR';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
            'issue_codes' => array_values(array_unique($issuecodes)),
        ];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'booking.introspection',
                'triggers' => [
                    'list properties', 'editable fields', 'which fields', 'which settings', 'list actions',
                    'what can you do', 'list of all settings', 'which settings',
                    'which fields', 'which actions', 'what can you do',
                ],
                'guidance' => [
                    '- If user asks which actions/skills are supported, use this introspection skill.',
                    '- If user asks for concrete entities (users, courses, options, etc.), route to a dedicated search/list skill.',
                    '- Keep introspection questions separate from entity lookup questions.',
                ],
            ],
        ];
    }

    /**
     * Execute skill.
     *
     * @param array $input
     * @param int $contextid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $contextid, int $userid): array {
        $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
        $detail = strtolower(trim((string)($input['detail'] ?? self::DETAIL_NAMES)));
        if ($detail !== self::DETAIL_FULL) {
            $detail = self::DETAIL_NAMES;
        }

        // Introspection (registry + executability evaluation) is engine machinery — it is injected by
        // the executor as a contract; only the localized deny-reason label is a presentation concern
        // and stays here.
        $listing = ($this->introspection ?? new skill_introspection_service())
            ->list_actions($userid, $contextid, $scope);
        $actions = $listing['available'];
        $unavailableactions = [];
        foreach ($listing['unavailable'] as $unavailable) {
            $unavailable['deny_reason_label'] = $this->describe_deny_reason((string)($unavailable['deny_reason'] ?? ''));
            $unavailableactions[] = $unavailable;
        }

        $capabilities = $this->build_user_capabilities($actions);

        $summary = $this->build_user_summary($scope, $capabilities, $unavailableactions, $detail === self::DETAIL_FULL);

        $debugmessage = $this->build_debug_summary($scope, $actions, $capabilities, $unavailableactions);

        // Planner-facing observation: the SAME compact catalog text the no-embeddings (slim_all) path
        // uses, so a "what can you do" turn hands the planner the full skill list without the verbose
        // dump that previously bloated the follow-up selection prompt into a gateway timeout (thread
        // 565). The user-facing answer stays the grouped summary above. Engine-agnostic: the slim
        // catalog comes via the injected introspection provider, not from engine internals here.
        // Two detail levels (George, 2026-09-30, thread 23502): the overview the user asked for is the list of
        // names - 91 selector cards (49 KB of IS/NOT/WHEN lines) handed to the synchronizer as the "result" made
        // it answer with a one-line stub instead of rendering anything. The full catalog stays available as
        // detail=full, for a turn that will pick a skill from the list next.
        if ($detail === self::DETAIL_FULL) {
            $observation = ($this->introspection ?? new skill_introspection_service())
                ->render_full_skill_catalog($userid, $contextid, $scope);
        } else {
            $observation = $this->render_names_catalog($capabilities);
        }
        return [
            'status' => 'executed',
            'detail' => $summary,
            'resultid' => null,
            'usermessage' => $summary,
            'debugmessage' => $debugmessage,
            'detail_level' => $detail,
            'capabilities' => $capabilities,
            'actions' => $actions,
            'unavailable_actions' => $unavailableactions,
            'observation_full' => $observation,
        ];
    }

    /**
     * Render the names-only catalog: one line per skill, grouped by provider and readonly/mutating.
     *
     * @param array[] $capabilities Provider blocks from build_user_capabilities().
     * @return string
     */
    private function render_names_catalog(array $capabilities): string {
        $lines = [];
        foreach ($capabilities as $providerblock) {
            $provider = trim((string)($providerblock['provider'] ?? ''));
            $groups = (array)($providerblock['groups'] ?? []);
            foreach (['readonly' => 'readonly', 'write' => 'mutating'] as $accesslevel => $mutability) {
                $entries = (array)($groups[$accesslevel] ?? []);
                if (empty($entries)) {
                    continue;
                }
                $lines[] = '## ' . ($provider !== '' ? $provider : 'unknown') . ' [' . $mutability . ']';
                foreach ($entries as $capability) {
                    $skillname = trim((string)($capability['skill'] ?? ''));
                    if ($skillname === '') {
                        $skillname = trim((string)($capability['label'] ?? ''));
                    }
                    if ($skillname !== '') {
                        $lines[] = '- ' . $skillname;
                    }
                }
                $lines[] = '';
            }
        }
        return trim(implode("\n", $lines));
    }

    /**
     * Build a technical debug summary for developers.
     *
     * @param string $scope
     * @param array $actions
     * @param array $capabilities
     * @param array $unavailableactions
     * @return string
     */
    private function build_debug_summary(
        string $scope,
        array $actions,
        array $capabilities,
        array $unavailableactions = []
    ): string {
        $lines = [
            'Skill: ' . self::SKILL_NAME,
            'Scope: ' . $scope,
            'Returned actions: ' . count($actions),
            'Provider groups: ' . count($capabilities),
            'Unavailable actions: ' . count($unavailableactions),
        ];
        return implode("\n", $lines);
    }

    /**
     * Build a user-facing summary sentence for the selected scope.
     *
     * @param string $scope
     * @param array[] $capabilities
     * @param array[] $unavailableactions
     * @param bool $withdescriptions Append each skill's description (detail=full) or list the names only.
     * @return string
     */
    private function build_user_summary(
        string $scope,
        array $capabilities,
        array $unavailableactions = [],
        bool $withdescriptions = true
    ): string {
        if (empty($capabilities)) {
            $summary = get_string('ai_list_actions_summary_none', 'bookingextension_agent');
        } else if ($scope === 'readonly') {
            $summary = get_string('ai_list_actions_summary_readonly', 'bookingextension_agent');
        } else if ($scope === 'mutating') {
            $summary = get_string('ai_list_actions_summary_mutating', 'bookingextension_agent');
        } else {
            $summary = get_string('ai_list_actions_summary_all', 'bookingextension_agent');
        }

        $lines = [$summary];
        foreach ($capabilities as $providerblock) {
            $provider = trim((string)($providerblock['provider'] ?? 'unknown'));
            $groups = (array)($providerblock['groups'] ?? []);
            if ($provider === '') {
                $provider = 'unknown';
            }

            $lines[] = $provider . ':';
            foreach (['readonly', 'write'] as $accesslevel) {
                if (!isset($groups[$accesslevel])) {
                    continue;
                }

                $lines[] = '  ' . $accesslevel . ':';
                foreach ((array)$groups[$accesslevel] as $capability) {
                    $skilllabel = trim((string)($capability['label'] ?? ''));
                    $description = trim((string)($capability['description'] ?? ''));
                    $skillname = trim((string)($capability['skill'] ?? ''));

                    $line = '    - ';
                    if ($skilllabel !== '') {
                        $line .= $skilllabel;
                    } else if ($skillname !== '') {
                        $line .= $skillname;
                    } else {
                        $line .= get_string('ai_list_actions_summary_none', 'bookingextension_agent');
                    }

                    if ($withdescriptions && $description !== '') {
                        $line .= ': ' . $description;
                    }

                    $lines[] = $line;
                }
            }
        }

        if (!empty($unavailableactions)) {
            $lines[] = '';
            $lines[] = get_string('ai_list_actions_summary_unavailable_heading', 'bookingextension_agent');
            foreach ($unavailableactions as $action) {
                $skillname = trim((string)($action['skill'] ?? ''));
                $reason = trim((string)($action['deny_reason_label'] ?? ''));
                $reasoncode = trim((string)($action['deny_reason'] ?? ''));
                $detail = $this->build_unavailable_action_detail((array)$action);
                $line = '  - ' . ($skillname !== '' ? $skillname : 'unknown skill');
                if ($reason !== '') {
                    $line .= ': ' . $reason;
                } else if ($reasoncode !== '') {
                    $line .= ': ' . $reasoncode;
                }
                if ($detail !== '') {
                    $line .= ' (' . $detail . ')';
                }
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Return a short human-readable description for a deny reason.
     *
     * @param string $reason
     * @return string
     */
    private function describe_deny_reason(string $reason): string {
        return match ($reason) {
            skill_contract_validator::DENY_RUNTIME_DISABLED => get_string(
                'ai_list_actions_unavailable_runtime_disabled',
                'bookingextension_agent'
            ),
            skill_contract_validator::DENY_INACTIVE => get_string(
                'ai_list_actions_unavailable_inactive',
                'bookingextension_agent'
            ),
            skill_contract_validator::DENY_MISSING_CAPABILITY => get_string(
                'ai_list_actions_unavailable_missing_capability',
                'bookingextension_agent'
            ),
            skill_contract_validator::DENY_CONTEXT_INVALID => get_string(
                'ai_list_actions_unavailable_context_invalid',
                'bookingextension_agent'
            ),
            skill_contract_validator::DENY_SKILL_VERSION_UNSUPPORTED => get_string(
                'ai_list_actions_unavailable_version_unsupported',
                'bookingextension_agent'
            ),
            default => get_string('ai_list_actions_unavailable_unknown', 'bookingextension_agent'),
        };
    }

    /**
     * Build a compact technical detail string for an unavailable action.
     *
     * @param array $action
     * @return string
     */
    private function build_unavailable_action_detail(array $action): string {
        $diagnostics = (array)($action['diagnostics'] ?? []);
        $details = [];

        $requiredcapabilities = array_values(array_filter(array_map(
            'strval',
            (array)($diagnostics['required_capabilities'] ?? [])
        )));
        if (!empty($requiredcapabilities)) {
            $details[] = 'required=' . implode(', ', $requiredcapabilities);
        }

        if (array_key_exists('active', $diagnostics) && $diagnostics['active'] === false) {
            $details[] = 'platform disabled';
        }

        if (array_key_exists('contextid', $diagnostics)) {
            $details[] = 'contextid=' . (string)$diagnostics['contextid'];
        }

        return implode('; ', $details);
    }

    /**
     * Build user-friendly capability blocks grouped by provider and read/write state.
     *
     * @param array[] $actions
     * @return array[]
     */
    private function build_user_capabilities(array $actions): array {
        $grouped = [];

        foreach ($actions as $action) {
            $provider = trim((string)($action['provider'] ?? 'unknown'));
            if ($provider === '') {
                $provider = 'unknown';
            }

            $readonly = !empty($action['readonly']) ? 'readonly' : 'write';
            if (!isset($grouped[$provider])) {
                $grouped[$provider] = [
                    'provider' => $provider,
                    'groups' => [
                        'readonly' => [],
                        'write' => [],
                    ],
                ];
            }

            $grouped[$provider]['groups'][$readonly][] = [
                'skill' => (string)($action['skill'] ?? ''),
                'label' => (string)($action['label'] ?? ''),
                'description' => (string)($action['description'] ?? ''),
            ];
        }

        ksort($grouped);
        foreach ($grouped as &$providerblock) {
            foreach (['readonly', 'write'] as $accesslevel) {
                usort(
                    $providerblock['groups'][$accesslevel],
                    static function (array $left, array $right): int {
                        $leftlabel = trim((string)($left['label'] ?? ''));
                        $rightlabel = trim((string)($right['label'] ?? ''));
                        $leftskill = trim((string)($left['skill'] ?? ''));
                        $rightskill = trim((string)($right['skill'] ?? ''));

                        $leftkey = $leftlabel !== '' ? $leftlabel : $leftskill;
                        $rightkey = $rightlabel !== '' ? $rightlabel : $rightskill;

                        return strcmp($leftkey, $rightkey);
                    }
                );
            }
        }
        unset($providerblock);

        return array_values($grouped);
    }
}
