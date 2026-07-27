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
 * Language strings for the SRL Advisor local plugin.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['inline_aria_panel'] = 'SRL Advisor check-in';
$string['inline_dismiss'] = 'Not now';
$string['inline_error_generic'] = 'We could not save your response. Please try again or open the portal.';
$string['inline_no_strategy_post'] = "I didn't have a clear strategy";
$string['inline_no_strategy_pre'] = "I haven't decided yet";
$string['inline_other_label'] = 'Tell us briefly which strategy you have in mind';
$string['inline_other_placeholder'] = 'In your own words (max 200 chars)…';
$string['inline_other_required'] = 'Please describe the strategy before saving.';
$string['inline_placeholder'] = 'Pick one…';
$string['inline_portal_fallback_link'] = 'Read more about learning strategies on SRL Advisor';
$string['inline_question_post'] = 'Now that you have finished this section, what strategy did you use?';
$string['inline_question_pre'] = 'Before you start this section, what strategy will you use?';
$string['inline_submit'] = 'Save';
$string['inline_thanks'] = 'Thanks — your response was recorded.';
$string['nav_link'] = 'SRL Advisor';
$string['navbar_tooltip_no_pending'] = 'SRL Advisor — your learning-strategy companion';
$string['navbar_tooltip_with_pending'] = 'SRL Advisor — {$a} pending check-in(s)';
$string['pluginname'] = 'SRL Advisor';
$string['privacy:metadata:srl_advisor_backend'] = 'To deliver self-regulated-learning support, the plugin sends a limited set of pseudonymised data to the SRL Advisor backend service. No directly identifying personal data (name, email) is ever transmitted.';
$string['privacy:metadata:srl_advisor_backend:behaviorevents'] = 'Behavioural telemetry within course activities (e.g. page scroll, video, clipboard and resource-download events) used to surface learning-strategy prompts.';
$string['privacy:metadata:srl_advisor_backend:courseid'] = 'The Moodle course ID, used to scope your learning data and action items to the correct course.';
$string['privacy:metadata:srl_advisor_backend:strategychoice'] = 'Your learning-strategy selections and short free-text reflections submitted in pre/post check-ins.';
$string['privacy:metadata:srl_advisor_backend:useridhash'] = 'A salted SHA-256 hash of your Moodle user ID, used so the backend can recognise your activity across sessions without storing your real identity.';
$string['settings_api_token'] = 'Organization API Token';
$string['settings_api_token_desc'] = 'The unique API token generated from the SRL Advisor Superadmin portal for this institution. This is used to sign the JWTs.';
$string['settings_backend_url'] = 'SRL Advisor Backend URL (server-to-server)';
$string['settings_backend_url_desc'] = 'The base URL the Moodle server uses to reach the SRL Advisor web application for API calls (e.g., https://srladvisor.example.com, or http://host.containers.internal:8000 for a container lab). Do not include a trailing slash.';
$string['settings_enabled_course_ids'] = 'Enabled Course IDs';
$string['settings_enabled_course_ids_desc'] = 'A comma-separated list of Moodle Course IDs where the SRL Advisor link will be displayed (e.g., 2,3,7). All other courses will not show the link.';
$string['settings_insecure_ssl'] = 'Allow insecure SSL (dev only)';
$string['settings_insecure_ssl_desc'] = 'Disable peer and host SSL verification on the action-items API call. Use only when pointing at a dev backend with a self-signed certificate. Leave off in production.';
$string['settings_public_backend_url'] = 'SRL Advisor Public URL (browser-facing)';
$string['settings_public_backend_url_desc'] = 'Optional. The URL the student\'s browser uses to reach the SRL Advisor web application during launch redirects. Set this when the Moodle server reaches the backend over an internal hostname the browser cannot resolve (e.g., http://127.0.0.1:8000 in a local container lab). Falls back to the Backend URL when empty. Do not include a trailing slash.';
$string['srl_advisor:participate'] = 'Participate in SRL Advisor learning-strategy check-ins and view personalised action items';
$string['srladvisor_inline_service'] = 'SRL Advisor Inline';
$string['srladvisor_sync_service'] = 'SRL Advisor Sync';
$string['summative_banner_cta'] = 'Please take a few minutes to complete the post-course survey. Your reflections help us improve the course for future students.';
$string['summative_banner_heading'] = 'You\'ve finished the course!';
$string['summative_banner_link'] = 'Open the post-course survey';
