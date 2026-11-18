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
 * Function to correctly upgrade mod_booking.
 *
 * @package    mod_booking
 * @copyright  2022 Wunderbyte GmbH <info@wunderbyte.at>
 * @author     Bernhard Fischer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Function to upgrade old ids which where part of the field "text"
 * within booking options and move them into the new DB field 'identifier'.
 * @return void
 */
function booking_migrate_option_identifiers_2022090802() {
    global $DB;
    if ($separator = get_config('booking', 'uniqueoptionnameseparator')) {
        if ($recordstomigrate = $DB->get_records('booking_options')) {
            foreach ($recordstomigrate as $record) {
                // Records that do not contain the separator do not need to be changed.
                if (strpos($record->text, $separator) == false) {
                    continue;
                }
                [$name, $identifier] = explode($separator, $record->text);
                /* Example: MyOption#?#4eded74a => the name "MyOption" will be restored
                and the identifier "4eded74a" will be moved to the identifier field. */
                $record->identifier = $identifier;
                $record->text = $name;
                $DB->update_record('booking_options', $record);
            }
        }
    }
}

/**
 * We renamed the column optionid to itemid,
 * so we need to set the area to "option" for each migrated row.
 * @return void
 */
function booking_migrate_optionids_for_prices_2022112901() {
    global $DB;
    if ($recordstomigrate = $DB->get_records('booking_prices')) {
        foreach ($recordstomigrate as $record) {
            $record->area = 'option';
            $DB->update_record('booking_prices', $record);
        }
    }
}

/**
 * With the new view.php we also introduced a new way to configure fields
 * for the list of booking options. So after the new view gets introduced,
 * we need to set all fields so nothing disappears.
 */
function booking_migrate_optionsfields_2023022800() {
    global $DB;
    if ($recordstomigrate = $DB->get_records('booking')) {
        foreach ($recordstomigrate as $record) {
            $record->optionsfields =
                'description,statusdescription,teacher,showdates,dayofweektime,location,institution,minanswers';
            $DB->update_record('booking', $record);
        }
    }
}

/**
 * Fix descriptionformat for all booking options.
 */
function booking_fix_bookingoption_descriptionformat_2024022700() {
    global $DB;
    $DB->execute(
        "UPDATE {booking_options}
        SET descriptionformat = 1
        WHERE descriptionformat = 0"
    );
}

/**
 * Fix showlistoncoursepage for all booking instances.
 */
function booking_fix_showlistoncoursepage_2024030801() {
    global $DB;
    $DB->execute(
        "UPDATE {booking}
        SET showlistoncoursepage = 1
        WHERE showlistoncoursepage = 2"
    );
}

/**
 * Migrate former bookingids to contextids.
 * @return void
 */
function booking_migrate_contextids_2024040901() {
    global $DB;

    $DB->execute(
        "UPDATE {booking_rules}
        SET contextid = 1"
    );
}

/**
 * Make sure we have no NULL value in template id.
 *
 * @return [type]
 *
 */
function booking_fix_templateid() {

    global $DB;

    $sql = "SELECT id, templateid
            FROM {booking}
            WHERE templateid IS NULL";
    $records = $DB->get_records_sql($sql);

    foreach ($records as $record) {
        $record->templateid = 0;
        $DB->update_record('booking', $record);
    }
}

/**
 * Function to add the "places" information to all the existing booking_answer records.
 *
 * @return void
 *
 */
function booking_fix_places_for_booking_answers() {

    global $DB;

    // Define your SQL update query.
    $sql = "UPDATE {booking_answers}
               SET places = 1
             WHERE places IS NULL";

    // Execute the query.
    $DB->execute($sql);
}

/**
 * Remove values form completiongradeitemnumber and completionpassgrade to avoid #779 error after #629.
 *
 * @return void
 */
