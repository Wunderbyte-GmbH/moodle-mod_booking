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
 * Tests for the sqlfilter checkbox form support.
 *
 * @package mod_booking
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking\bo_availability;

use advanced_testcase;
use MoodleQuickForm;

/**
 * The sqlfilter checkboxes must be frozen (value-preserving) with an explanatory
 * note while booking/usesqlfilteravailability is off, and untouched while it is on.
 *
 * @covers \mod_booking\bo_availability\sqlfilter_form_support
 */
final class sqlfilter_form_support_test extends advanced_testcase {
    /**
     * Build a form with one sqlfilter-style advcheckbox.
     *
     * @return MoodleQuickForm
     */
    private function get_form(): MoodleQuickForm {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');
        $form = new MoodleQuickForm('testform', 'post', '');
        $form->addElement('advcheckbox', 'testsqlfiltercheck', 'Test sql filter');
        return $form;
    }

    /**
     * With the feature enabled the helper must not touch the form.
     *
     * @return void
     */
    public function test_enabled_setting_leaves_checkbox_untouched(): void {
        $this->resetAfterTest();
        set_config('usesqlfilteravailability', 1, 'booking');

        $form = $this->get_form();
        $notename = sqlfilter_form_support::freeze_when_disabled($form, 'testsqlfiltercheck');

        $this->assertNull($notename);
        $this->assertFalse($form->isElementFrozen('testsqlfiltercheck'));
        $this->assertFalse($form->elementExists('testsqlfiltercheck_disablednote'));
    }

    /**
     * With the feature disabled the checkbox is frozen and a note is added;
     * admins get a link to the setting, other users do not.
     *
     * @return void
     */
    public function test_disabled_setting_freezes_checkbox_and_adds_note(): void {
        $this->resetAfterTest();
        set_config('usesqlfilteravailability', 0, 'booking');

        // A user without moodle/site:config gets the note without the settings link.
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $form = $this->get_form();
        $notename = sqlfilter_form_support::freeze_when_disabled($form, 'testsqlfiltercheck');

        $this->assertSame('testsqlfiltercheck_disablednote', $notename);
        $this->assertTrue($form->isElementFrozen('testsqlfiltercheck'));
        $this->assertTrue($form->elementExists($notename));
        $notehtml = $form->getElement($notename)->toHtml();
        $this->assertStringNotContainsString('admin/settings.php', $notehtml);

        // An admin gets the direct link to the setting.
        $this->setAdminUser();
        $adminform = $this->get_form();
        $adminnotename = sqlfilter_form_support::freeze_when_disabled($adminform, 'testsqlfiltercheck');

        $this->assertSame('testsqlfiltercheck_disablednote', $adminnotename);
        $adminnotehtml = $adminform->getElement($adminnotename)->toHtml();
        $this->assertStringContainsString('admin/settings.php', $adminnotehtml);
        $this->assertStringContainsString('section=modsettingbooking', $adminnotehtml);
    }
}
