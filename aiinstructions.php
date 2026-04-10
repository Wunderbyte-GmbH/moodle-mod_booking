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
 * AI Instructions chat interface for booking managers.
 *
 * @package     mod_booking
 * @copyright   2025 Wunderbyte GmbH <info@wunderbyte.at>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_booking\agent\authorization_service;
use mod_booking\agent\orchestrator;
use mod_booking\agent\interpreter;
use mod_booking\agent\task_registry;
use mod_booking\agent\conversation_store;
use mod_booking\singleton_service;

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'booking');
require_course_login($course, false, $cm);

$context = context_module::instance($cm->id);
$PAGE->set_context($context);
$PAGE->activityheader->disable();

// Authorization.
$authz = new authorization_service();
$authz->require_valid_context($cmid);
$authz->require_use_capability($USER->id, $cmid);

$bookingsettings = singleton_service::get_instance_of_booking_settings_by_cmid($cmid);

$baseurl = new moodle_url('/mod/booking/aiinstructions.php', ['id' => $cmid]);
$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('aiinstructions', 'mod_booking'));
$PAGE->set_heading(format_string($bookingsettings->name));

// Check provider availability.
$registry     = task_registry::make_default();
$store        = new conversation_store();
$interp       = new interpreter($registry);
$orchestrator = new orchestrator($registry, $interp, $store);
$providerstatus = $orchestrator->is_provider_available($cmid, $USER->id);

// Get or create thread for this user + instance.
$thread = $store->get_or_create_thread($USER->id, $cmid, $cm->instance);

$templatedata = [
    'cmid'               => $cmid,
    'threadid'           => $thread->id,
    'sesskey'            => sesskey(),
    'provider_available' => $providerstatus,
    'no_provider_msg'    => $providerstatus ? '' : get_string('ai_provider_not_configured', 'mod_booking'),
    'wwwroot'            => $CFG->wwwroot,
];

$PAGE->requires->js_call_amd('mod_booking/aiinstructions', 'init', [$templatedata]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_booking/aiinstructions', $templatedata);
echo $OUTPUT->footer();