function booking_remove_completiongradeitemnumber_2025010803() {
    global $DB;

    $bookingmoduleid = $DB->get_field('modules', 'id', ['name' => 'booking']);

    // Define your SQL update query.
    $sql = "UPDATE {course_modules}
        SET completiongradeitemnumber = null, completionpassgrade = 0
        WHERE module = :bookigmodules";

    // Define the parameters for the query.
    $params = ['bookigmodules' => $bookingmoduleid];

    // Execute the query.
    $DB->execute($sql, $params);
}

/**
 * Initialize the timecreated field for booking_options.
 * @return void
 */
function booking_options_initialize_timecreated() {
    global $DB;

    $sql = "UPDATE {booking_options}
               SET timecreated = timemodified
             WHERE timecreated = 0";

    // Execute the query.
    $DB->execute($sql);
}

/**
 * Updates booking_form_config JSON fields, changing id 425 to 391.
 */
function booking_upgrade_change_id_425_to_391() {
    global $DB;

    $records = $DB->get_records('booking_form_config');

    foreach ($records as $record) {
        $jsondata = json_decode($record->json, true);

        if (!is_array($jsondata)) {
            continue;
        }

        $updated = false;

        foreach ($jsondata as &$element) {
            if (isset($element['id']) && $element['id'] == 425) {
                $element['id'] = 391;
                $updated = true;
            }
        }
        unset($element); // Important: Break the reference after the loop!

        if ($updated) {
            $record->json = json_encode($jsondata, JSON_UNESCAPED_SLASHES);
            $DB->update_record('booking_form_config', $record);
        }
    }
}

/**
 * Migrate old selflearningcourse json flag to new booking option type.
 */
function booking_migrate_selflearningcourse_json_to_type_2025122201(): void {
    global $DB;
    // Fetch all booking options.
    $records = $DB->get_records('booking_options', [], '', 'id, json, type');
    if (empty($records)) {
        return;
    }
    foreach ($records as $record) {
        $type = 0; // Default type.
        if (!empty($record->json)) {
            $jsonobject = json_decode($record->json);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (
                    isset($jsonobject->selflearningcourse)
                    && $jsonobject->selflearningcourse == 1
                ) {
                    $type = 1; // Self-learning course type.
                }
            }
        }
        if ($record->type === null) {
            // Only update if type is not already set.
            $DB->set_field(
                'booking_options',
                'type',
                $type,
                ['id' => $record->id]
            );
        }
    }
}

/**
 * Delete custom fields in tool_certificate component.
 *
 * @return void
 */
function booking_delete_customfields_in_tool_certificate_2026030500(): void {
    global $DB;

    // Get the categories for this component.
    $categories = $DB->get_records('customfield_category', ['component' => 'tool_certificate']);

    foreach ($categories as $category) {
        $categorycontroller = \core_customfield\category_controller::create($category->id);
        try {
            $handler = \core_customfield\handler::get_handler(
                $category->component,
                $category->area,
                $category->itemid
            );
            $handler->delete_category($categorycontroller);
        } catch (moodle_exception $e) {
            // Might happen when plugin tool_certificate is not installed.
            // In this case, we can just ignore the error and continue.
            return;
        }
    }
}

/**
 * Delete all duplicated custom fields of a booking option (same optionid and cfgname),
 * keeping only the oldest row (lowest id) of every group.
 *
 * This runs as one set-based pass with bulk deletes, so it stays fast on big tables.
 * The previous implementation re-scanned the whole table once per deleted duplicate,
 * which could time out during upgrades on production sites.
 *
 * @return void
 */
function booking_delete_duplicate_customfields_2017112101(): void {
    global $DB;

    $sql = "SELECT bcf.id
              FROM {booking_customfields} bcf
              JOIN (SELECT optionid, cfgname, MIN(id) AS keepid
                      FROM {booking_customfields}
                  GROUP BY optionid, cfgname
                    HAVING COUNT(*) > 1) dup
                ON dup.optionid = bcf.optionid AND dup.cfgname = bcf.cfgname
             WHERE bcf.id <> dup.keepid";
    $duplicateids = $DB->get_fieldset_sql($sql);

    foreach (array_chunk($duplicateids, 1000) as $chunk) {
        [$insql, $inparams] = $DB->get_in_or_equal($chunk);
        $DB->delete_records_select('booking_customfields', "id $insql", $inparams);
    }
}

