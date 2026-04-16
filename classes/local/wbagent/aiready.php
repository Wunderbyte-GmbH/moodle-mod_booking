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
 * AI readiness helper for booking AI instructions.
 *
 * @package     mod_booking
 * @copyright   2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\local\wbagent;

use context_module;
use context_system;
use core\di;
use core_ai\aiactions\generate_text;
use core_ai\manager as ai_manager;
use mod_booking\singleton_service;

/**
 * Central readiness state for the booking AI panel.
 */
class aiready {
    /** @var int */
    private int $cmid;

    /** @var int */
    private int $userid;

    /** @var int */
    private int $bookingid;

    /**
     * Constructor.
     *
     * @param int $cmid
     * @param int $userid
     * @param int $bookingid
     */
    public function __construct(int $cmid, int $userid, int $bookingid) {
        $this->cmid = $cmid;
        $this->userid = $userid;
        $this->bookingid = $bookingid;
    }

    /**
     * Export readiness and chat config for mustache/JS.
     *
     * @return array
     */
    public function export_for_template(): array {
        global $CFG;

        $context = context_module::instance($this->cmid);
        $authz = new authorization_service();

        $isplatformadmin = has_capability('moodle/site:config', context_system::instance(), $this->userid);
        $hascapability = $authz->can_use($this->userid, $this->cmid);

        $providersconfigured = false;
        $haswunderbyteprovider = false;
        $provideractive = false;
        $courseenabled = false;
        $contextenabled = false;
        $debugmode = !empty(get_config('booking', 'bookingdebugmode'));

        $cm = get_coursemodule_from_id('booking', $this->cmid, 0, false, MUST_EXIST);
        $providerconfigurl = (new \moodle_url('/admin/settings.php', ['section' => 'aiprovider']))->out(false);
        $courseconfigurl = (new \moodle_url('/course/edit.php', ['id' => $cm->course]))->out(false);
        $moduleconfigurl = (new \moodle_url('/course/modedit.php', ['update' => $this->cmid, 'return' => 1]))->out(false);
        $capabilityurl = (new \moodle_url('/admin/roles/check.php', [
            'contextid' => $context->id,
            'capability' => 'mod/booking:useaiinstructions',
        ]))->out(false);

        if (class_exists('\\core_ai\\manager')) {
            try {
                $manager = di::get(ai_manager::class);
                $providersconfigured = !empty($manager->get_provider_instances());
                $haswunderbyteprovider = !empty($manager->get_provider_instances([
                    'name' => 'Wunderbyte',
                    'provider' => 'aiprovider_openai\\provider',
                ]));
                $provideractive = $manager->is_action_available(generate_text::class);
                $courseenabled = ai_manager::is_ai_tools_enabled_in_course($context);
                $contextenabled = $manager->is_action_enabled_in_context($context, generate_text::class);
            } catch (\Throwable $e) {
                $providersconfigured = false;
                $haswunderbyteprovider = false;
                $provideractive = false;
                $courseenabled = false;
                $contextenabled = false;
            }
        }

        $readyforchat = $provideractive && $contextenabled && $hascapability;
        $threadid = 0;

        if ($readyforchat) {
            $store = new conversation_store();
            $thread = $store->get_or_create_thread($this->userid, $this->cmid, $this->bookingid);
            $threadid = (int)$thread->id;
        }

        $checks = [
            $this->build_check(
                $providersconfigured,
                get_string('aiready_check_provider_configured', 'mod_booking'),
                $providersconfigured
                    ? get_string('aiready_check_provider_configured_done', 'mod_booking')
                    : get_string('aiready_check_provider_configured_todo', 'mod_booking'),
                $providerconfigurl
            ),
            $this->build_check(
                $provideractive,
                get_string('aiready_check_provider_active', 'mod_booking'),
                $provideractive
                    ? get_string('aiready_check_provider_active_done', 'mod_booking')
                    : get_string('aiready_check_provider_active_todo', 'mod_booking'),
                $providerconfigurl
            ),
            $this->build_check(
                $courseenabled,
                get_string('aiready_check_course_enabled', 'mod_booking'),
                $courseenabled
                    ? get_string('aiready_check_course_enabled_done', 'mod_booking')
                    : get_string('aiready_check_course_enabled_todo', 'mod_booking'),
                $courseconfigurl
            ),
            $this->build_check(
                $contextenabled,
                get_string('aiready_check_context_enabled', 'mod_booking'),
                $contextenabled
                    ? get_string('aiready_check_context_enabled_done', 'mod_booking')
                    : get_string('aiready_check_context_enabled_todo', 'mod_booking'),
                $moduleconfigurl
            ),
            $this->build_check(
                $hascapability,
                get_string('aiready_check_capability', 'mod_booking'),
                $hascapability
                    ? get_string('aiready_check_capability_done', 'mod_booking')
                    : get_string('aiready_check_capability_todo', 'mod_booking'),
                $capabilityurl
            ),
        ];

        $introtext = get_string('aiready_intro_text', 'mod_booking');

        $admintext = '';
        $nonadmintext = '';

        if (!$readyforchat) {
            if ($isplatformadmin) {
                $admintext = $haswunderbyteprovider
                    ? ''
                    : get_string('aiready_admin_text', 'mod_booking');
            } else {
                $nonadmintext = get_string('aiready_nonadmin_text', 'mod_booking');
            }
        }

        $activationquestiontext = $haswunderbyteprovider
            ? get_string('aitrial_activation_question_existing_provider', 'mod_booking')
            : get_string('aitrial_activation_question', 'mod_booking');

        $stats = $this->get_booking_statistics();
        $welcometext = ($stats['num_options'] === 0)
            ? get_string('ai_welcome_empty', 'mod_booking')
            : get_string('ai_welcome_with_options', 'mod_booking', (object) [
                'numoptions' => $stats['num_options'],
                'numbooked' => $stats['num_booked'],
            ]);

        return [
            'cmid' => $this->cmid,
            'threadid' => $threadid,
            'sesskey' => sesskey(),
            'wwwroot' => $CFG->wwwroot,
            'ready_for_chat' => $readyforchat,
            'provider_available' => $provideractive,
            'is_platform_admin' => $isplatformadmin,
            'has_use_capability' => $hascapability,
            'show_trial_button' => $isplatformadmin && !$readyforchat && !$haswunderbyteprovider,
            'show_trial_activate_button' => $isplatformadmin && !$readyforchat && $haswunderbyteprovider,
            'activation_question_text' => $activationquestiontext,
            'intro_text' => $introtext,
            'admin_text' => $admintext,
            'nonadmin_text' => $nonadmintext,
            'readiness_checks' => $checks,
            'num_options' => $stats['num_options'],
            'num_booked' => $stats['num_booked'],
            'welcome_text' => $welcometext,
            'debug_mode' => $debugmode,
        ];
    }

