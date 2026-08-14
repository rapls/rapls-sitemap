=== Rapls Sitemap ===

Contributors: rapls
Donate link: https://buymeacoffee.com/rapls
Tags: html sitemap, sitemap, table of contents, site map page, navigation
Requires at least: 6.3
Tested up to: 7.0
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An HTML sitemap page for readers: pages, posts, categories, authors, archives and navigation menus, from one shortcode or block.

== Description ==

**Rapls Sitemap builds the "table of contents" page your visitors use to find things** — your pages in their real hierarchy, your posts under their categories, and as much or as little else as you choose. Drop in the `[rapls_sitemap]` shortcode or the Sitemap block, and the page keeps building itself as the site grows.

This is a *human-facing* sitemap, not the XML sitemap search engines read. WordPress produces that itself at `wp-sitemap.xml`, and this plugin stays out of its way.

It is a maintained replacement for **PS Auto Sitemap** (closed in 2022) and **WP Sitemap Page**, written from scratch. Both plugins' documented interfaces are reproduced, so an existing sitemap page keeps working without editing its content.

= Why site owners pick Rapls Sitemap =

* **One placement, the whole page.** Pages, posts, categories, authors and date archives, one after another under their own headings, from a single shortcode.
* **Simple to start, deep when you need it.** The settings screen opens on a **Basic** tab holding the eight decisions a sitemap cannot be built without. The rest is one tab away, in panels that open themselves when they hold anything but a default.
* **A truncated list says so.** Where the entry cap cuts a list short, the output says it was cut.
* **Built for large sites.** One query per post type, a cap on all three queries, and a render cache that clears itself on content changes.
* **No front-end credit link, and no dashboard advertising.**

= What it lists =

* **Pages** in their parent/child hierarchy, to any depth you choose.
* **Posts**, optionally grouped under their categories, with child categories nested.
* **Categories, tags and taxonomies**, with or without the entries under them.
* **Authors**, filtered by user ID and role, and **date archives** by year and month.
* **Navigation menus**, with `#` placeholder items printed as headings rather than links that go nowhere.
* **Custom post types and taxonomies**, as long as they are viewable on the front end. One that is not publicly queryable is never listed, whatever asks for it, because its pages would 404.

= Choosing what appears =

* Depth limit, and a "only what is under this page" scope a shortcode can resolve to the current page or the one above.
* Exclude posts, categories or users by ID, or whole post types and taxonomies. Excluding a parent takes its children with it.
* Leave out the sitemap's own page, password-protected entries, and entries individually marked noindex.
* Limit by publication date, for a sitemap of one school year. A category holding nothing from that year drops out with it.
* Entry caps: per list, per category, and a starting offset.

= Ordering and design =

* Entries by date, title, ID, menu order, last modified, comment count, at random, or by a custom field — which is how you get a true kana order for Japanese titles. Category headings by name, count, slug, ID, or a hand-set order.
* 27 CSS presets plus "no styling at all", for themes that would rather do it themselves.
* Font size, line height, indent, link colour, underline and column count on top of the preset.
* Bullets as discs, circles, squares, emoji, or an icon class such as Font Awesome, separately for top-level and nested items.
* Section and category labels as real `h2`–`h6` headings, which screen-reader users navigate by.
* An Additional CSS box, gated on `unfiltered_html` rather than on access to the settings screen.

= Migrating from PS Auto Sitemap or WP Sitemap Page =

You can switch without editing the content of your sitemap page. None of either plugin's code is used here, only their documented interfaces. Both options are **off by default**, because answering to another plugin's markup unasked is a surprise.

* **From WP Sitemap Page** — recognises `[wp_sitemap_page]` and its `only` values. The shortcode is not claimed while WP Sitemap Page itself is active, so the two cannot fight over it.
* **From PS Auto Sitemap** — recognises the `<!-- SITEMAP CONTENT REPLACE POINT -->` comment left in page content.

**PS Auto Sitemap's settings can be read in with one button.** Its options survive its deletion, so a site that ran it years ago still holds the answers its owner gave. They are read, never written.

= noindex integrations =

Entries **individually** marked noindex can be left out, reading these with no setup: **Yoast SEO, Rank Math, SEO SIMPLE PACK, SEOPress, The SEO Framework, All in One SEO** and the **Cocoon** theme. Categories, tags and authors are read too, where the term or author is what is listed.

