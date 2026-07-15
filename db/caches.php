<?php
/**
 * Cache definitions for the SRL Advisor local plugin.
 *
 * `consent_status` — MUC application cache backing the telemetry-injection
 * consent gate in lib.php (`local_srl_advisor_student_has_consented`). Keyed
 * by the pseudonymous participant id (sha256(user_id . site_identifier)); the
 * value is 1 (consented) or 0 (not), sourced from backend
 * `GET /api/v1/consent-status/{pseudo_id}`. Short TTL keeps latency off page
 * loads while bounding how long a stale consent decision can linger. This is
 * a transient cache only — the plugin remains null-storage from Moodle's DB
 * perspective (nothing consent-related is written to a plugin table).
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'consent_status' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'ttl'        => 300, // 5 minutes.
    ],
];
