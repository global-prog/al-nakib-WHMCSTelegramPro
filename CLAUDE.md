# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is the WHMCS Telegram Pro module (WHMCSTelegramPro) that sends professional notifications from WHMCS to Telegram. It implements the WHMCS NotificationModuleInterface and provides zero-duplicate message delivery with rich data extraction from the WHMCS database.

**Current Version**: 4.1.0
**Status**: Production Ready
**PHP**: 7.4+
**WHMCS**: 8.0+
**Developed By**: RAQEEB AL-NAQEEB - AL-NAKIB LTD DEV TEAM

## Architecture

### Core Components

**WHMCSTelegramPro.php** (Main Module - 987 lines)
- Implements `WHMCS\Module\Contracts\NotificationModuleInterface`
- Namespace: `WHMCS\Module\Notification\WHMCSTelegramPro`
- Entry point: `sendNotification()` method processes all notifications
- Uses WHMCS Capsule (Illuminate Database) for all database operations

**admin_panel.php** (Admin Interface - 372 lines)
- Secure admin-only web interface for testing bot configuration
- Authentication: Requires WHMCS admin session and `CurrentUser` verification
- Features: Bot token testing, test message sending, setup instructions
- Access: `/modules/notifications/WHMCSTelegramPro/admin_panel.php`

**whmcs.json** (Module Metadata)
- Defines module type, version, requirements, and features
- Used by WHMCS marketplace and module system

### Key Architecture Patterns

**Duplicate Prevention System**
- Uses content-based hashing with `generateNotificationHash()`
- Stores notification hashes in `tblconfiguration` table
- Three prevention modes: strict (10min), medium (5min), light (2min)
- Auto-cleanup of records older than 24 hours
- Hash format: `alnakib_{md5_hash}_{YmdHi_timestamp}`

**Data Extraction Pipeline**
1. `extractAndEnrichData()` - Detects notification type from URL/title
2. Type-specific extractors: `getInvoiceData()`, `getTicketData()`, `getOrderData()`
3. Database joins to fetch related client, product, department data
4. Returns enriched array with formatted display data

**Message Generation**
- Three built-in formats: professional, detailed, compact
- Custom template support with variable substitution
- HTML/Markdown parse mode support
- Context-aware icons and status indicators
- Auto-escaping with `htmlspecialchars()`

**Retry Logic**
- `sendWithRetry()` attempts delivery up to MAX_RETRIES (2) times
- 1-second delay between retry attempts
- Returns structured result array: `['success' => bool, 'error' => string, 'message_id' => int]`

## Database Operations

All database queries use **WHMCS Capsule** (Laravel Query Builder):

```php
use WHMCS\Database\Capsule;

// Example query pattern used throughout
$invoice = Capsule::table('tblinvoices')
    ->leftJoin('tblclients', 'tblinvoices.userid', '=', 'tblclients.id')
    ->where('tblinvoices.id', $invoiceId)
    ->select([...])
    ->first();
```

### Tables Used
- `tblinvoices` - Invoice data with client joins
- `tbltickets` - Ticket data with client/department joins
- `tblticketreplies` - Reply history and admin assignment
- `tblorders` - Order data with client joins
- `tblorderitems` - Product/domain details for orders
- `tblclients` - Client information (firstname, lastname, email, company)
- `tblticketdepartments` - Department names
- `tbladmins` - Admin names for ticket assignments
- `tblconfiguration` - Duplicate prevention storage (non-standard usage)

## Configuration

### Module Settings (settings() method)
- `bot_token` - Required, password field, Telegram bot API token
- `default_chat_id` - Optional, fallback chat ID for notifications
- `parse_mode` - Dropdown: HTML (default) or Markdown
- `enable_debug` - Yes/No, logs to WHMCS activity log
- `duplicate_prevention` - Dropdown: strict/medium/light/off

### Per-Rule Settings (notificationSettings() method)
- `chat_id` - Overrides default_chat_id for specific rules
- `use_professional_format` - professional/detailed/compact/custom
- `message_template` - Custom template when format is 'custom'
- `include_url` - Yes/No, add direct WHMCS link
- `silent_notification` - Yes/No, disable notification sound

## Development Commands

### Testing
There is no automated test suite. Testing is done manually:

1. **Test Bot Connection**:
   - Navigate to Setup → Staff Management → Notification Providers
   - Click "Configure" on "WHMCS Telegram Pro"
   - Use "Test Settings" button
   - OR use admin panel at `/modules/notifications/WHMCSTelegramPro/admin_panel.php`

