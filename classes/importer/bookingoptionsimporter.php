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

namespace mod_booking\importer;

use mod_booking\import\csvsettings;
use mod_booking\import\fileparser;
use stdClass;


/**
 * Renderable class for the catscalemanagers
 *
 * @package    mod_booking
 * @copyright  2023 Wunderbyte GmbH
 * @author     Georg Maißer, Magdalena Holczik
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bookingoptionsimporter {

    /**
     * Define settings and call fileparser.
     *
     * @param stdClass $data ajaxdata from import form
     * @param string $content
     * @return array
     *
     */
    public static function execute_bookingoptions_csv_import(stdClass $data, string $content) {

        $definedcolumns = self::define_bookingoption_columns();
        $callback = self::get_callbackfunction();

        $settings = self::define_settings(
            $definedcolumns,
            $callback,
            true,
            $data->delimiter_name,
            $data->encoding,
            $data->dateparseformat,
        );

        // Every entry should have this value here.
        $settings->set_columnswithvalues(['cmid' => $data->cmid]);

        $parser = new fileparser($settings);

        return $parser->process_csv_data($content);
    }

    /**
     * Return ajax formdata.
     *
     * @return array formdata for filepicker
     *
     */
    public static function return_ajaxformdata(): array {
        $ajaxformdata = [
            'id' => 'mbo_csv_import_form',
            'settingscallback' => 'mod_booking\importer\bookingoptionsimporter::execute_bookingoptions_csv_import',
        ];
        return $ajaxformdata;
    }

    /**
     * Get callback function.
     *
     * @return mixed callbackfunction
     *
     */
    private static function get_callbackfunction() {
        return "mod_booking\booking_option::update";
    }

    /**
     * Configure and return settings object.
     *
     * @param array $definedcolumns
     * @param string|null $callbackfunction
     * @param bool $acceptunknowncolumns
     * @param string|null $delimiter
     * @param string|null $encoding
     * @param string|null $dateformat
     *
     * @return mixed
     *
     * @return csvsettings
     *
     */
    private static function define_settings(
        array $definedcolumns,
        string $callbackfunction = null,
        bool $acceptunknowncolumns = false,
        string $delimiter = null,
        string $encoding = null,
        string $dateformat = null
        ) {

        $settings = new csvsettings($definedcolumns);

        if (!empty($callbackfunction)) {
            $settings->set_callback($callbackfunction);
        }

        if (!empty($acceptunknowncolumns)) {
            $settings->set_acceptunknowncolumns($acceptunknowncolumns);
        }

        if (!empty($delimiter)) {
            $settings->set_delimiter($delimiter);
        }
        if (!empty($encoding)) {
            $settings->set_encoding($encoding);
        }
        if (!empty($dateformat)) {
            $settings->set_dateformat($dateformat);
        }

        return $settings;
    }

    /**
     * Define settings for csv import form.
     *
     * @return array
     *
     */
    private static function define_bookingoption_columns() {

        $columnssequential = [
            [
                'name' => 'identifier',
                'mandatory' => false,
                'format' => PARAM_TEXT,
                'importinstruction' => get_string('import_identifier', 'mod_booking'),
            ],
            [
                'name' => 'titleprefix',
                'mandatory' => false,
                'format' => PARAM_TEXT,
                'importinstruction' => get_string('import_tileprefix', 'mod_booking'),
            ],
            [
                'name' => 'title',
                'mandatory' => false,
                'format' => PARAM_TEXT,
                'importinstruction' => get_string('import_title', 'mod_booking'),
            ],
            [
                'name' => 'text',
                'mandatory' => false,
                'format' => PARAM_TEXT,
                'importinstruction' => get_string('import_text', 'mod_booking'),
            ],
            [
                'name' => 'location',
                'mandatory' => false,
                'format' => PARAM_TEXT,
                'importinstruction' => get_string('import_location', 'mod_booking'),
            ],
             [
                'name' => 'institution',
                'mandatory' => false,
                'format' => PARAM_TEXT,
                'importinstruction' => get_string('import_location', 'mod_booking'),
            ],
             [
                'name' => 'address',
                'mandatory' => false,
                'format' => PARAM_TEXT,
                'defaultvalue' => '',
                'importinstruction' => get_string('import_location', 'mod_booking'),
            ],
            [
                'name' => 'maxanswers',
                'mandatory' => false,
                'type' => PARAM_INT,
                'importinstruction' => get_string('import_maxanswers', 'mod_booking'),
            ],
            [
                'name' => 'maxoverbooking',
                'mandatory' => false,
                'type' => PARAM_INT,
                'importinstruction' => get_string('import_maxoverbooking', 'mod_booking'),
            ],
            [
                'name' => 'coursenumber',
                'mandatory' => false,
                'type' => PARAM_INT,
                'importinstruction' => get_string('import_coursenumber', 'mod_booking'),
            ],
            [
                'name' => 'courseshortname',
                'mandatory' => false,
                'type' => PARAM_ALPHANUM,
                'importinstruction' => get_string('import_courseshortname', 'mod_booking'),
            ],
            [
                'name' => 'addtocalendar',
                'mandatory' => false,
                'type' => PARAM_INT,
                'importinstruction' => get_string('import_addtocalendar', 'mod_booking'),
            ],
            [
                'name' => 'dayofweek',
                'mandatory' => false,
                'type' => PARAM_TEXT,
                'importinstruction' => get_string('import_dayofweek', 'mod_booking'),
            ],
            [
                'name' => 'dayofweektime',
                'mandatory' => false,
                'type' => PARAM_TEXT,
                'importinstruction' => get_string('import_dayofweektime', 'mod_booking'),
            ],
            [
                'name' => 'dayofweekstarttime',
                'mandatory' => false,
                'type' => PARAM_TEXT,
                'importinstruction' => get_string('import_dayofweekstarttime', 'mod_booking'),
            ],
            [
                'name' => 'dayofweekendtime',
                'mandatory' => false,
                'type' => PARAM_TEXT,
                'importinstruction' => get_string('import_dayofweekendtime', 'mod_booking'),
            ],
            [
                'name' => 'description',
                'mandatory' => false,
                'type' => PARAM_TEXT,
                'importinstruction' => get_string('import_description', 'mod_booking'),
            ],
            [
                'name' => 'default', // Price of default category.
                'mandatory' => false,
                'type' => PARAM_FLOAT,
                'importinstruction' => get_string('import_default', 'mod_booking'),
            ],
            [
                'name' => 'teacheremail', // Price of default category.
                'mandatory' => false,
                'type' => PARAM_EMAIL,
                'importinstruction' => get_string('import_teacheremail', 'mod_booking'),
            ],
            [
                'name' => 'useremail', // Price of default category.
                'mandatory' => false,
                'type' => PARAM_EMAIL,
                'importinstruction' => get_string('import_useremail', 'mod_booking'),
            ],
        ];
        return $columnssequential;
    }

    /**
     * Export columns for template.
     *
     * @return mixed
     *
     */
    public static function export_columns_for_template() {
        return self::define_bookingoption_columns();
    }
}
