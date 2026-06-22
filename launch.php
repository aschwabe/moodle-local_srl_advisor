<?php
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
$srl_cache_key    = "srl_navbar_count_{$courseid}";
$srl_cache_at_key = "srl_navbar_count_at_{$courseid}";
unset($SESSION->{$srl_cache_key}, $SESSION->{$srl_cache_at_key});

// --- Retrieve plugin configuration ---
$backend_url        = rtrim(trim(get_config('local_srl_advisor', 'backend_url')), '/');
$public_backend_url = rtrim(trim(get_config('local_srl_advisor', 'public_backend_url')), '/');
$api_token          = trim(get_config('local_srl_advisor', 'api_token'));

if (empty($backend_url) || empty($api_token)) {
    throw new moodle_exception('Plugin is not configured. Please ask your administrator to set the Backend URL and API Token.');
}

// `backend_url` is the server-to-server URL (e.g., host.containers.internal:8000)
// used by the plugin's curl calls. The browser cannot resolve container-internal
// hostnames, so the launch redirect must use a user-facing URL. Falls back to
// backend_url for institutions where the backend is reachable at the same address
// from both the Moodle host and the student's browser.
$redirect_base = !empty($public_backend_url) ? $public_backend_url : $backend_url;

// --- Build the JWT Payload ---
// We pseudonymize the user: instead of sending their real ID, we derive a
// deterministic hash so the Python app can identify them without PII.
$pseudonymous_id = hash('sha256', $USER->id . $CFG->siteidentifier);

// DEC-048 follow-up: surface the section's human name so the backend can
// stamp portal task labels with the unit name. Moodle stores the visible
// section name in `course_sections.name`; if the operator left it blank,
// fall back to "Topic N" using `sectionnum`. Either way the backend gets
// something better than "Quick question about this section".
$section_label = null;
if (!empty($sectionid)) {
    global $DB;
    $section_row = $DB->get_record('course_sections', ['id' => $sectionid], 'name,section');
    if ($section_row) {
        $candidate = isset($section_row->name) ? trim((string)$section_row->name) : '';
        if ($candidate === '') {
            $candidate = isset($section_row->section)
                ? get_string('section') . ' ' . (int)$section_row->section
                : null;
        }
        $section_label = ($candidate !== '') ? $candidate : null;
    }
}

// Delegate to the canonical plugin mint (DEC-043). 300s TTL covers the
// student's redirect from Moodle → backend; section_id + section_label are
// optional, threaded through to drive micro-survey routing + portal task
// labels respectively.
$jwt = local_srl_advisor_build_jwt(
    $courseid,
    $pseudonymous_id,
    $api_token,
    300,
    $sectionid,
    $section_label
);

// --- Auto-submit POST form to Python backend ---
// We use an auto-submitting form to ensure the JWT is sent via POST (not GET),
// which prevents it from appearing in browser history or server logs.
$launch_url = $redirect_base . '/launch';

echo $OUTPUT->header();
?>
<html>
<body onload="document.getElementById('srl_launcher').submit();">
    <noscript>
        <p>Redirecting to SRL Advisor. If you are not redirected automatically, <button form="srl_launcher" type="submit">click here</button>.</p>
    </noscript>
    <form id="srl_launcher" method="POST" action="<?php echo htmlspecialchars($launch_url); ?>">
        <input type="hidden" name="moodle_jwt" value="<?php echo htmlspecialchars($jwt); ?>" />
    </form>
</body>
</html>
<?php
echo $OUTPUT->footer();
