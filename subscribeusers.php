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
 * This page allows a user to subscribe/unsubscribe other users from a booking option.
 *
 * TODO: upgrade logging, add logging for added/deleted users
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author David Bogner davidbogner@gmail.com
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/booking/locallib.php');

use core\output\notification;
use mod_booking\booking_utils;
use mod_booking\form\subscribe_cohort_or_group_form;
use mod_booking\output\booked_users;
use mod_booking\output\renderer;
use mod_booking\singleton_service;
use mod_booking\booking_existing_user_selector;
use mod_booking\booking_potential_user_selector;
use mod_booking\local\bookingworkflow\bookforothers;

global $CFG, $DB, $COURSE, $PAGE, $OUTPUT;

$id = required_param('id', PARAM_INT); // Course_module ID.
$optionid = required_param('optionid', PARAM_INT);
$subscribe = optional_param('subscribe', false, PARAM_BOOL);
$unsubscribe = optional_param('unsubscribe', false, PARAM_BOOL);
$agree = optional_param('agree', false, PARAM_BOOL);
$bookanyone = optional_param('bookanyone', false, PARAM_BOOL);
$synctoggle = optional_param('synctoggle', 0, PARAM_INT);
$synctoggleval = optional_param('synctoggleval', -1, PARAM_INT);
$syncdisableall = optional_param('syncdisableall', 0, PARAM_INT);

if (get_config('booking', 'alwaysbookanyone')) {
    $bookanyone = true;
}

// If we have already submitted the form, we don't want to fall into the agree policy.
$formsubmitted = optional_param('submitbutton', '', PARAM_TEXT);

[$course, $cm] = get_course_and_cm_from_cmid($id);

(bool) $subscribesuccess = false;
(bool) $unsubscribesuccess = false;

require_login($course, true, $cm);

// Print the page header.
$context = context_module::instance($cm->id);
$PAGE->set_context($context);

// In Moodle 4.0+ we want to turn the instance description off on every page except view.php.
$PAGE->activityheader->disable();

$bookingoption = singleton_service::get_instance_of_booking_option($cm->id, $optionid);
$optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);

$url = new moodle_url('/mod/booking/subscribeusers.php', ['id' => $id, 'optionid' => $optionid, 'agree' => $agree]);
$errorurl = new moodle_url('/mod/booking/view.php', ['id' => $id]);

$PAGE->set_url($url);

// Without the "bookforothers" or "bookmyteam" capability, we do not allow anything.
if (
    !has_capability('mod/booking:bookforothers', $context)
    && !has_capability('mod/booking:bookmyteam', $context)
) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('accessdenied', 'mod_booking'), 4);
    echo get_string('nopermissiontoaccesspage', 'mod_booking');
    echo $OUTPUT->footer();
    die();
}

if (!booking_check_if_teacher($bookingoption->option)) {
    if (!(has_capability('mod/booking:subscribeusers', $context) || has_capability('moodle/site:accessallgroups', $context))) {
        throw new moodle_exception('nopermissions', 'core', $errorurl, get_string('bookotherusers', 'mod_booking'));
    }
}

// Slot booking options manage their participants per slot. Booking users here directly is not
// possible, so instead of the subscribe form we show an explanatory warning and a way back.
if ((int)($optionsettings->type ?? MOD_BOOKING_OPTIONTYPE_DEFAULT) === MOD_BOOKING_OPTIONTYPE_SLOTBOOKING) {
    $PAGE->set_title(get_string('modulename', 'booking'));
    $PAGE->set_heading($COURSE->fullname);
    $PAGE->navbar->add(get_string('booking:subscribeusers', 'booking'), $url);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($optionsettings->get_title_with_prefix()), 3);

    $warning = get_string('slot_nosubscribe', 'mod_booking');
    if (class_exists('local_shopping_cart\shopping_cart')) {
        $warning .= ' ' . get_string('slot_nosubscribe_cashier', 'mod_booking');
    }
    $warning .= ' ' . get_string('slot_nosubscribe_unenrol', 'mod_booking');
    echo $OUTPUT->notification($warning, notification::NOTIFY_WARNING);

    $backurl = new moodle_url('/mod/booking/report.php', ['id' => $cm->id, 'optionid' => $optionid]);
    echo $OUTPUT->single_button($backurl, get_string('backtoresponses', 'booking'), 'get');

    echo $OUTPUT->footer();
    die();
}

