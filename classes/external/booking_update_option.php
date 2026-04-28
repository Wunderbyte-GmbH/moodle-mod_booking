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
 * External service: update a booking option (v1 Application-Service API).
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
use mod_booking\local\wbagent\dto\update_option_input_dto;
use mod_booking\local\wbagent\services\mutation\option_mutation_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Update a booking option via the versioned Application-Service API (v1).
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booking_update_option extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'           => new external_value(PARAM_INT, 'Course-module id.'),
            'fields'         => new external_value(
                PARAM_RAW,
                'JSON-encoded option fields (optionid or optionquery required).'
            ),
            'idempotencykey' => new external_value(
                PARAM_ALPHANUMEXT,
                'Client-supplied idempotency key for safe retries.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Update a booking option.
     *
     * @param int    $cmid
     * @param string $fields         JSON-encoded update fields.
     * @param string $idempotencykey Optional client-supplied idempotency key.
     * @return array
     */
    public static function execute(int $cmid, string $fields, string $idempotencykey = ''): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'           => $cmid,
            'fields'         => $fields,
            'idempotencykey' => $idempotencykey,
        ]);

        $authz = new authorization_service();
        $authz->require_valid_context($params['cmid']);
        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability('mod/booking:updatebooking', $context);

        // Idempotency: if this key was already processed successfully, return the stored result.
        if ($params['idempotencykey'] !== '') {
            $existing = $DB->get_record('booking_ai_runs', ['idempotencykey' => $params['idempotencykey']]);
            if ($existing && $existing->status === 'completed') {
                $results = json_decode((string)($existing->resultsjson ?? ''), true);
                $resultid = (int)(is_array($results) ? ($results[0]['resultid'] ?? 0) : 0);
                return [
                    'status'   => 'skipped',
                    'detail'   => 'Already processed (idempotency key matched).',
                    'resultid' => $resultid,
                    'warnings' => [],
                ];
            }
        }

        $fieldsarray = json_decode($params['fields'], true);
        if (!is_array($fieldsarray)) {
            return ['status' => 'error', 'detail' => 'Invalid JSON in fields parameter.', 'resultid' => 0, 'warnings' => []];
        }

        $dto     = update_option_input_dto::from_array($fieldsarray);
        $service = new option_mutation_service();

        $validation = $service->validate_update($dto, $params['cmid']);
        if (!($validation['valid'] ?? false)) {
            $detail = implode('; ', array_merge(
                array_values((array)($validation['errors'] ?? [])),
                array_values((array)($validation['ambiguities'] ?? []))
            ));
            return ['status' => 'error', 'detail' => $detail, 'resultid' => 0, 'warnings' => []];
        }

        $result = $service->update_option($dto, $params['cmid'], (int)$USER->id);
        return [
            'status'   => $result->status,
            'detail'   => $result->detail,
            'resultid' => (int)($result->resultid ?? 0),
            'warnings' => array_values(array_map('strval', $result->warnings)),
        ];
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'   => new external_value(PARAM_TEXT, 'Result status (executed|error|skipped).'),
            'detail'   => new external_value(PARAM_RAW, 'Human-readable detail message.'),
            'resultid' => new external_value(PARAM_INT, 'ID of the updated option, or 0.'),
            'warnings' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Warning text.'),
                'Warnings.',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
