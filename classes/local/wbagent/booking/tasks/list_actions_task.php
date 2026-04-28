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

namespace mod_booking\local\wbagent\booking\tasks;

use mod_booking\local\wbagent\booking\booking_task_support;
use mod_booking\local\wbagent\interfaces\task_trigger_provider_interface;
use mod_booking\local\wbagent\services\answering\list_actions_answering_service;
use mod_booking\local\wbagent\task_registry;

/**
 * Task definition for booking.list_actions.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_actions_task extends base_booking_task implements task_trigger_provider_interface {
    /** Task name constant. */
    public const TASK_NAME = 'booking.list_actions';

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(true);
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'List supported booking AI actions/tasks derived from registered task schemas.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'question' => [
                    'type' => 'string',
                    'description' => 'Optional original user question for language detection and phrasing.',
                    'required' => false,
                ],
                'scope' => [
                    'type' => 'string',
                    'description' => 'Filter scope: all (default), readonly, or mutating.',
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
     * Return task-specific message triggers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'booking.list_actions_request',
                'description' => 'User asks which actions/tasks the booking agent can perform.',
            ],
            [
                'id' => 'booking.list_actions_scope_filter',
                'description' => 'User asks for only readonly or only mutating actions.',
            ],
        ];
    }

    /**
     * Validate task input.
     *
     * @param array $input
     * @param int $cmid
     * @return array{valid:bool,errors:array<int,string>,ambiguities:array<int,string>}
     */
    public function validate(array $input, int $cmid): array {
        $errors = [];
        $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
        $allowed = ['all', 'readonly', 'mutating'];
        if (!in_array($scope, $allowed, true)) {
            $errors[] = 'Field "scope" must be one of: all, readonly, mutating.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
        ];
    }

    /**
     * Return contextual guidance packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        return [
            [
                'id' => 'booking.introspection',
                'triggers' => [
                    'list properties', 'editable fields', 'which fields', 'which settings', 'list actions',
                    'what can you do', 'liste aller einstellungen', 'welche einstellungen',
                    'welche felder', 'welche aktionen', 'was kannst du',
                ],
                'guidance' => [
                    '- If user asks which booking option properties can be created/updated, use booking.list_option_properties.',
                    '- If user asks which actions/tasks are supported, use booking.list_actions.',
                    '- Do not map these capability/introspection questions to booking.search_options.',
                ],
            ],
        ];
    }

    /**
     * Execute task.
     *
     * @param array $input
     * @param int $cmid
     * @param int $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $question = trim((string)($input['question'] ?? ''));
        $outputlang = $this->get_output_language($input);
        $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
        $actions = [];
        $selectedtasknames = [];
        $registry = task_registry::make_default();
        foreach ($registry->get_task_names() as $name) {
            if ($scope === 'readonly' && !$registry->is_read_only_task($name)) {
                continue;
            }
            if ($scope === 'mutating' && $registry->is_read_only_task($name)) {
                continue;
            }

            $task = $registry->get_task($name);
            if (!$task) {
                continue;
            }

            $schema = $task->get_schema();
            $selectedtasknames[] = $name;
            $actions[] = [
                'task' => $name,
                'label' => booking_task_support::get_localized_action_label_for_output($name),
                'description' => (string)($schema['description'] ?? ''),
                'readonly' => $task->is_read_only(),
            ];
        }

        $available = array_fill_keys($selectedtasknames, true);
        $capabilities = $this->build_user_capabilities($available);

        $summary = '';
        $answersource = 'none';
        try {
            $answeringresult = $this->create_list_actions_answering_service()->answer_question(
                $question,
                $scope,
                $capabilities,
                $actions,
                $outputlang,
                $cmid,
                $userid
            );
            $llmanswer = trim((string)($answeringresult['answer'] ?? ''));
            if ($llmanswer !== '') {
                $summary = $this->enforce_max_chars($llmanswer, 650);
                $answersource = 'llm';
            }
        } catch (\Throwable $e) {
            $answersource = 'error';
        }

        if ($summary === '') {
            $summary = $this->build_user_summary($scope, $capabilities);
            $answersource = $answersource === 'error' ? 'fallback_after_error' : 'fallback';
        }

        $debugmessage = $this->build_debug_summary($scope, $actions, $capabilities, $answersource);

        return [
            'status' => 'executed',
            'detail' => $summary,
            'resultid' => null,
            'summary' => $summary,
            'usermessage' => $summary,
            'debugmessage' => $debugmessage,
            'capabilities' => $capabilities,
            'actions' => $actions,
        ];
    }

    /**
     * Build a technical debug summary for developers.
     *
     * @param string $scope
     * @param array $actions
     * @param array $capabilities
     * @param string $answersource
     * @return string
     */
    private function build_debug_summary(string $scope, array $actions, array $capabilities, string $answersource): string {
        $lines = [
            'Task: ' . self::TASK_NAME,
            'Scope: ' . $scope,
            'Returned actions: ' . count($actions),
            'Derived capabilities: ' . count($capabilities),
            'Answer source: ' . $answersource,
        ];
        return implode("\n", $lines);
    }

    /**
     * Create the list-actions answering service.
     *
     * @return list_actions_answering_service
     */
    protected function create_list_actions_answering_service(): list_actions_answering_service {
        return new list_actions_answering_service();
    }

    /**
     * Build a user-facing summary sentence for the selected scope.
     *
     * @param string $scope
     * @param array $capabilities
     * @return string
     */
    private function build_user_summary(string $scope, array $capabilities): string {
        $intro = '';

        if (empty($capabilities)) {
            return get_string('ai_list_actions_summary_none', 'mod_booking');
        }

        if ($scope === 'readonly') {
            $intro = get_string('ai_list_actions_summary_readonly', 'mod_booking');
        } else if ($scope === 'mutating') {
            $intro = get_string('ai_list_actions_summary_mutating', 'mod_booking');
        } else {
            $intro = get_string('ai_list_actions_summary_all', 'mod_booking');
        }

        $lines = array_map(static function (array $capability): string {
            $title = trim((string)($capability['title'] ?? ''));
            $description = trim((string)($capability['description'] ?? ''));

            if ($title !== '' && $description !== '') {
                return '- ' . $title . ': ' . $description;
            }

            if ($title !== '') {
                return '- ' . $title;
            }

            if ($description !== '') {
                return '- ' . $description;
            }

            return '';
        }, $capabilities);

        $lines = array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
        if (empty($lines)) {
            return $intro;
        }

        return $intro . "\n" . implode("\n", $lines);
    }

    /**
     * Build user-friendly capability blocks from the currently selected task set.
     *
     * @param array $available
     * @return array<int,array<string,string>>
     */
    private function build_user_capabilities(array $available): array {
        $capabilities = [];

        if (
            isset($available[create_option_task::TASK_NAME])
            || isset($available[update_option_task::TASK_NAME])
            || isset($available[bulk_update_options_task::TASK_NAME])
        ) {
            $capabilities[] = [
                'title' => get_string('ai_capability_manage_options_title', 'mod_booking'),
                'description' => get_string('ai_capability_manage_options_desc', 'mod_booking'),
            ];
        }

        if (isset($available[search_options_task::TASK_NAME])) {
            $capabilities[] = [
                'title' => get_string('ai_capability_search_options_title', 'mod_booking'),
                'description' => get_string('ai_capability_search_options_desc', 'mod_booking'),
            ];
        }

        if (isset($available[search_users_task::TASK_NAME]) || isset($available[search_courses_task::TASK_NAME])) {
            $capabilities[] = [
                'title' => get_string('ai_capability_search_people_courses_title', 'mod_booking'),
                'description' => get_string('ai_capability_search_people_courses_desc', 'mod_booking'),
            ];
        }

        if (
            isset($available[list_option_properties_task::TASK_NAME])
            || isset($available[self::TASK_NAME])
        ) {
            $capabilities[] = [
                'title' => get_string('ai_capability_explain_setup_title', 'mod_booking'),
                'description' => get_string('ai_capability_explain_setup_desc', 'mod_booking'),
            ];
        }

        if (isset($available[add_price_category_task::TASK_NAME])) {
            $capabilities[] = [
                'title' => get_string('ai_capability_pricing_title', 'mod_booking'),
                'description' => get_string('ai_capability_pricing_desc', 'mod_booking'),
            ];
        }

        if (isset($available[get_current_user_task::TASK_NAME])) {
            $capabilities[] = [
                'title' => get_string('ai_capability_user_context_title', 'mod_booking'),
                'description' => get_string('ai_capability_user_context_desc', 'mod_booking'),
            ];
        }

        return $capabilities;
    }
}
