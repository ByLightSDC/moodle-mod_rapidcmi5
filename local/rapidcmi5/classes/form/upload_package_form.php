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

class upload_package_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'general', get_string('uploadpackage', 'local_rapidcmi5'));

        // Project identifier.
        $mform->addElement('text', 'project_identifier', get_string('projectidentifier', 'local_rapidcmi5'));
        $mform->setType('project_identifier', PARAM_TEXT);
        $mform->addRule('project_identifier', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('project_identifier', 'projectidentifier', 'local_rapidcmi5');

        // Project name.
        $mform->addElement('text', 'project_name', get_string('projectname', 'local_rapidcmi5'));
        $mform->setType('project_name', PARAM_TEXT);

        // Version.
        $mform->addElement('text', 'version', get_string('version', 'local_rapidcmi5'));
        $mform->setType('version', PARAM_TEXT);
        $mform->addRule('version', get_string('required'), 'required', null, 'client');

        // Git repo URL.
        $mform->addElement('text', 'git_repo_url', get_string('gitrepo', 'local_rapidcmi5'));
        $mform->setType('git_repo_url', PARAM_URL);

        // Release notes.
        $mform->addElement('textarea', 'release_notes', get_string('releasenotes', 'local_rapidcmi5'),
            ['rows' => 3, 'cols' => 60]);
        $mform->setType('release_notes', PARAM_RAW);

        // Package ZIP file.
        $mform->addElement('filepicker', 'packagefile', get_string('packagefile', 'local_rapidcmi5'),
            null, ['maxbytes' => 0, 'accepted_types' => ['.zip']]);
        $mform->addRule('packagefile', get_string('required'), 'required', null, 'client');

        // Deploy to courses (optional).
        $mform->addElement('text', 'deploy_course_ids', get_string('deploytocourseids', 'local_rapidcmi5'));
        $mform->setType('deploy_course_ids', PARAM_TEXT);
        $mform->setDefault('deploy_course_ids', '');

        $this->add_action_buttons(true, get_string('uploadanddeploy', 'local_rapidcmi5'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (!empty($data['deploy_course_ids'])) {
            $ids = array_map('trim', explode(',', $data['deploy_course_ids']));
            foreach ($ids as $id) {
                if (!is_numeric($id) || (int) $id <= 0) {
                    $errors['deploy_course_ids'] = get_string('error:invalidcourseids', 'local_rapidcmi5');
                    break;
                }
            }
        }

        return $errors;
    }
}
