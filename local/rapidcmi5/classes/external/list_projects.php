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

class list_projects extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'search' => new external_value(PARAM_TEXT, 'Search term', VALUE_DEFAULT, ''),
            'offset' => new external_value(PARAM_INT, 'Offset', VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Limit', VALUE_DEFAULT, 50),
        ]);
    }

    public static function execute(string $search = '', int $offset = 0, int $limit = 50): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'search' => $search,
            'offset' => $offset,
            'limit' => $limit,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rapidcmi5:manage', $context);

        $projects = project_manager::list_projects($params['search'], $params['offset'], $params['limit']);
        $total = project_manager::count_projects($params['search']);

        $items = [];
        foreach ($projects as $project) {
            $currentversion = null;
            if ($project->currentversionid) {
                global $DB;
                $ver = $DB->get_record('local_rapidcmi5_versions', ['id' => $project->currentversionid]);
                if ($ver) {
                    $currentversion = $ver->versionnumber;
                }
            }

            $items[] = [
                'id' => (int) $project->id,
                'name' => $project->name,
                'identifier' => $project->identifier,
                'gitrepourl' => $project->gitrepourl ?? '',
                'currentversion' => $currentversion ?? '',
                'timemodified' => (int) $project->timemodified,
            ];
        }

        return [
            'projects' => $items,
            'total' => $total,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'projects' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Project ID'),
                    'name' => new external_value(PARAM_TEXT, 'Project name'),
                    'identifier' => new external_value(PARAM_TEXT, 'Project identifier'),
                    'gitrepourl' => new external_value(PARAM_TEXT, 'Git repo URL'),
                    'currentversion' => new external_value(PARAM_TEXT, 'Current version string'),
                    'timemodified' => new external_value(PARAM_INT, 'Last modified timestamp'),
                ])
            ),
            'total' => new external_value(PARAM_INT, 'Total number of projects'),
        ]);
    }
}
