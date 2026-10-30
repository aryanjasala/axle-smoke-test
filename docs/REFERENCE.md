# Reference

## How a run works

A run checks every key URL in up to three tiers, and says which tier found what.

| Tier | Where it runs | What it finds |
| --- | --- | --- |
| Server | A loopback request from the site to itself, from cron, WP-CLI, the REST API or the screen | Status and redirect, time, size, fatal errors (core's scrape check, as the auto-update rollback uses), PHP warnings and notices, core's critical error page, blank pages, missing same-origin scripts and stylesheets, landmark counts, a hash of the normalised HTML and a fingerprint of the visible text |
| Browser | A hidden same-origin frame in an admin's browser, on the Smoke Test screen, the update screens, or any admin page while a run waits | JavaScript errors, unhandled promise rejections, `console.error`, CSP violations, files and images that failed, and the position, size and visibility of the landmarks |
| Worker | Your own machine or CI, through the REST API (see below) | Whatever it uploads: the same fields as the browser tier, plus a PNG screenshot |

A run's life: **queued** (60 seconds after the last change, so a batch of updates makes one run), **running** (the server tier), **waiting_browser** (the next admin page view picks it up, with a notice), **done**, or **failed** (the site could not load itself; the run shows core's own loopback test result). A run that waits a day for a browser is closed with the server results.

The run names the change that started it: "WooCommerce 9.8.1 to 9.9.0", "Theme Twenty Twenty-Four to Twenty Twenty-Five", or the plugins whose version changed in a deploy.

### What starts a run

| Trigger | When |
| --- | --- |
| `update` | `upgrader_process_complete` for plugins, themes, core and translations, manual or automatic, and `automatic_updates_complete` |
| `install` | A plugin installed or activated (`activated_plugin`) |
| `theme` | `switch_theme` |
| `deploy` | The five-minute check: the version and main-file date of every plugin and theme, `.git/HEAD` and `composer.lock` changed outside WordPress |
| `manual`, `cli`, `rest`, `schedule`, `rollback` | Run now, `wp axle-smoke-test run`, the REST API or an ability, the optional daily run, and the run after a rollback |

With `DISABLE_WP_CRON`, a queued run starts at the end of the request that caused it, after the response was sent (`fastcgi_finish_request` where PHP-FPM has it). While core's `.maintenance` file exists, a queued run waits.

### Key URLs

Picked from the site (each type can be switched off): the home page, the latest post, the latest page that is not the front page, the busiest category, a search, a page that does not exist (expects 404), the feed, the REST API index, the login page, the dashboard and the Plugins screen. With WooCommerce also the shop, the latest product, the cart, checkout (expects 200 or 302 with an empty cart) and my account. You add your own with expected statuses, up to 25 URLs in total.

wp-admin URLs open as a signed-in user: the admin who pressed Run now, or for cron and WP-CLI runs the cron user from the settings, when "wp-admin checks from cron" is on (off by default). Without a user they are checked as "sends a signed-out visitor to the login page".

### Verdicts

| Verdict | When |
| --- | --- |
| Red | No response, an unexpected 4xx or 5xx, a fatal error, core's critical error page, a blank page (under 40 bytes of visible text by default), a broken feed or REST response |
| Amber | New PHP warnings or notices, new JavaScript errors, new missing files or images, a landmark gone or hidden, a landmark moved or resized past 40 px, more than 60% of the visible text changed, or an unexpected status under 400 |
| Green | None of the above, compared with the baseline |

"New" means not in the baseline: the last green result for that URL and tier. A green result becomes the baseline by itself. After an intended change, such as a redesign, accept a run (or one URL of it) as the new baseline. Numbers in error messages are ignored when comparing, so a warning on a different line is not new.

Errors are blamed on a plugin, must-use plugin or theme by file path (PHP) or script URL (JavaScript, missing files).

### Rollback

