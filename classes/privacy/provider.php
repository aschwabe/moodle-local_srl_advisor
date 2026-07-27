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
 * Privacy API provider for the SRL Advisor local plugin (DEC-017, amended DEC-062).
 *
 * The plugin stores NO personal data in the Moodle database. However, it
 * transmits user-related data to an EXTERNAL service (the SRL Advisor backend):
 * a salted SHA-256 hash of the Moodle user id, the course id, behavioural
 * telemetry events, and learning-strategy / reflection responses. Moodle's
 * Privacy API requires that any plugin sending data to an external location
 * declare a metadata provider with add_external_location_link() — null_provider
 * is ONLY valid when the plugin neither stores nor transmits personal data.
 *
 * Grounded against https://moodledev.io/docs/4.5/apis/subsystems/privacy
 * ("Many plugins will interact with external systems ... use
 * add_external_location_link()"). See L032 (no-assumptions rule).
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_srl_advisor\privacy;

use core_privacy\local\metadata\collection;

/**
 * Privacy metadata provider for the SRL Advisor local plugin.
 *
 * @package    local_srl_advisor
 * @copyright  2026 Andrew Schwabe
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\provider {
    /**
     * Describe the user data transmitted to the external SRL Advisor backend.
     *
     * @param collection $collection the metadata collection to add to.
     * @return collection the updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link(
            'srl_advisor_backend',
            [
                'useridhash'     => 'privacy:metadata:srl_advisor_backend:useridhash',
                'courseid'       => 'privacy:metadata:srl_advisor_backend:courseid',
                'behaviorevents' => 'privacy:metadata:srl_advisor_backend:behaviorevents',
                'strategychoice' => 'privacy:metadata:srl_advisor_backend:strategychoice',
            ],
            'privacy:metadata:srl_advisor_backend'
        );
        return $collection;
    }
}
