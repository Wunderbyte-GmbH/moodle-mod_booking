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
 * Access declarations for tables that are shown on pages with a capability check.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

use context;
use local_wunderbyte_table\wunderbyte_table;

/**
 * Access declarations for tables that are shown on pages with a capability check.
 *
 * A table is cached and reloaded by its hash (paging, filters, downloads, actions). The page
 * that shows the table is not involved in these requests, so the table repeats the check of
 * the page. The check is done in the context the page uses for it, which can differ from the
 * context the table validates the login in.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class table_access {
    /**
     * Describes the audience "users with at least one of the capabilities in the context".
     *
     * @param context $context
     * @param string[] $capabilities
     * @return array with the keys callback and args, for wunderbyte_table::declare_access_by_callback()
     */
    public static function any_capability(context $context, array $capabilities): array {
        return [
            'callback' => [self::class, 'any_capability_callback'],
            'args' => [
                'contextid' => (int)$context->id,
                'capabilities' => implode(',', $capabilities),
            ],
        ];
    }

    /**
     * Declares that a table is for users with at least one of the capabilities in the context.
     *
     * @param wunderbyte_table $table
     * @param context $context
     * @param string[] $capabilities
     * @return void
     */
    public static function declare_any_capability(wunderbyte_table $table, context $context, array $capabilities): void {
        self::declare($table, self::any_capability($context, $capabilities));
    }

    /**
     * Declares an audience on a table.
     *
     * @param wunderbyte_table $table
     * @param array $access with the keys callback and args, see any_capability()
     * @return void
     */
    public static function declare(wunderbyte_table $table, array $access): void {
        $table->declare_access_by_callback($access['callback'], $access['args'] ?? []);
    }

    /**
     * Access check, called by the table for the current user.
     *
     * @param wunderbyte_table $table
     * @param array $args contextid and capabilities, see any_capability()
     * @return bool
     */
    public static function any_capability_callback(wunderbyte_table $table, array $args): bool {
        $context = context::instance_by_id((int)($args['contextid'] ?? 0), IGNORE_MISSING);
        if (empty($context)) {
            // The booking instance or course of the table does not exist any more.
            return false;
        }
        foreach (explode(',', (string)($args['capabilities'] ?? '')) as $capability) {
            if ($capability !== '' && has_capability($capability, $context)) {
                return true;
            }
        }
        return false;
    }
}
