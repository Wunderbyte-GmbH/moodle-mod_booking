# CSV Importer #

## Short description ##
Default CSV Importer, ideally combined with a dynamic form.
The importer is using a specific importer instance (in the demo \mod_booking\classes\bookingoptionsimporter.php) where columns, settings, callbackfunction can be defined.
This specific importer is first defining the settings (see below) and passing the settings object to a fileparser:
        $parser = new fileparser($settings);
The fileparser will then process the csv data as defined in the callback:
        $parser->process_csv_data($content);
and return an array with status, errors, warning that can be treated by the javascript i.e. to display user feedback.

## Detailed description ##

The importer can be rendered like:

    $inputform = new \mod_booking\form\csvimport(null, null, 'post', '', [], true, bookingoptionsimporter::return_ajaxformdata());

    $inputform->set_data_for_dynamic_submission();

    return html_writer::div($inputform->render(), '', ['id' => 'mbo_csv_import_form']);

Make sure to use a corresponding JS File, listening to the DynamicForm submitted event.
    (Demo in \mod_booking\amd\src\csvimport.js)

## Define columns:

Columns can be defined as an associative or sequential array:

        $columnsassociative = array(
            'userid' => array(
                'columnname' => get_string('id'),
                'mandatory' => true, // If mandatory and unique are set in first column, columnname will be used as key in records array.
                'unique' => true,
                'format' => 'string',
                'importinstruction' => get_string('canbesetto0iflabelgiven', 'mod_booking'), // Can be displayed in template along with other informations about the columns to be imported.
                                'transform' => fn($x) => get_string($x, 'mod_booking'), // Function can be transmitted.
                'default' => false,
            ),
            'starttime' => array(
                'mandatory' => true,
                'format' => PARAM_INT,// If format is set to PARAM_INT parser will cast given string to integer.
                'type' => 'date',
            ),
            'price' => array(
                'mandatory' => false,
                'format' => PARAM_FLOAT, // PARAM_FLOAT is casted to float, if separated via comma will be replaced with dot.
            ),
        );
    OR
        $columnssequential = [
            array(
                'name' => 'componentid',
                'columnname' => get_string('id'),
                'mandatory' => true,
                'format' => PARAM_INT,
                'importinstruction' => get_string('canbesetto0iflabelgiven', 'mod_booking'), // Can be displayed in template along with other informations about the columns to be imported.
            ),
            array(
                'name' => 'componentname',
                'mandatory' => true,
                'format' => 'string',
            ),
            array (
                'name' => 'timemodified',
                'mandatory' => false,
                'type' => 'date', // Will throw warning if empty or 0.
            ),
        ]
Make sure, all columns needed for your callback (i.e. identifier for DB queries) are set mandatory.

## UX / User Feedback

To give the users feedback, the importinstructions can be displayed along with other information of the columns. See template mod_booking/templates/importer/importerdatainfodisplay.mustache that can be rendered underneath the importer form.

In the current demo, there are several detailed error messages displayed as notifications after the import.

## Define Settings

To define the Settings instanciate the csvsettings class and use the setter functions.
        $settings = new csvsettings($definedcolumns);
        $settings->set_callback($callbackfunction);
        $settings->set_delimiter($delimiter);
        $settings->set_encoding($encoding);
        $settings->set_dateformat($dateformat);
