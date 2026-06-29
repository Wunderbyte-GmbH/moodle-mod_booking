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

namespace mod_booking\local\wizard;


use bookingextension_agent\local\wizard\interfaces\skill_input_normalizer_interface;
use bookingextension_agent\local\wizard\interfaces\skill_input_normalizer_provider_interface;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\interfaces\skill_provider_interface;
use bookingextension_agent\local\wizard\services\skill_catalog_discovery;
use mod_booking\local\wizard\booking\provider_skill_input_normalizer;

/**
 * mod_booking AI task provider entrypoint.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_provider implements
    skill_input_normalizer_provider_interface,
    skill_provider_interface {
    /**
     * Return the component name.
     *
     * @return string
     */
    public function get_component(): string {
        return 'mod/booking';
    }

    /**
     * Return concrete task instances.
     *
     * @return array<int,skill_interface>
     */
    public function get_skills(): array {
        $tasks = array_values((new skill_catalog_discovery())->instances('mod_booking'));

        usort($tasks, static fn(skill_interface $a, skill_interface $b): int => strcmp($a->get_name(), $b->get_name()));
        return $tasks;
    }

    /**
     * Return discovery diagnostics from the last get_skills() call.
     *
     * @return array<int,string>
     */
    public function get_discovery_diagnostics(): array {
        return (new skill_catalog_discovery())->diagnostics();
    }

    /**
     * Return contextual prompt packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        $packs = [];
        $seenids = [];

        foreach ($this->get_skills() as $task) {
            if (!method_exists($task, 'get_contextual_prompt_packs')) {
                continue;
            }

            $taskpacks = (array)$task->get_contextual_prompt_packs();
            foreach ($taskpacks as $pack) {
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
     * Return optional issue code provider.
     *
     * @return null
     */
    public function get_issue_code_provider(): ?\bookingextension_agent\local\wizard\interfaces\issue_code_provider_interface {
        return null;
    }

    /**
     * Return optional prompt guidance.
     *
     * @return array<string,mixed>
     */
    public function get_prompt_guidance(): array {
        return [];
    }

    /**
     * Return optional provider-owned task input normalizer.
     *
     * @return skill_input_normalizer_interface|null
     */
    public function get_skill_input_normalizer(): ?skill_input_normalizer_interface {
        return new provider_skill_input_normalizer();
    }
}