if (($synctoggle || $syncdisableall) && has_capability('mod/booking:updatebooking', $context) && confirm_sesskey()) {
    if ($syncdisableall) {
        \mod_booking\local\sync\booking_enrolment::disable_rules_for_option($optionid);
    } else if ($synctoggle && $synctoggleval >= 0) {
        \mod_booking\local\sync\booking_enrolment::update_rule_settings($synctoggle, ['isenabled' => (int)$synctoggleval]);
    }
    redirect(new moodle_url('/mod/booking/subscribeusers.php', ['id' => $id, 'optionid' => $optionid, 'agree' => $agree]));
}

$bookingoption->update_booked_users();
$bookingoption->apply_tags();

$PAGE->set_title(get_string('modulename', 'booking'));
$PAGE->set_heading($COURSE->fullname);
$PAGE->navbar->add(get_string('booking:subscribeusers', 'booking'), $url);
if (!$agree && empty($formsubmitted) && (!empty($bookingoption->booking->settings->bookingpolicy))) {
    echo $OUTPUT->header();
    $alright = false;
    $message = "<p><b>" . get_string('bookingpolicyagree', 'booking') . ":</b></p>";
    $message .= "<p>" . format_text($bookingoption->booking->settings->bookingpolicy, FORMAT_HTML) . "<p>";
    $continueurl = new moodle_url($PAGE->url->out(false, ['agree' => 1]));
    $continue = new single_button($continueurl, get_string('continue'), 'get');
    $cancel = new single_button($errorurl, get_string('cancel'), 'get');
    echo $OUTPUT->confirm($message, $continue, $cancel);
    echo $OUTPUT->footer();
    die();
} else {
    $subscribeduseroptions = [
        'bookingid' => $cm->instance,
        'accesscontext' => $context,
        'optionid' => $optionid,
        'cm' => $cm,
        'course' => $course,
        'potentialusers' => $bookingoption->bookedvisibleusers,
    ];
    $potentialuseroptions = $subscribeduseroptions;

    // Potential users will be selected on instantiation of booking_potential_user_selector.
    $potentialuseroptions['potentialusers'] = [];

    /** @var renderer $bookingoutput */
    $bookingoutput = $PAGE->get_renderer('mod_booking');
    $existingselector = new booking_existing_user_selector('removeselect', $subscribeduseroptions);
    $subscriberselector = new booking_potential_user_selector('addselect', $potentialuseroptions);

    if (data_submitted()) {
        require_sesskey();
        if ($subscribe) {
            $users = $subscriberselector->get_selected_users();
            $subscribesuccess = true;
            $subscribedusers = [];
            $notsubscribedusers = [];

            if (
                has_capability('mod/booking:subscribeusers', $context) ||
                (booking_check_if_teacher($bookingoption->option))
            ) {
                foreach ($users as $user) {
                    // Restrict who this agent may actually book for (eg. supervisors and their own team).
                    [$allowedtobook, ] = bookforothers::check_booking_capability($optionid, $USER->id, $user->id);
                    if (!$allowedtobook) {
                        $subscribesuccess = false;
                        $notsubscribedusers[] = $user;
                        continue;
                    }

                    // If there is a price on the booking option, we don't want to subscribe the user directly.
                    if (
                        class_exists('local_shopping_cart\shopping_cart')
                        && !empty($optionsettings->jsonobject->useprice)
                        && empty(get_config('booking', 'turnoffwaitinglist'))
                    ) {
                        $status = 3; // This added without confirmation.
                    } else {
                        $status = 0;
                    }

                    if (!$bookingoption->user_submit_response($user, 0, 0, $status, MOD_BOOKING_VERIFIED)) {
                        $subscribesuccess = false;
                        $notsubscribedusers[] = $user;
                    }
                    $subscribedusers[] = $user->id;
                }
                if ($subscribesuccess) {
                    redirect(
                        $url,
                        get_string(
                            'allusersbooked',
                            'mod_booking',
                            count($subscribedusers)
                        ),
                        5
                    );
                } else {
                    $output = '<br>';
                    if (!empty($notsubscribedusers)) {
                        foreach ($notsubscribedusers as $user) {
                            $result = $DB->get_records_sql(
                                'SELECT ba.id answerid, bo.text
                                FROM {booking_answers} ba
                                LEFT JOIN {booking_options} bo ON bo.id = ba.optionid
                                WHERE ba.userid = ? AND ba.waitinglist < ?
                                AND ba.bookingid = ?',
                                [
                                    $user->id,
                                    MOD_BOOKING_STATUSPARAM_RESERVED,
                                    $bookingoption->booking->id,
                                    ]
                            );
                            $output .= "{$user->firstname} {$user->lastname}";
                            if (!empty($result)) {
                                $r = [];
                                foreach ($result as $v) {
                                    $r[] = $v->text;
                                }
                                $output .= '&nbsp;' . get_string('enrolledinoptions', 'mod_booking') .
                                         implode(', ', $r);
                            }
                            $output .= " <br>";
                        }
                    }
                    redirect($url, get_string('notallbooked', 'mod_booking', $output), 5);
                }
            } else {
                throw new moodle_exception('invalidaction');
            }
        } else if (
            $unsubscribe
            && (
                has_capability('mod/booking:deleteresponses', $context)
                || (booking_check_if_teacher($bookingoption->option))
            )
        ) {
            $users = $existingselector->get_selected_users();
            $unsubscribesuccess = true;
            foreach ($users as $user) {
                // Restrict who this agent may actually remove (eg. supervisors and their own team).
                [$allowedtobook, ] = bookforothers::check_booking_capability($optionid, $USER->id, $user->id);
                if (!$allowedtobook) {
                    $unsubscribesuccess = false;
                    throw new moodle_exception(
                        'norighttoaccess',
                        'mod_booking',
                        $url->out(),
                        null,
                        'Not allowed to unsubscribe user with id ' . $user->id
                    );
                }

                if (!$bookingoption->user_delete_response($user->id)) {
                    $unsubscribesuccess = false;
                    throw new moodle_exception(
                        'cannotremovesubscriber',
                        'booking',
                        $url->out(),
                        null,
                        'Cannot remove subscriber with id ' . $user->id
                    );
                }
            }
        } else if (
            $unsubscribe
            && (
                !has_capability('mod/booking:deleteresponses', $context)
                || (booking_check_if_teacher($bookingoption->option))
            )
        ) {
                    throw new moodle_exception(
                        'nopermission',
                        'booking',
                        $url->out(),
                        null,
                        'Permission to unsubscribe users is missing'
                    );
        }
        $subscriberselector->invalidate_selected_users();
        $existingselector->invalidate_selected_users();
        $bookingoption->update_booked_users();
        $subscriberselector->set_potential_users($bookingoption->potentialusers);
        $existingselector->set_potential_users($bookingoption->bookedvisibleusers);
    }
}

