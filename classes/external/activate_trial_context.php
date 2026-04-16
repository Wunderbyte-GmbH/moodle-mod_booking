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
 * External function: activate AI in course + course module after trial token retrieval.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use context_module;
use context_system;
use core\di;
use core_ai\manager as ai_manager;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Activate AI in course and module context.
 */
class activate_trial_context extends external_api {
    /**
     * Parameter definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course-module id of the booking instance.'),
        ]);
    }

    /**
     * Activate AI tools for the course and this specific booking module.
     *
     * @param int $cmid
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB;

        require_sesskey();

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);
        $cmid = $params['cmid'];

        require_capability('moodle/site:config', context_system::instance());

        $context = context_module::instance($cmid);
        self::validate_context($context);

        $cm = get_coursemodule_from_id('booking', $cmid, 0, false, MUST_EXIST);
        $courseid = (int)$cm->course;

        // If a Wunderbyte provider exists but is disabled, enable it as part of activation.
        if (class_exists('\\core_ai\\manager')) {
            try {
                $manager = di::get(ai_manager::class);
                $instances = $manager->get_provider_instances([
                    'name' => request_trial_key::PROVIDER_NAME,
                    'provider' => 'aiprovider_openai\\provider',
                ]);

                foreach ($instances as $instance) {
                    if (!$instance->enabled) {
                        $manager->enable_provider_instance($instance);
                    }
                }
            } catch (\Throwable $e) {
                // Continue with context activation even if provider toggling is unavailable.
                debugging('Provider activation skipped: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // 1) Enable AI tools at course level.
        $DB->set_field('course', 'enableaitools', 1, ['id' => $courseid]);

        // 2) Enable AI tools and generate_text action in this module.
        $DB->set_field('course_modules', 'enableaitools', 1, ['id' => $cmid]);
        $DB->set_field('course_modules', 'enabledaiactions', json_encode((object) [
            'generate_text' => true,
        ]), ['id' => $cmid]);

        \core_plugin_manager::reset_caches();
        rebuild_course_cache($courseid, false, true);

        return [
            'success' => true,
            'message' => get_string('aitrial_activate_success', 'mod_booking'),
        ];
    }

    /**
     * Return definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether activation was successful.'),
            'message' => new external_value(PARAM_TEXT, 'Status message.'),
        ]);
    }
}
