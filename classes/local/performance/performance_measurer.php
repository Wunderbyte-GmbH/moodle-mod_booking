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

namespace mod_booking\local\performance;

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
    const TABLE = 'booking_performance_measurements';

    /**
     * @var string $shortcodehash
     */
    private string $shortcodehash = '';

    /**
     * @var string $note
     */
    private string $note = '';

    /**
     * @var string $shortcodename
     */
    private string $shortcodename = '';

    /**
     * @var string $actions
     */
    private string $actions = '';

    /**
     * @var int $cycle
     */
    private int $cycle = 0;

    /**
     * @var self $instance
     */
    private static ?self $instance = null;

    /**
     * @var bool $active
     */
    private static bool $active = false;

    /**
     * @var array $measurements
     */
    private static array $measurements = [];

    /**
     * Constructs performance class,
     * @param string $shortcodehash
     * @return void
     */
    private function __construct($shortcodehash, $actions, $note) {
        $this->shortcodename = $shortcodehash;
        $this->shortcodehash = hash('sha256', $shortcodehash);
        $this->note = $note;
        $this->actions = $actions;
        return;
    }

    /**
     * Constructs performance class,
     * @param string $hash
     * @param bool $nocycle
     * @return void
     */
    public function start($name, $nocycle = false) {
        if (!self::$active) {
            return;
        }
        $cycle = $this->get_cycle();

        if (!$nocycle) {
            $name = "$name - $cycle";
        }

        $starttime = (int) (microtime(true) * 1_000_000);

        // If we have opened this measurepoint, we add the time.
        if (isset(self::$measurements[$name])) {
            self::$measurements[$name]['relstarttime'] = $starttime;
            return;
        }

        $openmeasurements = $this->has_open_measurement_with_name($name);
        if ($openmeasurements) {
            $this->delete_measurements($openmeasurements);
        }

        $record = [
            'starttime' => $starttime,
            'endtime' => 0,
            'measurementname' => $name,
            'shortcodehash' => $this->shortcodehash,
            'shortcodename' => $this->shortcodename,
            'actions' => $this->actions,
            'note' => $this->note,
        ];

        self::$measurements[$name]['starttime'] = $starttime;
        self::$measurements[$name]['relstarttime'] = $starttime;
        self::$measurements[$name]['delta'] = 0;
        self::$measurements[$name]['shortcodehash'] = $this->shortcodehash;
        $this->open_measurement($record);
        return;
    }

    /**
     * Constructs performance class,
     * @param string $name
     * @return array|bool
     */
    private function has_open_measurement_with_name($name) {
        global $DB;
        $conditions = [
            'endtime' => 0,
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

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);

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
     * @param bool $nocycle
     * @return void
     */
    public function end($name, $nocycle = false) {
        if (!self::$active) {
            return;
        }
        global $DB;

        $cycle = $this->get_cycle();

        if (!$nocycle) {
            $name = "$name - $cycle";
        }

        $endtime = (int) (microtime(true) * 1_000_000);
        $delta = $endtime - self::$measurements[$name]['relstarttime'];
        self::$measurements[$name]['delta'] += $delta;

        return;
    }

    /**
     * Constructs performance class,
     * @return void
     */
    public function delete_all_open_measurement() {
        global $DB;
        $conditions = [
            'endtime' => 0,
            'shortcodehash' => $this->shortcodehash,
        ];
        $DB->delete_records(self::TABLE, $conditions);
        return;
    }

    /**
     * Start root measurement and activate measurer
     * @param string $shortcode
     * @param string $actions
     * @param string $note
     * @return void
     */
    public static function begin(string $shortcode, string $actions, string $note): void {
        if (self::$active) {
            return;
        }

        self::$instance = new self($shortcode, $actions, $note);
        self::$active = true;

        self::$instance->start('Entire time', true);
    }

    /**
     * End root measurement and deactivate
     * @return void
     */
    public static function finish(): void {
        global $DB;

        if (!self::$active || !self::$instance) {
            return;
        }

        self::$instance->end('Entire time', true);

        foreach (self::$measurements as $name => $measurement) {
            $conditions = [
                'shortcodehash' => $measurement['shortcodehash'],
                'measurementname' => $name,
                'endtime'  => 0,
            ];

            $endtime = $measurement['starttime'] + $measurement['delta'];

            $DB->set_field(
                self::TABLE,
                'endtime',
                $endtime,
                $conditions
            );
        }

        self::$measurements = [];
        self::$instance = null;
        self::$active = false;
    }

    /**
     * Check if measurement is active
     * @return bool
     */
    public static function is_active(): bool {
        return self::$active && self::$instance !== null;
    }

    /**
     * Safe accessor
     * @return self
     */
    public static function instance(): ?self {
        return self::$instance;
    }

    /**
     * Sets the current cycle counter.
     *
     * @param int $number
     *
     * @return void
     *
     */
    public function set_cycle(int $number) {
        $this->cycle = $number;
    }

    /**
     * Gets the current cycle counter.
     *
     * @return int
     *
     */
    public function get_cycle() {
        return $this->cycle;
    }
}
