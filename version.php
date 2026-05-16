<?php
/**
 * SRL Advisor local plugin version information.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026051601;   // DEC-032: summative banner on course-view-* + mod-page-view; new lang strings + Mustache template.
$plugin->requires  = 2024100700;   // Requires Moodle 4.5+ (DEC-031).
$plugin->component = 'local_srl_advisor';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = 'v0.4.0-alpha';
