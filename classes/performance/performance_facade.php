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

use mod_booking\performance\actions\action_executor;
use mod_booking\performance\actions\execution_point;
use mod_booking\performance\actions\execution_times;

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
     * @param string $shortcodehash
     * @return array
     */
    public static function execute($shortcode, $actions) {
        $status = false;
        performance_measurer::begin($shortcode, $actions);
        $actions = json_decode($actions);
        $executor = new action_executor();

        $executiontimes = $actions->execution_times->times;
        try {
            // Actions before all cyrcles.
            $executor->execute(execution_point::BEFORE_ALL);
            for ($i = 1; $i <= $executiontimes; $i++) {
                $executor->execute(execution_point::BEFORE_EACH);

                performance_facade::start_measurement('Cycle time ' . $i);
                try {
                    $status = self::run_shortcode($shortcode);
                } catch (\Throwable $e) {
                    debugging("Shortcode execution error: " . $e->getMessage(), DEBUG_DEVELOPER);
                } finally {
                    performance_facade::end_measurement('Cycle time ' . $i);
                }
            }
        } finally {
            performance_measurer::finish();
        }
        return [
            'status' => $status,
            'received' => $shortcode,
            'hashedreceived' => hash('sha256', $shortcode),
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
     * Constructs performance class,
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
     * Constructs performance class,
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
}
