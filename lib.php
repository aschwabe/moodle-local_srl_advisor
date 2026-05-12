<?php
/**
 * Library functions for the SRL Advisor local plugin.
 *
 * This is a STATELESS launcher plugin. It does not create or modify any
 * Moodle database tables. All Participant state, consent logic, and routing
 * is handled by the SRL Advisor Python web application.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Builds a signed JWT for server-to-server calls to the SRL Advisor API.
 * Uses the same signing scheme as launch.php so the backend can verify it.
 *
 * @param int    $courseid       Moodle course ID.
 * @param string $pseudonymous_id SHA-256 hash of user ID + site identifier.
 * @param string $api_token      Org API token used as the HMAC signing secret.
 * @return string Signed JWT string.
 */
function local_srl_advisor_build_jwt($courseid, $pseudonymous_id, $api_token) {
    global $CFG;

    $issued_at  = time();
    $expires_at = $issued_at + 60; // Short TTL — API call only, not a browser launch.

    $header  = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode([
        'iss'        => parse_url($CFG->wwwroot, PHP_URL_HOST),
        'sub'        => $pseudonymous_id,
        'course_id'  => $courseid,
        'section_id' => null,
        'iat'        => $issued_at,
        'exp'        => $expires_at,
    ])), '+/', '-_'), '=');

    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", $api_token, true)), '+/', '-_'), '=');

    return "$header.$payload.$signature";
}

/**
 * Calls the SRL Advisor action-items API and returns the pending count.
 * Returns 0 silently on any error so a slow or unreachable backend never
 * breaks Moodle navigation.
 *
 * @param string $backend_url   Base URL of the SRL Advisor backend.
 * @param string $jwt           Signed JWT for Bearer authentication.
 * @return int  Number of pending action items (0 on error).
 */
function local_srl_advisor_get_pending_count($backend_url, $jwt) {
    $url = rtrim($backend_url, '/') . '/api/v1/action-items';

    // Allow insecure SSL for dev/self-signed backends via plugin config.
    $insecure_ssl = (bool)get_config('local_srl_advisor', 'insecure_ssl');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3, // Never block navigation for more than 3 seconds.
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $jwt"],
        CURLOPT_SSL_VERIFYPEER => $insecure_ssl ? false : true,
        CURLOPT_SSL_VERIFYHOST => $insecure_ssl ? 0 : 2,
    ]);

    $response  = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $http_code !== 200) {
        debugging(
            "local_srl_advisor: action-items call failed (url=$url, http=$http_code, err=$curl_err, body=" . substr((string)$response, 0, 200) . ")",
            DEBUG_DEVELOPER
        );
        return 0;
    }

    $data = json_decode($response, true);
    return isset($data['pending_count']) ? (int)$data['pending_count'] : 0;
}

/**
 * Injects an "SRL Advisor" link into the course navigation if the current
 * course is in the admin-managed list of enabled courses.
 * Appends a numeric badge to the label when the student has pending action items.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The current course object.
 * @param context_course $context The course context.
 */
function local_srl_advisor_extend_navigation_course($navigation, $course, $context) {
    global $USER, $CFG;

    // LAB-001 diagnostic — confirm hook fires at all on this page.
    debugging("local_srl_advisor: extend_navigation_course ENTER courseid={$course->id} userid={$USER->id}", DEBUG_DEVELOPER);

    // Only show the link to enrolled students (not guests, admins viewing as themselves).
    if (!isloggedin() || isguestuser()) {
        debugging("local_srl_advisor: SKIP — not logged in or guest user", DEBUG_DEVELOPER);
        return;
    }

    // Check if the plugin is configured.
    $backend_url = trim(get_config('local_srl_advisor', 'backend_url'));
    $api_token   = trim(get_config('local_srl_advisor', 'api_token'));
    if (empty($backend_url) || empty($api_token)) {
        debugging("local_srl_advisor: SKIP — backend_url or api_token empty (backend_url_set=" . (!empty($backend_url) ? '1' : '0') . " api_token_set=" . (!empty($api_token) ? '1' : '0') . ")", DEBUG_DEVELOPER);
        return;
    }

    // Check if the current course is in the admin-defined allowlist.
    $enabled_ids_raw = get_config('local_srl_advisor', 'enabled_course_ids');
    if (empty($enabled_ids_raw)) {
        debugging("local_srl_advisor: SKIP — enabled_course_ids config is empty", DEBUG_DEVELOPER);
        return;
    }

    $enabled_ids = array_map('trim', explode(',', $enabled_ids_raw));
    if (!in_array((string)$course->id, $enabled_ids)) {
        debugging("local_srl_advisor: SKIP — courseid {$course->id} not in enabled_course_ids=[{$enabled_ids_raw}]", DEBUG_DEVELOPER);
        return;
    }

    debugging("local_srl_advisor: PASS gates — calling action-items API (backend_url={$backend_url})", DEBUG_DEVELOPER);

    // Derive the pseudonymous user ID (matches what launch.php sends).
    $pseudonymous_id = hash('sha256', $USER->id . $CFG->siteidentifier);

    // Query the SRL Advisor API for pending action items.
    $jwt           = local_srl_advisor_build_jwt($course->id, $pseudonymous_id, $api_token);
    $pending_count = local_srl_advisor_get_pending_count($backend_url, $jwt);
    debugging("local_srl_advisor: pending_count={$pending_count} — rendering nav node", DEBUG_DEVELOPER);

    // Build the nav label — append badge count when there are pending items.
    $label = get_string('nav_link', 'local_srl_advisor');
    if ($pending_count > 0) {
        $label .= ' (' . $pending_count . ')';
    }

    // Build the URL for the launch page, passing the current course ID.
    $launch_url = new moodle_url('/local/srl_advisor/launch.php', ['courseid' => $course->id]);

    // Add the link to the course navigation under the course root node.
    $node = navigation_node::create(
        $label,
        $launch_url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_srl_advisor',
        new pix_icon('i/report', '')
    );

    $navigation->add_node($node);
}