Before each plugin or theme update (`upgrader_pre_install`), the current folder is zipped into `wp-content/axle-smoke-test/backups/`. Folders over 100 MB are skipped, the last 2 backups per item are kept, for 14 days. The folder gets an `index.php`, an `.htaccess` deny rule and a `web.config`. On nginx, add:

```nginx
location ^~ /wp-content/axle-smoke-test/ { deny all; }
```

A red or amber run with a backup of the version before its update offers "Roll back <item> to <version>". It unzips the backup to a temp folder in `wp-content/upgrade`, swaps the folders (the current one comes back if anything fails), clears OPcache for those files in the web server's PHP too (through a one-time loopback, since WP-CLI and cron have their own OPcache), and runs the check again as a `rollback` run.

A rollback puts files back, not the database. The run lists the options ending in `_version` or `_db_version` that changed during the update. Core is never rolled back. While Axle Read-Only Mode has the site frozen, rollbacks are refused.

**Auto rollback** (off by default) rolls back after a red run when the errors are blamed on the updated item, or only one item was updated. It never rolls back the run after a rollback.

### Alerts

When a run is red (or amber, if you ask): an email (the addresses in the settings, or the site admin email), a webhook (generic JSON or a Slack message) and an admin notice that stays until it is dismissed or a green run follows. The same URL and verdict alert at most once an hour. Site Health gets a "Last smoke test" test.

## Hooks

### Filters

| Filter | Arguments | Default |
| --- | --- | --- |
| `axle_smoke_test_capability` | `$capability` | `update_plugins`. Needed for the screen, the REST API, the abilities and the panel |
| `axle_smoke_test_auto_urls` | `$urls` | The URLs picked from the site |
| `axle_smoke_test_urls` | `$urls` | The final list: picked plus your own |
| `axle_smoke_test_max_urls` | `$max` | 25 (1 to 200) |
| `axle_smoke_test_expected_status` | `$statuses, $url` | From the URL entry |
| `axle_smoke_test_request_args` | `$args, $url` | `wp_remote_get()` arguments of each loopback |
| `axle_smoke_test_cache_bust` | `$bust, $url` | `true`: a cache-busting query argument and `Cache-Control: no-cache` |
| `axle_smoke_test_normalise_html` | `$normalised, $html` | HTML without nonces, timestamps, `ver=` and generated ids, before hashing |
| `axle_smoke_test_ignored_php_errors` | `$patterns, $url` | None. Plain text, or `/regex/` |
| `axle_smoke_test_ignored_js_errors` | `$patterns, $url` | None. Plain text, or `/regex/` |
| `axle_smoke_test_layout_selectors` | `$selectors` | `header`, `nav`, `main`, `footer`, `h1`, `form`, `.wp-site-blocks > *`, `#page`, `#content` |
| `axle_smoke_test_layout_threshold` | `$pixels` | 40, from the settings |
| `axle_smoke_test_text_change_threshold` | `$percent` | 60, from the settings |
| `axle_smoke_test_blank_page_bytes` | `$bytes` | 40, from the settings |
| `axle_smoke_test_verdict` | `$verdict, $reasons, $subject, $level, $baseline` | Per URL (`$level` = `url`, `$subject` = the result) and per run (`run`) |
| `axle_smoke_test_attribute_error` | `$source, $location, $error` | `plugin:<folder>`, `mu-plugin:<file>`, `theme:<stylesheet>`, `core` or `unknown` |
| `axle_smoke_test_alert_recipients` | `$emails, $run` | The settings, or the admin email |
| `axle_smoke_test_alert_message` | `$message, $run` | `subject`, `body`, `lines`, `url` |
| `axle_smoke_test_webhook_payload` | `$payload, $run, $format` | Generic JSON or a Slack message |
| `axle_smoke_test_alert_rate_limit` | `$seconds` | 3600 per URL and verdict. 0 alerts every time |
| `axle_smoke_test_auto_rollback` | `$rollback, $candidate, $run` | The setting (off) and the blame rule |
| `axle_smoke_test_backup_max_bytes` | `$bytes, $type, $item` | 100 MB |
| `axle_smoke_test_backups_to_keep` | `$count` | 2 per item |
| `axle_smoke_test_retention_days` | `$days` | 30, from the settings |
| `axle_smoke_test_retention_runs` | `$runs` | 200, from the settings |
| `axle_smoke_test_debounce_seconds` | `$seconds` | 60 |
| `axle_smoke_test_fingerprint_parts` | `$parts` | Plugin and theme versions with file dates, `.git/HEAD`, `composer.lock`, core version |
| `axle_smoke_test_admin_loopback_enabled` | `$enabled` | `true`. `false` checks wp-admin URLs as signed out only |
| `axle_smoke_test_settle_ms` | `$ms` | 1500: the wait after `load` before the browser tier measures |
| `axle_smoke_test_triggers` | `$triggers` | `update`, `install`, `theme`, `deploy`, from the settings |

