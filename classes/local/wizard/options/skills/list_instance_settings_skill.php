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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_booking\local\wizard\options\skills;

use context_module;
use mod_booking\local\wizard\engine\module_targeted_skill;
use mod_booking\local\wizard\engine\skill_risk_class;
use mod_booking\local\wizard\engine\skill_trigger_provider_interface;

/**
 * Task definition for mod_booking.list_instance_settings — READ-ONLY (R0).
 *
 * Returns the catalog of configurable booking-instance fields (labels, types, descriptions)
 * together with their current values. This is the read half of the former
 * mod_booking.configure_booking_instance action=list_fields: a pure read must never travel
 * through the confirmation queue, so it lives in its own R0 skill while
 * {@see configure_booking_instance_skill} stays write-only (action=update).
 *
 * Both skills share the identical field catalog via
 * configure_booking_instance_skill::get_configurable_fields().
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_instance_settings_skill extends booking_skill_base implements skill_trigger_provider_interface {
    // Generic activity-instance targeting: the engine resolves the operating booking instance
    // (ambient course first, then site-wide) from cmid/activityquery and asks when ambiguous,
    // so the lookup also works from non-module entry points (dashboard, MCP system context).
    use module_targeted_skill;

    /** Task name constant. */
    public const TASK_NAME = 'mod_booking.list_instance_settings';

    /**
     * The module type whose instances this skill targets.
     *
     * @return string
     */
    public function get_target_modname(): string {
        return 'booking';
    }

    /**
     * Constructor — read-only, R0 (runs without the confirmation gate).
     *
     * The native capability mirrors the write skill: instance settings (notification
     * addresses, policies, …) are management data, so reading them stays bound to
     * mod/booking:updatebooking at the target activity.
     */
    public function __construct() {
        parent::__construct(true, skill_risk_class::R0, ['mod/booking:updatebooking']);
    }

    /**
     * Return task name.
     *
     * @return string
     */
    public function get_name(): string {
        return self::TASK_NAME;
    }

    /**
     * Return task schema.
     *
     * @return array
     */
    public function get_schema(): array {
        return [
            'version' => 1,
            'description' => 'List the configurable settings of a booking activity instance: the full'
                . ' field catalog (name, label, type, description) with the current values.'
                . ' Read-only — use this for questions like "what can I configure" or "show the'
                . ' current settings". To CHANGE a setting, use mod_booking.configure_booking_instance'
                . ' (action=update) afterwards.',
            'readonly' => $this->is_read_only(),
            'example_utterances' => [
                'What can I configure for this booking instance?',
                'Show the current activity-level settings',
                'Which settings does this booking activity have?',
                'List the instance settings of the booking activity',
            ],
            'properties' => [
                'activityquery' => [
                    'type' => 'string',
                    'description' => 'Optional: the name of the target booking activity, when it is not the '
                        . 'current one (e.g. over MCP, which runs at the system context). If omitted and the '
                        . 'site has a single booking activity in scope it is used automatically.',
                    'required' => false,
                ],
                'outputlang' => [
                    'type' => 'string',
                    'description' => 'Optional language code for response strings, e.g. de or en.',
                    'required' => false,
                ],
            ],
        ];
    }

    /**
     * Return task-specific message triggers.
     *
     * @return array
     */
    public function get_message_triggers(): array {
        return [
            [
                'id' => 'mod_booking.list_instance_settings_request',
                'description' => 'User asks which settings a booking activity instance has, what can be '
                    . 'configured on it, or wants to see the current instance-level configuration values.',
                'examples' => [
                    'What can I configure for this booking instance?',
                    'Show the current activity-level settings',
                    'Which settings does this booking activity have?',
                    'What are the current settings of the booking activity?',
                ],
            ],
        ];
    }

    /**
     * Check task input structure (no DB access).
     *
     * @param array $input
     * @return array
     */
    public function check_structure(array $input): array {
        return [
            'valid' => true,
            'errors' => [],
            'ambiguities' => [],
        ];
    }

    /**
     * Explicit preflight for the readonly task — scope + capability guards, input unchanged.
     *
     * @param array $input
     * @param int   $cmid
     * @param int   $userid
     * @return array
     */
    protected function run_preflight(array $input, int $cmid, int $userid): array {
        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        if ($guard = $this->require_booking_instance_scope($cmid)) {
            return $guard;
        }
        if ($capdenied = $this->require_native_capability('mod/booking:updatebooking', $cmid, $userid)) {
            return $capdenied;
        }
        return $this->pass($input);
    }

    /**
     * Execute task: return the field catalog with current values.
     *
     * R0 skills run without the preflight gate, so the scope and capability guards are
     * enforced here as well (graceful results, never a crash).
     *
     * @param array $input
     * @param int   $cmid
     * @param int   $userid
     * @return array
     */
    public function execute(array $input, int $cmid, int $userid): array {
        global $DB;

        $cmid = $this->resolve_cmid_from_context_or_cmid($cmid);
        if ($scoperesult = $this->build_no_instance_scope_result($cmid)) {
            return $scoperesult;
        }

        $context = context_module::instance($cmid);
        if (!has_capability('mod/booking:updatebooking', $context, $userid)) {
            $message = get_string('nopermissions', 'error', 'mod/booking:updatebooking');
            return [
                'status' => 'error',
                'detail' => $message,
                'usermessage' => $message,
                'resultid' => null,
                'issue_codes' => ['NO_NATIVE_CAPABILITY'],
            ];
        }

        $cm = get_coursemodule_from_id('booking', $cmid);
        if (!$cm) {
            $message = "Could not resolve booking instance for cmid=$cmid.";
            return [
                'status' => 'error',
                'detail' => $message,
                'usermessage' => $message,
                'resultid' => null,
            ];
        }
        $bookingid = (int)$cm->instance;

        $record = $DB->get_record('booking', ['id' => $bookingid]);
        $fields = [];
        foreach (configure_booking_instance_skill::get_configurable_fields() as $key => $meta) {
            $current = $record ? ($record->$key ?? null) : null;
            $fields[] = [
                'field' => $key,
                'label' => $meta['label'],
                'type' => $meta['type'],
                'description' => $meta['description'],
                'current_value' => $current,
            ];
        }

        $editlink = (new \moodle_url('/course/modedit.php', ['update' => $cmid]))->out(false);

        $summary = count($fields) . ' configurable field(s) for booking instance id=' . $bookingid . ".\n";
        foreach ($fields as $f) {
            $current = ($f['current_value'] !== null && $f['current_value'] !== '')
                ? (string)$f['current_value']
                : '(empty)';
            $summary .= "- {$f['field']} ({$f['label']}, {$f['type']}): {$current}\n";
        }
        $summary .= "Edit link: $editlink";

        return [
            'status' => 'executed',
            'detail' => $summary,
            'usermessage' => $summary,
            'observation_full' => $summary,
            'fields' => $fields,
            'link' => $editlink,
            'resultid' => null,
            'debugmessage' => $this->build_task_debug_message(self::TASK_NAME, [], [
                'bookingid: ' . $bookingid,
                'fields returned: ' . count($fields),
            ]),
        ];
    }
}
