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
 * Testfile for timetable
 * @package    mod_booking
 * @copyright  2022 Wunderbyte GmbH
 * @author     Thomas Winkler
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$delid = optional_param('del', 0, PARAM_INT);
$context = \context_system::instance();
$PAGE->set_context($context);
require_login();

$PAGE->set_url(new moodle_url('/mod/booking/subbooking_timetabletest.php', []));

$title = "timetabletest";
$PAGE->set_title($title);
$PAGE->set_heading($title);

// Test JSON for timetable.
$json = '{
   "days": [
      {
         "day":"29.11"
      },
      {
         "day":"30.11",
         "current": true

      },
      {
         "day":"01.12"
      }
   ],
   "date":30.11,
   "slots":[
      {
            "slot":"08:00-09:00"
      },
      {
            "slot":"09:00-10:00"
      },
      {
            "slot":"10:00-11:00"
      },
      {
            "slot":"11:00-12:00"
      },
      {
            "slot":"12:00-13:00"
      },
      {
            "slot":"13:00-14:00"
      },
      {
            "slot":"14:00-15:00"
      },
      {
            "slot":"15:00-16:00"
      },
      {
            "slot":"16:00-17:00"
      },
      {
            "slot":"17:00-18:00"
      },
      {
            "slot":"19:00-20:00"
      },
      {
            "slot":"20:00-21:00"
      },
      {
            "slot":"21:00-22:00"
      }
   ],
   "locations":[
      {
         "name":"Halle1",
         "timeslots":[
            {
               "free":true,
               "slot":"11:00 - 12:00",
               "price":30,
               "currency":"€",
               "area": "subbooking-optionid",
               "component": "mod_booking",
               "itemid": "1"
            },
            {
               "free":false,
               "slot":"12:00 - 13:00",
               "price":30,
               "currency":"€",
               "area": "subbooking-optionid",
               "component": "mod_booking",
               "itemid": "2"
            },
            {
               "free":false,
               "slot":"13:00 - 14:00",
               "price":30,
               "currency":"€",
               "area": "subbooking-optionid",
               "component": "mod_booking",
               "itemid": "3"
            }
         ]
      },
      {
         "name":"Halle2",
         "timeslots":[
            {
               "free":false,
               "slot":"11:00 - 12:00",
               "price":30,
               "currency":"€"
            },
            {
               "free":true,
               "slot":"12:00 - 13:00",
               "price":30,
               "currency":"€"
            },
            {
               "free":true,
               "slot":"13:00 - 14:00",
               "price":30,
               "currency":"€"
            }
         ]
      },
      {
         "name":"Halle3",
         "timeslots":[
            {
               "free":false,
               "slot":"11:00 - 12:00",
               "price":30,
               "currency":"€"
            },
            {
               "free":false,
               "slot":"12:00 - 13:00",
               "price":30,
               "currency":"€"
            },
            {
               "free":true,
               "slot":"13:00 - 14:00",
               "price":30,
               "currency":"€"
            }
         ]
      }
   ]
}';

$jsondecode = json_decode($json);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_booking/subbooking/timeslottable', $jsondecode);

echo $OUTPUT->footer();