Numbers are clamped and a filter that returns garbage falls back to the default. For example, to also check the shop's order tracking page and ignore a noisy warning:

```php
add_filter( 'axle_smoke_test_urls', function ( $urls ) {
	$urls[] = array( 'key' => 'track', 'label' => 'Track order', 'url' => '/track/', 'expected' => array( 200 ) );
	return $urls;
} );
add_filter( 'axle_smoke_test_ignored_php_errors', function ( $patterns ) {
	$patterns[] = '/^Deprecated: .*legacy-widget/';
	return $patterns;
} );
```

### Actions

| Action | Arguments | When |
| --- | --- | --- |
| `axle_smoke_test_run_queued` | `$run` | A run was queued, or a change joined a queued run |
| `axle_smoke_test_run_started` | `$run` | The server tier starts |
| `axle_smoke_test_url_checked` | `$result, $run` | One URL in one tier has a verdict |
| `axle_smoke_test_run_finished` | `$run, $results` | After the server tier, and again when the browser or a worker adds results |
| `axle_smoke_test_run_failed` | `$run, $error` | The site could not load itself |
| `axle_smoke_test_baseline_accepted` | `$run, $results, $url_key` | Results became the baseline |
| `axle_smoke_test_alert_sent` | `$channel, $run, $recipients, $ok` | `notice`, `email` or `webhook` |
| `axle_smoke_test_backup_created` | `$entry` | A plugin or theme was zipped before an update |
| `axle_smoke_test_before_rollback` | `$candidate, $run` | About to roll back |
| `axle_smoke_test_rolled_back` | `$candidate, $run` | Rolled back |
| `axle_smoke_test_rollback_failed` | `$candidate, $error, $run` | A rollback failed; nothing changed |
| `axle_smoke_test_deploy_detected` | `$changed, $items` | The code fingerprint changed outside WordPress |
| `axle_smoke_test_purged` | `$counts` | Old runs and backups were deleted |
| `axle_smoke_test_settings_saved` | `$settings, $old` | Settings were saved |

```php
add_action( 'axle_smoke_test_run_finished', function ( $run ) {
	if ( 'red' === $run['verdict'] && 'done' === $run['status'] ) {
		my_pager( 'Smoke test red: ' . $run['summary']['change'] );
	}
} );
```

### JavaScript (`wp.hooks`, namespace `axleSmokeTest`)

| Hook | Kind | Arguments |
| --- | --- | --- |
| `axleSmokeTest.layoutSelectors` | filter | `selectors, url` |
| `axleSmokeTest.ignoredJsErrors` | filter | `patterns, url` |
| `axleSmokeTest.settleMs` | filter | `ms, url` |
| `axleSmokeTest.collectResult` | filter | `data, url, run`: what is sent for one URL |
| `axleSmokeTest.urlChecked` | action | `stored, url, run` |
| `axleSmokeTest.runFinished` | action | `run` |

