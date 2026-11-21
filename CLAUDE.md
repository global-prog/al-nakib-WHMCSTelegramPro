# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

WHMCS Telegram Pro (v4.2.2) is a WHMCS **notification provider** module that forwards WHMCS notification-rule events (invoices, tickets, orders, services, domains, clients) to Telegram. Plain PHP, no build step, no Composer dependencies. Targets WHMCS 8.x and 9.0 (9.0 requires PHP 8.2+, 8.3 recommended) on PHP 7.4 through 8.3. Keep the code 7.4-compatible: no `match`, nullsafe `?->`, constructor promotion, `str_contains()` and similar.

Deployment path: `<whmcs>/modules/notifications/WHMCSTelegramPro/`. WHMCS discovers the module by folder name, file name `WHMCSTelegramPro.php`, and class `WHMCS\Module\Notification\WHMCSTelegramPro\WHMCSTelegramPro`. All three must stay in sync.

## Owner rules

- **Plain ASCII only** in every shipped file and in message output: no emoji, em/en dashes, arrows, curly quotes, bullet characters or ellipsis characters. Use `-`, `>`, `|`, `...`. Check with `grep -nP '[^\x00-\x7F]' *.php *.md *.txt *.json *.svg`. (Currency symbols coming from the WHMCS database are data and may appear in messages.)
- **No AI attribution** anywhere: no `Co-Authored-By` or "Generated with" lines in commits or PRs.
- This CLAUDE.md is **not** shipped in the release zip.

## Workspace layout

