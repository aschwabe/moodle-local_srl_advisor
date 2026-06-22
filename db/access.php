<?php
/**
 * Capability definitions for the SRL Advisor local plugin (DEC-062).
 *
 * Supersedes the DEC-047/049 enrolment-gate deviation. `local/srl_advisor:participate`
 * is the access gate for student-facing check-ins and action items. Granted to the
 * `student` archetype by default, so enrolled students get it automatically; an admin
 * can grant it to a non-enrolled researcher/auditor via the role-permission UI without
 * enrolling them (the use case that drove this off the deferral list).
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    'local/srl_advisor:participate' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'student' => CAP_ALLOW,
        ],
    ],
];
