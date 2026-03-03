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

class upload_player extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'draftitemid' => new external_value(PARAM_INT, 'Draft area item ID containing the player ZIP'),
            'version' => new external_value(PARAM_TEXT, 'Player version string (semver)'),
        ]);
    }

    public static function execute(int $draftitemid, string $version): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'draftitemid' => $draftitemid,
            'version' => $version,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/rapidcmi5:manage', $context);

        $record = player_manager::upload_player($params['draftitemid'], $params['version']);

        return [
            'playerversionid' => (int) $record->id,
            'version' => $record->version,
            'file_count' => (int) $record->file_count,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'playerversionid' => new external_value(PARAM_INT, 'Player version record ID'),
            'version' => new external_value(PARAM_TEXT, 'Version string'),
            'file_count' => new external_value(PARAM_INT, 'Number of player files'),
        ]);
    }
}
