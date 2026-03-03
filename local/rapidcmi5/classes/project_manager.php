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
 * Manages CRUD for RapidCMI5 projects and versions.
 */
class project_manager {

    /**
     * Get or create a project by its stable identifier.
     *
     * @param string $identifier Stable project ID (courseId IRI from cmi5.xml).
     * @param string $name Display name (used for creation or update).
     * @param string $gitrepourl Optional git repo URL.
     * @param string $description Optional description.
     * @return object The project record and whether it was newly created.
     */
    public static function get_or_create_project(string $identifier, string $name = '',
            string $gitrepourl = '', string $description = ''): \stdClass {
        global $DB, $USER;

        $project = $DB->get_record('local_rapidcmi5_projects', ['identifier' => $identifier]);
        $isnew = false;

        if ($project) {
            // Update mutable fields if provided.
            $updated = false;
            if (!empty($name) && $name !== $project->name) {
                $project->name = $name;
                $updated = true;
            }
            if (!empty($gitrepourl) && $gitrepourl !== $project->gitrepourl) {
                $project->gitrepourl = $gitrepourl;
                $updated = true;
            }
            if (!empty($description) && $description !== $project->description) {
                $project->description = $description;
                $updated = true;
            }
            if ($updated) {
                $project->timemodified = time();
                $DB->update_record('local_rapidcmi5_projects', $project);
            }
        } else {
            $now = time();
            $project = new \stdClass();
            $project->name = !empty($name) ? $name : $identifier;
            $project->identifier = $identifier;
            $project->gitrepourl = $gitrepourl ?: null;
            $project->description = $description ?: null;
            $project->currentversionid = null;
            $project->currentpackageid = null;
            $project->createdby = $USER->id;
            $project->timecreated = $now;
            $project->timemodified = $now;
            $project->id = $DB->insert_record('local_rapidcmi5_projects', $project);
            $isnew = true;
        }

        $result = new \stdClass();
        $result->project = $project;
        $result->is_new = $isnew;
        return $result;
    }

    /**
     * Create a new version for a project.
     *
     * @param int $projectid Project ID.
     * @param string $versionnumber Version string (semver or build number).
     * @param int $packageid Content library package ID.
     * @param string $commithash Optional git commit hash.
     * @param int $buildtimestamp Unix timestamp when CLI built the package.
     * @param string $sha256hash Optional ZIP hash.
     * @param string $releasenotes Optional release notes.
     * @return object The created version record.
     * @throws \moodle_exception If version already exists.
     */
    public static function create_version(int $projectid, string $versionnumber, int $packageid,
            string $commithash = '', int $buildtimestamp = 0,
            string $sha256hash = '', string $releasenotes = ''): \stdClass {
        global $DB, $USER;

        // Check for duplicate version.
        if ($DB->record_exists('local_rapidcmi5_versions', [
            'projectid' => $projectid,
            'versionnumber' => $versionnumber,
        ])) {
            throw new \moodle_exception('error:versionexists', 'local_rapidcmi5', '', $versionnumber);
        }

        $now = time();
        $version = new \stdClass();
        $version->projectid = $projectid;
        $version->versionnumber = $versionnumber;
        $version->commithash = $commithash ?: null;
        $version->buildtimestamp = $buildtimestamp ?: $now;
        $version->packageid = $packageid;
        $version->sha256hash = $sha256hash ?: null;
        $version->releasenotes = $releasenotes ?: null;
        $version->createdby = $USER->id;
        $version->timecreated = $now;
        $version->id = $DB->insert_record('local_rapidcmi5_versions', $version);

        // Update project's current version and package pointers.
        $DB->update_record('local_rapidcmi5_projects', (object) [
            'id' => $projectid,
            'currentversionid' => $version->id,
            'currentpackageid' => $packageid,
            'timemodified' => $now,
        ]);

        return $version;
    }

    /**
     * Get a project by ID.
     *
     * @param int $projectid
     * @return object|false
     */
    public static function get_project(int $projectid) {
        global $DB;
        return $DB->get_record('local_rapidcmi5_projects', ['id' => $projectid]);
    }

    /**
     * Get a project by identifier.
     *
     * @param string $identifier
     * @return object|false
     */
    public static function get_project_by_identifier(string $identifier) {
        global $DB;
        return $DB->get_record('local_rapidcmi5_projects', ['identifier' => $identifier]);
    }

