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
 * External service: configure the Wunderbyte provider from an existing third-party provider.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use context;
use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\services\trial\trial_provisioner;

/**
 * Fill the aiprovider_wunderbyte instance with the credentials/models of an already-configured
 * third-party provider (e.g. OpenAI), adding a default embeddings model. No trial key, no external
 * call — the existing credentials are reused locally.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class configure_provider_from_existing extends external_api {
    /**
     * Describe input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Module context id.'),
        ]);
    }

    /**
     * Configure the Wunderbyte provider from an existing provider's credentials.
     *
     * @param int $contextid
     * @return array
     */
    public static function execute(int $contextid): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), ['contextid' => $contextid]);

        $authz = new authorization_service();
        if ($problem = $authz->check_use_readiness((int)$USER->id, (int)$params['contextid'])) {
            return ['success' => false, 'message' => $problem['message']];
        }
        $context = context::instance_by_id((int)$params['contextid'], MUST_EXIST);
        self::validate_context($context);
        // Writing site-global provider credentials is admin-only by default (audit 15-F02): its own
        // capability, no automatic role assignment, delegable explicitly. Admins pass via doanything.
        require_capability('bookingextension/agent:manageaiproviders', context_system::instance());

        return (new trial_provisioner())->configure_from_existing_provider((int)$params['contextid']);
    }

    /**
     * Describe the return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the provider was configured.'),
            'message' => new external_value(PARAM_RAW, 'User-facing result message.'),
        ]);
    }
}
