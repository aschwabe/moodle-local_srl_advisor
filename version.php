<?php
/**
 * SRL Advisor local plugin version information.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026051600;   // DEC-031 v1.1 inline check-ins: 3 new AJAX external fns + local_srl_advisor_inline service + shared relay helper + JWT TTL 30s.
$plugin->requires  = 2024100700;   // Requires Moodle 4.5+ (DEC-031: bumped to match actual test target across institutions).
$plugin->component = 'local_srl_advisor';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = 'v0.3.0-alpha';
