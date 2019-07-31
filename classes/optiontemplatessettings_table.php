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

class optiontemplatessettings_table extends table_sql {

    public function __construct($uniqueid) {
        parent::__construct($uniqueid);

        // Define the list of columns to show.
        $columns = array('name', 'coursename', 'id');
        $this->define_columns($columns);

        // Define the titles of columns to show in header.
        $headers = array(get_string('booking', 'booking'), get_string('course'), '');
        $this->define_headers($headers);
    }

    public function col_coursename($values) {
        if ($values->courseid == 0) {
            return "";
        }

        return $values->coursename;
    }

    public function col_id($values) {
        $delete = get_string('delete');
        $url = new moodle_url('/mod/booking/optiontemplatessettings.php', array('id' => $values->id));
        return "<a href=\"{$url}\">{$delete}</a>";
    }

    public function other_cols($colname, $value) {
        return $value->{$colname};
    }

}