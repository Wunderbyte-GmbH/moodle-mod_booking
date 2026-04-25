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
 * External service: render AI command preview as booking option row.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace mod_booking\external;

use context_module;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use mod_booking\local\wbagent\authorization_service;
use mod_booking\local\wbagent\preview_policy;
use mod_booking\booking;
use mod_booking\output\view;
use mod_booking\singleton_service;
use mod_booking\table\bookingoptions_wbtable;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * Render a preview row for confirmed AI commands using wunderbyte_table path.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_render_command_preview extends external_api {
    /**
     * Describe parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course-module id.'),
            'commands' => new external_value(PARAM_RAW, 'JSON encoded commands array.', VALUE_DEFAULT, ''),
            'optionid' => new external_value(
                PARAM_INT,
                'Optional booking option id to render directly.',
                VALUE_DEFAULT,
                0
            ),
            'optionids' => new external_value(
                PARAM_RAW,
                'Optional JSON array of option ids to render.',
                VALUE_DEFAULT,
                '[]'
            ),
            'query' => new external_value(
                PARAM_TEXT,
                'Optional fulltext query for previewing multiple options.',
                VALUE_DEFAULT,
                ''
            ),
            'limit' => new external_value(
                PARAM_INT,
                'Maximum number of options to render when using query/all mode.',
                VALUE_DEFAULT,
                10
            ),
        ]);
    }

    /**
     * Render preview HTML.
     *
     * @param int $cmid
     * @param string $commands
     * @param int $optionid
     * @param string $optionids
     * @param string $query
     * @param int $limit
     * @return array
     */
    public static function execute(
        int $cmid,
        string $commands = '',
        int $optionid = 0,
        string $optionids = '[]',
        string $query = '',
        int $limit = 10
    ): array {
        global $DB, $USER, $OUTPUT, $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'commands' => $commands,
            'optionid' => $optionid,
            'optionids' => $optionids,
            'query' => $query,
            'limit' => $limit,
        ]);

        $authz = new authorization_service();
        $authz->require_valid_context($params['cmid']);
        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        $authz->require_use_capability((int)$USER->id, $params['cmid']);

        // Ensure page requirements manager is initialised and collect JS emitted while rendering preview HTML.
        $OUTPUT->header();
        $PAGE->start_collecting_javascript_requirements();

        // Command preview is a debugging aid and should only be visible when Moodle debug is enabled.
        if (!self::is_preview_enabled_in_debug_mode()) {
            return [
                'success' => true,
                'html' => '',
                'message' => '',
                'javascript' => (string)$PAGE->requires->get_end_code(),
            ];
        }

        // Preview policy: if commands are provided but none are on the previewable-task allowlist,
        // return a silent no-op (empty HTML, no error).  This prevents spurious output for
        // tasks like entities.create_entity that produce no booking-option row.
        if (trim((string)$params['commands']) !== '') {
            $decodedcmds = json_decode((string)$params['commands'], true);
            if (is_array($decodedcmds) && !empty($decodedcmds)) {
                if (empty(preview_policy::filter_previewable_commands($decodedcmds))) {
                    return [
                        'success' => true,
                        'html' => '',
                        'message' => '',
                        'javascript' => (string)$PAGE->requires->get_end_code(),
                    ];
                }
            }
        }

        $cm = get_coursemodule_from_id('booking', $params['cmid'], 0, false, MUST_EXIST);
        $resolvedoptionid = (int)$params['optionid'];

        if ($resolvedoptionid > 0) {
            $view = new view($params['cmid'], 'showonlyone', $resolvedoptionid);
            $html = (string)$view->get_rendered_showonlyone_table($resolvedoptionid);
            if (trim($html) === '') {
                return [
                    'success' => false,
                    'html' => '',
                    'message' => get_string('ai_preview_no_matching_option', 'mod_booking'),
                    'javascript' => (string)$PAGE->requires->get_end_code(),
                ];
            }

            return [
                'success' => true,
                'html' => $html,
                'message' => '',
                'javascript' => (string)$PAGE->requires->get_end_code(),
            ];
        }

        $decodedids = json_decode((string)$params['optionids'], true);
        $requestedids = [];
        if (is_array($decodedids) && !empty($decodedids)) {
            foreach ($decodedids as $id) {
                $normalizedid = (int)$id;
                if ($normalizedid > 0) {
                    $requestedids[] = $normalizedid;
                }
            }
        }

        $query = trim((string)$params['query']);
        $limit = max(1, min(50, (int)$params['limit']));

        if (!empty($requestedids) || $query !== '' || trim((string)$params['commands']) === '') {
            $html = self::render_preview_table((int)$params['cmid'], $requestedids, $query, $limit);
            if (trim($html) === '') {
                return [
                    'success' => false,
                    'html' => '',
                    'message' => get_string('ai_preview_no_matching_option', 'mod_booking'),
                    'javascript' => (string)$PAGE->requires->get_end_code(),
                ];
            }

            return [
                'success' => true,
                'html' => $html,
                'message' => '',
                'javascript' => (string)$PAGE->requires->get_end_code(),
            ];
        }

        $resolvedoptionids = [];

        if (empty($resolvedoptionids)) {
            $decoded = json_decode((string)$params['commands'], true);
            if (!is_array($decoded) || empty($decoded)) {
                return [
                    'success' => false,
                    'html' => '',
                    'message' => get_string('ai_preview_no_commands', 'mod_booking'),
                    'javascript' => (string)$PAGE->requires->get_end_code(),
                ];
            }

            $first = reset($decoded);
            if (!is_array($first)) {
                return [
                    'success' => false,
                    'html' => '',
                    'message' => get_string('ai_preview_no_commands', 'mod_booking'),
                    'javascript' => (string)$PAGE->requires->get_end_code(),
                ];
            }

            $task = (string)($first['task'] ?? '');
            $input = $first['input'] ?? [];
            if (!is_array($input)) {
                $input = [];
            }

            if ($task !== 'booking.create_option' && $task !== 'booking.update_option') {
                return [
                    'success' => true,
                    'html' => '',
                    'message' => '',
                    'javascript' => (string)$PAGE->requires->get_end_code(),
                ];
            }

            if ($task === 'booking.update_option') {
                $resolvedoptionid = (int)($input['optionid'] ?? 0);
                if ($resolvedoptionid <= 0 && !empty($input['optionquery'])) {
                    $query = trim((string)$input['optionquery']);
                    if ($query !== '') {
                        $records = $DB->get_records_select(
                            'booking_options',
                            'bookingid = :bookingid AND ' . $DB->sql_like('text', ':text', false),
                            [
                                'bookingid' => (int)$cm->instance,
                                'text' => $query,
                            ],
                            'id ASC',
                            'id, text',
                            0,
                            2
                        );
                        if (count($records) === 1) {
                            $record = reset($records);
                            $resolvedoptionid = (int)$record->id;
                        }
                    }
                }
            } else if ($task === 'booking.create_option') {
                $title = trim((string)($input['text'] ?? ''));
                if ($title !== '') {
                    $records = $DB->get_records_select(
                        'booking_options',
                        'bookingid = :bookingid AND LOWER(text) = LOWER(:text)',
                        [
                            'bookingid' => (int)$cm->instance,
                            'text' => $title,
                        ],
                        'id ASC',
                        'id, text',
                        0,
                        2
                    );
                    if (count($records) === 1) {
                        $record = reset($records);
                        $resolvedoptionid = (int)$record->id;
                    } else if (count($records) > 1) {
                        return [
                            'success' => false,
                            'html' => '',
                            'message' => get_string('agent_booking_create_option_exists_multiple', 'mod_booking'),
                            'javascript' => (string)$PAGE->requires->get_end_code(),
                        ];
                    }
                }
            }

            if ($resolvedoptionid > 0) {
                $resolvedoptionids = [$resolvedoptionid];
            }
        }

        $resolvedoptionids = array_values(array_unique(array_filter($resolvedoptionids, static fn(int $id): bool => $id > 0)));

        if (empty($resolvedoptionids)) {
            return [
                'success' => false,
                'html' => '',
                'message' => get_string('ai_preview_no_matching_option', 'mod_booking'),
                'javascript' => (string)$PAGE->requires->get_end_code(),
            ];
        }

        $existingids = [];
        foreach ($resolvedoptionids as $id) {
            $exists = $DB->record_exists('booking_options', [
                'id' => $id,
                'bookingid' => (int)$cm->instance,
            ]);
            if ($exists) {
                $existingids[] = $id;
            }
        }

        if (empty($existingids)) {
            return [
                'success' => false,
                'html' => '',
                'message' => get_string('ai_preview_no_matching_option', 'mod_booking'),
                'javascript' => (string)$PAGE->requires->get_end_code(),
            ];
        }

        $htmlparts = [];
        foreach ($existingids as $id) {
            $view = new view($params['cmid'], 'showonlyone', $id);
            $html = (string)$view->get_rendered_showonlyone_table($id);
            if (trim($html) !== '') {
                $htmlparts[] = '<div class="booking-ai-preview-item mb-3">' . $html . '</div>';
            }
        }

        $html = implode('', $htmlparts);

        if (trim($html) === '') {
            return [
                'success' => false,
                'html' => '',
                'message' => get_string('ai_preview_no_matching_option', 'mod_booking'),
                'javascript' => (string)$PAGE->requires->get_end_code(),
            ];
        }

        return [
            'success' => true,
            'html' => $html,
            'message' => '',
            'javascript' => (string)$PAGE->requires->get_end_code(),
        ];
    }

    /**
     * Render one Wunderbyte options table for list/query/multi-id preview mode.
     *
     * @param int $cmid
     * @param array $optionids
     * @param string $query
     * @param int $limit
     * @return string
     */
    private static function render_preview_table(int $cmid, array $optionids, string $query, int $limit): string {
        $booking = singleton_service::get_instance_of_booking_by_cmid($cmid);
        $bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);
        $viewparam = (int)booking::get_value_of_json_by_key((int)$booking->id, 'viewparam');
        if ($viewparam <= 0) {
            $viewparam = MOD_BOOKING_VIEW_PARAM_LIST;
        }

        $optionsfields = explode(',', (string)($bookingsettings->optionsfields ?? ''));
        if (!in_array('booknow', $optionsfields)) {
            $optionsfields[] = 'booknow';
        }

        $table = new bookingoptions_wbtable("cmid_{$cmid} aipreviewtable");
        view::apply_standard_params_for_bookingtable(
            $table,
            $optionsfields,
            false,
            true,
            false,
            false,
            true,
            $viewparam,
            $cmid
        );

        $wherearray = ['bookingid' => (int)$booking->id];
        $additionalwhere = '';
        if (!empty($optionids)) {
            $safeids = array_values(array_unique(array_filter($optionids, static fn(int $id): bool => $id > 0)));
            if (!empty($safeids)) {
                $additionalwhere = 'id IN (' . implode(', ', $safeids) . ')';
            }
        }

        [$fields, $from, $where, $params, $filter] = booking::get_options_filter_sql(
            0,
            0,
            '',
            null,
            $booking->context,
            [],
            $wherearray,
            null,
            [MOD_BOOKING_STATUSPARAM_BOOKED],
            $additionalwhere,
            '',
            $table
        );
        $table->set_filter_sql($fields, $from, $where, $filter, $params);

        if ($query !== '') {
            $table->apply_filter('', $query);
            $table->apply_searchtext($query);
        }

        return (string)$table->outhtml($limit, true);
    }

    /**
     * Whether preview rendering is enabled via Moodle debug configuration.
     *
     * @return bool
     */
    private static function is_preview_enabled_in_debug_mode(): bool {
        global $CFG;
        return !empty($CFG->debug);
    }

    /**
     * Describe return shape.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether preview could be rendered.'),
            'html' => new external_value(PARAM_RAW, 'Rendered preview HTML.'),
            'message' => new external_value(PARAM_TEXT, 'Fallback message if preview unavailable.'),
            'javascript' => new external_value(PARAM_RAW, 'Collected JavaScript fragment.', VALUE_OPTIONAL),
        ]);
    }
}
