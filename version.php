<?php
/**
 * SRL Advisor local plugin version information.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026051201;   // LAB-001: entry + per-return debugging() in extend_navigation_course to surface why badge not visible.
$plugin->requires  = 2022112800;   // Requires Moodle 4.1+.
$plugin->component = 'local_srl_advisor';
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = 'v0.2.0-alpha';
