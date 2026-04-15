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
$string['settings_backend_url']            = 'SRL Advisor Backend URL';
$string['settings_backend_url_desc']       = 'The base URL of the SRL Advisor web application (e.g., https://srladvisor.example.com). Do not include a trailing slash.';
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