// Add the Moodle form for cohort and group subscription.
$mform = new subscribe_cohort_or_group_form();
$mform->set_data(['id' => $id, 'optionid' => $optionid, 'agree' => "1"]);

// Form processing and displaying is done here.
if ($fromform = $mform->get_data()) {
    $notificationstring = '';
    $delay = 0;
    $notificationtype = notification::NOTIFY_INFO;

    $url = new moodle_url('/mod/booking/subscribeusers.php', ['id' => $id, 'optionid' => $optionid, 'agree' => 1]);

    if (!empty($fromform->cohortids) || !empty($fromform->groupids)) {
        $result = booking_utils::book_cohort_or_group_members($fromform, $bookingoption, $context);
        $delay = 120;

        // Generate the notification string and determine the notification color.
        $notificationstring = get_string('resultofcohortorgroupbooking', 'mod_booking', $result);

        if ($result->notenrolledusers > 0 || $result->notsubscribedusers > 0) {
            $notificationstring .= get_string('problemsofcohortorgroupbooking', 'mod_booking', $result);

            if ($result->subscribedusers > 0) {
                $notificationtype = notification::NOTIFY_WARNING;
            } else {
                $notificationtype = notification::NOTIFY_ERROR;
            }
        } else {
            if ($result->subscribedusers > 0) {
                $notificationtype = notification::NOTIFY_SUCCESS;
            } else {
                $notificationtype = notification::NOTIFY_ERROR;
            }
        }
    } else {
        $notificationtype = notification::NOTIFY_ERROR;
        $notificationstring = get_string('nogrouporcohortselected', 'mod_booking');
        $delay = 5;
    }

    try {
        redirect($url, $notificationstring, $delay, $notificationtype);
    } catch (moodle_exception $e) {
        debugging('subscribeusers.php: Exception in redirect function.');
    }
}

// Under some circumstances, we don't allow direct booking of user.
if (
    class_exists('local_shopping_cart\shopping_cart')
    && !empty($optionsettings->jsonobject->useprice)
    && empty(get_config('booking', 'turnoffwaitinglist'))
) {
    $message = get_string('nodirectbookingbecauseofprice', 'mod_booking');
    $type = \core\notification::INFO;
    \core\notification::add($message, $type);
}

echo $OUTPUT->header();

