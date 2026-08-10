# Video Guard (local_videoguard)

Serves `mod_interactivevideo` uploads as **HLS with per-segment signed URLs**, so a
copied video address is worth nothing.

![Moodle](https://img.shields.io/badge/Moodle-4.4%2B-orange.svg)
![License](https://img.shields.io/badge/License-GPLv3-blue.svg)

**Author:** Daividdi

---

## The problem

`mod_interactivevideo` points the player straight at the uploaded MP4 through
`pluginfile.php`. Enrolment is checked, and that is the whole of it. Any enrolled
user can open DevTools, copy the address and download the entire film — and hand
that address to anyone else, because it stays valid indefinitely.

## What this does

- Segments each video into HLS with `ffmpeg -c copy` — a remux, so **no re-encode,
  no quality loss**, and roughly a second per 100 MB.
- Emits the playlist from a PHP endpoint that checks login, enrolment, group and
  availability restrictions, with **every segment URL individually signed**.
- Has nginx validate those signatures itself via `secure_link`, so the media never
  passes through PHP and no worker is tied up for the length of a download.
- Stamps the viewer's name and email over the player, drifting between nine
  positions so a fixed-frame capture cannot crop it out of every frame.
- Runs automatically: an event observer queues work when an activity is saved, and
  an hourly sweep catches what the observer never sees — course restores and web
  service uploads among them.

## What this does not do

Downloading becomes "capture every segment inside the signing window and remux
them", which is beyond a casual user and trivial for a determined one. And nothing
here touches screen recording — no DRM does either.

Two limits worth stating plainly:

- **A signed URL works without a session for as long as it is valid.** `secure_link`
  validates a signature, not a cookie. Anyone handed that URL can fetch that segment
  until it expires.
- **The window cannot be short.** hls.js fetches a VOD playlist once, so the
  signature has to outlive the whole viewing session including pauses. The default
  is four hours. That bounds sharing and makes a copied URL useless the next day; it
  is not a sixty-second token, and it cannot be.

### The overlay is on the screen, not in the file

This is the limit that matters most, and it is easy to assume otherwise.

The viewer's name and email are drawn as an HTML element over the player. **Nothing
is burned into the video itself** — `ffmpeg` runs with `-c copy`, a remux, and no
video filter touches the frames.

So:

| How the video escapes | Traceable? |
| --- | --- |
| Screen recording, or a phone pointed at the monitor | **yes** — the overlay is in the recording |
| Segments captured and remuxed back into a file | **no** — that file carries no mark at all |

The delivery controls make the second case hard: a segment fetched without a valid
signature or session is refused, and reassembling a film means collecting hundreds of
pieces. But if someone does it, what they end up with is clean.

Burning a per-viewer mark into the frames would mean transcoding the whole library
once per viewer. For a library of a few gigabytes that is not a tuning decision, it
is a different product — so this is a stated limitation, not an oversight.

**A watermarked PDF does not have this weakness**: there the mark is inside the file
and survives being downloaded and forwarded. If your most sensitive material can be a
document rather than a video, it is the stronger medium.

The overlay is also removable from the developer console, deliberately. It is aimed
at the ordinary user who would otherwise film the screen without a second thought,
not at an adversary who already has better options.

---

## Requirements

- Moodle 4.4+ (needs `\core\hook\output\before_footer_html_generation`)
- `mod_interactivevideo`
- **ffmpeg** reachable by the process that runs Moodle cron
- A delivery backend, either:
  - **nginx** with `--with-http_secure_link_module` (in the official image already), or
  - **Apache** with `mod_xsendfile`, or nginx, for the session-check mode

## Delivery modes

Set under *Site administration → Plugins → Local plugins → Video guard*.

**Signed URL** (`securelink`) — the web server validates a signature by itself, so
media never touches PHP. Requires nginx. The signature stands in for a session,
which is exactly its weakness: a copied URL keeps working, logged in or not, until
it expires, and the window cannot be short because hls.js fetches a VOD playlist
once.

**Session check + send-file** (`xsendfile`) — every segment goes back through
Moodle, which re-checks enrolment and availability, then hands the file to the web
server via `X-Sendfile` (Apache) or `X-Accel-Redirect` (nginx). A copied URL is
worthless to anyone not logged in, and there is no expiry window at all. The cost is
one Moodle bootstrap per segment; the worker is released the moment the header is
written, so it is not a held connection.

**Where both are available, prefer the session check.** The signed URL exists for
the case where the web server cannot consult a session, not because it is stronger.

## Installation

**1. Install the plugin**

```bash
cd /path/to/moodle/local          # Moodle 5.x: /path/to/moodle/public/local
git clone https://github.com/Daividdi/moodle-local_videoguard.git videoguard
php admin/cli/upgrade.php --non-interactive
```

**2. Share a secret between Moodle and nginx**

In `config.php`:

```php
$CFG->videoguard_secret = 'a-long-random-string';
```

**3. Serve the segments**

Segments are written to `/var/hls` (see `segmenter::ROOT`). That path must be
writable by cron and readable by the web server.

```nginx
location ^~ /hls/ {
    secure_link $arg_st,$arg_e;
    secure_link_md5 "$secure_link_expires$uri a-long-random-string";

    if ($secure_link = "")  { return 403; }   # missing or invalid
    if ($secure_link = "0") { return 410; }   # expired

    root /var;
    autoindex off;
    add_header Cache-Control "private, no-store" always;
}
```

The secret must match `$CFG->videoguard_secret` **exactly**, and so must the
expression — the concatenation order and that single space are part of the contract.
Get either wrong and every segment returns 403.

> Do **not** add nested `location` blocks here. A nested location that matches does
> not inherit `secure_link` from its parent, and would serve the segments with no
> signature check at all.

**4. Let it run**

Nothing else. Existing videos are picked up by the next hourly sweep; new uploads
are queued the moment the activity is saved and processed on the next cron run.

---

## How it behaves

A teacher uploads a video and saves. Within about a minute — almost all of it
waiting for cron, not converting — the activity is segmented and repointed at the
signed playlist. No extra step for the teacher.

During that window the original MP4 still plays, unsegmented. That is deliberate: a
course under construction has no audience yet. If your videos are added to live
courses with students already enrolled, that window is a real exposure and you
should weigh it.

`mod_interactivevideo` resets its source columns every time an activity is saved, so
the plugin repoints on every save rather than once. The cycle is self-correcting: a
teacher who re-uploads simply triggers it again.

**One thing your teachers will notice:** after processing, the activity's edit form
shows the video source as a URL rather than the file they uploaded. The file is
still there — the activity now points at the protected stream. Re-uploading a file
resets it and the cycle runs again.

---

## Privacy

The player overlay displays the viewer's name and email on screen. Nothing is
stored beyond Moodle's own logs.

## Licence

GPL v3 or later, matching Moodle.
