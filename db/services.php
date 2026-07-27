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
        'description'   => 'Returns enrolled active students in a course as {pseudo_id, moodle_user_id} pairs. ' .
            'pseudo_id is sha256(user_id . site_identifier) — same scheme as the launch flow. ' .
            'Used by SRL Advisor consent-gated data sync.',
        'type'          => 'read',
        'capabilities'  => 'moodle/course:viewparticipants',
        'ajax'          => false,
        'loginrequired' => true,
    ],

    // DEC-031 v1.1 inline check-ins — AJAX, enrolment-gated (no custom capability).
    'local_srl_advisor_get_pending_check_in' => [
        'classname'     => 'local_srl_advisor\\external\\get_pending_check_in',
        'methodname'    => 'execute',
        'description'   => 'Returns the pending unit check-in payload (or empty struct) for the current user, course and section.',
        'type'          => 'read',
        'ajax'          => true,
        'loginrequired' => true,
    ],
    'local_srl_advisor_submit_check_in' => [
        'classname'     => 'local_srl_advisor\\external\\submit_check_in',
        'methodname'    => 'execute',
        'description'   => 'Submits the inline check-in choice (strategy or no_strategy). ' .
            'Requires a client-generated Idempotency-Key to suppress duplicate POSTs on retry.',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
    ],
    'local_srl_advisor_dismiss_check_in' => [
        'classname'     => 'local_srl_advisor\\external\\dismiss_check_in',
        'methodname'    => 'execute',
        'description'   => 'Records an inline-panel dismissal. Does NOT complete the underlying task; ' .
            'the nav badge keeps surfacing it as pending work.',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
    ],

    // LAB-002 / DEC-035: behavior-event L1 batch relay. AMD modules POST
    // batches of {verb, occurred_at, payload, ...} events through this AJAX
    // function; the function builds a fresh JWT and forwards to backend
    // POST /api/v1/behavior-events.
    'local_srl_advisor_record_behavior_events' => [
        'classname'     => 'local_srl_advisor\\external\\record_behavior_events',
        'methodname'    => 'execute',
        'description'   => 'Batched behavior-event relay (scroll/video/clipboard/perf). Forwards to backend L1 ingest endpoint.',
        'type'          => 'write',
        'ajax'          => true,
        'loginrequired' => true,
    ],
];

$services = [
    'SRL Advisor Sync' => [
        'functions'        => [
            'local_srl_advisor_get_enrolled_hashed_users',
            'gradereport_user_get_grade_items',
            'core_completion_get_activities_completion_status',
            // DEC-032: course-level completion trigger for the summative survey.
            // Sync orchestrator calls this only when both org + course
            // `summative_survey_enabled` are true.
            'core_completion_get_course_completion_status',
        ],
        'restrictedusers'  => 1,
        'enabled'          => 1,
        'shortname'        => 'local_srl_advisor_sync',
        'downloadfiles'    => 0,
        'uploadfiles'      => 0,
    ],
    // DEC-031 Q3 resolution: NEW service, separate auth posture from sync.
    // restrictedusers=0 + loginrequired=true means any logged-in Moodle user
    // (enrolment is checked inside each function), not a webservice-token user.
    'SRL Advisor Inline' => [
        'functions'        => [
            'local_srl_advisor_get_pending_check_in',
            'local_srl_advisor_submit_check_in',
            'local_srl_advisor_dismiss_check_in',
            'local_srl_advisor_record_behavior_events',
        ],
        'restrictedusers'  => 0,
        'enabled'          => 1,
        'shortname'        => 'local_srl_advisor_inline',
        'downloadfiles'    => 0,
        'uploadfiles'      => 0,
    ],
];
