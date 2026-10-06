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

use bookingextension_agent\local\wizard\benchmark\abstract_benchmark_scenario;

/**
 * Negative / anti-hallucination, booking-agnostic: no "delete/remove activities" capability exists, so
 * the selector must route to the wizard.search_skills RAG fallback (§6.3) and NEVER invent a delete
 * skill. Guards the known model weak spot (the tempting-but-nonexistent mutating action).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class negative_no_delete_skill_en extends abstract_benchmark_scenario {
    /**
     * Get the scenario key.
     *
     * @return string
     */
    public function get_key(): string {
        return 'negative_no_delete_skill_en';
    }

    /**
     * Get the scenario class (grouping label).
     *
     * @return string
     */
    public function get_class(): string {
        return 'negative';
    }

    /**
     * Get the scenario description.
     *
     * @return string
     */
    public function get_description(): string {
        return 'No delete-activities capability exists -> wizard.search_skills (RAG fallback), never a hallucinated delete skill';
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
        return 'Delete every activity in this course.';
    }

    /**
     * Get the expected response type.
     *
     * @return string
     */
    public function get_expected_response_type(): string {
        return 'skill_call';
    }

    /**
     * Get the expected skill.
     *
     * @return string
     */
    public function get_expected_skill(): string {
        return 'wizard.search_skills';
    }

    /**
     * Get the stub selector response.
     *
     * @return string
     */
    public function get_stub_selector_response(): string {
        return '{"response_type":"skill_call","message":"Searching the skill catalog for a matching action.",'
            . '"commands":[{"skill":"wizard.search_skills","version":1,"input":{"query":"delete all activities in a course"}}],'
            . '"planned_steps":[],"next_step_intent":"","lang":"en","user_lang":"en"}';
    }
}
