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

use cache;
use mod_booking\local\performance\actions\action_executor;
use mod_booking\local\performance\actions\execution_point;
use mod_booking\singleton_service;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Measures time performance for better tracking.
 *
 * @copyright Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class performance_facade {
    /**
     * Constructs performance class,
     * @param array $parameter
     * @return array
     */
    public static function execute(array $parameter): array {
        $status = false;
        performance_measurer::begin(
            $parameter['value'],
            $parameter['actions'],
            $parameter['note']
        );
        $actions = json_decode($parameter['actions']);
        $executor = new action_executor();

        $executiontimes = $actions->execution_times->times;
        try {
            // Actions before all cyrcles.
            $executor->execute(execution_point::BEFORE_ALL, $actions);
            for ($i = 1; $i <= $executiontimes; $i++) {
                $executor->execute(execution_point::BEFORE_EACH, $actions);
                self::set_cycle($i);
                self::start_measurement('Cycle');
                try {
                    $status = self::run_shortcode($parameter['value']);
                } catch (\Throwable $e) {
                    debugging("Shortcode execution error: " . $e->getMessage(), DEBUG_DEVELOPER);
                } finally {
                    self::end_measurement('Cycle');
                }

                // For a realistic measurement, we need to destroy the singletons.
                singleton_service::destroy_instance();
                // Becasue of static acceleration, we also need to destroy instances.
                // This does not purge the caches, only the instances in the memory.
                \core_cache\factory::reset();
            }
        } finally {
            performance_measurer::finish();
        }
        return [
            'status' => $status,
            'received' => $parameter['value'],
            'hashedreceived' => hash('sha256', $parameter['value']),
        ];
    }

    /**
     * Run the registered callback for a shortcode.
     *
     * @param string $shortcode
     * @return mixed|null Result of the callback, or null on failure.
     */
    public static function run_shortcode($shortcode) {
        global $PAGE;
        require_login();
        $PAGE->set_url('/mod/booking/performance.php');
        $PAGE->set_context(\context_system::instance());

        $executedshortcode = format_text($shortcode);
        if ($shortcode != $executedshortcode) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Starts a measurement.
     * @param string $name
     * @return void
     */
    public static function start_measurement($name) {
        $measurer = performance_measurer::instance();
        if (!$measurer) {
            return;
        }
        $measurer->start($name);
    }

    /**
     * Ends a measurement.
     * @param string $name
     * @return void
     */
    public static function end_measurement($name) {
        $measurer = performance_measurer::instance();
        if (!$measurer) {
            return;
        }

        $measurer->end($name);
    }

    /**
     * Sets the current cycle
     *
     * @param int $number
     *
     * @return void
     *
     */
    public static function set_cycle(int $number) {
        $measurer = performance_measurer::instance();
        $measurer->set_cycle($number);
    }
}
