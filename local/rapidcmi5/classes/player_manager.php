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

namespace local_rapidcmi5;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages player version uploads, detection, and in-place upgrades.
 */
class player_manager {

    /** @var string Component for file storage. */
    const COMPONENT = 'local_rapidcmi5';

    /** @var string File area for player ZIPs. */
    const FILEAREA = 'player_package';

    /** @var array Heuristic patterns for detecting player files at package root. */
    const PLAYER_FILE_PATTERNS = [
        '/^main\.[a-f0-9]+\.js$/',
        '/^main\.[a-f0-9]+\.css$/',
        '/^runtime\.[a-f0-9]+\.js$/',
        '/^styles\.[a-f0-9]+\.css$/',
        '/^styles\.[a-f0-9]+\.js$/',
        '/^\d+\.[a-f0-9]+\.js$/',  // Chunk files.
    ];

    /** @var array Known player files at root (exact match). */
    const PLAYER_ROOT_FILES = [
        'index.html', 'favicon.ico', 'env-config.js',
        '3rdpartylicenses.txt', 'player-manifest.json',
    ];

    /** @var array Files per AU directory that belong to the player (exact match). */
    const PLAYER_AU_FILES = ['index.html', 'favicon.ico'];

    /**
     * Upload a player version from draft area.
     *
     * @param int $draftitemid Draft area item ID containing the player ZIP.
     * @param string $version Semver version string.
     * @return \stdClass The created player_versions record.
     */
    public static function upload_player(int $draftitemid, string $version): \stdClass {
        global $DB, $USER;

        if ($DB->record_exists('local_rapidcmi5_player_versions', ['version' => $version])) {
            throw new \moodle_exception('error:playerversionexists', 'local_rapidcmi5', '', $version);
        }

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $syscontext = \context_system::instance();

        // Get the ZIP from draft area.
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'sortorder, id', false);
        if (empty($files)) {
            throw new \moodle_exception('error:nofileindraft', 'local_rapidcmi5');
        }
        $zipfile = reset($files);

        // Extract to temp directory.
        $tempdir = make_request_directory();
        $packer = get_file_packer('application/zip');
        $zipfile->extract_to_pathname($packer, $tempdir);

        // Read or generate manifest.
        $manifestpath = $tempdir . '/player-manifest.json';
        if (file_exists($manifestpath)) {
            $manifestjson = file_get_contents($manifestpath);
            $manifestdata = json_decode($manifestjson, true);
            if (empty($manifestdata['files'])) {
                throw new \moodle_exception('error:invalidmanifest', 'local_rapidcmi5');
            }
        } else {
            // Generate manifest from directory listing.
            $manifestdata = self::generate_manifest_from_dir($tempdir, $version);
        }

        // Read raw index.html and cfg.json templates.
        $indexhtml = '';
        $cfgjson = '';
        if (file_exists($tempdir . '/index.html')) {
            $indexhtml = file_get_contents($tempdir . '/index.html');
        }
        if (file_exists($tempdir . '/cfg.json')) {
            $cfgjson = file_get_contents($tempdir . '/cfg.json');
        }

        // Store manifest with templates included.
        $manifestdata['indexhtml'] = $indexhtml;
        $manifestdata['cfgjson'] = $cfgjson;
        $manifestdata['playerVersion'] = $version;

        $now = time();
        $record = new \stdClass();
        $record->version = $version;
        $record->sha256hash = $zipfile->get_contenthash();
        $record->manifest = json_encode($manifestdata);
        $record->createdby = $USER->id;
        $record->timecreated = $now;
        $record->id = $DB->insert_record('local_rapidcmi5_player_versions', $record);

        // Store the ZIP file.
        $fs->delete_area_files($syscontext->id, self::COMPONENT, self::FILEAREA, $record->id);
        $filerecord = [
            'contextid' => $syscontext->id,
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => $record->id,
            'filepath' => '/',
            'filename' => $zipfile->get_filename(),
        ];
        $fs->create_file_from_storedfile($filerecord, $zipfile);

