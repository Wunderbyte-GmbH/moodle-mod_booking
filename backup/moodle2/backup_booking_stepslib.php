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
 * Define all the backup steps that will be used by the backup_booking_activity_task
 *
 * @package mod_booking
 * @copyright 2012 onwards David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Define the complete booking structure for backup, with file and id annotations
 */
class backup_booking_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the booking instance structure.
     *
     * @return backup_nested_element
     * @throws base_element_struct_exception
     * @throws base_step_exception
     */
    protected function define_structure() {

        // To know if we are including userinfo.
        $userinfo = $this->get_setting_value('userinfo');

        // Notice: Semesters and holidays are not backed up with booking instances, as they are set for the whole site.

        // Define each element separated.
        $booking = new backup_nested_element(
            'booking',
            ['id'],
            ['course', 'name', 'intro', 'introformat', 'bookingmanager', 'mailtemplatessource', 'sendmail',
                'copymail', 'allowupdate', 'bookingpolicy', 'bookingpolicyformat', 'timeopen', 'timeclose', 'timemodified',
                'autoenrol', 'bookedtext', 'waitingtext', 'statuschangetext', 'deletedtext', 'bookingchangedtext',
                'maxperuser', 'sendmailtobooker', 'duration', 'points', 'organizatorname',
                'pollurl', 'addtogroup', 'categoryid', 'pollurltext', 'eventtype',
                'notificationtext', 'userleave', 'enablecompletion', 'pollurlteachers',
                'pollurlteacherstext', 'activitycompletiontext', 'cancancelbook', 'conectedbooking', 'showinapi',
                'lblbooking', 'lbllocation', 'lblinstitution', 'lblname', 'lblsurname',
                'btncacname', 'lblteachname', 'lblsputtname', 'btnbooknowname', 'btncancelname',
                'booktootherbooking', 'lblacceptingfrom', 'lblnumofusers', 'numgenerator',
                'paginationnum', 'banusernames', 'daystonotify', 'notifyemail',
                'daystonotifyteachers', 'notifyemailteachers', 'assessed', 'assesstimestart', 'assesstimefinish',
                'scale', 'whichview', 'daystonotify2', 'enablepresence', 'completionmodule', 'responsesfields',
                'reportfields', 'optionsfields', 'optionsdownloadfields',
                'beforebookedtext', 'beforecompletedtext', 'aftercompletedtext',
                'signinsheetfields', 'comments', 'ratings', 'removeuseronunenrol', 'teacherroleid', 'allowupdatedays',
                'templateid', 'showlistoncoursepage', 'coursepageshortinfo', 'bookingimagescustomfield',
                'defaultoptionsort', 'defaultsortorder', 'showviews', 'customtemplateid', 'autcractive', 'autcrprofile',
                'autcrvalue', 'autcrtemplate', 'semesterid', 'iselective', 'maxcredits', 'consumeatonce', 'enforceorder',
            ]
        );

        $options = new backup_nested_element('options');
        $option = new backup_nested_element(
            'option',
            ['id'],
            ['text', 'maxanswers', 'maxoverbooking', 'minanswers', 'bookingopeningtime', 'bookingclosingtime', 'courseid',
                'coursestarttime', 'courseendtime', 'enrolmentstatus', 'description', 'descriptionformat',
                'limitanswers', 'timecreated', 'timemodified', 'addtocalendar', 'calendarid', 'pollurl',
                'groupid', 'sent', 'sent2', 'sentteachers', 'location', 'institution', 'address',
                'pollurlteachers', 'howmanyusers', 'pollsend', 'removeafterminutes',
                'notificationtext', 'notificationtextformat', 'disablebookingusers',
                'beforebookedtext', 'beforecompletedtext', 'aftercompletedtext', 'shorturl', 'duration',
                'parentid', 'semesterid', 'dayofweektime', 'invisible', 'timemadevisible', 'annotation',
                'identifier', 'titleprefix', 'priceformulaadd', 'priceformulamultiply', 'priceformulaoff',
                'dayofweek', 'availability', 'status', 'responsiblecontact', 'credits', 'sortorder', 'json', 'sqlfilter',
            ]
        );

        $answers = new backup_nested_element('answers');
        $answer = new backup_nested_element(
            'answer',
            ['id'],
            ['bookingid', 'optionid', 'userid', 'timemodified', 'completed', 'timecreated',
                'waitinglist', 'frombookingid', 'numrec', 'status', 'notes',
            ]
        );

        $optiondates = new backup_nested_element('optiondates');
        $optiondate = new backup_nested_element(
            'optiondate',
            ['id'],
            ['bookingid', 'optionid', 'eventid', 'coursestarttime', 'courseendtime', 'daystonotify', 'sent', 'reason', 'reviewed']
        );

        $optiondatesteachers = new backup_nested_element('optiondates_teachers');
        $optiondatesteacher = new backup_nested_element(
            'optiondates_teacher',
            ['id'],
            ['optiondateid', 'userid']
        );

        $categories = new backup_nested_element('categories');
        $category = new backup_nested_element(
            'category',
            ['id'],
            ['cid', 'name']
        );

        $teachers = new backup_nested_element('teachers');
        $teacher = new backup_nested_element(
            'teacher',
            ['id'],
            ['bookingid', 'optionid', 'userid', 'completed']
        );

        $tags = new backup_nested_element('tags');
        $tag = new backup_nested_element(
            'tag',
            ['id'],
            ['tag', 'text', 'textformat']
        );

        $others = new backup_nested_element('others');
        $other = new backup_nested_element(
            'other',
            ['id'],
            ['optionid', 'otheroptionid', 'userslimit']
        );

        $prices = new backup_nested_element('prices');
        $price = new backup_nested_element(
            'price',
            ['id'],
            ['itemid', 'area', 'pricecategoryidentifier', 'price', 'currency']
        );

        // Entities can be set for whole options...
        $entitiesrelationsforoptions = new backup_nested_element('entitiesrelationsforoptions');
        $entitiesrelationforoption = new backup_nested_element(
            'entitiesrelationforoption',
            ['id'],
            ['entityid', 'component', 'area', 'instanceid', 'timecreated']
        );
        $entitiesrelationsforoptions->add_child($entitiesrelationforoption);

        // ...or for individual optiondates.
        $entitiesrelationsforoptiondates = new backup_nested_element('entitiesrelationsforoptiondates');
        $entitiesrelationforoptiondate = new backup_nested_element(
            'entitiesrelationforoptiondate',
            ['id'],
            ['entityid', 'component', 'area', 'instanceid', 'timecreated']
        );
        $entitiesrelationsforoptiondates->add_child($entitiesrelationforoptiondate);

        $customfields = new backup_nested_element('customfields');
        $customfield = new backup_nested_element(
            'customfield',
            ['id'],
            ['bookingid', 'optionid', 'optiondateid', 'cfgname', 'value']
        );

        $subbookingoptions = new backup_nested_element('subbookingoptions');
        $subbookingoption = new backup_nested_element(
            'subbookingoption',
            ['id'],
            ['optionid', 'name', 'type', 'json', 'block']
        );

        $history = new backup_nested_element('history');
        $historyitem = new backup_nested_element(
            'historyitem',
            ['id'],
            ['bookingid', 'optionid', 'answerid', 'userid', 'status', 'json']
        );

        // Build the tree.
        $booking->add_child($options);
        $options->add_child($option);

        $booking->add_child($answers);
        $answers->add_child($answer);

        $booking->add_child($optiondates);
        $optiondates->add_child($optiondate);

        $optiondate->add_child($optiondatesteachers);
        $optiondatesteachers->add_child($optiondatesteacher);

        $booking->add_child($categories);
        $categories->add_child($category);

        $booking->add_child($teachers);
        $teachers->add_child($teacher);

        $booking->add_child($tags);
        $tags->add_child($tag);

        $booking->add_child($customfields);
        $customfields->add_child($customfield);

        $option->add_child($others);
        $others->add_child($other);

        $option->add_child($prices);
        $prices->add_child($price);

        // We have entitiesrelations for both options and optiondates!
        $option->add_child($entitiesrelationsforoptions);
        $optiondate->add_child($entitiesrelationsforoptiondates);

        $option->add_child($subbookingoptions);
        $subbookingoptions->add_child($subbookingoption);

        $booking->add_child($history);
        $history->add_child($historyitem);

        // Define sources.
        $booking->set_source_table('booking', ['id' => backup::VAR_ACTIVITYID]);

        $option->set_source_sql('SELECT * FROM {booking_options} WHERE bookingid = ?', [backup::VAR_PARENTID]);

        $category->set_source_table('booking_category', ['course' => '../../course']);
        $tag->set_source_table('booking_tags', ['courseid' => '../../course']);
        $other->set_source_table('booking_other', ['optionid' => backup::VAR_PARENTID]);
        $optiondate->set_source_table('booking_optiondates', ['bookingid' => backup::VAR_PARENTID]);
        $customfield->set_source_table('booking_customfields', ['bookingid' => backup::VAR_PARENTID]);
        $historyitem->set_source_table('booking_history', ['bookingid' => backup::VAR_PARENTID]);

        // Only backup (or duplicate) teachers, if config setting is set.
        if (get_config('booking', 'duplicationrestoreteachers')) {
            $teacher->set_source_table('booking_teachers', ['bookingid' => backup::VAR_PARENTID]);
            // Also backup teaching reports (which teacher was there at which session).
            $optiondatesteacher->set_source_table('booking_optiondates_teachers', ['optiondateid' => backup::VAR_PARENTID]);
        }

        // Only backup (or duplicate) prices, if config setting is set.
        if (get_config('booking', 'duplicationrestoreprices')) {
            /* IMPORTANT: Once we support subbookings, we might have different areas than 'option'
            and this means 'itemid' might be something else than an optionid.
            So we have to find out, if we still can set the params like this. */
            $price->set_source_table('booking_prices', ['itemid' => backup::VAR_PARENTID]);
        }

        // Only backup (or duplicate) entities, if config setting is set AND if entities are available.
        if (get_config('booking', 'duplicationrestoreentities') && class_exists('local_entities\entitiesrelation_handler')) {
            $entitiesrelationforoption->set_source_table(
                'local_entities_relations',
                ['instanceid' => backup::VAR_PARENTID]
            );
            $entitiesrelationforoptiondate->set_source_table(
                'local_entities_relations',
                ['instanceid' => backup::VAR_PARENTID]
            );
        }

        // Only backup (or duplicate) subbookingoptions, if config setting is set.
        if (get_config('booking', 'duplicationrestoresubbookings')) {
            $subbookingoption->set_source_table('booking_subbooking_options', ['optionid' => backup::VAR_PARENTID]);
        }

        // All the rest of elements only happen if we are including user info.
        if ($userinfo) {
            $answer->set_source_table('booking_answers', ['bookingid' => backup::VAR_PARENTID]);
        }

        // Define id annotations.
        $answer->annotate_ids('user', 'userid');

        // Define file annotations.
        $booking->annotate_files('mod_booking', 'intro', null); // This file area hasn't itemid.
        $booking->annotate_files('mod_booking', 'bookingpolicy', null); // This file area hasn't
                                                                        // itemid.
        $booking->annotate_files('mod_booking', 'description', 'id'); // This file area hasn't
                                                                      // itemid.
                                                                      // Return the root element
                                                                      // (booking), wrapped into
                                                                      // standard activity
                                                                      // structure.
        return $this->prepare_activity_structure($booking);
    }
}
