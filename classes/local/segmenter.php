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

namespace local_videoguard\local;

/**
 * Turns an uploaded video into signed-delivery HLS and repoints the activity at it.
 *
 * @package    local_videoguard
 * @copyright  2026 Aditek / Angel Aligner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class segmenter {

    /** Where segments live, as mounted into the containers (rw in cron, ro in php-fpm). */
    const ROOT = '/var/hls';

    /** Segment length. Six seconds is the usual VOD compromise between seek
     *  granularity and request count. */
    const SEGMENT_SECONDS = 6;

    /** Records which source produced the current segments, so re-runs are cheap. */
    const STAMP = '.source';

    /**
     * Directory holding one activity's segments.
     *
     * @param int $instanceid interactivevideo instance id
     * @return string
     */
    public static function dir_for(int $instanceid): string {
        return self::ROOT . '/iv' . $instanceid;
    }

    /**
     * The uploaded video for an activity, or null if there is none.
     *
     * @param \stdClass $cm course module record
     * @return \stored_file|null
     */
    public static function source_file(\stdClass $cm): ?\stored_file {
        $context = \context_module::instance($cm->id);
        $files = get_file_storage()->get_area_files(
            $context->id, 'mod_interactivevideo', 'video', 0, 'id', false);
        foreach ($files as $file) {
            if (!$file->is_directory()) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Whether the segments on disk are missing or stale for this source.
     *
     * Comparing against the stored contenthash is what makes the task safe to run
     * on every save and on every sweep: re-encoding 6 GB because a teacher renamed
     * an activity would be its own kind of outage.
     *
     * @param \stored_file $file
     * @param int $instanceid
     * @return bool
     */
    public static function needs_segmenting(\stored_file $file, int $instanceid): bool {
        $dir = self::dir_for($instanceid);
        $stamp = $dir . '/' . self::STAMP;

        if (!is_file($dir . '/stream.m3u8') || !is_file($stamp)) {
            return true;
        }
        return trim(file_get_contents($stamp)) !== $file->get_contenthash();
    }

    /**
     * Segments the file. Throws on any failure - callers must not fall back to
     * serving the unprotected original.
     *
     * @param \stored_file $file
     * @param int $instanceid
     * @throws \moodle_exception
     */
    public static function segment(\stored_file $file, int $instanceid): void {
        $dir = self::dir_for($instanceid);

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \moodle_exception('cannotcreatedir', 'error', '', $dir);
        }
        if (!is_writable($dir)) {
            // php-fpm mounts this read-only on purpose; only cron may write.
            throw new \moodle_exception('cannotwritedir', 'error', '', $dir);
        }

        // Build into a scratch directory and swap at the end, so a viewer never
        // meets a half-written playlist referencing segments that do not exist yet.
        $work = $dir . '.building';
        self::rmtree($work);
        if (!mkdir($work, 0755, true)) {
            throw new \moodle_exception('cannotcreatedir', 'error', '', $work);
        }

        // Read straight from the file pool - copying a 1.3 GB source to a temp file
        // first would double the disk cost of every run for no benefit.
        $source = get_file_storage()->get_file_system()
            ->get_local_path_from_storedfile($file, true);

        // -c copy: remux only, no re-encode. Lossless, and roughly a second per
        // 100 MB. The third data track some of these files carry is dropped by the
        // explicit -map, otherwise ffmpeg refuses to mux it into MPEG-TS.
        $cmd = sprintf(
            '/usr/bin/ffmpeg -hide_banner -loglevel error -y -i %s ' .
            '-map 0:v:0 -map 0:a:0 -c copy ' .
            '-f hls -hls_time %d -hls_list_size 0 -hls_playlist_type vod ' .
            '-hls_segment_filename %s %s 2>&1',
            escapeshellarg($source),
            self::SEGMENT_SECONDS,
            escapeshellarg($work . '/seg_%05d.ts'),
            escapeshellarg($work . '/stream.m3u8')
        );

        $output = [];
        $status = 0;
        exec($cmd, $output, $status);

        if ($status !== 0 || !is_file($work . '/stream.m3u8')) {
            self::rmtree($work);
            throw new \moodle_exception('segmentfailed', 'local_videoguard', '',
                'ffmpeg exit ' . $status . ': ' . implode(' | ', array_slice($output, -5)));
        }

        file_put_contents($work . '/' . self::STAMP, $file->get_contenthash());

        // Swap by rename, not delete-then-rename. Deleting first leaves a window -
        // short, but real - where the directory does not exist and every viewer of
        // that video gets "not processed yet". Renaming the old copy aside first
        // means the gap is a single rename, and the old segments are still there to
        // put back if the swap itself fails.
        $retired = $dir . '.retired';
        self::rmtree($retired);
        if (is_dir($dir) && !rename($dir, $retired)) {
            self::rmtree($work);
            throw new \moodle_exception('cannotcreatedir', 'error', '', $dir);
        }
        if (!rename($work, $dir)) {
            if (is_dir($retired)) {
                rename($retired, $dir);
            }
            self::rmtree($work);
            throw new \moodle_exception('cannotcreatedir', 'error', '', $dir);
        }
        self::rmtree($retired);

        // Permissions have to be set explicitly. mkdir()'s mode argument is masked
        // by the process umask, and this task runs as root inside the cron
        // container: the result was 0750 directories and 0660 files owned by root,
        // which php-fpm and nginx (both www-data) cannot read. Every freshly
        // processed video would have reported "still being prepared" forever, and
        // nothing in the task itself would have failed.
        self::make_readable($dir);
    }

    /**
     * Points the activity at the signed manifest.
     *
     * mod_interactivevideo resets these two columns to the file source every time
     * the activity is saved, so this has to run after each save rather than once.
     *
     * @param \stdClass $cm
     * @param int $instanceid
     */
    public static function repoint(\stdClass $cm, int $instanceid): void {
        global $DB, $CFG;

        $url = $CFG->wwwroot . '/local/videoguard/manifest.php/' . $cm->id . '/stream.m3u8';
        $DB->set_field('interactivevideo', 'source', 'url', ['id' => $instanceid]);
        $DB->set_field('interactivevideo', 'videourl', $url, ['id' => $instanceid]);
    }

    /**
     * Makes the tree readable by the web user.
     *
     * Deliberately not chown: the containers disagree about numeric uids, and mode
     * bits are the portable answer. The segments are not secret at rest - they are
     * protected in transit by the signed URL, and the host filesystem is not served.
     *
     * @param string $path
     */
    protected static function make_readable(string $path): void {
        @chmod($path, 0755);
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? self::make_readable($full) : @chmod($full, 0644);
        }
    }

    /**
     * Recursively removes a directory. Missing is fine.
     *
     * @param string $path
     */
    protected static function rmtree(string $path): void {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? self::rmtree($full) : unlink($full);
        }
        rmdir($path);
    }
}