- This directory (`WHMCSTelegramPro/`) is the git repo (remote `global-prog/al-nakib-WHMCSTelegramPro`, branch `main`) and the canonical source.
- The parent folder `X:\WHMCS-TelegramNotificationsPlugin\` is not under version control. It holds release zips and a stale older copy in `al-nakib-WHMCSTelegramPro-main/`. Don't edit that copy.

## Commands

No test suite, linter config, or build. Local checks:

```bash
php -l WHMCSTelegramPro.php
php -l admin_panel.php
```

Offline logic testing works with a scratch harness that:
- defines `WHMCS`;
- stubs `WHMCS\Module\Contracts\NotificationModuleInterface`, `WHMCS\Notification\Contracts\NotificationInterface` and `WHMCS\Module\Notification\DescriptionTrait` (its setters must `return $this`);
- stubs `WHMCS\Database\Capsule` with a chainable fake (`table()`, `where()` ... return `$this`; `first()`, `get()`, `value()`, `exists()`, `insertGetId()`, `delete()` return canned data);
- then calls private methods via `ReflectionMethod`.

`admin_panel.php` can be tested the same way: put a copy under a fake root (`root/modules/notifications/WHMCSTelegramPro/`) with a stub `root/init.php` that defines `WHMCS`, `$_SESSION`, `CurrentUser`, `Capsule`, `checkPermission()` and `logActivity()`, then run the panel with the PHP CLI.

Run everything with `php -d error_reporting=E_ALL` to catch PHP 8.x deprecations. The local PHP (`C:\tools\php83`) loads no `mbstring`/`curl`/`openssl` by default, which is why the code has mbstring-free `textLength()`/`textCut()` helpers. To exercise the cURL path, add `-d extension_dir=C:/tools/php83/ext -d extension=curl -d extension=openssl -d extension=mbstring`. This machine's network re-signs TLS, so live API calls also need `-d curl.cainfo=<PEM exported from the Windows root store>`, at a path without `~`. Never disable verification in the module. Point harnesses at a *copy* of the module, or they will create `logs/` inside the repo.

Real testing needs a live WHMCS install: open `admin_panel.php` (diagnostics), then Setup > Staff Management > Notification Providers > WHMCS Telegram Pro > Test Settings, then create a rule under Setup > Notifications and trigger an event.

Build the release zip from the parent directory with forward-slash paths (PowerShell 5.1 `Compress-Archive` writes backslashes, which break on Linux). Ship only `*.php`, `*.svg`, `*.json`, `*.txt` and `README.md` / `PRODUCTION_CHECKLIST.md`, **not** `CLAUDE.md`, `.git`, `.claude`, `.gitignore` or `logs/`. The command `zip` is not installed here; use Python's `zipfile` (`C:/Program Files/Python313/python.exe`, not the `python3` store stub, which hangs).

**Version bumps:** update `VERSION` and the docblock in `WHMCSTelegramPro.php`, `whmcs.json`, the `admin_panel.php` docblock, README.md (header and history), INSTALL.txt (two places), PRODUCTION_CHECKLIST.md (title), and this file.

## WHMCS contract facts (verified against developers.whmcs.com)

- `getAttributes()` returns **objects** with `getLabel()`, `getValue()`, `getUrl()`, `getStyle()` and `getIcon()`. It is not an associative array. `getAttributeList()` normalises them.
- The docs say `sendNotification()` should throw on failure. On the owner's live install a thrown exception aborted the admin action that triggered it (a ticket reply showed WHMCS's "Oops! Something went wrong" page). So `sendNotification()` catches `\Throwable`, records it with `logError()` (always on) and returns. The catch body is itself wrapped in a `try` and `logError()` guards `logActivity()`, because logging can fail too (dropped DB connection). **Nothing may escape `sendNotification()`; do not reintroduce a throw.**
- `getDynamicField()` returns `['values' => [...]]`.
- Setting types: text, password, yesno, dropdown, radio, textarea, dynamic. A checked `yesno` is saved as `on`, so always test it through `isEnabled()`, never with `=== 'yes'`.

## Architecture (`WHMCSTelegramPro.php`, one class)

`sendNotification()` pipeline:

1. `getChatId()` (per-rule `chat_id` overrides `default_chat_id`), then `validateCredentials()` rejects malformed tokens and chat IDs with an actionable message.
2. `detectEntity()`: finds the type and ID by matching `invoices.php`, `supporttickets.php`, `orders.php`, `clientsservices.php`, `clientsdomains.php` or `clientssummary.php` against the notification URL, then attribute URLs. The title fallback (`Invoice #`, `Order #`, `Ticket #`) yields a display number (`lookup`), not a row ID.
3. `extractAndEnrichData()` (see step 5) runs **before** the dedup claim, because the ticket hash needs the latest reply ID.
4. **Dedup:** hash of chat ID, entity, title and first 100 characters of the message, plus `last_reply_id` for tickets (two different replies can carry identical WHMCS text; a repeated copy of one event has the same DB state, so it still hashes the same). Stored in `mod_whmcstelegrampro_sent` (created lazily by `ensureDedupTable()`, which also removes pre-4.2 `alnakib_%` rows from `tblconfiguration`). `claimNotification()` claims the hash *before* sending: after a cheap `exists()` pre-check it inserts its own row, then re-checks for an earlier row with the same hash inside the window (`id < own id`) and backs off (releasing its row) if one exists. Of two requests handling the same event at the same moment, exactly one sends; the pre-check alone would let both through, so keep the post-insert re-check. A successful claim also prunes rows older than 24 h (in its own try, so a cleanup failure keeps the claim). The `catch` block calls `releaseClaim()` when the send did not succeed. Dedup **fails open**: if the table can't be created or queried, the message is sent rather than dropped; a row already inserted before the failure is still returned so it gets released. Window: strict 10 min, medium 5, light 2; `off` writes no rows. `ensureDedupTable()` treats a concurrent "table already exists" as success.
5. `extractAndEnrichData()`: `getInvoiceData()`, `getTicketData()` or `getOrderData()`; other types use only the WHMCS attributes. **Every sub-query has its own try/catch that calls `logDebug`**, so one bad column can't blank the whole message. **All catches in the module take `\Throwable`, not `\Exception`**: a PHP 8 `TypeError`/`Error` from unexpected data must degrade a detail, not cancel the notification. Schema notes:
   - order contents come from `tblhosting`, `tbldomains` and `tblhostingaddons` by `orderid` (there is no order-items table); the order's invoice is `tblorders.invoiceid`;
   - `tbltickets.tid` is the public ticket number (`c` is an access key); `tbltickets.flag` is the assigned admin ID; `tbltickets.service` looks like `S<id>` or `D<id>`;
   - ticket staff replies are detected via `Capsule::schema()->hasColumn()` on `tblticketreplies.admin`/`adminid`; the latest reply is fetched regardless, and when neither column exists its author is left empty (shown as "Latest message:");
   - `tblorders.nameservers` is comma-separated; promo types are Percentage, Fixed Amount, Price Override, Free Setup;
   - currency format (`tblcurrencies.format`, 1 to 4) is read in a separate cached query (`currencyFormat()`) so a schema difference can only affect formatting.
6. **Format:** `generateProfessionalMessage()` dispatches to professional (default), detailed, compact, or custom (`processCustomTemplate()`: one `preg_replace_callback` pass, so inserted values are never re-scanned). Output is **always Telegram HTML**. Every format uses `headline()` (generic text for a blank title), and a result with no visible text (`plainText()` empty, e.g. a custom template whose placeholders are all empty) falls back to the professional format, because Telegram rejects empty messages.
   - Professional layout, blocks separated by blank lines: bold headline plus `summaryLine()`; the WHMCS message unless `repeatsTitle()` (Unicode aware) says it only repeats the headline or `repeatsLatestMessage()` says it is the ticket text shown below; key facts from `invoiceLines()` / `ticketLines()` / `orderLines()` or the attribute list; the item list (`itemList()`, max 15) or the ticket's latest message in a `<blockquote>`; the action link.
   - `link()` only creates links for absolute http(s) URLs (`isHttpUrl()`); relative attribute URLs are resolved with `absoluteUrl()` against `adminBaseUrl()`.
   - Escape with `e()` (null-safe). `limitLength()` measures with `telegramLength()` (UTF-16 units, as Telegram counts; characters outside the BMP count 2), cuts at a line break past 4000, and re-closes a `<blockquote>` the cut lands inside. Custom templates convert `<br>` to newlines (Telegram has no `<br>`).
   - Title fallbacks look up the displayed number first (`invoicenum`, `ordernum`) and the row ID only if nothing matches, using `clone` of the base query for each attempt.
