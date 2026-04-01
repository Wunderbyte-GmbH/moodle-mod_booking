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
 * Tests for certificates when bookingoption is completed.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @author 2025 David Ala
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use coding_exception;
use mod_booking\table\manageusers_table;
use mod_booking_generator;
use mod_booking\bo_availability\bo_info;
use mod_booking\event\certificate_issued;
use stdClass;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/booking/lib.php');
require_once($CFG->dirroot . '/mod/booking/classes/price.php');

/**
 * Class handling tests certificate conditions.
 *
 * @package mod_booking
 * @category test
 * @copyright 2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 */
final class certificate_conditions_test extends advanced_testcase {
    /** @var array Associative array of created user objects keyed by user name (e.g. student1, student2, bookingmanager, teacher). */
    private array $users = [];

    /** @var array Array of created booking option objects, indexed in the same order as provide_standard_data()['options']. */
    private array $bookingoptions = [];

    /** @var object|null The course created by base_scenario(). */
    private ?object $course = null;

    /** @var object|null The booking module created by base_scenario(). */
    private ?object $booking = null;

    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->preventResetByRollback();
        $this->resetAfterTest(true);
    }

    /**
     * Mandatory clean-up after each test.
     */
    public function tearDown(): void {
        parent::tearDown();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Test the bookingoption condition where every option is an item and requiredcount = 2.
     *
     * @covers \mod_booking\local\certificate_conditions\conditions\bookingoptions
     * @covers \mod_booking\local\certificateclass::issue_certificate
     * @covers \mod_booking\local\certificateclass::all_required_options_fulfilled
     * @covers \mod_booking\local\certificate_conditions\actions\createcertificate
     *     */
    public function test_condition_booking_options(): void {
        global $DB;
        $this->base_scenario();
        // Create a Certificate Template.
        $certificate = $this->get_certificate_generator()->create_template((object)['name' => 'Certificate 1']);

        // Create Condition: bookingoption and insert it into DB.
        $data = $this->set_bookingoption_condition($certificate);
        $conditionid = $DB->insert_record('booking_cert_cond', $data);
         // Create items for conditions.
        $this->set_condition_items($conditionid);

        $this->setUser($this->users['student1']);
        // User 1 Books option 1.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[0]->id);
        // We book user1.
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student1']->id);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student1']->id);

        $this->setAdminUser();
        $optionaobj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        $optionaobj->toggle_user_completion($this->users['student1']->id);
        singleton_service::destroy_booking_answers($optionaobj->id);

        // We completed one option but have to complete both in order to get a certificate.
        $this->assertEquals(0, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student1']->id]));

        $settings2 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[1]->id);
        $result = booking_bookit::bookit('option', $settings2->id, $this->users['student1']->id);
        $result = booking_bookit::bookit('option', $settings2->id, $this->users['student1']->id);
        $this->setAdminUser();

        $optionbobj = singleton_service::get_instance_of_booking_option($settings2->cmid, $settings2->id);
        $optionbobj->toggle_user_completion($this->users['student1']->id);
        singleton_service::destroy_booking_answers($optionbobj->id);
        // Now student1 should have one certificate.
        $this->assertEquals(1, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student1']->id]));

        $this->setUser($this->users['student2']);
        $this->setAdminUser();
        $settings1 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[0]->id);
        // We book user2.
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student2']->id);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student2']->id);

        $this->setAdminUser();
        $optionaobj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        $optionaobj->toggle_user_completion($this->users['student2']->id);
        singleton_service::destroy_booking_answers($optionaobj->id);

        // We completed one option but have to complete both in order to get a certificate.
        $this->assertEquals(0, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student2']->id]));

        $settings2 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[1]->id);
        $result = booking_bookit::bookit('option', $settings2->id, $this->users['student2']->id);
        $result = booking_bookit::bookit('option', $settings2->id, $this->users['student2']->id);
        $this->setAdminUser();

        $optionbobj = singleton_service::get_instance_of_booking_option($settings2->cmid, $settings2->id);
        $optionbobj->toggle_user_completion($this->users['student2']->id);
        singleton_service::destroy_booking_answers($optionbobj->id);

        $this->assertEquals(1, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student2']->id]));

        // We should have now two certificate issues in total because both student1 and student2 completed the condition.
        $this->assertEquals(2, $DB->count_records('tool_certificate_issues'));

        self::teardown();
    }

    /**
     * Test booking option condition with user profile filter.
     *
     * @covers \mod_booking\local\certificate_conditions\filters\userprofilefield
     * @covers \mod_booking\local\certificate_conditions\conditions\bookingoptions
     * @covers \mod_booking\local\certificateclass::issue_certificate
     * @covers \mod_booking\local\certificateclass::all_required_options_fulfilled
     * @covers \mod_booking\local\certificate_conditions\actions\createcertificate
     */
    public function test_condition_booking_options_with_filter(): void {
        global $DB;
        $this->base_scenario();
        // Create a Certificate Template.
        $certificate = $this->get_certificate_generator()->create_template((object)['name' => 'Certificate 1']);
        // Create Condition: bookingoption with filter and insert it into DB.
        $filter = ['filtername' => 'userprofilefield', 'field' => 'externalid', 'value' => '1234'];
        $data = $this->set_bookingoption_condition($certificate, $filter);
        $conditionid = $DB->insert_record('booking_cert_cond', $data);
        $this->set_condition_items($conditionid);

        // Only user 2 has a profile field externalid with value 1234, so only completion of user2 should lead to a certificate.
         $this->setUser($this->users['student1']);
        // User 1 Books option 1.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[0]->id);
        // We book user1.
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student1']->id);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student1']->id);

        $this->setAdminUser();
        $optionaobj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        $optionaobj->toggle_user_completion($this->users['student1']->id);
        singleton_service::destroy_booking_answers($optionaobj->id);

        // After completing one option, no certificate should be issued yet.
        $this->assertEquals(0, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student1']->id]));

        $settings2 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[1]->id);
        $result = booking_bookit::bookit('option', $settings2->id, $this->users['student1']->id);
        $result = booking_bookit::bookit('option', $settings2->id, $this->users['student1']->id);
        $this->setAdminUser();

        $optionbobj = singleton_service::get_instance_of_booking_option($settings2->cmid, $settings2->id);
        $optionbobj->toggle_user_completion($this->users['student1']->id);
        singleton_service::destroy_booking_answers($optionbobj->id);
        // Now Student1 should not have a certificate because of the filter.
        $this->assertEquals(0, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student1']->id]));

        // Student 2 does the same and should get a certificate because of the filter.
        $this->setUser($this->users['student2']);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student2']->id);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student2']->id);

        $this->setAdminUser();
        $optionaobj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        $optionaobj->toggle_user_completion($this->users['student2']->id);
        singleton_service::destroy_booking_answers($optionaobj->id);

        // After completing one option, no certificate should be issued yet.
        $this->assertEquals(0, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student2']->id]));

        $settings2 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[1]->id);
        $result = booking_bookit::bookit('option', $settings2->id, $this->users['student2']->id);
        $result = booking_bookit::bookit('option', $settings2->id, $this->users['student2']->id);
        $this->setAdminUser();

        $optionbobj = singleton_service::get_instance_of_booking_option($settings2->cmid, $settings2->id);
        $optionbobj->toggle_user_completion($this->users['student2']->id);
        singleton_service::destroy_booking_answers($optionbobj->id);
        // Now Student2 should have a certificate because of the filter.
        $this->assertEquals(1, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student2']->id]));
    }

    /**
     * Test tagged options condition without filter.
     *
     * @covers \mod_booking\local\certificate_conditions\conditions\taggedoptions
     * @covers \mod_booking\local\certificateclass::issue_certificate
     * @covers \mod_booking\local\certificateclass::all_required_options_fulfilled
     * @covers \mod_booking\local\certificate_conditions\actions\createcertificate
     */
    public function test_condition_taggedoptions(): void {
        global $DB;
        $this->base_scenario();
        // Create a Certificate Template.
        $certificate = $this->get_certificate_generator()->create_template((object)['name' => 'Certificate 1']);
        // Create Condition: bookingoption with filter and insert it into DB.
        $data = $this->set_taggedoptions_condition($certificate);
        $conditionid = $DB->insert_record('booking_cert_cond', $data);
        $this->set_condition_items($conditionid);

        $this->setUser($this->users['student1']);
        // User 1 Books option 1.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[0]->id);
        // We book user1.
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student1']->id);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student1']->id);

        $this->setAdminUser();
        $optionaobj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        $optionaobj->toggle_user_completion($this->users['student1']->id);
        singleton_service::destroy_booking_answers($optionaobj->id);

        // We complete the option and should have a certificate.
        $this->assertEquals(1, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student1']->id]));
    }

    /**
     * Test tagged options condition with user profile filter.
     *
     * @covers \mod_booking\local\certificate_conditions\filters\userprofilefield
     * @covers \mod_booking\local\certificate_conditions\conditions\taggedoptions
     * @covers \mod_booking\local\certificateclass::issue_certificate
     * @covers \mod_booking\local\certificateclass::all_required_options_fulfilled
     * @covers \mod_booking\local\certificate_conditions\actions\createcertificate
     */
    public function test_condition_taggedoptions_with_filter(): void {
        global $DB;
        $this->base_scenario();
        // Create a Certificate Template.
        $certificate = $this->get_certificate_generator()->create_template((object)['name' => 'Certificate 1']);
        // Create Condition: bookingoption with filter and insert it into DB.
        $filter = ['filtername' => 'userprofilefield', 'field' => 'externalid', 'value' => '1234'];
        $data = $this->set_taggedoptions_condition($certificate, $filter);
        $conditionid = $DB->insert_record('booking_cert_cond', $data);
        $this->set_condition_items($conditionid);

        $this->setUser($this->users['student1']);
        // User 1 Books option 1.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[0]->id);
        // We book user1.
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student1']->id);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student1']->id);

        $this->setAdminUser();
        $optionaobj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        $optionaobj->toggle_user_completion($this->users['student1']->id);
        singleton_service::destroy_booking_answers($optionaobj->id);

        // Student1 does not match the filter, so no certificate should be issued.
        $this->assertEquals(0, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student1']->id]));

         // Student 2 does the same and should get a certificate because of the filter.
        $this->setUser($this->users['student2']);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student2']->id);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student2']->id);

        $this->setAdminUser();
        $optionaobj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        $optionaobj->toggle_user_completion($this->users['student2']->id);
        singleton_service::destroy_booking_answers($optionaobj->id);

        // Student2 matches the filter, so one certificate should be issued.
        $this->assertEquals(1, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student2']->id]));
    }

    /**
     * Test that multiple certificate conditions can be fulfilled together.
     *
     * @covers \mod_booking\local\certificate_conditions\conditions\bookingoption
     * @covers \mod_booking\local\certificate_conditions\conditions\taggedoptions
     * @covers \mod_booking\local\certificateclass::issue_certificate
     * @covers \mod_booking\local\certificateclass::all_required_options_fulfilled
     * @covers \mod_booking\local\certificate_conditions\actions\createcertificate
     */
    public function test_multiple_conditions(): void {
        global $DB;
        $this->base_scenario();
        // Create a Certificate Templates.
        $certificate1 = $this->get_certificate_generator()->create_template((object)['name' => 'Certificate 1']);
        $certificate2 = $this->get_certificate_generator()->create_template((object)['name' => 'Certificate 2']);

        // Create Conditions: taggedoption with filter and bookingoptioncondition without filter.
        $filter = ['filtername' => 'userprofilefield', 'field' => 'externalid', 'value' => '1234'];
        $data = $this->set_taggedoptions_condition($certificate1, $filter);
        $conditionid = $DB->insert_record('booking_cert_cond', $data);
        $this->set_condition_items($conditionid);

        $data = $this->set_bookingoption_condition($certificate2);
        $conditionid = $DB->insert_record('booking_cert_cond', $data);
        $this->set_condition_items($conditionid);

        $this->setUser($this->users['student1']);
        // User1 completes option 1 and should have no certificates.
        // He does not get certificate1 because of the filter, and certificate2 needs both completed.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[0]->id);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student1']->id);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student1']->id);

        $this->setAdminUser();
        $optionaobj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        $optionaobj->toggle_user_completion($this->users['student1']->id);
        singleton_service::destroy_booking_answers($optionaobj->id);
        $this->assertEquals(0, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student1']->id]));

        // User 1 completes option 2 and should get certificate2.
        $settings2 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[1]->id);
        $result = booking_bookit::bookit('option', $settings2->id, $this->users['student1']->id);
        $result = booking_bookit::bookit('option', $settings2->id, $this->users['student1']->id);
        $this->setAdminUser();

        $optionbobj = singleton_service::get_instance_of_booking_option($settings2->cmid, $settings2->id);
        $optionbobj->toggle_user_completion($this->users['student1']->id);
        singleton_service::destroy_booking_answers($optionbobj->id);
        // Now student1 should have one certificate.
        $this->assertEquals(1, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student1']->id]));

        // We also look if it is the correct template.
        $certificate = $DB->get_record('tool_certificate_issues', ['userid' => $this->users['student1']->id]);
        $this->assertEquals($certificate2->get_id(), $certificate->templateid);
        // User 2 completes both booking options and should have 3 certificates.
        // Two from tagged options and one from booking option (2x certificate1, 1x certificate2).
        $this->setUser($this->users['student2']);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student2']->id);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student2']->id);

        $this->setAdminUser();
        $optionaobj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        $optionaobj->toggle_user_completion($this->users['student2']->id);
        singleton_service::destroy_booking_answers($optionaobj->id);

        // We should have 1 Certificate1.
        $this->assertEquals(1, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student2']->id]));
        $certificate = $DB->get_record('tool_certificate_issues', ['userid' => $this->users['student1']->id]);
        $this->assertEquals($certificate2->get_id(), $certificate->templateid);

        $settings2 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[1]->id);
        $result = booking_bookit::bookit('option', $settings2->id, $this->users['student2']->id);
        $result = booking_bookit::bookit('option', $settings2->id, $this->users['student2']->id);
        $this->setAdminUser();

        $optionbobj = singleton_service::get_instance_of_booking_option($settings2->cmid, $settings2->id);
        $optionbobj->toggle_user_completion($this->users['student2']->id);
        singleton_service::destroy_booking_answers($optionbobj->id);
        // Student2 should now have three certificates in total across both conditions.
        $this->assertEquals(3, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student2']->id]));

        // We now look if 2 are certificate1.
        $certificates = $DB->get_records('tool_certificate_issues', [
            'userid' => $this->users['student2']->id,
            'templateid' => $certificate1->get_id(),
        ]);
        $this->assertEquals(2, count($certificates));
    }

    /**
     * Test single-certificate mode when multiple issuance is disabled.
     *
     * @covers \mod_booking\local\certificate_conditions\conditions\taggedoptions
     * @covers \mod_booking\local\certificateclass::issue_certificate
     * @covers \mod_booking\local\certificateclass::all_required_options_fulfilled
     * @covers \mod_booking\local\certificate_conditions\actions\createcertificate
     */
    public function test_issue_multiple_certificates_off(): void {
        global $DB;
        $this->base_scenario();
        set_config('issuemultiplecertificates', 0, 'booking');
        // Create a Certificate Template.
        $certificate = $this->get_certificate_generator()->create_template((object)['name' => 'Certificate 1']);
        // Create Condition: bookingoption with filter and insert it into DB.
        $data = $this->set_taggedoptions_condition($certificate);
        $conditionid = $DB->insert_record('booking_cert_cond', $data);
        $this->set_condition_items($conditionid);

        $this->setUser($this->users['student1']);
        // User 1 Books option 1.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[0]->id);
        // We book user1.
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student1']->id);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student1']->id);

        $this->setAdminUser();
        $optionaobj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        $optionaobj->toggle_user_completion($this->users['student1']->id);
        singleton_service::destroy_booking_answers($optionaobj->id);

        // We complete the option and should have a certificate.
        $this->assertEquals(1, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student1']->id]));

        // We complete option 2 and should not get another certificate because issue multiple certificates is turned off.
        $this->setUser($this->users['student1']);
        // User 1 Books option 2.
        $settings2 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[1]->id);
        // We book user1.
        $result = booking_bookit::bookit('option', $settings2->id, $this->users['student1']->id);
        $result = booking_bookit::bookit('option', $settings2->id, $this->users['student1']->id);

        $this->setAdminUser();
        $optionaobj = singleton_service::get_instance_of_booking_option($settings2->cmid, $settings2->id);
        $optionaobj->toggle_user_completion($this->users['student1']->id);
        singleton_service::destroy_booking_answers($optionaobj->id);

        // We complete the option and should have no additional certificate.
        $this->assertEquals(1, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student1']->id]));
    }
    /**
     * Test with setting to manual certificate trigger.
     *
     * @covers \mod_booking\local\certificate_conditions\conditions\taggedoptions
     * @covers \mod_booking\local\certificateclass::issue_certificate
     * @covers \mod_booking\local\certificateclass::all_required_options_fulfilled
     * @covers \mod_booking\local\certificate_conditions\actions\createcertificate
     */
    public function test_only_manual_certificate_trigger(): void {
        global $DB;
        $this->base_scenario();
        // We turn off automatic triggering of certificates to test the manual trigger.
        set_config('certificatemanualtrigger', 1, 'booking');
        // Create a Certificate Template.
        $certificate = $this->get_certificate_generator()->create_template((object)['name' => 'Certificate 1']);
        // Create Condition: bookingoption with filter and insert it into DB.
        $data = $this->set_taggedoptions_condition($certificate);
        $conditionid = $DB->insert_record('booking_cert_cond', $data);
        $this->set_condition_items($conditionid);

        $this->setUser($this->users['student1']);
        // User 1 Books option 1.
        $settings1 = singleton_service::get_instance_of_booking_option_settings($this->bookingoptions[0]->id);
        // We book user1.
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student1']->id);
        $result = booking_bookit::bookit('option', $settings1->id, $this->users['student1']->id);

        $this->setAdminUser();
        $optionaobj = singleton_service::get_instance_of_booking_option($settings1->cmid, $settings1->id);
        $optionaobj->toggle_user_completion($this->users['student1']->id);
        singleton_service::destroy_booking_answers($optionaobj->id);

        // We complete the option and should have no certificate, because it is manual trigger only.
        $this->assertEquals(0, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student1']->id]));

        // Now we manually trigger it.
        $answer = $DB->get_record('booking_answers', ['userid' => $this->users['student1']->id]);
        $table = new manageusers_table('manageuserstable');
        $actiondata = '{"type":"wb_action_button","id":"-1","methodname":"trigger_certificate_booking_answers",' .
        '"formname":"","nomodal":"0","selectionmandatory":"1","titlestring":"issuecertificate",'
        . '"bodystring":"issuecertificatebody","submitbuttonstring":"apply",'
        . '"component":"mod_booking","initialized":"true","checkedids":["' . $answer->id . '"]}';
        $sink = $this->redirectEvents();
        $table->action_trigger_certificate_booking_answers(0, $actiondata);
        $events = $sink->get_events();
        // We check if no bookingoption_completed event was triggered because of the manual trigger.
        foreach ($events as $event) {
            $name = $event->get_name();
            $this->assertNotEquals($name, get_string('bookingoptioncompleted', 'mod_booking'));
        }
        // Now we should have a certificate.
        $this->assertEquals(1, $DB->count_records('tool_certificate_issues', ['userid' => $this->users['student1']->id]));
    }

    /**
     * Set up base certificate configuration and create all standard entities from provide_standard_data().
     * Results are stored on $this->users, $this->bookingoptions, $this->course, and $this->booking.
     *
     *
     * @return void
     */
    private function base_scenario(): void {
        $standarddata = self::provide_standard_data();
        $this->setAdminUser();

        // Turn on certificates and select certificateconditions.
        set_config('certificateon', 1, 'booking');
        set_config('certificateoptions', 1, 'booking');
        set_config('issuemultiplecertificates', 1, 'booking');

        // Create profile field used by certificate filter tests.
        $this->getDataGenerator()->create_custom_profile_field([
            'datatype' => 'text',
            'shortname' => 'externalid',
            'name' => 'External ID',
        ]);

        // Create users and store them as a class property.
        $this->users = [];
        foreach ($standarddata['users'] as $user) {
            $params = $user['params'] ?? [];
            $this->users[$user['name']] = $this->getDataGenerator()->create_user($params);
        }

        // Create course and enrol all users.
        $this->course = $this->getDataGenerator()->create_course([
            'fullname' => 'Test Course',
            'shortname' => 'TC102',
            'category' => 1,
            'enablecompletion' => 1,
        ]);
        foreach ($this->users as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $this->course->id);
        }

        // Create booking module.
        $bdata = $standarddata['booking'];
        $bdata['course']         = $this->course->id;
        $bdata['bookingmanager'] = $this->users['bookingmanager']->username;
        $this->booking = $this->getDataGenerator()->create_module('booking', $bdata);

        // Create booking options and store them as a class property.
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $this->bookingoptions = [];
        $option1 = $standarddata['option'];
        $option1['bookingid'] = $this->booking->id;
        $option1['courseid'] = $this->course->id;
        $option1['text'] = 'Test option1';
        $this->bookingoptions[] = $plugingenerator->create_option((object)$option1);

        $option2 = $standarddata['option'];
        $option2['bookingid'] = $this->booking->id;
        $option2['courseid'] = $this->course->id;
        $option2['text'] = 'Test option2';

        $this->bookingoptions[] = $plugingenerator->create_option((object)$option2);
    }


    /**
     * Provides the data that's constant for the test.
     *
     * @return array
     *
     */
    private static function provide_standard_data(): array {
        return [
        'booking' => [
        'name' => 'Test',
        'eventtype' => 'Test event',
        'enablecompletion' => 1,
        'bookedtext' => ['text' => 'text'],
        'waitingtext' => ['text' => 'text'],
        'notifyemail' => ['text' => 'text'],
        'statuschangetext' => ['text' => 'text'],
        'deletedtext' => ['text' => 'text'],
        'pollurltext' => ['text' => 'text'],
        'pollurlteacherstext' => ['text' => 'text'],
        'notificationtext' => ['text' => 'text'],
        'userleave' => ['text' => 'text'],
        'tags' => '',
        'completion' => 2,
        'showviews' => ['mybooking,myoptions,showall,showactive,myinstitution'],
        ],
        'option' => [
        'coursestarttime_0' => strtotime('now + 1 day'),
        'courseendtime_0' => strtotime('now + 2 day'),
        'importing' => 1,
        'useprice' => 0,
        ],
        'users' => [ // Number of entries corresponds to number of users.
        [
            'name' => 'student1',
            'params' => [],
        ],
        [
            'name' => 'student2',
            'params' => [
                'profile_field_externalid' => '1234',
            ],
        ],
        [
            'name' => 'bookingmanager', // Bookingmanager always needs to be set.
            'params' => [],
        ],
        [
            'name' => 'teacher',
            'params' => [],
        ],
        ],
        ];
    }

    /**
     * Generator for Certificates.
     *
     * @return \component_generator_base
     *
     */
    protected function get_certificate_generator() {
        return $this->getDataGenerator()->get_plugin_generator('tool_certificate');
    }

    /**
     * Set the bookingoption condition.
     *
     * @param object $certificate
     * @param array $filterjson
     *
     * @return stdClass
     *
     */
    private function set_bookingoption_condition(object $certificate, array $filterjson = []) {
        $data = new stdClass();
        $data->name = "Bookingoption_condition_test";
        $data->filterjson = json_encode($filterjson);
        $data->logicjson = json_encode(['conditionname' => 'bookingoption', 'requiredcount' => 2]);
        $data->actionjson = json_encode([
            'actionname' => 'createcertificate',
            'certid' => $certificate->get_id(),
            'expirydatetype' => 0,
            'expirydateabsolute' => 1773129540,
            'expirydaterelative' => 0,
        ]);
        $data->isactive = 1;
        $data->usetemplate = 0;
        $data->timecreated = time();
        return $data;
    }

     /**
      * Sets conditions for tagged options.
      *
      * @param object $certificate
      * @param array $filterjson
      *
      * @return stdClass
      *
      */
    private function set_taggedoptions_condition(object $certificate, array $filterjson = []) {
        $data = new stdClass();
        $data->name = "Tagged_options_condition_test";
        $data->filterjson = json_encode($filterjson);
        $data->logicjson = json_encode(['conditionname' => 'taggedoptions', 'requiredcount' => 1]);
        $data->actionjson = json_encode([
            'actionname' => 'createcertificate',
            'certid' => $certificate->get_id(),
            'expirydatetype' => 0,
            'expirydateabsolute' => 1773129540,
            'expirydaterelative' => 0,
        ]);
        $data->isactive = 1;
        $data->usetemplate = 0;
        $data->timecreated = time();
        return $data;
    }

    /**
     * Set the bookingoption condition items.
     *
     * @param int $conditionid
     *
     * @return void
     *
     */
    private function set_condition_items(int $conditionid) {
        global $DB;
        $itemdata = new stdClass();
        $itemdata->component = 'mod_booking';
        $itemdata->area = 'bookingoption';
        $itemdata->conditionid = $conditionid;
        $itemdata->sortorder = 0;
        $itemdata->configjson = json_encode([]);
        foreach ($this->bookingoptions as $option) {
            $itemdata->itemid = $option->id;
            $DB->insert_record('booking_cert_cond_item', $itemdata);
        }
    }
}
