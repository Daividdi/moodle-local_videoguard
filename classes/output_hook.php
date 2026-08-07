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

namespace local_videoguard;

/**
 * Stamps the viewer's identity over the video player.
 *
 * Every other control here protects the FILE. This one protects nothing - it is
 * aimed squarely at the vector none of them can touch: someone recording their
 * own screen, or pointing a phone at it. No DRM stops that. What an identified
 * overlay does is make the resulting recording traceable to the account that
 * played it, which is the only leverage that survives the copy.
 *
 * It is removable from the developer console, and that is fine: the audience for
 * this is the ordinary user who would otherwise film the screen without a second
 * thought, not the determined adversary who already has better options.
 *
 * @package    local_videoguard
 * @copyright  2026 Aditek / Angel Aligner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class output_hook {

    /**
     * Injects the overlay on interactive video pages only.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(
            \core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $USER;

        if (!isloggedin() || isguestuser()) {
            return;
        }
        // Match on the page URL, not on $PAGE->cm: mod_interactivevideo's view.php
        // calls set_context() but never set_cm(), so $PAGE->cm is empty there and a
        // cm-based guard silently suppresses the overlay on the only page that
        // needs it. The cm check is kept as a second signal for other entry points.
        $path = $PAGE->url ? $PAGE->url->get_path() : '';
        $isvideopage = (substr($path, -strlen('/mod/interactivevideo/view.php'))
                === '/mod/interactivevideo/view.php')
            || (!empty($PAGE->cm) && $PAGE->cm->modname === 'interactivevideo');

        if (!$isvideopage) {
            return;
        }

        $label = fullname($USER);
        if (!empty($USER->email)) {
            $label .= ' · ' . $USER->email;
        }
        $payload = json_encode($label, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
            | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $hook->add_html(self::markup($payload));
    }

    /**
     * The overlay's CSS and JS.
     *
     * @param string $payload JSON-encoded identity string
     * @return string
     */
    protected static function markup(string $payload): string {
        return <<<HTML
<style>
#videoguard-stamp {
    position: absolute;
    z-index: 30;
    /* Must never intercept a click: the player's own controls live underneath. */
    pointer-events: none;
    user-select: none;
    font: 600 13px/1.3 system-ui, -apple-system, "Segoe UI", sans-serif;
    letter-spacing: .01em;
    color: rgba(255, 255, 255, .55);
    /* Dark halo so the text stays legible over a white slide as well as a dark one. */
    text-shadow: 0 1px 3px rgba(0, 0, 0, .85), 0 0 8px rgba(0, 0, 0, .55);
    white-space: nowrap;
    transition: top .8s ease, left .8s ease, opacity .8s ease;
    max-width: 92%;
    overflow: hidden;
    text-overflow: ellipsis;
}
@media (max-width: 576px) { #videoguard-stamp { font-size: 11px; } }
</style>
<script>
(function () {
    var LABEL = {$payload};

    function place(el) {
        // Wanders between nine positions rather than sitting in one corner, so a
        // crop or a fixed-frame capture cannot simply cut it out of every frame.
        var xs = [6, 36, 66], ys = [5, 46, 88];
        el.style.left = xs[Math.floor(Math.random() * xs.length)] + '%';
        el.style.top  = ys[Math.floor(Math.random() * ys.length)] + '%';
        el.style.opacity = (0.42 + Math.random() * 0.28).toFixed(2);
    }

    function mount() {
        var host = document.getElementById('wrapper');
        if (!host || document.getElementById('videoguard-stamp')) {
            return !!host;
        }
        // The host already carries position-relative from the activity template;
        // set it defensively in case that markup changes upstream.
        if (getComputedStyle(host).position === 'static') {
            host.style.position = 'relative';
        }
        var el = document.createElement('div');
        el.id = 'videoguard-stamp';
        el.textContent = LABEL;
        el.setAttribute('aria-hidden', 'true');
        host.appendChild(el);
        place(el);
        setInterval(function () { place(el); }, 7000);

        // Re-attach if the player rebuilds its DOM, which it does when the video
        // is swapped or the layout changes; without this the stamp silently
        // disappears for the rest of the session.
        new MutationObserver(function () {
            if (!document.getElementById('videoguard-stamp')) {
                mount();
            }
        }).observe(host, {childList: true});
        return true;
    }

    if (!mount()) {
        // The player mounts asynchronously; retry briefly, then give up rather
        // than leave a timer running for the life of the page.
        var tries = 0;
        var timer = setInterval(function () {
            if (mount() || ++tries > 40) {
                clearInterval(timer);
            }
        }, 250);
    }
})();
</script>
HTML;
    }
}
