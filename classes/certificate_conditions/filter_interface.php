<?php
namespace mod_booking\certificate_conditions;

use MoodleQuickForm;
use stdClass;

interface filter_interface {
    public function add_filter_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null);
    public function get_name_of_filter(bool $localized = true): string;
    public function save_filter(stdClass &$data): void;
    public function set_defaults(stdClass &$data, stdClass $record);
    public function set_filterdata(stdClass $record): void;
    public function set_filterdata_from_json(string $json): void;
    public function execute(stdClass &$sql, array &$params): void;
    /**
     * Evaluate condition filter in given context (usually event data).
     * Returns true when filter accepts the context.
     * @param stdClass $context
     * @return bool
     */
    public function evaluate(stdClass $context): bool;
}
