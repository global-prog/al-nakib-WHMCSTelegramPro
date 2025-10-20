# WHMCS Telegram Pro
## Version 4.1.0 - Professional Notification System
**Developed By: RAQEEB AL-NAQEEB - AL-NAKIB LTD DEV TEAM**

### 🚀 Features
- ✅ **ZERO Duplicate Messages** - Advanced content-based duplicate detection
- 📊 **Comprehensive Data Extraction** - ALL invoice items, ticket messages, order details
- 🎯 **Smart Detection** - Automatically extracts relevant WHMCS data
- ⚡ **High Performance** - Optimized database queries and caching
- 🔒 **Enterprise Security** - Input validation, secure tokens, admin-only access
- 📱 **Professional Formatting** - Clean, informative messages with status icons
- 🧾 **Invoice Breakdown** - Complete itemized lists with tax calculations
- 💬 **Ticket Previews** - Message excerpts, reply statistics, time tracking
- 📦 **Order Details** - All items, promo codes, nameservers, and more

### 📦 Installation

1. **Upload Module**
   - Extract the `WHMCSTelegramPro` folder to `/modules/notifications/` in your WHMCS installation
   - The path should be: `/your-whmcs/modules/notifications/WHMCSTelegramPro/`

2. **Set Permissions**
   ```bash
   chmod 755 /path/to/whmcs/modules/notifications/WHMCSTelegramPro
   chmod 644 /path/to/whmcs/modules/notifications/WHMCSTelegramPro/*.php
   ```

3. **Configure in WHMCS**
   - Navigate to: Setup → Staff Management → Notification Providers
   - Click "Configure" on "WHMCS Telegram Pro"
   - Enter your Bot Token (get from @BotFather on Telegram)
   - Enter default Chat ID (your Telegram chat/channel ID)
   - Configure settings as needed

### ⚙️ Configuration Settings

| Setting | Recommended | Description |
|---------|-------------|-------------|
| Bot Token | Required | Your Telegram bot token from @BotFather |
| Default Chat ID | Required | Default Telegram chat/channel ID |
| Duplicate Prevention | Medium (5 min) | Prevents duplicate notifications |
| Debug Mode | No | Enable only for troubleshooting |
| Parse Mode | HTML | Message formatting mode |
| Include URL | Yes | Add WHMCS links to messages |

### 🎯 Notification Types Supported

- **Invoices**: Complete breakdown with items, tax, subtotal, payment info, overdue warnings
- **Support Tickets**: Message previews, reply counts, assignment info, related services
- **Orders**: Full item lists, promo codes, invoices, nameservers, billing cycles
- **Clients**: New signup, profile updates with all details
- **Services**: Provisioning, suspension, termination
- **Domains**: Registration, renewal, transfer

### 📋 Enhanced Information Display

**Invoice Notifications Include:**
- Itemized list of all invoice items with amounts
- Subtotal, Tax (with rates), Credit Applied
- Invoice/Due/Paid dates
- Days overdue warnings (if applicable)
- Payment method information
- Client company, phone, country

**Ticket Notifications Include:**
- First 200 characters of ticket message
- Total messages with client/staff breakdown
- Time since last update (human-readable)
- Related service/product information
- Assignment and department details
- Priority with color-coded icons
- CC emails, flags, IP address

**Order Notifications Include:**
- Complete list of all order items (products, domains, addons)
- Billing cycles and registration periods
- Associated invoice with status
- Promo code with discount details
- Custom nameservers if specified
- Order date and payment method

### 🔧 Troubleshooting

1. **Enable Debug Mode**
   - Set "Debug Logging" to "Yes" in provider settings
   - Check WHMCS Activity Log for "WHMCS Telegram Pro" entries

2. **Test Connection**
   - Use the test feature in provider configuration
   - Visit `/modules/notifications/WHMCSTelegramPro/admin_panel.php` (admin login required)

3. **Common Issues**
   - **No notifications**: Check bot token and chat ID
   - **Duplicates**: Increase duplicate prevention window
   - **Missing data**: Enable debug mode to see data extraction

### 📊 Version History

**v4.1.0** (Current)
- Complete rebranding to WHMCS Telegram Pro
- Comprehensive data extraction for all notification types
- Invoice items breakdown with full financial details
- Ticket message previews and reply statistics
- Order complete item lists with all metadata
- Enhanced professional message formatting
- Improved error handling and logging
- Developed by RAQEEB AL-NAQEEB - AL-NAKIB LTD DEV TEAM

### 🔒 Security Notes

- Bot tokens are stored encrypted
- Admin panel requires WHMCS authentication
- All inputs are validated and sanitized
- Debug mode disabled by default
- No sensitive data logged in production

### 📞 Support

For issues or questions:
- Check PRODUCTION_CHECKLIST.md for deployment guide
- Enable debug mode for detailed logging
- Review WHMCS activity log for errors
- Visit: https://github.com/global-prog/al-nakib-WHMCSTelegramPro

### 📄 License

MIT License - See LICENSE file for details

---

**Production Ready** - All security and performance checks passed
**Tested With** - WHMCS 8.0+, PHP 7.4+
**Developed By** - RAQEEB AL-NAQEEB - AL-NAKIB LTD DEV TEAM
**Last Updated** - January 2025
