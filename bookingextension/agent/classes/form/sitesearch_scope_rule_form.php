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
 * Modal form adding/updating a category or course scope rule on the site-search governance page.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace bookingextension_agent\form;

use bookingextension_agent\local\wizard\services\sitesearch\index_scope_estimator;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_area_registry;
use bookingextension_agent\local\wizard\services\sitesearch\site_content_chunk_pipeline;
use bookingextension_agent\local\wizard\services\sitesearch\sitesearch_scope_repository;
use context;
use context_system;
use core_course_category;
use core_form\dynamic_form;
use moodle_url;
use stdClass;

/**
 * One modal per rule scope type (the launcher button passes `area` + `scopetype` as AJAX args):
 * category rules pick from the capability-filtered category list, course rules use the core
 * course autocomplete (frontpage included — SITEID counts as a normal course scope, concept §9).
 *
 * Besides the enumerated area ids the `area` arg accepts the wildcard '*' (blueprint §3.0): one
 * rule covering every content area with a course dimension — the launcher buttons of the
 * governance page's "all content areas" section preset it. The file flag is always offered for
 * the wildcard (it covers file-capable areas); as everywhere it only takes effect per file-capable
 * area.
 *
 * Saving writes through {@see sitesearch_scope_repository} (upsert), so picking a scope that
 * already has a rule simply UPDATES that rule — the repository's delta-sync chokepoint queues the
 * targeted backfill/prune either way. The rule's estimate + traffic light appear in the rule list
 * right after the page reload (deliberate robust variant over an in-form live preview, concept §6
 * "kein hartes Muss"): the per-scope figure is measured and MUC-cached here during submission, so
 * the reloaded page serves it from the cache instead of re-measuring.
 */
