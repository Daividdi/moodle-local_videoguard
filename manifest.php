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

/**
 * Emits an HLS manifest whose every segment URL is signed and short-lived.
 *
 * This exists because mod_interactivevideo stores the video address in a plain DB
 * column (`videourl`), which is rendered as-is - a signed URL there would be frozen
 * at save time and expire. Pointing that column at THIS script instead moves the
 * signing to request time, once per viewer.
 *
 * The segments themselves never pass through PHP: nginx validates the signature
 * with secure_link and streams the file, so no php-fpm worker is held for the
 * length of a download.
 *
 * URL shape (slasharguments): /local/videoguard/manifest.php/<cmid>/stream.m3u8
 * The trailing .m3u8 is load-bearing - mod_interactivevideo's html5video player
 * only reaches for hls.js when the URL contains it.
 *
 * @package    local_videoguard
 * @copyright  2026 Aditek / Angel Aligner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

/**
 * How long a signed segment URL stays valid.
 *
 * This cannot be short. hls.js fetches a VOD manifest ONCE, so the signature has
 * to outlive the whole viewing session including pauses - if it expires mid-watch
 * the player simply stalls with 410s. Four hours bounds link sharing and makes a
 * copied URL useless the next day, which is the realistic goal; it is not, and
 * cannot be, a sixty-second token.
 */
const VIDEOGUARD_TTL = 4 * HOURSECS;


/**
 * Signs a path exactly the way nginx's secure_link_md5 will verify it.
 *
 * The expression on the nginx side is "$secure_link_expires$uri <secret>", so the
 * concatenation order and that single space are part of the contract - change one
 * and every segment starts returning 403.
 *
 * @param string $uri path only, no query string, as nginx sees it
 * @param int $expires unix timestamp
 * @param string $secret shared with nginx
 * @return string base64url-encoded md5, as secure_link expects
 */
function local_videoguard_sign(string $uri, int $expires, string $secret): string {
    $raw = md5($expires . $uri . ' ' . $secret, true);
    return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($raw));
}

$relativepath = get_file_argument();
if (empty($relativepath)) {
    throw new moodle_exception('invalidargument');
}

$args = explode('/', trim($relativepath, '/'));
$cmid = (int)array_shift($args);
if ($cmid <= 0) {
    throw new moodle_exception('invalidargument');
}

$cm = get_coursemodule_from_id('interactivevideo', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);

// The whole access decision lives here: enrolment, group and availability
// restrictions, and whether the activity is visible to this user at all.
require_login($course, false, $cm);

$mode = get_config('local_videoguard', 'delivery') ?: 'securelink';

// The shared secret only exists to stand in for a session the web server cannot
// read. In gatekeeper mode there is a real session check, so it is not required.
$secret = $CFG->videoguard_secret ?? '';
if ($mode === 'securelink' && $secret === '') {
    throw new moodle_exception('misconfigured', 'local_videoguard');
}

$dir = \local_videoguard\local\segmenter::ROOT . '/iv' . (int)$cm->instance;
$source = $dir . '/stream.m3u8';
if (!is_readable($source)) {
    // Not segmented yet, or segmentation failed. Fail with a clear message rather
    // than falling back to the unprotected original.
    throw new moodle_exception('notprocessed', 'local_videoguard');
}

$expires = time() + VIDEOGUARD_TTL;
$instance = (int)$cm->instance;
$out = [];

foreach (file($source, FILE_IGNORE_NEW_LINES) as $line) {
    // Tags and blanks pass through untouched; anything else is a segment filename.
    if ($line === '' || $line[0] === '#') {
        $out[] = $line;
        continue;
    }
    $segment = basename(trim($line));

    if ($mode === 'securelink') {
        // The web server validates a signature it can check without PHP.
        $uri = '/hls/iv' . $instance . '/' . $segment;
        $sig = local_videoguard_sign($uri, $expires, $secret);
        $out[] = $CFG->wwwroot . $uri . '?st=' . $sig . '&e=' . $expires;
    } else {
        // Every segment goes back through Moodle, which re-checks the session.
        $out[] = $CFG->wwwroot . '/local/videoguard/segment.php/'
            . $cm->id . '/' . $segment;
    }
}

// The manifest is per-user and time-limited, so it must never be cached anywhere -
// a shared cache would hand one viewer's signatures to the next.
header('Content-Type: application/vnd.apple.mpegurl; charset=utf-8');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
echo implode("\n", $out) . "\n";
