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
use core_external\external_single_structure;
use core_external\external_value;
use local_rapidcmi5\player_manager;

class upgrade_player extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'player_version_id' => new external_value(PARAM_INT, 'Player version ID to upgrade to'),
            'versionid' => new external_value(PARAM_INT, 'Library package version ID (0 if using cmid)', VALUE_DEFAULT, 0),
            'cmid' => new external_value(PARAM_INT, 'Course module ID (0 if using versionid)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $player_version_id, int $versionid = 0, int $cmid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'player_version_id' => $player_version_id,
            'versionid' => $versionid,
            'cmid' => $cmid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rapidcmi5:deploy', $context);

        if ($params['versionid'] > 0) {
            $result = player_manager::upgrade_package_player($params['versionid'], $params['player_version_id']);
        } else if ($params['cmid'] > 0) {
            $result = player_manager::upgrade_activity_player($params['cmid'], $params['player_version_id']);
        } else {
            throw new \invalid_parameter_exception('Either versionid or cmid must be provided');
        }

        return [
            'success' => $result->success,
            'aus_updated' => $result->aus_updated,
            'files_replaced' => $result->files_replaced,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether upgrade succeeded'),
            'aus_updated' => new external_value(PARAM_INT, 'Number of AUs updated'),
            'files_replaced' => new external_value(PARAM_INT, 'Number of files replaced'),
        ]);
    }
}
