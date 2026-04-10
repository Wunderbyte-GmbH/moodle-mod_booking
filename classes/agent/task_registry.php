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

namespace mod_booking\agent;

use mod_booking\agent\interfaces\agent_task_provider;

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

    /** @var array<string, agent_task_provider> task name => provider instance */
    private array $providers = [];

    /**
     * Register a task provider.  All tasks it declares are mapped to it.
     *
     * @param agent_task_provider $provider
     * @return void
     */
    public function register(agent_task_provider $provider): void {
        foreach ($provider->get_task_names() as $name) {
            $this->providers[$name] = $provider;
        }
    }

    /**
     * Return the provider for a given task name, or null if not found.
     *
     * @param string $taskname
     * @return agent_task_provider|null
     */
    public function get_provider(string $taskname): ?agent_task_provider {
        return $this->providers[$taskname] ?? null;
    }

    /**
     * Return all registered task names (the allow-list).
     *
     * @return string[]
     */
    public function get_task_names(): array {
        return array_keys($this->providers);
    }

    /**
     * Return schemas for all registered tasks (for inclusion in the system prompt).
     *
     * @return array<string, array>  task name => schema array
     */
    public function get_all_schemas(): array {
        $schemas = [];
        foreach ($this->providers as $name => $provider) {
            $schemas[$name] = $provider->get_task_schema($name);
        }
        return $schemas;
    }

    /**
     * Build and return the default registry loaded with all booking task providers.
     *
     * @return self
     */
    public static function make_default(): self {
        $registry = new self();
        $registry->register(new \mod_booking\agent\booking\booking_task_provider());
        return $registry;
    }
}