echo $OUTPUT->heading(format_string($optionsettings->get_title_with_prefix()), 3, 'helptitle', 'uniqueid');

// Switch to turn booking of anyone ON or OFF.
if (has_capability('mod/booking:bookanyone', $context) && $bookanyone) {
    set_user_preference('bookanyone', '1');
    // Show button to turn it off again.
    $url = new moodle_url('/mod/booking/subscribeusers.php', ['id' => $id,
                                                                'optionid' => $optionid,
                                                                'agree' => $agree,
                                                            ]);
    echo '<a class="btn btn-sm btn-light" href="' . $url . '">' . get_string('bookanyoneswitchoff', 'mod_booking') . '</a>';
    echo '<div class="alert alert-warning p-1 mt-1 text-center">' . get_string('bookanyonewarning', 'mod_booking')  . '</div>';
} else if (has_capability('mod/booking:bookanyone', $context)) {
    set_user_preference('bookanyone', '0');
    // Show button to turn it off again.
    $url = new moodle_url(
        '/mod/booking/subscribeusers.php',
        [
            'id' => $id,
            'optionid' => $optionid,
            'agree' => $agree,
            'bookanyone' => true,
        ]
    );
    echo '<a class="btn btn-sm btn-light" href="' . $url . '">' . get_string('bookanyoneswitchon', 'mod_booking') . '</a>';
}


// Call the template renderer to display all other users.
$data = new booked_users(
    'option',
    $optionid,
    false, // We already see the booked users.
    true,
    true,
    true,
    true,
    false,
    false,
    true
);
/** @var renderer $renderer */
$renderer = $PAGE->get_renderer('mod_booking');
echo $renderer->render_booked_users($data);


echo html_writer::tag(
    'div',
    html_writer::link(
        new moodle_url(
            '/mod/booking/report.php',
            ['id' => $cm->id, 'optionid' => $optionid]
        ),
        get_string('backtoresponses', 'booking')
    ),
    ['style' => 'width:100%; font-weight: bold; text-align: right;']
);

if ($subscribesuccess || $unsubscribesuccess) {
    if ($subscribesuccess) {
        echo $OUTPUT->container(get_string('allchangessaved', 'booking'), 'important', 'notice');
    }
    if (
        $unsubscribesuccess && (
            has_capability('mod/booking:deleteresponses', $context) ||
            (booking_check_if_teacher($bookingoption->option))
        )
    ) {
        echo $OUTPUT->container(get_string('allchangessaved', 'booking'), 'important', 'notice');
    }
}

if (
    booking_check_if_teacher($bookingoption->option)
    && !has_capability(
        'mod/booking:readallinstitutionusers',
        $context
    )
) {
    echo html_writer::tag(
        'div',
        get_string('onlyusersfrominstitution', 'mod_booking', $bookingoption->option->institution),
        ['class' => 'alert alert-info']
    );
}

echo $bookingoutput->subscriber_selection_form($existingselector, $subscriberselector, $course->id);
echo '<br>';

