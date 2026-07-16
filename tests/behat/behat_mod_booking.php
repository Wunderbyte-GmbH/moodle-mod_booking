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
 * Defines message providers (types of messages being sent)
 *
 * @package mod_booking
 * @copyright 2023 Wunderbyte GmbH <info@wunderbyte.at>
 * @author Georg Maißer
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\UnsupportedDriverActionException;
use Behat\Mink\Exception\DriverException;
use mod_booking\booking;
use mod_booking\singleton_service;
use mod_booking\booking_rules\booking_rules;
use mod_booking\booking_rules\rules_info;
use mod_booking\bo_availability\conditions\maxoptionsfromcategory;
use mod_booking\local\wizard\options\skills\diagnose_cancellation_issue_skill;
use Behat\Gherkin\Node\TableNode;
use Moodle\BehatExtension\Exception\SkippedException;

/**
 * To create booking specific behat scearios.
 */
class behat_mod_booking extends behat_base {
    /** @var array Last direct cancellation diagnosis task result. */
    private array $lastdiagnosecancellationresult = [];

    /**
     * Skip equipment scenarios when the installed local_entities lacks the equipment feature.
     *
     * The equipment UI (location-scoped quantity fields and the "Show equipment for the
     * selected location" reload button) lives in local_entities. On checkouts without that
     * feature — e.g. CI pulling local_entities `main` while the equipment work is still on
     * its feature branch — the button never renders and the scenario would fail instead of
     * skip. The lang string is the feature marker: it ships with the equipment code.
     *
     * @Given /^the local_entities equipment feature is available$/
     * @return void
     */
    public function the_local_entities_equipment_feature_is_available(): void {
        if (!get_string_manager()->string_exists('refreshequipment', 'local_entities')) {
            throw new SkippedException(
                'Skipping equipment scenario: the installed local_entities does not provide the equipment feature.'
            );
        }
    }

    /**
     * Skip opt-in real LLM scenarios unless explicitly enabled.
     *
     * @Given /^real LLM mode is enabled$/
     * @return void
     */
    public function real_llm_mode_is_enabled(): void {
        if ((string)getenv('BOOKING_AI_REAL_LLM') !== '1') {
            throw new SkippedException(
                'Skipping real LLM Behat scenario because BOOKING_AI_REAL_LLM=1 is not set.'
            );
        }
    }

    /**
     * Ensure non-real-LLM scenario runs only when real LLM mode is not requested.
     *
     * @Given /^real LLM mode is disabled$/
     * @return void
     */
    public function real_llm_mode_is_disabled(): void {
        if ((string)getenv('BOOKING_AI_REAL_LLM') === '1') {
            throw new SkippedException(
                'Skipping non-real-LLM Behat scenario because BOOKING_AI_REAL_LLM=1 is enabled.'
            );
        }
    }

    /**
     * Create booking option in booking instance
     * @Given /^I create booking option "(?P<optionname_string>(?:[^"]|\\")*)" in "(?P<instancename_string>(?:[^"]|\\")*)"$/
     * @param string $optionname
     * @param string $instancename
     * @return void
     */
    public function i_create_booking_option($optionname, $instancename) {

        $cm = $this->get_cm_by_booking_name($instancename);

        $booking = singleton_service::get_instance_of_booking_by_cmid((int)$cm->id);

        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = $optionname;
        $record->courseid = $cm->course;
        $record->description = 'Test description';

        $datagenerator = \testing_util::get_data_generator();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = $datagenerator->get_plugin_generator('mod_booking');
        $bookingoption1 = $plugingenerator->create_option($record);
    }

    /**
     * Follow a certain link
     * @Given /^I open the link "(?P<linkurl_string>(?:[^"]|\\")*)"$/
     * @param string $linkurl
     * @return void
     */
    public function i_open_the_link($linkurl) {
        $this->getSession()->visit($linkurl);
    }

    /**
     * Get a booking by booking instance name.
     *
     * @param string $name booking instance name.
     * @return stdClass the corresponding DB row.
     */
    protected function get_booking_by_name(string $name): stdClass {
        global $DB;
        return $DB->get_record('booking', ['name' => $name], '*', MUST_EXIST);
    }

    /**
     * Get a booking coursemodule object from the name.
     *
     * @param string $name name.
     * @return stdClass cm from get_coursemodule_from_instance.
     */
    protected function get_cm_by_booking_name(string $name): stdClass {
        $booking = $this->get_booking_by_name($name);
        return get_coursemodule_from_instance('booking', $booking->id, $booking->course);
    }

