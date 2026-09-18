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
        $project = $this->_customdata['project'] ?? null;

        $mform->addElement('header', 'general', get_string($project ? 'uploadnewversion' : 'uploadpackage',
            'local_rapidcmi5'));
        if ($project) {
            $mform->addElement('hidden', 'projectid', $project->id);
            $mform->setType('projectid', PARAM_INT);
            $mform->addElement('static', 'project_name', get_string('projectname', 'local_rapidcmi5'),
                format_string($project->name));
            $mform->addElement('static', 'project_identifier', get_string('projectidentifier', 'local_rapidcmi5'),
                s($project->identifier));
            $mform->addElement('static', 'uploadnotice', '', get_string('uploadversionnotice', 'local_rapidcmi5'));
        } else {
            // The project is identified by the course ID in the package's cmi5.xml.
            $mform->addElement('text', 'project_name', get_string('projectname', 'local_rapidcmi5'));
            $mform->setType('project_name', PARAM_TEXT);
            $mform->addHelpButton('project_name', 'projectname', 'local_rapidcmi5');
            $mform->addElement('text', 'git_repo_url', get_string('gitrepo', 'local_rapidcmi5'));
            $mform->setType('git_repo_url', PARAM_URL);
        }

        // Version.
        $mform->addElement('text', 'version', get_string('version', 'local_rapidcmi5'));
        $mform->setType('version', PARAM_TEXT);
        $mform->addRule('version', get_string('required'), 'required', null, 'client');

        // Release notes.
        $mform->addElement('textarea', 'release_notes', get_string('releasenotes', 'local_rapidcmi5'),
            ['rows' => 3, 'cols' => 60]);
        $mform->setType('release_notes', PARAM_RAW);

        // Package ZIP file.
        $mform->addElement('filepicker', 'packagefile', get_string('packagefile', 'local_rapidcmi5'),
            null, ['maxbytes' => 0, 'accepted_types' => ['.zip']]);
        $mform->addRule('packagefile', get_string('required'), 'required', null, 'client');

        if (!$project) {
            $mform->addElement('text', 'deploy_course_ids', get_string('deploytocourseids', 'local_rapidcmi5'));
            $mform->setType('deploy_course_ids', PARAM_TEXT);
            $mform->setDefault('deploy_course_ids', '');
        }
        $this->add_action_buttons(true, get_string($project ? 'uploadnewversion' : 'uploadanddeploy', 'local_rapidcmi5'));
    }

    /**
     * Course IDs from the comma-separated deploy field, each once.
     *
     * @param string $value Field value, e.g. "12, 15".
     * @return int[]|null The IDs, or null when any entry is not a positive whole number.
     */
    public static function parse_course_ids(string $value): ?array {
        $ids = [];
        foreach (array_filter(array_map('trim', explode(',', $value)), 'strlen') as $id) {
            if (!ctype_digit($id) || (int) $id <= 0) {
                return null;
            }
            $ids[] = (int) $id;
        }
        return array_values(array_unique($ids));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (self::parse_course_ids($data['deploy_course_ids'] ?? '') === null) {
            $errors['deploy_course_ids'] = get_string('error:invalidcourseids', 'local_rapidcmi5');
        }

        if (empty($errors)) {
            $project = $this->_customdata['project'] ?? null;
            try {
                if ($project) {
                    \local_rapidcmi5\version_uploader::validate($project->id, (int) $data['packagefile'], $data['version']);
                } else {
                    \local_rapidcmi5\version_uploader::validate_package((int) $data['packagefile'], $data['version']);
                }
            } catch (\moodle_exception $e) {
                $field = in_array($e->errorcode, ['error:versionexists', 'error:invalidversionlabel']) ? 'version' : 'packagefile';
                $errors[$field] = $e->getMessage();
            }
        }
        return $errors;
    }
}