They are read each time they are used, so a script loaded later can add them:

```js
wp.hooks.addFilter( 'axleSmokeTest.ignoredJsErrors', 'my-site', ( patterns ) => [ ...patterns, 'ResizeObserver loop' ] );
```

## Abilities (WordPress 6.9+)

| Ability | Annotations | What it does |
| --- | --- | --- |
| `axle-smoke-test/run-smoke-test` | write | Runs the server tier now and returns the run. Says in `note` that the browser tier is not part of it |
| `axle-smoke-test/list-runs` | readonly | Runs, newest first. Input: `verdict`, `limit` |
| `axle-smoke-test/get-run` | readonly | One run with every result and reason |
| `axle-smoke-test/list-urls` | readonly | The key URLs |
| `axle-smoke-test/accept-baseline` | idempotent | A run, or one URL of it, becomes the baseline |
| `axle-smoke-test/rollback-update` | destructive | Rolls back an item of a run. Refused without a backup, for core, and during a Read-Only Mode freeze |

All need the `axle_smoke_test_capability` capability.

## REST API and the external worker

Routes under `/wp-json/axle-smoke-test/v1`, all for the same capability, with the login cookie and a REST nonce or an application password:

| Route | Method | What |
| --- | --- | --- |
| `/runs` | GET | Runs. `verdict`, `per_page`, `page` |
| `/runs` | POST | Runs the server tier now. `urls`, `reason`, `browser` (true returns a browser token and the URLs) |
| `/runs/next` | POST | Starts the newest queued run now, or picks up the newest one waiting for a browser |
| `/runs/<id>` | GET | One run with its results and its browser URLs |
| `/runs/<id>/start` | POST | Starts a queued run now |
| `/runs/<id>/browser` | POST | A new browser token and the URLs |
| `/runs/<id>/results` | POST | One URL's result: `tier` (`browser` or `worker`), `url_key`, `data` |
| `/runs/<id>/complete` | POST | Marks a tier finished: `tier` |
| `/runs/<id>/baseline` | POST | Accepts the run, or `url_key` |
| `/runs/<id>/rollback` | POST | Rolls back `item` |
| `/runs/<id>/report` | GET | The run and its results as a JSON download |
| `/results/<id>/thumbnail` | GET | A worker's screenshot |
| `/urls` | GET | The key URLs |

A result's `data`: `js_errors` (each `message`, `url`, `line`, `kind`), `failed` (each `url`, `status`, `kind`), `layout` (selector => `x`, `y`, `w`, `h`, `visible`), `csp`, `load_ms`, `http_status`, `error`, and for a worker `thumbnail` (a base64 PNG under 2 MB). Lists are capped at 50 entries and texts at 500 characters.

`docs/worker/smoke-worker.js` in the repository (not in the plugin zip) is a reference worker with Playwright: it loads the run's URLs in Chromium, takes a screenshot of each, uploads the results and exits with 1 on red.

```sh
SITE=https://example.com WP_USER=admin WP_APP_PASSWORD="abcd efgh ijkl mnop qrst uvwx" node docs/worker/smoke-worker.js
```

## WP-CLI

| Command | What |
| --- | --- |
| `wp axle-smoke-test run [--urls=<keys>] [--reason=<text>] [--wait] [--timeout=<s>] [--format=<f>]` | Runs a check now (a queued run from `wp plugin update` starts now, so it can name and roll back what changed). Exits with 1 on red. `--wait` waits for the browser tier |
| `wp axle-smoke-test list [--verdict=<v>] [--limit=<n>] [--format=<f>]` | Runs |
| `wp axle-smoke-test show <id> [--format=<f>]` | One run |
| `wp axle-smoke-test urls list\|add <url> [--expect=<statuses>] [--label=<l>] [--no-browser]\|remove <key>` | Key URLs |
| `wp axle-smoke-test baseline accept <run-id> [--url=<key>]` | Accepts a baseline |
| `wp axle-smoke-test rollback <run-id> [--item=<plugin\|theme>] [--yes]` | Rolls back and runs again |
| `wp axle-smoke-test backups list\|purge [--all]` | Backups |
| `wp axle-smoke-test purge [--older-than=<days>]` | Deletes old runs and backups |