    /**
     * Build a single readiness check row.
     *
     * @param bool $done
     * @param string $label
     * @param string $detail
     * @param string|null $configureurl
     * @return array
     */
    private function build_check(bool $done, string $label, string $detail, ?string $configureurl = null): array {
        return [
            'done' => $done,
            'label' => $label,
            'detail' => $detail,
            'configureurl' => $configureurl,
            'configurelabel' => get_string('aiready_configure_here', 'mod_booking'),
            'icon' => $done
                ? '<i class="fa fa-check-square text-success" aria-hidden="true"></i>'
                : '<i class="fa fa-square-o text-muted" aria-hidden="true"></i>',
        ];
    }

    /**
     * Get booking statistics using singletons and cached objects.
     *
     * @return array with 'num_options' and 'num_booked' keys
     */
    private function get_booking_statistics(): array {
        $numoptions = 0;
        $numbooked = 0;

        try {
            // Get booking instance via singleton.
            $bookinginstance = singleton_service::get_instance_of_booking_by_bookingid($this->bookingid);
            if (!$bookinginstance) {
                return [
                    'num_options' => 0,
                    'num_booked' => 0,
                ];
            }

            $numoptions = $bookinginstance->get_all_options_count();

            // Get all option IDs for this booking.
            $optionids = $bookinginstance->get_all_options(0, 0);

            // Count booked persons by iterating through all options.
            foreach ($optionids as $option) {
                $optionid = $option->id;
                // Get option settings via singleton.
                $optionsettings = singleton_service::get_instance_of_booking_option_settings($optionid);
                // Get booking answers via singleton to count booked persons.
                $answers = singleton_service::get_instance_of_booking_answers($optionsettings);
                // Count booked persons.
                $bookedusers = $answers->get_usersonlist();
                $numbooked += count($bookedusers);
            }
        } catch (\Exception $e) {
            // If something goes wrong, return zeros.
            $numoptions = 0;
            $numbooked = 0;
        }

        return [
            'num_options' => $numoptions,
            'num_booked' => $numbooked,
        ];
    }
}
