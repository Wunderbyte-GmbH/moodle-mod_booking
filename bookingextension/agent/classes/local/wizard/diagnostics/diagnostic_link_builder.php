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

declare(strict_types=1);

namespace bookingextension_agent\local\wizard\diagnostics;

use context;
use moodle_url;

/**
 * Builds real moodle_url deep-links for the diagnose-skill family.
 *
 * Standing rule: links are built here (server-side, real moodle_url), never by the LLM. Admin/privileged
 * targets are only returned when the asking user may actually open them, so the observation never carries
 * a link that yields a 403 for the recipient.
 *
 * Pure skill-layer helper — referenced by skills, never by the engine.
 *
 * @package    bookingextension_agent
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class diagnostic_link_builder {
    /**
     * Course view page.
     *
     * @param int $courseid
     * @return moodle_url
     */
    public function course(int $courseid): moodle_url {
        return new moodle_url('/course/view.php', ['id' => $courseid]);
    }

    /**
     * Activity (course module) view page.
     *
     * @param string $modname
     * @param int $cmid
     * @return moodle_url
     */
    public function activity(string $modname, int $cmid): moodle_url {
        return new moodle_url('/mod/' . $modname . '/view.php', ['id' => $cmid]);
    }

    /**
     * User profile, optionally scoped to a course.
     *
     * @param int $userid
     * @param int $courseid 0 = site profile.
     * @return moodle_url
     */
    public function user_profile(int $userid, int $courseid = 0): moodle_url {
        $params = ['id' => $userid];
        if ($courseid > 0) {
            $params['course'] = $courseid;
        }
        return new moodle_url('/user/view.php', $params);
    }

    /**
     * Course enrolment methods page.
     *
     * @param int $courseid
     * @return moodle_url
     */
    public function enrol_instances(int $courseid): moodle_url {
        return new moodle_url('/enrol/instances.php', ['id' => $courseid]);
    }

    /**
     * Enrolled users page.
     *
     * @param int $courseid
     * @return moodle_url
     */
    public function enrolled_users(int $courseid): moodle_url {
        return new moodle_url('/user/index.php', ['id' => $courseid]);
    }

    /**
     * Core "check permissions" tool for a context.
     *
     * @param int $contextid
     * @return moodle_url
     */
    public function check_permissions(int $contextid): moodle_url {
        return new moodle_url('/admin/roles/check.php', ['contextid' => $contextid]);
    }

    /**
     * Role assignment page for a context.
     *
     * @param int $contextid
     * @return moodle_url
     */
    public function assign_roles(int $contextid): moodle_url {
        return new moodle_url('/admin/roles/assign.php', ['contextid' => $contextid]);
    }

    /**
     * Gradebook setup tree.
     *
     * @param int $courseid
     * @return moodle_url
     */
    public function grade_setup(int $courseid): moodle_url {
        return new moodle_url('/grade/edit/tree/index.php', ['id' => $courseid]);
    }

    /**
     * Per-user grade report.
     *
     * @param int $courseid
     * @param int $userid
     * @return moodle_url
     */
    public function user_grade_report(int $courseid, int $userid): moodle_url {
        return new moodle_url('/grade/report/user/index.php', ['id' => $courseid, 'userid' => $userid]);
    }

    /**
     * Notification preferences for a user.
     *
     * @param int $userid
     * @return moodle_url
     */
    public function notification_preferences(int $userid): moodle_url {
        return new moodle_url('/message/notificationpreferences.php', ['userid' => $userid]);
    }

    /**
     * Course completion settings (which criteria mark the course complete).
     *
     * @param int $courseid
     * @return moodle_url
     */
    public function completion_settings(int $courseid): moodle_url {
        return new moodle_url('/course/completion.php', ['id' => $courseid]);
    }

    /**
     * Activity completion report (per-activity completion for all users).
     *
     * @param int $courseid
     * @return moodle_url
     */
    public function activity_completion_report(int $courseid): moodle_url {
        return new moodle_url('/report/progress/index.php', ['course' => $courseid]);
    }

    /**
     * Course completion report (criteria status per user).
     *
     * @param int $courseid
     * @return moodle_url
     */
    public function course_completion_report(int $courseid): moodle_url {
        return new moodle_url('/report/completion/index.php', ['course' => $courseid]);
    }

    /**
     * Scheduled tasks admin page (admin-only).
     *
     * @return moodle_url
     */
    public function scheduled_tasks(): moodle_url {
        return new moodle_url('/admin/tool/task/scheduledtasks.php');
    }

    /**
     * Outgoing mail configuration (admin-only).
     *
     * @return moodle_url
     */
    public function outgoing_mail_config(): moodle_url {
        return new moodle_url('/admin/settings.php', ['section' => 'outgoingmailconfig']);
    }

    /**
     * Return the link only when the user holds the capability at the context, else null.
     *
     * @param moodle_url $url
     * @param string $capability
     * @param context $context
     * @param int $userid
     * @return moodle_url|null
     */
    public function if_capable(moodle_url $url, string $capability, context $context, int $userid): ?moodle_url {
        return has_capability($capability, $context, $userid) ? $url : null;
    }

    /**
     * Return the link only when the user is a site admin, else null (for admin-only pages).
     *
     * @param moodle_url $url
     * @param int $userid
     * @return moodle_url|null
     */
    public function if_admin(moodle_url $url, int $userid): ?moodle_url {
        return is_siteadmin($userid) ? $url : null;
    }
}
