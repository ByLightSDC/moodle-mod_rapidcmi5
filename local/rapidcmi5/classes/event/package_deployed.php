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

namespace local_rapidcmi5\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event fired when a cmi5 package is deployed to a course via RapidCMI5.
 *
 * @package    local_rapidcmi5
 * @copyright  2026 David Estes
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_deployed extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_rapidcmi5_deployments';
    }

    public static function get_name() {
        return 'Package deployed';
    }

    public function get_description() {
        $cmid = $this->other['cmid'] ?? 'unknown';
        return "A cmi5 package was deployed to course module '{$cmid}' " .
            "in course '{$this->courseid}' by user '{$this->userid}'.";
    }

    public function get_url() {
        $cmid = $this->other['cmid'] ?? 0;
        if ($cmid) {
            return new \moodle_url('/mod/cmi5/view.php', ['id' => $cmid]);
        }
        return null;
    }

    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['cmid'])) {
            throw new \coding_exception('The \'cmid\' value must be set in other.');
        }
    }
}