final class sitesearch_scope_rule_form extends dynamic_form {
    /**
     * Form fields: hidden area + scopetype (launcher-provided), the scope picker matching the
     * scope type, the enabled flag, and — only for file-capable areas — the file-indexing flag.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;

        // GOTCHA (project experience): inside dynamic forms ALWAYS the form's own
        // $this->optional_param() (reads the AJAX args), never the global optional_param().
        $area = $this->optional_param('area', '', PARAM_RAW_TRIMMED);
        $scopetype = $this->optional_param('scopetype', '', PARAM_ALPHA);

        $mform->addElement('hidden', 'area', $area);
        $mform->setType('area', PARAM_RAW_TRIMMED);
        $mform->addElement('hidden', 'scopetype', $scopetype);
        $mform->setType('scopetype', PARAM_ALPHA);

        if ($scopetype === sitesearch_scope_repository::SCOPETYPE_CATEGORY) {
            // Capability-filtered category list (concept §6).
            $categories = core_course_category::make_categories_list('bookingextension/agent:configuresitesearch');
            $mform->addElement(
                'autocomplete',
                'scopeid',
                get_string('sitesearchgovernance_ruleform_scope_category', 'bookingextension_agent'),
                $categories
            );
        } else {
            // Core course autocomplete; the frontpage is a selectable, normal course scope (§9).
            $mform->addElement(
                'course',
                'scopeid',
                get_string('sitesearchgovernance_ruleform_scope_course', 'bookingextension_agent'),
                ['multiple' => false, 'includefrontpage' => true]
            );
        }
        $mform->setType('scopeid', PARAM_INT);
        $mform->addRule('scopeid', get_string('required'), 'required', null, 'client');

        $mform->addElement(
            'advcheckbox',
            'enabled',
            get_string('sitesearchgovernance_ruleform_enabled', 'bookingextension_agent')
        );
        $mform->setDefault('enabled', 1);

        // The file flag only exists for file-capable areas (uses_file_indexing()); for all other
        // areas it is omitted entirely — process_dynamic_submission() then never touches it.
        if ($this->area_uses_file_indexing($area)) {
            $mform->addElement(
                'advcheckbox',
                'includefiles',
                get_string('sitesearchgovernance_ruleform_includefiles', 'bookingextension_agent')
            );
            $mform->setDefault('includefiles', 0);
            if (!site_content_chunk_pipeline::extractor_available()) {
                $mform->addElement(
                    'static',
                    'includefilesnote',
                    '',
                    get_string('sitesearchgovernance_files_noextractor', 'bookingextension_agent')
                );
            }
        }
    }

    /**
     * Server-side validation: the area must be an enumerated search area, the scope type one of
     * the two rule types, and the picked category/course must exist.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        global $DB;

        $errors = [];

        $registry = new site_content_area_registry();
        $area = (string)($data['area'] ?? '');
        // The wildcard '*' is a valid rule area besides the enumerated ids (§3.0).
        if (!site_content_area_registry::is_wildcard($area) && !in_array($area, $registry->all_area_keys(), true)) {
            $errors['area'] = get_string('sitesearchgovernance_ruleform_error_area', 'bookingextension_agent');
        }

        $scopetype = (string)($data['scopetype'] ?? '');
        $scopeid = (int)($data['scopeid'] ?? 0);
        if ($scopetype === sitesearch_scope_repository::SCOPETYPE_CATEGORY) {
            if ($scopeid <= 0 || core_course_category::get($scopeid, IGNORE_MISSING, true) === null) {
                $errors['scopeid'] = get_string('sitesearchgovernance_ruleform_error_scope', 'bookingextension_agent');
            }
        } else if ($scopetype === sitesearch_scope_repository::SCOPETYPE_COURSE) {
            if ($scopeid <= 0 || !$DB->record_exists('course', ['id' => $scopeid])) {
                $errors['scopeid'] = get_string('sitesearchgovernance_ruleform_error_scope', 'bookingextension_agent');
            }
        } else {
            $errors['scopetype'] = get_string('sitesearchgovernance_ruleform_error_scope', 'bookingextension_agent');
        }

        return $errors;
    }

    /**
     * Persist the rule through the repository (upsert; its delta-sync chokepoint queues the
     * targeted backfill/prune) and pre-warm the rule's estimate for the reloaded rule list.
     *
     * @return stdClass ['saved' => bool, 'estimate' => array|null] — the estimate in the
     *                  {@see index_scope_estimator::estimate_for_scope()} shape.
     */
    public function process_dynamic_submission(): stdClass {
        $data = $this->get_data();

        $area = (string)$data->area;
        $scopetype = (string)$data->scopetype;
        $scopeid = (int)$data->scopeid;
        $includefiles = !empty($data->includefiles);

        $repository = new sitesearch_scope_repository();
        $repository->set_enabled($area, !empty($data->enabled), $scopetype, $scopeid);
        if ($this->area_uses_file_indexing($area)) {
            $repository->set_includefiles($area, $includefiles, $scopetype, $scopeid);
        }

        // Measure (and thereby MUC-cache) the rule's estimate now, so the governance page shows
        // the figure + traffic light immediately after the reload without re-measuring.
        $estimate = (new index_scope_estimator())->estimate_for_scope($area, $scopetype, $scopeid, $includefiles);

        return (object)['saved' => true, 'estimate' => $estimate];
    }

    /**
     * Pre-fill the launcher-provided area + scope type.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission(): void {
        $this->set_data([
            'area' => $this->optional_param('area', '', PARAM_RAW_TRIMMED),
            'scopetype' => $this->optional_param('scopetype', '', PARAM_ALPHA),
        ]);
    }

    /**
     * Rule governance is a site-wide, config-style action.
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        return context_system::instance();
    }

    /**
     * Same gate as the governance page itself.
     *
     * @return void
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('bookingextension/agent:configuresitesearch', context_system::instance());
    }

    /**
     * Fallback URL for non-JS submission.
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/mod/booking/bookingextension/agent/sitesearch_governance.php');
    }

    /**
     * Whether an area indexes files (drives the presence of the includefiles element). The
     * wildcard counts as file-capable — it covers file-capable areas, so its rules must carry
     * the flag.
     *
     * @param string $areakey Search area id or the wildcard '*'.
     * @return bool
     */
    private function area_uses_file_indexing(string $areakey): bool {
        if (site_content_area_registry::is_wildcard($areakey)) {
            return true;
        }
        $instance = (new site_content_area_registry())->area_instance($areakey);
        return $instance !== null && $instance->uses_file_indexing();
    }
}
