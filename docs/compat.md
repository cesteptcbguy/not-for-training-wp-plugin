# Compatibility

Results of testing Not For Training alongside other plugins, on a wp-env site (WordPress 7.1.5, PHP 8.3, Twenty Twenty-Five). Each plugin was tested on its own, at its latest free version from WordPress.org.

## SEO plugins

Tested 2026-11-24. Pages checked: the homepage, a post, a category archive, and a page set to noindex in the SEO plugin.

| Plugin | Version | Robots meta without a shim | Shim | Robots meta with the shim | robots.txt | Status check |
| --- | --- | --- | --- | --- | --- | --- |
| Yoast SEO | 28.9 | One tag, noai and noimageai merged (Yoast uses `wp_robots`) | None needed | Same | Our block first, Yoast's block (with the only `*` group) last | All OK |
| Rank Math | 1.0.282 | One tag, Rank Math's own, **no noai** | `includes/compat/rank-math.php` | One tag, noai and noimageai added after Rank Math's values | Core's rules, then our block | All OK |
| All in One SEO | 5.0.5.1 | One tag, AIOSEO's own, **no noai** | `includes/compat/aioseo.php` | One tag, noai and noimageai added | **Without the shim, our block was rewritten** (see below). With it: AIOSEO's output, then our block, intact | All OK |
| SEOPress | 10.4 | **Two tags**: core's (only noai, noimageai) and SEOPress's | `includes/compat/seopress.php` | One tag, noai and noimageai added after SEOPress's values | Core's rules, then our block | All OK |

On every noindex page, the SEO plugin's `noindex` (and its other values) stayed, with noai and noimageai added after them. The `X-Robots-Tag: noai, noimageai` header was sent with every plugin.

### How each plugin prints robots meta

- **Yoast SEO** builds its tag from core's `wp_robots` list, so our values arrive like any other.
- **Rank Math** calls `remove_all_filters( 'wp_robots' )` and prints its own tag. The shim adds our values through `rank_math/frontend/robots` (a keyed array, joined with ", ").
- **All in One SEO** also calls `remove_all_filters( 'wp_robots' )` and prints its own tag. The shim adds our values through `aioseo_robots_meta` (a keyed array, joined with ", ").
- **SEOPress** prints its own tag from `wp_head` (`seopress_titles_advanced_robots_hook`) but leaves core's `wp_robots` running, so our values kept core's tag alive as a second tag. The shim adds our values through `seopress_titles_robots_attrs` and keeps them out of core's list while SEOPress's tag is going out. When SEOPress doesn't print a tag (its Titles module is off, or the site discourages search engines), core's tag keeps them. Checked with search engines discouraged: one tag, `noindex, nofollow, noai, noimageai`.

Shims load only when their plugin is active, detected by `RANK_MATH_VERSION`, `AIOSEO_VERSION` or `SEOPRESS_VERSION` on `plugins_loaded`.

### robots.txt editors

| Plugin | Editor | How it works | Status check |
| --- | --- | --- | --- |
| Yoast SEO | Tools > File editor | Saves a **physical file**, seeded with the current output (our block included). Nothing virtual. | No file: OK. After "Create robots.txt file": FYI, frozen_current. After changing the AI bot mode: Check this, frozen_stale. After editing the file to drop our block: Problem, physical file. |
| Rank Math | General Settings > Edit robots.txt | **Virtual**: replaces WordPress's output at priority 10. Our block (priority 999) is still added after it. The custom text replaces Rank Math's own Sitemap line. | OK |
| All in One SEO | Tools > Robots.txt Editor | **Virtual**: its rules are merged into the generated output (a `Disallow: /private/` rule joined the `*` group). | OK (with the shim) |
| SEOPress | None in the free version (robots.txt is a Pro feature) | | |

### AIOSEO's robots.txt rewrite

AIOSEO rebuilds robots.txt on `robots_txt` at priority 10000, re-parsing everything before it. Its parser (`extractRules()`) gives user agents that share a group only the group's first rule, and drops comments. Without the shim:

- Each AI training bot still got `Disallow: /`.
- Each AI search bot except the last got only `Allow: /wp-admin/admin-ajax.php`. That still allows everything, but without the `/wp-admin/` exception.
- The `# Not For Training` marker was gone, so the status check reported the rules as replaced.

