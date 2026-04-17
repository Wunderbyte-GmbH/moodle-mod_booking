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

namespace mod_booking\local\wbagent\booking;

use mod_booking\local\wbagent\task_registry;
use mod_booking\local\wbagent\booking\tasks\create_option_task;
use mod_booking\local\wbagent\booking\tasks\get_current_user_task;
use mod_booking\local\wbagent\booking\tasks\list_actions_task;
use mod_booking\local\wbagent\booking\tasks\list_option_properties_task;
use mod_booking\local\wbagent\booking\tasks\search_courses_task;
use mod_booking\local\wbagent\booking\tasks\search_options_task;
use mod_booking\local\wbagent\booking\tasks\update_option_task;
use mod_booking\local\wbagent\booking\tasks\search_users_task;

/**
 * Execute service for booking AI tasks.
 *
 * First extraction step: all read-only execute branches are moved here.
 * Mutating branches still run in booking_task_support and will be extracted next.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_task_execute_service {
    /**
     * Execute supported read-only tasks.
     *
     * @param string $taskname
     * @param array<string,mixed> $input
     * @param int $cmid
     * @param int $userid
     * @param booking_task_support $support
     * @return array<string,mixed>|null Null when task is not handled here.
     */
    public function execute(string $taskname, array $input, int $cmid, int $userid, booking_task_support $support): ?array {
        if ($taskname === search_options_task::TASK_NAME) {
            $query = trim((string)($input['query'] ?? ''));
            $when = trim((string)($input['when'] ?? ''));
            $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : ($query === '' ? 50 : 10);

            $rows = booking_task_support::search_option_candidates_for_preview($cmid, $query, $limit, $when);
            if (empty($rows)) {
                return [
                    'status' => 'executed',
                    'detail' => 'No matching booking options found.',
                    'resultid' => null,
                ];
            }

            $structuredoptions = [];
            foreach ($rows as $row) {
                $optionid = (int)($row['optionid'] ?? 0);
                $name = (string)($row['text'] ?? '');
                $link = booking_task_support::build_option_link_for_output($cmid, $optionid);
                $structuredoptions[] = [
                    'id' => $optionid,
                    'name' => $name,
                    'link' => $link,
                ];
            }

            return [
                'status' => 'executed',
                'detail' => 'Found ' . count($structuredoptions) . ' option(s).',
                'resultid' => (int)($rows[0]['optionid'] ?? 0),
                'previewoptionids' => array_values(array_map(
                    static fn(array $row): int => (int)($row['optionid'] ?? 0),
                    $rows
                )),
                'options' => $structuredoptions,
            ];
        }

        if ($taskname === search_users_task::TASK_NAME) {
            $query = trim((string)($input['query'] ?? ''));
            $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : 10;

            if ($query === '') {
                return ['status' => 'error', 'detail' => 'Field "query" is required.', 'resultid' => null];
            }

            $users = booking_task_support::search_user_candidates_for_preview($query, $limit);
            if (empty($users)) {
                return [
                    'status' => 'executed',
                    'detail' => 'No matching users found.',
                    'resultid' => null,
                ];
            }

            $ids = array_map(fn($row) => (int)($row['userid'] ?? 0), $users);
            return [
                'status' => 'executed',
                'detail' => 'Found users: ' . implode(', ', $ids) . '. ' . json_encode($users),
                'resultid' => (int)($users[0]['userid'] ?? 0),
            ];
        }

        if ($taskname === search_courses_task::TASK_NAME) {
            $query = trim((string)($input['query'] ?? ''));
            $limit = isset($input['limit']) ? max(1, (int)$input['limit']) : 10;

            if ($query === '') {
                return ['status' => 'error', 'detail' => 'Field "query" is required.', 'resultid' => null];
            }

            $courses = booking_task_support::search_course_candidates_for_preview($query, $limit);
            if (empty($courses)) {
                return [
                    'status' => 'executed',
                    'detail' => 'No matching courses found.',
                    'resultid' => null,
                ];
            }

            $ids = array_map(fn($row) => (int)($row['courseid'] ?? 0), $courses);
            return [
                'status' => 'executed',
                'detail' => 'Found courses: ' . implode(', ', $ids) . '. ' . json_encode($courses),
                'resultid' => (int)($courses[0]['courseid'] ?? 0),
            ];
        }

        if ($taskname === list_option_properties_task::TASK_NAME) {
            $createschema = $support->get_task_schema(create_option_task::TASK_NAME);
            $updateschema = $support->get_task_schema(update_option_task::TASK_NAME);
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
            ];
        }

        if ($taskname === list_actions_task::TASK_NAME) {
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

        if ($taskname === get_current_user_task::TASK_NAME) {
            global $USER;

            $user = $USER;
            $fullname = trim((string)$user->firstname . ' ' . (string)$user->lastname);

            return [
                'status' => 'executed',
                'detail' => 'Current user identified.',
                'resultid' => (int)$user->id,
                'userid' => (int)$user->id,
                'email' => (string)$user->email,
                'fullname' => $fullname,
            ];
        }

        return null;
    }
}
