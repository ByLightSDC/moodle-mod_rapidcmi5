<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_rapidcmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observers that keep deployment records in step with cmi5 activities.
 */
class observer {

    /**
     * Track cmi5 activities created or changed outside RapidCMI5, e.g. from the mod_cmi5 activity library.
     *
     * Never interrupts saving the activity: a failure only means the activity stays unmanaged.
     *
     * @param \core\event\course_module_created|\core\event\course_module_updated $event
     */
    public static function course_module_saved(\core\event\base $event): void {
        if (($event->other['modulename'] ?? '') !== 'cmi5') {
            return;
        }
        try {
            deployment_manager::link_library_activity((int) $event->objectid);
        } catch (\Throwable $e) {
            debugging('RapidCMI5 could not track activity ' . $event->objectid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Handle course module deletion.
     *
     * When a cmi5 activity is deleted (via course UI, recycle bin, etc.),
     * remove the corresponding deployment record so it doesn't become orphaned.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;

        $data = $event->get_data();
        if (($data['other']['modulename'] ?? '') !== 'cmi5') {
            return;
        }

        $cmid = (int) $data['objectid'];
        $DB->delete_records('local_rapidcmi5_deployments', ['cmid' => $cmid]);
    }
}
