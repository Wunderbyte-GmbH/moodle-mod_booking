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
 * External service: request a trial key challenge nonce.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\external;

use context_system;
use core\context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use bookingextension_agent\local\wizard\services\security\authorization_service;
use bookingextension_agent\local\wizard\services\trial\trial_provisioner;

/**
 * Request a Wunderbyte trial key and provision a working AI provider from it.
 */
class request_trial_key extends external_api {
    /**
     * Describe input parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Module context id.'),
            'consented' => new external_value(
                PARAM_BOOL,
                'Whether the user confirmed the data-protection consent in the trial modal.',
                VALUE_DEFAULT,
                false
            ),
            'strategy' => new external_value(
                PARAM_ALPHA,
                'Chosen provider path: "wunderbyte" (full) or "openai" (standard, reduced). Empty = auto-detect.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Request a trial key and provision the AI provider instance from it.
     *
     * @param int $contextid
     * @param bool $consented
     * @param string $strategy
     * @return array
     */
    public static function execute(int $contextid, bool $consented = false, string $strategy = ''): array {
        global $USER;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'consented' => $consented,
            'strategy' => $strategy,
        ]);

        $authz = new authorization_service();
        if ($problem = $authz->check_use_readiness((int)$USER->id, (int)$params['contextid'])) {
            return ['success' => false, 'message' => $problem['message']];
        }
        $context = context::instance_by_id((int)$params['contextid'], MUST_EXIST);
        self::validate_context($context);
        // Managers may onboard too; admins pass via moodle/site:doanything.
        require_capability('bookingextension/agent:requesttrial', context_system::instance());

        // GDPR gate: the trial may only be provisioned once the user has confirmed the
        // data-protection consent shown in the modal. Refuse otherwise (defends against
        // direct web-service calls that bypass the UI).
        if (empty($params['consented'])) {
            return [
                'success' => false,
                'message' => get_string('aitrial_consent_required', 'bookingextension_agent'),
            ];
        }

        // Record the consent server-side as a durable audit trail before provisioning.
        \bookingextension_agent\event\trial_consent_given::create([
            'context' => $context,
            'userid' => (int)$USER->id,
        ])->trigger();

        // Full provisioning: mint the trial key and create/enable the provider instance.
        // The UI sends the user's explicit path choice; an unknown/empty value falls back to
        // auto-detection inside the provisioner. Returns {success, message}.
        $strategy = in_array($params['strategy'], ['wunderbyte', 'openai'], true) ? $params['strategy'] : null;
        return (new trial_provisioner())->provision((int)$params['contextid'], $strategy);
    }

    /**
     * Describe return values.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Request status.'),
            'message' => new external_value(PARAM_RAW, 'User-facing status message.'),
        ]);
    }
}
