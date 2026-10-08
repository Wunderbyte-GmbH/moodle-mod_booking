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

use Closure;
use context_system;
use mod_booking\event\bookingoption_updated;
use mod_booking\form\option_form;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use moodleform;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');

/**
 * Saving the option form without touching anything must not trigger bookingoption_updated.
 *
 * Every data set creates an option with one property (or one family of properties) set, loads the
 * option form exactly like core_form\external\dynamic_form::execute() does, re-submits the values the
 * form exported without changing any of them and records the bookingoption_updated events. A failing
 * data set names the field classes that reported a phantom change together with old and new value.
 *
 * The evasys data sets cover the fields of the bookingextension_evasys subplugin. Its SOAP client is
 * mocked automatically under PHPUnit; the data sets are skipped when the subplugin is not installed.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking_option::update
 * @covers \mod_booking\booking_utils::react_on_changes
 * @covers \mod_booking\option\field_base::check_for_changes
 * @covers \mod_booking\dates::save_optiondates_from_form
 */
final class option_form_noop_save_test extends booking_advanced_testcase {
    /** @var stdClass course the booking instance lives in */
    private stdClass $course;

    /** @var stdClass booking instance (course module record) */
    private stdClass $booking;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $manager = $this->getDataGenerator()->create_user();
        $this->booking = $this->getDataGenerator()->create_module('booking', [
            'course' => $this->course->id,
            'bookingmanager' => $manager->username,
            'sendmail' => 0,
        ]);
    }

    /**
     * One data set per property that the option form can hold.
     *
     * @return array<string, array{0: string}>
     */
    public static function property_provider(): array {
        $cases = [
            'baseline (text only)',
            'description',
            'notificationtext',
            'annotation',
            'beforebookedtext',
            'beforecompletedtext',
            'aftercompletedtext',
            'titleprefix',
            'identifier',
            'location',
            'institution',
            'address',
            'pollurl',
            'pollurlteachers',
            'maxanswers',
            'minanswers',
            'maxoverbooking',
            'howmanyusers',
            'credits',
            'duration',
            'removeafterminutes',
            'invisible',
            'disablebookingusers',
            'disablecancel',
            'waitforconfirmation',
            'addtocalendar',
            'addtogroup',
            'enrolmentstatus',
            'courseid',
            'canceluntil',
            'bookingopeningtime',
            'bookingclosingtime',
            'one date',
            'two dates',
            'one date created with seconds',
            'teachers',
            'responsiblecontact',
            'price',
            'option entity',
            'option entity and one date',
            'date entity',
            'customfield text',
            'customfield select',
            'customfield checkbox',
            'customfield date',
            'availability enrolledincourse',
            'availability previouslybooked',
            'availability userprofilefield',
            'evasys survey without report recipients',
            'evasys survey with report recipient',
            'evasys survey notifying participants',
            'evasys enabled but no evaluation chosen',
        ];
        $provider = [];
        foreach ($cases as $case) {
            $provider[$case] = [$case];
        }
        return $provider;
    }

    /**
     * Re-saving the untouched option form must not report any change.
     *
     * @dataProvider property_provider
     * @param string $case
     * @return void
     */
    public function test_unchanged_form_save_does_not_trigger_bookingoption_updated(string $case): void {
        $seed = $this->seed($case);
        if ($seed === null) {
            $this->markTestSkipped("Prerequisites for '$case' are not installed.");
        }

        $optionid = $this->create_option($seed);

        // A first round trip: everything the form shows is sent back as is.
        $events = $this->save_form_unchanged($optionid);
        $this->assertSame(
            [],
            $this->describe_events($events),
            "Saving the untouched option form reported changes for '$case'."
        );

        // The form state must be stable: a second untouched save must be silent as well.
        $events = $this->save_form_unchanged($optionid);
        $this->assertSame(
            [],
            $this->describe_events($events),
            "The second untouched save of the option form reported changes for '$case'."
        );
    }

    /**
     * Returns the generator record for a data set, creating prerequisites (users, entities, price
     * categories, custom fields) on the way. Returns null when a required plugin is missing.
     *
     * @param string $case
     * @return array|null
     */
    private function seed(string $case): ?array {
        $gen = $this->getDataGenerator();
        /** @var mod_booking_generator $bookinggen */
        $bookinggen = $gen->get_plugin_generator('mod_booking');

        // Minute aligned timestamps: the date selectors of the form work with minute precision.
        $start = strtotime('+10 days 10:00');
        $end = strtotime('+10 days 12:00');
        $onedate = [
            'optiondateid_1' => 0,
            'daystonotify_1' => 0,
            'coursestarttime_1' => $start,
            'courseendtime_1' => $end,
        ];

        switch ($case) {
            case 'baseline (text only)':
                return [];
            case 'description':
                return ['description' => 'A plain description'];
            case 'notificationtext':
                return ['notificationtext' => 'Notification text'];
            case 'annotation':
                return ['annotation' => 'Internal annotation'];
            case 'beforebookedtext':
                return ['beforebookedtext' => 'Before booked'];
            case 'beforecompletedtext':
                return ['beforecompletedtext' => 'Before completed'];
            case 'aftercompletedtext':
                return ['aftercompletedtext' => 'After completed'];
            case 'titleprefix':
                return ['titleprefix' => 'PFX'];
            case 'identifier':
                return ['identifier' => 'my-identifier-1'];
            case 'location':
                return ['location' => 'Room 101'];
            case 'institution':
                return ['institution' => 'Institute'];
            case 'address':
                return ['address' => 'Main street 1'];
            case 'pollurl':
                return ['pollurl' => 'https://example.com/poll'];
            case 'pollurlteachers':
                return ['pollurlteachers' => 'https://example.com/pollteachers'];
            case 'maxanswers':
                return ['maxanswers' => 7];
            case 'minanswers':
                return ['minanswers' => 2];
            case 'maxoverbooking':
                return ['maxanswers' => 3, 'maxoverbooking' => 4];
            case 'howmanyusers':
                return ['howmanyusers' => 5];
            case 'credits':
                return ['credits' => 3];
            case 'duration':
                return ['duration' => 2 * HOURSECS];
            case 'removeafterminutes':
                return ['removeafterminutes' => 30];
            case 'invisible':
                return ['invisible' => 1];
            case 'disablebookingusers':
                return ['disablebookingusers' => 1];
            case 'disablecancel':
                return ['disablecancel' => 1];
            case 'waitforconfirmation':
                return ['waitforconfirmation' => 1];
            case 'addtocalendar':
                return ['addtocalendar' => 1];
            case 'addtogroup':
                return ['chooseorcreatecourse' => 1, 'courseid' => $this->course->id, 'addtogroup' => 1];
            case 'enrolmentstatus':
                return ['chooseorcreatecourse' => 1, 'courseid' => $this->course->id, 'enrolmentstatus' => 1];
            case 'courseid':
                return ['chooseorcreatecourse' => 1, 'courseid' => $this->course->id];
            case 'canceluntil':
                return ['canceluntilcheckbox' => 1, 'canceluntil' => $start];
            case 'bookingopeningtime':
                return ['restrictanswerperiodopening' => 1, 'bookingopeningtime' => strtotime('-1 day 09:00')];
            case 'bookingclosingtime':
                return ['restrictanswerperiodclosing' => 1, 'bookingclosingtime' => strtotime('+5 days 09:00')];
            case 'one date':
                return $onedate;
            case 'two dates':
                return $onedate + [
                    'optiondateid_2' => 0,
                    'daystonotify_2' => 0,
                    'coursestarttime_2' => strtotime('+11 days 10:00'),
                    'courseendtime_2' => strtotime('+11 days 12:00'),
                ];
            case 'one date created with seconds':
                // Imports, web services and generators store seconds; the form cannot show them.
                return [
                    'optiondateid_1' => 0,
                    'daystonotify_1' => 0,
                    'coursestarttime_1' => $start + 11,
                    'courseendtime_1' => $end + 11,
                ];
            case 'teachers':
                $teacher = $gen->create_user();
                $gen->enrol_user($teacher->id, $this->course->id, 'editingteacher');
                return ['teachersforoption' => $teacher->username];
            case 'responsiblecontact':
                $contact = $gen->create_user();
                return ['responsiblecontact' => $contact->username];
            case 'price':
                $bookinggen->create_pricecategory((object)[
                    'ordernum' => 1,
                    'name' => 'default',
                    'identifier' => 'default',
                    'defaultvalue' => 100,
                    'pricecatsortorder' => 1,
                ]);
                return ['useprice' => 1, 'default' => 10];
            case 'option entity':
                if (!class_exists('local_entities\entitiesrelation_handler')) {
                    return null;
                }
                return ['local_entities_entityid_0' => $this->create_entity()];
            case 'option entity and one date':
                if (!class_exists('local_entities\entitiesrelation_handler')) {
                    return null;
                }
                return $onedate + ['local_entities_entityid_0' => $this->create_entity()];
            case 'date entity':
                if (!class_exists('local_entities\entitiesrelation_handler')) {
                    return null;
                }
                return $onedate + [
                    'local_entities_entityid_1' => $this->create_entity(),
                    'local_entities_entityarea_1' => 'optiondate',
                ];
            case 'customfield text':
                $this->create_booking_customfield('cftext', 'text');
                return ['customfield_cftext' => 'Some value'];
            case 'customfield select':
                $this->create_booking_customfield('cfselect', 'select', ['options' => "Alpha\nBeta\nGamma"]);
                return ['customfield_cfselect' => 2];
            case 'customfield checkbox':
                $this->create_booking_customfield('cfcheckbox', 'checkbox');
                return ['customfield_cfcheckbox' => 1];
            case 'customfield date':
                $this->create_booking_customfield('cfdate', 'date', ['includetime' => 1]);
                return ['customfield_cfdate' => $start];
            case 'availability enrolledincourse':
                $other = $gen->create_course();
                return [
                    'bo_cond_enrolledincourse_restrict' => 1,
                    'bo_cond_enrolledincourse_courseids' => [$other->id],
                    'bo_cond_enrolledincourse_courseids_operator' => 'OR',
                ];
            case 'availability previouslybooked':
                $other = $this->create_option(['text' => 'The other option']);
                return [
                    'bo_cond_previouslybooked_restrict' => 1,
                    'bo_cond_previouslybooked_optionid' => $other,
                ];
            case 'availability userprofilefield':
                return [
                    'bo_cond_userprofilefield_1_default_restrict' => 1,
                    'bo_cond_userprofilefield_field' => 'email',
                    'bo_cond_userprofilefield_operator' => '~',
                    'bo_cond_userprofilefield_value' => 'example',
                ];
            case 'evasys enabled but no evaluation chosen':
                if (!$this->configure_evasys()) {
                    return null;
                }
                return $onedate;
            case 'evasys survey without report recipients':
                if (!$this->configure_evasys()) {
                    return null;
                }
                return $onedate + $this->evasys_seed();
            case 'evasys survey with report recipient':
                if (!$this->configure_evasys()) {
                    return null;
                }
                $recipient = $gen->create_user();
                $roleid = $gen->create_role(['shortname' => 'evasysreports']);
                role_assign($roleid, $recipient->id, context_system::instance()->id);
                set_config('rolereportrecipients', $roleid, 'bookingextension_evasys');
                return $onedate + $this->evasys_seed(['evasys_other_report_recipients' => [$recipient->id]]);
            case 'evasys survey notifying participants':
                if (!$this->configure_evasys()) {
                    return null;
                }
                return $onedate + $this->evasys_seed(['evasys_notifyparticipants' => 1]);
        }
        $this->fail("Unknown data set '$case'.");
    }

    /**
     * Enables the evasys booking extension so its fields are part of the option form.
     *
     * The SOAP client is replaced by the plugin's own mock under PHPUnit. Returns false when the
     * extension is not installed.
     *
     * @return bool
     */
    private function configure_evasys(): bool {
        if (!class_exists('bookingextension_evasys\option\fields\evasys')) {
            return false;
        }
        set_config('useevasys', 1, 'bookingextension_evasys');
        set_config('evasyssubunits', '1', 'bookingextension_evasys');
        set_config('evasysperiods', '7-' . base64_encode('Period 2026'), 'bookingextension_evasys');
        return true;
    }

    /**
     * The evasys values of an option, as the option form would store them (form id and period are
     * "id-base64(name)" strings, see evasys_handler::get_forms_for_query()).
     *
     * @param array $overrides
     * @return array
     */
    private function evasys_seed(array $overrides = []): array {
        return array_merge([
            'evasys_form' => '510-' . base64_encode('Course Evaluation'),
            'evasys_durationbeforestart' => -7200,
            'evasys_durationafterend' => 7200,
            'evasys_other_report_recipients' => [],
            'evasys_notifyparticipants' => 0,
            'evasysperiods' => get_config('bookingextension_evasys', 'evasysperiods'),
            'evasys_timemode' => 0,
            'evasys_surveyurl' => '',
            'evasys_booking_id' => 0,
            'evasys_delete' => 0,
            'evasys_confirmdelete' => 0,
            'evasys_qr' => 0,
            'qrurl' => '',
        ], $overrides);
    }

    /**
     * Creates an option in the booking instance of this test.
     *
     * @param array $fields
     * @return int optionid
     */
    private function create_option(array $fields): int {
        /** @var mod_booking_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_booking');
        $option = $generator->create_option((object)array_merge([
            'bookingid' => $this->booking->id,
            'text' => 'Option under test',
        ], $fields));
        singleton_service::destroy_instance();
        return (int)$option->id;
    }

    /**
     * Creates one entity and returns its id.
     *
     * @return int
     */
    private function create_entity(): int {
        return (int)$this->getDataGenerator()->get_plugin_generator('local_entities')->create_entities([
            'name' => 'Room A',
            'shortname' => 'rooma',
        ]);
    }

    /**
     * Creates a custom field for booking options.
     *
     * @param string $shortname
     * @param string $type
     * @param array $configdata
     * @return void
     */
    private function create_booking_customfield(string $shortname, string $type, array $configdata = []): void {
        $gen = $this->getDataGenerator();
        $category = $gen->create_custom_field_category([
            'name' => 'Booking fields',
            'component' => 'mod_booking',
            'area' => 'booking',
            'itemid' => 0,
            'contextid' => context_system::instance()->id,
        ]);
        $category->save();
        $field = $gen->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => 'Field ' . $shortname,
            'shortname' => $shortname,
            'type' => $type,
            'configdata' => $configdata,
        ]);
        $field->save();
    }

    /**
     * Loads the option form like the AJAX dynamic form does, submits the exported values unchanged
     * and returns the bookingoption_updated events this triggered for the option.
     *
     * @param int $optionid
     * @return bookingoption_updated[]
     */
    private function save_form_unchanged(int $optionid): array {
        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($optionid);
        $args = [
            'cmid' => (int)$settings->cmid,
            'id' => $optionid,
            'optionid' => $optionid,
            'bookingid' => (int)$settings->bookingid,
        ];

        // 1. The form is loaded: core_form\external\dynamic_form::execute() without a submit marker.
        $form = new option_form(null, null, 'post', '', [], true, $args, true);
        $form->set_data_for_dynamic_submission();
        $this->finalize_definition($form);
        $values = $this->export_values_as_browser_would($form);

        // 2. The same values are posted back - a new request with a cold singleton cache.
        singleton_service::destroy_instance();
        $submitted = option_form::mock_ajax_submit(array_merge($args, $values));
        $form = new option_form(null, null, 'post', '', [], true, $submitted, true);
        $form->set_data_for_dynamic_submission();
        $this->assertFalse($form->is_cancelled());
        $this->assertTrue($form->is_submitted());
        $this->assertTrue($form->is_validated(), 'Validation errors: ' . json_encode($this->validation_errors($form)));

        $sink = $this->redirectEvents();
        $form->process_dynamic_submission();
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof bookingoption_updated && (int)$event->objectid === $optionid
        ));
        $sink->close();
        return $events;
    }

    /**
     * Runs definition_after_data() like moodleform::display() does, so the dynamically added
     * elements (e.g. the dates) are part of the form.
     *
     * @param option_form $form
     * @return void
     */
    private function finalize_definition(option_form $form): void {
        $finalize = function () {
            if (!$this->_definition_finalized) {
                $this->_definition_finalized = true;
                $this->definition_after_data();
            }
        };
        Closure::bind($finalize, $form, moodleform::class)();
    }

    /**
     * Returns the values of all form elements the way a browser would post them.
     *
     * exportValues() delivers the default values of an unsubmitted form, but already processed: date
     * selectors and durations are exported as timestamps and seconds while the browser posts their
     * sub-elements. Unchecked plain checkboxes and buttons are not posted at all.
     *
     * @param option_form $form
     * @return array
     */
    private function export_values_as_browser_would(option_form $form): array {
        /** @var \MoodleQuickForm $mform */
        $mform = Closure::bind(fn() => $this->_form, $form, moodleform::class)();

        $nothingsubmitted = [];
        $values = [];
        foreach ($mform->_elements as $element) {
            $name = $element->getName();
            $type = $element->getType();
            if ($name === null || $name === '' || $element->isFrozen()) {
                // Frozen elements render no inputs, so nothing is posted for them.
                continue;
            }
            if (in_array($type, ['date_time_selector', 'date_selector', 'duration'], true)) {
                $values[$name] = $this->group_values_as_browser_would($element);
                continue;
            }
            $exported = $element->exportValue($nothingsubmitted, true);
            if (!is_array($exported)) {
                continue;
            }
            $value = $exported[$name] ?? null;
            if ($type === 'autocomplete' && !$element->getMultiple() && is_array($value)) {
                // A single autocomplete posts one scalar, not a list.
                $exported[$name] = $value === [] ? '' : reset($value);
            } else if ($type === 'hidden' && $value === null) {
                // A hidden input without a value is posted as an empty string.
                $exported[$name] = '';
            } else if ($type === 'checkbox' && empty($value)) {
                // An unchecked checkbox is not posted at all.
                continue;
            }
            $values = \HTML_QuickForm::arrayMerge($values, $exported);
        }
        unset($values['sesskey']);
        return array_filter($values, fn($value) => $value !== null);
    }

    /**
     * Returns the sub-element values of a date selector or duration group as a browser posts them.
     *
     * A select whose current value is not one of its options (e.g. an odd minute with a 5 minute step)
     * is rendered without a selected option, so the browser posts its first option. The enabled
     * checkbox of an optional selector is only posted when checked; static parts are never posted.
     *
     * @param \HTML_QuickForm_group $group
     * @return array
     */
    private function group_values_as_browser_would(\HTML_QuickForm_group $group): array {
        $posted = [];
        foreach ($group->getElements() as $sub) {
            $subname = $sub->getName();
            switch ($sub->getType()) {
                case 'select':
                    $current = (array)$sub->getValue();
                    $current = $current === [] ? null : (string)reset($current);
                    $allowed = array_map(fn($option) => (string)$option['attr']['value'], $sub->_options);
                    if ($current === null || !in_array($current, $allowed, true)) {
                        $current = $allowed[0] ?? '';
                    }
                    $posted[$subname] = $current;
                    break;
                case 'checkbox':
                    if ($sub->getChecked()) {
                        $posted[$subname] = 1;
                    }
                    break;
                case 'text':
                    $posted[$subname] = $sub->getValue();
                    break;
                default:
                    // Static elements (e.g. the calendar icon) are not posted.
                    break;
            }
        }
        return $posted;
    }

    /**
     * Returns the validation errors of a form.
     *
     * @param option_form $form
     * @return array
     */
    private function validation_errors(option_form $form): array {
        $mform = Closure::bind(fn() => $this->_form, $form, moodleform::class)();
        return Closure::bind(fn() => $this->_errors, $mform, \HTML_QuickForm::class)();
    }

    /**
     * Turns the events into a readable list of the changes they carry, for the assertion message.
     *
     * @param bookingoption_updated[] $events
     * @return array
     */
    private function describe_events(array $events): array {
        $described = [];
        foreach ($events as $event) {
            foreach ($event->other['changes'] ?? [] as $change) {
                $change = (array)$change;
                $described[] = [
                    'fieldname' => $change['fieldname'] ?? '',
                    'formkey' => $change['formkey'] ?? '',
                    'oldvalue' => json_encode($change['oldvalue'] ?? null),
                    'newvalue' => json_encode($change['newvalue'] ?? null),
                ];
            }
        }
        return $described;
    }
}
