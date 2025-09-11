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

namespace mod_booking\local\competencies;

use cache;

/**
 * Handler class for user competencies.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author     Bernhard Fischer
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class competencies_handler {
    /** @var array Static cache of user competencies */
    protected static $usercompetencies = [];

    /** @var array Static cache of competency shortnames */
    protected static $competencyshortnames = [];

    /**
     * Get all competency IDs for a given user.
     *
     * @param int $userid
     * @param int|null $timestamp Optional creation timestamp to filter competencies.
     * @return array competency IDs
     */
    public static function get_user_competency_ids(int $userid, ?int $timestamp = null): array {
        global $DB;
        // First, check static cache.
        if (isset(self::$usercompetencies[$userid])) {
            return self::$usercompetencies[$userid];
        }

        // Define Moodle cache (store per user).
        $cache = cache::make('mod_booking', 'usercompetenciescache');

        // Check Moodle cache.
        $competencyids = $cache->get($userid);

        if ($competencyids !== false) {
            self::$usercompetencies[$userid] = $competencyids;
            return $competencyids;
        }

        // Fallback: Query the database.
        if (!empty($timestamp)) {
            // Query competencies created before the provided timestamp.
            $sql = "SELECT competencyid
                    FROM {competency_usercomp}
                    WHERE userid = :userid AND timecreated <= :timestamp";
            $params = ['userid' => $userid, 'timestamp' => $timestamp];
            $records = $DB->get_records_sql($sql, $params);
        } else {
            // Query all competencies for the user.
            $records = $DB->get_records('competency_usercomp', ['userid' => $userid], '', 'competencyid');
        }
        $competencyids = array_keys($records);

        // Store in both caches.
        self::$usercompetencies[$userid] = $competencyids;
        $cache->set($userid, $competencyids);

        return $competencyids;
    }

    /**
     * Get shortname of competency by id.
     *
     * @param int $competencyid the competency id
     * @return string shortname of the competency
     */
    public static function get_competency_shortname_by_id(int $competencyid): string {
        global $DB;
        // First, check static cache.
        if (isset(self::$competencyshortnames[$competencyid])) {
            return self::$competencyshortnames[$competencyid];
        }

        // Define Moodle cache (store per user).
        $cache = cache::make('mod_booking', 'competenciesshortnamescache');

        // Check Moodle cache.
        $competencyshortname = $cache->get($competencyid);

        if ($competencyshortname !== false) {
            self::$competencyshortnames[$competencyid] = $competencyshortname;
            return $competencyshortname;
        }

        // Fallback: Query the database.
        $shortname = $DB->get_field('competency', 'shortname', ['id' => $competencyid]);
        if (empty($shortname)) {
            debugging('Competencies_handler: Competency with id ' . $competencyid . ' not found.', DEBUG_DEVELOPER);
            return '';
        }
        // Store in both caches.
        self::$competencyshortnames[$competencyid] = $shortname;
        $cache->set($competencyid, $shortname);

        return $shortname;
    }

    /**
     * Check if a user has a specific competency.
     *
     * @param int $userid
     * @param int $competencyid
     * @param int|null $timestamp Optional creation timestamp to filter competencies.
     * @return bool
     */
    public static function user_has_competency(int $userid, int $competencyid, ?int $timestamp = null): bool {
        $competencyids = self::get_user_competency_ids($userid, $timestamp);
        return in_array($competencyid, $competencyids);
    }
}
