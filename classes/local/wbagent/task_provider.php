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

namespace mod_booking\local\wbagent;

use core_component;
use mod_booking\local\wbagent\interfaces\task_interface;
use mod_booking\local\wbagent\interfaces\task_provider_interface;

/**
 * mod_booking task provider entrypoint.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_provider implements task_provider_interface {
    /**
     * Return the component name.
     *
     * @return string
     */
    public function get_component(): string {
        return 'mod_booking';
    }

    /**
     * Return concrete task instances.
     *
     * @return array
     */
    public function get_tasks(): array {
        $taskclasses = core_component::get_component_classes_in_namespace('mod_booking', 'local\\wbagent\\booking\\tasks');
        $tasks = [];

        foreach (array_keys($taskclasses) as $classname) {
            try {
                $reflection = new \ReflectionClass($classname);
                if ($reflection->isAbstract()) {
                    continue;
                }

                $task = $reflection->newInstance();
            } catch (\Throwable $e) {
                continue;
            }

            if (!$task instanceof task_interface) {
                continue;
            }

            $tasks[] = $task;
        }

        usort($tasks, static fn(task_interface $a, task_interface $b): int => strcmp($a->get_name(), $b->get_name()));
        return $tasks;
    }

    /**
     * Return contextual prompt packs.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        $packs = [];
        $seenids = [];

        foreach ($this->get_tasks() as $task) {
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
}
