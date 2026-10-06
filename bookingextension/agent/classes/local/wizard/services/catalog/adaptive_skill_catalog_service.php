<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Adaptive skill catalog reducer.
 *
 * Discovery is SEMANTIC-ONLY (see docs/Blueprints/flowcharts DISCO_RULE): the planner never
 * lexically force-includes skills. The former mandatory/recency tiering (MANDATORY_SKILL_KEYWORDS,
 * get_mandatory_skills, always_available flag, recency Top-K) has been removed — a skill that is not
 * retrieved is an EMBEDDING problem, fixed via its anchors (example_utterances), never lexically.
 * The single sanctioned exception is wizard.search_skills, force-added as the engine RAG fallback in
 * discovery_phase_service (not here).
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace bookingextension_agent\local\wizard\services\catalog;

/**
 * Returns the full skill catalog for the planner; selection narrowing is done semantically by the
 * embeddings discovery, not by this service.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adaptive_skill_catalog_service {
    /**
     * Return the active skill catalog (full; no lexical tiering — discovery is semantic-only).
     *
     * @param array $fullcatalog Full skill contracts from registry.
     * @param array $recentskillhistory Unused (kept for signature compatibility).
     * @param string $phase Unused (kept for signature compatibility).
     * @return array{active_skills: array}
     */
    public static function get_adaptive_catalog(
        array $fullcatalog,
        array $recentskillhistory = [],
        string $phase = 'discovery'
    ): array {
        unset($recentskillhistory, $phase);
        return ['active_skills' => $fullcatalog];
    }
}
