<?php
/**
 * External function: record_behavior_events (LAB-002 / DEC-035).
 *
 * AMD modules (scroll_telemetry today; future video, clipboard, perf modules)
 * batch behavior events and POST them through this AJAX function. The function
 * mints a fresh JWT, forwards the batch to backend
 * `POST /api/v1/behavior-events`, and returns `{ok, accepted, skipped, error?}`.
 *
 * Capability gate matches the inline check-in pattern (DEC-062): the AJAX
 * caller's $courseid is re-checked server-side via
 * `has_capability('local/srl_advisor:participate', ...)` against $USER. AMD
 * must NOT forward an arbitrary courseid.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_srl_advisor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;

class record_behavior_events extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id; server-side enrolment-gated', VALUE_REQUIRED),
            // events arrives as a JSON string from AMD to dodge Moodle's external_api
            // limitations on nested-array-of-objects. Backend re-parses.
            'events' => new external_value(PARAM_RAW, 'JSON-encoded array of event objects matching the backend BehaviorEventIn schema', VALUE_REQUIRED),
        ]);
    }

    public static function execute(int $courseid, string $events): array {
        global $CFG, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'events' => $events,
        ]);

        // Decode the batch. Refuse malformed JSON or non-array at the boundary.
        $batch = json_decode($params['events'], true);
        if (!is_array($batch)) {
            return ['ok' => false, 'error' => 'invalid_payload', 'accepted' => 0, 'skipped' => 0];
        }

        $courseid = (int)$params['courseid'];
        if ($courseid <= 0) {
            return ['ok' => false, 'error' => 'invalid_courseid', 'accepted' => 0, 'skipped' => 0];
        }

        $context = context_course::instance($courseid);
        self::validate_context($context);
        if (!has_capability('local/srl_advisor:participate', $context, $USER)) {
            return ['ok' => false, 'error' => 'not_enrolled', 'accepted' => 0, 'skipped' => 0];
        }

        $backend_url = trim((string)get_config('local_srl_advisor', 'backend_url'));
        $api_token   = trim((string)get_config('local_srl_advisor', 'api_token'));
        if (empty($backend_url) || empty($api_token)) {
            return ['ok' => false, 'error' => 'plugin_not_configured', 'accepted' => 0, 'skipped' => 0];
        }

        require_once($CFG->dirroot . '/local/srl_advisor/lib.php');

        $pseudo = hash('sha256', $USER->id . $CFG->siteidentifier);
        $jwt = local_srl_advisor_build_jwt($courseid, $pseudo, $api_token);

        // Backend expects {events: [...]}.
        $result = local_srl_advisor_relay_backend_call(
            'behavior_ingest', '/api/v1/behavior-events', 'POST',
            ['events' => $batch], $jwt, 5
        );
        if (!$result['ok']) {
            return [
                'ok' => false,
                'error' => 'backend_' . ($result['error_kind'] ?? 'unknown'),
                'accepted' => 0,
                'skipped' => 0,
            ];
        }

        $data = $result['data'];
        return [
            'ok' => true,
            'error' => '',
            'accepted' => (int)($data['accepted'] ?? 0),
            'skipped' => (int)($data['skipped'] ?? 0),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok'        => new external_value(PARAM_BOOL, 'True on success'),
            'error'     => new external_value(PARAM_TEXT, 'Short error tag; empty on success'),
            'accepted'  => new external_value(PARAM_INT, 'Count of events written to L1'),
            'skipped'   => new external_value(PARAM_INT, 'Count of events de-duplicated by idempotency_key'),
        ]);
    }
}
