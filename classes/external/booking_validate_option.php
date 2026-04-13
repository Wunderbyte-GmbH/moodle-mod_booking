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
 * External service: dry-run validation for booking option mutations (no side effects).
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use context_module;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use mod_booking\local\wbagent\authorization_service;
use mod_booking\local\wbagent\dto\bulk_update_options_input_dto;
use mod_booking\local\wbagent\dto\create_option_input_dto;
use mod_booking\local\wbagent\dto\update_option_input_dto;
use mod_booking\local\wbagent\services\option_mutation_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Validate a booking option mutation without executing it (dry-run endpoint).
 *
 * Returns validation errors and ambiguities.  No records are created or modified.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_validate_option extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'   => new external_value(PARAM_INT, 'Course-module id.'),
            'task'   => new external_value(PARAM_ALPHANUMEXT, 'Mutation type: create, update, or bulk_update.'),
            'fields' => new external_value(PARAM_RAW, 'JSON-encoded option fields to validate.'),
        ]);
    }

    /**
     * Validate a mutation without executing it.
     *
     * @param int    $cmid
     * @param string $task   One of: create, update, bulk_update.
     * @param string $fields JSON-encoded fields.
     * @return array
     */
    public static function execute(int $cmid, string $task, string $fields): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'   => $cmid,
            'task'   => $task,
            'fields' => $fields,
        ]);

        $authz = new authorization_service();
        $authz->require_valid_context($params['cmid']);
        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/booking:updatebooking', $context);

        $fieldsarray = json_decode($params['fields'], true);
        if (!is_array($fieldsarray)) {
            return ['valid' => false, 'errors' => ['Invalid JSON in fields parameter.'], 'ambiguities' => []];
        }

        $service = new option_mutation_service();

        if ($params['task'] === 'create') {
            try {
                $dto = create_option_input_dto::from_array($fieldsarray);
            } catch (\InvalidArgumentException $e) {
                return ['valid' => false, 'errors' => [$e->getMessage()], 'ambiguities' => []];
            }
            $result = $service->validate_create($dto, $params['cmid']);
        } else if ($params['task'] === 'update') {
            $dto    = update_option_input_dto::from_array($fieldsarray);
            $result = $service->validate_update($dto, $params['cmid']);
        } else if ($params['task'] === 'bulk_update') {
            $dto    = bulk_update_options_input_dto::from_array($fieldsarray);
            $result = $service->validate_bulk_update($dto, $params['cmid']);
        } else {
            return [
                'valid'       => false,
                'errors'      => ["Unknown task type: {$params['task']}. Use create, update, or bulk_update."],
                'ambiguities' => [],
            ];
        }

        return [
            'valid'       => (bool)($result['valid'] ?? false),
            'errors'      => array_values(array_map('strval', (array)($result['errors'] ?? []))),
            'ambiguities' => array_values(array_map('strval', (array)($result['ambiguities'] ?? []))),
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'valid'       => new external_value(PARAM_BOOL, 'Whether the input is valid.'),
            'errors'      => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Error message.'),
                'Validation errors.',
                VALUE_DEFAULT,
                []
            ),
            'ambiguities' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Ambiguity message.'),
                'Ambiguous inputs that need clarification.',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
