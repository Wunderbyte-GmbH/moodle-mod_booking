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

namespace bookingextension_agent\local\wizard\services;

use bookingextension_agent\local\wizard\config\runtime_feature_flags;
use bookingextension_agent\local\wizard\wb_action_names;
use core\context;
use core_ai\manager as ai_manager;
use core_ai\aiactions\explain_text;
use core_ai\aiactions\generate_text;
use core_ai\aiactions\summarise_text;
use core_text;

/**
 * Routing and debug helpers for orchestrator provider/action selection.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class orchestrator_routing_service {
    /** Discovery planner phase. */
    public const PHASE_DISCOVERY = 'discovery';

    /** Selection planner phase. */
    public const PHASE_SELECTION = 'selection';

    /** Parameter construction planner phase. */
    public const PHASE_PARAMETER_CONSTRUCTION = 'parameter_construction';

    /** @var string Planner action of the selector (small model). */
    private string $wbplanneraction;

    /** @var string Planner action of the constructor (large model); falls back to $wbplanneraction. */
    private string $wbconstructaction;

    /**
     * Read-only runtime feature-flag snapshot used by orchestration consumers.
     *
     * @return array
     */
    public static function get_runtime_feature_flags_snapshot(): array {
        return runtime_feature_flags::snapshot();
    }

    /**
     * Constructor.
     *
     * @param string $wbplanneraction Planner action of the selector.
     * @param string $wbconstructaction Planner action of the constructor.
     */
    public function __construct(
        string $wbplanneraction,
        string $wbconstructaction = wb_action_names::PLANNER_CONSTRUCT
    ) {
        $this->wbplanneraction = $wbplanneraction;
        $this->wbconstructaction = $wbconstructaction;
    }

    /**
     * Action class of the discovery query normalizer: the small planner action when available, else generate_text.
     *
     * @param ai_manager $manager
     * @return string
     */
    public function resolve_query_normalizer_action_class(ai_manager $manager): string {
        try {
            if ($manager->is_action_available($this->wbplanneraction)) {
                return $this->wbplanneraction;
            }
        } catch (\Throwable $e) {
            debugging('orchestrator_routing_service: normalizer routing failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        return generate_text::class;
    }

    /**
     * Route to planner action classes by explicit pipeline phase.
     *
     * @param ai_manager $manager
     * @param context $context
     * @param string $phase
     * @return array{actionclass:string, routepolicy:string, routingfallback:bool}
     */
    public function resolve_action_class_for_phase(ai_manager $manager, context $context, string $phase): array {
        $normalizedphase = $this->normalize_phase($phase);
        if ($normalizedphase === self::PHASE_PARAMETER_CONSTRUCTION) {
            return $this->resolve_construction_action_class($manager, $context);
        }

        return $this->resolve_selection_action_class($manager, $context);
    }

    /**
     * Route the selection phase action class.
     *
     * @param ai_manager $manager
     * @param context $context
     * @return array{actionclass:string, routepolicy:string, routingfallback:bool}
     */
    private function resolve_selection_action_class(ai_manager $manager, context $context): array {
        try {
            if ($manager->is_action_available($this->wbplanneraction)) {
                return [
                    'actionclass' => $this->wbplanneraction,
                    'routepolicy' => $this->build_phase_route_policy(self::PHASE_SELECTION, 'wunderbyte'),
                    'routingfallback' => false,
                ];
            }
        } catch (\Throwable $e) {
            // Best-effort: fall through to the next available action below.
            debugging('orchestrator_routing_service: selection-phase routing failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        if ($this->is_action_available_in_context($manager, $context, summarise_text::class)) {
            return [
                'actionclass' => summarise_text::class,
                'routepolicy' => $this->build_phase_route_policy(self::PHASE_SELECTION, 'openai'),
                'routingfallback' => false,
            ];
        }

        return [
            'actionclass' => generate_text::class,
            'routepolicy' => $this->build_phase_route_policy(self::PHASE_SELECTION, 'default'),
            'routingfallback' => true,
        ];
    }

    /**
     * Route the construction phase action class.
     *
     * @param ai_manager $manager
     * @param context $context
     * @return array{actionclass:string, routepolicy:string, routingfallback:bool}
     */
    private function resolve_construction_action_class(ai_manager $manager, context $context): array {
        // The constructor's own action first (large model), else the selector's planner action. Each check stands
        // alone, so a provider without the constructor action still routes to planner_decide.
        foreach ([$this->wbconstructaction, $this->wbplanneraction] as $planneraction) {
            try {
                if ($manager->is_action_available($planneraction)) {
                    return [
                        'actionclass' => $planneraction,
                        'routepolicy' => $this->build_phase_route_policy(self::PHASE_PARAMETER_CONSTRUCTION, 'wunderbyte'),
                        'routingfallback' => false,
                    ];
                }
            } catch (\Throwable $e) {
                // Best-effort: fall through to the next available action below.
                debugging('orchestrator_routing_service: construction-phase routing failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        if ($this->is_action_available_in_context($manager, $context, summarise_text::class)) {
            return [
                'actionclass' => summarise_text::class,
                'routepolicy' => $this->build_phase_route_policy(self::PHASE_PARAMETER_CONSTRUCTION, 'openai'),
                'routingfallback' => false,
            ];
        }

        return [
            'actionclass' => generate_text::class,
            'routepolicy' => $this->build_phase_route_policy(self::PHASE_PARAMETER_CONSTRUCTION, 'default'),
            'routingfallback' => true,
        ];
    }

    /**
     * Check action availability with context and global provider state.
     *
     * @param ai_manager $manager
     * @param context $context
     * @param string $actionclass
     * @return bool
     */
    public function is_action_available_in_context(ai_manager $manager, context $context, string $actionclass): bool {
        if (!$manager->is_action_available($actionclass)) {
            return false;
        }
        if (!method_exists($manager, 'is_action_enabled_in_context')) {
            return true;
        }
        return $manager->is_action_enabled_in_context($context, $actionclass);
    }

    /**
     * Build compact orchestrator telemetry in source field.
     *
     * @param string $actionclass
     * @param string $routepolicy
     * @param bool $routingfallback
     * @param string $phase
     * @param string $primaryprovider
     * @param int $historycount
     * @param int $observationcount
     * @param string $catalogselectionmode
     * @param string $embeddingstatus
     * @param int $catalogsize
     * @param bool $embeddingrebuildqueued
     * @param bool $exception
     * @return string
     */
    public function build_debug_source(
        string $actionclass,
        string $routepolicy,
        bool $routingfallback,
        string $phase,
        string $primaryprovider,
        int $historycount,
        int $observationcount,
        string $catalogselectionmode,
        string $embeddingstatus,
        int $catalogsize,
        bool $embeddingrebuildqueued,
        bool $exception
    ): string {
        $phasemap = [
            self::PHASE_DISCOVERY => 'disc',
            self::PHASE_SELECTION => 'sel',
            self::PHASE_PARAMETER_CONSTRUCTION => 'cons',
        ];
        $actionmap = [
            generate_text::class => 'gen',
            summarise_text::class => 'sum',
            explain_text::class => 'exp',
            $this->wbplanneraction => 'wpl',
            $this->wbconstructaction => 'wpc',
        ];

        $normalizedphase = $this->normalize_phase($phase);
        $step = $normalizedphase === self::PHASE_DISCOVERY ? 'tcp' : 'sr';
        $action = $actionmap[$actionclass] ?? 'oth';
        $route = 'df';
        $routefamily = $this->route_policy_family($routepolicy);
        if ($routefamily === 'openai') {
            $route = 'oa';
        } else if ($routefamily === 'wunderbyte') {
            $route = 'wb';
        }
        $provider = provider_routing_util::short_provider_for_debug($primaryprovider);
        $phasekey = $phasemap[$normalizedphase] ?? 'disc';

        $source = 'orc'
            . '|p=' . $phasekey
            . '|st=' . $step
            . '|ac=' . $action
            . '|rt=' . $route
            . '|fb=' . ($routingfallback ? '1' : '0')
            . '|pv=' . $provider
            . '|hm=' . max(0, $historycount)
            . '|ob=' . max(0, $observationcount)
            . '|cm=' . $this->short_debug_token($catalogselectionmode)
            . '|em=' . $this->short_debug_token($embeddingstatus)
            . '|tk=' . max(0, $catalogsize)
            . '|rq=' . ($embeddingrebuildqueued ? '1' : '0')
            . '|ex=' . ($exception ? '1' : '0');

        if (core_text::strlen($source) > 100) {
            return core_text::substr($source, 0, 100);
        }

        return $source;
    }

    /**
     * Upsert phase telemetry in an existing debug source string.
     *
     * @param string $source
     * @param string $phase
     * @return string
     */
    public function with_phase_in_debug_source(string $source, string $phase): string {
        $normalizedphase = $this->normalize_phase($phase);
        $phasekey = 'disc';
        if ($normalizedphase === self::PHASE_SELECTION) {
            $phasekey = 'sel';
        } else if ($normalizedphase === self::PHASE_PARAMETER_CONSTRUCTION) {
            $phasekey = 'cons';
        }

        $cleaned = preg_replace('/\|p=[^|]*/', '', $source) ?? $source;
        $withphase = $cleaned . '|p=' . $phasekey;

        if (core_text::strlen($withphase) > 100) {
            return core_text::substr($withphase, 0, 100);
        }

        return $withphase;
    }

    /**
     * Return normalized route-policy family.
     *
     * @param string $routepolicy
     * @return string
     */
    public function route_policy_family(string $routepolicy): string {
        $normalized = core_text::strtolower(trim($routepolicy));
        if (strpos($normalized, 'wunderbyte') !== false) {
            return 'wunderbyte';
        }
        if (strpos($normalized, 'openai') !== false) {
            return 'openai';
        }

        return 'default';
    }

    /**
     * Check whether route policy belongs to Wunderbyte family.
     *
     * @param string $routepolicy
     * @return bool
     */
    public function is_wunderbyte_routepolicy(string $routepolicy): bool {
        return $this->route_policy_family($routepolicy) === 'wunderbyte';
    }

    /**
     * Keep debug token values compact and stable.
     *
     * @param string $value
     * @return string
     */
    private function short_debug_token(string $value): string {
        $normalized = preg_replace('/[^a-z0-9_\-]+/i', '', core_text::strtolower(trim($value)));
        if (!is_string($normalized) || $normalized === '') {
            return 'na';
        }

        // 10 until 2026-09-20. The catalogue modes "embed_topk" and "embed_topk_family_boost" both truncated
        // to "embed_topk", so the one field we read to tell whether the family boost is active could not
        // distinguish the two states at all — the boost looked dead while it was running. 24 keeps the tag
        // short and separates every mode the pipeline emits.
        if (core_text::strlen($normalized) > 24) {
            return core_text::substr($normalized, 0, 24);
        }

        return $normalized;
    }

    /**
     * Normalize external phase labels to supported pipeline phases.
     *
     * @param string $phase
     * @return string
     */
    private function normalize_phase(string $phase): string {
        $normalized = trim(core_text::strtolower($phase));
        if ($normalized === self::PHASE_SELECTION) {
            return self::PHASE_SELECTION;
        }
        if ($normalized === self::PHASE_PARAMETER_CONSTRUCTION) {
            return self::PHASE_PARAMETER_CONSTRUCTION;
        }
        return self::PHASE_DISCOVERY;
    }

    /**
     * Build compact route-policy token with phase context.
     *
     * @param string $phase
     * @param string $family
     * @return string
     */
    private function build_phase_route_policy(string $phase, string $family): string {
        $normalizedphase = $this->normalize_phase($phase);
        $phasekey = 'disc';
        if ($normalizedphase === self::PHASE_SELECTION) {
            $phasekey = 'sel';
        } else if ($normalizedphase === self::PHASE_PARAMETER_CONSTRUCTION) {
            $phasekey = 'cons';
        }

        return $phasekey . '_' . core_text::strtolower(trim($family));
    }
}
