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

namespace bookingextension_agent\local\wizard;

use bookingextension_agent\local\wizard\interfaces\result_summary_provider_interface;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\interfaces\skill_provider_interface;
use bookingextension_agent\local\wizard\summarizer\basic_collection_result_summary_contributor;
use bookingextension_agent\local\wizard\summarizer\diagnosis_result_summary_contributor;
use bookingextension_agent\local\wizard\summarizer\docs_result_summary_contributor;
use bookingextension_agent\local\wizard\summarizer\single_object_result_summary_contributor;

/**
 * bookingextension_agent skill provider entrypoint.
 *
 * @package    bookingextension_agent
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_provider implements result_summary_provider_interface, skill_provider_interface {
    /**
     * Return the component name.
     *
     * @return string
     */
    public function get_component(): string {
        return 'bookingextension/agent';
    }

    /**
     * Return concrete skill instances.
     *
     * @return array
     */
    public function get_skills(): array {
        $skills = array_values(skill_discovery::get_skill_instances('bookingextension_agent'));

        usort($skills, static fn(skill_interface $a, skill_interface $b): int => strcmp($a->get_name(), $b->get_name()));
        return $skills;
    }

    /**
     * Return discovery diagnostics from the last get_skills() call.
     *
     * @return string[]
     */
    public function get_discovery_diagnostics(): array {
        return skill_discovery::get_last_diagnostics();
    }

    /**
     * Return contextual prompt packs.
     *
     * @return array[]
     */
    public function get_contextual_prompt_packs(): array {
        $packs = [];
        $seenids = [];

        foreach ($this->get_skills() as $skill) {
            if (!method_exists($skill, 'get_contextual_prompt_packs')) {
                continue;
            }

            $skillpacks = (array)$skill->get_contextual_prompt_packs();
            foreach ($skillpacks as $pack) {
                if (!is_array($pack)) {
                    continue;
                }
                $id = (string)($pack['id'] ?? '');
                if ($id === '' || isset($seenids[$id])) {
                    continue;
                }
                $seenids[$id] = true;
                $packs[] = $pack;
            }
        }

        return $packs;
    }

    /**
     * Return optional issue code provider for domain-specific business logic codes.
     *
     * @return \bookingextension_agent\local\wizard\interfaces\issue_code_provider_interface|null
     */
    public function get_issue_code_provider(): ?\bookingextension_agent\local\wizard\interfaces\issue_code_provider_interface {
        try {
            return new booking_issue_code_provider();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Return optional prompt guidance (domain-specific LLM instructions).
     *
     * @return array
     */
    public function get_prompt_guidance(): array {
        // For now, no custom prompt guidance beyond what orchestrator provides.
        // Plugins can override to inject domain-specific instructions.
        return [];
    }

    /**
     * Return result summary contributors for this component.
     *
     * @return \bookingextension_agent\local\wizard\interfaces\summarizer\result_summary_contributor_interface[]
     */
    public function get_result_summary_contributors(): array {
        return [
            new basic_collection_result_summary_contributor(),
            new single_object_result_summary_contributor(),
            new docs_result_summary_contributor(),
            new diagnosis_result_summary_contributor(),
        ];
    }
}
