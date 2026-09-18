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

namespace local_rapidcmi5\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_rapidcmi5\project_manager;
use local_rapidcmi5\deployment_manager;
use local_rapidcmi5\version_uploader;

/**
 * Deploy a cmi5 package from the RapidCMI5 CLI with project/version tracking.
 */
class deploy_package extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'draftitemid' => new external_value(PARAM_INT, 'Draft area item ID from core_files_upload'),
            'project_identifier' => new external_value(PARAM_TEXT,
                'Ignored: the project is identified by the course ID in the package\'s cmi5.xml', VALUE_DEFAULT, ''),
            'project_name' => new external_value(PARAM_TEXT, 'Project display name', VALUE_DEFAULT, ''),
            'version' => new external_value(PARAM_TEXT, 'Version string (semver or build number)'),
            'commit_hash' => new external_value(PARAM_ALPHANUMEXT, 'Git commit hash', VALUE_DEFAULT, ''),
            'git_repo_url' => new external_value(PARAM_URL, 'Git repository URL', VALUE_DEFAULT, ''),
            'build_timestamp' => new external_value(PARAM_INT, 'Unix timestamp of build', VALUE_DEFAULT, 0),
            'release_notes' => new external_value(PARAM_RAW, 'Release notes', VALUE_DEFAULT, ''),
            'deploy_to_courses' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course ID'),
                'Course IDs to auto-deploy into',
                VALUE_DEFAULT,
                []
            ),
            'section_id' => new external_value(PARAM_INT, 'Section number within course', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $draftitemid, string $project_identifier,
            string $project_name, string $version, string $commit_hash,
            string $git_repo_url, int $build_timestamp, string $release_notes,
            array $deploy_to_courses, int $section_id): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'draftitemid' => $draftitemid,
            'project_identifier' => $project_identifier,
            'project_name' => $project_name,
            'version' => $version,
            'commit_hash' => $commit_hash,
            'git_repo_url' => $git_repo_url,
            'build_timestamp' => $build_timestamp,
            'release_notes' => $release_notes,
            'deploy_to_courses' => $deploy_to_courses,
            'section_id' => $section_id,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rapidcmi5:deploy', $context);

        // Validates the package, then creates the project if needed and the version in one transaction.
        $uploaded = version_uploader::upload_package($params['draftitemid'], $params['version'],
            $params['release_notes'], [
                'name' => $params['project_name'],
                'gitrepourl' => $params['git_repo_url'],
                'commithash' => $params['commit_hash'],
                'buildtimestamp' => $params['build_timestamp'],
            ]);
        $project = $uploaded->project;
        $versionrecord = $uploaded->version;
        $previousversion = project_manager::get_previous_version($project->id, $versionrecord->id);
        $deployments = deployment_manager::deploy_to_courses($project->id, $versionrecord->id,
            (int) $versionrecord->libraryversionid, $params['deploy_to_courses'], $project->name, $params['section_id']);

        return [
            'projectid' => (int) $project->id,
            'versionid' => (int) $versionrecord->id,
            'packageid' => (int) $versionrecord->packageid,
            'is_new_project' => $uploaded->isnewproject,
            'previous_version' => $previousversion ?? '',
            'deployments' => $deployments,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'projectid' => new external_value(PARAM_INT, 'Project ID'),
            'versionid' => new external_value(PARAM_INT, 'Version ID'),
            'packageid' => new external_value(PARAM_INT, 'Content library package ID'),
            'is_new_project' => new external_value(PARAM_BOOL, 'Whether a new project was created'),
            'previous_version' => new external_value(PARAM_TEXT, 'Previous version string, empty if first'),
            'deployments' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'cmid' => new external_value(PARAM_INT, 'Course module ID (0 on error)'),
                    'status' => new external_value(PARAM_ALPHA, 'success or error'),
                    'message' => new external_value(PARAM_TEXT, 'Error message if failed'),
                ]),
                'Deployment results'
            ),
        ]);
    }
}
