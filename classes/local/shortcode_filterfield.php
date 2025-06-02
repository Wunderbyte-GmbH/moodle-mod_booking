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
 * The cartstore class handles the in and out of the cache.
 *
 * @package mod_booking
 * @author Magdalena Holczik
 * @copyright 2025 Wunderbyte GmbH
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local;

/**
 * Mimic structure of customfields in order to apply filter to shortcode.
 */
class shortcode_filterfield {
    /**
     * Name of the colum of the field.
     *
     * @var string $shortname
     */
    public $shortname;

    /**
     * Data where information like multiple is stored.
     *
     * @var string $configdata
     */
    public $configdata;

    /**
     * Construct the field.
     *
     * @param string $shortname
     * @param bool $multiselect
     *
     */
    public function __construct(string $shortname, bool $multiselect = false) {
        $this->shortname = $shortname;

        // We simulate the configdata object.
        $this->configdata = json_encode([
            'multiselect' => $multiselect,
        ]);
    }

    /**
     * Check if column exists in given table.
     *
     * @param string $tablename
     *
     * @return bool
     *
     */
    public function verify_field(string $tablename = 'booking_options'): bool {
        global $DB;

        $columns = $DB->get_columns($tablename);
        return array_key_exists($this->shortname, $columns);
    }

}