    /**
     * Open the edit page for a booking option identified by option text and booking name.
     *
     * @Given /^I open the edit booking option page for option "([^"]*)" in booking "([^"]*)"$/
     * @param string $optiontext
     * @param string $bookingname
     * @return void
     */
    public function i_open_the_edit_booking_option_page_for_option_in_booking(
        string $optiontext,
        string $bookingname
    ): void {
        global $DB;

        $booking = $DB->get_record('booking', ['name' => $bookingname], '*', IGNORE_MISSING);
        if (!$booking) {
            throw new \dml_missing_record_exception('booking', ['name' => $bookingname]);
        }

        // Name collisions can happen across sequential scenarios; prefer the newest instance.
        $booking = $DB->get_records('booking', ['name' => $bookingname], 'id DESC', '*', 0, 1);
        $booking = reset($booking);
        $cm = get_coursemodule_from_instance('booking', (int)$booking->id, (int)$booking->course, false, MUST_EXIST);
        $option = $DB->get_record('booking_options', [
            'bookingid' => $booking->id,
            'text' => $optiontext,
        ], '*', MUST_EXIST);

        $url = new \moodle_url('/mod/booking/editoptions.php', [
            'id' => (int)$cm->id,
            'optionid' => (int)$option->id,
        ]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Open the "new booking option" form page for a booking instance by name.
     *
     * @Given /^I open the new booking option page for booking "([^"]*)"$/
     * @param string $bookingname
     * @return void
     */
    public function i_open_the_new_booking_option_page_for_booking(string $bookingname): void {
        $cm = $this->get_cm_by_booking_name($bookingname);
        $url = new \moodle_url('/mod/booking/editoptions.php', [
            'id' => (int)$cm->id,
            'optionid' => 0,
        ]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Fill specified HTMLQuickForm element by its number under given xpath with a value.
     * @When /^I click on the element with the number "([^"]*)" with the dynamic identifier "([^"]*)" and action "([^"]*)"$/
     * @param mixed $numberofitem
     * @param mixed $containeridentifier
     * @param mixed $actionidentifier
     * @return void
     * @throws RuntimeException
     * @throws InvalidArgumentException
     * @throws UnsupportedDriverActionException
     * @throws DriverException
     */
    public function i_click_on_element($numberofitem, $containeridentifier, $actionidentifier) {
        // Use $dynamicIdentifier to locate and fill in the corresponding form field.
        // Use $value to set the desired value in the form field.

        // First we need to open all collapsibles.
        // We should probably have a single fuction for that.
        $xpathtarget = "//tr[starts-with(@id, '" . $containeridentifier . "')]//a[@data-methodname='" . $actionidentifier . "']";
        $fields = $this->getSession()->getPage()->findAll('xpath', $xpathtarget);

        $counter = 1;
        foreach ($fields as $field) {
            if ($counter == $numberofitem) {
                $field->click();
            }
            $counter++;
        }
    }

    /**
     * Clean bookig singleton cache
     * @Given /^I clean booking cache$/
     * @return void
     */
    public function i_clean_booking_cache() {
        // Use phpunit's teardown() to ensure clean up the booking cache.
        $datagenerator = \testing_util::get_data_generator();
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = $datagenerator->get_plugin_generator('mod_booking');
        $plugingenerator->teardown();
    }

    /**
     * Rename bookingoption children
     * @Given /^I rename my bookingoption children$/
     * @return void
     */
    public function i_rename_my_bookingoption_children() {
        global $DB;
        $sql = "
            SELECT * FROM {booking_options}
            WHERE parentid > 0
            ORDER BY coursestarttime ASC
        ";
        $children = $DB->get_records_sql($sql, []);

        $i = 1;
        foreach ($children as $child) {
            $data = [
                'text' => 'child ' . $i,
                'id' => $child->id,
            ];
            $DB->update_record('booking_options', $data);
            $i++;
        };
    }

    /**
     * Create single booking rule form "vertical" description
     *
     * @Given the following booking rule exists:
     * @param TableNode $table
     * @return void
     */
    public function the_following_booking_rule_exists(TableNode $table) {
        $pairs = $table->getRows();
        $data = [];
        foreach ($pairs as $row) {
            if (count($row) >= 2) {
                $data[trim($row[0])] = $row[1];
            }
        }
        // Create via your plugin generator.
        /** @var \mod_booking_generator $gen */
        $gen = \testing_util::get_data_generator()->get_plugin_generator('mod_booking');
        $gen->create_rule($data);
    }

