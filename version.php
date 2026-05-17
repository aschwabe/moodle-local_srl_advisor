<?php
/**
 * SRL Advisor local plugin version information.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026051709;   // DEC-048 follow-up: bootstrap tooltip on top-right navbar icon (data-toggle/data-bs-toggle).
$plugin->requires  = 2024100700;   // Requires Moodle 4.5+ (DEC-031).
$plugin->component = 'local_srl_advisor';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = 'v0.4.0-alpha';
