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
 * Resolves configured model/dimensions for wunderbyte embeddings action.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard;

/**
 * Reads effective embeddings action settings from enabled wunderbyte providers.
 */
class embeddings_action_config_resolver {
    /** Embeddings action class key in provider action config. */
    private const WB_ACTION_GENERATE_EMBEDDINGS = wb_action_names::GENERATE_EMBEDDINGS;

    /** Wunderbyte provider class key. */
    private const WB_PROVIDER_CLASS = 'aiprovider_wunderbyte\\provider';

    /** Default embeddings model fallback. */
    private const DEFAULT_MODEL = orchestrator::EMBEDDINGS_DEFAULT_MODEL;

    /** Default embeddings dimensions fallback. */
    private const DEFAULT_DIMENSIONS = orchestrator::EMBEDDINGS_DEFAULT_DIMENSIONS;

    /**
     * Filename-safe variant key (model + dimensions) for the active embeddings configuration.
     *
     * Embeddings of different models are incompatible, so each variant lives in its own CSV file
     * (…__<model>__<dims>.csv). Switching the model therefore never invalidates the others, and
     * switching back is free. {@see embeddings_csv_repository_base} applies this as the path suffix.
     *
     * @return string
     */
    public function variant_key(): string {
        $cfg = $this->resolve();
        return embeddings_csv_repository_base::normalize_variant_key(
            $cfg['model'] . '__' . $cfg['dimensions']
        );
    }

    /**
     * Resolve the effective model/dimensions, honouring optional explicit overrides.
     *
     * Precedence per value: explicit argument → active provider config ({@see resolve()}) →
     * orchestrator default. An empty model or a non-positive dimension falls back to the default.
     * This is the single source for the resolution the index services previously duplicated.
     *
     * @param string|null $model      Explicit model override, or null to use the active config.
     * @param int|null    $dimensions Explicit dimensions override, or null to use the active config.
     * @return array{model:string,dimensions:int}
     */
    public function resolve_with_overrides(?string $model, ?int $dimensions): array {
        $settings = $this->resolve();

        $resolvedmodel = trim((string)($model ?? ($settings['model'] ?? self::DEFAULT_MODEL)));
        if ($resolvedmodel === '') {
            $resolvedmodel = self::DEFAULT_MODEL;
        }

        $resolveddimensions = (int)($dimensions ?? ($settings['dimensions'] ?? self::DEFAULT_DIMENSIONS));
        if ($resolveddimensions < 1) {
            $resolveddimensions = self::DEFAULT_DIMENSIONS;
        }

        return ['model' => $resolvedmodel, 'dimensions' => $resolveddimensions];
    }

    /**
     * Resolve model and dimensions from active wunderbyte embeddings action config.
     *
     * @return array{model:string,dimensions:int}
     */
    public function resolve(): array {
        global $DB;

        try {
            $providers = $DB->get_records(
                'ai_providers',
                ['provider' => self::WB_PROVIDER_CLASS, 'enabled' => 1],
                'id ASC'
            );

            foreach ($providers as $provider) {
                $decoded = json_decode((string)($provider->actionconfig ?? ''), true);
                if (!is_array($decoded)) {
                    continue;
                }

                $actionconfig = $decoded[self::WB_ACTION_GENERATE_EMBEDDINGS] ?? null;
                if (!is_array($actionconfig)) {
                    continue;
                }

                if (array_key_exists('enabled', $actionconfig) && empty($actionconfig['enabled'])) {
                    continue;
                }

                $settings = (array)($actionconfig['settings'] ?? []);
                $model = trim((string)($settings['model'] ?? ''));
                $dimensions = (int)($settings['dimensions'] ?? 0);

                if ($model === '') {
                    $model = self::DEFAULT_MODEL;
                }
                if ($dimensions < 1) {
                    $dimensions = self::DEFAULT_DIMENSIONS;
                }

                return [
                    'model' => $model,
                    'dimensions' => $dimensions,
                ];
            }
        } catch (\Throwable $e) {
            return [
                'model' => self::DEFAULT_MODEL,
                'dimensions' => self::DEFAULT_DIMENSIONS,
            ];
        }

        return [
            'model' => self::DEFAULT_MODEL,
            'dimensions' => self::DEFAULT_DIMENSIONS,
        ];
    }
}
