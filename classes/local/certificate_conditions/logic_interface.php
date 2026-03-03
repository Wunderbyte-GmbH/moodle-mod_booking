<?php
namespace mod_booking\local\certificate_conditions;

use MoodleQuickForm;
use stdClass;

interface logic_interface {
    /**
     * Add logic-specific fields to the dynamic form.
     *
     * @param MoodleQuickForm $mform
     * @param array|null $ajaxformdata
     * @return void
     */
    public function add_logic_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null);

    /**
     * Return display name of the logic handler.
     *
     * @param bool $localized
     * @return string
     */
    public function get_name_of_logic(bool $localized = true): string;

    /**
     * Persist submitted logic configuration into JSON form data.
     *
     * @param stdClass $data
     * @return void
     */
    public function save_logic(stdClass &$data): void;

    /**
     * Set default form values from existing condition record.
     *
     * @param stdClass $data
     * @param stdClass $record
     * @return mixed
     */
    public function set_defaults(stdClass &$data, stdClass $record);

    /**
     * Set internal logic data from a condition record.
     *
     * @param stdClass $record
     * @return void
     */
    public function set_logicdata(stdClass $record): void;

    /**
     * Set internal logic data from JSON payload.
     *
     * @param string $json
     * @return void
     */
    public function set_logicdata_from_json(string $json): void;

    /**
     * Apply logic constraints to SQL builder context.
     *
     * @param stdClass $sql
     * @param array $params
     * @return void
     */
    public function execute(stdClass &$sql, array &$params): void;

    /**
     * Evaluate logic using given context. Return true if condition should fire.
     *
     * @param stdClass $context
     * @return bool
     */
    public function evaluate(stdClass $context): bool;
}
