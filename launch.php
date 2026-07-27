<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * SRL Advisor Launch Page.
 *
 * This script is the bridge between Moodle and the SRL Advisor Python web application.
 * It constructs a signed JWT containing the student's pseudonymous Identity (Moodle
 * user ID hash + course ID) and uses an HTTP POST redirect to forward the student
 * to the Python app.
 *
 * NO student PII (name, email) is transmitted. Only a hashed user identifier,
 * the course ID, and the organization token.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// Ensure the user is logged in before we do anything.
require_login();

// Get the course ID from the request.
$courseid  = required_param('courseid', PARAM_INT);
$sectionid = optional_param('sectionid', null, PARAM_INT); // Optional: triggers micro-survey if present.
$course    = get_course($courseid);

// Set up the Moodle page context.
$context = context_course::instance($courseid);
require_capability('local/srl_advisor:participate', $context); // DEC-062: was mod/assign:view proxy.

// DEC-059: invalidate the navbar pending-count session cache before the launch
// redirect. A student arriving at launch.php is in the middle of (or just
// after) an action — the cached count is about to be wrong. Clearing here
// guarantees the badge refetches fresh on the next Moodle page render after
// the student returns from the portal.
global $SESSION;
$srlcachekey    = "srl_navbar_count_{$courseid}";
$srlcacheatkey = "srl_navbar_count_at_{$courseid}";
unset($SESSION->{$srlcachekey}, $SESSION->{$srlcacheatkey});

// Retrieve plugin configuration.
$backendurl        = rtrim(trim(get_config('local_srl_advisor', 'backend_url')), '/');
$publicbackendurl = rtrim(trim(get_config('local_srl_advisor', 'public_backend_url')), '/');
$apitoken          = trim(get_config('local_srl_advisor', 'api_token'));

if (empty($backendurl) || empty($apitoken)) {
    throw new moodle_exception('Plugin is not configured. Please ask your administrator to set the Backend URL and API Token.');
}

// The backend_url is the server-to-server URL (e.g., host.containers.internal:8000)
// used by the plugin's curl calls. The browser cannot resolve container-internal
// hostnames, so the launch redirect must use a user-facing URL. Falls back to
// backend_url for institutions where the backend is reachable at the same address
// from both the Moodle host and the student's browser.
$redirectbase = !empty($publicbackendurl) ? $publicbackendurl : $backendurl;

// Build the JWT payload.
// Pseudonymize the user: instead of sending their real ID, derive a
// deterministic hash so the Python app can identify them without PII.
$pseudonymousid = hash('sha256', $USER->id . $CFG->siteidentifier);

// DEC-048 follow-up: surface the section's human name so the backend can
// stamp portal task labels with the unit name. Moodle stores the visible
// section name in `course_sections.name`; if the operator left it blank,
// fall back to "Topic N" using `sectionnum`. Either way the backend gets
// something better than "Quick question about this section".
$sectionlabel = null;
if (!empty($sectionid)) {
    global $DB;
    $sectionrow = $DB->get_record('course_sections', ['id' => $sectionid], 'name,section');
    if ($sectionrow) {
        $candidate = isset($sectionrow->name) ? trim((string)$sectionrow->name) : '';
        if ($candidate === '') {
            $candidate = isset($sectionrow->section)
                ? get_string('section') . ' ' . (int)$sectionrow->section
                : null;
        }
        $sectionlabel = ($candidate !== '') ? $candidate : null;
    }
}

// Delegate to the canonical plugin mint (DEC-043). 300s TTL covers the
// student's redirect from Moodle → backend; section_id + section_label are
// optional, threaded through to drive micro-survey routing + portal task
// labels respectively.
$jwt = local_srl_advisor_build_jwt(
    $courseid,
    $pseudonymousid,
    $apitoken,
    300,
    $sectionid,
    $sectionlabel
);

// Auto-submit POST form to the Python backend.
// Using a form POST ensures the JWT is not sent via GET and does not appear
// in browser history or server logs.
$launchurl = $redirectbase . '/launch';

echo $OUTPUT->header();
echo '<html>';
echo '<body onload="document.getElementById(\'srl_launcher\').submit();">';
echo '<noscript><p>Redirecting to SRL Advisor. If you are not redirected, ';
echo '<button form="srl_launcher" type="submit">click here</button>.</p></noscript>';
echo '<form id="srl_launcher" method="POST" action="' . htmlspecialchars($launchurl) . '">';
echo '<input type="hidden" name="moodle_jwt" value="' . htmlspecialchars($jwt) . '" />';
echo '</form></body></html>';
echo $OUTPUT->footer();