2. **Test Live Notifications**:
   - Create notification rule in Setup → Notifications
   - Trigger real WHMCS event (create invoice, ticket, etc.)
   - Check Telegram for message delivery
   - Review WHMCS Activity Log if debug mode enabled

3. **Debug Mode**:
   - Enable "Debug Logging" in provider settings
   - Check WHMCS Activity Log for entries starting with "WHMCS Telegram Pro v4.1.0"
   - All debug entries include JSON-encoded data arrays

### Deployment

**Installation Path**: `/modules/notifications/WHMCSTelegramPro/`

**File Permissions**:
```bash
chmod 755 /path/to/whmcs/modules/notifications/WHMCSTelegramPro
chmod 644 /path/to/whmcs/modules/notifications/WHMCSTelegramPro/*.php
```

**Pre-Deployment Checklist**: See PRODUCTION_CHECKLIST.md for comprehensive security, performance, and configuration validation steps.

**Post-Deployment**:
1. Configure provider in WHMCS admin
2. Test connection using built-in test
3. Set debug mode to "No" for production
4. Configure notification rules for desired events
5. Test with real events

## Important Implementation Details

### Notification Type Detection
The module detects notification types using multiple strategies in order:
1. URL regex matching: `/invoices.*id=(\d+)/`, `/supporttickets.*id=(\d+)/`, etc.
2. Title regex matching: `/Invoice #(\d+)/`, `/#([A-Z]+-\d+)/` (ticket mask)
3. Attributes fallback: `$attributes['invoiceid']`, `$attributes['ticketid']`, etc.

### Security Features
- WHMCS init check: `if (!defined("WHMCS")) die()`
- Admin panel authentication via `CurrentUser::isAuthenticatedAdmin()`
- Bot token stored as password field (masked in UI)
- Input sanitization with `htmlspecialchars()` and `strip_tags()`
- SSL verification enabled for Telegram API calls
- Admin access logged via `logActivity()`

### Telegram API Integration
- API Base: `https://api.telegram.org/bot{TOKEN}/`
- Methods used: `getMe` (test), `sendMessage` (notifications)
- Uses PHP `file_get_contents()` with `stream_context_create()`
- Timeout: 30 seconds (DEFAULT_TIMEOUT constant)
- No external dependencies (cURL not required)

### Message Length Handling
- Telegram limit: 4096 characters
- Module truncates messages automatically if exceeded
- Priority given to title, client info, amount, status

### Rate Limiting
The code references rate limiting (RATE_LIMIT_DEFAULT = 15) but the actual implementation is not present in WHMCSTelegramPro.php. The constant exists but is not actively used in the notification flow.

## Common Modification Scenarios

### Adding New Notification Type
1. Add type detection logic in `extractAndEnrichData()` using URL/title regex
2. Create new `getXxxData($id)` method following pattern of `getInvoiceData()`
3. Add database queries using Capsule with appropriate joins
4. Return enriched data array with 'type', 'client', 'amount', 'status', 'details'
5. Add icon in `getTypeIcon()` and action text in `getActionText()`

### Modifying Message Format
1. Edit format methods: `generateProfessionalFormat()`, `generateDetailedFormat()`, `generateCompactFormat()`
2. Use `htmlspecialchars()` for all user data to prevent XSS
3. Respect `$includeUrl` parameter for link inclusion
4. Use icon helper methods: `getTypeIcon()`, `getStatusIcon()`, `getPriorityIcon()`

### Changing Duplicate Prevention Logic
1. Modify time windows in `isDuplicateNotification()` $windows array
2. Adjust hash generation in `generateNotificationHash()` to change uniqueness criteria
3. Modify cleanup interval in `recordNotificationSent()` (currently 24 hours)

## Code Style Conventions

- PSR-4 autoloading with WHMCS namespace structure
- Use `try-catch` blocks for all database and API operations
- Log errors via `logDebug()` or `logActivity()` when available
- Return structured arrays from helper methods
- Use constants for magic numbers (VERSION, RATE_LIMIT_DEFAULT, MAX_RETRIES)
- Comprehensive inline comments for complex logic
- Array destructuring for readability: `['success' => $success, 'error' => $error]`

## Critical Constraints

- **No Composer dependencies** - Module must work with vanilla WHMCS PHP installation
- **Database portability** - Use Capsule only, no raw SQL
- **WHMCS compatibility** - Must implement NotificationModuleInterface contract
- **Backward compatibility** - Settings changes must not break existing notification rules
- **Performance** - Minimize database queries, use joins instead of N+1 queries
- **Security first** - Never log bot tokens, always sanitize output, verify admin access
