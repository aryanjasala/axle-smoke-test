=== Axle Smoke Test ===
Contributors: aryanjasala
Tags: smoke test, updates, rollback, monitoring, deploy
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checks your key pages after every update or deploy, finds what broke and which plugin broke it, and rolls it back.

== Description ==

An update or a deploy breaks checkout, wp-admin or a page template, and the first person to notice is a customer. WordPress itself only checks the home page for a fatal error, and only after automatic updates. The tools that do more need a SaaS account and a site the public can reach.

Axle Smoke Test checks your key pages from your own server, right after the change, and tells you what broke and which plugin or theme broke it. Nothing leaves your site.

Part of Axle by AryanX, a family of plugins for running WordPress sites safely.

**When it runs:**

* After plugin, theme, core and translation updates, manual or automatic.
* After a plugin is installed or activated, and after a theme switch.
* After a deploy: every 5 minutes it compares the versions and file dates of every plugin and theme, plus `.git/HEAD` and `composer.lock`. Code changed outside WordPress starts a run too.
* When you press Run now, from WP-CLI (`wp axle-smoke-test run`, which exits with 1 on red for deploy pipelines), the REST API or an ability.
* A batch of updates makes one run, 60 seconds after the last change.

**What it checks, from the server:**

* Each key URL loads with the status you expect: the home page, the latest post and page, a category, search, a page that should be a 404, the feed, the REST API, the login page, the dashboard and the Plugins screen. With WooCommerce also the shop, a product, cart, checkout and my account. Add your own URLs, up to 25.
* Fatal errors, through the same check WordPress uses for its own auto-update rollback, and core's "critical error" page.
* New PHP warnings and notices, with the plugin or theme that raised them.
* Blank pages, missing scripts and stylesheets, landmarks (header, nav, main, footer, h1, forms) that are gone, and visible text that changed past a threshold.
* wp-admin screens as a signed-in admin, with a login that works once, for one screen, for 60 seconds.

**What it checks in your browser:**

When you have wp-admin open, the same pages load in a hidden frame. It records JavaScript errors (with the plugin whose script threw them), files and images that failed to load, and where the landmarks sit on the page. A run started by cron waits for the next admin page view, and says so.

**Verdicts:**

* Red: an unexpected 4xx or 5xx, a fatal error, the critical error page, or a blank page.
* Amber: new PHP warnings, new JavaScript errors, missing files, a landmark gone or moved, or the text changed a lot.
* Green: the page looks like its baseline, the last green run. A redesign is not a failure: accept the run as the new baseline.

**Rollback:**

Before each plugin or theme update, the current files are zipped (up to 100 MB, the last 2 per item, for 14 days). A red run offers "Roll back Smoke Fixture to 1.1": it puts the old files back, clears OPcache and runs the check again. Auto rollback is there too, off by default. Database changes the new version made are not undone, and the run lists the version options that changed so you know. Core updates are never rolled back.

**Alerts:**

Email, a webhook (generic JSON or Slack), an admin notice, and a Site Health test. The same broken page sends at most one alert an hour.

**Works with the other Axle plugins:**

* Activity Log with Undo logs runs and rollbacks.
* Update Rehearsal is suggested for the next update of what broke.
* Axle Read-Only Mode blocks rollbacks while the site is frozen.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

= WP-CLI =

* `wp axle-smoke-test run [--urls=<keys>] [--reason=<text>] [--wait]` runs a check now and exits with 1 on red.
* `wp axle-smoke-test list`, `show <id>`, `urls list|add|remove`, `baseline accept <run-id>`, `rollback <run-id> [--item=<plugin>]`, `backups list|purge` and `purge`.

= For developers =

Every default, limit, list and message has a filter, and every step fires an action: `axle_smoke_test_run_finished`, `axle_smoke_test_rolled_back`, `axle_smoke_test_verdict` and many more. The browser runner has `wp.hooks` filters and actions too. There are abilities for agents (WordPress 6.9+) and a REST contract for an external worker, for real screenshots from your own machine or CI. The full list is in [the reference](https://github.com/aryanjasala/axle-smoke-test/blob/main/docs/REFERENCE.md).

= Why WordPress 6.6 =

6.6 added the rollback of failed automatic plugin updates, which uses the same fatal error check. From 6.6 the plugin works next to it instead of against it. The abilities need 6.9 and are only added there.

== Privacy ==

Runs remember which administrator started them. Page contents are never stored: only a hash, a text fingerprint and landmark counts. Runs are deleted after 30 days (or 200 runs). Alert emails go to the addresses you set. The webhook URL and the basic-auth password are stored encrypted. The plugin adds text to Settings > Privacy and takes part in Export and Erase Personal Data.

== External services ==

None by default. The plugin only loads pages of your own site. If you add a webhook URL in the settings, a red (or, if you ask, amber) run sends the site URL, the run's verdict, what changed and the broken pages to that URL. That is a service you choose and run; its terms and privacy policy apply.

== Frequently Asked Questions ==

= Does it slow my site down? =

No. Nothing runs on normal page views. A run makes one request per key URL (and a HEAD request per script and stylesheet on them) after a change, in the background.

= My host blocks loopback requests. =

Then the server checks cannot run. The run fails with core's own loopback test result from Site Health, so you know why. Sites behind a password prompt work: add the username and password in the settings.

= Does it take screenshots? =

Not on the server: a server cannot render pages without a headless browser, and most hosts have none. The browser check measures the layout instead. For real screenshots, run the reference Playwright worker from the GitHub repo on your own machine or in CI; the plugin stores and shows what it uploads.

= What does a rollback not undo? =

Database changes. If the new version changed its tables or settings, the old version may not understand them. The run lists options like `woocommerce_db_version` that changed during the update, so you can decide.

= Will the hidden browser check click or buy anything? =

No. It only loads pages and reads them. It never submits forms or completes a checkout.

= Does it work on multisite? =

Yes, per site. Network Admin > Settings > Smoke Test shows the latest verdict of every site.

== Screenshots ==

1. A red run after an update: the fatal error, the plugin that caused it, and the one-click rollback.
2. The latest run and every run before it.
3. Right after an update on the Plugins screen, the check runs and shows the verdict.
4. An amber run: a new JavaScript error, found by the check in your browser.
5. Settings: key URLs, what to check and how to be told.

== Changelog ==

= 1.0.0 =
* First release.
