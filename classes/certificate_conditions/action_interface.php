<?php
namespace mod_booking\certificate_conditions;

use MoodleQuickForm;
use stdClass;

interface action_interface {
    public function add_action_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null);
    public function get_name_of_action(bool $localized = true): string;
    public function save_action(stdClass &$data): void;
    public function set_defaults(stdClass &$data, stdClass $record);
    public function set_actiondata(stdClass $record): void;
    public function set_actiondata_from_json(string $json): void;
    public function execute(stdClass &$sql, array &$params): void;
    /**
     * Perform the action given context.
     * @param stdClass $context
     * @return void
     */
    public function execute_action(stdClass $context): void;
}
