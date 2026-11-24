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

The test: turn "Add no-AI tags" off on the settings page, let the homepage be cached, then turn it back on. None of the four plugins purges its cache when our settings are saved, so visitors kept getting the cached homepage without the tags.

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

### Purging on settings save (not built)

Every plugin above has a public purge, so saving our settings could clear the page cache:

| Plugin | Public purge | Source |
| --- | --- | --- |
| W3 Total Cache | `w3tc_flush_all()` | Its documentation; not checked in its code here |
| WP Fastest Cache | `wpfc_clear_all_cache()` | Checked in its code and used in these tests |
| Cache Enabler | `do_action( 'cache_enabler_clear_complete_cache' )` | Used in these tests |
| LiteSpeed Cache | `do_action( 'litespeed_purge_all' )` | Its documentation; not checked in its code here |
| WP Rocket | `rocket_clean_domain()` | Its documentation; not tested (not free) |

Before building this, check each name against that plugin's current code.

Proposal, not built: on `update_option_notfortraining_settings`, and only if a setting that changes page output changed (no-AI tags, rights-reserved notice, save friction or its selectors), call whichever of these exists. Nothing else on the site would be purged at other times. The status check would then have no stale-page case to catch after a settings change, though it would still catch stale pages from other causes.

### Test environment notes

- Cache Enabler names its settings file after the host without the port (`localhost.php`), but looks it up early in a request using the `Host` header with the port (`localhost:8888.php`). On wp-env's port 8888 it never cached until the file was also saved under the second name. Sites on ports 80 and 443 aren't affected.
- Every plugin was removed afterwards, along with what its uninstall left behind: W3 Total Cache's `wp-content/cache` and `wp-content/w3tc-config`, WP Fastest Cache's `wp-content/cache` folders, and Cache Enabler's `WP_CACHE` block in `wp-config.php` and its `wp-content/settings` folder.
