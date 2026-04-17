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
use mod_booking\local\wbagent\task_registry;

/**
 * Task definition for booking.list_actions.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_actions_task extends base_booking_task {
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
     * @return array<string,mixed>
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'List supported booking AI actions/tasks derived from registered task schemas.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'scope' => [
                    'type' => 'string',
                    'description' => 'Filter scope: all (default), readonly, or mutating.',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Validate task input.
     *
     * @param array<string,mixed> $input
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
     * @param array<string,mixed> $input
     * @param int $cmid
     * @param int $userid
     * @return array<string,mixed>
     */
    public function execute(array $input, int $cmid, int $userid): array {
        $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
        $actions = [];
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
            $actions[] = [
                'task' => $name,
                'label' => booking_task_support::get_localized_action_label_for_output($name),
                'description' => (string)($schema['description'] ?? ''),
                'readonly' => $task->is_read_only(),
            ];
        }

        return [
            'status' => 'executed',
            'detail' => '',
            'resultid' => null,
            'actions' => $actions,
        ];
    }
}