    /**
     * List projects with optional search.
     *
     * @param string $search Search term for name/identifier.
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public static function list_projects(string $search = '', int $offset = 0, int $limit = 50): array {
        global $DB;

        $params = [];
        $where = '';

        if (!empty($search)) {
            $where = "WHERE " . $DB->sql_like('name', ':search1', false) .
                     " OR " . $DB->sql_like('identifier', ':search2', false);
            $params['search1'] = '%' . $DB->sql_like_escape($search) . '%';
            $params['search2'] = '%' . $DB->sql_like_escape($search) . '%';
        }

        $sql = "SELECT * FROM {local_rapidcmi5_projects} $where ORDER BY timemodified DESC";
        return array_values($DB->get_records_sql($sql, $params, $offset, $limit));
    }

    /**
     * Count projects with optional search.
     *
     * @param string $search
     * @return int
     */
    public static function count_projects(string $search = ''): int {
        global $DB;

        if (empty($search)) {
            return $DB->count_records('local_rapidcmi5_projects');
        }

        $params = [];
        $where = $DB->sql_like('name', ':search1', false) .
                 " OR " . $DB->sql_like('identifier', ':search2', false);
        $params['search1'] = '%' . $DB->sql_like_escape($search) . '%';
        $params['search2'] = '%' . $DB->sql_like_escape($search) . '%';

        return $DB->count_records_select('local_rapidcmi5_projects', $where, $params);
    }

    /**
     * Get versions for a project.
     *
     * @param int $projectid
     * @return array
     */
    public static function get_versions(int $projectid): array {
        global $DB;
        return array_values($DB->get_records('local_rapidcmi5_versions',
            ['projectid' => $projectid], 'timecreated DESC'));
    }

    /**
     * Get the previous version string for a project (before current).
     *
     * @param int $projectid
     * @param int $excludeversionid Version ID to exclude (the just-created one).
     * @return string|null
     */
    public static function get_previous_version(int $projectid, int $excludeversionid): ?string {
        global $DB;

        $sql = "SELECT versionnumber FROM {local_rapidcmi5_versions}
                WHERE projectid = :projectid AND id != :excludeid
                ORDER BY timecreated DESC";
        $record = $DB->get_record_sql($sql, [
            'projectid' => $projectid,
            'excludeid' => $excludeversionid,
        ], IGNORE_MULTIPLE);

        return $record ? $record->versionnumber : null;
    }

    /**
     * Get deployments for a project.
     *
     * @param int $projectid
     * @return array
     */
    public static function get_deployments(int $projectid): array {
        global $DB;
        return array_values($DB->get_records('local_rapidcmi5_deployments',
            ['projectid' => $projectid], 'timecreated DESC'));
    }

    /**
     * Delete a project and its versions.
     *
     * @param int $projectid
     * @param bool $deletepackages Also delete content library packages.
     * @param bool $deleteactivities Also delete deployed cmi5 activities.
     */
    public static function delete_project(int $projectid, bool $deletepackages = false,
            bool $deleteactivities = false): void {
        global $DB;

        $project = $DB->get_record('local_rapidcmi5_projects', ['id' => $projectid], '*', MUST_EXIST);

        if ($deleteactivities) {
            $deployments = $DB->get_records('local_rapidcmi5_deployments', ['projectid' => $projectid]);
            foreach ($deployments as $deployment) {
                deployment_manager::delete_activity($deployment->cmid, $deployment->courseid);
            }
        }

        if ($deletepackages) {
            $versions = $DB->get_records('local_rapidcmi5_versions', ['projectid' => $projectid]);
            $library = new \mod_cmi5\content_library();
            foreach ($versions as $version) {
                try {
                    $library->delete_package($version->packageid);
                } catch (\Exception $e) {
                    // Package may be in use by other activities; skip.
                    debugging("Could not delete package {$version->packageid}: " . $e->getMessage());
                }
            }
        }

        // Delete in dependency order.
        $DB->delete_records('local_rapidcmi5_deployments', ['projectid' => $projectid]);
        $DB->delete_records('local_rapidcmi5_versions', ['projectid' => $projectid]);
        $DB->delete_records('local_rapidcmi5_projects', ['id' => $projectid]);
    }
}
