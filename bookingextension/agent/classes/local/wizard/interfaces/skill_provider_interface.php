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

namespace bookingextension_agent\local\wizard\interfaces;

/**
 * Provider interface for components contributing AI skills.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface skill_provider_interface {
    /**
     * Return provider component name.
     *
     * @return string
     */
    public function get_component(): string;

    /**
     * Return concrete skill instances contributed by this provider.
     *
     * @return array
     */
    public function get_skills(): array;

    /**
     * Return optional contextual prompt packs.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array;

    /**
     * Return optional issue code provider (plugin-specific business logic codes).
     *
     * Allows plugins to define custom issue codes beyond the generic framework.
     * Return null if not provided; framework provides default or generic implementations.
     *
     * @return \bookingextension_agent\local\wizard\interfaces\issue_code_provider_interface|null
     */
    public function get_issue_code_provider(): ?\bookingextension_agent\local\wizard\interfaces\issue_code_provider_interface;

    /**
     * Return optional prompt guidance (domain-specific LLM instructions).
     *
     * Allows plugins to customize the AI agent's behavior and reasoning within this domain.
     * Return empty array if not provided.
     *
     * @return array
     */
    public function get_prompt_guidance(): array;
}