// We separated this part of the form handling from the above part, because of the redirect function.
if (!$fromform = $mform->get_data()) {
    // This branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed...
    // ... or on the first display of the form.

    if (has_capability('mod/booking:updatebooking', $context)) {
        echo html_writer::tag('h5', get_string('syncmanagementheader', 'mod_booking'), ['class' => 'mt-4']);

        $addbuttonattributes = [
            'class' => 'btn btn-primary btn-sm mb-2',
            'id' => 'booking-sync-add-rule-btn',
        ];
        echo html_writer::tag('button', get_string('syncaddrule', 'mod_booking'), $addbuttonattributes);

        $disableurl = new moodle_url('/mod/booking/subscribeusers.php', [
            'id' => $id,
            'optionid' => $optionid,
            'agree' => $agree,
            'syncdisableall' => 1,
            'sesskey' => sesskey(),
        ]);
        echo ' ' . html_writer::link($disableurl, get_string('syncdisableallrules', 'mod_booking'), [
            'class' => 'btn btn-warning btn-sm mb-2',
        ]);

        $syncrules = \mod_booking\local\sync\booking_enrolment::get_rules_for_option($optionid);
        if (empty($syncrules)) {
            echo html_writer::tag('p', get_string('syncmanagementempty', 'mod_booking'), ['class' => 'text-muted']);
        } else {
            $table = new html_table();
            $table->head = [
                get_string('syncrulesource', 'mod_booking'),
                get_string('syncenrolaction', 'mod_booking'),
                get_string('syncunenrolaction', 'mod_booking'),
                get_string('syncconditionpolicy', 'mod_booking'),
                get_string('syncruleactive', 'mod_booking'),
                get_string('actions'),
            ];
            $table->data = [];
            foreach ($syncrules as $rule) {
                $sourcecell = $rule->sourcetypelabel . ': ' . s($rule->sourcename);
                $enrolcell  = $rule->syncenrol ? '&#10003;' : '&mdash;';
                $unenrolcell = $rule->syncunenrol ? '&#10003;' : '&mdash;';
                $policycell = $rule->conditionpolicy
                    ? get_string('syncconditionpolicy_override', 'mod_booking')
                    : get_string('syncconditionpolicy_respect', 'mod_booking');
                if ($rule->isenabled) {
                    $toggleurl = new moodle_url('/mod/booking/subscribeusers.php', [
                        'id' => $id,
                        'optionid' => $optionid,
                        'agree' => $agree,
                        'synctoggle' => $rule->id,
                        'synctoggleval' => 0,
                        'sesskey' => sesskey(),
                    ]);
                    $activecell = html_writer::tag('span', get_string('yes'), ['class' => 'badge badge-success'])
                        . ' ' . html_writer::link($toggleurl, '(' . get_string('disable') . ')', ['class' => 'small']);
                } else {
                    $activecell = html_writer::tag('span', get_string('no'), ['class' => 'badge badge-secondary'])
                        . ' ' . html_writer::tag(
                            'button',
                            '(' . get_string('enable') . ')',
                            [
                                'type' => 'button',
                                'class' => 'btn btn-link btn-sm p-0 align-baseline booking-sync-rule-action',
                                'data-action' => 'activate',
                                'data-ruleid' => (int)$rule->id,
                            ]
                        );
                }

                $editbutton = html_writer::tag(
                    'button',
                    get_string('edit'),
                    [
                        'type' => 'button',
                        'class' => 'btn btn-sm btn-outline-primary me-1 booking-sync-rule-action',
                        'data-action' => 'edit',
                        'data-ruleid' => (int)$rule->id,
                    ]
                );
                $deletebutton = html_writer::tag(
                    'button',
                    get_string('delete'),
                    [
                        'type' => 'button',
                        'class' => 'btn btn-sm btn-outline-danger booking-sync-rule-action',
                        'data-action' => 'delete',
                        'data-ruleid' => (int)$rule->id,
                    ]
                );
                $actioncell = $editbutton . ' ' . $deletebutton;

                $table->data[] = [$sourcecell, $enrolcell, $unenrolcell, $policycell, $activecell, $actioncell];
            }
            echo html_writer::table($table);
        }

        $PAGE->requires->js_call_amd('mod_booking/sync_rule_modal', 'init', [
            '#booking-sync-add-rule-btn',
            '.booking-sync-rule-action',
            (int)$cm->id,
            (int)$optionid,
            get_string('syncaddrule', 'mod_booking'),
            get_string('synceditrule', 'mod_booking'),
            get_string('syncdeleterule', 'mod_booking'),
            get_string('syncactivaterule', 'mod_booking'),
        ]);

        $diagnosticsbutton = html_writer::tag(
            'button',
            get_string('syncdiagnosticsheader', 'mod_booking'),
            [
                'id' => 'booking-sync-diagnostics-trigger',
                'type' => 'button',
                'class' => 'btn btn-link px-0 mt-2',
                'data-toggle' => 'collapse',
                'data-target' => '#booking-sync-diagnostics',
                'data-bs-toggle' => 'collapse',
                'data-bs-target' => '#booking-sync-diagnostics',
                'aria-expanded' => 'false',
                'aria-controls' => 'booking-sync-diagnostics',
            ]
        );
        echo html_writer::tag('div', $diagnosticsbutton);

        $diagnosticscontent = html_writer::tag(
            'p',
            get_string('loading', 'moodle'),
            ['class' => 'text-muted mb-0', 'id' => 'booking-sync-diagnostics-content']
        );

        echo html_writer::tag('div', $diagnosticscontent, [
            'class' => 'collapse',
            'id' => 'booking-sync-diagnostics',
        ]);

        $PAGE->requires->js_call_amd('mod_booking/sync_diagnostics', 'init', [
            '#booking-sync-diagnostics-trigger',
            '#booking-sync-diagnostics-content',
            (int)$cm->id,
            (int)$optionid,
            30,
            get_string('loading', 'moodle'),
            get_string('error', 'moodle'),
        ]);
    }

    $mform->display();
}

echo $OUTPUT->footer();