A deploy pipeline:

```sh
wp plugin update --all && wp axle-smoke-test run --reason="$GIT_COMMIT" || exit 1
```

## Security

- **The wp-admin login.** For a wp-admin URL, the run creates a WordPress session for the user that expires in 60 seconds (core stores only a hash of its token) and sends its login cookies with that one loopback. The session is marked with the one path it may open. Any other path, any method but GET or HEAD, `admin-post.php`, `admin-ajax.php` and the REST API are refused, and the session is destroyed after its first request. From cron and WP-CLI this only happens when you switch on "wp-admin checks from cron".
- **Run tokens.** Loopbacks carry a random token in an `X-Axle-Smoke-Test` header, stored hashed for two minutes. It only switches on the PHP error collector for that request. Browser frames carry `?axle_smoke=<token>` (hashed, one hour), which only adds the collector script and hides the toolbar. Neither ever signs anyone in.
- **Secrets.** The webhook URL and the basic-auth password are encrypted with a key derived from the site's salts, and never shown back in full.
- **Files.** Backups and thumbnails sit behind deny rules and are only served to the capability, through PHP.
- **No calls out**, except to the webhook you set.

## Data

| What | Contents |
| --- | --- |
| `{prefix}axle_smoke_runs` table | One row per run: times, trigger, context (what changed, from and to versions, the URLs checked), status, verdict, tier flags, summary (counts, sources, version options changed, rollbacks) and the user who started it |
| `{prefix}axle_smoke_results` table | One row per URL and tier: status, time, size, PHP and JavaScript errors, missing files, HTML hash, text fingerprint, landmarks, layout, thumbnail file, verdict, reasons and whether it is the baseline. Never the page HTML |
| `axle_smoke_test_settings` option | Settings, secrets encrypted |
| `axle_smoke_test_urls` option | Your own key URLs |
| `axle_smoke_test_fingerprint` option | The last code fingerprint |
| `axle_smoke_test_backups` option | The backup index |
| `axle_smoke_test_alert_log`, `axle_smoke_test_notice` options | Rate limiting, and the run the notice shows |
| `axle_smoke_test_db_version` option | Table version |
| `wp-content/axle-smoke-test/backups/` | The zips |
| `uploads/axle-smoke-test/` | Worker screenshots |
| `mu-plugins/axle-smoke-test-early.php` | The early collector, only if you install it |

Cron events: `axle_smoke_test_run` (queued runs), `axle_smoke_test_deploy_check` (every 5 minutes), `axle_smoke_test_cleanup` (daily: runs older than 30 days or beyond 200, keeping baselines; backups older than 14 days; runs that waited a day for a browser), `axle_smoke_test_daily` (the optional daily run). Deactivating stops them and removes the early collector. Uninstalling removes both tables, every option and transient, the cron events, the backups and thumbnails folders and the early collector, on every site of a network.

## Limits

- No pixel screenshots on the server. The browser tier measures the layout instead; a worker can upload real screenshots.
- The browser tier only runs while an admin has wp-admin open. Runs from cron wait for that, up to a day.
- Without the early collector, PHP warnings raised while plugins that load before this one are loading are not seen, only fatal errors. `debug.log` is only read for a request whose collector could not run, since every other request writes to it too.
- Rollback needs a backup made by this plugin, direct file access, and does not undo database changes.
- Forms and checkout are loaded, never submitted.
- Loopback requests must work. If the host blocks them, runs fail and show core's loopback test result.
