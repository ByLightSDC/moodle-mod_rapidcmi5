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

class get_project extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'projectid' => new external_value(PARAM_INT, 'Project ID (0 if using identifier)', VALUE_DEFAULT, 0),
            'project_identifier' => new external_value(PARAM_TEXT, 'Project identifier (alternative to ID)', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $projectid = 0, string $project_identifier = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'projectid' => $projectid,
            'project_identifier' => $project_identifier,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rapidcmi5:manage', $context);

        if ($params['projectid'] > 0) {
            $project = project_manager::get_project($params['projectid']);
        } else if (!empty($params['project_identifier'])) {
            $project = project_manager::get_project_by_identifier($params['project_identifier']);
        } else {
            throw new \invalid_parameter_exception('Either projectid or project_identifier must be provided');
        }

        if (!$project) {
            throw new \moodle_exception('error:projectnotfound', 'local_rapidcmi5');
        }

        $versions = project_manager::get_versions($project->id);
        $deployments = project_manager::get_deployments($project->id);

        $versiondata = [];
        foreach ($versions as $v) {
            $versiondata[] = [
                'id' => (int) $v->id,
                'versionnumber' => $v->versionnumber,
                'commithash' => $v->commithash ?? '',
                'buildtimestamp' => (int) $v->buildtimestamp,
                'packageid' => (int) $v->packageid,
                'sha256hash' => $v->sha256hash ?? '',
                'releasenotes' => $v->releasenotes ?? '',
                'timecreated' => (int) $v->timecreated,
            ];
        }

        $deploymentdata = [];
        foreach ($deployments as $d) {
            $coursename = '';
            global $DB;
            $course = $DB->get_record('course', ['id' => $d->courseid], 'fullname');
            if ($course) {
                $coursename = $course->fullname;
            }
            $deploymentdata[] = [
                'id' => (int) $d->id,
                'courseid' => (int) $d->courseid,
                'coursename' => $coursename,
                'cmid' => (int) $d->cmid,
                'versionid' => (int) $d->versionid,
                'timemodified' => (int) $d->timemodified,
            ];
        }

        return [
            'id' => (int) $project->id,
            'name' => $project->name,
            'identifier' => $project->identifier,
            'gitrepourl' => $project->gitrepourl ?? '',
            'description' => $project->description ?? '',
            'currentversionid' => (int) ($project->currentversionid ?? 0),
            'currentpackageid' => (int) ($project->currentpackageid ?? 0),
            'timecreated' => (int) $project->timecreated,
            'timemodified' => (int) $project->timemodified,
            'versions' => $versiondata,
            'deployments' => $deploymentdata,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Project ID'),
            'name' => new external_value(PARAM_TEXT, 'Project name'),
            'identifier' => new external_value(PARAM_TEXT, 'Project identifier'),
            'gitrepourl' => new external_value(PARAM_TEXT, 'Git repo URL'),
            'description' => new external_value(PARAM_RAW, 'Description'),
            'currentversionid' => new external_value(PARAM_INT, 'Current version ID'),
            'currentpackageid' => new external_value(PARAM_INT, 'Current package ID'),
            'timecreated' => new external_value(PARAM_INT, 'Created timestamp'),
            'timemodified' => new external_value(PARAM_INT, 'Modified timestamp'),
            'versions' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Version ID'),
                    'versionnumber' => new external_value(PARAM_TEXT, 'Version string'),
                    'commithash' => new external_value(PARAM_TEXT, 'Git commit hash'),
                    'buildtimestamp' => new external_value(PARAM_INT, 'Build timestamp'),
                    'packageid' => new external_value(PARAM_INT, 'Package ID'),
                    'sha256hash' => new external_value(PARAM_TEXT, 'ZIP hash'),
                    'releasenotes' => new external_value(PARAM_RAW, 'Release notes'),
                    'timecreated' => new external_value(PARAM_INT, 'Created timestamp'),
                ])
            ),
            'deployments' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Deployment ID'),
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'coursename' => new external_value(PARAM_TEXT, 'Course name'),
                    'cmid' => new external_value(PARAM_INT, 'Course module ID'),
                    'versionid' => new external_value(PARAM_INT, 'Deployed version ID'),
                    'timemodified' => new external_value(PARAM_INT, 'Last modified'),
                ])
            ),
        ]);
    }
}
