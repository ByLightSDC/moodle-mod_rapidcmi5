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

class get_project_versions extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'projectid' => new external_value(PARAM_INT, 'Project ID'),
        ]);
    }

    public static function execute(int $projectid): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'projectid' => $projectid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rapidcmi5:manage', $context);

        $project = project_manager::get_project($params['projectid']);
        if (!$project) {
            throw new \moodle_exception('error:projectnotfound', 'local_rapidcmi5');
        }

        $versions = project_manager::get_versions($project->id);

        $result = [];
        foreach ($versions as $v) {
            $result[] = [
                'id' => (int) $v->id,
                'versionnumber' => $v->versionnumber,
                'commithash' => $v->commithash ?? '',
                'buildtimestamp' => (int) $v->buildtimestamp,
                'packageid' => (int) $v->packageid,
                'sha256hash' => $v->sha256hash ?? '',
                'releasenotes' => $v->releasenotes ?? '',
                'createdby' => (int) $v->createdby,
                'timecreated' => (int) $v->timecreated,
            ];
        }

        return ['versions' => $result];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'versions' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Version ID'),
                    'versionnumber' => new external_value(PARAM_TEXT, 'Version string'),
                    'commithash' => new external_value(PARAM_TEXT, 'Git commit hash'),
                    'buildtimestamp' => new external_value(PARAM_INT, 'Build timestamp'),
                    'packageid' => new external_value(PARAM_INT, 'Package ID'),
                    'sha256hash' => new external_value(PARAM_TEXT, 'ZIP hash'),
                    'releasenotes' => new external_value(PARAM_RAW, 'Release notes'),
                    'createdby' => new external_value(PARAM_INT, 'Creator user ID'),
                    'timecreated' => new external_value(PARAM_INT, 'Created timestamp'),
                ])
            ),
        ]);
    }
}
