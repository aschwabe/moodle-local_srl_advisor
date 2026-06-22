<?php
/**
 * Uninstall hook for the SRL Advisor local plugin (DEC-062).
 *
 * Removes the plugin's custom capability rows so an uninstall leaves no orphan
 * entries in mdl_capabilities / mdl_role_capabilities. Moodle core also cleans
 * capabilities on uninstall, but declaring this explicitly makes the lifecycle
 * auditable (per DEC-047 §"capability rows persist across upgrade/uninstall").
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Plugin uninstall cleanup.
 *
 * @return bool
 */
function xmldb_local_srl_advisor_uninstall() {
    capabilities_cleanup('local_srl_advisor');
    return true;
}