        $record->file_count = count($manifestdata['files']);
        return $record;
    }

    /**
     * Generate a manifest from a directory listing (for player ZIPs without player-manifest.json).
     *
     * @param string $dir Temp directory path.
     * @param string $version Version string.
     * @return array Manifest data.
     */
    private static function generate_manifest_from_dir(string $dir, string $version): array {
        $files = [];
        $iterator = new \DirectoryIterator($dir);
        foreach ($iterator as $file) {
            if ($file->isDot()) {
                continue;
            }
            $filename = $file->getFilename();
            if ($file->isDir()) {
                if ($filename === 'assets') {
                    // Include all files under assets/.
                    $assetiter = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($file->getPathname(), \RecursiveDirectoryIterator::SKIP_DOTS)
                    );
                    foreach ($assetiter as $assetfile) {
                        if ($assetfile->isFile()) {
                            $relpath = 'assets/' . substr($assetfile->getPathname(), strlen($file->getPathname()) + 1);
                            $files[] = str_replace('\\', '/', $relpath);
                        }
                    }
                }
                continue;
            }
            // Skip compiled_course or other non-player content.
            if (self::is_player_root_file($filename)) {
                $files[] = $filename;
            }
        }

        return [
            'playerVersion' => $version,
            'buildTimestamp' => time(),
            'files' => $files,
        ];
    }

    /**
     * Check if a filename is a player root file (exact or pattern match).
     */
    private static function is_player_root_file(string $filename): bool {
        if (in_array($filename, self::PLAYER_ROOT_FILES)) {
            return true;
        }
        foreach (self::PLAYER_FILE_PATTERNS as $pattern) {
            if (preg_match($pattern, $filename)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get a player version record.
     */
    public static function get_player_version(int $id): ?\stdClass {
        global $DB;
        $record = $DB->get_record('local_rapidcmi5_player_versions', ['id' => $id]);
        return $record ?: null;
    }

    /**
     * Get the latest player version.
     */
    public static function get_latest_player_version(): ?\stdClass {
        global $DB;
        $record = $DB->get_record_sql(
            "SELECT * FROM {local_rapidcmi5_player_versions} ORDER BY timecreated DESC LIMIT 1"
        );
        return $record ?: null;
    }

    /**
     * List player versions.
     */
    public static function list_player_versions(int $offset = 0, int $limit = 50): array {
        global $DB;
        return array_values($DB->get_records('local_rapidcmi5_player_versions',
            null, 'timecreated DESC', '*', $offset, $limit));
    }

    /**
     * Count player versions.
     */
    public static function count_player_versions(): int {
        global $DB;
        return $DB->count_records('local_rapidcmi5_player_versions');
    }

    /**
     * Detect the player version embedded in a library package version.
     *
     * @param int $versionid Library package version ID (cmi5_package_versions.id).
     * @return \stdClass Detection result.
     */
    public static function detect_player_in_library(int $versionid): \stdClass {
        $fs = get_file_storage();
        $syscontext = \context_system::instance();

        return self::detect_player_in_filearea(
            $fs, $syscontext->id, 'mod_cmi5', 'library_content', $versionid
        );
    }

    /**
     * Detect the player version embedded in an activity.
     *
     * For library-sourced activities (packageversionid set), checks the library_content filearea.
     * For ad-hoc activities, checks the module's content filearea.
     *
     * @param int $cmid Course module ID.
     * @return \stdClass Detection result.
     */
    public static function detect_player_in_activity(int $cmid): \stdClass {
        global $DB;

        $cm = get_coursemodule_from_id('cmi5', $cmid, 0, false, MUST_EXIST);
        $instance = $DB->get_record('cmi5', ['id' => $cm->instance], '*', MUST_EXIST);
        $fs = get_file_storage();

        // Library-sourced activity: check library_content filearea.
        if (!empty($instance->packageversionid)) {
            return self::detect_player_in_filearea(
                $fs, \context_system::instance()->id, 'mod_cmi5', 'library_content', $instance->packageversionid
            );
        }

        // Ad-hoc activity: check module content filearea.
        $context = \context_module::instance($cm->id);
        return self::detect_player_in_filearea(
            $fs, $context->id, 'mod_cmi5', 'content', 0
        );
    }

    /**
     * Core detection logic for any file area.
     */
    private static function detect_player_in_filearea(\file_storage $fs, int $contextid,
            string $component, string $filearea, int $itemid): \stdClass {
        $result = new \stdClass();
        $result->is_rapidcmi5 = false;
        $result->player_version = null;
        $result->au_count = 0;

        // Check for player-manifest.json at root.
        $manifest = $fs->get_file($contextid, $component, $filearea, $itemid, '/', 'player-manifest.json');
        if ($manifest && !$manifest->is_directory()) {
            $data = json_decode($manifest->get_content(), true);
            if (!empty($data['playerVersion'])) {
                $result->is_rapidcmi5 = true;
                $result->player_version = $data['playerVersion'];
            }
        }

        // Heuristic: look for main.*.js at root.
        if (!$result->is_rapidcmi5) {
            $rootfiles = $fs->get_directory_files($contextid, $component, $filearea, $itemid, '/', false, false);
            foreach ($rootfiles as $file) {
                $filename = $file->get_filename();
                if (preg_match('/^main\.[a-f0-9]+\.js$/', $filename)) {
                    $result->is_rapidcmi5 = true;
                    break;
                }
            }
        }

        // Count AU directories (those containing config.json under compiled_course/blocks/).
        if ($result->is_rapidcmi5) {
            $allfiles = $fs->get_area_files($contextid, $component, $filearea, $itemid, '', false);
            foreach ($allfiles as $file) {
                if ($file->get_filename() === 'config.json' &&
                        strpos($file->get_filepath(), '/compiled_course/blocks/') !== false) {
                    $result->au_count++;
                }
            }
        }

        return $result;
    }

    /**
     * Upgrade the player in a library package version.
     *
     * @param int $versionid Library package version ID.
     * @param int $playerversionid Player version ID.
     * @return \stdClass Upgrade result.
     */
    public static function upgrade_package_player(int $versionid, int $playerversionid): \stdClass {
        $fs = get_file_storage();
        $syscontext = \context_system::instance();

        return self::upgrade_player_in_filearea(
            $fs, $syscontext->id, 'mod_cmi5', 'library_content', $versionid, $playerversionid
        );
    }

    /**
     * Upgrade the player in an activity.
     *
     * For library-sourced activities, upgrades in the library_content filearea.
     * For ad-hoc activities, upgrades in the module's content filearea.
     *
     * @param int $cmid Course module ID.
     * @param int $playerversionid Player version ID.
     * @return \stdClass Upgrade result.
     */
    public static function upgrade_activity_player(int $cmid, int $playerversionid): \stdClass {
        global $DB;

        $cm = get_coursemodule_from_id('cmi5', $cmid, 0, false, MUST_EXIST);
        $instance = $DB->get_record('cmi5', ['id' => $cm->instance], '*', MUST_EXIST);
        $fs = get_file_storage();

        // Library-sourced activity: upgrade in library_content filearea.
        if (!empty($instance->packageversionid)) {
            return self::upgrade_player_in_filearea(
                $fs, \context_system::instance()->id, 'mod_cmi5', 'library_content',
                $instance->packageversionid, $playerversionid
            );
        }

        // Ad-hoc activity: upgrade in module content filearea.
        $context = \context_module::instance($cm->id);
        return self::upgrade_player_in_filearea(
            $fs, $context->id, 'mod_cmi5', 'content', 0, $playerversionid
        );
    }

    /**
     * Core upgrade logic for any file area.
     */
    private static function upgrade_player_in_filearea(\file_storage $fs, int $contextid,
            string $component, string $filearea, int $itemid, int $playerversionid): \stdClass {
        global $DB;

        $playerversion = $DB->get_record('local_rapidcmi5_player_versions',
            ['id' => $playerversionid], '*', MUST_EXIST);
        $manifest = json_decode($playerversion->manifest, true);

        $result = new \stdClass();
        $result->success = false;
        $result->aus_updated = 0;
        $result->files_replaced = 0;

        // Extract player ZIP to temp directory.
        $syscontext = \context_system::instance();
        $playerfiles = $fs->get_area_files($syscontext->id, self::COMPONENT, self::FILEAREA,
            $playerversionid, 'sortorder, id', false);
        if (empty($playerfiles)) {
            throw new \moodle_exception('error:playerfilenotfound', 'local_rapidcmi5');
        }
        $playerzip = reset($playerfiles);
        $tempdir = make_request_directory();
        $packer = get_file_packer('application/zip');
        $playerzip->extract_to_pathname($packer, $tempdir);

        // Step 1: Identify existing player files to delete at root.
        $oldmanifest = null;
        $manifestfile = $fs->get_file($contextid, $component, $filearea, $itemid, '/', 'player-manifest.json');
        if ($manifestfile && !$manifestfile->is_directory()) {
            $oldmanifest = json_decode($manifestfile->get_content(), true);
        }

        // Delete old player files from root.
        $rootfiles = $fs->get_directory_files($contextid, $component, $filearea, $itemid, '/', false, false);
        foreach ($rootfiles as $file) {
            $filename = $file->get_filename();
            $shoulddelete = false;

            if ($oldmanifest && in_array($filename, $oldmanifest['files'] ?? [])) {
                $shoulddelete = true;
            } else if (self::is_player_root_file($filename)) {
                $shoulddelete = true;
            }

            if ($shoulddelete) {
                $file->delete();
                $result->files_replaced++;
            }
        }

        // Delete old root assets/ directory.
        $rootassets = $fs->get_directory_files($contextid, $component, $filearea, $itemid, '/assets/', true, true);
        foreach ($rootassets as $file) {
            $file->delete();
            $result->files_replaced++;
        }

        // Step 2: Copy new player files to root.
        // Skip cfg.json — it contains course-specific runtime configuration
        // (server endpoints, logo paths) that must not be overwritten.
        foreach ($manifest['files'] as $relpath) {
            if ($relpath === 'cfg.json') {
                continue;
            }
            if (strpos($relpath, '/') !== false) {
                // Subdirectory file (e.g. assets/logo.png).
                $dirname = '/' . dirname($relpath) . '/';
                $basename = basename($relpath);
            } else {
                $dirname = '/';
                $basename = $relpath;
            }

            $sourcepath = $tempdir . '/' . $relpath;
            if (!file_exists($sourcepath)) {
                continue;
            }

            // Ensure directory entry exists.
            if ($dirname !== '/') {
                self::ensure_directory($fs, $contextid, $component, $filearea, $itemid, $dirname);
            }

            // Delete existing file at this path if any.
            $existing = $fs->get_file($contextid, $component, $filearea, $itemid, $dirname, $basename);
            if ($existing && !$existing->is_directory()) {
                $existing->delete();
            }

            $filerecord = [
                'contextid' => $contextid,
                'component' => $component,
                'filearea' => $filearea,
                'itemid' => $itemid,
                'filepath' => $dirname,
                'filename' => $basename,
            ];
            $fs->create_file_from_pathname($filerecord, $sourcepath);
        }

        // Step 3: Update player files in each AU directory.
        $audirs = self::find_au_directories($fs, $contextid, $component, $filearea, $itemid);
        $rawindexhtml = $manifest['indexhtml'] ?? '';
        $rawcfgjson = $manifest['cfgjson'] ?? '';

        foreach ($audirs as $aupath) {
            // Calculate relative path from AU dir back to package root.
            // AU path is like /compiled_course/blocks/blockname/auname/
            // Must match Node.js path.relative() output used by cmi5-builder:
            // e.g. 4 components deep → ../../../../
            $parts = array_filter(explode('/', trim($aupath, '/')));
            $depth = count($parts);
            $relativepath = str_repeat('../', $depth);
            $relativepath = rtrim($relativepath, '/'); // e.g. ../../../..

            // Localize and write index.html.
            if (!empty($rawindexhtml)) {
                $localizedhtml = self::localize_nx_build_pathing($rawindexhtml, $relativepath);
                self::write_file_content($fs, $contextid, $component, $filearea, $itemid,
                    $aupath, 'index.html', $localizedhtml);
                $result->files_replaced++;
            }

            // NOTE: cfg.json in AU directories is NOT replaced during upgrade.
            // It contains course-specific runtime configuration (LRS endpoints, server URLs)
            // set at build time. Only the root cfg.json is a player file.

            // Copy favicon.ico.
            $faviconpath = $tempdir . '/favicon.ico';
            if (file_exists($faviconpath)) {
                $existing = $fs->get_file($contextid, $component, $filearea, $itemid, $aupath, 'favicon.ico');
                if ($existing && !$existing->is_directory()) {
                    $existing->delete();
                }
                $filerecord = [
                    'contextid' => $contextid,
                    'component' => $component,
                    'filearea' => $filearea,
                    'itemid' => $itemid,
                    'filepath' => $aupath,
                    'filename' => 'favicon.ico',
                ];
                $fs->create_file_from_pathname($filerecord, $faviconpath);
                $result->files_replaced++;
            }

            $result->aus_updated++;
        }

        // Step 4: Write updated player-manifest.json to package root.
        $newmanifest = [
            'playerVersion' => $manifest['playerVersion'],
            'buildTimestamp' => $manifest['buildTimestamp'] ?? time(),
            'files' => $manifest['files'],
        ];
        self::write_file_content($fs, $contextid, $component, $filearea, $itemid,
            '/', 'player-manifest.json', json_encode($newmanifest, JSON_PRETTY_PRINT));

        $result->success = true;
        return $result;
    }

    /**
     * PHP port of localizeNxBuildPathing() from generateAuConfigs.ts.
     */
    private static function localize_nx_build_pathing(string $content, string $relativepath): string {
        $content = str_replace('"runtime.', '"' . $relativepath . '/runtime.', $content);
        $content = str_replace('"styles.', '"' . $relativepath . '/styles.', $content);
        $content = str_replace('"main.', '"' . $relativepath . '/main.', $content);
        $content = str_replace('<base href="/">', '', $content);
        return $content;
    }

    /**
     * PHP port of localizeCfg() from generateAuConfigs.ts.
     */
    private static function localize_cfg(string $content, string $relativepath): string {
        // Replace "./" only at the start of JSON string values (after a quote).
        // Using str_replace('./', ...) would also match ./ inside ../ sequences.
        return preg_replace('#"\\./#', '"' . $relativepath . '/', $content);
    }

    /**
     * Find all AU directories in a package (directories containing config.json under compiled_course/blocks/).
     */
    private static function find_au_directories(\file_storage $fs, int $contextid,
            string $component, string $filearea, int $itemid): array {
        $audirs = [];
        $allfiles = $fs->get_area_files($contextid, $component, $filearea, $itemid, '', false);
        foreach ($allfiles as $file) {
            if ($file->get_filename() === 'config.json' &&
                    strpos($file->get_filepath(), '/compiled_course/blocks/') !== false) {
                $audirs[] = $file->get_filepath();
            }
        }
        return $audirs;
    }

    /**
     * Write string content to a file in the file area, replacing if it exists.
     */
    private static function write_file_content(\file_storage $fs, int $contextid,
            string $component, string $filearea, int $itemid,
            string $filepath, string $filename, string $content): void {
        $existing = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);
        if ($existing && !$existing->is_directory()) {
            $existing->delete();
        }
        $filerecord = [
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => $filepath,
            'filename' => $filename,
        ];
        $fs->create_file_from_string($filerecord, $content);
    }

    /**
     * Ensure a directory entry exists in the file area.
     */
    private static function ensure_directory(\file_storage $fs, int $contextid,
            string $component, string $filearea, int $itemid, string $dirpath): void {
        $existing = $fs->get_file($contextid, $component, $filearea, $itemid, $dirpath, '.');
        if (!$existing) {
            try {
                $fs->create_file_from_string([
                    'contextid' => $contextid,
                    'component' => $component,
                    'filearea' => $filearea,
                    'itemid' => $itemid,
                    'filepath' => $dirpath,
                    'filename' => '.',
                ], '');
            } catch (\Exception $e) {
                // Directory may already exist.
            }
        }
    }
}
