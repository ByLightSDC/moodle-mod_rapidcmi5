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

namespace local_rapidcmi5\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class upload_player_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('uploadplayerversion', 'local_rapidcmi5'));

        // Version string.
        $mform->addElement('text', 'version', get_string('version', 'local_rapidcmi5'));
        $mform->setType('version', PARAM_TEXT);
        $mform->addRule('version', get_string('required'), 'required', null, 'client');

        // Player ZIP file.
        $mform->addElement('filepicker', 'playerfile', get_string('playerfile', 'local_rapidcmi5'),
            null, ['maxbytes' => 0, 'accepted_types' => ['.zip']]);
        $mform->addRule('playerfile', get_string('required'), 'required', null, 'client');

        $this->add_action_buttons(true, get_string('uploadplayerversion', 'local_rapidcmi5'));
    }
}
