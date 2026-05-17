<?php
/**
 * SRL Advisor hook registry (Moodle 4.5+ hook API).
 *
 * Moodle 4.5 deprecated several plugin-callback functions (e.g.
 * `local_*_before_footer`) in favour of the hook system. Register listener
 * classes here so they fire without producing developer-debug deprecation
 * notices.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook'     => \core\hook\output\before_footer_html_generation::class,
        'callback' => [\local_srl_advisor\hook\before_footer::class, 'callback'],
    ],
];
