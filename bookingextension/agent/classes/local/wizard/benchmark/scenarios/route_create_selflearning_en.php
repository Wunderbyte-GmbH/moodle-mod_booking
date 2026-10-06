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
 * Routing scenario: EN self-learning duration -> create_selflearning_option (cross-language)
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class route_create_selflearning_en extends abstract_routing_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'route_create_selflearning_en';
    }

    /**
     * Get the scenario description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'EN self-learning duration -> create_selflearning_option (cross-language)';
    }

    /**
     * Get the query language.
     *
     * @return string
     */
    public function get_language(): string {
        return 'en';
    }

    /**
     * Get the user message.
     *
     * @return string
     */
    public function get_user_message(): string {
        return 'Create a self-learning course "Data Protection Basics" with a learning duration of 4 hours '
            . 'and no fixed time slots.';
    }

    /**
     * Get the expected skill.
     *
     * @return string
     */
    public function get_expected_skill(): string {
        return 'mod_booking.create_selflearning_option';
    }

    /**
     * Get the confusable sibling skills the selector must NOT choose.
     *
     * @return string[]
     */
    public function get_forbidden_siblings(): array {
        return ['mod_booking.create_option', 'mod_booking.create_slotbooking_option'];
    }
}
