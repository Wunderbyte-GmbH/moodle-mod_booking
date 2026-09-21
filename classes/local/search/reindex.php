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
 * Keeps the search index in sync with changes of related data.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\search;

use mod_booking\booking_option;

/**
 * Keeps the search index in sync with changes of related data.
 *
 * Core search drives incremental indexing off one modified timestamp per document. Changes of
 * related data (custom field values, teacher assignments, the name of the connected course)
 * do not touch booking_options.timemodified on their own, so the observers of this plugin bump
 * it through this class. That keeps get_document_recordset() a plain timestamp query and also
 * gives every other consumer of timemodified an honest value.
 */
class reindex {
    /**
     * Bump the modification date of one booking option.
     *
     * @param int $optionid
     * @return void
     */
    public static function mark_option_modified(int $optionid): void {
        self::mark_options_modified([$optionid]);
    }

    /**
     * Bump the modification date of several booking options.
     *
     * @param array $optionids
     * @return void
     */
    public static function mark_options_modified(array $optionids): void {
        global $DB;

        $optionids = array_values(array_unique(array_filter(array_map('intval', $optionids))));

        if (empty($optionids)) {
            return;
        }

        if (!self::indexing_is_relevant()) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($optionids, SQL_PARAMS_NAMED);

        $DB->execute(
            "UPDATE {booking_options} SET timemodified = :now WHERE id $insql",
            array_merge($inparams, ['now' => time()])
        );

        foreach ($optionids as $optionid) {
            booking_option::purge_cache_for_option($optionid);
        }
    }

    /**
     * Bump the modification date of all booking options connected to a course.
     *
     * Used when the name of the connected course changes, which is part of the option document.
     *
     * @param int $courseid
     * @return void
     */
    public static function mark_options_of_connected_course_modified(int $courseid): void {
        global $DB;

        if ($courseid <= 0 || !self::indexing_is_relevant()) {
            return;
        }

        $optionids = $DB->get_fieldset_select('booking_options', 'id', 'courseid = :courseid', ['courseid' => $courseid]);

        self::mark_options_modified($optionids);
    }

    /**
     * Bump the modification date of the booking option a subbooking belongs to.
     *
     * @param int $subbookingid
     * @return void
     */
    public static function mark_option_of_subbooking_modified(int $subbookingid): void {
        global $DB;

        if ($subbookingid <= 0 || !self::indexing_is_relevant()) {
            return;
        }

        $optionid = $DB->get_field('booking_subbooking_options', 'optionid', ['id' => $subbookingid], IGNORE_MISSING);

        if (empty($optionid)) {
            return;
        }

        self::mark_option_modified((int) $optionid);
    }

    /**
     * Ask core search to index the booking areas again from scratch.
     *
     * Used when a change affects many documents at once and bumping every single booking option
     * would mean a site wide write, for example when the definition of a custom field changes or
     * when the indexed content sources are reconfigured.
     *
     * @return void
     */
    public static function request_full_reindex(): void {
        if (!self::indexing_is_relevant()) {
            return;
        }

        foreach (['mod_booking-bookingoption', 'mod_booking-subbooking', 'mod_booking-activity'] as $areaid) {
            \core_search\manager::request_index(\context_system::instance(), $areaid);
        }
    }

    /**
     * Whether it is worth writing reindex information at all.
     *
     * On sites without global search the bump would only cost write operations.
     *
     * @return bool
     */
    protected static function indexing_is_relevant(): bool {
        if (!class_exists('\core_search\manager')) {
            return false;
        }

        return \core_search\manager::is_global_search_enabled();
    }
}
