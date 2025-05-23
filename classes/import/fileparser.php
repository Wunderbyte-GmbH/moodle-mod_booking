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

/**
 * Class fileparser.
 *
 * @package    mod_booking
 * @copyright  2023 Wunderbyte GmbH <georg.maisser@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\import;

use csv_import_reader;
use DateTime;
use Exception;
use html_writer;
use mod_booking\event\records_imported;
use mod_booking\event\testitem_imported;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . "/csvlib.class.php");

/**
 * Fileparser for import.
 *
 * @package    mod_booking
 * @copyright  2023 Wunderbyte GmbH <georg.maisser@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fileparser {

    /**
     * @var string
     */
    protected $pluginname = "mod_booking";

    /**
     * @var string
     */
    protected $delimiter = 'comma';

    /**
     * @var string
     */
    protected $enclosure = '';

    /**
     * @var string
     */
    protected $encoding = 'UTF-8';

    /**
     * @var array of column names
     */
    protected $columns = [];

    /**
     * @var array of fieldnames imported from csv
     */
    protected $fieldnames = [];

    /**
     * @var array with errors one per line
     * Error will break the import of this record
     */
    protected $csverrors = [];

    /**
     * @var array with warnings one per line
     * Import of record
     */
    protected $csvwarnings = [];

    /**
     * @var object
     */
    protected $settings = null;

    /**
     * @var array error message
     */
    protected $errors = [];

    /**
     * @var array of fieldnames from other db tables
     */
    protected $additionalfields = [];

    /**
     * @var array of objects
     */
    protected $customfields = [];

    /**
     * @var object of strings
     */
    protected $requirements;

    /**
     * @var string columnname
     */
    public $uniquekey;

    /**
     * @var array of objects
     */
    protected $records = [];

    /**
     * @var bool acceptunknowncolumns
     */
    protected $acceptunknowncolumns = false;

    /**
     * Instantioate attributes.
     *
     * @param mixed $settings
     *
     */
    public function __construct($settings) {
        // Optional: switch on type of settings object -> process data according to type (csv, ...).
        $this->apply_settings($settings);
    }

    /**
     * Validate and apply settings
     *
     * @param mixed $settings
     *
     * @return bool|void
     *
     */
    private function apply_settings($settings) {
        global $DB;
        $this->settings = $settings;

        if (!empty($this->settings->columns)) {
            $this->columns = $this->settings->columns;
        } else {
            $this->errors[] = get_string('nolabels', 'mod_booking');
            return false;
        }

        $this->delimiter = !empty($this->settings->delimiter) ? $this->settings->delimiter : 'comma';
        $this->enclosure = !empty($this->settings->enclosure) ? $this->settings->enclosure : '"';
        $this->encoding = !empty($this->settings->encoding) ? $this->settings->encoding : 'utf-8';
        $this->acceptunknowncolumns = !empty($this->settings->acceptunknowncolumns) ? $this->settings->acceptunknowncolumns : false;
    }

    /**
     * Imports content and compares to settings.
     *
     * Returns array of records, associative if first column is defined mandatory and unique,
     * otherwise sequential. Line errors might have happend.
     *
     * @param mixed $content
     * @return array
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function process_csv_data($content) {
        $data = [];
        $iid = csv_import_reader::get_new_iid($this->pluginname);
        if (empty($iid)) {
            $this->errors[] = "Could not get new import id.";
        }
        $cir = new csv_import_reader($iid, $this->pluginname);

        // TODO: Check if delimiter, enclosure and encoding is correctly set.
        // In $content, is the delimiter set?
        $readcount = $cir->load_csv_content($content, $this->encoding, $this->delimiter, null, $this->enclosure);

        if (empty($readcount)) {
            $this->errors[] = $cir->get_error();
            return $this->exit_and_return_records($cir);
        }

        $fieldnames = $cir->get_columns();
        // Csv column headers.
        if ($fieldnames == false) {
            $this->errors[] = $cir->get_error();
            $this->errors[] = get_string('checkdelimiteroremptycontent', 'mod_booking');
            return $this->exit_and_return_records($cir);
        }
        $this->fieldnames = $fieldnames;
        if (!empty($this->validate_fieldnames())) {
            $this->errors[] = $this->validate_fieldnames();
            return $this->exit_and_return_records($cir);
        }

        // Check if first column is set mandatory and unique.
        // If unique column existis -> key.
        $firstcolumn = $this->fieldnames[0];
        if ($this->get_param_value($firstcolumn, 'mandatory') == true
        && $this->get_param_value($firstcolumn, 'unique') == true) {
            $this->uniquekey = $firstcolumn;
        }

        $cir->init();
        $this->records = [];
        while ($line = $cir->next()) {
            $csvrecord = array_combine($fieldnames, $line);

            // Add static values from settings.
            foreach ($this->settings->columnswithvalues as $key => $value) {
                $csvrecord[$key] = $value;
            }
            // We treat each line, if validation is successfull.
            if ($this->validate_data($csvrecord, $line)) {
                $data = [];
                foreach ($csvrecord as $columnname => $value) {
                    $data[$columnname] = $value;
                }

                // Execute the callback. If this doesn't work, don't treat the record.
                $callbackresponse = $this->execute_callback($data);
                if ($callbackresponse['success'] == 0) {
                    $this->errors[] = $callbackresponse['message'];
                    continue;
                } else if ($callbackresponse['success'] == 2) {
                    $this->csvwarnings[] = $callbackresponse['message'];
                    unset($callbackresponse['message']);
                }
                $this->records['callbackresponse'] = $callbackresponse;

                if (isset($this->uniquekey)) { // With unique key set, we build an associative array.
                    if (!isset($this->records[$firstcolumn])) {
                        $this->records[$firstcolumn] = [];
                    }
                    $this->records[$firstcolumn][$csvrecord[$firstcolumn]] = $data;

                } else { // Without unique key, we build a sequential array.
                    array_push($this->records, $data);
                }
            }

        }
        return $this->exit_and_return_records($cir);
    }

    /**
     * Exit and return
     *
     * @param object $cir
     *
     * @return array
     */
    private function exit_and_return_records(object $cir) {

        // Collecting errors, warnings and general successinformation for $this->records.
        $this->checksuccess();
        $cir->cleanup(true);
        $cir->close();
        return $this->records;

    }


    /**
     * Executes callback
     *
     * @param array $data
     *
     * @return array
     */
    private function execute_callback(array $data) {

        if (!$callback = $this->settings->callback) {
            throw new moodle_exception('callbackfunctionnotdefined', 'mod_booking');
        };
        try {
            $optionid = $callback($data);

            $result = ['success' => 1, 'message' => ''];
            if ($result['success'] != 1 && $result['success'] != 2) {
                return [
                    'success' => 0,
                    'message' => $result['message'],
                ];
            } else {
                return [
                    'success' => $result['success'],
                    'message' => $result['message'],
                ];
            }
        } catch (Exception $e) {

            return [
                'success' => 0,
                'message' => $e->getMessage(),
            ];
        }

    }

    /**
     * Collecting errors, warnings and general successinformation.
     *
     * @return void
     *
     */
    private function checksuccess() {
        if ($this->records !== []) {
            $this->records['numberofsuccessfullyupdatedrecords'] = count($this->records) - 1;
            // If data was parsed successfully, return 1, else return 0.
            $this->records['success'] = 1;
            $this->trigger_records_imported_event($this->records['numberofsuccessfullyupdatedrecords']);

        } else {
            $this->records['success'] = 0;
        }

        $this->records['errors'] = [];

        if ($this->errors !== []) {
            $this->records['errors']['generalerrors'] = $this->errors;
        }
        if ($this->csverrors !== []) { // Lines with error will not be imported.
            $this->records['errors']['lineerrors'] = $this->csverrors;
        }
        if ($this->csvwarnings !== []) { // Lines with warning are imported.
            $this->records['errors']['warnings'] = $this->csvwarnings;
        }
    }

    /**
     * Validate each records by comparing to settings.
     *
     * @param array $csvrecord
     * @param array $line
     */
    private function validate_data($csvrecord, $line) {
        // Validate data.

        // We want to have at least one column with data, even when nothing is mandatory.
        $foundvalues = array_filter($line, fn($a) => !empty($a));
        if (empty($foundvalues)) {
            $this->add_csverror("No data was found in this record", implode(', ', $line));
            return false;
        }

        foreach ($csvrecord as $column => $value) {

            // Value "0" counts as value and returns valueisset true.
            !$valueisset = (("" !== $value) && (null !== $value)) ? true : false;

            $linevalues = implode(', ', $line);

            // Check if empty fields are mandatory.
            if (!$valueisset) {
                if ($this->field_is_mandatory($column)) {
                    $this->add_csverror("The field $column is mandatory but contains no value.", $linevalues);
                    return false;
                }
                // If no value is set, use defaultvalue.
                if (isset($this->settings->columns->$column->defaultvalue)) {
                    $value = $this->settings->columns->$column->defaultvalue;
                }
            } else {
                // Validation of field type.
                switch ($this->get_param_value($column, "type")) {
                    case "date":
                        if (!$this->validate_datefields($value)) {
                            $format = $this->settings->dateformat;
                            $this->add_csvwarnings(
                                "$value is not a valid date format in $column. Format should be Unix timestamp or like: $format",
                                $line[0]);
                            break;
                        }
                        break;
                    default:
                        break;
                }
                // Validation of field format.
                switch ($this->get_param_value($column, "format")) {
                    case PARAM_INT:
                        $value = $this->cast_string_to_int($value);
                        if (is_string($value)) {
                            $this->add_csvwarnings("$value is not a valid integer in $column", $linevalues);
                        }
                        break;
                    case PARAM_FLOAT:
                        $value = $this->cast_string_to_float($value);
                        if (is_string($value)) {
                            $this->add_csvwarnings("$value is not a valid float in $column", $linevalues);
                        }
                        break;
                    case PARAM_ALPHANUM:
                        if (!ctype_alnum($value)) {
                            $this->add_csvwarnings("$value is not a valid alphanum in $column", $linevalues);
                        }
                        break;
                    default:
                        break;
                }
            }
        };
        return true;
    }
    /**
     * Check if the given string is a valid float.
     * If separated via comma, replace by dot and - if possible -, cast to float.
     *
     * @param string $value
     * @return * either float or the given value (string)
     */
    protected function cast_string_to_float($value) {

        // Check if separated by comma.
        $commacount = substr_count($value, ',');
        if ($commacount == 1) {
            $floatstring = str_replace(',', '.', $value);

        } else {
            $floatstring = $value;
        }
        $validation = filter_var($floatstring, FILTER_VALIDATE_FLOAT);
        if ($validation !== false) {
            return $validation;
        } else {
            return $value;
        }
    }

    /**
     * Check if the given string is a valid int and if possible, cast to int.
     *
     * @param string $value
     * @return * either int or the given value (string)
     */
    protected function cast_string_to_int($value) {

        $validation = filter_var($value, FILTER_VALIDATE_INT);

        if ($validation !== false) {
            // The string is a valid integer.
            $int = (int)$value; // Casting to integer.
            return $int;
        } else {
            return $value;
        }
    }
    /**
     * Comparing labels of content to required labels.
     * @return string empty if ok, errormsg if fieldname not correct in csv file.
     */
    protected function validate_fieldnames() {
        $error = '';
        if (count($this->fieldnames) == 1 && count($this->columns) > 1) {
            $error .= get_string('checkdelimiter', 'mod_booking');
            return $error;
        }
        foreach ($this->columns as $column) {
            if (!in_array($column->columnname, array_values($this->fieldnames))
                && $column->mandatory == true) {
                // Should all keys be there or only mandatory?
                $error .= get_string('missinglabel', 'mod_booking', $column->columnname);
                break;
            }
        }

        // This check is only performed if we don't accept unknown columns.
        if (!$this->acceptunknowncolumns) {
            foreach ($this->fieldnames as $fieldname) {
                if (!in_array($fieldname, array_keys($this->columns))) {
                    $error .= get_string('wronglabels', 'mod_booking', $fieldname);
                    break;
                }
            }
        }

        return $error;
    }

    /**
     * Check if field is mandatory have values. Adds error and returns false in case of fail.
     *
     * @param string $columnname
     * @return bool true on validation false on error
     */
    protected function field_is_mandatory($columnname) {

        return $this->settings->columns[$columnname]->mandatory ?? false;
    }

    /**
     * Check if date fields format is valid. Adds error and returns false in case of fail.
     * @param string $value
     * @return bool true on validation false on error
     */
    protected function validate_datefields($value) {
        // Check if we have a readable string in correct format.
        $readablestring = false;
        $dateformat = !empty($this->settings->dateformat) ? $this->settings->dateformat : "j.n.Y H:i:s";
        if (date_create_from_format($dateformat, $value) &&
                strtotime($value, time())) {
                    $readablestring = true;
        }
        // Check accepts all ints.
        $date = DateTime::createFromFormat('U', $value);

        if (($date && $date->format('U') == $value) || $readablestring) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Checks the value of a given param of the column.
     * @param string $columnname
     * @param string $param
     * @return string param value on success, empty string if not found.
     */
    protected function get_param_value($columnname, $param) {
        if (isset($this->settings->columns[$columnname]->$param)) {
            return $this->settings->columns[$columnname]->$param;
        } else {
            return "";
        }
    }

    /**
     * Sets the value of a given param of the column.
     *
     * @param mixed $columnname
     * @param mixed $param
     * @param mixed $value
     *
     * @return boolean true if successful false on error
     *
     */
    protected function set_param_value($columnname, $param, $value) {
        if (isset($this->settings->columns[$columnname]->$param)) {
            $this->settings->columns[$columnname]->$param = $value;
            return true;
        } else {
            return false;
        }
    }

    /**
     * Add error message to $this->csverrors
     *
     * @param mixed $errorstring
     * @param mixed $i id of record
     *
     * @return void
     *
     */
    protected function add_csverror($errorstring, $i) {
        $this->csverrors[] = nl2br($errorstring.".\nIn line with values: $i ");
    }

    /**
     * Add error message to $this->csvwarnings
     *
     * @param mixed $errorstring
     * @param mixed $i id of record
     *
     * @return void
     *
     */
    protected function add_csvwarnings($errorstring, $i) {
        $this->csvwarnings[] = nl2br($errorstring.".\nIn line with values: $i ");
    }
    /**
     * Get line errors.
     *
     * @return array line errors
     */
    public function get_line_errors() {
        return $this->csverrors;
    }

    /**
     * Get line warnings.
     *
     * @return array line warnings
     */
    public function get_line_warnings() {
        return $this->csvwarnings;
    }
    /**
     * Get errors.
     *
     * @return array errors
     */
    public function get_error() {
        return $this->errors;
    }

    /**
     * Trigger event for records to be imported.
     * @param int $numberofimporteditems
     */
    private function trigger_records_imported_event($numberofimporteditems) {
        $event = records_imported::create([
            'context' => \context_system::instance(),
            'other' => [
                'itemcount' => $numberofimporteditems,
            ],
            ]);
        $event->trigger();
    }
}
