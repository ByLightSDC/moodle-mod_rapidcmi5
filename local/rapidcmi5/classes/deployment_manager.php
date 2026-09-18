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
     * Move a deployed activity to another version of its project, keeping its name and learner progress.
     *
     * AUs are updated in place by IRI (see content_library::copy_structure_to_activity()), so learner
     * records stay attached.
     *
     * @param int $deploymentid Deployment ID.
     * @param int $versionid Project version to move the activity to.
     * @return \stdClass The updated deployment.
     * @throws \moodle_exception error:libraryrevisionmissing when the version's library revision cannot be found.
     */
    public static function update_deployment(int $deploymentid, int $versionid): \stdClass {
        global $DB;
        $deployment = $DB->get_record('local_rapidcmi5_deployments', ['id' => $deploymentid], '*', MUST_EXIST);
        $version = $DB->get_record('local_rapidcmi5_versions',
            ['id' => $versionid, 'projectid' => $deployment->projectid], '*', MUST_EXIST);
        $revision = project_manager::get_library_version($version);
        if (!$revision) {
            throw new \moodle_exception('error:libraryrevisionmissing', 'local_rapidcmi5');
        }
        $transaction = $DB->start_delegated_transaction();
        self::update_activity((int) $deployment->cmid, (int) $revision->id);
        $deployment->versionid = $version->id;
        $deployment->timemodified = time();
        $DB->update_record('local_rapidcmi5_deployments', $deployment);
        $transaction->allow_commit();
        self::fire_deployed_event((int) $deployment->id, (int) $deployment->courseid, (int) $deployment->cmid,
            (int) $version->id);
        return $deployment;
    }

    /**
     * Track a cmi5 activity added outside RapidCMI5, such as from the mod_cmi5 activity library.
     *
     * The activity is recorded as a deployment when its library revision belongs to exactly one project
     * version and the project has no other managed activity in the course. An activity that is already
     * tracked follows its revision to another version of the same project.
     *
     * @param int $cmid Course module ID.
     * @return \stdClass|null The deployment, or null when the activity is not tracked.
     */
    public static function link_library_activity(int $cmid): ?\stdClass {
        global $DB;
        $cm = get_coursemodule_from_id('cmi5', $cmid, 0, false, IGNORE_MISSING);
        $revisionid = $cm ? (int) $DB->get_field('cmi5', 'packageversionid', ['id' => $cm->instance]) : 0;
        if (!$revisionid) {
            return null;
        }
        $versions = $DB->get_records('local_rapidcmi5_versions', ['libraryversionid' => $revisionid],
            'id ASC', 'id, projectid', 0, 2);
        if (count($versions) !== 1) {
            return null;
        }
        $version = reset($versions);
        $now = time();
        $existing = $DB->get_record('local_rapidcmi5_deployments', ['cmid' => $cmid]);
        if ($existing) {
            if ((int) $existing->projectid === (int) $version->projectid && (int) $existing->versionid !== (int) $version->id) {
                $existing->versionid = $version->id;
                $existing->timemodified = $now;
                $DB->update_record('local_rapidcmi5_deployments', $existing);
            }
            return $existing;
        }
        if ($DB->record_exists('local_rapidcmi5_deployments', ['projectid' => $version->projectid, 'courseid' => $cm->course])) {
            // One managed activity per project per course, as for adoption.
            return null;
        }
        $deployment = (object) ['projectid' => $version->projectid, 'versionid' => $version->id,
            'courseid' => $cm->course, 'cmid' => $cmid,
            'sectionid' => $DB->get_field('course_sections', 'section', ['id' => $cm->section]),
            'timecreated' => $now, 'timemodified' => $now];
        $deployment->id = $DB->insert_record('local_rapidcmi5_deployments', $deployment);
        return $deployment;
    }

    /**
     * Deploy one version to several courses, reporting each course so one failure does not stop the rest.
     *
     * @param int $projectid Project ID.
     * @param int $versionid Project version ID.
     * @param int $libraryversionid Content library revision ID.
     * @param int[] $courseids Courses to deploy to; repeated IDs are deployed once.
     * @param string $name Activity name.
     * @param int $sectionid Section number for new activities.
     * @return array[] One entry per course: courseid, cmid (0 on error), status ('success' or 'error') and message.
     */
    public static function deploy_to_courses(int $projectid, int $versionid, int $libraryversionid,
            array $courseids, string $name, int $sectionid = 0): array {
        $results = [];
        foreach (array_unique(array_map('intval', $courseids)) as $courseid) {
            try {
                $deployment = self::deploy_to_course($projectid, $versionid, $libraryversionid, $courseid,
                    $name, $sectionid);
                $results[] = ['courseid' => $courseid, 'cmid' => (int) $deployment->cmid,
                    'status' => 'success', 'message' => ''];
            } catch (\Exception $e) {
                $results[] = ['courseid' => $courseid, 'cmid' => 0, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }
        return $results;
    }

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
        // Creating the activity fires course_module_created, which may already have recorded it
        // (see link_library_activity()), so complete that record rather than adding a second one.
        $deployment = $DB->get_record('local_rapidcmi5_deployments', ['cmid' => $cmid]);
        if ($deployment) {
            $deployment->projectid = $projectid;
            $deployment->versionid = $versionid;
            $deployment->timemodified = $now;
            $DB->update_record('local_rapidcmi5_deployments', $deployment);
        } else {
            $deployment = new \stdClass();
            $deployment->projectid = $projectid;
            $deployment->versionid = $versionid;
            $deployment->courseid = $courseid;
            $deployment->cmid = $cmid;
            $deployment->sectionid = $sectionid ?: null;
            $deployment->timecreated = $now;
            $deployment->timemodified = $now;
            $deployment->id = $DB->insert_record('local_rapidcmi5_deployments', $deployment);
        }

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
        $moduleinfo->profileid = $libraryversion->profileid ?? 0;

        // Use Moodle's standard module creation.
        // cmi5_add_instance will resolve the latest version, set packageversionid,
        // copy AU structure, and increment usage count.
        $moduleinfo = add_moduleinfo($moduleinfo, $course);
        // The module defaults to the library's latest revision; honor the requested project revision.
        if ((int) $DB->get_field('cmi5', 'packageversionid', ['id' => $moduleinfo->instance]) !== $libraryversionid) {
            \mod_cmi5\content_library::sync_activity_to_version($moduleinfo->instance, $libraryversionid);
        }

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
    private static function update_activity(int $cmid, int $libraryversionid, ?string $name = null): int {
        global $DB;

        $cm = get_coursemodule_from_id('cmi5', $cmid, 0, false, MUST_EXIST);

        // Get the package ID from the library version.
        $libraryversion = $DB->get_record('cmi5_package_versions', ['id' => $libraryversionid], '*', MUST_EXIST);

        // Update the cmi5 instance record.
        $instance = $DB->get_record('cmi5', ['id' => $cm->instance], '*', MUST_EXIST);
        if ($name !== null) {
            $instance->name = $name;
        }
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
