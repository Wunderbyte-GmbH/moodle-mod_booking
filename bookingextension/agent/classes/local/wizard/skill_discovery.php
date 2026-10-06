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

use core_component;
use bookingextension_agent\local\wizard\interfaces\skill_interface;
use bookingextension_agent\local\wizard\interfaces\skill_trigger_provider_interface;
use bookingextension_agent\local\wizard\services\engine_alias_registrar;

/**
 * Discovers agent classes below a component's local/wizard tree.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class skill_discovery {
    /** @var string[] diagnostics collected during the last discovery run */
    private static array $lastdiagnostics = [];

    /**
     * Return all concrete skill instances keyed by skill name.
     *
     * Discovery is intentionally constrained to canonical skill directories.
     * Broken skill classes are skipped and reported as diagnostics so a single
     * third-party skill cannot break the whole agent.
     *
     * @param string $component
     * @return array
     */
    public static function get_skill_instances(string $component = 'bookingextension_agent'): array {
        self::$lastdiagnostics = [];
        $classes = self::find_candidate_classes($component);
        if (!empty($classes)) {
            // Skill classes reference engine types through component-local
            // aliases; define them before any candidate class is loaded.
            engine_alias_registrar::ensure_component_aliases($component);
        }
        usort($classes, [self::class, 'compare_skill_classes']);

        $skills = [];
        foreach ($classes as $classname) {
            $instance = self::instantiate_if_supported($classname, skill_interface::class);
            if (!$instance instanceof skill_interface) {
                continue;
            }

            try {
                $skillname = trim($instance->get_name());
            } catch (\Throwable $e) {
                self::add_diagnostic('Skipping discovered skill that failed get_name(): ' . $classname, $e);
                continue;
            }

            if ($skillname === '' || isset($skills[$skillname])) {
                if ($skillname === '') {
                    self::add_diagnostic('Skipping discovered skill with empty skill name: ' . $classname);
                } else {
                    self::add_diagnostic('Skipping duplicate discovered skill name: ' . $skillname . ' from ' . $classname);
                }
                continue;
            }

            $skills[$skillname] = $instance;
        }

        ksort($skills);
        return $skills;
    }

    /**
     * Return all discovered trigger providers for the given component.
     *
     * Discovery is intentionally broad: every PHP class below
     * classes/local/wizard is considered. Classes without the explicit trigger
     * provider interface are ignored silently.
     *
     * @param string $component
     * @return skill_trigger_provider_interface[]
     */
    public static function get_trigger_provider_instances(string $component = 'bookingextension_agent'): array {
        $providers = [];

        $classes = self::find_candidate_classes($component);
        if (!empty($classes)) {
            engine_alias_registrar::ensure_component_aliases($component);
        }
        foreach ($classes as $classname) {
            $instance = self::instantiate_if_supported($classname, skill_trigger_provider_interface::class);
            if (!$instance instanceof skill_trigger_provider_interface) {
                continue;
            }

            $providers[] = $instance;
        }

        return $providers;
    }

    /**
     * Return diagnostics collected during the last discovery run.
     *
     * @return string[]
     */
    public static function get_last_diagnostics(): array {
        return self::$lastdiagnostics;
    }

    /**
     * Find all PHP classes under a component's local/wizard tree.
     *
     * @param string $component
     * @return string[]
     */
    private static function find_candidate_classes(string $component): array {
        [$plugintype, $pluginname] = core_component::normalize_component($component);
        if ($plugintype === 'core' || empty($pluginname)) {
            return [];
        }

        $plugindir = core_component::get_plugin_directory($plugintype, $pluginname);
        if (empty($plugindir)) {
            return [];
        }

        $basedir = $plugindir . '/classes/local/wizard';
        if (!is_dir($basedir)) {
            return [];
        }

        $classes = [];
        $classesdir = $plugindir . '/classes/';
        $skilldirs = self::get_skill_directories($basedir);

        foreach ($skilldirs as $skilldir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($skilldir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileinfo) {
                if (!$fileinfo->isFile() || $fileinfo->getExtension() !== 'php') {
                    continue;
                }

                $path = $fileinfo->getPathname();
                if (strpos($path, '/tests/') !== false) {
                    continue;
                }

                $relative = substr($path, strlen($classesdir));
                if ($relative === false || $relative === '') {
                    continue;
                }

                $relativeclass = str_replace(['/', '.php'], ['\\', ''], $relative);
                $classes[] = core_component::normalize_componentname($component) . '\\' . $relativeclass;
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * Return canonical skill directories below local/wizard.
     *
     * @param string $basedir
     * @return string[]
     */
    private static function get_skill_directories(string $basedir): array {
        $skilldirs = [];
        $iterator = new \DirectoryIterator($basedir);
        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isDir() || $fileinfo->isDot()) {
                continue;
            }

            $skilldir = $fileinfo->getPathname() . '/skills';
            if (is_dir($skilldir)) {
                $skilldirs[] = $skilldir;
            }
        }

        sort($skilldirs);
        return $skilldirs;
    }

    /**
     * Instantiate a class and return it when it implements the expected interface.
     *
     * @param string $classname
     * @param string $interfacename
     * @return object|null
     */
    private static function instantiate_if_supported(string $classname, string $interfacename): ?object {
        try {
            self::ensure_class_loaded($classname);

            $reflection = new \ReflectionClass($classname);
            if ($reflection->isAbstract()) {
                return null;
            }

            $instance = $reflection->newInstance();
        } catch (\Throwable $e) {
            self::add_diagnostic('Skipping discovered class that cannot be instantiated: ' . $classname, $e);
            return null;
        }

        return $instance instanceof $interfacename ? $instance : null;
    }

    /**
     * Ensure a discovered class file is loaded even when classloader caches are stale.
     *
     * New classes can be visible in filesystem discovery before Moodle's runtime
     * class map notices them. Resolve the expected file path from the component
     * namespace and include it directly as a safe fallback.
     *
     * @param string $classname
     * @return void
     */
    private static function ensure_class_loaded(string $classname): void {
        if (class_exists($classname, false)) {
            return;
        }

        $parts = explode('\\', $classname);
        if (count($parts) < 3) {
            return;
        }

        $component = $parts[0] . '_' . $parts[1];
        [$plugintype, $pluginname] = core_component::normalize_component($component);
        if ($plugintype === 'core' || empty($pluginname)) {
            return;
        }

        $plugindir = core_component::get_plugin_directory($plugintype, $pluginname);
        if (empty($plugindir)) {
            return;
        }

        $relativeparts = array_slice($parts, 2);
        if (empty($relativeparts)) {
            return;
        }

        $file = $plugindir . '/classes/' . implode('/', $relativeparts) . '.php';
        if (is_file($file)) {
            require_once($file);
        }
    }

    /**
     * Add a discovery diagnostic.
     *
     * @param string $message
     * @param \Throwable|null $exception
     * @return void
     */
    private static function add_diagnostic(string $message, ?\Throwable $exception = null): void {
        if ($exception !== null) {
            $message .= ' (' . get_class($exception) . ': ' . $exception->getMessage() . ')';
        }

        self::$lastdiagnostics[] = $message;
    }

    /**
     * Sort skill classes so domain-specific namespaces win over core defaults.
     *
     * @param string $left
     * @param string $right
     * @return int
     */
    private static function compare_skill_classes(string $left, string $right): int {
        $leftpriority = self::get_namespace_priority($left);
        $rightpriority = self::get_namespace_priority($right);

        if ($leftpriority !== $rightpriority) {
            return $rightpriority <=> $leftpriority;
        }

        return strcmp($left, $right);
    }

    /**
     * Return namespace priority for duplicate skill resolution.
     *
     * @param string $classname
     * @return int
     */
    private static function get_namespace_priority(string $classname): int {
        $marker = '\\local\\wizard\\';
        $position = strpos($classname, $marker);
        if ($position === false) {
            return 0;
        }

        $remainder = substr($classname, $position + strlen($marker));
        if ($remainder === false || $remainder === '') {
            return 0;
        }

        $parts = explode('\\', $remainder);
        if (count($parts) < 2 || $parts[1] !== 'skills') {
            return 0;
        }

        return $parts[0] === 'core' ? 0 : 1;
    }
}
