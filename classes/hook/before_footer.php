<?php
/**
 * SRL Advisor before-footer hook listener (Moodle 4.5+ hook API).
 *
 * Moodle 4.5 deprecated the legacy `local_srl_advisor_before_footer()`
 * callback in favour of the hook system. This class implements the new
 * listener; `db/hooks.php` registers it for the
 * `core\hook\output\before_footer_html_generation` hook.
 *
 * Body delegates to the existing legacy callback so the two code paths stay
 * in sync until we delete the legacy entry post-pilot.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_srl_advisor\hook;

defined('MOODLE_INTERNAL') || die();

class before_footer {
    public static function callback(\core\hook\output\before_footer_html_generation $hook): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/srl_advisor/lib.php');
        local_srl_advisor_render_before_footer();
    }
}
