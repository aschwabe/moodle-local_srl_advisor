<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

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

/**
 * Hook listener class for the before-footer HTML generation hook.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class before_footer {
    /**
     * Inject SRL Advisor AMD modules before the Moodle page footer.
     *
     * @param \core\hook\output\before_footer_html_generation $hook The hook instance.
     */
    public static function callback(\core\hook\output\before_footer_html_generation $hook): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/srl_advisor/lib.php');
        local_srl_advisor_render_before_footer();
    }
}
