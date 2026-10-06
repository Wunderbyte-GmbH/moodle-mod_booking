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
 * Shared normalization of the planner phase trace.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\services;

/**
 * Normalizes a planner phase trace into the canonical three-phase shape
 * (discovery / selection / parameter_construction). Previously copied in the conversation store and
 * the message persistence service.
 */
class phase_trace_normalizer {
    /**
     * Normalize a phase trace.
     *
     * Keeps any value already stored under a canonical phase key; additionally accepts a flat list
     * of `['phase' => ..., …]` entries and slots the first entry of each phase into its bucket.
     *
     * @param array $phasetrace
     * @return array{discovery:mixed,selection:mixed,parameter_construction:mixed}
     */
    public static function normalize(array $phasetrace): array {
        $normalized = [
            'discovery' => [],
            'selection' => [],
            'parameter_construction' => [],
        ];

        if (isset($phasetrace['discovery']) && is_array($phasetrace['discovery'])) {
            $normalized['discovery'] = $phasetrace['discovery'];
        }
        if (isset($phasetrace['selection']) && is_array($phasetrace['selection'])) {
            $normalized['selection'] = $phasetrace['selection'];
        }
        if (isset($phasetrace['parameter_construction']) && is_array($phasetrace['parameter_construction'])) {
            $normalized['parameter_construction'] = $phasetrace['parameter_construction'];
        }

        foreach ($phasetrace as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $phase = trim((string)($entry['phase'] ?? ''));
            if ($phase !== '' && isset($normalized[$phase]) && empty($normalized[$phase])) {
                $normalized[$phase] = $entry;
            }
        }

        return $normalized;
    }
}
