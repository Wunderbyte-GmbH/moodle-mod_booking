<?php
namespace mod_booking\local\certificate_conditions;

use MoodleQuickForm;
use stdClass;

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
     * @return void
     */
    public function execute_action(stdClass $context): void;
}
