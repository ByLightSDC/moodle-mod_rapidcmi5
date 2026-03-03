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

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');

/**
 * Manages deployment of cmi5 activities into Moodle courses.
 */
class deployment_manager {

    /**
     * Deploy or update a cmi5 activity in a course from a content library package.
     *
     * @param int $projectid RapidCMI5 project ID.
     * @param int $versionid RapidCMI5 version ID.
     * @param int $libraryversionid Content library version ID (cmi5_package_versions.id).
     * @param int $courseid Moodle course ID.
     * @param string $name Activity name.
     * @param int $sectionid Section number within course.
     * @return object Deployment record.
     */
    public static function deploy_to_course(int $projectid, int $versionid, int $libraryversionid,
            int $courseid, string $name, int $sectionid = 0): \stdClass {
        global $DB;

        // Verify course exists.
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        // Check for existing deployment of this project to this course.
        $existing = $DB->get_record('local_rapidcmi5_deployments', [
            'projectid' => $projectid,
            'courseid' => $courseid,
        ]);

        if ($existing) {
            // Update existing activity with new package version.
            $cmid = self::update_activity($existing->cmid, $libraryversionid, $name);
            $now = time();
            $existing->versionid = $versionid;
            $existing->timemodified = $now;
            $DB->update_record('local_rapidcmi5_deployments', $existing);

            // Fire deployment event.
            self::fire_deployed_event($existing->id, $courseid, $existing->cmid, $versionid);

            return $existing;
        }

        // Create new activity.
        $cmid = self::create_activity($courseid, $libraryversionid, $name, $sectionid);

        $now = time();
        $deployment = new \stdClass();
        $deployment->projectid = $projectid;
        $deployment->versionid = $versionid;
        $deployment->courseid = $courseid;
        $deployment->cmid = $cmid;
        $deployment->sectionid = $sectionid ?: null;
        $deployment->timecreated = $now;
        $deployment->timemodified = $now;
        $deployment->id = $DB->insert_record('local_rapidcmi5_deployments', $deployment);

        // Fire deployment event.
        self::fire_deployed_event($deployment->id, $courseid, $cmid, $versionid);

        return $deployment;
    }

    /**
     * Fire the package_deployed event.
     *
     * @param int $deploymentid Deployment record ID.
     * @param int $courseid Course ID.
     * @param int $cmid Course module ID.
     * @param int $versionid RapidCMI5 version ID.
     */
    private static function fire_deployed_event(int $deploymentid, int $courseid,
            int $cmid, int $versionid): void {
        $event = \local_rapidcmi5\event\package_deployed::create([
            'objectid' => $deploymentid,
            'context' => \context_course::instance($courseid),
            'courseid' => $courseid,
            'other' => [
                'cmid' => $cmid,
                'versionid' => $versionid,
            ],
        ]);
        $event->trigger();
    }

    /**
     * Create a new cmi5 activity module in a course.
     *
     * @param int $courseid
     * @param int $libraryversionid Content library version ID (cmi5_package_versions.id).
     * @param string $name Activity name.
     * @param int $section Section number.
     * @return int Course module ID.
     */
    private static function create_activity(int $courseid, int $libraryversionid, string $name,
            int $section = 0): int {
        global $DB;

        $course = get_course($courseid);
        $module = $DB->get_record('modules', ['name' => 'cmi5'], '*', MUST_EXIST);

        // Get the package ID from the library version.
        $libraryversion = $DB->get_record('cmi5_package_versions', ['id' => $libraryversionid], '*', MUST_EXIST);

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'cmi5';
        $moduleinfo->module = $module->id;
        $moduleinfo->name = $name;
        $moduleinfo->course = $courseid;
        $moduleinfo->section = $section;
        $moduleinfo->visible = 1;
        $moduleinfo->visibleoncoursepage = 1;

        // Set the library package source (field names must match what cmi5_add_instance expects).
        $moduleinfo->packagesource = 'library';
        $moduleinfo->packageid = $libraryversion->packageid;

        // Use Moodle's standard module creation.
        // cmi5_add_instance will resolve the latest version, set packageversionid,
        // copy AU structure, and increment usage count.
        $moduleinfo = add_moduleinfo($moduleinfo, $course);

        return $moduleinfo->coursemodule;
    }

    /**
     * Update an existing cmi5 activity with a new package version.
     *
     * @param int $cmid Course module ID.
     * @param int $libraryversionid New content library version ID (cmi5_package_versions.id).
     * @param string $name Updated activity name.
     * @return int Course module ID.
     */
    private static function update_activity(int $cmid, int $libraryversionid, string $name): int {
        global $DB;

        $cm = get_coursemodule_from_id('cmi5', $cmid, 0, false, MUST_EXIST);

        // Get the package ID from the library version.
        $libraryversion = $DB->get_record('cmi5_package_versions', ['id' => $libraryversionid], '*', MUST_EXIST);

        // Update the cmi5 instance record.
        $instance = $DB->get_record('cmi5', ['id' => $cm->instance], '*', MUST_EXIST);
        $instance->name = $name;
        $instance->packageid = $libraryversion->packageid;
        $instance->packageversionid = $libraryversionid;
        $instance->timemodified = time();
        $DB->update_record('cmi5', $instance);

        // Re-copy structure from the new package version.
        \mod_cmi5\content_library::copy_structure_to_activity($libraryversionid, $instance->id);

        return $cmid;
    }

    /**
     * Delete a deployed cmi5 activity.
     *
     * @param int $cmid Course module ID.
     * @param int $courseid Course ID.
     */
    public static function delete_activity(int $cmid, int $courseid): void {
        try {
            $course = get_course($courseid);
            course_delete_module($cmid);
        } catch (\Exception $e) {
            debugging("Could not delete activity cm:{$cmid}: " . $e->getMessage());
        }
    }
}