/**
 * Make shortcodename, shortcodehash and measurementname of booking_performance_measurements NOT NULL.
 *
 * Step 2026013000 created these columns nullable on upgraded sites, while install.xml declares them NOT NULL.
 * Existing NULL values become empty strings. The indexes on shortcodehash are dropped and re-created,
 * because the database manager refuses to change columns that are part of an index.
 *
 * @return void
 */
function booking_performance_measurements_notnull_2026101401(): void {
    global $DB;

    $dbman = $DB->get_manager();
    $table = new xmldb_table('booking_performance_measurements');
    if (!$dbman->table_exists($table)) {
        return;
    }

    $indexes = [
        new xmldb_index('hashx', XMLDB_INDEX_NOTUNIQUE, ['shortcodehash']),
        new xmldb_index('hashnameendx', XMLDB_INDEX_NOTUNIQUE, ['shortcodehash', 'endtime']),
    ];
    foreach ($indexes as $index) {
        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }
    }

    $fields = [
        new xmldb_field('shortcodename', XMLDB_TYPE_CHAR, '1333', null, XMLDB_NOTNULL, null, null, 'endtime'),
        new xmldb_field('shortcodehash', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'shortcodename'),
        new xmldb_field('measurementname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'shortcodehash'),
    ];
    foreach ($fields as $field) {
        $name = $field->getName();
        $DB->set_field_select('booking_performance_measurements', $name, '', "$name IS NULL");
        $dbman->change_field_notnull($table, $field);
    }

    foreach ($indexes as $index) {
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
    }
}

/**
 * Adds the missing cancellation deadline to purchases of booking options without dates.
 *
 * A fixed cancellation date of the booking instance ("Cancelling is only possible until certain date")
 * was not applied to booking options without a start time and to self-learning courses. Shopping cart
 * stores the deadline with every purchase at checkout and only checks this stored date, so these
 * purchases were stored without a deadline and can be cancelled forever. This sets the fixed date of
 * the instance for them, as it would have been stored at checkout.
 *
 * Only purchases without a deadline are changed and only paid and pending ones. Booking options
 * with dates never were affected: their purchases without a deadline were made while the instance
 * had no limit, so they keep it. Running it again changes nothing.
 *
 * Plain SQL on purpose: an upgrade step must not rely on the classes of the plugin (they expect the
 * final database structure) and there is no cashier logged in whom the API of shopping cart asks for.
 *
 * @return array ids of the changed rows of local_shopping_cart_history, grouped by the deadline that was set
 */
function booking_add_fixed_canceluntil_to_purchases_without_dates_2026111800(): array {
    global $DB;

    // Shopping cart is optional.
    if (!$DB->get_manager()->table_exists('local_shopping_cart_history')) {
        return [];
    }

    // The cancel mode and the fixed date of an instance are stored in its json.
    $bookings = $DB->get_records_select(
        'booking',
        $DB->sql_like('json', ':needle'),
        ['needle' => '%allowupdatetimestamp%'],
        'id ASC',
        'id, json'
    );

    $changed = [];
    foreach ($bookings as $booking) {
        $json = json_decode($booking->json ?? '');
        // The mode "fixed date" (MOD_BOOKING_CANCANCELBOOK_ABSOLUTE) is 0, so the key has to exist.
        if (
            !is_object($json)
            || !isset($json->cancelrelativedate)
            || (int)$json->cancelrelativedate !== 0
            || empty($json->allowupdatetimestamp)
        ) {
            continue;
        }
        $canceluntil = (int)$json->allowupdatetimestamp;

        // Payment status 0 is pending, 2 is paid (LOCAL_SHOPPING_CART_PAYMENT_PENDING and ..._SUCCESS).
        // Booking option type 1 is a self-learning course, its start time is only used for sorting.
        $historyids = $DB->get_fieldset_sql(
            "SELECT h.id
               FROM {local_shopping_cart_history} h
               JOIN {booking_options} bo ON bo.id = h.itemid
              WHERE h.componentname = :componentname
                    AND h.area = :area
                    AND h.paymentstatus IN (0, 2)
                    AND (h.canceluntil IS NULL OR h.canceluntil = 0)
                    AND bo.bookingid = :bookingid
                    AND (bo.coursestarttime IS NULL OR bo.coursestarttime = 0 OR bo.type = 1)
           ORDER BY h.id ASC",
            ['componentname' => 'mod_booking', 'area' => 'option', 'bookingid' => $booking->id]
        );

        // In chunks, databases limit the number of parameters of a query.
        foreach (array_chunk($historyids, 1000) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $DB->set_field_select('local_shopping_cart_history', 'canceluntil', $canceluntil, "id $insql", $inparams);
        }

        if (!empty($historyids)) {
            $changed[$canceluntil] = array_merge($changed[$canceluntil] ?? [], array_map('intval', $historyids));
        }
    }

    return $changed;
}

