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

namespace bookingextension_agent\local\wizard\services\activities;

use context_course;
use stdClass;

/**
 * Lists the activity modules a user may add to a course and resolves a module name deterministically.
 *
 * Skill-owned domain knowledge: the curated whitelist of modules the generic add_activity skill supports
 * lives here, not in the engine. A module is offered only when it is installed, allowed in the course, and
 * the acting user natively holds mod/<modname>:addinstance at the course context (Gate 2, by-the-user).
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class module_catalog_service {
    /**
     * Curated whitelist of simple, formally stable content modules (blueprint §7).
     *
     * These are the modules whose "validate-and-create" hull the generic skill can build reliably.
     * Modules whose value lives in a follow-up workflow (e.g. quiz) get a dedicated skill instead.
     *
     * @var string[]
     */
    public const WHITELIST = ['page', 'url', 'label', 'book', 'folder', 'forum'];

    /**
     * Return the modules from the whitelist the user may currently add to the course.
     *
     * @param stdClass $course
     * @param int $userid
     * @return array[] Ordered by human label.
     */
    public function list_addable_modules(stdClass $course, int $userid): array {
        global $DB;

        $names = get_module_types_names();
        $user = \core_user::get_user($userid, '*', IGNORE_MISSING) ?: null;
        // Site-level enabled modules (the module chooser hides disabled ones).
        $enabled = $DB->get_records_menu('modules', ['visible' => 1], '', 'name, id');

        $modules = [];
        foreach (self::WHITELIST as $modname) {
            // Installed (and thus has a human-readable name)?
            if (!array_key_exists($modname, $names)) {
                continue;
            }
            // Enabled site-wide?
            if (!array_key_exists($modname, $enabled)) {
                continue;
            }
            // Allowed in this course AND the acting user natively holds mod/<modname>:addinstance.
            if (!course_allowed_module($course, $modname, $user)) {
                continue;
            }
            $modules[] = [
                'modname' => $modname,
                'label' => (string)$names[$modname],
            ];
        }

        usort($modules, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));
        return $modules;
    }

    /**
     * Resolve a user-provided module name against the addable modules.
     *
     * Tries the canonical module name (e.g. "page"), then an exact case-insensitive match on the human
     * label (e.g. "Text and media area"), then a substring match on the label. The planner never invents
     * internal module names; it passes the user's wording, which we map deterministically here.
     *
     * @param array[] $addable
     * @param string $query
     * @return array[] The matching subset (0, 1, or many).
     */
    public function resolve_module_name(array $addable, string $query): array {
        $needle = \core_text::strtolower(trim($query));
        if ($needle === '') {
            return [];
        }

        // 1) Canonical module name or exact label match.
        $exact = [];
        foreach ($addable as $module) {
            $modname = \core_text::strtolower($module['modname']);
            $label = \core_text::strtolower($module['label']);
            if ($modname === $needle || $label === $needle) {
                $exact[] = $module;
            }
        }
        if (!empty($exact)) {
            return $exact;
        }

        // 2) Substring match on the human label.
        $partial = [];
        foreach ($addable as $module) {
            if (
                str_contains(\core_text::strtolower($module['label']), $needle)
                || str_contains(\core_text::strtolower($module['modname']), $needle)
            ) {
                $partial[] = $module;
            }
        }
        return $partial;
    }

    /**
     * Whether a module name is on the supported whitelist.
     *
     * @param string $modname
     * @return bool
     */
    public function is_whitelisted(string $modname): bool {
        return in_array(\core_text::strtolower(trim($modname)), self::WHITELIST, true);
    }
}
