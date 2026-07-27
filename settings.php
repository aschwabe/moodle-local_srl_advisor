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
 * Admin settings for the SRL Advisor plugin.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_srl_advisor', get_string('pluginname', 'local_srl_advisor'));

    // Backend URL: The base URL of the SRL Advisor FastAPI web application,
    // as reachable from the Moodle server (server-to-server curl calls).
    $settings->add(new admin_setting_configtext(
        'local_srl_advisor/backend_url',
        get_string('settings_backend_url', 'local_srl_advisor'),
        get_string('settings_backend_url_desc', 'local_srl_advisor'),
        'https://srladvisor.example.com',
        PARAM_URL
    ));

    // Public Backend URL: The browser-facing URL used for the launch redirect.
    // Required when Moodle runs in a container that reaches the backend via an
    // internal hostname the student's browser cannot resolve. Leave empty if the
    // backend is reachable at the same address from both the Moodle host and the
    // student's browser.
    $settings->add(new admin_setting_configtext(
        'local_srl_advisor/public_backend_url',
        get_string('settings_public_backend_url', 'local_srl_advisor'),
        get_string('settings_public_backend_url_desc', 'local_srl_advisor'),
        '',
        PARAM_URL
    ));

    // API Token: The JWT organization token generated from the SRL Advisor Superadmin portal.
    $settings->add(new admin_setting_configpasswordunmask(
        'local_srl_advisor/api_token',
        get_string('settings_api_token', 'local_srl_advisor'),
        get_string('settings_api_token_desc', 'local_srl_advisor'),
        ''
    ));

    // Enabled Course IDs: Comma-separated list of Moodle course IDs where the SRL Advisor link is displayed.
    $settings->add(new admin_setting_configtext(
        'local_srl_advisor/enabled_course_ids',
        get_string('settings_enabled_course_ids', 'local_srl_advisor'),
        get_string('settings_enabled_course_ids_desc', 'local_srl_advisor'),
        '',
        PARAM_TEXT
    ));

    // Insecure SSL: Disable peer/host verification for the backend call.
    // Intended for dev/self-signed backends. Leave off in production.
    $settings->add(new admin_setting_configcheckbox(
        'local_srl_advisor/insecure_ssl',
        get_string('settings_insecure_ssl', 'local_srl_advisor'),
        get_string('settings_insecure_ssl_desc', 'local_srl_advisor'),
        0
    ));

    $ADMIN->add('localplugins', $settings);
}