7. **Send:** `sendWithRetry()` > `sendTelegramMessage()` > `apiRequest()`. cURL when both `curl_init` and `curl_exec` exist (hosts sometimes disable only `curl_exec`; 5 s connect, 10 s total), streams otherwise (requires OpenSSL). `curl_close()` is called only below PHP 8.0 (no-op since 8.0, deprecated in 8.5); the token is redacted from error text. `MAX_RETRIES` (2) is the total number of attempts, not extra retries. Retries only on 429 (honouring `retry_after` up to 5 s) and 5xx, and no new attempt starts after `RETRY_BUDGET` (15 s); network errors are not retried because notifications run inside the admin request. `migrate_to_chat_id` (group upgraded to supergroup) triggers one resend to the new ID plus a `logError()` telling the admin to update the setting; retries reuse the new ID. "can't parse entities" triggers one plain-text resend via `htmlToPlain()`, which turns links into `text (url)` before stripping tags. `validateCredentials()` never echoes unsafe characters from the chat ID.

Logging:
- `logError()` (always on) writes the full text to `logs/error_log.php` and a 300-character line to the Activity Log. `logDebug()` (only with "Enable Debug Logging") writes to the file; the processing entry uses `debugSummary()` so no client personal data is logged.
- `writeLog()` enforces the owner's requirement that **the plugin log never exceeds 5 MB** (`LOG_MAX_BYTES`): exclusive lock, keep the newest half at a line boundary when full, 64 KB per entry (`LOG_MAX_ENTRY_BYTES`). The cap is read from the private `$logMaxBytes` property, not the constant, so a harness can lower it via `ReflectionProperty` to test rotation without writing 5 MB. The file starts with `LOG_HEADER` (a PHP exit line) because the production server runs nginx (aaPanel), which ignores `.htaccess`; `<?` in logged text is broken up to `< ?`. `logFile()` is public so the panel reads the same path.

Use `WHMCS\Database\Capsule` for all DB access, never raw SQL. Never put `transfersecret` or other secrets into messages.

### `admin_panel.php` (diagnostics panel)

Standalone page that requires `../../../init.php`. Access **fails closed**: `$_SESSION['adminid']`, then `CurrentUser` (any error = 403), then `checkPermission()` in no-redirect mode for any of "Configure General Settings", "Configure Addon Modules", "Configure Notifications" (names are not documented). `checkPermission()` lives in `includes/adminfunctions.php`, which init.php does not load for non-admin pages, so the panel loads it from `ROOTDIR`; if the function still does not exist, access is denied. Don't read `$_SESSION['adminusername']`.

- All actions are POST with a session CSRF token (`tgpro_csrf`, compared with `hash_equals`), followed by a 303 redirect (post/redirect/get, result carried in `$_SESSION['tgpro_flash']`) so a refresh never repeats an action. Security headers include a strict CSP (no scripts at all) and `X-Frame-Options`.
- "Send test" instantiates the module and calls `testConnection()` **on the server**, so it tests the real network path. It tries to read saved provider settings from `tblnotificationproviders` (JSON, then WHMCS `decrypt()` on the whole value and on the token field); a token is used only if it has the bot token format, otherwise the panel asks for it. That storage is undocumented. The token is never echoed into the page.
- "Check connection" does DNS (`gethostbyname`, then an AAAA lookup for IPv6-only hosts) plus an HTTPS request to `api.telegram.org` without a token; `tgpro_curl_hint()` explains common cURL error numbers (6, 7, 28, 35/60/77).
- The module class is required only if `class_exists(..., false)` says WHMCS has not loaded it already.
- There is no proxy support. If the server cannot reach Telegram, the fix is network-side.
- The log viewer reads the last 64 KB of the log; "Clear log" rewrites the file to `LOG_HEADER`.

## Unverified on a live install

- The exact `tbltickets` / `tblticketreplies` / `tblhostingaddons` / `tblcurrencies.format` columns across WHMCS 8.x/9.x. Lookups degrade gracefully, and debug logging reports failures.
- The admin-URL shapes used by `detectEntity()` for service, domain and client events.
- The admin permission names and the `tblnotificationproviders.settings` storage format used by `admin_panel.php`.