A default applying to a whole post type, taxonomy or archive is **not** read. Those listings appear only because you chose them, and an SEO plugin's default — Yoast noindexes date archives out of the box — would otherwise empty a list you asked for.

A password-protected entry never contributes an excerpt, whether or not it stays in the list. Its text is not for everybody, and this output is cached and shared.

= Extending it =

= Multilingual =

WPML and Polylang work with no configuration. Both narrow the post and term queries to the current language, and this plugin does not switch those filters off. The render cache keys on the locale, so one language is never served another's.

Ten filters cover what the settings cannot reach, including `rapls_sitemap/query_args`, which hands you the query for one post type before it runs. Worked examples are on the plugin page.

Learn more: [Plugin details and developer reference](https://raplsworks.com/plugins/rapls-sitemap/) | [Source code (GitHub)](https://github.com/rapls/rapls-sitemap)

== Installation ==

1. Install it from the Plugins screen, or upload the `rapls-sitemap` folder to `/wp-content/plugins/`.
2. Activate it from the Plugins menu.
3. Go to **Settings → Rapls Sitemap** and choose what to list.
4. Put `[rapls_sitemap]` on the page that should hold the sitemap, or add the **Sitemap** block.

= Migrating from another sitemap plugin =

1. Activate this plugin and deactivate the old one.
2. Open **Settings → Rapls Sitemap → Advanced → Coming from another plugin** and switch on the option matching the plugin you came from.
3. From PS Auto Sitemap, use **Import from PS Auto Sitemap** at the foot of the screen.
4. Check the page, then adjust what the import could only approximate: the design is matched to the nearest preset rather than recreated, and "divide" mode becomes the category-only listing.

== Frequently Asked Questions ==

= Is this the XML sitemap for search engines? =

No. This builds the HTML page your visitors read. WordPress produces the XML sitemap itself at `wp-sitemap.xml`, untouched by this plugin.

= Can one placement show pages, posts, categories and authors together? =

Yes, and that is what most sitemap pages want. Tick the sections you need and they appear in order, each under its own heading: `[rapls_sitemap sections="page,post,category,author,archive"]`

= Can I list a navigation menu instead? =

Yes. Choose **A navigation menu** as the source, or name one in a shortcode with `menu="global-nav"`. It is listed in its own order with its own labels, because that order is a decision somebody made. Several menus fit in one sitemap with `sections="menu:global-nav,menu:footer-nav"`.

= Can I list only the pages under one page? =

Yes. Give **Limit to one branch** a page ID, or use one of two words in a shortcode. `child_of="current"` resolves to whichever page it sits on, so a section landing page lists its own children without naming an ID that differs between staging and production. `child_of="parent"` resolves to the page above.

= Can I make a sitemap for one year? =

Yes. Set a publication window with `date_after` and `date_before`. Both ends are inclusive and either can stand alone. The format is `YYYY`, `YYYY-MM` or `YYYY-MM-DD`; anything else, including a date that does not exist, is read as no limit rather than a date nobody meant.

= Will it cope with a large site? =

Yes, within a cap you set. A sitemap asks for everything at once, so the cap exists to stop that query exhausting memory; it applies to the post, term and user queries alike and defaults to 2000. **A list that stops short always says so in the output** — a silently short sitemap would be worse than a slow one.

= Who can edit the Additional CSS box? =

Only users with `unfiltered_html`: administrators on a single site, network administrators on multisite. Not everyone who can open the settings screen, because on multisite that includes site administrators and this field prints verbatim.

= Does it print a credit link on my site? =

No. Nothing linking to the plugin or its author is ever output on the front end.

= Does it work with a screen reader? =

The sitemap is a labelled `nav` landmark, and section and category labels can be output as real `h2`–`h6` headings rather than styled text. Screen-reader users move through a page by its headings, which matters more here than almost anywhere.

== Screenshots ==

1. The Basic tab: what to list, how deep, what to leave out, and which of the 28 designs to use.
2. The Advanced tab. Everything optional, in panels that open themselves when they hold something other than a default.
3. The output: pages in their hierarchy, posts under their categories.
4. The block sidebar. Every placement can list something different.

== Changelog ==

= 0.1.0 =
* Initial release.
* Japanese translation included.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
