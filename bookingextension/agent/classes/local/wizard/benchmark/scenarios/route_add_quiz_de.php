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
 * Routing scenario: Create a quiz/test -> add_quiz (not generic activity, not edit-existing)
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class route_add_quiz_de extends abstract_routing_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'route_add_quiz_de';
    }

    /**
     * Get the scenario description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'Create a quiz/test -> add_quiz (not generic activity, not edit-existing)';
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
        return 'Erstelle ein Quiz mit 10 Fragen fuer diesen Kurs.';
    }

    /**
     * Get the expected skill.
     *
     * @return string
     */
    public function get_expected_skill(): string {
        return 'course.add_quiz';
    }

    /**
     * Get the confusable sibling skills the selector must NOT choose.
     *
     * @return string[]
     */
    public function get_forbidden_siblings(): array {
        return ['course.add_activity', 'course.update_quiz'];
    }
}
