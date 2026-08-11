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

namespace mod_booking;

use context;
use context_module;
use context_system;
use core_external\external_api;
use require_login_exception;
/**
 * Helper functions to check permissions.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class permissions {
    /**
     * Checks a capability in all possible contexts. It's expensive.
     * @param string $capability
     * @param int $contextlevel
     * @return bool
     */
    public static function has_capability_anywhere($capability = 'moodle/course:manageactivities', $contextlevel = CONTEXT_MODULE) {

        global $DB;

        $sql = "SELECT ctx.id AS contextid
            FROM {context} ctx
            WHERE ctx.contextlevel = :contextlevel";

        $contextids = $DB->get_fieldset_sql($sql, ['contextlevel' => $contextlevel]);

        foreach ($contextids as $contextid) {
            $context = context::instance_by_id($contextid);
            if (has_capability($capability, $context)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks if the current user is allowed to edit booking options anywhere in the system.
     *
     * This is the gate for webservices which back form elements of the booking option form
     * (autocomplete selectors, templates etc.): as we cannot know for which context the user
     * is currently editing, we accept any of the option editing capabilities in any context.
     * It's expensive, so only use it in webservices which are called by option editors.
     *
     * @return bool
     */
    public static function has_any_booking_editing_capability(): bool {
        return self::has_capability_anywhere('mod/booking:limitededitownoption')
            || self::has_capability_anywhere('mod/booking:addeditownoption')
            || self::has_capability_anywhere('mod/booking:updatebooking');
    }

    /**
     * Require that the current user is allowed to edit booking options anywhere in the system.
     *
     * @return void
     * @throws \moodle_exception if the user has none of the option editing capabilities
     */
    public static function require_any_booking_editing_capability(): void {
        if (!self::has_any_booking_editing_capability()) {
            throw new \moodle_exception('nopermissions', 'error', '', 'edit booking options');
        }
    }

    /**
     * Validates the execution context of the user-facing booking webservices
     * (the booking chain: allow to add to cart, loading of the pre booking pages,
     * booking itself; furthermore the notification list toggle, the option
     * description modal and the slot picker services).
     *
     * Booking options are regularly presented outside of their own course, e.g. in
     * shortcode lists, so the booking users are often not (or not yet) enrolled in
     * the course of the booking instance. Like when booking directly, users holding
     * mod/booking:choose on the booking instance (the capability the "allowed to book
     * in instance" condition checks as well) may book anyway: for them the system
     * context is validated instead, which still enforces an active login and session.
     * All other users need full access to the course of the booking instance.
     *
     * @param int $cmid course module id of the booking instance, 0 if unknown
     * @return void
     * @throws require_login_exception if the user may not access the booking instance
     */
    public static function validate_context_for_booking(int $cmid): void {
        if (empty($cmid)) {
            external_api::validate_context(context_system::instance());
            return;
        }
        $modulecontext = context_module::instance($cmid);
        try {
            external_api::validate_context($modulecontext);
        } catch (require_login_exception $e) {
            if (!has_capability('mod/booking:choose', $modulecontext)) {
                throw $e;
            }
            external_api::validate_context(context_system::instance());
        }
    }
}
