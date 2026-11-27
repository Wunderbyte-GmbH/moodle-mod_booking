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

use advanced_testcase;
use moodle_url;

/**
 * Links in calendar entries and mails go through bookingredirect.php. The script only redirects
 * to pages of this site, so a link on the domain of the site can never lead to another site.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_booking\booking::decode_redirect_url
 * @covers \mod_booking\booking::encode_moodle_url
 */
final class bookingredirect_test extends advanced_testcase {
    /**
     * The value of the encodedurl parameter of a link created by the plugin.
     *
     * @param moodle_url $target
     * @return string
     */
    private function encoded_param_of(moodle_url $target): string {
        $link = new moodle_url(booking::encode_moodle_url($target));
        return (string)$link->get_param('encodedurl');
    }

    /**
     * Links created by the plugin keep working.
     */
    public function test_links_of_the_plugin_are_resolved(): void {
        $target = new moodle_url('/mod/booking/link.php', [
            'id' => 5,
            'optionid' => 7,
            'action' => 'join',
            'sessionid' => 9,
            'fieldid' => 11,
        ]);

        $this->assertSame($target->out(false), booking::decode_redirect_url($this->encoded_param_of($target)));
    }

    /**
     * Targets outside of the site are refused.
     *
     * @dataProvider foreign_target_provider
     * @param string $target
     */
    public function test_targets_outside_of_the_site_are_refused(string $target): void {
        global $CFG;

        $target = str_replace('WWWROOT', $CFG->wwwroot, $target);
        $this->assertSame('', booking::decode_redirect_url(base64_encode($target)));
    }

    /**
     * Targets that are not pages of this site.
     *
     * @return array
     */
    public static function foreign_target_provider(): array {
        return [
            'another site' => ['https://attacker.example/login'],
            'another site that starts like this site' => ['WWWROOT.attacker.example/login'],
            'this site as user name of another site' => ['WWWROOT@attacker.example/login'],
            'another scheme' => ['javascript://attacker.example/%0aalert(1)'],
            'no url' => ['not a url'],
            'empty' => [''],
        ];
    }
}
