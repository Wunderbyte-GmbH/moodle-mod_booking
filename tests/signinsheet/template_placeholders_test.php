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
 * Tests for the placeholders of the sign-in sheet HTML template.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use context_system;
use mod_booking\signinsheet\signinsheet_config;
use mod_booking\signinsheet\signinsheet_generator;
use mod_booking\tests\booking_advanced_testcase;
use mod_booking_generator;
use stdClass;

/**
 * Outside of [[users]] the HTML template (setting signinsheethtml) resolves custom booking
 * option fields and all placeholders of the booking rules, written as [[...]] instead of {...}.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\signinsheet\signinsheet_generator::render_html
 */
final class template_placeholders_test extends booking_advanced_testcase {
    /**
     * Booking option with a custom booking option field (roomnumber) and one booked user.
     *
     * @return booking_option
     */
    private function create_booked_option(): booking_option {
        global $CFG;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        // Custom booking option field (core customfield api, scope mod_booking / booking).
        $category = $this->getDataGenerator()->create_custom_field_category([
            'name' => 'Sign-in sheet fields',
            'component' => 'mod_booking',
            'area' => 'booking',
            'itemid' => 0,
            'contextid' => context_system::instance()->id,
        ]);
        $category->save();
        $field = $this->getDataGenerator()->create_custom_field([
            'categoryid' => $category->get('id'),
            'name' => 'Room',
            'shortname' => 'roomnumber',
            'type' => 'text',
            'configdata' => '',
        ]);
        $field->save();

        // Custom user profile fields: one with its own shortname and one sharing the shortname of the
        // custom booking option field (the option field wins). Both are filled for the downloading admin,
        // whose values must never appear - profile fields are rendered per booked user inside [[users]].
        $this->getDataGenerator()->create_custom_profile_field(
            ['shortname' => 'rank', 'name' => 'Rank', 'datatype' => 'text']
        );
        $this->getDataGenerator()->create_custom_profile_field(
            ['shortname' => 'roomnumber', 'name' => 'Room (profile)', 'datatype' => 'text']
        );
        // Uppercase letters in the shortname: PostgreSQL folds unquoted column aliases to lowercase.
        $this->getDataGenerator()->create_custom_profile_field(
            ['shortname' => 'Abteilung', 'name' => 'Abteilung', 'datatype' => 'text']
        );
        require_once($CFG->dirroot . '/user/profile/lib.php');
        profile_save_data((object) [
            'id' => get_admin()->id,
            'profile_field_rank' => 'Major',
            'profile_field_roomnumber' => 'Profile room',
        ]);

        $booking = $this->getDataGenerator()->create_module('booking', [
            'name' => 'Sign-in booking',
            'course' => $course->id,
            'signinsheetfields' => ['fullname', 'email', 'description', 'signature'],
        ]);
        /** @var mod_booking_generator $plugingenerator */
        $plugingenerator = self::getDataGenerator()->get_plugin_generator('mod_booking');
        $record = new stdClass();
        $record->bookingid = $booking->id;
        $record->text = 'Yoga für Anfänger:innen';
        $record->coursestarttime = strtotime('tomorrow 10:00');
        $record->courseendtime = strtotime('tomorrow 12:00');
        $record->customfield_roomnumber = 'Raum 4.02';
        $option = $plugingenerator->create_option($record);

        // Two booked users with their own profile field values (rendered per row inside [[users]]).
        $bookedusers = [
            ['Anna', 'Bianchi', 'Sergeant', 'Vertrieb', 'Profile of {email} [[bookingoptionname]]'],
            ['Ben', 'Tester', 'Captain', 'Einkauf', ''],
        ];
        $profiles = [];
        foreach ($bookedusers as [$firstname, $lastname, $rank, $department, $description]) {
            $user = $this->getDataGenerator()->create_user([
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => strtolower($firstname) . '@example.com',
                // User data is inserted into the sheet but must never be parsed for placeholders.
                'description' => $description,
            ]);
            $this->getDataGenerator()->enrol_user($user->id, $course->id);
            $plugingenerator->create_answer(['optionid' => $option->id, 'userid' => $user->id]);
            $profiles[$user->id] = ['profile_field_rank' => $rank, 'profile_field_Abteilung' => $department];
        }
        // Profile values are set after all users exist: the local_taskflow observer of user_created
        // re-saves the custom profile fields of every user seen before in the process from stale
        // objects (taskflowadapter_standard::process_incoming_data), which would blank them.
        foreach ($profiles as $userid => $profile) {
            profile_save_data((object) (['id' => $userid] + $profile));
        }

        singleton_service::destroy_instance();
        $settings = singleton_service::get_instance_of_booking_option_settings($option->id);
        return singleton_service::get_instance_of_booking_option($settings->cmid, $option->id);
    }

