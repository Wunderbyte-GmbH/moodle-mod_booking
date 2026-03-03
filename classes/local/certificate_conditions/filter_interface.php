<?php
namespace mod_booking\local\certificate_conditions;

use MoodleQuickForm;
use stdClass;

interface filter_interface {
    /**
     * Add filter-specific fields to the dynamic form.
     *
     * @param MoodleQuickForm $mform
     * @param array|null $ajaxformdata
     * @return void
     */
    public function add_filter_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null);

    /**
     * Return display name of the filter.
     *
     * @param bool $localized
     * @return string
     */
    public function get_name_of_filter(bool $localized = true): string;

    /**
     * Persist submitted filter configuration into JSON form data.
     *
     * @param stdClass $data
     * @return void
     */
    public function save_filter(stdClass &$data): void;

    /**
     * Set default form values from existing condition record.
     *
     * @param stdClass $data
     * @param stdClass $record
     * @return mixed
     */
    public function set_defaults(stdClass &$data, stdClass $record);

    /**
     * Set internal filter data from a condition record.
     *
     * @param stdClass $record
     * @return void
     */
    public function set_filterdata(stdClass $record): void;

    /**
     * Set internal filter data from JSON payload.
     *
     * @param string $json
     * @return void
     */
    public function set_filterdata_from_json(string $json): void;

    /**
     * Apply filter logic to SQL builder context.
     *
     * @param stdClass $sql
     * @param array $params
     * @return void
     */
    public function execute(stdClass &$sql, array &$params): void;

    /**
     * Evaluate condition filter in given context (usually event data).
     * Returns true when filter accepts the context.
     *
     * @param stdClass $context
     * @return bool
     */
    public function evaluate(stdClass $context): bool;
}
