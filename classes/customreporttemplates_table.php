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
namespace mod_booking;

use moodle_url;
use table_sql;

class customreporttemplates_table extends table_sql {

    /**
     * @var int
     */
    public $cmid = 0;

    /**
     * customreporttemplates_table constructor.
     *
     * @param $uniqueid
     * @param $cmid
     * @throws \coding_exception
     */
    public function __construct($uniqueid, $cmid) {
        parent::__construct($uniqueid);
        $this->cmid = $cmid;

        // Define the list of columns to show.
        $columns = array('name', 'file', 'action');
        $this->define_columns($columns);

        // Define the titles of columns to show in header.
        $headers = array(get_string('name'), get_string('file'), get_string('action'));
        $this->define_headers($headers);
    }

    /**
     * Display actions for the templates (delete or edit)
     *
     * @param $values
     * @return string
     * @throws \coding_exception
     * @throws \moodle_exception
     */
    public function col_action($values) {
        global $OUTPUT;
        $output = '';
        $delete = get_string('delete');
        $url = new moodle_url('/mod/booking/customreporttemplates.php',
            array('templateid' => $values->id, 'action' => 'delete', 'id' => $this->cmid));
        $output .= $OUTPUT->single_button($url, $delete, 'get');
        return $output;
    }

    public function col_file($values) {
        $fs = get_file_storage();
        list($course, $cm) = get_course_and_cm_from_cmid($this->cmid);
        $coursecontext = \context_course::instance($course->id);

        $files = $fs->get_area_files($coursecontext->id, 'mod_booking', 'templatefile', $values->id);

        $file = array_pop($files);
        if (!is_null($file)) {
            $url = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(),
                $file->get_itemid(), $file->get_filepath(), $file->get_filename(), false);

            return \html_writer::link($url, $file->get_filename());
        }

        return '';
    }
}
