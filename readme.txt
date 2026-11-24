=== Not For Training ===
Contributors: cestep
Donate link: https://buymeacoffee.com/cesteptcbguy
Tags: ai, robots.txt, crawlers, copyright, privacy
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Opt your site out of AI training while staying findable in search engines and AI search.

== Description ==

Not For Training tells AI companies your content isn't available for model training, using every opt-out signal that's in use today, without hurting your search rankings or link previews.

**Training is blocked, search isn't.** AI training and dataset crawlers (GPTBot, ClaudeBot, CCBot, Google-Extended, Applebot-Extended, Bytespider, and others) get `Disallow: /` in robots.txt. AI search crawlers and user-requested fetches (OAI-SearchBot, ChatGPT-User, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User) keep the same access search engines have, so you stay findable in ChatGPT, Claude and Perplexity search. Googlebot, Bingbot and Applebot keep your normal rules.

What it does:

* **robots.txt**: adds an AI training group and an AI search group to WordPress's robots.txt, after core's and your SEO plugin's rules. Your existing rules and sitemap lines are never changed. Nothing is added when the site is set to discourage search engines.
* **noai, noimageai**: added to the page's single robots meta tag (merged with your SEO plugin's values) and sent as an `X-Robots-Tag` header.
* **TDM reservation (W3C TDMRep)**: a `tdm-reservation` meta tag and header, and `/.well-known/tdmrep.json` served by WordPress.
* **Image size cap**: uploads are scaled to 1600px on the longest side, the 2048px size isn't generated, and srcset never offers anything larger, so full-resolution files aren't published.
* **GPS stripping**: location data is removed from uploaded JPEGs before any size is made. Copyright, artist, IPTC and all other metadata stay.
* **Delete originals (off by default)**: optionally removes the uploaded original once WordPress has made its scaled copy.

These are opt-out signals that well-behaved crawlers honour. They can't stop a crawler that ignores them.

== Frequently Asked Questions ==

= Will this hurt my search rankings? =

No. Search engine crawlers aren't named in any rule, so they read your robots.txt exactly as before. Search engines ignore `noai` and `noimageai`.

= Does it work with Yoast SEO, Rank Math and similar plugins? =

Yes. The plugin runs after them in robots.txt and adds its values to the same robots meta tag instead of printing a second one.

= Does it work with page caching? =

The meta tags are part of the cached page. Response headers are only sent on pages WordPress renders, so pages a caching plugin serves directly from the web server, and image files, need the headers set in the server configuration.

= My site has a physical robots.txt file. =

A real `robots.txt` file in the site root is served by the web server before WordPress runs, so the plugin's rules won't appear. Remove or rename the file to let WordPress serve robots.txt.

== Changelog ==

= 0.1.0 =
* First release.
