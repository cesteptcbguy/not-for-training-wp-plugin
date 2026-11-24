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

Tell AI companies not to train on your work, and keep your site findable in Google and AI search.

== Description ==

Not For Training tells AI companies not to train on your work and makes your images harder to take, without hiding you from Google. It sends opt-out signals and adds speed bumps. It can't stop someone who ignores the rules or takes a screenshot.

It works as soon as you activate it. The defaults block AI training and keep AI search.

= Training is blocked, search isn't =

AI training and dataset crawlers get `Disallow: /` in your robots.txt. These include GPTBot, ClaudeBot, CCBot, Google-Extended, Applebot-Extended, Bytespider, meta-externalagent, Amazonbot and others.

AI search crawlers keep the same access search engines have. So do the fetchers that load a page when someone asks an AI about it. These are OAI-SearchBot, ChatGPT-User, Claude-SearchBot, Claude-User, PerplexityBot and Perplexity-User. You stay findable in ChatGPT, Claude and Perplexity.

Google, Bing and Pinterest are left alone. They keep your normal robots.txt rules.

= Settings =

Go to Settings > Not For Training. Every setting has a plain explanation on the page.

**AI bots**

* **Block AI training, allow AI search (recommended).** AI companies can't use your site to train their models. ChatGPT, Claude and Perplexity can still find you and link to you when people search.
* **Block all AI.** Also keeps you out of AI search tools. People asking ChatGPT, Claude or Perplexity won't be pointed to your site.
* **Off.** No AI rules are added to your robots.txt.

Google's AI Overviews use the same crawler as Google search, so they can't be blocked without leaving Google. This plugin leaves Google, Bing and Pinterest alone.

**Signals**

* **Add no-AI tags to every page.** A signal some AI tools honor. It doesn't block anything by itself. The plugin adds `noai` and `noimageai` to your page's robots meta tag and sends them as an `X-Robots-Tag` header on pages and feeds.
* **Add a rights-reserved notice for text and data mining.** A machine-readable notice that you reserve your rights, recognized under EU copyright law. The plugin adds a `tdm-reservation` meta tag and header, and serves `/.well-known/tdmrep.json` (W3C TDMRep).

**Images** (these apply to new uploads)

* **Largest image size.** The largest copy of new uploads. Smaller copies are less useful for AI training and copying. Images already on your site aren't changed. Choose 1200px, 1600px (recommended), 2048px (good if a print shop needs zoom), or Don't change (WordPress default, 2560px).
* **Remove location data from new photos.** Phones save where a photo was taken. This removes it and keeps your copyright info.
* **Only keep the smaller copy.** WordPress normally also keeps your full-size file, at a web address anyone can guess. Turn this on only if your full-size files are saved somewhere else. Full-size files for images uploaded while this is on can't be brought back. This is off by default.

**Saving images**

* **Make images harder to save.** Turns off dragging, right-click saving and the long-press save menu on phones. Anyone determined can still take a screenshot. You can still save your own images while you're logged in. This is on by default.
* **Extra images to cover.** For web people. Leave this blank unless some images on your site aren't covered. Use CSS selectors, separated by commas.

It covers images in posts and pages, galleries, featured images, lightboxes, WooCommerce product galleries and Elementor images. Clicking still works, so lightboxes, product zoom and linked images behave as before. Anyone who can upload files to your site, such as authors and admins, doesn't get it at all.

= Setup notice =

After you activate the plugin, a notice appears on the Plugins screen and the settings page. It says the plugin is on and offers two buttons: Review settings and Dismiss. Dismissing it hides it for you. Saving the settings hides it for everyone. It doesn't appear anywhere else in the dashboard.

= How location data is removed =

When "Remove location data from new photos" is on:

* **JPEG:** GPS is removed from the EXIF data and from the XMP data. Everything else stays, including copyright, artist, camera details, IPTC and color profiles.
* **HEIC and HEIF:** iPhone photos are often HEIC files. If your server can convert them, WordPress makes a JPEG and keeps the original .heic file at a public address. If it can't, the .heic file is the image. Either way, the plugin clears GPS in the .heic file. It changes only the GPS bytes, so the file stays the same size and still opens normally.
* **Generated sizes:** After WordPress makes its smaller copies, the plugin checks each one, plus the main file and the kept original, and clears any GPS left in them.

The image itself is never re-encoded. If a file looks damaged or unusual, the plugin leaves it alone.

= Is it working? =

The settings page checks your site. It tells you in plain words whether the AI rules, the no-AI tags and the rights-reserved notice are actually reaching visitors and crawlers.

It catches the common problems:

* A robots.txt file or an SEO plugin overriding the rules.
* A saved robots.txt file with old rules.
* A caching plugin serving old pages.
* Search engines blocked by a setting.
* A server that drops copyright info from resized images.

When something's wrong, it says what to do. It gives you the rules to copy or the file to download.

The main checks also show up in Tools > Site Health.

= What it doesn't do yet =

