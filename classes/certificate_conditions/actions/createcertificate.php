<?php
namespace mod_booking\certificate_conditions\actions;

use mod_booking\certificate_conditions\action_interface;
use MoodleQuickForm;
use stdClass;

class createcertificate implements action_interface {
    public $certid = 0;

    public function add_action_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        $mform->addElement('text', 'action_createcertificate_certid',
            get_string('action_createcertificate_certid', 'mod_booking'));
        $mform->setType('action_createcertificate_certid', PARAM_INT);
    }

    public function get_name_of_action(bool $localized = true): string {
        return $localized ? get_string('action_createcertificate', 'mod_booking') : 'action_createcertificate';
    }

    public function save_action(stdClass &$data): void {
        $obj = new stdClass();
        $obj->actionname = 'createcertificate';
        $obj->certid = $data->action_createcertificate_certid ?? 0;
        $data->actionjson = json_encode($obj);
    }

    public function set_defaults(stdClass &$data, stdClass $record) {
        if (!empty($record->actionjson)) {
            $obj = json_decode($record->actionjson);
            $data->action_createcertificate_certid = $obj->certid ?? 0;
        }
    }

    public function set_actiondata(stdClass $record): void {}
    public function set_actiondata_from_json(string $json): void {
        $obj = json_decode($json);
        if ($obj) {
            $this->certid = (int)($obj->certid ?? 0);
        }
    }
    public function execute(stdClass &$sql, array &$params): void {}

    /**
     * Create certificate for the user in the context.
     * Context is expected to have 'userid' and 'optionid' properties.
     * NOTE: This method relies on the certificateclass::issue_certificate signature.
     * We do NOT override the certificate id; only apply the action if a certificate
     * is already configured on the booking option.
     * @param stdClass $context
     * @return void
     */
    public function execute_action(stdClass $context): void {
        // For now, we just call issue_certificate to generate a certificate for this user
        // The actual certificate template should already be configured on the booking option
        $userid = $context->userid ?? 0;
        $optionid = $context->optionid ?? 0;
        if (!$userid || !$optionid) {
            return;
        }
        // Use the existing certificateclass method - it will check certificate config on the booking option
        \mod_booking\local\certificateclass::issue_certificate($optionid, $userid, time());
    }
}
