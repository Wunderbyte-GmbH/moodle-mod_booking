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
 * Unit tests making sure mod/booking/settings.php loads without errors on a plain installation.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_booking;

use advanced_testcase;
use core_plugin_manager;
use mod_booking\customfield\booking_handler;
use mod_booking\utils\wb_payment;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Loads settings.php exactly like a real site does and fails on any PHP warning,
 * notice, deprecation or exception (e.g. undefined variables or empty arrays that
 * are accessed without a guard).
 *
 * The main scenario is a "plain" installation: no Booking PRO license and no
 * booking custom fields configured, which is the state right after installing
 * the plugin on a fresh site.
 *
 * @package mod_booking
 * @category test
 * @copyright 2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class settings_loading_test extends advanced_testcase {
    /**
     * Tests set up.
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->setAdminUser();
    }

    /**
     * Tests tear down.
     */
    public function tearDown(): void {
        // Static override survives between tests, so always remove it.
        wb_payment::override_pro_version_for_tests(null);
        parent::tearDown();
    }

    /**
     * A plain installation: no PRO license activated and no booking customfields set.
     *
     * This is the state of a fresh install of the free Booking version. Loading the
     * admin settings must not produce undefined variables, invalid array accesses,
     * exceptions or any other PHP error.
     */
    public function test_settings_load_on_plain_installation_without_pro(): void {
        // Force the plain (non-PRO) code paths, PHPUnit would otherwise always run as PRO.
        wb_payment::override_pro_version_for_tests(false);

        // Make sure the preconditions of the scenario really hold.
        $this->assertFalse(
            wb_payment::pro_version_is_activated(),
            'Precondition failed: PRO version must not be activated for this scenario.'
        );
        $this->assertEmpty(
            booking_handler::get_customfields(),
            'Precondition failed: there must be no booking customfields for this scenario.'
        );

        // Settings.php runs in both modes on a real site: with the full settings tree
        // (admin search, saving settings) and without it (building the navigation).
        $this->load_settings_and_assert_clean(true);
        $this->load_settings_and_assert_clean(false);
    }

    /**
     * The same check with an activated PRO version (still without customfields),
     * so the PRO-only settings blocks are covered as well.
     */
    public function test_settings_load_with_pro_activated(): void {
        wb_payment::override_pro_version_for_tests(true);

        $this->assertTrue(wb_payment::pro_version_is_activated());
        $this->assertEmpty(booking_handler::get_customfields());

        $this->load_settings_and_assert_clean(true);
        $this->load_settings_and_assert_clean(false);
    }

    /**
     * Includes mod/booking/settings.php the same way core does and asserts that
     * no PHP warning, notice, deprecation, exception or unexpected output occurs.
     *
     * @param bool $fulltree whether to build the full settings tree
     * @return \admin_root the admin tree the settings were loaded into
     */
    private function load_settings_and_assert_clean(bool $fulltree): \admin_root {
        global $ADMIN;

        $mode = $fulltree ? 'fulltree' : 'non-fulltree';

        // A fresh admin root with the category the module settings get attached to,
        // like in admin_get_root(). Using a private root keeps the test isolated
        // from settings.php files of other plugins.
        $adminroot = new \admin_root($fulltree);
        $adminroot->add('root', new \admin_category('modsettings', 'Activity modules'));

        // Settings.php pulls in the global $ADMIN, which admin_get_root() sets
        // before the plugin settings are loaded on a real site.
        $previousadmin = $ADMIN ?? null;
        $ADMIN = $adminroot;

        $plugininfo = core_plugin_manager::instance()->get_plugin_info('mod_booking');
        $this->assertNotNull($plugininfo, 'mod_booking must be installed.');

        $problems = [];

        // Collect every warning, notice and deprecation that originates from booking code,
        // instead of stopping at the first one. Undefined variables and undefined array
        // keys are reported by PHP 8 as E_WARNING.
        set_error_handler(
            function (int $errno, string $errstr, string $errfile, int $errline) use (&$problems): bool {
                $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
                $frames = array_column($trace, 'file');
                $frames[] = $errfile;
                foreach ($frames as $file) {
                    if (strpos((string) $file, '/mod/booking/') !== false) {
                        $problems[] = sprintf('%s in %s:%d', $errstr, $errfile, $errline);
                        break;
                    }
                }
                // Suppress the error so the whole file is processed and all problems are reported at once.
                return true;
            },
            E_ALL
        );

        ob_start();
        try {
            // This is exactly what core does when building the admin tree,
            // it defines $ADMIN, $settings, $module and includes settings.php.
            $plugininfo->load_settings($adminroot, 'modsettings', true);
        } catch (\Throwable $e) {
            $problems[] = sprintf(
                'Exception (%s): %s in %s:%d',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
        } finally {
            $output = ob_get_clean();
            restore_error_handler();
            $ADMIN = $previousadmin;
        }

        $this->assertSame(
            [],
            $problems,
            "Loading mod/booking/settings.php ($mode) caused PHP errors:\n" . implode("\n", $problems)
        );
        $this->assertSame(
            '',
            (string) $output,
            "Loading mod/booking/settings.php ($mode) produced unexpected output."
        );

        // The settings structure must actually have been built - guards against the
        // include silently bailing out and turning this test into a false positive.
        $this->assertInstanceOf(
            \admin_category::class,
            $adminroot->locate('modbookingfolder'),
            "The booking settings folder is missing in the admin tree ($mode)."
        );
        $settingspage = $adminroot->locate('modsettingbooking');
        $this->assertInstanceOf(
            \admin_settingpage::class,
            $settingspage,
            "The main booking settings page is missing in the admin tree ($mode)."
        );
        if ($fulltree) {
            $this->assertNotEmpty(
                $settingspage->settings,
                'The main booking settings page contains no settings although the full tree was requested.'
            );
        }

        return $adminroot;
    }

    /**
     * The booking category opens with the PRO activation, followed by the links to the other booking
     * pages, and only then the general settings.
     */
    public function test_category_starts_with_pro_activation_then_subpages_then_settings(): void {
        wb_payment::override_pro_version_for_tests(true);
        $adminroot = $this->load_settings_and_assert_clean(true);

        $children = array_values($adminroot->locate('modbookingfolder')->get_children());
        $names = array_map(fn($child) => $child->name, $children);

        $this->assertSame('modbookinglicense', $names[0], 'The PRO activation must be the first page.');
        // Subplugins append their own pages behind the general settings, so these are not necessarily
        // last - but every page of the booking module itself comes before them.
        $settingsindex = array_search('modsettingbooking', $names, true);
        $this->assertGreaterThan(1, $settingsindex, 'The linked pages must come before the general settings.');
        foreach ($children as $index => $child) {
            if ($child instanceof \admin_externalpage && strpos($child->name, 'modbooking') === 0) {
                $this->assertLessThan($settingsindex, $index, "The page {$child->name} must come before the general settings.");
            }
        }

        // The links form one block right below the activation: no settings page of the booking module
        // sits between them, otherwise the category page renders its settings in the middle of the list.
        $firstsettingspage = null;
        foreach ($children as $index => $child) {
            if ($index === 0 || strpos($child->name, 'mod') !== 0) {
                continue;
            }
            if ($child instanceof \admin_settingpage && $firstsettingspage === null) {
                $firstsettingspage = $child->name;
            }
            if ($child instanceof \admin_externalpage && $firstsettingspage !== null) {
                $this->fail("The link {$child->name} comes after the settings page {$firstsettingspage}.");
            }
        }

        $licensepage = $children[0];
        $this->assertInstanceOf(\admin_settingpage::class, $licensepage);
        $this->assertTrue(property_exists($licensepage->settings, 'bookinglicensekey'));
        $this->assertFalse(
            property_exists($adminroot->locate('modsettingbooking')->settings, 'bookinglicensekey'),
            'The license key must exist only once.'
        );
    }

    /**
     * The fields offered for the sign-in sheet are the booking custom fields defined on the custom field
     * page (stored as customfield_<n>), never other settings whose names merely contain "customfield".
     */
    public function test_showcustfields_offers_only_defined_custom_fields(): void {
        set_config('customfield_0', 'Room', 'booking');
        set_config('customfield_0type', 'textfield', 'booking');
        set_config('customfield_0options', '', 'booking');
        set_config('cardoptionviewcustomfields', 'sport,botags', 'booking');
        set_config('customfieldicon_sport', 'fa-futbol', 'booking');
        set_config('customfieldsforview', '', 'booking');

        $this->assertSame(['customfield_0'], array_keys(booking_option::get_customfield_settings()));

        $adminroot = $this->load_settings_and_assert_clean(true);
        $settings = $adminroot->locate('modsettingbooking')->settings;
        $this->assertTrue(property_exists($settings, 'bookingshowcustfields'));
        $this->assertSame(['customfield_0' => 'Room'], $settings->bookingshowcustfields->choices);
    }

    /**
     * The admin navigation (built without the full tree) lists the same booking pages as the full
     * tree, including the pages of every booking extension.
     */
    public function test_navigation_lists_the_same_pages_as_the_full_tree(): void {
        wb_payment::override_pro_version_for_tests(true);
        $full = $this->folder_page_names($this->load_settings_and_assert_clean(true));
        $navigation = $this->folder_page_names($this->load_settings_and_assert_clean(false));

        $this->assertSame($full, $navigation);
        $this->assertNotEmpty(
            array_filter($navigation, fn($name) => strpos($name, 'mod') !== 0),
            'Precondition failed: no booking extension added a page.'
        );
    }

    /**
     * Without the full tree no settings page in the booking category carries settings: the navigation
     * is built on every admin page, so the settings and their database or config work wait for the full tree.
     */
    public function test_navigation_builds_no_settings(): void {
        wb_payment::override_pro_version_for_tests(true);
        $adminroot = $this->load_settings_and_assert_clean(false);

        foreach ($adminroot->locate('modbookingfolder')->get_children() as $child) {
            if ($child instanceof \admin_settingpage) {
                $this->assertEmpty((array) $child->settings, "The page {$child->name} built settings without the full tree.");
            }
        }
    }

    /**
     * The entry tickets heading only carries the ticket settings: the settings registered after the
     * ticket block (e.g. use competencies) must not appear as part of that section.
     */
    public function test_ticket_heading_only_carries_ticket_settings(): void {
        wb_payment::override_pro_version_for_tests(true);
        set_config('bookingticketon', 1, 'booking');
        $adminroot = $this->load_settings_and_assert_clean(true);

        $insection = false;
        $found = false;
        foreach ((array) $adminroot->locate('modsettingbooking')->settings as $key => $setting) {
            if ($setting instanceof \admin_setting_heading) {
                $insection = $key === 'bookingbookingticketheading';
                $found = $found || $insection;
                continue;
            }
            if ($insection) {
                $this->assertStringStartsWith('bookingticket', $setting->name, "{$key} is shown under the entry tickets heading.");
            }
        }
        $this->assertTrue($found, 'Precondition failed: the entry tickets heading is missing.');
    }

    /**
     * The names of the pages in the booking category, in their order.
     *
     * @param \admin_root $adminroot
     * @return string[]
     */
    private function folder_page_names(\admin_root $adminroot): array {
        return array_values(array_map(fn($child) => $child->name, $adminroot->locate('modbookingfolder')->get_children()));
    }
}
