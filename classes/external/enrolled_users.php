<?php
/**
 * External function: get_enrolled_hashed_users (DEC-017).
 *
 * Enumerates active enrolled users for a course and returns SHA-256 hashes
 * of the user ids (salted with the Moodle siteidentifier) paired with the
 * raw user id. The hash matches the scheme used by launch.php and lib.php
 * so SRL Advisor can match each pair against an existing tbl_participant
 * row before invoking any per-user data fetch.
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

class enrolled_users extends external_api {

    public static function get_enrolled_hashed_users_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Moodle course id', VALUE_REQUIRED),
        ]);
    }

    /**
     * Return enrolled users for $courseid as [{pseudo_id, moodle_user_id}, ...].
     *
     * Pulls only u.id from get_enrolled_users() — no names, emails, or other
     * profile fields are read, so the call cannot accidentally leak PII.
     */
    public static function get_enrolled_hashed_users(int $courseid): array {
        global $CFG;

        $params = self::validate_parameters(
            self::get_enrolled_hashed_users_parameters(),
            ['courseid' => $courseid]
        );

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('moodle/course:viewparticipants', $context);

        // Args: $context, $withcapability='', $groupid=0, $userfields='u.id',
        //       $orderby=null, $limitfrom=0, $limitnum=0, $onlyactive=true.
        $users = get_enrolled_users($context, '', 0, 'u.id', null, 0, 0, true);

        $out = [];
        foreach ($users as $u) {
            $out[] = [
                'pseudo_id'      => hash('sha256', $u->id . $CFG->siteidentifier),
                'moodle_user_id' => (int) $u->id,
            ];
        }
        return $out;
    }

    public static function get_enrolled_hashed_users_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'pseudo_id'      => new external_value(PARAM_ALPHANUM, 'SHA-256 hex of user.id . siteidentifier'),
                'moodle_user_id' => new external_value(PARAM_INT, 'Raw Moodle user id (used only for downstream API calls; never persisted by SRL Advisor)'),
            ])
        );
    }
}
