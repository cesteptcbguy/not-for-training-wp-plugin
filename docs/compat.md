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
