<?php
/**
 * External function: get_pending_check_in (DEC-031 v1.1 inline check-ins).
 *
 * AMD module calls this for each in-scope page view. Returns the pending
 * unit check-in payload (or empty) for the (current user, course, section)
 * triple. JSON shape mirrors the GET /api/v1/check-in backend contract.
 *
 * Capability: `local/srl_advisor:participate` in $courseid's context (DEC-062,
 * supersedes the DEC-031 BLOCKER #2 `is_enrolled` gate).
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_srl_advisor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use context_course;

class get_pending_check_in extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'  => new external_value(PARAM_INT, 'Moodle course id', VALUE_REQUIRED),
            'sectionid' => new external_value(PARAM_INT, 'Moodle course_sections.id (NOT sectionnum)', VALUE_REQUIRED),
        ]);
    }

    public static function execute(int $courseid, int $sectionid): array {
        global $CFG, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'  => $courseid,
            'sectionid' => $sectionid,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);

        // Capability gate (DEC-062, supersedes BLOCKER #2). Defends against forged
        // courseid in the AJAX payload — the participate cap is resolved against
        // THIS course's context, so a forged courseid the user has no role in
        // yields no capability and is rejected.
        if (!has_capability('local/srl_advisor:participate', $context, $USER)) {
            return self::empty_payload();
        }

        $backend_url = trim((string)get_config('local_srl_advisor', 'backend_url'));
        $api_token   = trim((string)get_config('local_srl_advisor', 'api_token'));
        if (empty($backend_url) || empty($api_token)) {
            debugging('local_srl_advisor[inline_get]: plugin not configured', DEBUG_DEVELOPER);
            return self::empty_payload();
        }

        require_once($CFG->dirroot . '/local/srl_advisor/lib.php');

        // DEC-048 follow-up: look up the section's human name so the backend
        // can stamp portal task labels with the unit name. Falls back to
        // "Topic N" using `sectionnum` if the operator left `name` blank.
        global $DB;
        $section_label = null;
        $section_row = $DB->get_record('course_sections', ['id' => $params['sectionid']], 'name,section');
        if ($section_row) {
            $candidate = isset($section_row->name) ? trim((string)$section_row->name) : '';
            if ($candidate === '' && isset($section_row->section)) {
                $candidate = get_string('section') . ' ' . (int)$section_row->section;
            }
            $section_label = ($candidate !== '') ? $candidate : null;
        }

        $pseudo = hash('sha256', $USER->id . $CFG->siteidentifier);
        $jwt = local_srl_advisor_build_jwt(
            $params['courseid'],
            $pseudo,
            $api_token,
            30,
            $params['sectionid'],
            $section_label
        );
        $path = '/api/v1/check-in?section_id=' . $params['sectionid'];

        $result = local_srl_advisor_relay_backend_call(
            'inline_get', $path, 'GET', null, $jwt
        );
        if (!$result['ok']) {
            return self::empty_payload();
        }

        $data = $result['data'];
        if (!is_array($data) || empty($data['task'])) {
            return self::empty_payload();
        }

        $task = $data['task'];
        $options = [];
        foreach (($task['options'] ?? []) as $opt) {
            $kind = (string)($opt['kind'] ?? '');
            $options[] = [
                'kind'            => $kind,
                'strategy_id'     => isset($opt['strategy_id']) ? (int)$opt['strategy_id'] : 0,
                'name'            => (string)($opt['name'] ?? ''),
                'description'     => (string)($opt['description'] ?? ''),
                'label'           => (string)($opt['label'] ?? ''),
                'is_no_strategy'  => ($kind === 'no_strategy'),
                'is_other'        => ($kind === 'other'),
            ];
        }

        return [
            'has_task'              => true,
            'task_id'               => (int)($task['id'] ?? 0),
            'task_type'             => (string)($task['type'] ?? ''),
            'is_pre'                => (bool)($task['is_pre'] ?? false),
            'label'                 => (string)($task['label'] ?? ''),
            'section_id'            => (int)($task['section_id'] ?? 0),
            'options'               => $options,
            'previous_strategy_id'  => isset($task['previous_strategy_id']) ? (int)$task['previous_strategy_id'] : 0,
            'render_started_at_ms'  => (int)($task['render_started_at_ms'] ?? 0),
        ];
    }

    private static function empty_payload(): array {
        return [
            'has_task'              => false,
            'task_id'               => 0,
            'task_type'             => '',
            'is_pre'                => false,
            'label'                 => '',
            'section_id'            => 0,
            'options'               => [],
            'previous_strategy_id'  => 0,
            'render_started_at_ms'  => 0,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'has_task'             => new external_value(PARAM_BOOL, 'True when a panel should render'),
            'task_id'              => new external_value(PARAM_INT, 'Task id, 0 when has_task=false'),
            'task_type'            => new external_value(PARAM_TEXT, 'unit_pre | unit_post | empty when has_task=false'),
            'is_pre'               => new external_value(PARAM_BOOL, 'True for forethought (pre) tasks'),
            'label'                => new external_value(PARAM_TEXT, 'Panel heading'),
            'section_id'           => new external_value(PARAM_INT, 'Moodle section id this task is scoped to'),
            'options'              => new external_multiple_structure(
                new external_single_structure([
                    'kind'            => new external_value(PARAM_TEXT, 'strategy | no_strategy | other'),
                    'strategy_id'     => new external_value(PARAM_INT, '0 when kind=no_strategy or kind=other'),
                    'name'            => new external_value(PARAM_TEXT, 'Strategy name (kind=strategy)'),
                    'description'     => new external_value(PARAM_TEXT, 'Student-facing description (kind=strategy)'),
                    'label'           => new external_value(PARAM_TEXT, 'No-strategy/Other label (kind=no_strategy|other)'),
                    'is_no_strategy'  => new external_value(PARAM_BOOL, 'True when kind=no_strategy; drives the Mustache branch'),
                    'is_other'        => new external_value(PARAM_BOOL, 'True when kind=other; drives the Mustache branch + AMD text-input reveal'),
                ])
            ),
            'previous_strategy_id' => new external_value(PARAM_INT, 'Strategy_id from this participant\'s pre answer in the same section (0 = none / no_strategy)'),
            'render_started_at_ms' => new external_value(PARAM_INT, 'Server-stamped epoch ms; AMD subtracts to compute response_time_ms'),
        ]);
    }
}
