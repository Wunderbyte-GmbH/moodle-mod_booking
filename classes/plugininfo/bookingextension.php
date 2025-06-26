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
 * Subplugin info class.
 *
 * @package   mod_booking
 * @copyright Wunderbyte GmbH 2025
 * @author    Bernhard Fischer-Sengseis
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_booking\plugininfo;

use core\plugininfo\base;

/**
 * Models subplugin define classes.
 */
class bookingextension extends base {
    /**
     * Returns the information about plugin availability
     *
     * True means that the plugin is enabled. False means that the plugin is
     * disabled. Null means that the information is not available, or the
     * plugin does not support configurable availability or the availability
     * can not be changed.
     *
     * @return null|bool
     */
    public function is_enabled() {
        return true;
    }

    /**
     * Should there be a way to uninstall the plugin via the administration UI.
     *
     * By default uninstallation is not allowed, plugin developers must enable it explicitly!
     *
     * @return bool
     */
    public function is_uninstall_allowed() {
        return true;
    }

    /**
     * Pre-uninstall hook.
     */
    public function uninstall_cleanup() {
        global $CFG;
        parent::uninstall_cleanup();
    }

    /**
     * Add an Option to col_action in the bookingoptions_wbtable.php
     *
     * @param object $settings
     * @param mixed $context
     *
     * @return string
     *
     */
    public static function add_options_to_col_actions(object $settings, mixed $context): string {
        return '';
    }

    /**
     * Returns array of allowed event keys for booking rule react on event.
     *
     * @return array
     *
     */
    public static function get_allowedruleeventkeys(): array {
        return [];
    }
}
