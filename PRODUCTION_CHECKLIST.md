# Production Checklist - WHMCS Telegram Pro 4.2.2

## Requirements

- WHMCS 8.x or 9.0
- PHP 7.4 to 8.3 (WHMCS 9.0 itself requires PHP 8.2 or newer; 8.3 recommended)
- PHP cURL extension (recommended), or allow_url_fopen with OpenSSL
- Outbound HTTPS from the server to `api.telegram.org`
- A Telegram bot token from @BotFather, with the bot added to the target chat, group or channel
- The module folder writable by the web server user (for the `logs/` folder)

## What the module does

### Security
- [x] The module file cannot be run directly (`if (!defined("WHMCS"))`)
- [x] The bot token is a password field in WHMCS, is never written to logs, and is redacted from error text
- [x] Every value placed into a Telegram message or the diagnostics page is escaped
- [x] Links are created only for absolute http(s) URLs
- [x] Domain transfer secrets (EPP codes) are never included in messages
- [x] TLS certificates are verified for every Telegram request
- [x] Diagnostics panel: WHMCS admin session and a configuration permission, both failing closed
      (if WHMCS cannot confirm either, access is denied), CSRF-protected forms with redirect after each
      action, strict Content Security Policy, no external scripts or stylesheets, every test logged
- [x] Nothing can escape `sendNotification()`: even a failure while logging is contained
- [x] Debug entries record what happened, not client personal data

### Delivery
- [x] Bot token and chat ID formats are validated before calling Telegram, with clear errors
- [x] Up to 2 attempts per message: Telegram rate limits (429, honouring `retry_after` up to 5 s)
      and 5xx errors are retried; network errors are not, so a blocked network cannot stall WHMCS pages.
      No new attempt starts after 15 seconds.
- [x] Connection attempts time out after 5 seconds, requests after 10 seconds
- [x] If Telegram rejects the HTML, the message is resent once as plain text
- [x] If a group was upgraded to a supergroup, the message is redirected to the new chat ID and the
      new ID is logged so the setting can be updated
- [x] Message length is measured the way Telegram counts it; long messages are cut at a line break
      with formatting kept valid; item lists are capped at 15 lines
- [x] Duplicate prevention per chat (10, 5 or 2 minute window, or off). A notification is claimed in
      `mod_whmcstelegrampro_sent` before sending, so two copies fired at the same moment cannot both go out;
      the claim is released if sending fails. For tickets the latest reply is part of the check, so a new
      reply is never mistaken for a duplicate. Rows are removed after 24 hours.
- [x] Messages always have visible text (generic headline for a blank title; an empty custom template
      falls back to the Professional format); the plain-text resend keeps link URLs
- [x] Failures never interrupt WHMCS (a Telegram outage cannot break a ticket reply or checkout)

### Logging
- [x] Errors and debug entries go to `logs/error_log.php`, plus one short Activity Log line per failure
- [x] The log never exceeds 5 MB: when full, the oldest entries are dropped; one entry is capped at 64 KB
- [x] The log starts with a PHP exit line and PHP open tags in logged text are broken up, so the file
      cannot be downloaded or executed over the web (Apache or nginx)
- [x] If `logs/` is not writable, errors fall back to the Activity Log

### Data
- [x] Invoices: items, subtotal, tax, credit, due, paid and overdue details, gateway display name
- [x] Tickets: subject, priority, department, assignee, message counts, related service, latest message
- [x] Orders: services, domains and addons, invoice, promo code (all promotion types), nameservers
- [x] Amounts follow each currency's WHMCS format setting
- [x] Services, domains, clients and other events: the details WHMCS attaches to the notification
- [x] Each database lookup fails on its own (any PHP error, not only database exceptions) and is
      reported in the debug log, so a message is still sent

## Not implemented

- Configurable timeouts, retry counts or rate limits
- Markdown parse mode (messages are always Telegram HTML)
- Proxy support: the server needs direct outbound HTTPS to api.telegram.org

## Deployment steps

1. Upload the `WHMCSTelegramPro` folder to `/modules/notifications/WHMCSTelegramPro/`.
2. Set permissions: 755 for the folder, 644 for files; the folder must be writable by the web server user.
3. Open `/modules/notifications/WHMCSTelegramPro/admin_panel.php` and run "Check connection".
   Fix any red items in "Server environment" first.
4. Go to Setup > Staff Management > Notification Providers, configure "WHMCS Telegram Pro",
   enter the bot token and default chat ID, then click Test Settings.
5. Create rules under Setup > Notifications and trigger a real event (for example, create a test invoice).
6. If something is missing, enable Debug Logging temporarily and read the plugin log in the diagnostics panel.
7. Turn Debug Logging off again once everything works.

## Upgrading

From 4.2.0 or 4.2.1: replace the files. Nothing else is needed.

From 4.1.0:
- Replace the files. The duplicate-prevention table is created automatically on the first notification,
  and the old `alnakib_...` rows are removed from `tblconfiguration` at the same time.
- The Parse Mode setting has been removed; messages are always sent as HTML.
- Custom templates that used `{icon}` still work; the placeholder now renders as nothing.

## Recommendations

- Use a dedicated bot and private chats or channels, because messages contain client names, emails and amounts.
- Rotate the bot token if it is ever exposed.
- Keep Debug Logging off in normal operation.
