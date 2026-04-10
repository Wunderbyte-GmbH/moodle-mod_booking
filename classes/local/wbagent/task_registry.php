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

/**
 * Task schema registry.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\wbagent;

use core_component;
use mod_booking\local\wbagent\interfaces\task_interface;
use mod_booking\local\wbagent\interfaces\task_provider_interface;

/**
 * Central registry that maps task names to their provider instances.
 *
 * Providers register themselves here. The orchestrator uses the registry
 * to embed task schemas in the system prompt and the executor uses it to
 * dispatch commands to the correct provider.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class task_registry {
    /** @var array<string, task_provider_interface> component => provider instance */
    private array $providers = [];

    /** @var array<string, task_interface> task name => task instance */
    private array $tasks = [];

    /**
     * Register a task provider.  All tasks it declares are mapped to it.
     *
     * @param task_provider_interface $provider
     * @return void
     */
    public function register(task_provider_interface $provider): void {
        $this->providers[$provider->get_component()] = $provider;
        foreach ($provider->get_tasks() as $task) {
            $this->tasks[$task->get_name()] = $task;
        }
    }

    /**
     * Return the task for a given task name, or null if not found.
     *
     * @param string $taskname
     * @return task_interface|null
     */
    public function get_task(string $taskname): ?task_interface {
        return $this->tasks[$taskname] ?? null;
    }

    /**
     * Return all registered task names (the allow-list).
     *
     * @return string[]
     */
    public function get_task_names(): array {
        return array_keys($this->tasks);
    }

    /**
     * Return all registered task instances.
     *
     * @return array<string,task_interface>
     */
    public function get_tasks(): array {
        return $this->tasks;
    }

    /**
     * Whether a task is read-only.
     *
     * @param string $taskname
     * @return bool
     */
    public function is_read_only_task(string $taskname): bool {
        $task = $this->get_task($taskname);
        return $task ? $task->is_read_only() : false;
    }

    /**
     * Return schemas for all registered tasks (for inclusion in the system prompt).
     *
     * @return array<string, array>  task name => schema array
     */
    public function get_all_schemas(): array {
        $schemas = [];
        foreach ($this->tasks as $name => $task) {
            $schemas[$name] = $task->get_schema();
        }
        return $schemas;
    }

    /**
     * Return all context-specific prompt packs from registered providers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function get_contextual_prompt_packs(): array {
        $packs = [];
        $seenids = [];

        foreach ($this->providers as $provider) {
            $providerpacks = $provider->get_contextual_prompt_packs();
            foreach ($providerpacks as $pack) {
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
     * Build and return the default registry loaded with all booking task providers.
     *
     * @return self
     */
    public static function make_default(): self {
        $registry = new self();
        $registeredcomponents = [];

        foreach (core_component::get_component_names() as $component) {
            $classname = '\\' . $component . '\\local\\wbagent\\task_provider';
            if (!class_exists($classname)) {
                continue;
            }

            $provider = new $classname();
            if (!$provider instanceof task_provider_interface) {
                continue;
            }

            $registry->register($provider);
            $registeredcomponents[$provider->get_component()] = true;
        }

        if (!isset($registeredcomponents['mod_booking'])) {
            $provider = new task_provider();
            $registry->register($provider);
        }

        return $registry;
    }
}