    /**
     * Create page activity with given shortcode text (must contains cmid=<booking name>) which refers given booking instance
     * // phpcs:ignore
     * @Given /^I create a page "(?P<pageid>[^"]*)" in course "(?P<coursename>[^"]*)" that refers booking "(?P<bookingname>[^"]*)" with shortcode "(?P<shortcode>(?:[^"]|\\")*)"$/
     * @param string $pageid
     * @param string $coursename
     * @param string $bookingname
     * @param string $shortcode
     * @return void
     */
    public function i_create_page_ref_booking(string $pageid, string $coursename, string $bookingname, string $shortcode) {
        global $DB, $CFG;
        require_once($CFG->libdir . '/testing/generator/lib.php');

        // 1) Validate page is unique.
        $modid = $DB->get_field('modules', 'id', ['name' => 'page'], MUST_EXIST);
        if ($DB->record_exists('course_modules', ['module' => $modid, 'idnumber' => $pageid])) {
            throw new \moodle_exception('Page with idnumber ' . $pageid . ' already exists.');
        }
        // 2) Get course.
        $course = $DB->get_record('course', ['shortname' => $coursename], '*', MUST_EXIST);

        // 3) Find cmid of booking by name (краще використовувати idnumber, якщо є).
        $modid = $DB->get_field('modules', 'id', ['name' => 'booking'], MUST_EXIST);
        $cm = $DB->get_record_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {booking} b ON b.id = cm.instance
              WHERE cm.course = ? AND cm.module = ? AND b.name = ?",
            [$course->id, $modid, $bookingname],
            MUST_EXIST
        );

        // 4) Build content with resolved cmid.
        $content = str_replace($bookingname, $cm->id, $shortcode);

        // 5) Create mod_page via data generator.
        /** @var testing_data_generator $dg */
        $dg = testing_util::get_data_generator();
        /** @var mod_page_generator $pg */
        $pg = $dg->get_plugin_generator('mod_page');

