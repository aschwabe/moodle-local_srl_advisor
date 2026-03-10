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
 * Injects an "SRL Advisor" link into the course navigation if the current
 * course is in the admin-managed list of enabled courses.
 *
 * @param navigation_node $navigation The course navigation node.
 * @param stdClass $course The current course object.
 * @param context_course $context The course context.
 */
function local_srl_advisor_extend_navigation_course($navigation, $course, $context) {
    global $USER;

    // Only show the link to enrolled students (not guests, admins viewing as themselves).
    if (!isloggedin() || isguestuser()) {
        return;
    }

    // Check if the plugin is configured.
    $backend_url = trim(get_config('local_srl_advisor', 'backend_url'));
    $api_token   = trim(get_config('local_srl_advisor', 'api_token'));
    if (empty($backend_url) || empty($api_token)) {
        return;
    }

    // Check if the current course is in the admin-defined allowlist.
    $enabled_ids_raw = get_config('local_srl_advisor', 'enabled_course_ids');
    if (empty($enabled_ids_raw)) {
        return;
    }

    $enabled_ids = array_map('trim', explode(',', $enabled_ids_raw));
    if (!in_array((string)$course->id, $enabled_ids)) {
        return;
    }

    // Build the URL for the launch page, passing the current course ID.
    $launch_url = new moodle_url('/local/srl_advisor/launch.php', ['courseid' => $course->id]);

    // Add the link to the course navigation under the course root node.
    $node = navigation_node::create(
        get_string('nav_link', 'local_srl_advisor'),
        $launch_url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_srl_advisor',
        new pix_icon('i/report', '')
    );

    $navigation->add_node($node);
}
