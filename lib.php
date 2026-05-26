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
 *
 * Single canonical mint for the plugin (DEC-043, todo #16). Both the
 * launch.php student-bridge flow AND every AJAX-relay flow go through
 * this function. RFC 7515 URL-safe base64 with stripped padding —
 * matches the PyJWT-backed verifier on the backend (DEC-039).
 *
 * @param int      $courseid        Moodle course ID.
 * @param string   $pseudonymous_id SHA-256 hash of user ID + site identifier.
 * @param string   $api_token       Org API token used as the HMAC signing secret.
 * @param int      $ttl_seconds     Token validity window in seconds. Defaults
 *                                  to 30 (DEC-031 short-TTL for AJAX relays).
 *                                  launch.php overrides to 300 to cover the
 *                                  student's redirect to the backend.
 * @param int|null $section_id      Optional Moodle section ID — surfaced as
 *                                  the `section_id` claim for micro-survey
 *                                  routing in the launch flow.
 * @param string|null $section_label Optional human-readable Moodle section name
 *                                  (`course_sections.name` or "Topic N" fallback)
 *                                  — surfaced as the `section_label` claim so the
 *                                  backend can stamp portal task labels with the
 *                                  unit name (DEC-048 follow-up).
 * @return string Signed JWT string.
 */
function local_srl_advisor_build_jwt(
    $courseid,
    $pseudonymous_id,
    $api_token,
    $ttl_seconds = 30,
    $section_id = null,
    $section_label = null
) {
    global $CFG;

    $issued_at  = time();
    $expires_at = $issued_at + (int)$ttl_seconds;

    $claims = [
        'iss'        => parse_url($CFG->wwwroot, PHP_URL_HOST),
        'sub'        => $pseudonymous_id,
        'course_id'  => $courseid,
        'section_id' => $section_id,
        'iat'        => $issued_at,
        'exp'        => $expires_at,
    ];
    if (!empty($section_label)) {
        $claims['section_label'] = (string)$section_label;
    }

    $header  = rtrim(strtr(base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=');

    $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", $api_token, true)), '+/', '-_'), '=');

    return "$header.$payload.$signature";
}

/**
 * Shared HTTP relay to the SRL Advisor backend.
 *
 * DEC-031 BLOCKER #3 resolution: single helper for every backend call from
 * the plugin (nav-badge, inline GET, inline POST, inline dismiss). Encodes
 * the DEC-029 hardening lessons:
 *   - distinguishes transport failure (curl errno) from backend error (non-2xx)
 *   - logs body slice on non-2xx only (never on success, avoids debug bloat)
 *   - tags every log line with $category so subsystems have separate grep
 *     channels (`local_srl_advisor[badge]`, `[inline_get]`, `[inline_post]`,
 *     `[inline_dismiss]`, `[sync]`)
 *
 * @param string $category    'badge' | 'inline_get' | 'inline_post' | 'inline_dismiss' | 'sync'
 * @param string $path        Must start with '/api/v1/'.
 * @param string $method      'GET' or 'POST'.
 * @param array|null $body    JSON-encoded on POST; ignored on GET.
 * @param string $jwt         Signed Bearer token.
 * @param int    $timeout     Seconds. Default 3 — never block Moodle nav longer than that.
 * @param array  $extra_headers Optional extra HTTP headers (e.g. Idempotency-Key).
 *                              Each entry is the full header line, e.g. 'Idempotency-Key: <uuid>'.
 * @return array {
 *   bool ok,
 *   int http_code,
 *   mixed|null data,         JSON-decoded body on 2xx, null otherwise
 *   string raw,              Raw response body
 *   string|null error_kind,  'transport' | 'backend' | 'parse' | null on success
 * }
 */
function local_srl_advisor_relay_backend_call($category, $path, $method, $body, $jwt, $timeout = 3, $extra_headers = []) {
    // Defensive: refuse arbitrary paths even if upstream construction is broken.
    if (strpos($path, '/api/v1/') !== 0) {
        debugging(
            "local_srl_advisor[{$category}]: refused non-/api/v1 path ({$path})",
            DEBUG_DEVELOPER
        );
        return ['ok' => false, 'http_code' => 0, 'data' => null, 'raw' => '', 'error_kind' => 'transport'];
    }

    $backend_url = trim((string)get_config('local_srl_advisor', 'backend_url'));
    if (empty($backend_url)) {
        debugging(
            "local_srl_advisor[{$category}]: backend_url not configured",
            DEBUG_DEVELOPER
        );
        return ['ok' => false, 'http_code' => 0, 'data' => null, 'raw' => '', 'error_kind' => 'transport'];
    }

    $url = rtrim($backend_url, '/') . $path;
    $insecure_ssl = (bool)get_config('local_srl_advisor', 'insecure_ssl');

    $headers = ["Authorization: Bearer $jwt"];
    if ($method === 'POST' && $body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    foreach ($extra_headers as $h) {
        $headers[] = $h;
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => $insecure_ssl ? false : true,
        CURLOPT_SSL_VERIFYHOST => $insecure_ssl ? 0 : 2,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $body === null ? '' : json_encode($body);
    }
    curl_setopt_array($ch, $opts);

    $response  = curl_exec($ch);
    $curl_errno = curl_errno($ch);
    $curl_err   = curl_error($ch);
    $http_code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Transport failure — curl could not complete the round-trip.
    if ($response === false || $curl_errno !== 0) {
        debugging(
            "local_srl_advisor[{$category}]: transport failure (url={$url}, errno={$curl_errno}, err={$curl_err})",
            DEBUG_DEVELOPER
        );
        return [
            'ok' => false,
            'http_code' => $http_code,
            'data' => null,
            'raw' => (string)$response,
            'error_kind' => 'transport',
        ];
    }

    // Backend reachable but returned a non-2xx status — log body slice.
    if ($http_code < 200 || $http_code >= 300) {
        debugging(
            "local_srl_advisor[{$category}]: backend {$http_code} (url={$url}, body=" . substr((string)$response, 0, 200) . ")",
            DEBUG_DEVELOPER
        );
        return [
            'ok' => false,
            'http_code' => $http_code,
            'data' => null,
            'raw' => (string)$response,
            'error_kind' => 'backend',
        ];
    }

    $data = json_decode((string)$response, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        debugging(
            "local_srl_advisor[{$category}]: JSON parse error (url={$url}, json_err=" . json_last_error_msg() . ")",
            DEBUG_DEVELOPER
        );
        return [
            'ok' => false,
            'http_code' => $http_code,
            'data' => null,
            'raw' => (string)$response,
            'error_kind' => 'parse',
        ];
    }

    return [
        'ok' => true,
        'http_code' => $http_code,
        'data' => $data,
        'raw' => (string)$response,
        'error_kind' => null,
    ];
}


/**
 * Calls the SRL Advisor action-items API and returns the pending count.
 * Returns 0 silently on any error so a slow or unreachable backend never
 * breaks Moodle navigation.
 *
 * Thin wrapper over `local_srl_advisor_relay_backend_call` (DEC-031 BLOCKER #3).
 *
 * @param string $backend_url   Unused — kept for backwards-compat with callers.
 * @param string $jwt           Signed JWT for Bearer authentication.
 * @return int  Number of pending action items (0 on error).
 */
function local_srl_advisor_get_pending_count($backend_url, $jwt) {
    $result = local_srl_advisor_relay_backend_call('badge', '/api/v1/action-items', 'GET', null, $jwt);
    if (!$result['ok']) {
        return 0;
    }
    return isset($result['data']['pending_count']) ? (int)$result['data']['pending_count'] : 0;
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

    // Build the nav label — append a Bootstrap pill badge when there are pending items.
    // Boost theme ships Bootstrap 4/5 utility classes; Moodle's nav renderer emits label HTML
    // unescaped, so the span renders as a red rounded-pill next to the link text.
    $label = get_string('nav_link', 'local_srl_advisor');
    if ($pending_count > 0) {
        $label .= ' <span class="badge bg-danger text-white rounded-pill ms-1">'
            . (int)$pending_count
            . '</span>';
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

/**
 * Moodle navbar-output callback — renders an SRL Advisor icon + pending-count
 * pill in the top-right user-menu region, alongside Moodle's bell and chat
 * icons. Same gates as the course-nav badge (enrolled in an allowlisted course
 * + plugin configured + logged-in non-guest). 60s session cache prevents
 * hammering the backend on every Moodle page render.
 *
 * Returns '' when any gate fails — Moodle simply omits the icon.
 *
 * @param renderer_base $renderer
 * @return string HTML (link + badge) or '' when not applicable.
 */
function local_srl_advisor_render_navbar_output(\renderer_base $renderer): string {
    global $USER, $CFG, $PAGE, $SESSION;

    if (empty($PAGE) || !isloggedin() || isguestuser()) {
        return '';
    }

    $course = $PAGE->course ?? null;
    if (!$course || empty($course->id) || (int)$course->id === SITEID) {
        // Top-right icon only shows inside a course context (matches the
        // nav-badge gate). Site front-page / dashboard / admin pages omit.
        return '';
    }
    $courseid = (int)$course->id;

    $backend_url = trim((string)get_config('local_srl_advisor', 'backend_url'));
    $api_token   = trim((string)get_config('local_srl_advisor', 'api_token'));
    if (empty($backend_url) || empty($api_token)) {
        return '';
    }

    $enabled_ids_raw = (string)get_config('local_srl_advisor', 'enabled_course_ids');
    if ($enabled_ids_raw === '') {
        return '';
    }
    $enabled_ids = array_map('trim', explode(',', $enabled_ids_raw));
    if (!in_array((string)$courseid, $enabled_ids, true)) {
        return '';
    }

    if (!is_enrolled(\context_course::instance($courseid), $USER, '', true)) {
        return '';
    }

    // 5s per-(user, course) session cache (DEC-059, was 60s). Keyed by courseid
    // so the badge updates when the student switches courses without waiting
    // out the window. 60s was too stale — after completing a task, the badge
    // sat at the old count for up to a minute before refreshing, looking like
    // a bug to the student. 5s is short enough that completion → next page
    // render shows the correct count, while still throttling the rapid-fire
    // case of a student scrolling through many course pages.
    $cache_key = "srl_navbar_count_{$courseid}";
    $cache_at_key = "srl_navbar_count_at_{$courseid}";
    $now = time();
    $cached = isset($SESSION->{$cache_key}) ? (int)$SESSION->{$cache_key} : null;
    $cached_at = isset($SESSION->{$cache_at_key}) ? (int)$SESSION->{$cache_at_key} : 0;

    if ($cached !== null && ($now - $cached_at) < 5) {
        $pending_count = $cached;
    } else {
        $pseudonymous_id = hash('sha256', $USER->id . $CFG->siteidentifier);
        $jwt = local_srl_advisor_build_jwt($courseid, $pseudonymous_id, $api_token);
        $pending_count = (int)local_srl_advisor_get_pending_count($backend_url, $jwt);
        $SESSION->{$cache_key} = $pending_count;
        $SESSION->{$cache_at_key} = $now;
    }

    $launch_url = (new \moodle_url('/local/srl_advisor/launch.php', ['courseid' => $courseid]))->out(false);
    $aria = get_string('nav_link', 'local_srl_advisor');
    $tooltip = $pending_count > 0
        ? get_string('navbar_tooltip_with_pending', 'local_srl_advisor', $pending_count)
        : get_string('navbar_tooltip_no_pending', 'local_srl_advisor');
    $badge_html = '';
    if ($pending_count > 0) {
        $badge_html = '<span class="badge bg-danger text-white rounded-pill srladvisor-navbar__badge">'
            . (int)$pending_count
            . '<span class="sr-only"> ' . s($aria) . '</span>'
            . '</span>';
    }

    // Bootstrap tooltip via data-toggle="tooltip" — Moodle Boost initialises
    // tooltips on navbar elements automatically. data-placement="bottom" so
    // the bubble drops below the user-menu strip without colliding with the
    // page header. The native `title` attribute is the fallback when JS is
    // disabled or the tooltip plugin failed to load.
    return '<div class="popover-region srladvisor-navbar">'
        . '<a class="nav-link srladvisor-navbar__link" href="' . htmlspecialchars($launch_url) . '" '
        . 'title="' . s($tooltip) . '" '
        . 'aria-label="' . s($tooltip) . '" '
        . 'data-toggle="tooltip" data-bs-toggle="tooltip" '
        . 'data-placement="bottom" data-bs-placement="bottom">'
        . '<span class="srladvisor-navbar__icon-wrap">'
        . '<i class="icon fa fa-graduation-cap fa-fw" aria-hidden="true"></i>'
        . $badge_html
        . '</span>'
        . '</a></div>';
}

/**
 * Renderer fired before the page footer. Invoked by the Moodle 4.5+ hook
 * listener in `classes/hook/before_footer.php`, which is registered in
 * `db/hooks.php` against `core\hook\output\before_footer_html_generation`.
 *
 * The legacy `local_srl_advisor_before_footer()` name was renamed to
 * `local_srl_advisor_render_before_footer()` to prevent Moodle's
 * `process_legacy_callbacks()` from auto-invoking it as a deprecated
 * callback (which would emit a developer-debug notice AND double-fire
 * everything in here alongside the hook listener).
 *
 * DEC-031 v1.1 inline check-ins. Gates the inline AMD module to:
 *   - mod-page-view only (Q1 resolution; widen post-pilot)
 *   - logged-in, non-guest, enrolled students (BLOCKER #2)
 *   - admin allowlist of enabled courses (matches nav-badge gate)
 *   - plugin configured (backend_url + api_token set)
 *
 * Any gate miss is a silent no-op. AMD bootstrap itself swallows
 * AJAX failures, so the worst-case outcome is "no panel" — never a
 * page render error.
 *
 * Section id resolves from `$PAGE->cm->section` (course_sections.id,
 * NOT sectionnum). Passed through to the backend GET.
 */
function local_srl_advisor_render_before_footer() {
    global $PAGE, $USER, $CFG;

    if (empty($PAGE)) {
        return;
    }

    $pagetype = (string)$PAGE->pagetype;
    $is_mod_page    = ($pagetype === 'mod-page-view');
    $is_mod_assign  = ($pagetype === 'mod-assign-view');
    $is_mod_quiz    = ($pagetype === 'mod-quiz-view');
    $is_mod_activity = $is_mod_page || $is_mod_assign || $is_mod_quiz;
    $is_course    = (strpos($pagetype, 'course-view-') === 0);
    if (!$is_mod_activity && !$is_course) {
        return;
    }

    // User gate — logged-in, non-guest, enrolled in this course.
    if (!isloggedin() || isguestuser()) {
        return;
    }

    // $PAGE->course is a magic-getter property on moodle_page. Read via local
    // variable so PHP's `empty()` does not double-resolve through __isset()
    // (which is not defined on moodle_page and produces inconsistent results
    // depending on internal page state).
    $course = $PAGE->course ?? null;
    if (!$course || empty($course->id)) {
        return;
    }
    $courseid = (int)$course->id;

    // Plugin configured?
    $backend_url = trim((string)get_config('local_srl_advisor', 'backend_url'));
    $api_token   = trim((string)get_config('local_srl_advisor', 'api_token'));
    if (empty($backend_url) || empty($api_token)) {
        return;
    }

    // Course in allowlist?
    $enabled_ids_raw = (string)get_config('local_srl_advisor', 'enabled_course_ids');
    if (empty($enabled_ids_raw)) {
        return;
    }
    $enabled_ids = array_map('trim', explode(',', $enabled_ids_raw));
    if (!in_array((string)$courseid, $enabled_ids, true)) {
        return;
    }

    // Enrolment gate (BLOCKER #2) — same posture as nav-badge.
    $context = context_course::instance($courseid);
    if (!is_enrolled($context, $USER, '', true)) {
        return;
    }

    // --- v1.1 inline check-in AMD inject (DEC-031 + DEC-048 follow-up) ----
    // Widened from mod-page-view-only to also include mod-assign-view and
    // mod-quiz-view so the post check-in can render on a section's last
    // activity (typically the Reflection / Assignment), not just on a Reading
    // Page. First/last positions now computed against ALL cms in the section
    // sequence — pre fires on whichever cm the student lands on first, post
    // fires on the last cm. Single-cm sections render both on the same cm.
    if ($is_mod_activity) {
        $cm = $PAGE->cm ?? null;
        if (!$cm || empty($cm->section)) {
            debugging('local_srl_advisor[inline_get]: mod-*-view without $PAGE->cm->section', DEBUG_DEVELOPER);
        } else {
            $sectionid = (int)$cm->section;
            global $DB;
            $sequence = (string)$DB->get_field('course_sections', 'sequence', ['id' => $sectionid]);
            $cm_ids = array_values(array_filter(array_map('intval', explode(',', $sequence))));
            $current_cmid = (int)$cm->id;
            $is_first = !empty($cm_ids) && $cm_ids[0] === $current_cmid;
            $is_last  = !empty($cm_ids) && end($cm_ids) === $current_cmid;

            // Same cm is both first and last (single-cm section) → emit both
            // phases. AMD's per-phase mount + sanity gate decides which panel
            // renders based on the backend's pending task.
            $phases = [];
            if ($is_first) {
                $phases[] = 'pre';
            }
            if ($is_last && !$is_first) {
                $phases[] = 'post';
            } elseif ($is_first && $is_last) {
                $phases[] = 'post';
            }

            if (empty($phases)) {
                debugging("local_srl_advisor[inline_get]: skip — cmid={$current_cmid} is not first/last in section {$sectionid} (sequence=" . implode(',', $cm_ids) . ')', DEBUG_DEVELOPER);
            } else {
                $portal_url = (new moodle_url('/local/srl_advisor/launch.php', ['courseid' => $courseid]))->out(false);
                debugging("local_srl_advisor[inline_get]: injecting AMD (courseid={$courseid}, sectionid={$sectionid}, cmid={$current_cmid}, pagetype={$pagetype}, phases=" . implode('+', $phases) . ", first={$is_first}, last={$is_last})", DEBUG_DEVELOPER);
                foreach ($phases as $phase) {
                    $PAGE->requires->js_call_amd(
                        'local_srl_advisor/check_in',
                        'init',
                        [$courseid, $sectionid, $portal_url, $phase]
                    );
                }
            }

            // LAB-002 scroll + LAB-003 video + LAB-004 download + LAB-005
            // clipboard telemetry — Page-view only. Quiz and Assignment pages
            // have their own content models (interactive forms, submission UIs)
            // where reading-engagement signals don't map to the question these
            // labs measure. Keep the original mod-page-view gate.
            if ($is_mod_page) {
                $PAGE->requires->js_call_amd(
                    'local_srl_advisor/scroll_telemetry',
                    'init',
                    [$courseid, $sectionid, $pagetype, $current_cmid]
                );
                $PAGE->requires->js_call_amd(
                    'local_srl_advisor/video_telemetry',
                    'init',
                    [$courseid, $sectionid, $pagetype, $current_cmid]
                );
                $PAGE->requires->js_call_amd(
                    'local_srl_advisor/download_telemetry',
                    'init',
                    [$courseid, $sectionid, $pagetype, $current_cmid]
                );
                $PAGE->requires->js_call_amd(
                    'local_srl_advisor/clipboard_telemetry',
                    'init',
                    [$courseid, $sectionid, $pagetype, $current_cmid]
                );
            }
        }
    }

    // --- LAB-004 download telemetry on course-view-* (DEC-057) ----------
    // Section/course homepage is where students click mod-resource activity
    // links (PDF, slides, docx) — Moodle resolves these to the actual file
    // via /mod/resource/view.php. Mount the download module here so those
    // clicks are captured even when the student never opens a Page.
    // sectionid + cmid are null on course-view; AMD accepts null gracefully.
    if ($is_course && !$is_mod_activity) {
        $PAGE->requires->js_call_amd(
            'local_srl_advisor/download_telemetry',
            'init',
            [$courseid, null, $pagetype, null]
        );
    }

    // --- DEC-032 end-of-course summative banner -------------------------
    // Render on every gated course-view-* AND mod-page-view render so the
    // student sees the prompt regardless of where they land after finishing
    // the course. Single backend round-trip per render; no AMD/AJAX.
    $pseudonymous_id = hash('sha256', $USER->id . $CFG->siteidentifier);
    $jwt = local_srl_advisor_build_jwt($courseid, $pseudonymous_id, $api_token);
    $result = local_srl_advisor_relay_backend_call('banner', '/api/v1/action-items', 'GET', null, $jwt);
    if (!$result['ok']) {
        return;
    }
    $items = $result['data']['items'] ?? [];
    $has_summative = false;
    foreach ($items as $item) {
        if (isset($item['type']) && $item['type'] === 'summative_survey') {
            $has_summative = true;
            break;
        }
    }
    if (!$has_summative) {
        return;
    }

    // Portal launch URL — same as nav-badge; backend's /launch routes the
    // student into the consent/dashboard flow which surfaces the survey card.
    $portal_url = (new moodle_url('/local/srl_advisor/launch.php', ['courseid' => $courseid]))->out(false);
    $heading = get_string('summative_banner_heading', 'local_srl_advisor');
    $cta     = get_string('summative_banner_cta', 'local_srl_advisor');
    $link    = get_string('summative_banner_link', 'local_srl_advisor');

    // Server-side render. Mustache auto-escapes; safe against any future
    // string-injection vectors in the lang file.
    global $OUTPUT;
    return $OUTPUT->render_from_template('local_srl_advisor/summative_banner', [
        'heading'    => $heading,
        'cta'        => $cta,
        'link_label' => $link,
        'portal_url' => $portal_url,
    ]);
}
