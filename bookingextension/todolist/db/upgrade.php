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
 * Upgrade steps for bookingextension_todolist.
 *
 * @package     bookingextension_todolist
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade callback.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_bookingextension_todolist_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026031301) {
        $itemtable = new xmldb_table('bookingextension_todolist_item');
        $itemtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $itemtable->add_field('optionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $itemtable->add_field('text', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $itemtable->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $itemtable->add_field('status', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $itemtable->add_field('completed_by', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $itemtable->add_field('completed_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $itemtable->add_field('created_by', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $itemtable->add_field('created_at', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $itemtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $itemtable->add_key('optionid_fk', XMLDB_KEY_FOREIGN, ['optionid'], 'booking_options', ['id']);
        $itemtable->add_key('completedby_fk', XMLDB_KEY_FOREIGN, ['completed_by'], 'user', ['id']);
        $itemtable->add_key('createdby_fk', XMLDB_KEY_FOREIGN, ['created_by'], 'user', ['id']);
        $itemtable->add_index('optionid_sortorder_idx', XMLDB_INDEX_NOTUNIQUE, ['optionid', 'sortorder']);
        $itemtable->add_index('optionid_status_idx', XMLDB_INDEX_NOTUNIQUE, ['optionid', 'status']);

        if (!$dbman->table_exists($itemtable)) {
            $dbman->create_table($itemtable);
        }

        $assignmenttable = new xmldb_table('bookingextension_todolist_assignment');
        $assignmenttable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $assignmenttable->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $assignmenttable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $assignmenttable->add_field('notified_at', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $assignmenttable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $assignmenttable->add_key('itemid_fk', XMLDB_KEY_FOREIGN, ['itemid'], 'bookingextension_todolist_item', ['id']);
        $assignmenttable->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $assignmenttable->add_index('itemid_userid_idx', XMLDB_INDEX_NOTUNIQUE, ['itemid', 'userid']);

        if (!$dbman->table_exists($assignmenttable)) {
            $dbman->create_table($assignmenttable);
        }

        upgrade_plugin_savepoint(true, 2026031301, 'bookingextension', 'todolist');
    }

    return true;
}
