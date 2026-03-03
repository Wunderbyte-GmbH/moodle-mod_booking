<?php
namespace mod_booking\certificate_conditions;

use MoodleQuickForm;
use stdClass;

interface logic_interface {
    public function add_logic_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null);
    public function get_name_of_logic(bool $localized = true): string;
    public function save_logic(stdClass &$data): void;
    public function set_defaults(stdClass &$data, stdClass $record);
    public function set_logicdata(stdClass $record): void;
    public function set_logicdata_from_json(string $json): void;
    public function execute(stdClass &$sql, array &$params): void;
    /**
     * Evaluate logic using given context. Return true if condition should fire.
     * @param stdClass $context
     * @return bool
     */
    public function evaluate(stdClass $context): bool;
}