/**
 * Deletes the calendar events of cancelled booking options.
 *
 * Until now cancelling a booking option only deleted the calendar events of the booked users. The
 * instance-wide events (course or site events) and the events of the teachers stayed in the calendar.
 * Cancelling deletes all of them now, this does the same for the options that are already cancelled.
 *
 * Deleted are the instance-wide events of the option (found by their uuid "optionid-optiondateid" and
 * by the reference in booking_optiondates) and all its user events (tracked in booking_userevents).
 * Events of other components and of options that are not cancelled are not touched. Running it again
 * changes nothing.
 *
 * Plain SQL on purpose: an upgrade step must not rely on the classes of the plugin (they expect the
 * final database structure).
 *
 * @return array number of deleted calendar events, indexed by the id of the cancelled booking option
 */
function booking_delete_calendar_events_of_cancelled_options_2026111801(): array {
    global $DB;

    $deleted = [];
    // Status 1 is cancelled, see booking_option::cancelbookingoption().
    $optionids = $DB->get_fieldset_select('booking_options', 'id', 'status = 1', [], 'id ASC');
    foreach ($optionids as $optionid) {
        // Instance-wide events by uuid. Events of other components (e.g. imported iCal events) may have a
        // uuid starting the same way, so the component is part of the condition.
        $eventids = $DB->get_fieldset_select(
            'event',
            'id',
            "component = 'mod_booking' AND eventtype <> 'user' AND " . $DB->sql_like('uuid', ':pattern'),
            ['pattern' => $DB->sql_like_escape($optionid . '-') . '%']
        );
        // Instance-wide events the dates of the option refer to.
        $eventids = array_merge($eventids, $DB->get_fieldset_sql(
            "SELECT od.eventid
               FROM {booking_optiondates} od
              WHERE od.optionid = :optionid AND od.eventid > 0",
            ['optionid' => $optionid]
        ));
        // User events of teachers (and of booked users, if any are left).
        $eventids = array_merge($eventids, $DB->get_fieldset_sql(
            "SELECT bue.eventid
               FROM {booking_userevents} bue
              WHERE bue.optionid = :optionid AND bue.eventid > 0",
            ['optionid' => $optionid]
        ));
        $eventids = array_unique(array_map('intval', $eventids));

        $count = 0;
        // In chunks, databases limit the number of parameters of a query.
        foreach (array_chunk($eventids, 1000) as $chunk) {
            [$insql, $inparams] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            $count += $DB->count_records_select('event', "id $insql", $inparams);
            $DB->delete_records_select('event', "id $insql", $inparams);
        }

        // The references to the deleted events.
        $DB->delete_records('booking_userevents', ['optionid' => $optionid]);
        $DB->set_field_select('booking_optiondates', 'eventid', null, 'optionid = :optionid AND eventid IS NOT NULL', [
            'optionid' => $optionid,
        ]);
        $DB->set_field_select('booking_options', 'calendarid', 0, 'id = :id AND calendarid <> 0', ['id' => $optionid]);

        if ($count > 0) {
            $deleted[(int)$optionid] = $count;
        }
    }

    return $deleted;
}
