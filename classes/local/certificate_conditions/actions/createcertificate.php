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

namespace mod_booking\local\certificate_conditions\actions;

use mod_booking\local\certificate_conditions\action_interface;
use mod_booking\local\certificate_conditions\actions_info;
use mod_booking\local\certificateclass;
use MoodleQuickForm;
use stdClass;
use tool_certificate\certificate as toolCertificate;

/**
 * Helper to display certificate condition references on booking option form.
 *
 * @package    mod_booking
 * @copyright  Wunderbyte GmbH <info@wunderbyte.at>,
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class createcertificate implements action_interface {
    /**
     * Certificate template ID to use for issued certificates.
     *
     * @var int
     */
    public $certid = 0;
    /**
     * Expiry date type for the issued certificate.
     *
     * @var int
     */
    public $expirydatetype = 0;
    /**
     * Absolute expiry date for the issued certificate.
     *
     * @var int
     */
    public $expirydateabsolute = 0;
    /**
     * Relative expiry date for the issued certificate.
     *
     * @var int
     */
    public $expirydaterelative = 0;

    /**
     * Add action fields to the dynamic form.
     *
     * @param MoodleQuickForm $mform
     * @param array|null $ajaxformdata
     * @return void
     */
    public function add_action_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        $mform->addElement(
            'select',
            'action_createcertificate_certid',
            get_string('action_createcertificate_certid', 'mod_booking'),
            self::get_available_certificate_templates()
        );
        $mform->setType('action_createcertificate_certid', PARAM_INT);

        if (class_exists('tool_certificate\\certificate')) {
            toolCertificate::add_expirydate_to_form($mform);
        }
    }

    /**
     * Return available certificate templates for selection.
     *
     * @return array
     */
    private static function get_available_certificate_templates(): array {
        global $DB;

        $selection = [0 => get_string('choose...', 'mod_booking')];

        if (!class_exists('tool_certificate\\certificate')) {
            return $selection;
        }

        $records = $DB->get_records('tool_certificate_templates', [], 'name ASC', 'id, name');
        foreach ($records as $record) {
            $selection[(int)$record->id] = $record->name;
        }

        return $selection;
    }

    /**
     * Return action label.
     *
     * @param bool $localized
     * @return string
     */
    public function get_name_of_action(bool $localized = true): string {
        return $localized ? get_string('action_createcertificate', 'mod_booking') : 'action_createcertificate';
    }

    /**
     * Persist action configuration into JSON payload.
     *
     * @param stdClass $data
     * @return void
     */
    public function save_action(stdClass &$data): void {
        $obj = new stdClass();
        $obj->actionname = 'createcertificate';
        $obj->certid = (int)($data->action_createcertificate_certid ?? 0);
        $obj->expirydatetype = (int)($data->expirydatetype ?? 0);
        $obj->expirydateabsolute = (int)($data->expirydateabsolute ?? 0);
        $obj->expirydaterelative = (int)($data->expirydaterelative ?? 0);
        $data->actionjson = json_encode($obj);
    }

    /**
     * Set default form values from existing record.
     *
     * @param stdClass $data
     * @param stdClass $record
     * @return void
     */
    public function set_defaults(stdClass &$data, stdClass $record) {
        if (!empty($record->actionjson)) {
            $obj = json_decode($record->actionjson);
            $data->action_createcertificate_certid = $obj->certid ?? 0;
            $data->expirydatetype = (int)($obj->expirydatetype ?? 0);
            $data->expirydateabsolute = (int)($obj->expirydateabsolute ?? 0);
            $data->expirydaterelative = (int)($obj->expirydaterelative ?? 0);
        }
    }

    /**
     * Set internal action data from condition record.
     *
     * @param stdClass $record
     * @return void
     */
    public function set_actiondata(stdClass $record): void {
    }

    /**
     * Set internal action data from JSON payload.
     *
     * @param string $json
     * @return void
     */
    public function set_actiondata_from_json(string $json): void {
        $obj = json_decode($json);
        if ($obj) {
            $this->certid = (int)($obj->certid ?? 0);
            $this->expirydatetype = (int)($obj->expirydatetype ?? 0);
            $this->expirydateabsolute = (int)($obj->expirydateabsolute ?? 0);
            $this->expirydaterelative = (int)($obj->expirydaterelative ?? 0);
        }
    }

    /**
     * Apply action constraints to SQL builder context.
     *
     * @param stdClass $sql
     * @param array $params
     * @return void
     */
    public function execute(stdClass &$sql, array &$params): void {
    }

    /**
     * Create certificate for the user in the context.
     * Context is expected to have 'userid' and 'optionid' properties.
     * The certificate template id is taken from this action configuration.
     * @param stdClass $context
     * @param stdClass $condition
     * @return void
     */
    public function execute_action(stdClass $context, stdClass $condition): void {
        $userid = $context->userid ?? 0;
        $optionid = $context->optionid ?? 0;
        if (!$userid || !$optionid) {
            return;
        }

        if (empty($this->certid)) {
            return;
        }
        if (empty(get_config('booking', 'issuemultiplecertificates'))) {
            $existing = actions_info::certificate_already_issued($condition->id, (int)$this->certid, $userid);
            if ($existing) {
                return;
            }
        }
        certificateclass::issue_certificate(
            $optionid,
            $userid,
            time(),
            (int)$this->certid,
            (int)$this->expirydatetype,
            (int)$this->expirydateabsolute,
            (int)$this->expirydaterelative,
            $condition
        );
    }

    /**
     * Validate action-specific form data.
     *
     * @param array $data
     * @return array
     */
    public function validate(array $data): array {
        $errors = [];

        if (empty($data['action_createcertificate_certid'])) {
            $errors['action_createcertificate_certid'] = get_string('error:entervalue', 'mod_booking');
        }

        return $errors;
    }
}
