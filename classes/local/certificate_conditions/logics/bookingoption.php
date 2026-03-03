<?php
namespace mod_booking\local\certificate_conditions\logics;

use mod_booking\local\certificate_conditions\logic_interface;
use mod_booking\singleton_service;
use MoodleQuickForm;
use stdClass;

class bookingoption implements logic_interface {
    public $optionid = 0;
    public $optionids = [];
    public $requiredcount = 1;

    /**
     * Add logic fields to the dynamic form.
     *
     * @param MoodleQuickForm $mform
     * @param array|null $ajaxformdata
     * @return void
     */
    public function add_logic_to_mform(MoodleQuickForm &$mform, ?array &$ajaxformdata = null) {
        $cmid = 0;
        if (!empty($ajaxformdata['cmid'])) {
            $cmid = (int)$ajaxformdata['cmid'];
        } else if (!empty($ajaxformdata['contextid'])) {
            global $DB;
            $contextrecord = $DB->get_record('context', ['id' => (int)$ajaxformdata['contextid']], 'id,contextlevel,instanceid');
            if (!empty($contextrecord) && (int)$contextrecord->contextlevel === CONTEXT_MODULE) {
                $cmid = (int)$contextrecord->instanceid;
            }
        }

        $bookingoptions = [
            'tags' => false,
            'multiple' => true,
            'noselectionstring' => get_string('choose...', 'mod_booking'),
            'ajax' => 'mod_booking/form_booking_options_selector',
            'cmid' => $cmid,
            'valuehtmlcallback' => function ($value) {
                global $OUTPUT;

                if (empty($value)) {
                    return get_string('choose...', 'mod_booking');
                }

                $optionsettings = singleton_service::get_instance_of_booking_option_settings((int)$value);
                $instancesettings = singleton_service::get_instance_of_booking_settings_by_cmid($optionsettings->cmid);

                $details = (object)[
                    'id' => $optionsettings->id,
                    'titleprefix' => $optionsettings->titleprefix,
                    'text' => $optionsettings->text,
                    'instancename' => $instancesettings->name,
                ];

                return $OUTPUT->render_from_template(
                    'mod_booking/form_booking_options_selector_suggestion',
                    $details
                );
            },
        ];

        $mform->addElement(
            'autocomplete',
            'logic_bookingoption_optionid',
            get_string('logic_bookingoption_optionid', 'mod_booking'),
            [],
            $bookingoptions
        );
        if (!empty($cmid)) {
            $element = $mform->getElement('logic_bookingoption_optionid');
            if ($element) {
                $element->updateAttributes([
                    'data-cmid' => $cmid,
                ]);
            }
        }
        $mform->setType('logic_bookingoption_optionid', PARAM_INT);

        $mform->addElement(
            'text',
            'logic_bookingoption_requiredcount',
            get_string('logic_bookingoption_requiredcount', 'mod_booking')
        );
        $mform->setType('logic_bookingoption_requiredcount', PARAM_INT);
        $mform->setDefault('logic_bookingoption_requiredcount', 1);
    }

    /**
     * Return logic label.
     *
     * @param bool $localized
     * @return string
     */
    public function get_name_of_logic(bool $localized = true): string {
        return $localized ? get_string('logic_bookingoption', 'mod_booking') : 'logic_bookingoption';
    }

    /**
     * Persist logic configuration into JSON payload.
     *
     * @param stdClass $data
     * @return void
     */
    public function save_logic(stdClass &$data): void {
        $optionids = $data->logic_bookingoption_optionid ?? [];
        if (!is_array($optionids)) {
            $optionids = [$optionids];
        }

        $optionids = array_values(array_filter(array_map('intval', $optionids)));
        $requiredcount = (int)($data->logic_bookingoption_requiredcount ?? 1);
        $requiredcount = max(1, $requiredcount);

        $obj = new stdClass();
        $obj->logicname = 'bookingoption';
        $obj->optionids = $optionids;
        $obj->optionid = (int)($optionids[0] ?? 0);
        $obj->requiredcount = $requiredcount;
        $data->logicjson = json_encode($obj);
    }

    /**
     * Set default form values from existing record.
     *
     * @param stdClass $data
     * @param stdClass $record
     * @return void
     */
    public function set_defaults(stdClass &$data, stdClass $record) {
        if (!empty($record->logicjson)) {
            $obj = json_decode($record->logicjson);
            if (!empty($obj->optionids) && is_array($obj->optionids)) {
                $data->logic_bookingoption_optionid = array_map('intval', $obj->optionids);
            } else if (!empty($obj->optionid)) {
                $data->logic_bookingoption_optionid = [(int)$obj->optionid];
            } else {
                $data->logic_bookingoption_optionid = [];
            }

            $data->logic_bookingoption_requiredcount = (int)($obj->requiredcount ?? 1);
        }
    }

    /**
     * Set internal logic data from condition record.
     *
     * @param stdClass $record
     * @return void
     */
    public function set_logicdata(stdClass $record): void {
    }

    /**
     * Set internal logic data from JSON payload.
     *
     * @param string $json
     * @return void
     */
    public function set_logicdata_from_json(string $json): void {
        $obj = json_decode($json);
        if ($obj) {
            if (!empty($obj->optionids) && is_array($obj->optionids)) {
                $this->optionids = array_values(array_filter(array_map('intval', $obj->optionids)));
                $this->optionid = (int)($this->optionids[0] ?? 0);
            } else {
                $this->optionid = (int)($obj->optionid ?? 0);
                $this->optionids = $this->optionid ? [$this->optionid] : [];
            }

            $this->requiredcount = max(1, (int)($obj->requiredcount ?? 1));
        }
    }

    /**
     * Apply logic constraints to SQL builder context.
     *
     * @param stdClass $sql
     * @param array $params
     * @return void
     */
    public function execute(stdClass &$sql, array &$params): void {
    }

    /**
     * Returns true if the event object corresponds to the configured option id.
     * @param stdClass $context
     * @return bool
     */
    public function evaluate(stdClass $context): bool {
        $event = $context->event ?? null;
        if (!$event) {
            return false;
        }
        if ($event instanceof \mod_booking\event\bookingoption_completed) {
            $candidateoptionids = $this->optionids;
            if (empty($candidateoptionids) && !empty($this->optionid)) {
                $candidateoptionids = [$this->optionid];
            }

            if (empty($candidateoptionids)) {
                return false;
            }

            $userid = (int)($context->userid ?? $event->relateduserid ?? $event->userid ?? 0);
            if (empty($userid)) {
                return false;
            }

            $requiredcount = min(max(1, (int)$this->requiredcount), count($candidateoptionids));
            $completedcount = 0;

            foreach ($candidateoptionids as $candidateoptionid) {
                $optionsettings = singleton_service::get_instance_of_booking_option_settings((int)$candidateoptionid);
                if (empty($optionsettings->id)) {
                    continue;
                }

                $bookinganswers = singleton_service::get_instance_of_booking_answers($optionsettings);
                if ((int)$bookinganswers->is_activity_completed($userid) === 1) {
                    $completedcount++;
                    if ($completedcount >= $requiredcount) {
                        return true;
                    }
                }
            }

            return false;
        }
        // for other events we don't match.
        return false;
    }
}
