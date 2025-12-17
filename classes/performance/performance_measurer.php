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
 * Handle fields for booking option.
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\performance;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Measures time performance for better tracking.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class performance_measurer {
    /**
     * @var string
     */
    CONST TABLE = 'booking_performance_measurements';

    /**
     * @var string $shortcodehash
     */
    private string $shortcodehash = '';

    /**
     * @var string $shortcodename
     */
    private string $shortcodename = '';

    /**
     * Constructs performance class,
     * @param string $shortcodehash
     * @return void
     */
    public function __construct($shortcodehash) {
        $this->shortcodename = $shortcodehash;
        $this->shortcodehash = hash('sha256', $shortcodehash);
        return;
    }

    /**
     * Constructs performance class,
     * @param string $hash
     * @return void
     */
    public function start($name) {
        $openmeasurements = $this->has_open_measurement_with_name($name);
        if ($openmeasurements) {
            $this->delete_measurements($openmeasurements);
        }
        $record = [
            'starttime' => time(),
            'measurementname' => $name,
            'shortcodehash' => $this->shortcodehash,
            'shortcodename' => $this->shortcodename,
        ];
        $this->open_measurement($record);
        return;
    }

    /**
     * Constructs performance class,
     * @param string $hash
     * @return array|bool
     */
    private function has_open_measurement_with_name($name) {
        global $DB;
        $conditions = [
            'endtime' => null,
            'measurementname' => $name,
            'shortcodehash' => $this->shortcodehash,
        ];
        return $DB->get_records(self::TABLE, $conditions);
    }

    /**
     * Constructs performance class,
     * @param string $hash
     * @return void
     */
    private function delete_measurements($openmeasurements) {
        global $DB;

        if (empty($openmeasurements)) {
            return;
        }

        $ids = array_map(
            static fn($measurement) => $measurement->id,
            $openmeasurements
        );

        list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);

        $DB->delete_records_select(
            self::TABLE,
            "id $insql",
            $params
        );
        return;
    }

    /**
     * Constructs performance class,
     * @param array $record
     * @return void
     */
    private function open_measurement($record) {
        global $DB;
        $DB->insert_record(self::TABLE, $record);
        return;
    }

    /**
     * Constructs performance class,
     * @param string $hash
     * @return void
     */
    public function end($name) {
        global $DB;

        $conditions = [
            'shortcodehash' => $this->shortcodehash,
            'measurementname' => $name,
            'endtime'  => 0,
        ];

        $DB->set_field(
            self::TABLE,
            'endtime',
            time(),
            $conditions
        );
        return;
    }

    /**
     * Constructs performance class,
     * @return void
     */
    public function delete_all_open_measurement() {
        global $DB;
        $conditions = [
            'endtime' => null,
            'shortcodehash' => $this->hash,
        ];
        $DB->delete_records(self::TABLE, $conditions);
        return;
    }
}
