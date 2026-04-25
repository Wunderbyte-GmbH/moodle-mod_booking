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
 * Task definition for booking.list_option_properties.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_option_properties_task extends base_booking_task {
    /** Task name constant. */
    public const TASK_NAME = 'booking.list_option_properties';

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
            'description' => 'List booking option properties derived from create/update task schemas.',
            'readonly' => $this->is_read_only(),
            'properties' => [
                'scope' => [
                    'type' => 'string',
                    'description' => 'Filter scope: all (default), create, update, or shared.',
                    'required' => false,
                ],
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
        $allowed = ['all', 'create', 'update', 'shared'];
        if (!in_array($scope, $allowed, true)) {
            $errors[] = 'Field "scope" must be one of: all, create, update, shared.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'ambiguities' => [],
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
        $registry = task_registry::make_default();
        $createtask = $registry->get_task(create_option_task::TASK_NAME);
        $updatetask = $registry->get_task(update_option_task::TASK_NAME);

        if (!$createtask || !$updatetask) {
            return ['status' => 'error', 'detail' => 'Required task schemas are unavailable.', 'resultid' => null];
        }

        $createschema = $createtask->get_schema();
        $updateschema = $updatetask->get_schema();
        $createproperties = (array)($createschema['properties'] ?? []);
        $updateproperties = (array)($updateschema['properties'] ?? []);

        $scope = strtolower(trim((string)($input['scope'] ?? 'all')));
        $keys = array_values(array_unique(array_merge(array_keys($createproperties), array_keys($updateproperties))));
        sort($keys);

        $properties = [];
        foreach ($keys as $key) {
            $increate = array_key_exists($key, $createproperties);
            $inupdate = array_key_exists($key, $updateproperties);

            if ($scope === 'create' && !$increate) {
                continue;
            }
            if ($scope === 'update' && !$inupdate) {
                continue;
            }
            if ($scope === 'shared' && !($increate && $inupdate)) {
                continue;
            }

            $source = $createproperties[$key] ?? $updateproperties[$key] ?? [];
            $properties[] = [
                'name' => (string)$key,
                'label' => booking_task_support::get_localized_property_label_for_output((string)$key),
                'type' => (string)($source['type'] ?? 'mixed'),
                'description' => (string)($source['description'] ?? ''),
                'increate' => $increate,
                'inupdate' => $inupdate,
                'requiredoncreate' => (bool)($createproperties[$key]['required'] ?? false),
                'requiredonupdate' => (bool)($updateproperties[$key]['required'] ?? false),
            ];
        }

        return [
            'status' => 'executed',
            'detail' => '',
            'resultid' => null,
            'properties' => $properties,
            'debugmessage' => $this->build_task_debug_message(
                self::TASK_NAME,
                $input,
                ['Properties returned: ' . count($properties)]
            ),
        ];
    }
}