    /**
     * Sign-in sheet generator in HTML template mode with the download presets of the plugin.
     *
     * @param booking_option $bookingoption
     * @return signinsheet_generator
     */
    private function create_signinsheet_generator(booking_option $bookingoption): signinsheet_generator {
        set_config('signinsheetmode', 'htmltemplate', 'booking');
        $pdfoptions = signinsheet_config::pdfoptions_from_config(signinsheet_config::for_option($bookingoption->optionid));
        $pdfoptions->saveasformat = 'pdf';
        return new signinsheet_generator($pdfoptions, $bookingoption);
    }

    /**
     * Custom fields and the supported booking rules placeholders are resolved outside of [[users]],
     * unsupported ones are not, the placeholders of the template mode keep their own values, css
     * braces and unknown placeholders are untouched and user data is never parsed.
     */
    public function test_rule_placeholders_are_rendered_outside_users(): void {
        $bookingoption = $this->create_booked_option();
        set_config(
            'signinsheethtml',
            '<style>.signaturetable td { border: 1px solid #000; }</style>'
            . '<h1>[[tablename]]</h1><h2>[[BookingOptionName]]</h2>'
            . '<p class="room">[[RoomNumber]]</p><p class="dates">[[DATES]]</p>'
            . '<p class="typo">[[nonexisting]]</p><p class="downloader">[[FIRSTNAME]]</p>'
            . '<p class="profilefield">[[Rank]]</p>'
            . '<table class="signaturetable"><tr><th>Name</th><th>Info</th><th>Rank</th><th>Abteilung</th></tr>'
            . '[[Users]]<tr><td>[[FullName]]</td><td>[[description]]</td><td>[[RANK]]</td><td>[[aBTEILUNG]]</td></tr>'
            . '[[/Users]]</table>',
            'booking'
        );

        $html = $this->create_signinsheet_generator($bookingoption)->render_html();

        // Custom booking option field and a placeholder of the booking rules, written as [[...]] - the
        // placeholders are case-insensitive ([[RoomNumber]], [[BookingOptionName]], [[Users]], ...).
        $this->assertStringContainsString('<p class="room">Raum 4.02</p>', $html);
        $this->assertStringContainsString('<h2>Yoga für Anfänger:innen</h2>', $html);
        // The placeholders of the template mode keep their values: [[tablename]] follows the "title"
        // download preset (default: instance name and option name), [[dates]] the "sessions" preset
        // (default: no dates) - while {dates} of the rules would render a list.
        $this->assertStringContainsString('<h1>Sign-in booking: Yoga für Anfänger:innen</h1>', $html);
        $this->assertStringContainsString('<p class="dates"></p>', $html);
        // Placeholders not supported in sign-in sheets (for_signinsheet() false, e.g. the user placeholder
        // [[firstname]]) stay unresolved outside of [[users]].
        $this->assertStringContainsString('<p class="downloader">[[FIRSTNAME]]</p>', $html);
        // Custom user profile fields stay unresolved outside of [[users]] (the value of the downloading
        // admin is never rendered), and a custom booking option field wins over a profile field with the
        // same shortname (Raum 4.02, not Profile room).
        $this->assertStringContainsString('<p class="profilefield">[[Rank]]</p>', $html);
        $this->assertStringNotContainsString('Major', $html);
        $this->assertStringNotContainsString('Profile room', $html);
        // The css of the template is untouched, an unknown placeholder stays visible as before.
        $this->assertStringContainsString('.signaturetable td { border: 1px solid #000; }', $html);
        $this->assertStringContainsString('<p class="typo">[[nonexisting]]</p>', $html);
        $this->assertStringNotContainsString('{nonexisting}', $html);
        $this->assertStringNotContainsString('{roomnumber}', $html);
        $this->assertStringNotContainsString('@@signinsheet', $html);
        // Inside [[users]] the custom user profile fields are rendered per booked user, and the user rows
        // are inserted after all placeholders were resolved - user data is never parsed.
        $this->assertStringContainsString(
            '<td>Bianchi, Anna</td><td>Profile of {email} [[bookingoptionname]]</td><td>Sergeant</td><td>Vertrieb</td>',
            $html
        );
        $this->assertStringContainsString('<td>Tester, Ben</td><td></td><td>Captain</td><td>Einkauf</td>', $html);
    }

    /**
     * A template without any placeholder of the rules (the default template) renders as before.
     */
    public function test_default_template_is_unchanged(): void {
        $bookingoption = $this->create_booked_option();
        set_config('signinsheethtml', '', 'booking');

        $html = $this->create_signinsheet_generator($bookingoption)->render_html();

        $this->assertStringContainsString('Sign-in booking: Yoga für Anfänger:innen', $html);
        $this->assertStringContainsString('Bianchi, Anna', $html);
        $this->assertStringContainsString('Tester, Ben', $html);
        $this->assertStringContainsString('anna@example.com', $html);
        $this->assertStringNotContainsString('[[', $html);
        $this->assertStringNotContainsString('@@signinsheet', $html);
    }
}
