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

namespace mod_booking\local\wbagent\booking\tasks;

/**
 * Shared option schema properties used by booking mutation tasks.
 *
 * @package    mod_booking
 * @copyright  2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class option_schema_definition {
    /**
     * Return shared option properties for create/update/bulk-update schemas.
     *
     * @return array
     */
    public static function common_properties(): array {
        return [
            'description' => [
                'type' => 'string',
                'description' => 'Long description/body text of the booking option.',
                'required' => false,
            ],
            'location'    => ['type' => 'string', 'description' => 'Location / venue.', 'required' => false],
            'address'     => ['type' => 'string', 'description' => 'Address of the venue.', 'required' => false],
            'maxanswers'  => ['type' => 'integer', 'description' => 'Maximum number of participants.', 'required' => false],
            'maxoverbooking' => ['type' => 'integer', 'description' => 'Waiting-list size.', 'required' => false],
            'optiondates' => [
                'type' => 'array',
                'description' => 'Optional list of date ranges. Each item should include '
                    . 'coursestarttime and courseendtime (ISO 8601 or Unix timestamp).',
                'required' => false,
            ],
            'optiondatesmode' => [
                'type' => 'string',
                'description' => 'How optiondates are applied on update: append (default, keep existing sessions) '
                    . 'or replace (overwrite all existing sessions).',
                'required' => false,
            ],
            'coursestarttime' => [
                'type' => 'string',
                'description' => 'Legacy single-date start time (ISO 8601 or Unix timestamp). Prefer optiondates[].',
                'required' => false,
            ],
            'courseendtime' => [
                'type' => 'string',
                'description' => 'Legacy single-date end time (ISO 8601 or Unix timestamp). Prefer optiondates[].',
                'required' => false,
            ],
            'bookingopeningtime' => [
                'type' => 'string',
                'description' => 'Booking opening time restriction (ISO 8601 or Unix timestamp).',
                'required' => false,
            ],
            'bookingclosingtime' => [
                'type' => 'string',
                'description' => 'Booking closing time restriction (ISO 8601 or Unix timestamp).',
                'required' => false,
            ],
            'teacheremail' => [
                'type'        => 'string',
                'description' => 'E-mail address of the teacher. Used to resolve teacherid.',
                'required'    => false,
            ],
            'teacherquery' => [
                'type'        => 'string',
                'description' => 'Search query to resolve a teacher by name/email/id when teacheremail is unknown.',
                'required'    => false,
            ],
            'optiontype' => [
                'type' => 'string',
                'description' => 'Booking option type. Accepted values: normal|withdates, selflearning|selflearningcourse, '
                    . 'slotbooking|slot.',
                'required' => false,
            ],
            'slot_enabled' => [
                'type' => 'boolean',
                'description' => 'Alias for optiontype=slotbooking. If true, slot booking type is selected.',
                'required' => false,
            ],
            'slot_opening_time' => [
                'type' => 'string',
                'description' => 'AVAILABILITY WINDOW start (HH:MM). This is NOT the slot duration. '
                    . 'It defines the earliest time a slot can start on each active weekday (e.g. "12:00").',
                'required' => false,
            ],
            'slot_closing_time' => [
                'type' => 'string',
                'description' => 'AVAILABILITY WINDOW end (HH:MM). This is NOT the slot duration. '
                    . 'It defines the latest time a slot can end on each active weekday (e.g. "16:00").',
                'required' => false,
            ],
            'slot_valid_from' => [
                'type' => 'string',
                'description' => 'Date from which recurring slots are generated (ISO 8601 or Unix timestamp).',
                'required' => false,
            ],
            'slot_valid_until' => [
                'type' => 'string',
                'description' => 'Date until which recurring slots are generated (ISO 8601 or Unix timestamp).',
                'required' => false,
            ],
            // WEEKDAY FLAGS: 1=Monday, 2=Tuesday, 3=Wednesday, 4=Thursday, 5=Friday, 6=Saturday, 7=Sunday.
            // For slotbooking: set ONLY the intended day(s) to true. All other days MUST be explicitly false.
            // Example for Wednesday only: slot_day_3=true, slot_day_1=false, slot_day_2=false,
            // slot_day_4=false, slot_day_5=false, slot_day_6=false, slot_day_7=false.
            'slot_day_1' => [
                'type' => 'boolean',
                'description' => 'Monday (day 1). true=active, false=inactive. Must be set explicitly.',
                'required' => false,
            ],
            'slot_day_2' => [
                'type' => 'boolean',
                'description' => 'Tuesday (day 2). true=active, false=inactive. Must be set explicitly.',
                'required' => false,
            ],
            'slot_day_3' => [
                'type' => 'boolean',
                'description' => 'Wednesday (day 3). true=active, false=inactive. Must be set explicitly.',
                'required' => false,
            ],
            'slot_day_4' => [
                'type' => 'boolean',
                'description' => 'Thursday (day 4). true=active, false=inactive. Must be set explicitly.',
                'required' => false,
            ],
            'slot_day_5' => [
                'type' => 'boolean',
                'description' => 'Friday (day 5). true=active, false=inactive. Must be set explicitly.',
                'required' => false,
            ],
            'slot_day_6' => [
                'type' => 'boolean',
                'description' => 'Saturday (day 6). true=active, false=inactive. Must be set explicitly.',
                'required' => false,
            ],
            'slot_day_7' => [
                'type' => 'boolean',
                'description' => 'Sunday (day 7). true=active, false=inactive. Must be set explicitly.',
                'required' => false,
            ],
            'slot_duration_minutes' => [
                'type' => 'integer',
                'description' => 'REQUIRED for slotbooking: Length of each INDIVIDUAL slot in minutes (e.g. 30). '
                    . 'This is the duration a user books, NOT the opening-to-closing window. '
                    . 'Example: window 12:00-16:00 with slot_duration_minutes=30 creates 8 slots of 30 min each.',
                'required' => false,
            ],
            'slot_interval_minutes' => [
                'type' => 'integer',
                'description' => 'Slot interval in minutes for rolling slot setup.',
                'required' => false,
            ],
            'slot_max_participants_per_slot' => [
                'type' => 'integer',
                'description' => 'Maximum participants per slot.',
                'required' => false,
            ],
            'slot_max_slots_per_user' => [
                'type' => 'integer',
                'description' => 'Maximum number of slots each user can book.',
                'required' => false,
            ],
            'selflearningcourse' => [
                'type' => 'boolean',
                'description' => 'If true, marks this as a self-learning course (no fixed dates; '
                    . 'duration is used instead). Requires PRO and selflearningcourse feature to be active.',
                'required' => false,
            ],
            'duration' => [
                'type' => 'integer',
                'description' => 'Duration of the booking option in seconds. Only stored when selflearningcourse=true. '
                    . 'E.g. 4 hours = 14400.',
                'required' => false,
            ],
            'disablecancel' => [
                'type' => 'boolean',
                'description' => 'If true, participants cannot cancel their own booking. '
                    . 'If false (default), self-cancellation is allowed.',
                'required' => false,
            ],
            'invisible' => [
                'type' => 'integer',
                'description' => 'Visibility state of the option: 0 = visible, 1 = invisible, 2 = visible only via direct link.',
                'required' => false,
            ],
            'visibility' => [
                'type' => 'string',
                'description' => 'Visibility alias: visible|invisible|directlink (also accepts visiblewithlink/public/hidden).',
                'required' => false,
            ],
            'coursequery' => [
                'type' => 'string',
                'description' => 'Search query to resolve a Moodle course by full name/shortname and link it.',
                'required' => false,
            ],
            'enrolledincoursequery' => [
                'type' => 'string',
                'description' => 'Course query (single or comma-separated) to restrict booking to users enrolled '
                    . 'in these course(s). Uses enrolled-in-course availability condition.',
                'required' => false,
            ],
            'enrolledincourseenabled' => [
                'type' => 'boolean',
                'description' => 'Enable/disable enrolled-in-course restriction explicitly.',
                'required' => false,
            ],
            'enrolledincourseoperator' => [
                'type' => 'string',
                'description' => 'Operator for enrolled-in-course restriction: OR (default) or AND.',
                'required' => false,
            ],
            'enrolledincoursesqlfilter' => [
                'type' => 'boolean',
                'description' => 'Enable SQL-based enrollment filter for enrolled-in-course condition.',
                'required' => false,
            ],
            'enrolledincourseoverride' => [
                'type' => 'boolean',
                'description' => 'Enable override for enrolled-in-course condition.',
                'required' => false,
            ],
            'enrolledincourseoverrideoperator' => [
                'type' => 'string',
                'description' => 'Override operator for enrolled-in-course condition: OR or AND.',
                'required' => false,
            ],
            'enrolledincourseoverrideconditionids' => [
                'type' => 'array',
                'description' => 'List of condition IDs that can override the enrolled-in-course condition.',
                'required' => false,
            ],
            'enrolledincohortquery' => [
                'type' => 'string',
                'description' => 'Cohort query (single or comma-separated) to restrict booking to cohort members.',
                'required' => false,
            ],
            'enrolledincohortenabled' => [
                'type' => 'boolean',
                'description' => 'Enable/disable enrolled-in-cohort restriction explicitly.',
                'required' => false,
            ],
            'enrolledincohortoperator' => [
                'type' => 'string',
                'description' => 'Operator for cohort restriction: OR (default) or AND.',
                'required' => false,
            ],
            'enrolledincohort_sqlfilter' => [
                'type' => 'boolean',
                'description' => 'Enable SQL-based cohort filter for enrolled-in-cohort condition.',
                'required' => false,
            ],
            'enrolledincohortoverride' => [
                'type' => 'boolean',
                'description' => 'Enable override for enrolled-in-cohort condition.',
                'required' => false,
            ],
            'enrolledincohortoverrideoperator' => [
                'type' => 'string',
                'description' => 'Override operator for enrolled-in-cohort condition: OR or AND.',
                'required' => false,
            ],
            'enrolledincohortoverrideconditionids' => [
                'type' => 'array',
                'description' => 'List of condition IDs that can override the enrolled-in-cohort condition.',
                'required' => false,
            ],
            'hascompetencyquery' => [
                'type' => 'string',
                'description' => 'Competency query (single or comma-separated) for has-competency restriction.',
                'required' => false,
            ],
            'hascompetencyenabled' => [
                'type' => 'boolean',
                'description' => 'Enable/disable has-competency restriction explicitly.',
                'required' => false,
            ],
            'hascompetencyoperator' => [
                'type' => 'string',
                'description' => 'Operator for competency restriction: AND (default) or OR.',
                'required' => false,
            ],
            'hascompetencyoverride' => [
                'type' => 'boolean',
                'description' => 'Enable override for has-competency condition.',
                'required' => false,
            ],
            'hascompetencyoverrideoperator' => [
                'type' => 'string',
                'description' => 'Override operator for has-competency condition: OR or AND.',
                'required' => false,
            ],
            'hascompetencyoverrideconditionids' => [
                'type' => 'array',
                'description' => 'List of condition IDs that can override the has-competency condition.',
                'required' => false,
            ],
            'previouslybookedquery' => [
                'type' => 'string',
                'description' => 'Option query identifying the prerequisite booking option.',
                'required' => false,
            ],
            'previouslybookedenabled' => [
                'type' => 'boolean',
                'description' => 'Enable/disable previously-booked restriction explicitly.',
                'required' => false,
            ],
            'previouslybookedrequirecompletion' => [
                'type' => 'boolean',
                'description' => 'Require completion of prerequisite option (default false).',
                'required' => false,
            ],
            'selectusersquery' => [
                'type' => 'string',
                'description' => 'User query list (comma-separated) for explicit user allowlist restriction.',
                'required' => false,
            ],
            'selectusersenabled' => [
                'type' => 'boolean',
                'description' => 'Enable/disable selected-users restriction explicitly.',
                'required' => false,
            ],
            'bookusersquery' => [
                'type' => 'string',
                'description' => 'User query list (single or comma-separated) to directly book users to this option.',
                'required' => false,
            ],
            'bookuserstimebooked' => [
                'type' => 'string',
                'description' => 'Optional booking timestamp for imported bookings (ISO 8601 or Unix timestamp).',
                'required' => false,
            ],
            'bookuserscompleted' => [
                'type' => 'boolean',
                'description' => 'If true, mark newly booked users as completed.',
                'required' => false,
            ],
            'bookusersupdateexisting' => [
                'type' => 'boolean',
                'description' => 'If true, update existing booking answers when user is already booked.',
                'required' => false,
            ],
            'selectusersoverride' => [
                'type' => 'boolean',
                'description' => 'Enable override for select-users condition.',
                'required' => false,
            ],
            'selectusersoverrideoperator' => [
                'type' => 'string',
                'description' => 'Override operator for select-users condition: OR or AND.',
                'required' => false,
            ],
            'selectusersoverrideconditionids' => [
                'type' => 'array',
                'description' => 'List of condition IDs that can override the select-users condition.',
                'required' => false,
            ],
            'nooverlappingmode' => [
                'type' => 'string',
                'description' => 'No-overlapping condition mode: block or warn.',
                'required' => false,
            ],
            'nooverlappingenabled' => [
                'type' => 'boolean',
                'description' => 'Enable/disable no-overlapping condition explicitly.',
                'required' => false,
            ],
            'allowedtobookininstance' => [
                'type' => 'boolean',
                'description' => 'Enable allowed-to-book-in-instance condition.',
                'required' => false,
            ],
            'allowedtobookininstancecapabilitynotneeded' => [
                'type' => 'boolean',
                'description' => 'If true, users without capability may still book (default true in UI).',
                'required' => false,
            ],
            'userprofilestandardfield' => [
                'type' => 'string',
                'description' => 'Standard Moodle user profile field name for condition (e.g. department, city).',
                'required' => false,
            ],
            'userprofilestandardenabled' => [
                'type' => 'boolean',
                'description' => 'Enable/disable standard user profile condition explicitly.',
                'required' => false,
            ],
            'userprofilestandardoperator' => [
                'type' => 'string',
                'description' => 'Operator for standard profile condition (=, !=, <, >, ~, !~, [], [!], [~], [!~], (), (!)).',
                'required' => false,
            ],
            'userprofilestandardvalue' => [
                'type' => 'string',
                'description' => 'Comparison value for standard profile condition.',
                'required' => false,
            ],
            'userprofilestandardoverride' => [
                'type' => 'boolean',
                'description' => 'Enable override for standard user profile condition.',
                'required' => false,
            ],
            'userprofilestandardoverrideoperator' => [
                'type' => 'string',
                'description' => 'Override operator for standard user profile condition: OR or AND.',
                'required' => false,
            ],
            'userprofilestandardoverrideconditionids' => [
                'type' => 'array',
                'description' => 'List of condition IDs that can override the standard user profile condition.',
                'required' => false,
            ],
            'userprofilecustomfield' => [
                'type' => 'string',
                'description' => 'Custom user profile field shortname for condition.',
                'required' => false,
            ],
            'userprofilecustomenabled' => [
                'type' => 'boolean',
                'description' => 'Enable/disable custom user profile condition explicitly.',
                'required' => false,
            ],
            'userprofilecustomoperator' => [
                'type' => 'string',
                'description' => 'Operator for custom profile condition (=, !=, <, >, ~, !~, [], [!], [~], [!~], (), (!)).',
                'required' => false,
            ],
            'userprofilecustomvalue' => [
                'type' => 'string',
                'description' => 'Comparison value for custom profile condition.',
                'required' => false,
            ],
            'userprofilecustomconnectsecondfield' => [
                'type' => 'boolean',
                'description' => 'Connect a second profile field with AND logic for custom profile condition.',
                'required' => false,
            ],
            'userprofilecustomfield2' => [
                'type' => 'string',
                'description' => 'Second custom user profile field shortname for combined condition.',
                'required' => false,
            ],
            'userprofilecustomoperator2' => [
                'type' => 'string',
                'description' => 'Operator for second custom profile field (=, !=, <, >, ~, !~, [], [!], [~], [!~], (), (!)).',
                'required' => false,
            ],
            'userprofilecustomvalue2' => [
                'type' => 'string',
                'description' => 'Comparison value for second custom profile field.',
                'required' => false,
            ],
            'userprofilecustomsqlfilter' => [
                'type' => 'boolean',
                'description' => 'Enable SQL-based filter for custom user profile condition.',
                'required' => false,
            ],
            'userprofilecustomoverride' => [
                'type' => 'boolean',
                'description' => 'Enable override for custom user profile condition.',
                'required' => false,
            ],
            'userprofilecustomoverrideoperator' => [
                'type' => 'string',
                'description' => 'Override operator for custom user profile condition: OR or AND.',
                'required' => false,
            ],
            'userprofilecustomoverrideconditionids' => [
                'type' => 'array',
                'description' => 'List of condition IDs that can override the custom user profile condition.',
                'required' => false,
            ],
            'customformjson' => [
                'type' => 'object',
                'description' => 'Custom form condition payload with formsarray and optional deleteinfoscheckboxadmin.',
                'required' => false,
            ],
            'customformelements' => [
                'type' => 'array',
                'description' => 'Custom form elements for the customform condition. Each item supports: '
                    . 'formtype (advcheckbox|static|shorttext|select|url|mail|deleteinfoscheckboxuser|enrolusersaction), '
                    . 'label, value, required, enroluserstowaitinglist. '
                    . 'For formtype=select, value is a multiline list where each line is either '
                    . '"key => Display name" or '
                    . '"key => Display name => maxbookings => price => alloweduserids".',
                'required' => false,
            ],
            'customformdeleteinfoscheckboxadmin' => [
                'type' => 'boolean',
                'description' => 'Admin delete-info checkbox flag for custom form condition.',
                'required' => false,
            ],
            'customformenabled' => [
                'type' => 'boolean',
                'description' => 'Enable/disable custom form condition explicitly.',
                'required' => false,
            ],
            'prices' => [
                'type' => 'object',
                'description' => 'Map of price category identifiers to numeric prices, e.g. '
                    . '{"default": 10, "student": 20}.',
                'required' => false,
            ],
        ];
    }
}
