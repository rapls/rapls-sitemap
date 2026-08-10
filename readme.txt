=== Rapls Sitemap – HTML Sitemap Page for Pages, Posts, Categories, Authors & Menus ===

Contributors: rapls
Donate link: https://buymeacoffee.com/rapls
Tags: html sitemap, sitemap, table of contents, site map page, navigation
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An HTML sitemap page for readers: pages, posts, categories, authors, archives and navigation menus, from one shortcode or block.

== Description ==

**Rapls Sitemap builds the "table of contents" page your visitors use to find things** — your pages in their real hierarchy, your posts under their categories, and as much or as little else as you choose. Drop in the `[rapls_sitemap]` shortcode or the Sitemap block and the page builds itself, and keeps building itself as the site grows.

This is a *human-facing* sitemap. It is not the XML sitemap search engines read — WordPress produces that itself at `wp-sitemap.xml`, and this plugin deliberately stays out of its way.

It is a maintained replacement for **PS Auto Sitemap** (closed in 2022) and **WP Sitemap Page**, written from scratch. Both plugins' documented interfaces are reproduced so an existing sitemap page keeps working without editing its content, and PS Auto Sitemap's stored settings can be read in with one button.

= Why site owners pick Rapls Sitemap =

* **One placement, the whole page.** Pages, posts, categories, authors and date archives can be listed one after another, each under its own heading, from a single shortcode — the shape most sitemap pages actually have.
* **Your navigation, as your team arranged it.** List a WordPress menu in the menu's own order with the menu's own labels. On a site with hundreds of pages, "the routes we decided on" is often a better table of contents than "everything we have published".
* **Nothing is hidden quietly.** Where a list stops short of the content, it says so in the output. Caps, exclusions and truncation are always visible to the reader.
* **28 designs, no images.** Every preset is pure CSS with no sprites and no icon font, so they inherit your theme's colours and survive a dark background.
* **Built for large sites.** One query per post type, a configurable entry cap on all three queries, and a render cache that clears itself when content changes.
* **No front-end credit link. Ever.** The plugin never prints a link to its author on your site.

= What it lists =

* **Pages** in their parent/child hierarchy, to any depth you choose.
* **Posts**, optionally grouped under their categories, with child categories nested.
* **Categories, tags and custom taxonomies**, with or without the entries under them.
* **Authors**, filtered by user ID and by role.
* **Date archives**, by year and month.
* **Navigation menus**, in the menu's order, with `#` placeholder items printed as headings rather than links that go nowhere.
* **Custom post types and custom taxonomies**, alongside or instead of the built-in ones.

= Choosing what appears =

* Depth limit, and a "list only what is under this page" scope that a shortcode can resolve to the current page.
* Exclude posts, categories or users by ID. Excluding a parent takes its children with it.
* Exclude whole post types or taxonomies.
* Leave out the sitemap's own page, password-protected entries, and entries an SEO plugin has marked noindex.
* Limit by publication date, for a sitemap of one school year or one financial year. A category listing narrows with it, so a category holding nothing from that year is not listed either.
* Entry caps: per list, per category, and a starting offset.

= Ordering =

* Entries by date, title, ID, menu order, last modified, comment count, at random, or by a custom field — which is how you get a true kana order for Japanese titles.
* Category headings by name, entry count, slug, ID, or the order set by hand with a term-ordering plugin. Entry-count ordering uses the count WordPress keeps, which can differ from the number shown beside a category once exclusions have been applied.

= Design =

* 27 CSS presets plus "no styling at all" for themes that would rather do it themselves.
* Font size, line height, indent, link colour, underline behaviour and column count, layered on top of whichever preset is chosen.
* Bullets as discs, circles, squares, emoji, or an icon class such as Font Awesome — set separately for top-level and nested items.
* Section and category labels can be emitted as real `h2`–`h6` headings, which is what screen-reader users navigate by.
* An Additional CSS box, gated on the `unfiltered_html` capability rather than on access to the settings screen.

= Migrating from PS Auto Sitemap or WP Sitemap Page =

You can switch without editing the content of your sitemap page. None of either plugin's code is used here — only their documented interfaces.

Both compatibility options are **off by default**, because answering to another plugin's markup unasked is a surprise:

* **From WP Sitemap Page** — recognises `[wp_sitemap_page]` and its `only` values, including custom post types. The shortcode is not claimed while WP Sitemap Page itself is active, so the two cannot fight over it.
* **From PS Auto Sitemap** — recognises the `<!-- SITEMAP CONTENT REPLACE POINT -->` comment left in page content. That plugin's own "which page holds the sitemap" setting is not needed: the page is kept out of its own listing automatically.

**PS Auto Sitemap's settings can be read in with one button.** Its options survive its deletion, so a site that ran it years ago still holds the answers its owner already gave: which lists to show and in what order, the depth, the excluded categories and posts, whether caching was on, and the nearest design. The old settings are read, never written, so the import can be repeated.

= noindex integrations =

Entries marked noindex can be left out. These are read with no configuration: **Yoast SEO, Rank Math, SEO SIMPLE PACK, SEOPress, The SEO Framework, All in One SEO**, and the **Cocoon** theme (including its fallback to the Simplicity key it inherited).

Every one of those was read from the plugin itself rather than guessed. Anything else can be added with the `rapls_sitemap/is_noindex` filter — this plugin does not read meta keys on a hunch, because a wrong guess hides a page nobody asked to hide.

= Multilingual =

