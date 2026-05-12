<?php
/**
 * Web service declarations for the SRL Advisor local plugin (DEC-017).
 *
 * Declares the custom function used by SRL Advisor's data sync orchestrator
 * (see lib/services/sync_orchestrator.py and lib/services/moodle_client.py
 * in the SRL Advisor backend) to enumerate enrolled users for a course as
 * pseudonymous SHA-256 hashes paired with their raw Moodle user ids. The
 * raw ids are used by the backend only as transient API parameters for the
 * grades and completion fetches; nothing is persisted on the SRL Advisor
 * side beyond the hash that already exists in tbl_participant.moodle_user_id.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_srl_advisor_get_enrolled_hashed_users' => [
        'classname'     => 'local_srl_advisor\\external\\enrolled_users',
        'methodname'    => 'get_enrolled_hashed_users',
        'description'   => 'Returns enrolled active students in a course as {pseudo_id, moodle_user_id} pairs. pseudo_id is sha256(user_id . site_identifier) — the same scheme used by the launch flow. Used by SRL Advisor consent-gated data sync.',
        'type'          => 'read',
        'capabilities'  => 'moodle/course:viewparticipants',
        'ajax'          => false,
        'loginrequired' => true,
    ],
];

$services = [
    'SRL Advisor Sync' => [
        'functions'        => [
            'local_srl_advisor_get_enrolled_hashed_users',
            'gradereport_user_get_grade_items',
            'core_completion_get_activities_completion_status',
        ],
        'restrictedusers'  => 1,
        'enabled'          => 1,
        'shortname'        => 'local_srl_advisor_sync',
        'downloadfiles'    => 0,
        'uploadfiles'      => 0,
    ],
];
