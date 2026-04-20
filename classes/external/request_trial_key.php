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
 * External function: request a Wunderbyte free-trial AI key in one click.
 *
 * Flow:
 *  1. Admin triggers this from the onboarding panel (JS AJAX call).
 *  2. We generate a one-time nonce and store it in a 120-second MUC cache.
 *  3. We POST { wwwroot, nonce } to the Wunderbyte trial endpoint.
 *  4. The Wunderbyte server back-channels a GET to /mod/booking/trial_challenge.php?token={nonce}
 *     to verify the request really comes from the declared wwwroot.
 *  5. If verified and no prior trial exists for this site, the server issues a
 *     LiteLLM virtual key and returns { apikey, endpoint, model }.
 *  6. We find or create a "Wunderbyte" provider instance in core_ai and configure it.
 *  7. We enable AI tools on the course that contains the booking activity.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use cache;
use context_module;
use context_system;
use core\di;
use core\http_client;
use core_ai\aiactions\generate_text;
use core_ai\manager as ai_manager;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Request a Wunderbyte free-trial AI key and configure provider credentials.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class request_trial_key extends external_api {
    /** URL of the Wunderbyte trial issuing endpoint. */
    public const TRIAL_ENDPOINT = 'https://llm.wunderbyte.at/api/moodle-trial';

    /** Public base URL used by Moodle for inference requests. */
    public const PROVIDER_BASE_URL = 'https://llm.wunderbyte.at';

    /** Provider name stored in ai_providers.name so we can find it later. */
    public const PROVIDER_NAME = 'Wunderbyte';

    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course-module id of the booking instance.'),
        ]);
    }

    /**
     * Request a trial key and configure core_ai.
     *
     * @param int $cmid
     * @return array
     */
    public static function execute(int $cmid): array {
        global $CFG, $DB;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        $cmid = $params['cmid'];

        // Only platform admins may trigger this.
        require_capability('moodle/site:config', context_system::instance());

        $context = context_module::instance($cmid);
        self::validate_context($context);

        // ----------------------------------------------------------------
        // 1. Generate nonce, store in short-lived cache (120 s).
        // ----------------------------------------------------------------
        $nonce = bin2hex(random_bytes(16));
        $cache = cache::make('mod_booking', 'trialnonce');
        $cache->set('nonce_' . $nonce, $nonce);

        // ----------------------------------------------------------------
        // 2. Send POST to Wunderbyte trial endpoint.
        // ----------------------------------------------------------------
        $payload = json_encode([
            'wwwroot' => $CFG->wwwroot,
            'nonce'   => $nonce,
        ]);

        try {
            $client = di::get(http_client::class);
            $response = $client->post(self::TRIAL_ENDPOINT, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                'body'    => $payload,
                'timeout' => 20,
            ]);

            $body = (string) $response->getBody();
            $data = json_decode($body, true);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => get_string('aitrial_support_firewall', 'mod_booking')
                . PHP_EOL
                . PHP_EOL
                . $e->getMessage(),
            ];
        }

        // ----------------------------------------------------------------
        // 3. Validate server response.
        // ----------------------------------------------------------------
        if (empty($data['apikey'])) {
            $servermsg = $data['message'] ?? $data['error'] ?? get_string('aitrial_unexpected_response', 'mod_booking');

            if (preg_match('/token\s+expired|expired\s+token/i', (string)$servermsg)) {
                $prolicenseurl = get_string('aitrial_pro_license_url', 'mod_booking');
                return [
                    'success' => false,
                    'message' => get_string('aitrial_token_expired_subscription', 'mod_booking', $prolicenseurl),
                ];
            }

            return ['success' => false, 'message' => $servermsg];
        }

        $apikey = (string) $data['apikey'];
        $model  = (string) ($data['model'] ?? 'wunderbyte-trial');

        // ----------------------------------------------------------------
        // 4. Find or create the "Wunderbyte" core_ai provider instance.
        // aiprovider_openai speaks OpenAI-compatible REST (which LiteLLM also implements).
        // ----------------------------------------------------------------
        if (!class_exists('\\core_ai\\manager')) {
            return ['success' => false, 'message' => get_string('aitrial_coreai_unavailable', 'mod_booking')];
        }

        $manager = di::get(ai_manager::class);
        $providerclassname = 'aiprovider_openai\\provider';

        // Find existing "Wunderbyte" provider instance by name.
        $existingrecord = $DB->get_record(
            'ai_providers',
            ['name' => self::PROVIDER_NAME, 'provider' => $providerclassname],
            '*',
            IGNORE_MISSING
        );

        $actionconfig = [
            generate_text::class => [
                'enabled'  => true,
                'settings' => [
                    'endpoint'          => self::PROVIDER_BASE_URL . '/chat/completions',
                    'model'             => $model,
                    'systeminstruction' => '',
                ],
            ],
        ];

        $providerconfig = [
            'apikey' => $apikey,
        ];

        if ($existingrecord) {
            // Update the existing record with the new key and endpoint.
            $instances = $manager->get_provider_instances(['id' => $existingrecord->id]);
            $provider  = reset($instances);
            $provider  = $manager->update_provider_instance(
                provider:     $provider,
                config:       $providerconfig,
                actionconfig: $actionconfig,
            );
            if (!$provider->enabled) {
                $manager->enable_provider_instance($provider);
            }
        } else {
            // Create a brand new provider instance.
            $provider = $manager->create_provider_instance(
                classname:    $providerclassname,
                name:         self::PROVIDER_NAME,
                enabled:      true,
                config:       $providerconfig,
                actionconfig: $actionconfig,
            );
        }

        // Flush plugin-manager caches so provider changes are immediately visible.
        \core_plugin_manager::reset_caches();

        return [
            'success' => true,
            'message' => get_string('aitrial_token_received', 'mod_booking'),
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether the trial was successfully activated.'),
            'message' => new external_value(PARAM_TEXT, 'Status message.'),
        ]);
    }
}