WPML and Polylang work with no configuration. Both narrow the post and term queries to the current language, and this plugin does not switch those filters off. The render cache keys on the locale, so one language is never served another's sitemap.

Learn more: [Plugin details](https://raplsworks.com/plugins/rapls-sitemap/) | [Source code (GitHub)](https://github.com/rapls/rapls-sitemap)

== Installation ==

1. Upload the `rapls-sitemap` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
2. Activate it from the Plugins menu.
3. Go to **Settings → Rapls Sitemap** and choose what to list.
4. Put `[rapls_sitemap]` on the page that should hold the sitemap, or add the **Sitemap** block.

= Migrating from another sitemap plugin =

1. Activate this plugin and deactivate the old one.
2. Open **Settings → Rapls Sitemap → Migration** and switch on the option matching the plugin you came from.
3. If you came from PS Auto Sitemap, use **Import from PS Auto Sitemap** at the foot of the screen to read its stored settings in.
4. Check the page, then adjust anything the import could only approximate.

Two things are read across rather than reproduced. The design is matched to the nearest preset, not recreated — these are original stylesheets. And PS Auto Sitemap's "divide" mode, which listed categories with a "show the posts in this category" link beside each one, becomes the category-only listing: there is no equivalent per-category drill-down here.

== Frequently Asked Questions ==

= Is this the XML sitemap for search engines? =

No. This builds the HTML page your visitors read. WordPress produces the XML sitemap itself at `wp-sitemap.xml`, and this plugin does not touch it.

= Can one placement show pages, posts, categories and authors together? =

Yes, and that is what most sitemap pages want. Tick the sections you need and they appear in order, each under its own heading. In a shortcode:

`[rapls_sitemap sections="page,post,category,author,archive"]`

= Can I list a navigation menu instead? =

Yes. Choose **A navigation menu** as the source, or name one in a shortcode:

`[rapls_sitemap menu="global-nav"]`

The menu is listed in its own order with its own labels — it is not re-sorted, because that order is a decision somebody made. Menu items linking to `#`, the placeholders that hold open a dropdown, are printed as plain headings rather than as links that go nowhere.

Several menus can appear in one sitemap with `sections="menu:global-nav,menu:footer-nav"`.

= Can I list only the pages under one page? =

Yes. Give **Limit to one branch** a page ID, or use `child_of="current"` in a shortcode, which resolves to whichever page it is placed on. A section landing page can then list its own children without naming an ID that differs between staging and production.

= Can I make a sitemap for one year? =

Yes. Set a publication window with `date_after` and `date_before`. Both ends are inclusive and either can stand alone, and the format is `YYYY`, `YYYY-MM` or `YYYY-MM-DD` — anything else, including a date that does not exist, is read as no limit rather than as a date nobody meant. The archive and category listings narrow with it, so a year or a category holding nothing inside the window is not listed at all.

= Will it cope with a large site? =

Yes, within a cap you set. A sitemap asks for everything at once, so the entry cap exists to stop that query exhausting memory; it applies to the post, term and user queries alike and defaults to 2000. **A list that stops short always says so in the output** — a silently short sitemap would be worse than a slow one.

= Are the settings per placement, or site-wide? =

Most of them are both. The settings screen sets the site default, and a shortcode attribute or a block setting overrides it for one placement. Caching and stylesheet loading are site-wide only.

= What is the number beside a category? =

The number of entries actually listed under it, not the number the category holds. Exclusions, the noindex filter and the entry caps all change what is shown, and a category labelled "12" with eight entries under it is worse than no number at all. In category-only mode there is nothing listed, so the count is the category's own — including its children where they are nested.

= Can I sort Japanese titles into kana order? =

Only with a custom field holding the reading. Sorting by title uses the database collation, which is right for kana and Latin but not for kanji — nothing in WordPress records that 大阪 reads おおさか. Store the reading in a custom field, choose **Custom field** as the ordering, and name the field.

= Can I use Font Awesome icons as bullets? =

Yes. Choose **Icon class** for the bullet and enter a class such as `fa-solid fa-angle-right`. Dashicons and other icon fonts work the same way. The plugin does not bundle an icon font, so your theme or another plugin has to be loading one; where nothing is loaded the icon is simply absent and the sitemap is otherwise unaffected.

= Who can edit the Additional CSS box? =

Only users with `unfiltered_html`: administrators on a single site, network administrators on multisite. Not everyone who can open the settings screen, because on multisite that includes site administrators, and this field is printed verbatim. Users without the capability do not see the field, and saving the screen leaves the stored CSS untouched.

= Does it print a credit link on my site? =

No. Nothing is ever output on the front end that links to the plugin or its author.

= Does it work with a screen reader? =

The sitemap is a labelled `nav` landmark, and section and category labels can be output as real `h2`–`h6` headings rather than styled text. Screen-reader users move through a page by its headings, which matters more on a page whose purpose is structure than almost anywhere else. The heading level is a choice rather than a default, because the right level depends on the page the sitemap sits in.

== Screenshots ==

1. Choosing what to list. Several sections can be listed one after another from a single placement.
2. 28 design presets, and the type, colour and bullet settings that layer on top of them.
3. The output: pages in their hierarchy, posts under their categories.
4. The block sidebar. Every placement can list something different.

== Changelog ==

= 0.1.0 =
* Initial release.
* Japanese translation included (admin screen and block editor).

== Upgrade Notice ==

= 0.1.0 =
First release.
