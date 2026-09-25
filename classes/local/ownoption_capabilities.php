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
 * Migration of mod/booking:addeditownoption to the capabilities for own booking options.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

/**
 * Migration of mod/booking:addeditownoption to the capabilities for own booking options.
 *
 * The capabilities that replace addeditownoption (CLONED_CAPABILITIES) clone
 * its permissions ('clonepermissionsfrom' in db/access.php). Moodle only clones
 * into capabilities that are NEW in the database. On sites where some of them
 * are installed already, upgrade.php calls this class first, before Moodle
 * removes addeditownoption.
 *
 * cancelownoption and duplicateownoption are new actions that addeditownoption
 * never allowed. They are not cloned and not touched here, otherwise the
 * upgrade alone would let every former addeditownoption role cancel and
 * duplicate (Wunderbyte-GmbH/moodle-mod_booking#1602).
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ownoption_capabilities {
    /** @var string The capability that was split up. */
    public const OLD_CAPABILITY = 'mod/booking:addeditownoption';

    /** @var string[] The capabilities that replace it and inherit its permissions. */
    public const CLONED_CAPABILITIES = [
        'mod/booking:editownoption',
        'mod/booking:managebookingsownoption',
        'mod/booking:sendmailownoption',
        'mod/booking:editteachersownoption',
        'mod/booking:viewteacherreports',
    ];

    /** @var string[] New actions for own options that addeditownoption never allowed. They are not cloned. */
    public const NOT_CLONED_CAPABILITIES = [
        'mod/booking:cancelownoption',
        'mod/booking:duplicateownoption',
    ];

    /** @var string[] All capabilities for own booking options. */
    public const NEW_CAPABILITIES = [
        ...self::CLONED_CAPABILITIES,
        ...self::NOT_CLONED_CAPABILITIES,
    ];

    /**
     * Copies every role permission of addeditownoption (all roles, all contexts,
     * incl. overrides) to the cloned capabilities that already exist in the database.
     * The not cloned capabilities keep their permissions.
     *
     * The permissions these capabilities had before are removed first, so they end
     * up exactly equal to addeditownoption - like a clone of a new capability.
     *
     * @return int number of copied permissions
     */
    public static function copy_permissions_to_installed_capabilities(): int {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(self::CLONED_CAPABILITIES);
        $installed = $DB->get_fieldset_select('capabilities', 'name', "name $insql", $params);
        if (empty($installed) || !$DB->record_exists('capabilities', ['name' => self::OLD_CAPABILITY])) {
            return 0;
        }
        $oldpermissions = $DB->get_records('role_capabilities', ['capability' => self::OLD_CAPABILITY]);

        $now = time();
        $copied = 0;
        foreach ($installed as $capability) {
            $DB->delete_records('role_capabilities', ['capability' => $capability]);
            foreach ($oldpermissions as $old) {
                $DB->insert_record('role_capabilities', (object)[
                    'roleid' => $old->roleid,
                    'contextid' => $old->contextid,
                    'capability' => $capability,
                    'permission' => $old->permission,
                    'timemodified' => $now,
                    'modifierid' => $old->modifierid,
                ]);
                $copied++;
            }
        }

        accesslib_clear_all_caches(true);
        return $copied;
    }
}