The shim moves our filter to priority 10001 while AIOSEO is active, so our block goes out after AIOSEO's output, exactly as written.

### Rule order and search engines

- Yoast moves its block, with the only `*` group, to the end. Order doesn't matter to crawlers.
- With Yoast, and with a Rank Math custom robots.txt, the `*` group no longer disallows `/wp-admin/`, while our AI search group still does. AI search bots are then slightly stricter than search engines on `/wp-admin/` only.
- Search engines were never blocked by any combination tested.

### Setup notes

- Rank Math keeps its front end off until its setup wizard is connected or skipped. Testing used the wizard's "skip" option (`rank_math_registration_skip`).

## Caching plugins

Tested 2026-11-24, each plugin on its own, with page caching on and a logged-out visitor's homepage cached. WP Rocket wasn't tested: it isn't free.

| Plugin | Version | noai meta in cached HTML | X-Robots-Tag and tdm-reservation on cached hits | page_cache FYI | Stale homepage detected | After the plugin's purge | Minify and combine |
| --- | --- | --- | --- | --- | --- | --- | --- |
| W3 Total Cache | 2.11.0 | Yes | **No** (served from disk by `.htaccess` rules) | Yes | Yes, "Check this" | OK, on the second check (see below) | OK |
| WP Fastest Cache | 1.5.5 | Yes | **No** (served from disk by `.htaccess` rules) | Yes | Yes, "Check this" | OK | OK |
| Cache Enabler | 1.8.17 | Yes | **No** (served early by its `advanced-cache.php`, before WordPress loads) | Yes | Yes, after a fix (see below) | OK | OK |
| LiteSpeed Cache | 7.9.4 | Not cached (Apache, not a LiteSpeed server) | Yes (pages aren't cached) | Yes | Not applicable | Not applicable | OK, after a fix for "Load JS Delayed" (see below) |

None of the four sends our response headers on cached pages. The no-AI meta tag is in every cached page, so the page-level signal always reaches visitors and crawlers. For the headers on cached pages, set them in the server configuration. The readme's caching FAQ says so.

### Stale pages

The test: turn "Add no-AI tags" off on the settings page, let the homepage be cached, then turn it back on. None of the four plugins purges its own cache when our settings are saved, so visitors kept getting the cached homepage without the tags. (These tests ran before the plugin started purging on save; see "Purging on settings save" below.)

- **W3 Total Cache:** detected ("Check this"). Its "flush all" renames cached files to `*_old` and serves the old copy once more while it regenerates, so the first "Check again" after a flush can still say "Check this". The second says OK.
- **WP Fastest Cache:** detected, and OK after `wpfc_clear_all_cache()`.
- **Cache Enabler:** at first the status check said OK while visitors got the stale page. Cache Enabler only serves cached pages to requests whose `Accept` header includes `text/html`, and the loopback (cURL) sent `Accept: */*`, so it got a fresh page. **Fixed:** the homepage check now sends a browser's `Accept` header. After that: "Check this" on the stale page, OK after `cache_enabler_clear_complete_cache`.

### Minify, combine and delay

Checked on a cached post with an image block and a gallery, as a logged-out visitor in Chrome: right-click blocked on both images, right-click on text still works, drag blocked, `user-select: none`, no JavaScript errors.

- **W3 Total Cache:** JS, CSS and HTML minify with auto combine, plus inline JS and CSS minify. Our script stays inline (minified). OK.
- **WP Fastest Cache (free):** HTML and CSS minify, combine CSS, combine JS, render-blocking JS. The premium options (JS minify, the "powerful" variants) don't apply on the free version. Our script stays inline. OK.
- **Cache Enabler:** HTML minify including inline CSS and JS (it has no combine). OK.
- **LiteSpeed Cache:** JS, CSS and HTML minify, JS and CSS combine, including "combine external and inline JS". It moves our inline script and CSS into combined files. OK. "Load JS Deferred" is OK too.
- **LiteSpeed "Load JS Delayed":** every script waits for the visitor's first interaction, so **the first right-click on an image opened the menu.** **Fixed:** with LiteSpeed active, the save friction script tag gets `data-no-defer="1"`, which LiteSpeed leaves out of combining, deferring and delaying (`includes/compat/litespeed.php`, loaded only when `LSCWP_V` is defined). After the fix, the first right-click is blocked with delay and combine on. The CSS part (`user-select`, the iOS long-press rule) was never delayed.

### Purging on settings save

When the settings page is saved and a setting that changes page output actually changed (no-AI tags, rights-reserved notice, save friction, or its extra selectors), the plugin clears every page cache it finds, then clears its own status cache. Changes to the AI bot mode or the image settings don't purge. Nothing is purged at any other time: not on activation, deactivation, "Check again", cron, or option updates from code. The "Settings saved." notice gets one more line: "Cleared {name}'s page cache so visitors see the change.", or with several, "Cleared the page caches from A, B and C so visitors see the change." Without a cache plugin, the notice is core's plain "Settings saved.".

For 60 seconds after a purge, a missing no-AI tag on the homepage is reported as "Your page cache was just cleared. Some caching plugins take up to a minute to catch up. Check again in a minute." (still "Check this"), and that result is only cached for 60 seconds. After that, the usual message returns.

| Plugin | Purge called | Checked |
| --- | --- | --- |
| WP Super Cache | `wp_cache_clear_cache()` | In its code (3.1.5: `wp_cache_clear_cache( $blog_id = 0 )`), and on the dev site |
| W3 Total Cache | `w3tc_flush_all()` | In its code (2.11.0, `w3-total-cache-api.php`: `w3tc_flush_all( $extras = null )`), and on the dev site |
| WP Fastest Cache | `wpfc_clear_all_cache()` | In its code (1.5.5: `wpfc_clear_all_cache( $minified = false )`) |
| Cache Enabler | `do_action( 'cache_enabler_clear_complete_cache' )` | In its code (1.8.17), and on the dev site |
| LiteSpeed Cache | `do_action( 'litespeed_purge_all' )`, only when `LITESPEED_ALLOWED` is defined | In its code (7.9.4). LiteSpeed defines `LITESPEED_ALLOWED` only where it can cache (a LiteSpeed server), so on other servers there's nothing to clear and no notice. |
| WP Rocket | `rocket_clean_domain()`, only if the function exists | **Unverified**: WP Rocket isn't free, so its code wasn't checked |

Results on the dev site, each plugin on its own: cache the homepage with no-AI tags off, then turn them on and save.

- **WP Super Cache:** the save cleared the cache, visitors got the tags on the next (cached) request, and the status check went straight to OK.
- **Cache Enabler:** same.
- **W3 Total Cache:** its flush keeps serving the old copy of a page for up to 30 seconds after that copy was cached (`Cache_File_Generic::get_with_old()`). With the homepage cached a few seconds before the save, the first check after saving said "Check this" until the 30 seconds passed, then OK. (That first check now shows the "just cleared" message above.) With the homepage cached more than 30 seconds before the save, the first check said OK.

### Test environment notes

- Cache Enabler names its settings file after the host without the port (`localhost.php`), but looks it up early in a request using the `Host` header with the port (`localhost:8888.php`). On wp-env's port 8888 it never cached until the file was also saved under the second name. Sites on ports 80 and 443 aren't affected.
- Every plugin was removed afterwards, along with what its uninstall left behind: W3 Total Cache's `wp-content/cache` and `wp-content/w3tc-config`, WP Fastest Cache's `wp-content/cache` folders, and Cache Enabler's `WP_CACHE` block in `wp-config.php` and its `wp-content/settings` folder.

## Imagick on DDEV

Tested 2026-11-24 on a throwaway DDEV site (DDEV 1.25.3, nginx-fpm, PHP 8.3.31, WordPress 7.1.5), with the plugin mounted read-only from this repo. The site was deleted afterwards.

**Image editor:** Imagick 3.8.1 with ImageMagick 7.1.1-43 Q16. It reads JPEG, HEIC, HEIF, AVIF and WebP. WordPress picked `WP_Image_Editor_Imagick` for JPEG, `wp_image_editor_supports( image/heic )` was true, and the status check's image editor line said OK ("Your server keeps copyright info in smaller copies of images.").

### Test images

Three 3000x2000 images, uploaded through Media Library > Add New (the browser uploader) as an admin. All carried EXIF Copyright and Artist, plus Make, Model and DateTimeOriginal.

| File | GPS | Other copyright fields |
| --- | --- | --- |
| `big-gps-all.jpg` | EXIF GPS (latitude, longitude, altitude) | IPTC CopyrightNotice and By-line, XMP dc:rights and photoshop:Credit |
| `xmp-gps-only.jpg` | XMP only (`exif:GPSLatitude`, `GPSLongitude`, `GPSAltitude`) | Same as above |
| `heic-gps.heic` | EXIF GPS | XMP dc:rights and photoshop:Credit. No IPTC: macOS `sips` dropped it when converting from the JPEG, so it was never in the source. |

Every file WordPress wrote was checked with ExifTool 13.55: the main file, each size, and `original_image` when kept. "GPS tags" counts EXIF GPS, XMP `exif:GPS*` and the composite GPS position.

### Run 1: "Only keep the smaller copy" off (default)

| Upload | Files | Largest generated | 2048 size | GPS tags in any file | Copyright fields |
| --- | --- | --- | --- | --- | --- |
| big-gps-all.jpg | `-scaled` 1600x1067, 1536x1024, 1024x683, 768x512, 300x200, 150x150, original 3000x2000 | 1600 | No | 0 | All six in every file |
| xmp-gps-only.jpg | Same set | 1600 | No | 0 | All six in every file |
| heic-gps.heic | JPEG `-scaled` 1600x1067 and the same sizes (WordPress converted it to JPEG), original `heic-gps.heic` kept | 1600 | No | 0 | EXIF Copyright and Artist, XMP rights and credit in every file (the source had no IPTC) |

The kept originals had their GPS removed at upload too: the JPEGs by the byte-level EXIF and XMP strip, the HEIC by the in-place GPS IFD neutralization. `exiftool -validate` on the kept `heic-gps.heic`: OK.

### Run 2: "Only keep the smaller copy" on

The same three files, uploaded again (WordPress added `-1` to the names).

| Upload | Files | Original on disk | `original_image` in metadata | GPS tags in any file | Copyright fields |
| --- | --- | --- | --- | --- | --- |
| big-gps-all.jpg | `-scaled` 1600x1067 and the five sizes, no 2048 | No (`big-gps-all-1.jpg` gone) | No | 0 | All six in every file |
| xmp-gps-only.jpg | Same | No (`xmp-gps-only-1.jpg` gone) | No | 0 | All six in every file |
| heic-gps.heic | JPEG `-scaled` 1600x1067 and the five sizes, no 2048 | No (`heic-gps-1.heic` gone) | No | 0 | EXIF Copyright and Artist, XMP rights and credit |

### ExifTool output

The tag list used: `-a -G1 -s -GPS:all -XMP-exif:GPS* -Composite:GPSPosition -IFD0:Copyright -IFD0:Artist -IPTC:CopyrightNotice -IPTC:By-line -XMP-dc:Rights -XMP-photoshop:Credit -Composite:ImageSize`, shown as `<tags>`. No GPS line appears in any output.

Run 1:

```
$ exiftool <tags> wp-content/uploads/2026/11/big-gps-all-scaled.jpg
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[IFD0]          Artist                          : Jane Doe
[IPTC]          CopyrightNotice                 : Copyright 2026 Jane Doe
[IPTC]          By-line                         : Jane Doe
[XMP-dc]        Rights                          : (c) 2026 Jane Doe. All rights reserved.
[XMP-photoshop] Credit                          : Jane Doe Studio
[Composite]     ImageSize                       : 1600x1067

$ exiftool <tags> wp-content/uploads/2026/11/big-gps-all-150x150.jpg
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[IFD0]          Artist                          : Jane Doe
[IPTC]          CopyrightNotice                 : Copyright 2026 Jane Doe
[IPTC]          By-line                         : Jane Doe
[XMP-dc]        Rights                          : (c) 2026 Jane Doe. All rights reserved.
[XMP-photoshop] Credit                          : Jane Doe Studio
[Composite]     ImageSize                       : 150x150

$ exiftool <tags> wp-content/uploads/2026/11/big-gps-all.jpg
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[IFD0]          Artist                          : Jane Doe
[IPTC]          CopyrightNotice                 : Copyright 2026 Jane Doe
[IPTC]          By-line                         : Jane Doe
[XMP-dc]        Rights                          : (c) 2026 Jane Doe. All rights reserved.
[XMP-photoshop] Credit                          : Jane Doe Studio
[Composite]     ImageSize                       : 3000x2000

$ exiftool <tags> wp-content/uploads/2026/11/xmp-gps-only-scaled.jpg
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[IFD0]          Artist                          : Jane Doe
[IPTC]          CopyrightNotice                 : Copyright 2026 Jane Doe
[IPTC]          By-line                         : Jane Doe
[XMP-dc]        Rights                          : (c) 2026 Jane Doe. All rights reserved.
[XMP-photoshop] Credit                          : Jane Doe Studio
[Composite]     ImageSize                       : 1600x1067

$ exiftool <tags> wp-content/uploads/2026/11/heic-gps-scaled.jpg
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[IFD0]          Artist                          : Jane Doe
[XMP-dc]        Rights                          : (c) 2026 Jane Doe. All rights reserved.
[XMP-photoshop] Credit                          : Jane Doe Studio
[Composite]     ImageSize                       : 1600x1067

$ exiftool <tags> wp-content/uploads/2026/11/heic-gps.heic
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[IFD0]          Artist                          : Jane Doe
[XMP-dc]        Rights                          : (c) 2026 Jane Doe. All rights reserved.
[XMP-photoshop] Credit                          : Jane Doe Studio
[Composite]     ImageSize                       : 3000x2000

$ exiftool -validate -warning -error -a -G1 -s wp-content/uploads/2026/11/heic-gps.heic
[ExifTool]      Validate                        : OK
```

Run 2:

```
$ exiftool <tags> wp-content/uploads/2026/11/big-gps-all-1-scaled.jpg
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[IFD0]          Artist                          : Jane Doe
[IPTC]          CopyrightNotice                 : Copyright 2026 Jane Doe
[IPTC]          By-line                         : Jane Doe
[XMP-dc]        Rights                          : (c) 2026 Jane Doe. All rights reserved.
[XMP-photoshop] Credit                          : Jane Doe Studio
[Composite]     ImageSize                       : 1600x1067

$ exiftool <tags> wp-content/uploads/2026/11/xmp-gps-only-1-scaled.jpg
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[IFD0]          Artist                          : Jane Doe
[IPTC]          CopyrightNotice                 : Copyright 2026 Jane Doe
[IPTC]          By-line                         : Jane Doe
[XMP-dc]        Rights                          : (c) 2026 Jane Doe. All rights reserved.
[XMP-photoshop] Credit                          : Jane Doe Studio
[Composite]     ImageSize                       : 1600x1067

$ exiftool <tags> wp-content/uploads/2026/11/heic-gps-1-scaled.jpg
[IFD0]          Copyright                       : (c) 2026 Jane Doe. All rights reserved.
[IFD0]          Artist                          : Jane Doe
[XMP-dc]        Rights                          : (c) 2026 Jane Doe. All rights reserved.
[XMP-photoshop] Credit                          : Jane Doe Studio
[Composite]     ImageSize                       : 1600x1067

```

### Finding: common nginx configs block robots.txt and .well-known

The status check on this site reported two problems that come from DDEV's stock WordPress nginx config, not from the plugin:

- **robots_served: Problem (replaced).** The config has `location = /robots.txt { allow all; ... }` with no fallback to `index.php`, so with no physical file nginx returns 404 and WordPress's robots.txt never runs. This affects every plugin that adds to robots.txt. WordPress's output was correct at `/?robots=1`.
- **tdmrep: Problem.** The config has `location ~ /\. { deny all; }`, which returns 403 for `/.well-known/tdmrep.json`. It would also block an uploaded static `tdmrep.json`, so on this config the download-and-upload advice alone doesn't fix it.

Both are common in WordPress nginx configs. The fix is on the server:

```nginx
location = /robots.txt {
    try_files $uri /index.php?$args;
    access_log off;
    log_not_found off;
}

location ^~ /.well-known/ {
    allow all;
    try_files $uri /index.php?$args;
}
```

The `^~` prefix makes nginx use the `/.well-known/` block for those paths without checking the regex locations, so the `location ~ /\.` deny rule no longer applies there. Where the block sits in the file doesn't matter.

Possible follow-ups, not built: for a 404 robots.txt or a 403 tdmrep.json, the status messages could say that the web server isn't passing the request to WordPress, and point to these lines.
