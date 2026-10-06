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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services\trial;

use cache;
use core\http_client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Provisions a working AI provider from a Wunderbyte trial key.
 *
 * This closes the gap the old flow left open: requesting a trial used to only
 * cache a nonce and claim success, while no LiteLLM key was ever minted and no
 * AI provider instance was created. Here we run the full chain:
 *
 *   nonce  ->  POST {base}/api/moodle-trial  ->  {apikey, endpoint, model}
 *          ->  core_ai create/enable provider instance (config + actionconfig)
 *
 * The Wunderbyte trial service verifies the request origin by calling back to
 * trial_challenge.php?token={nonce} (see that file), so the nonce must be cached
 * before the POST. Endpoint is always the LiteLLM proxy, hard-coded to
 * https://llm.wunderbyte.at (see self::BASE_URL).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class trial_provisioner {
    /** @var string Hard-coded LiteLLM/trial service base URL (intentionally not an admin setting). */
    private const BASE_URL = 'https://llm.wunderbyte.at';

    /** @var string Role-aware chat alias: the agent's phases on the Wunderbyte provider (gateway may add JSON mode per role). */
    private const MODEL_CHAT = 'wunderbyte-privat';

    /** @var string Role-aware compact alias for the selector (planner_decide). */
    private const MODEL_CHAT_MINI = 'wunderbyte-privat-mini';

    /**
     * @var string Role-less alias for a trial on the core OpenAI provider: no request role reaches the gateway there, so
     * it must route to a model that keeps the planner JSON intact without JSON mode (Wunderbyte-GmbH/Wunderbyte-GmbH#2541).
     */
    private const MODEL_TRIAL_ROLELESS = 'wunderbyte-trial';

    /** @var string Provider instance name shared by both strategies (also the legacy OpenAI name). */
    private const INSTANCE_NAME = 'Wunderbyte';

    /** @var int Seconds to wait for the trial service (its own back-channel + LiteLLM call take a moment). */
    private const HTTP_TIMEOUT = 25;


    /**
     * Run the full trial provisioning for the given context.
     *
     * @param int $contextid Page/module context the trial was started from (used only for messaging/audit).
     * @param string|null $strategy 'wunderbyte' | 'openai'; null = auto-detect from installed providers.
     * @return array{success: bool, message: string} User-facing result.
     */
    public function provision(int $contextid, ?string $strategy = null): array {
        if (!class_exists('\\core_ai\\manager')) {
            return $this->fail(get_string('aitrial_coreai_unavailable', 'bookingextension_agent'));
        }

        $strategy = $strategy ?? $this->detect_strategy();
        if ($strategy === null) {
            // No usable provider plugin installed: point the admin at the Wunderbyte provider.
            $url = get_string('aitrial_provider_install_url', 'bookingextension_agent');
            return $this->fail(get_string('aitrial_provider_required', 'bookingextension_agent', $url));
        }

        // 1. Mint a nonce and cache it so the trial service's origin check (trial_challenge.php) succeeds.
        $nonce = random_string(32);
        $cache = cache::make('bookingextension_agent', 'trialnonce');
        $cache->set('nonce_' . $nonce, $nonce);

        // 2. Exchange the nonce for an API key at the trial endpoint.
        $exchange = $this->exchange_nonce($nonce);
        if (!$exchange['success']) {
            return $this->fail($exchange['message']);
        }

        // 3. Create (or repair) the provider instance with the returned key + endpoint.
        try {
            $this->upsert_provider_instance(
                $strategy,
                (string)$exchange['apikey'],
                (string)$exchange['endpoint'],
            );
        } catch (\Throwable $e) {
            debugging('trial_provisioner: provider instance creation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $this->fail(
                get_string('aitrial_provision_failed', 'bookingextension_agent'),
                'provider instance creation failed: ' . $e->getMessage()
            );
        }

        return [
            'success' => true,
            'message' => get_string('aitrial_provider_created', 'bookingextension_agent'),
        ];
    }

    /**
     * Configure the Wunderbyte provider from an already-configured third-party provider.
     *
     * Reuses the existing (non-Wunderbyte) provider's API key, chat endpoint and chat model for the
     * agent's custom Wunderbyte aiactions, and adds an embeddings model matched to the cloned endpoint
     * (wunderbyte-embeddings when it points at the Wunderbyte LLM, otherwise text-embedding-3-small).
     * No trial key and no call to llm.wunderbyte.at — the user's existing credentials are
     * reused locally. The full skill set still requires a PRO licence or the Wunderbyte LLM endpoint;
     * a third-party endpoint keeps the read-only restriction (by design).
     *
     * @param int $contextid
     * @return array{success: bool, message: string}
     */
    public function configure_from_existing_provider(int $contextid): array {
        unset($contextid);

        $source = null;
        foreach (\bookingextension_agent\local\wizard\services\provider_compat::get_provider_views() as $instance) {
            if (empty($instance->enabled)) {
                continue;
            }
            // Skip only ACTUAL Wunderbyte provider instances. A provider of another
            // type (e.g. OpenAI) whose endpoint merely points at the Wunderbyte LLM is
            // exactly what we want to clone the key from, so it must NOT be excluded.
            if (strpos((string)($instance->provider ?? ''), 'aiprovider_wunderbyte') !== false) {
                continue;
            }
            $settings = $instance->actionconfig['core_ai\\aiactions\\generate_text']['settings'] ?? [];
            if (empty($instance->config['apikey']) || empty($settings['endpoint'])) {
                continue;
            }
            $source = $instance;
            break;
        }

        if ($source === null) {
            return $this->fail(get_string('aitrial_clone_no_source', 'bookingextension_agent'));
        }

        $settings = $source->actionconfig['core_ai\\aiactions\\generate_text']['settings'] ?? [];
        $apikey = (string)$source->config['apikey'];
        $chatendpoint = (string)$settings['endpoint'];
        $chatmodel = (string)($settings['model'] ?? '');
        // Moodle 5.x keeps the instance name on the instance itself; config['name'] is the 4.5-shaped view.
        $sourcename = trim((string)($source->name ?? $source->config['name'] ?? ''));
        if ($sourcename === '') {
            $sourcename = (string)$source->provider;
        }

        $this->upsert_wunderbyte_from_clone($apikey, $chatendpoint, $chatmodel);

        // Existing Wunderbyte access (a trial or a purchased key on another provider type) now lives in the
        // Wunderbyte provider: switch the old instance off so only one Wunderbyte connection is active. It is kept,
        // not deleted, and a third-party provider that merely lent its key is left alone (#2569).
        $existingaccess = \bookingextension_agent\local\wizard\services\agent_access_service::instance_targets_wunderbyte_llm(
            $source
        );
        if ($existingaccess && $source instanceof \core_ai\provider) {
            \core\di::get(\core_ai\manager::class)->disable_provider_instance($source);
        }
        if ($existingaccess) {
            return [
                'success' => true,
                'message' => get_string('aitrial_clone_success_existing', 'bookingextension_agent', $sourcename),
            ];
        }

        return [
            'success' => true,
            'message' => get_string('aitrial_clone_success', 'bookingextension_agent', $sourcename),
        ];
    }

    /**
     * Configure the Wunderbyte provider directly from a user-supplied (purchased) API key.
     *
     * Stores the key on the aiprovider_wunderbyte instance against the hard-coded LiteLLM endpoint
     * (self::BASE_URL), reusing the same full trial action config. A lightweight quick check validates
     * the key before storing: a clear 401 from the proxy aborts (bad/expired key); any other,
     * inconclusive result still stores the key but flags that it could not be verified.
     *
     * @param int $contextid Page/module context (audit/messaging only).
     * @param string $apikey The purchased LiteLLM key (sk-...).
     * @return array{success: bool, message: string}
     */
    public function configure_from_apikey(int $contextid, string $apikey): array {
        unset($contextid);

        if (!class_exists('\\core_ai\\manager')) {
            return $this->fail(get_string('aitrial_coreai_unavailable', 'bookingextension_agent'));
        }
        // Mirror the trial flow: prefer the Wunderbyte provider (full skill set) but fall back to the
        // OpenAI-compatible provider (reduced skill set) so a pasted key still works when only the
        // standard provider is installed.
        $strategy = $this->detect_strategy();
        if ($strategy === null) {
            $url = get_string('aitrial_provider_install_url', 'bookingextension_agent');
            return $this->fail(get_string('aitrial_provider_required', 'bookingextension_agent', $url));
        }

        $apikey = trim($apikey);
        if (!preg_match('/^sk-[A-Za-z0-9_\\-]{20,}$/', $apikey)) {
            return $this->fail(get_string('agent_key_invalid_format', 'bookingextension_agent'));
        }

        // Quick check before storing (only a definitive auth failure blocks; see verify_apikey()).
        $verify = $this->verify_apikey($apikey);
        if ($verify === 'invalid') {
            return $this->fail(get_string('agent_key_invalid', 'bookingextension_agent'));
        }

        try {
            $this->upsert_provider_instance($strategy, $apikey, self::BASE_URL);
        } catch (\Throwable $e) {
            debugging('configure_from_apikey: provider instance creation failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $this->fail(
                get_string('aitrial_provision_failed', 'bookingextension_agent'),
                'provider instance creation failed: ' . $e->getMessage()
            );
        }

        $message = get_string('agent_key_stored', 'bookingextension_agent');
        if ($verify === 'unknown') {
            $message .= ' ' . get_string('agent_key_unverified_note', 'bookingextension_agent');
        }
        return ['success' => true, 'message' => $message];
    }

    /**
     * Lightweight validity check of a purchased key — no token cost, privacy-preserving.
     *
     * GET {BASE_URL}/v1/models with the key as bearer: 200 = valid, 401 = invalid (token not found /
     * unauthorized), anything else (403/404/5xx/network) = inconclusive. Only 401 is a hard "invalid",
     * so a temporary proxy hiccup or a route restriction never false-rejects a real key.
     *
     * @param string $apikey
     * @return string 'valid' | 'invalid' | 'unknown'
     */
    private function verify_apikey(string $apikey): string {
        $url = rtrim(self::BASE_URL, '/') . '/v1/models';
        $request = new Request('GET', $url, [
            'Authorization' => 'Bearer ' . $apikey,
            'Accept' => 'application/json',
        ]);

        $client = \core\di::get(http_client::class);
        try {
            $response = $client->send($request, [
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::TIMEOUT => self::HTTP_TIMEOUT,
            ]);
        } catch (GuzzleException $e) {
            debugging('configure_from_apikey: key verification unreachable: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 'unknown';
        }

        $status = $response->getStatusCode();
        if ($status === 200) {
            return 'valid';
        }
        if ($status === 401) {
            return 'invalid';
        }
        return 'unknown';
    }

    /**
     * Build a Wunderbyte actionconfig from a third-party chat endpoint/model + default embeddings.
     *
     * @param string $chatendpoint
     * @param string $chatmodel
     * @return array
     */
    private function build_cloned_actionconfig(string $chatendpoint, string $chatmodel): array {
        $chat = $chatendpoint;
        // Derive the embeddings endpoint from the chat endpoint (.../chat/completions -> .../embeddings).
        $embeddings = preg_replace('#/chat/completions/?$#', '/embeddings', $chat);
        if ($embeddings === null || $embeddings === $chat) {
            $embeddings = rtrim((string)(preg_replace('#/v1/.*$#', '', $chat) ?: $chat), '/') . '/v1/embeddings';
        }
        $model = $chatmodel !== '' ? $chatmodel : 'gpt-4o';

        // The embeddings model must match what the cloned endpoint can actually serve. When the source
        // endpoint points at the Wunderbyte LLM, its key only grants the wunderbyte-* aliases and rejects
        // text-embedding-3-small outright (which would break skill discovery). Any other (real OpenAI-style)
        // endpoint cannot serve the wunderbyte-embeddings alias, so it falls back to the widely available
        // OpenAI-compatible model.
        $wbhost = parse_url(self::BASE_URL, PHP_URL_HOST);
        $iswbendpoint = $wbhost !== null && stripos($embeddings, $wbhost) !== false;
        $embeddingsmodel = $iswbendpoint ? 'wunderbyte-embeddings' : 'text-embedding-3-small';
        // On the Wunderbyte LLM every action gets its standard tier, never the source's single chat model: the source may
        // be a role-less trial instance whose alias must not reach the role-aware actions (Wunderbyte-GmbH/Wunderbyte-GmbH#2541).
        $decidemodel = $iswbendpoint ? self::MODEL_CHAT_MINI : $model;
        if ($iswbendpoint) {
            $model = self::MODEL_CHAT;
        }

        return [
            'aiprovider_wunderbyte\\aiactions\\generate_embeddings' => [
                'enabled' => true,
                'settings' => [
                    'endpoint' => $embeddings,
                    'model' => $embeddingsmodel,
                    // The wunderbyte-embeddings alias resolves to bge-multilingual-gemma2 upstream
                    // (3584-dim vectors); a mismatching declaration makes every stored vector unusable.
                    'dimensions' => $iswbendpoint ? 3584 : 1536,
                ],
            ],
            'aiprovider_wunderbyte\\aiactions\\planner_decide' => [
                'enabled' => true,
                'modelsettings' => [],
                'settings' => [
                    'endpoint' => $chat,
                    'model' => $decidemodel,
                    'systeminstruction' => '',
                    // Greedy: the planner is a routing + JSON task — determinism kills run-to-run flips.
                    'temperature' => 0.0,
                ],
            ],
            'aiprovider_wunderbyte\\aiactions\\planner_construct' => [
                'enabled' => true,
                'modelsettings' => [],
                'settings' => [
                    'endpoint' => $chat,
                    'model' => $model,
                    'systeminstruction' => '',
                    'temperature' => 0.0,
                ],
            ],
            'aiprovider_wunderbyte\\aiactions\\generate_agent_reply' => [
                'enabled' => true,
                'modelsettings' => [],
                'settings' => [
                    'endpoint' => $chat,
                    'model' => $model,
                    'systeminstruction' => '',
                    // Mildly warm for natural prose, but low enough to stay faithful to the planner result.
                    'temperature' => 0.3,
                ],
            ],
            'core_ai\\aiactions\\generate_text' => [
                'enabled' => true,
                'modelsettings' => [],
                'settings' => [
                    'endpoint' => $chat,
                    'model' => $model,
                    'systeminstruction' => '',
                    'temperature' => 0.3,
                ],
            ],
        ];
    }

    /**
     * Create or update the Wunderbyte provider instance ("Wunderbyte") from cloned credentials.
     *
     * @param string $apikey
     * @param string $chatendpoint
     * @param string $chatmodel
     */
    private function upsert_wunderbyte_from_clone(
        string $apikey,
        string $chatendpoint,
        string $chatmodel
    ): void {
        $classname = 'aiprovider_wunderbyte\\provider';
        $config = ['apikey' => $apikey];
        $actionconfig = $this->build_cloned_actionconfig($chatendpoint, $chatmodel);

        $existing = array_values(array_filter(
            \bookingextension_agent\local\wizard\services\provider_compat::get_provider_views(),
            static fn($instance) => \bookingextension_agent\local\wizard\services\provider_compat::is_instance_of_provider(
                $instance,
                $classname
            )
        ));

        \bookingextension_agent\local\wizard\services\provider_compat::configure_provider(
            $classname,
            $config,
            $actionconfig,
            self::INSTANCE_NAME,
            $existing ? reset($existing) : null,
        );
    }

    /**
     * Decide which provider plugin to provision against.
     *
     * Wunderbyte is preferred (full action/skill coverage incl. embeddings). The
     * OpenAI-compatible path is the documented fallback with a reduced skill set.
     *
     * @return string|null 'wunderbyte', 'openai', or null when neither is installed.
     */
    private function detect_strategy(): ?string {
        if (\core_component::get_plugin_directory('aiprovider', 'wunderbyte')) {
            return 'wunderbyte';
        }
        if (\core_component::get_plugin_directory('aiprovider', 'openai')) {
            return 'openai';
        }
        return null;
    }

    /**
     * POST the nonce to the trial endpoint and normalise the response.
     *
     * @param string $nonce
     * @return array{success: bool, message: string, apikey?: string, endpoint?: string}
     */
    private function exchange_nonce(string $nonce): array {
        global $CFG;

        $base = rtrim(self::BASE_URL, '/');
        $url = $base . '/api/moodle-trial';

        $request = new Request(
            'POST',
            $url,
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            json_encode(['wwwroot' => $CFG->wwwroot, 'nonce' => $nonce]),
        );

        $client = \core\di::get(http_client::class);
        try {
            $response = $client->send($request, [
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::TIMEOUT => self::HTTP_TIMEOUT,
            ]);
        } catch (GuzzleException $e) {
            // Cannot even reach the service from here (often a firewall on the Moodle side).
            debugging('trial_provisioner: trial endpoint unreachable: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return $this->fail(
                get_string('aitrial_support_firewall', 'bookingextension_agent'),
                'trial endpoint unreachable: ' . $e->getMessage()
            );
        }

        $status = $response->getStatusCode();
        $body = json_decode((string)$response->getBody(), true);

        if ($status === 200 && is_array($body) && !empty($body['apikey'])) {
            return [
                'success' => true,
                'message' => '',
                'apikey' => (string)$body['apikey'],
                // ALWAYS use the admin-configured PUBLIC base for the provider's action
                // endpoints. The service echoes back its OWN internal LiteLLM URL (e.g.
                // http://litellm:4000) which Moodle cannot reach and curl blocks — so we
                // deliberately ignore $body['endpoint'] here.
                'endpoint' => $base,
            ];
        }

        // 403 = origin could not be verified (firewall / site not publicly reachable).
        if ($status === 403) {
            return $this->fail(get_string('aitrial_support_firewall', 'bookingextension_agent'));
        }

        // 409 = a trial was already issued for this site URL (one trial per URL). The site has used
        // up its free trial; point the user at the buy/subscription page (link label only, URL hidden).
        if ($status === 409) {
            return $this->fail(get_string(
                'aitrial_already_exists',
                'bookingextension_agent',
                get_string('aitrial_pro_license_url', 'bookingextension_agent')
            ));
        }

        // 429 = an abuse cap was hit (per-IP or global). The service sends the exact,
        // already user-facing reason in `detail` — surface it verbatim.
        if ($status === 429) {
            $detail = (is_array($body) && !empty($body['detail'])) ? (string)$body['detail'] : '';
            return $this->fail($detail !== ''
                ? $detail
                : get_string('aitrial_provision_failed', 'bookingextension_agent'));
        }

        $detail = (is_array($body) && !empty($body['detail'])) ? (string)$body['detail'] : '';
        debugging('trial_provisioner: unexpected trial response HTTP ' . $status
            . ($detail !== '' ? ': ' . $detail : ''), DEBUG_DEVELOPER);
        return $this->fail(
            get_string('aitrial_provision_failed', 'bookingextension_agent'),
            'trial endpoint HTTP ' . $status . ($detail !== '' ? ': ' . $detail : '')
        );
    }

    /**
     * Create the provider instance, or update+enable an existing same-named one (idempotent).
     *
     * @param string $strategy 'wunderbyte' | 'openai'
     * @param string $apikey LiteLLM virtual key returned by the trial service
     * @param string $endpoint LiteLLM base URL (e.g. https://llm.wunderbyte.at)
     */
    private function upsert_provider_instance(string $strategy, string $apikey, string $endpoint): void {
        $classname = $strategy === 'wunderbyte'
            ? 'aiprovider_wunderbyte\\provider'
            : 'aiprovider_openai\\provider';

        $config = ['apikey' => $apikey];
        $actionconfig = $this->build_actionconfig($strategy, $endpoint);

        // Re-find OUR instance by endpoint (targets the Wunderbyte LLM) + provider class — not by
        // display name. INSTANCE_NAME below is only the label we give a freshly created instance.
        // On Moodle 4.5 (no instances) this list is empty and configure_provider writes flat config.
        $existing = array_values(array_filter(
            \bookingextension_agent\local\wizard\services\agent_access_service::find_wunderbyte_llm_instances(false),
            static fn($instance) => \bookingextension_agent\local\wizard\services\provider_compat::is_instance_of_provider(
                $instance,
                $classname
            )
        ));

        \bookingextension_agent\local\wizard\services\provider_compat::configure_provider(
            $classname,
            $config,
            $actionconfig,
            self::INSTANCE_NAME,
            $existing ? reset($existing) : null,
        );
    }

    /**
     * Build the per-action endpoint/model map for the chosen strategy.
     *
     * Trial model aliases (granted by the minted key): wunderbyte-privat (chat),
     * wunderbyte-privat-mini (compact planner), wunderbyte-embeddings (embeddings) for the Wunderbyte strategy;
     * wunderbyte-trial for the OpenAI strategy, which sends no request role (Wunderbyte-GmbH/Wunderbyte-GmbH#2541).
     * `providerid` is intentionally omitted — core_ai owns the instance id.
     *
     * @param string $strategy 'wunderbyte' | 'openai'
     * @param string $endpoint LiteLLM base URL (no trailing slash expected, but tolerated)
     * @return array
     */
    private function build_actionconfig(string $strategy, string $endpoint): array {
        $base = rtrim($endpoint, '/');
        $chat = $base . '/v1/chat/completions';
        $embeddings = $base . '/v1/embeddings';

        // The generate_text action is a core_ai action both providers process; it is the minimum
        // for a usable agent and therefore the whole config for the OpenAI fallback.
        // temperature is read from these settings at request-build time (get_model_settings spreads
        // them into the body; the OpenAI provider reads a 'temperature' setting too) — the only
        // core_ai-conform way to control sampling (no per-call temperature exists). General-purpose
        // text gets a mild 0.5 on the OpenAI fallback; on the Wunderbyte side it is kept low (0.3).
        $gttemperature = ($strategy === 'openai') ? 0.5 : 0.3;
        $generatetext = [
            'core_ai\\aiactions\\generate_text' => [
                'enabled' => true,
                'modelsettings' => [],
                'settings' => [
                    'endpoint' => $chat,
                    'model' => $strategy === 'openai' ? self::MODEL_TRIAL_ROLELESS : self::MODEL_CHAT,
                    'systeminstruction' => '',
                    'temperature' => $gttemperature,
                ],
            ],
        ];

        if ($strategy === 'openai') {
            // OpenAI provider has no embeddings/planner/agent-reply actions -> reduced skill set (by design). The agent
            // routes selection and construction to summarise_text there (orchestrator_routing_service), so that action
            // has to reach the trial gateway too - left alone it sent the trial key to api.openai.com (demo4, #2569).
            $summarise = $generatetext['core_ai\\aiactions\\generate_text'];
            $summarise['settings']['temperature'] = 0.0;
            return $generatetext + ['core_ai\\aiactions\\summarise_text' => $summarise];
        }

        // Full Wunderbyte trial config: embeddings + compact planner + agent reply + generate_text. No action carries a
        // system instruction: the agent brings its complete prompt per phase (Wunderbyte-GmbH/Wunderbyte-GmbH#2505).
        return [
            'aiprovider_wunderbyte\\aiactions\\generate_embeddings' => [
                'enabled' => true,
                'settings' => [
                    'endpoint' => $embeddings,
                    'model' => 'wunderbyte-embeddings',
                    // See build_cloned_actionconfig(): the alias serves 3584-dim vectors.
                    'dimensions' => 3584,
                ],
            ],
            'aiprovider_wunderbyte\\aiactions\\planner_decide' => [
                'enabled' => true,
                'modelsettings' => [],
                'settings' => [
                    'endpoint' => $chat,
                    'model' => 'wunderbyte-privat-mini',
                    'systeminstruction' => '',
                    // Greedy: the planner is a routing + JSON task — determinism kills run-to-run flips.
                    'temperature' => 0.0,
                ],
            ],
            // The constructor builds schema-bound parameters: it runs on the large model (phase model tiers).
            'aiprovider_wunderbyte\\aiactions\\planner_construct' => [
                'enabled' => true,
                'modelsettings' => [],
                'settings' => [
                    'endpoint' => $chat,
                    'model' => 'wunderbyte-privat',
                    'systeminstruction' => '',
                    'temperature' => 0.0,
                ],
            ],
            'aiprovider_wunderbyte\\aiactions\\generate_agent_reply' => [
                'enabled' => true,
                'modelsettings' => [],
                'settings' => [
                    'endpoint' => $chat,
                    'model' => 'wunderbyte-privat',
                    'systeminstruction' => '',
                    // Mildly warm for natural prose, but low enough to stay faithful to the planner result.
                    'temperature' => 0.3,
                ],
            ],
        ] + $generatetext;
    }

    /**
     * Shorthand for a failed result.
     *
     * In developer debug mode the technical detail (HTTP status, endpoint detail or exception
     * message) is appended to the user-facing message so a trial failure is self-diagnosing —
     * the generic "could not be set up" otherwise hides the real cause (e.g. an HTTP 502 from
     * the trial endpoint when LiteLLM key creation fails).
     *
     * @param string $message User-facing message.
     * @param string $debugdetail Technical detail, only shown when developer debugging is on.
     * @return array{success: bool, message: string}
     */
    private function fail(string $message, string $debugdetail = ''): array {
        // Surface the technical detail when EITHER core developer debugging OR the agent's own
        // debug mode (aidebugmode) is on — admins typically flip the agent toggle and expect the
        // detail, not only the site-wide DEVELOPER debug level.
        $showdetail = debugging('', DEBUG_DEVELOPER)
            || (int)get_config('bookingextension_agent', 'aidebugmode') === 1;
        if ($debugdetail !== '' && $showdetail) {
            $message .= ' [' . $debugdetail . ']';
        }
        return ['success' => false, 'message' => $message];
    }
}