        $page = (object)[
            'course'        => $course->id,
            'name'          => ucfirst($pageid),
            'intro'         => 'Booking Options Shortcode Page',
            'introformat'   => FORMAT_HTML,
            'content'       => $content,
            'contentformat' => FORMAT_HTML,
            'idnumber'      => $pageid,
            'visible'       => 1,
        ];
        $pg->create_instance($page);
    }

    // AI instructions chat steps.

    /**
     * Navigate to the AI instructions chat page for a named booking instance.
     *
     * @Given /^I am on the AI instructions page for booking "(?P<bookingname_string>[^"]*)"$/
     * @param string $bookingname
     * @return void
     */
    public function i_am_on_the_ai_instructions_page_for_booking(string $bookingname): void {
        $cm = $this->get_cm_by_booking_name($bookingname);
        $url = new \moodle_url('/mod/booking/aiinstructions.php', ['id' => $cm->id]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Log in as a given user and navigate to the AI instructions chat page.
     *
     * @Given /^I am on the AI instructions page for booking "(?P<bookingname_string>[^"]*)" logged in as (?P<username_string>\w+)$/
     * @param string $bookingname
     * @param string $username
     * @return void
     */
    public function i_am_on_the_ai_instructions_page_for_booking_logged_in_as(
        string $bookingname,
        string $username
    ): void {
        $this->execute('behat_auth::i_log_in_as', [$username]);
        $this->i_am_on_the_ai_instructions_page_for_booking($bookingname);
    }

    /**
     * Log in as user, visit the AI instructions page, verify access is denied, then navigate away.
     * Navigating away is required so that the ChainedStepTester's automatic exception check.
     * The clean-page navigation avoids failures on the error page.
     *
     * @Given /^I visit the AI instructions page for booking "([^"]*)" as "([^"]*)" and expect access denied$/
     * @param string $bookingname
     * @param string $username
     * @return void
     */
    public function i_visit_ai_instructions_and_expect_access_denied(
        string $bookingname,
        string $username
    ): void {
        $this->execute('behat_auth::i_log_in_as', [$username]);
        $cm  = $this->get_cm_by_booking_name($bookingname);
        $url = new \moodle_url('/mod/booking/aiinstructions.php', ['id' => $cm->id]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));

        // Verify access is denied. Moodle can render this in different wrappers
        // depending on theme/version, so check both structure and common texts.
        $page = $this->getSession()->getPage();
        $errordiv = $page->find('xpath', "//div[@data-rel='fatalerror']");
        $errorbox = $page->find('css', '.alert-danger, .errorbox, #notice');
        $pagetext = core_text::strtolower($page->getText());
        $hasdeniedtext =
            str_contains($pagetext, 'you do not have permission')
            || str_contains($pagetext, 'you do not currently have permissions')
            || str_contains($pagetext, 'access denied')
            || str_contains($pagetext, 'keine berechtigung')
            || str_contains($pagetext, 'zugriff verweigert');

        if (!$errordiv && !$errorbox && !$hasdeniedtext) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Expected a Moodle permission-denied error page but none was found.',
                $this->getSession()
            );
        }

        // Navigate away so that the ChainedStepTester automatic "I look for exceptions"
        // step runs on the homepage rather than on the error page.
        $homeurl = new \moodle_url('/');
        $this->getSession()->visit($this->locate_path($homeurl->out_as_local_url(false)));
    }

    /**
     * Type a message into the AI chat input and click send.
     *
     * @When /^I send the AI message "(?P<message_string>[^"]*)"$/
     * @param string $message
     * @return void
     */
    public function i_send_the_ai_message(string $message): void {
        $page   = $this->getSession()->getPage();
        $input  = $page->find('css', '#booking-ai-input');
        if (!$input) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $this->getSession(),
                'element',
                'css',
                '#booking-ai-input'
            );
        }
        $input->setValue($message);

        $sendbtn = $page->find('css', '#booking-ai-send');
        if (!$sendbtn) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $this->getSession(),
                'element',
                'css',
                '#booking-ai-send'
            );
        }
        $sendbtn->click();
    }

    /**
     * Click the "Confirm" button in the AI command confirmation panel.
     *
     * @When /^I confirm the AI action$/
     * @return void
     */
    public function i_confirm_the_ai_action(): void {
        $btn = $this->getSession()->getPage()->find('css', '#booking-ai-btn-confirm');
        if (!$btn) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $this->getSession(),
                'element',
                'css',
                '#booking-ai-btn-confirm'
            );
        }
        $btn->click();
    }

    /**
     * Click the "Cancel" button in the AI command confirmation panel.
     *
     * @When /^I cancel the AI action$/
     * @return void
     */
    public function i_cancel_the_ai_action(): void {
        $btn = $this->getSession()->getPage()->find('css', '#booking-ai-btn-cancel');
        if (!$btn) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $this->getSession(),
                'element',
                'css',
                '#booking-ai-btn-cancel'
            );
        }
        $btn->click();
    }

    /**
     * Wait until at least one assistant message appears in the AI chat thread.
     *
     * Times out after 15 seconds.
     *
     * @Then /^I wait for the AI response$/
     * @return void
     */
    public function i_wait_for_the_ai_response(): void {
        $this->spin(function () {
            $el = $this->getSession()->getPage()->find('css', '.booking-ai-msg.assistant');
            return $el !== null;
        }, false, 15);
    }

    /**
     * Execute cancellation diagnosis task directly for deterministic non-real-LLM validation.
     *
     * @When /^I run cancellation diagnosis task in booking "([^"]*)" for option "([^"]*)" with question "([^"]*)"$/
     * @param string $bookingname
     * @param string $optionquery
     * @param string $question
     * @return void
     */
    public function i_run_cancellation_diagnosis_task_in_booking_for_option_with_question(
        string $bookingname,
        string $optionquery,
        string $question
    ): void {
        global $USER;

        $cm = $this->get_cm_by_booking_name($bookingname);
        $task = new diagnose_cancellation_issue_skill();
        $this->lastdiagnosecancellationresult = $task->execute([
            'question' => $question,
            'optionquery' => $optionquery,
        ], (int)$cm->id, (int)$USER->id);
    }

    /**
     * Assert direct cancellation diagnosis reports expected issue and concrete reason marker.
     *
     * @Then /^the cancellation diagnosis result should report issue "([^"]*)" and reason containing "([^"]*)"$/
     * @param string $expectedissue
     * @param string $reasonneedle
     * @return void
     */
    public function the_cancellation_diagnosis_result_should_report_issue_and_reason_containing(
        string $expectedissue,
        string $reasonneedle
    ): void {
        $result = $this->lastdiagnosecancellationresult;
        if (empty($result)) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'No cancellation diagnosis result is available. Run the diagnosis step first.',
                $this->getSession()
            );
        }

        if ((string)($result['status'] ?? '') !== 'executed') {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Expected diagnosis status "executed", got: ' . (string)($result['status'] ?? '(missing)'),
                $this->getSession()
            );
        }

        $actualissue = (string)($result['diagnosis']['issue'] ?? '');
        if ($actualissue !== $expectedissue) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Expected diagnosis issue "' . $expectedissue . '", got: "' . $actualissue . '".',
                $this->getSession()
            );
        }

        $reasons = (array)($result['diagnosis']['reasons'] ?? []);
        $reasonstext = implode("\n", $reasons);
        if (strpos($reasonstext, $reasonneedle) === false) {
            throw new \Behat\Mink\Exception\ExpectationException(
                'Expected diagnosis reasons to contain "' . $reasonneedle . '", got: ' . $reasonstext,
                $this->getSession()
            );
        }
    }

    /**
     * Assert that AI instructions page renders the correct UI for its readiness state.
     *
     * @Then /^the AI instructions page should render the expected readiness UI$/
     * @return void
     */
    public function the_ai_instructions_page_should_render_the_expected_readiness_ui(): void {
        $page = $this->getSession()->getPage();
        $wrapper = $page->find('css', '#booking-ai-wrapper');
        if (!$wrapper) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $this->getSession(),
                'element',
                'css',
                '#booking-ai-wrapper'
            );
        }

        $readyforchat = trim((string)$wrapper->getAttribute('data-ready-for-chat')) === '1';
        if ($readyforchat) {
            foreach (['#booking-ai-input', '#booking-ai-send', '#booking-ai-messages', '#booking-ai-thinking'] as $selector) {
                if (!$page->find('css', $selector)) {
                    throw new \Behat\Mink\Exception\ElementNotFoundException(
                        $this->getSession(),
                        'element',
                        'css',
                        $selector
                    );
                }
            }
            return;
        }

        if (!$page->find('css', '.booking-ai-onboarding-card')) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $this->getSession(),
                'element',
                'css',
                '.booking-ai-onboarding-card'
            );
        }
        if (!$page->find('css', '.booking-ai-readiness-list')) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $this->getSession(),
                'element',
                'css',
                '.booking-ai-readiness-list'
            );
        }
    }

    /**
     * Assert confirmation controls when chat is ready; otherwise assert onboarding view.
     *
     * @Then /^the AI instructions page should render confirmation controls when chat is ready$/
     * @return void
     */
    public function the_ai_instructions_page_should_render_confirmation_controls_when_chat_is_ready(): void {
        $page = $this->getSession()->getPage();
        $wrapper = $page->find('css', '#booking-ai-wrapper');
        if (!$wrapper) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $this->getSession(),
                'element',
                'css',
                '#booking-ai-wrapper'
            );
        }

        $readyforchat = trim((string)$wrapper->getAttribute('data-ready-for-chat')) === '1';
        if ($readyforchat) {
            foreach (['#booking-ai-confirm-panel', '#booking-ai-btn-confirm', '#booking-ai-btn-cancel'] as $selector) {
                if (!$page->find('css', $selector)) {
                    throw new \Behat\Mink\Exception\ElementNotFoundException(
                        $this->getSession(),
                        'element',
                        'css',
                        $selector
                    );
                }
            }
            return;
        }

        if (!$page->find('css', '.booking-ai-onboarding-card')) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $this->getSession(),
                'element',
                'css',
                '.booking-ai-onboarding-card'
            );
        }
    }

    /**
     * Confirm panel must be hidden on first load when chat is ready.
     *
     * @Then /^the AI confirmation panel should be hidden on initial load when chat is ready$/
     * @return void
     */
    public function the_ai_confirmation_panel_should_be_hidden_on_initial_load_when_chat_is_ready(): void {
        $page = $this->getSession()->getPage();
        $wrapper = $page->find('css', '#booking-ai-wrapper');
        if (!$wrapper) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $this->getSession(),
                'element',
                'css',
                '#booking-ai-wrapper'
            );
        }

        $readyforchat = trim((string)$wrapper->getAttribute('data-ready-for-chat')) === '1';
        if ($readyforchat) {
            $panel = $page->find('css', '#booking-ai-confirm-panel');
            if (!$panel) {
                throw new \Behat\Mink\Exception\ElementNotFoundException(
                    $this->getSession(),
                    'element',
                    'css',
                    '#booking-ai-confirm-panel'
                );
            }
            $classes = trim((string)$panel->getAttribute('class'));
            if (strpos(' ' . $classes . ' ', ' d-none ') === false) {
                throw new \Behat\Mink\Exception\ExpectationException(
                    'Expected confirmation panel to be hidden on initial page load.',
                    $this->getSession()
                );
            }
            return;
        }

        if (!$page->find('css', '.booking-ai-onboarding-card')) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $this->getSession(),
                'element',
                'css',
                '.booking-ai-onboarding-card'
            );
        }
    }
}
