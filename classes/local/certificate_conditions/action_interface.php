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

namespace mod_booking\local\certificate_conditions;

use MoodleQuickForm;
use stdClass;

/**
 * Helper to display certificate condition references on booking option form.
 *
 * @package    mod_booking
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>,
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface action_interface {
    /**
     * Add action-specific fields to the dynamic form.
     *
     * @param MoodleQuickForm $mform
     * @param array|null $ajaxformdata
     * @return void
     */
    public function add_action_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null);

    /**
     * Return display name of the action.
     *
     * @param bool $localized
     * @return string
     */
    public function get_name_of_action(bool $localized = true): string;

    /**
     * Persist submitted action configuration into JSON form data.
     *
     * @param stdClass $data
     * @return void
     */
    public function save_action(stdClass &$data): void;

    /**
     * Set default form values from existing condition record.
     *
     * @param stdClass $data
     * @param stdClass $record
     * @return mixed
     */
    public function set_defaults(stdClass &$data, stdClass $record);

    /**
     * Set internal action data from a condition record.
     *
     * @param stdClass $record
     * @return void
     */
    public function set_actiondata(stdClass $record): void;

    /**
     * Set internal action data from JSON payload.
     *
     * @param string $json
     * @return void
     */
    public function set_actiondata_from_json(string $json): void;

    /**
     * Apply action logic to SQL builder context.
     *
     * @param stdClass $sql
     * @param array $params
     * @return void
     */
    public function execute(stdClass &$sql, array &$params): void;

    /**
     * Perform the action given context.
     *
     * @param stdClass $context
     * @param stdClass $condition
     * @return void
     */
    public function execute_action(stdClass $context, stdClass $condition): void;

    /**
     * Validate action-specific form data.
     *
     * @param array $data Form data
     * @return array Associative array of field_name => error_message
     */
    public function validate(array $data): array;
}
