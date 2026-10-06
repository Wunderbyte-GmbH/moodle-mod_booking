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

namespace bookingextension_agent\local\wizard\services\discovery;

/**
 * Builds language-agnostic context priors for family ranking.
 *
 * Priors are soft score hints and never hard inclusion/exclusion filters.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class context_prior_builder {
    /**
     * Build normalized prior payload from context and runtime signals.
     *
     * @param int $contextid
     * @param array $signals
     * @return array
     */
    public function build(int $contextid, array $signals = []): array {
        $namespacehint = trim((string)($signals['namespace_hint'] ?? ''));
        // The namespace of the plugin that owns the page the request came from, '' when nothing identifies one.
        // This is the actual context signal; namespace_hint above is only catalogue popularity and says nothing
        // about where the user is (it resolves to whichever plugin registers the most skills).
        $contextnamespace = trim((string)($signals['context_namespace'] ?? ''));
        $pagetype = trim((string)($signals['page_type'] ?? 'unknown'));
        $userid = (int)($signals['userid'] ?? 0);

        return [
            'contextid' => max(0, $contextid),
            'namespace_hint' => $namespacehint,
            'context_namespace' => $contextnamespace,
            'page_type' => ($pagetype === '' ? 'unknown' : $pagetype),
            'user_state' => [
                'is_authenticated' => $userid > 0,
                'userid' => $userid,
            ],
            'is_hard_filter' => false,
        ];
    }
}
