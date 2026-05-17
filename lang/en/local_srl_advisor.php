<?php
/**
 * Language strings for the SRL Advisor local plugin.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Plugin metadata.
$string['pluginname'] = 'SRL Advisor';

// Navigation link shown inside enabled courses.
$string['nav_link'] = 'SRL Advisor';

// Admin settings page.
$string['settings_backend_url']            = 'SRL Advisor Backend URL (server-to-server)';
$string['settings_backend_url_desc']       = 'The base URL the Moodle server uses to reach the SRL Advisor web application for API calls (e.g., https://srladvisor.example.com, or http://host.containers.internal:8000 for a container lab). Do not include a trailing slash.';
$string['settings_public_backend_url']     = 'SRL Advisor Public URL (browser-facing)';
$string['settings_public_backend_url_desc'] = 'Optional. The URL the student\'s browser uses to reach the SRL Advisor web application during launch redirects. Set this when the Moodle server reaches the backend over an internal hostname the browser cannot resolve (e.g., http://127.0.0.1:8000 in a local container lab). Falls back to the Backend URL when empty. Do not include a trailing slash.';
$string['settings_api_token']              = 'Organization API Token';
$string['settings_api_token_desc']         = 'The unique API token generated from the SRL Advisor Superadmin portal for this institution. This is used to sign the JWTs.';
$string['settings_enabled_course_ids']     = 'Enabled Course IDs';
$string['settings_enabled_course_ids_desc'] = 'A comma-separated list of Moodle Course IDs where the SRL Advisor link will be displayed (e.g., 2,3,7). All other courses will not show the link.';
$string['settings_insecure_ssl']           = 'Allow insecure SSL (dev only)';
$string['settings_insecure_ssl_desc']      = 'Disable peer and host SSL verification on the action-items API call. Use only when pointing at a dev backend with a self-signed certificate. Leave off in production.';

// Privacy API (null_provider — DEC-017).
$string['privacy:metadata'] = 'The SRL Advisor plugin does not store any personal data in Moodle. It computes ephemeral SHA-256 hashes of user IDs (using the site identifier as a salt) and forwards them to the SRL Advisor backend for the launch flow and for consent-gated data sync. Real Moodle user IDs are sent to the backend only as transient API parameters and are never persisted there.';

// Web service (DEC-017).
$string['srladvisor_sync_service'] = 'SRL Advisor Sync';

// DEC-031 v1.1 inline check-ins.
$string['srladvisor_inline_service']      = 'SRL Advisor Inline';
$string['inline_question_pre']            = 'Before you start this section, what strategy will you use?';
$string['inline_question_post']           = 'Now that you have finished this section, what strategy did you use?';
$string['inline_no_strategy_pre']         = "I haven't decided yet";
$string['inline_no_strategy_post']        = "I didn't have a clear strategy";
$string['inline_submit']                  = 'Save';
$string['inline_dismiss']                 = 'Not now';
$string['inline_thanks']                  = 'Thanks — your response was recorded.';
$string['inline_error_generic']           = 'We could not save your response. Please try again or open the portal.';
$string['inline_portal_fallback_link']    = 'Open SRL Advisor portal';
$string['inline_aria_panel']              = 'SRL Advisor check-in';
$string['inline_placeholder']             = 'Pick one…';

// DEC-032 end-of-course summative survey banner.
$string['summative_banner_heading'] = 'You\'ve finished the course!';
$string['summative_banner_cta']     = 'Please take a few minutes to complete the post-course survey. Your reflections help us improve the course for future students.';
$string['summative_banner_link']    = 'Open the post-course survey';
