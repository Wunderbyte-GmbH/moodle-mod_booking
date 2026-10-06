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

namespace bookingextension_agent\local\wizard\benchmark\scenarios;

use bookingextension_agent\local\wizard\benchmark\abstract_routing_scenario;

/**
 * Routing scenario: Vague temporal browse (SO-1) -> search_options with EMPTY query, never query=demnaechst (F57a)
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class route_search_options_temporal_de extends abstract_routing_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'route_search_options_temporal_de';
    }

    /**
     * Get the scenario description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'Vague temporal browse (SO-1) -> search_options with EMPTY query, never query=demnaechst (F57a)';
    }

    /**
     * Get the query language.
     *
     * @return string
     */
    public function get_language(): string {
        return 'de';
    }

    /**
     * Get the user message.
     *
     * @return string
     */
    public function get_user_message(): string {
        return "Was gibt's denn bei euch demnächst, wo ich noch mitmachen kann?";
    }

    /**
     * Get the expected skill.
     *
     * @return string
     */
    public function get_expected_skill(): string {
        return 'mod_booking.search_options';
    }

    /**
     * The temporal phrase must never be staged as the substring query (#2317, F57a). A vague
     * "coming up"-style phrase stages an empty query; "when" is either empty (the upcoming
     * default since #2318) or a concrete resolved date - never the verbatim adverb.
     *
     * @param array $result
     * @return array
     */
    public function assert_additional(array $result): array {
        $checks = parent::assert_additional($result);
        $input = (array)($result['commands'][0]['input'] ?? []);
        $query = trim((string)($input['query'] ?? ''));
        $when = trim((string)($input['when'] ?? ''));

        $checks[] = [
            'label'  => 'temporal phrase must not be staged as substring query',
            'passed' => $query === '',
            'detail' => 'staged query: "' . $query . '"',
        ];
        $checks[] = [
            'label'  => 'when is empty or a concrete date, never the verbatim adverb',
            'passed' => $when === '' || (bool)preg_match('/\\d{4}-\\d{2}-\\d{2}/', $when),
            'detail' => 'staged when: "' . $when . '"',
        ];
        return $checks;
    }
}