* It doesn't remove location data from PNG, WebP or AVIF files.
* It doesn't change images that were uploaded before you activated it. The size limit and location removal apply to new uploads only.
* It doesn't stop other sites from hotlinking your images.
* It doesn't block crawlers at the server. robots.txt and the no-AI signals are requests. Crawlers that ignore them can still read your pages.

= Privacy =

The plugin doesn't collect any data, set cookies or contact any outside service.

== Installation ==

1. Install the plugin from Plugins > Add New Plugin, or upload the `not-for-training` folder to `/wp-content/plugins/`.
2. Activate it. The defaults start working right away.
3. Optional: go to Settings > Not For Training to review the settings.

== Frequently Asked Questions ==

= Can people still save my images? =

Yes. Anyone can take a screenshot, or dig the image out of the page's code. "Make images harder to save" only removes the easy ways: dragging, right-click saving and the long-press menu on phones.

The right-click menu is off on covered images, including images that are links. So "Open in new tab" won't show when someone right-clicks those images. Clicking them still works.

= Will this hurt my search rankings? =

No. Search engines aren't named in any of the plugin's rules, so they read your robots.txt as before. Search engines ignore the `noai` and `noimageai` tags.

= Can I block Google's AI Overviews? =

No. AI Overviews use the same crawler as Google search, so they can't be blocked without leaving Google. The plugin leaves Google alone. Google-Extended is blocked, which tells Google not to use your site to train its Gemini models.

= I don't see the plugin's rules in my robots.txt. =

Something is replacing WordPress's robots.txt. Check these:

* A real `robots.txt` file in your site's root folder. The web server sends it before WordPress runs, so the plugin's rules never appear. Remove or rename the file to let WordPress serve robots.txt.
* Your SEO plugin's robots.txt editor. Some editors replace WordPress's robots.txt with your own text. Some save it as a real file (see the next question). Clear the custom robots.txt in your SEO plugin, or copy the plugin's rules into it.
* "Discourage search engines from indexing this site" under Settings > Reading. When it's on, the plugin doesn't add anything.

Visit `yoursite.com/robots.txt` to see what's being served.

= My SEO plugin saved robots.txt as a file. =

Some SEO plugins can save WordPress's robots.txt as a real file. Yoast SEO's "Create robots.txt file" button does this. The AI rules get copied into the file as they are that day.

After that, the rules won't update on their own. The web server sends the file directly, so the plugin can't change it. If you change the AI bot setting, or a plugin update adds new bots, the file keeps the old rules.

The status check on Settings > Not For Training spots this. It tells you when the saved rules are still current, and when they're out of date. When they're out of date, it gives you the new rules to paste into the file.

= Does it work with Yoast SEO, Rank Math and similar plugins? =

Yes. The plugin adds its rules to WordPress's robots.txt, next to any rules your SEO plugin adds. Some SEO plugins rearrange the file, so the AI rules may come first or last. Order doesn't matter to crawlers. Each crawler looks for the group that names it.

The plugin also adds its values to the same robots meta tag as your SEO plugin, instead of printing a second one.

= My host uses nginx. =

Some nginx setups answer robots.txt themselves and block the .well-known folder before WordPress ever sees the request. Then the AI rules can't be added to robots.txt, and the rights-reserved file can't be reached. The notice in your pages still works.

The status check on Settings > Not For Training tells you when this happens. A host or web person can fix it with two small changes to the nginx config. The exact lines are in the "common nginx configs" section of [docs/compat.md](https://github.com/cesteptcbguy/not-for-training-wp-plugin/blob/main/docs/compat.md).

= Does it work with page caching? =

Mostly. The meta tags are saved in the cached page, so they still work. Response headers are only sent when WordPress builds the page. If a caching plugin serves pages straight from the web server, set the `X-Robots-Tag` and `tdm-reservation` headers in your server configuration too. The same goes for image files.

When you change a setting that affects your pages, the plugin clears your caching plugin's cache for you.

= Does it change images already on my site? =

No. The size limit and location removal apply to new uploads only.

= What happens when I delete the plugin? =

Its settings are removed. Images it changed stay as they are.

== Screenshots ==

1. The settings page: AI bot rules, signals and image settings.
2. The "Is it working?" status section, with plain-word results and the rules to copy.

== Changelog ==

= 0.1.0 =
* First release.
* robots.txt rules that block AI training crawlers and keep AI search crawlers, with "Block all AI" and "Off" options.
* noai and noimageai robots meta and X-Robots-Tag header on pages and feeds.
* TDM reservation meta tag, header and /.well-known/tdmrep.json.
* Size limit for new uploads, with no larger copies listed in srcset.
* GPS removal for JPEG (EXIF and XMP), HEIC and HEIF originals, and generated sizes.
* Optional removal of full-size originals.
* Settings page and a one-time setup notice.
* "Is it working?" status check on the settings page, and Site Health tests.
* "Make images harder to save": no dragging, right-click saving or long-press save menu on images, for visitors.
