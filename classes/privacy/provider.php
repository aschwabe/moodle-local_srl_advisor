<?php
/**
 * Privacy API provider for the SRL Advisor local plugin (DEC-017).
 *
 * The plugin stores no personal data in the Moodle database. It computes
 * ephemeral SHA-256 hashes of user ids at request time (launch flow + sync
 * web service) and forwards them to the SRL Advisor backend. Raw Moodle
 * user ids are sent as transient API parameters and never persisted by
 * SRL Advisor. null_provider is the correct declaration.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_srl_advisor\privacy;

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\null_provider;

class provider implements null_provider {
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
