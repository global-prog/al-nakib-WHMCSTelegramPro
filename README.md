# WHMCS Telegram Pro

Version 4.2.2 - Telegram notifications for WHMCS

Developed by RAQEEB AL-NAQEEB - AL-NAKIB LTD DEV TEAM

## Features

- Sends WHMCS notification rule events (invoices, tickets, orders, services, domains, clients) to Telegram
- Clear default message: a headline, a one-line summary (number, status, amount), the key facts, and a link back to WHMCS
- Invoice details: items, tax, credit, due and paid dates, overdue days, payment method
- Ticket details: subject, department, assignee, related service, message counts, and the latest message
- Order details: services, domains and addons, invoice, promo code, nameservers
- Four formats: Professional (default), Detailed, Compact, and Custom templates
- Duplicate prevention per chat, with a configurable time window
- Failed sends never interrupt WHMCS; they are logged instead
- Diagnostics panel that tests delivery from the server itself and shows the plugin log
- Plain text formatting, no emoji

## Requirements

- WHMCS 8.x or 9.0
- PHP 7.4 to 8.3 (WHMCS 9.0 itself requires PHP 8.2 or newer)
- PHP cURL extension (recommended), or allow_url_fopen with OpenSSL
- Outbound HTTPS from the server to api.telegram.org

## Installation

1. Upload the `WHMCSTelegramPro` folder to `/modules/notifications/` so the path is
   `/your-whmcs/modules/notifications/WHMCSTelegramPro/`.
2. Set permissions. The folder must be writable by the web server user so the plugin can create `logs/`:

   ```bash
   chmod 755 /path/to/whmcs/modules/notifications/WHMCSTelegramPro
   chmod 644 /path/to/whmcs/modules/notifications/WHMCSTelegramPro/*.php
   ```

3. In WHMCS go to Setup > Staff Management > Notification Providers, configure "WHMCS Telegram Pro",
   enter the bot token and default chat ID, and click Test Settings.
4. Go to Setup > Notifications, create a rule, and choose WHMCS Telegram Pro as the provider.

## Settings

Provider settings:

| Setting | Recommended | Description |
|---------|-------------|-------------|
| Bot Token | Required | Token from @BotFather |
| Default Chat ID | Required unless every rule sets its own | Numeric ID (for example -1001234567890) or @channelname |
| Enable Debug Logging | Off | Writes diagnostic entries to the plugin log |
| Duplicate Prevention | Medium (5 min) | Blocks identical notifications to the same chat within the window |

Per-rule settings: Chat ID (overrides the default), Message Format, Custom Template,
Include Direct Link, Silent Notification.

Custom template variables: `{title}`, `{message}`, `{client}`, `{amount}`, `{status}`, `{type}`, `{url}`,
`{date}`, `{items}`, and any other field the module collects (for example `{due_date}`, `{priority}`,
`{latest_message}`). Telegram HTML tags such as `<b>`, `<i>` and `<a>` may be used in the template;
inserted values are always escaped.

## Diagnostics panel

Open `/modules/notifications/WHMCSTelegramPro/admin_panel.php` while logged in to the WHMCS admin area
(there is also a link in the Bot Token setting). It shows:

- Server environment: PHP, cURL, OpenSSL, log folder, duplicate table
- Connection to Telegram: DNS and HTTPS checks from the server
- Send a test message: runs the module's own sending code from the server
- Plugin log: the latest entries, with a Clear log button

## Logging

- Errors and debug entries go to `modules/notifications/WHMCSTelegramPro/logs/error_log.php`.
- The file never exceeds 5 MB: when it is full the oldest entries are dropped.
- The file starts with a PHP exit line, so it cannot be downloaded over the web.
- Each failure also adds one short line to the WHMCS Activity Log.
- Debug entries record what happened, not client personal data.

## Troubleshooting

- No notifications: open the diagnostics panel and run "Check connection". If it fails, the server
  cannot reach Telegram (firewall, hosting provider block, or missing cURL/OpenSSL).
- "Invalid bot token": copy the token again from @BotFather.
- "chat not found": the bot must be a member (or admin, for channels) of the chat, and the chat ID must be exact.
- Group upgraded to a supergroup: the plugin redirects messages and logs the new chat ID; update the setting.
- Duplicates: raise the Duplicate Prevention window.

## Version history

**v4.2.2** (current)
- Any PHP error during a database lookup (not only database exceptions) now just leaves that detail
  out; previously it could stop the notification from being sent
- A new reply to a ticket is never blocked as a duplicate of the previous reply, even when WHMCS
  sends the same text for both; repeated copies of the same event are still blocked
- A failure while recording a sent notification no longer blocks the next delivery of that event
- Messages are never sent empty: a blank title gets a generic headline, and a custom template that
  produces no text falls back to the Professional format
- When Telegram rejects the formatting, the plain-text resend keeps the link back to WHMCS
- The ticket's latest message is no longer shown twice when WHMCS includes it in the notification text
- Works when the host disables curl_exec (falls back to the stream method); no curl_close()
  deprecation notice on newer PHP versions
- Diagnostics panel explains common connection errors (DNS, firewall, timeout, certificates) and
  resolves IPv6-only hosts

**v4.2.1**
- Diagnostics panel rebuilt: tests run on the server, environment and network checks, log viewer;
  access is denied whenever the admin session or permission cannot be verified
- A failure while logging can no longer escape and interrupt WHMCS
- Invoice and order title lookups match the displayed number before the database ID
- Duplicate prevention claims a notification before sending, so simultaneous duplicates are blocked
- Bot token and chat ID are validated with clear error messages
- Currency amounts follow the WHMCS currency format setting
- All promo code types are described correctly
- Messages in any language are kept (the repeated-text check is Unicode aware)
- Links are only created for absolute http(s) URLs
- Debug log no longer stores client personal data
- Supergroup chat ID changes are handled
- All files are plain ASCII

**v4.2.0**
- Removed emoji; fixed the Custom Template crash; fixed order and ticket data lookups
- Uses WHMCS notification attributes for every event type
- Duplicate prevention moved to its own table and made per chat
- Yes/No settings work with the values WHMCS saves
- Failed sends are logged instead of breaking the WHMCS page; 5 MB capped plugin log
- Domain transfer secrets are never included in messages
- Redesigned default message

**v4.1.0**
- Rebranded to WHMCS Telegram Pro

## Security notes

- The bot token is stored by WHMCS as a password setting and is never written to logs
- The diagnostics panel requires an authenticated WHMCS admin session and a configuration permission,
  and its forms are protected against cross-site request forgery
- All values placed into messages and pages are escaped
- TLS certificates are verified for every request to Telegram

## Support

- Repository: https://github.com/global-prog/al-nakib-WHMCSTelegramPro
- Deployment guide: PRODUCTION_CHECKLIST.md

## License

MIT (as declared in whmcs.json)
