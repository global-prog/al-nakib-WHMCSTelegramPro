<?php
/**
 * WHMCS Telegram Pro - Professional Notification System
 * Complete WHMCS notification solution with comprehensive data extraction
 *
 * @author RAQEEB AL-NAQEEB - AL-NAKIB LTD DEV TEAM
 * @version 4.1.0
 */

namespace WHMCS\Module\Notification\WHMCSTelegramPro;

use WHMCS\Module\Contracts\NotificationModuleInterface;
use WHMCS\Notification\Contracts\NotificationInterface;
use WHMCS\Module\Notification\DescriptionTrait;
use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * WHMCS Telegram Pro Notification Module
 */
class WHMCSTelegramPro implements NotificationModuleInterface
{
    use DescriptionTrait;

    const VERSION = '4.1.0';
    const RATE_LIMIT_DEFAULT = 15;
    const DEFAULT_TIMEOUT = 30;
    const MAX_RETRIES = 2;

    public function __construct()
    {
        $this->setDisplayName('WHMCS Telegram Pro')
             ->setLogoFileName('logo.svg');
    }

    /**
     * Provider Settings
     */
    public function settings()
    {
        return [
            'admin_panel_info' => [
                'FriendlyName' => '🚀 Admin Panel & Configuration',
                'Type' => 'text',
                'Description' => '
                <div style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
                           color: white; padding: 20px; border-radius: 10px; text-align: center;">
                    <h3 style="color: white; margin: 0 0 15px 0;">
                        ✅ WHMCS Telegram Pro v' . self::VERSION . '
                    </h3>
                    <p style="margin: 10px 0;">Professional notifications with comprehensive data extraction. By RAQEEB AL-NAQEEB - AL-NAKIB LTD</p>

                    <a href="javascript:void(0)"
                       onclick="window.open(\'../modules/notifications/WHMCSTelegramPro/admin_panel.php\', \'_blank\', \'width=1200,height=800\')"
                       style="display: inline-block; background: rgba(255,255,255,0.9); color: #333;
                              padding: 12px 24px; border-radius: 25px; text-decoration: none;
                              font-weight: bold; margin: 10px;">
                        🔧 Open Admin Panel
                    </a>

                    <br><br>
                    <small>✅ NO DUPLICATES | ✅ REAL DATA | ✅ PROFESSIONAL FORMAT</small>
                </div>',
            ],
            'bot_token' => [
                'FriendlyName' => 'Bot Token',
                'Type' => 'password',
                'Description' => 'Your Telegram Bot Token from @BotFather',
                'Required' => true,
            ],
            'default_chat_id' => [
                'FriendlyName' => 'Default Chat ID',
                'Type' => 'text',
                'Description' => 'Default chat/channel ID (e.g., -1001234567890)',
                'Required' => false,
            ],
            'parse_mode' => [
                'FriendlyName' => 'Parse Mode',
                'Type' => 'dropdown',
                'Options' => [
                    'HTML' => 'HTML',
                    'Markdown' => 'Markdown',
                ],
                'Default' => 'HTML',
            ],
            'enable_debug' => [
                'FriendlyName' => 'Enable Debug Logging',
                'Type' => 'yesno',
                'Description' => 'Log notification attempts for debugging',
                'Default' => 'no',
            ],
            'duplicate_prevention' => [
                'FriendlyName' => 'Duplicate Prevention',
                'Type' => 'dropdown',
                'Options' => [
                    'strict' => 'Strict (10 min window)',
                    'medium' => 'Medium (5 min window)',
                    'light' => 'Light (2 min window)',
                    'off' => 'Disabled',
                ],
                'Default' => 'medium',
                'Description' => 'Prevent duplicate notifications based on content and time window',
            ],
        ];
    }

    /**
     * Test connection
     */
    public function testConnection($settings)
    {
        if (empty($settings['bot_token'])) {
            throw new \Exception('Bot token is required');
        }

        // Test bot info
        $url = "https://api.telegram.org/bot{$settings['bot_token']}/getMe";
        $context = stream_context_create(['http' => ['timeout' => 10]]);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new \Exception('Could not connect to Telegram API');
        }

        $data = json_decode($response, true);

        if (!$data || !$data['ok']) {
            throw new \Exception('Invalid bot token: ' . ($data['description'] ?? 'Unknown error'));
        }

        $botInfo = $data['result'];

        // Test message to default chat if provided
        if (!empty($settings['default_chat_id'])) {
            $testMessage = "🚀 <b>WHMCS Telegram Pro v" . self::VERSION . " - Test Connection</b>\n\n✅ Configuration verified successfully!\nBy RAQEEB AL-NAQEEB - AL-NAKIB LTD\n\n📅 " . date('Y-m-d H:i:s');

            $result = $this->sendTelegramMessage(
                $settings['bot_token'],
                $settings['default_chat_id'],
                $testMessage,
                $settings['parse_mode'] ?? 'HTML'
            );

            if (!$result['success']) {
                throw new \Exception('Bot valid but chat error: ' . $result['error']);
            }
        }

        return [
            'success' => true,
            'message' => "✅ Bot: @{$botInfo['username']}" . ((!empty($settings['default_chat_id'])) ? " | Test sent!" : "")
        ];
    }

    /**
     * Notification settings per rule
     */
    public function notificationSettings()
    {
        return [
            'chat_id' => [
                'FriendlyName' => 'Chat ID',
                'Type' => 'text',
                'Description' => 'Specific chat/channel ID (overrides default)',
                'Required' => false,
            ],
            'use_professional_format' => [
                'FriendlyName' => 'Message Format',
                'Type' => 'dropdown',
                'Options' => [
                    'professional' => 'Professional (Clean & Structured)',
                    'detailed' => 'Detailed (All Available Data)',
                    'compact' => 'Compact (Minimal)',
                    'custom' => 'Custom Template',
                ],
                'Default' => 'professional',
            ],
            'message_template' => [
                'FriendlyName' => 'Custom Template (if Custom selected)',
                'Type' => 'textarea',
                'Description' => 'Use variables: {title}, {message}, {client}, {amount}, {status}, etc.',
                'Rows' => '4',
                'Default' => "",
            ],
            'include_url' => [
                'FriendlyName' => 'Include Direct Link',
                'Type' => 'yesno',
                'Description' => 'Add direct link to WHMCS item',
                'Default' => 'yes',
            ],
            'silent_notification' => [
                'FriendlyName' => 'Silent Notification',
                'Type' => 'yesno',
                'Description' => 'Send without sound',
                'Default' => 'no',
            ],
        ];
    }

    /**
     * Send notification - ULTIMATE FIX VERSION
     */
    public function sendNotification(
        NotificationInterface $notification,
        $moduleSettings,
        $notificationSettings
    ) {
        try {
            // Generate unique notification hash for deduplication
            $notificationHash = $this->generateNotificationHash($notification);

            // Check for duplicates with configurable window
            if ($this->isDuplicateNotification($notificationHash, $moduleSettings)) {
                $this->logDebug('DUPLICATE BLOCKED', ['hash' => $notificationHash], $moduleSettings);
                return 'duplicate_blocked_' . time();
            }

            // Extract and enrich notification data
            $enrichedData = $this->extractAndEnrichData($notification);

            $this->logDebug('ULTIMATE v' . self::VERSION . ' - Processing', $enrichedData, $moduleSettings);

            // Get chat ID
            $chatId = $this->getChatId($notificationSettings, $moduleSettings);
            if (empty($chatId)) {
                throw new \Exception('No chat ID configured');
            }

            // Generate professional message
            $message = $this->generateProfessionalMessage($notification, $enrichedData, $notificationSettings);

            // Send with retry logic
            $result = $this->sendWithRetry(
                $moduleSettings['bot_token'],
                $chatId,
                $message,
                $moduleSettings,
                $notificationSettings
            );

            if ($result['success']) {
                // Record this notification to prevent duplicates
                $this->recordNotificationSent($notificationHash);

                $this->logDebug('SUCCESS v' . self::VERSION, [
                    'message_id' => $result['message_id'],
                    'hash' => $notificationHash
                ], $moduleSettings);

                return $result['message_id'];
            }

            throw new \Exception($result['error']);

        } catch (\Exception $e) {
            $this->logDebug('ERROR v' . self::VERSION, ['error' => $e->getMessage()], $moduleSettings);
            throw $e;
        }
    }

    /**
     * Generate unique hash for notification deduplication
     */
    private function generateNotificationHash($notification)
    {
        $attributes = $notification->getAttributes();

        // Extract the unique identifier for this notification
        $uniqueId = '';
        $type = '';

        // Check URL for IDs
        $url = $notification->getUrl();
        if (preg_match('/invoices.*id=(\d+)/', $url, $matches)) {
            $type = 'invoice';
            $uniqueId = 'invoice_' . $matches[1];
        } elseif (preg_match('/supporttickets.*id=(\d+)/', $url, $matches)) {
            $type = 'ticket';
            $uniqueId = 'ticket_' . $matches[1];
        } elseif (preg_match('/orders.*id=(\d+)/', $url, $matches)) {
            $type = 'order';
            $uniqueId = 'order_' . $matches[1];
        }

        // Fallback to attributes
        if (empty($uniqueId)) {
            if (isset($attributes['invoiceid'])) {
                $uniqueId = 'invoice_' . $attributes['invoiceid'];
            } elseif (isset($attributes['ticketid'])) {
                $uniqueId = 'ticket_' . $attributes['ticketid'];
            } elseif (isset($attributes['orderid'])) {
                $uniqueId = 'order_' . $attributes['orderid'];
            } elseif (isset($attributes['relid'])) {
                $uniqueId = 'rel_' . $attributes['relid'];
            }
        }

        // Create hash based on unique content
        $hashData = [
            'unique_id' => $uniqueId,
            'title' => $notification->getTitle(),
            'message' => substr($notification->getMessage(), 0, 100), // First 100 chars
            'type' => $type ?: 'unknown' // Use detected type or 'unknown'
        ];

        return 'alnakib_' . md5(serialize($hashData)) . '_' . date('YmdHi');
    }

    /**
     * Check if notification is duplicate based on settings
     */
    private function isDuplicateNotification($hash, $settings)
    {
        $prevention = $settings['duplicate_prevention'] ?? 'medium';

        if ($prevention === 'off') {
            return false;
        }

        // Define time windows in minutes
        $windows = [
            'strict' => 10,  // 10 minutes
            'medium' => 5,   // 5 minutes
            'light' => 2     // 2 minutes
        ];

        $windowMinutes = $windows[$prevention] ?? 5;

        try {
            // Extract base hash without timestamp
            $baseHash = substr($hash, 0, strrpos($hash, '_'));

            // Check for recent notifications with same base hash
            $recentTime = date('Y-m-d H:i:s', strtotime("-{$windowMinutes} minutes"));

            $exists = Capsule::table('tblconfiguration')
                ->where('setting', 'like', $baseHash . '%')
                ->where(function($query) use ($recentTime) {
                    $query->where('created_at', '>=', $recentTime)
                          ->orWhere('updated_at', '>=', $recentTime);
                })
                ->exists();

            if ($exists) {
                $this->logDebug('Duplicate notification blocked', ['hash' => $hash, 'base' => $baseHash], $settings);
                return true; // Duplicate found
            }
        } catch (\Exception $e) {
            // If we can't check, allow the notification but log the error
            error_log("WHMCS Telegram Pro: Duplicate check failed - " . $e->getMessage());
            return false;
        }

        return false; // Not duplicate
    }

    /**
     * Record notification to prevent future duplicates
     */
    private function recordNotificationSent($hash)
    {
        try {
            Capsule::table('tblconfiguration')->insertOrIgnore([
                'setting' => $hash,
                'value' => time(),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Clean up old notification records (older than 24 hours)
            Capsule::table('tblconfiguration')
                ->where('setting', 'like', 'alnakib_%')
                ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-24 hours')))
                ->delete();

        } catch (\Exception $e) {
            // Log the error but don't fail the notification
            error_log("WHMCS Telegram Pro: Failed to record notification - " . $e->getMessage());
        }
    }

    /**
     * Extract and enrich notification data with real WHMCS data
     */
    private function extractAndEnrichData($notification)
    {
        $attributes = $notification->getAttributes();
        $enriched = [
            'title' => $notification->getTitle(),
            'message' => $notification->getMessage(),
            'url' => $notification->getUrl(),
            'type' => 'unknown',
            'client' => '',
            'amount' => '',
            'status' => '',
            'details' => []
        ];

        try {
            // Detect notification type and extract ID
            $url = $notification->getUrl();
            $title = $notification->getTitle();

            if (preg_match('/invoices.*id=(\d+)/', $url, $matches) ||
                preg_match('/Invoice #(\d+)/', $title, $matches) ||
                isset($attributes['invoiceid'])) {

                $invoiceId = $matches[1] ?? $attributes['invoiceid'] ?? $attributes['relid'];
                $enriched = array_merge($enriched, $this->getInvoiceData($invoiceId));

            } elseif (preg_match('/supporttickets.*id=(\d+)/', $url, $matches) ||
                      preg_match('/#([A-Z]+-\d+)/', $title, $matches) ||
                      isset($attributes['ticketid'])) {

                $ticketId = $attributes['ticketid'] ?? $attributes['relid'];
                $enriched = array_merge($enriched, $this->getTicketData($ticketId));

            } elseif (preg_match('/orders.*id=(\d+)/', $url, $matches) ||
                      preg_match('/Order #(\d+)/', $title, $matches) ||
                      isset($attributes['orderid'])) {

                $orderId = $matches[1] ?? $attributes['orderid'] ?? $attributes['relid'];
                $enriched = array_merge($enriched, $this->getOrderData($orderId));
            }

        } catch (\Exception $e) {
            // Log error but continue with basic data
            if (function_exists('logActivity')) {
                logActivity("WHMCS Telegram Pro: Data extraction error - " . $e->getMessage());
            }
        }

        return $enriched;
    }

    /**
     * Get invoice data from database
     */
    private function getInvoiceData($invoiceId)
    {
        if (!$invoiceId) return ['type' => 'invoice'];

        try {
            $invoice = Capsule::table('tblinvoices')
                ->leftJoin('tblclients', 'tblinvoices.userid', '=', 'tblclients.id')
                ->leftJoin('tblcurrencies', 'tblclients.currency', '=', 'tblcurrencies.id')
                ->where('tblinvoices.id', $invoiceId)
                ->select([
                    'tblinvoices.*',
                    'tblclients.firstname', 'tblclients.lastname', 'tblclients.email',
                    'tblclients.companyname', 'tblclients.phonenumber', 'tblclients.country',
                    'tblcurrencies.code as currency_code', 'tblcurrencies.prefix', 'tblcurrencies.suffix'
                ])
                ->first();

            if ($invoice) {
                // Get invoice items
                $items = Capsule::table('tblinvoiceitems')
                    ->where('invoiceid', $invoiceId)
                    ->select(['description', 'amount'])
                    ->get();

                $itemsList = [];
                $itemsText = '';
                foreach ($items as $item) {
                    $itemAmount = $invoice->prefix . number_format($item->amount, 2) . $invoice->suffix;
                    $itemsList[] = $item->description . ' - ' . $itemAmount;
                }
                if (!empty($itemsList)) {
                    $itemsText = implode("\n   • ", $itemsList);
                }

                // Format currency display
                $currencyCode = $invoice->currency_code ?: 'USD';
                $prefix = $invoice->prefix ?: '$';
                $suffix = $invoice->suffix ?: '';

                $formatAmount = function($amount) use ($prefix, $suffix) {
                    return $prefix . number_format($amount, 2) . $suffix;
                };

                // Format dates
                $invoiceDate = $invoice->date ? date('M j, Y', strtotime($invoice->date)) : 'N/A';
                $dueDate = $invoice->duedate ? date('M j, Y', strtotime($invoice->duedate)) : 'N/A';
                $datePaid = $invoice->datepaid && $invoice->datepaid != '0000-00-00 00:00:00' ?
                    date('M j, Y g:i A', strtotime($invoice->datepaid)) : 'Not Paid';

                // Calculate days overdue
                $daysOverdue = '';
                if ($invoice->status == 'Unpaid' && $invoice->duedate) {
                    $due = strtotime($invoice->duedate);
                    $now = time();
                    if ($now > $due) {
                        $days = floor(($now - $due) / 86400);
                        $daysOverdue = $days . ' day' . ($days != 1 ? 's' : '') . ' overdue';
                    }
                }

                return [
                    'type' => 'invoice',
                    'client' => trim($invoice->firstname . ' ' . $invoice->lastname),
                    'email' => $invoice->email,
                    'company' => $invoice->companyname,
                    'phone' => $invoice->phonenumber,
                    'country' => $invoice->country,
                    'amount' => $formatAmount($invoice->total),
                    'currency' => $currencyCode,
                    'status' => ucfirst($invoice->status),
                    'due_date' => $dueDate,
                    'date_paid' => $datePaid,
                    'invoice_date' => $invoiceDate,
                    'payment_method' => $invoice->paymentmethod ?: 'None',
                    'subtotal' => $formatAmount($invoice->subtotal),
                    'credit' => $invoice->credit > 0 ? $formatAmount($invoice->credit) : null,
                    'tax' => $invoice->tax > 0 ? $formatAmount($invoice->tax) : null,
                    'tax2' => $invoice->tax2 > 0 ? $formatAmount($invoice->tax2) : null,
                    'tax_rate' => $invoice->taxrate > 0 ? $invoice->taxrate . '%' : null,
                    'tax_rate2' => $invoice->taxrate2 > 0 ? $invoice->taxrate2 . '%' : null,
                    'notes' => $invoice->notes ?: null,
                    'items_text' => $itemsText,
                    'items_count' => count($items),
                    'days_overdue' => $daysOverdue,
                    'details' => [
                        'Invoice' => '#' . $invoice->invoicenum,
                        'Client' => trim($invoice->firstname . ' ' . $invoice->lastname),
                        'Email' => $invoice->email,
                        'Company' => $invoice->companyname ?: 'N/A',
                        'Phone' => $invoice->phonenumber ?: 'N/A',
                        'Country' => $invoice->country ?: 'N/A',
                        'Invoice Date' => $invoiceDate,
                        'Due Date' => $dueDate,
                        'Date Paid' => $datePaid,
                        'Status' => ucfirst($invoice->status),
                        'Subtotal' => $formatAmount($invoice->subtotal),
                        'Tax' => $invoice->tax > 0 ? $formatAmount($invoice->tax) . ' (' . $invoice->taxrate . '%)' : 'N/A',
                        'Tax 2' => $invoice->tax2 > 0 ? $formatAmount($invoice->tax2) . ' (' . $invoice->taxrate2 . '%)' : 'N/A',
                        'Credit Applied' => $invoice->credit > 0 ? $formatAmount($invoice->credit) : 'N/A',
                        'Total' => $formatAmount($invoice->total),
                        'Currency' => $currencyCode,
                        'Payment Method' => $invoice->paymentmethod ?: 'None Selected',
                        'Items Count' => count($items),
                        'Days Overdue' => $daysOverdue ?: 'N/A',
                        'Notes' => $invoice->notes ?: 'None'
                    ]
                ];
            }
        } catch (\Exception $e) {
            // Continue with empty data
        }

        return ['type' => 'invoice'];
    }

    /**
     * Get ticket data from database
     */
    private function getTicketData($ticketId)
    {
        if (!$ticketId) return ['type' => 'ticket'];

        try {
            $ticket = Capsule::table('tbltickets')
                ->leftJoin('tblclients', 'tbltickets.userid', '=', 'tblclients.id')
                ->leftJoin('tblticketdepartments', 'tbltickets.did', '=', 'tblticketdepartments.id')
                ->where('tbltickets.id', $ticketId)
                ->select([
                    'tbltickets.*',
                    'tblclients.firstname', 'tblclients.lastname', 'tblclients.email',
                    'tblclients.companyname', 'tblclients.phonenumber', 'tblclients.country',
                    'tblticketdepartments.name as dept_name'
                ])
                ->first();

            if ($ticket) {
                // Get last reply info
                $lastReply = Capsule::table('tblticketreplies')
                    ->leftJoin('tbladmins', 'tblticketreplies.adminid', '=', 'tbladmins.id')
                    ->where('tblticketreplies.tid', $ticketId)
                    ->orderBy('tblticketreplies.date', 'desc')
                    ->select([
                        'tblticketreplies.date',
                        'tblticketreplies.adminid',
                        'tblticketreplies.message as reply_message',
                        'tbladmins.firstname as admin_firstname',
                        'tbladmins.lastname as admin_lastname'
                    ])
                    ->first();

                // Get first message (ticket opening message)
                $firstMessage = strip_tags($ticket->message);
                $firstMessage = html_entity_decode($firstMessage);
                $messagePreview = strlen($firstMessage) > 200 ? substr($firstMessage, 0, 200) . '...' : $firstMessage;

                // Get message count
                $messageCount = Capsule::table('tblticketreplies')
                    ->where('tid', $ticketId)
                    ->count();

                // Get client reply count
                $clientReplies = Capsule::table('tblticketreplies')
                    ->where('tid', $ticketId)
                    ->whereNull('adminid')
                    ->orWhere('adminid', '')
                    ->count();

                // Get staff reply count
                $staffReplies = $messageCount - $clientReplies;

                // Get related service if any
                $relatedService = null;
                if ($ticket->service) {
                    $service = Capsule::table('tblhosting')
                        ->leftJoin('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                        ->where('tblhosting.id', $ticket->service)
                        ->select(['tblproducts.name as product_name', 'tblhosting.domain'])
                        ->first();

                    if ($service) {
                        $relatedService = $service->product_name . ($service->domain ? ' (' . $service->domain . ')' : '');
                    }
                }

                // Format dates
                $createdDate = $ticket->date ? date('M j, Y g:i A', strtotime($ticket->date)) : null;
                $lastUpdateDate = $ticket->lastreply ? date('M j, Y g:i A', strtotime($ticket->lastreply)) : null;
                $lastReplyDate = $lastReply ? date('M j, Y g:i A', strtotime($lastReply->date)) : null;

                // Calculate time since last update
                $timeSinceUpdate = '';
                if ($ticket->lastreply) {
                    $lastReplyTime = strtotime($ticket->lastreply);
                    $now = time();
                    $diff = $now - $lastReplyTime;

                    if ($diff < 3600) {
                        $timeSinceUpdate = floor($diff / 60) . ' minutes ago';
                    } elseif ($diff < 86400) {
                        $hours = floor($diff / 3600);
                        $timeSinceUpdate = $hours . ' hour' . ($hours != 1 ? 's' : '') . ' ago';
                    } else {
                        $days = floor($diff / 86400);
                        $timeSinceUpdate = $days . ' day' . ($days != 1 ? 's' : '') . ' ago';
                    }
                }

                // Get CC emails if any
                $ccEmails = $ticket->cc ?: null;

                // Get ticket flags
                $flags = [];
                if ($ticket->flag) $flags[] = 'Flagged';
                if ($ticket->merged_ticket_id) $flags[] = 'Merged';
                $flagsText = !empty($flags) ? implode(', ', $flags) : null;

                // Build comprehensive ticket data
                return [
                    'type' => 'ticket',
                    'client' => trim($ticket->firstname . ' ' . $ticket->lastname),
                    'email' => $ticket->email,
                    'company' => $ticket->companyname,
                    'phone' => $ticket->phonenumber,
                    'country' => $ticket->country,
                    'status' => ucfirst($ticket->status),
                    'priority' => ucfirst($ticket->urgency),
                    'department' => $ticket->dept_name ?: 'General',
                    'subject' => $ticket->title,
                    'ticket_id' => $ticket->tid,
                    'ticket_mask' => $ticket->c ?: null,
                    'created_date' => $createdDate,
                    'last_update' => $lastUpdateDate,
                    'time_since_update' => $timeSinceUpdate,
                    'last_reply_by' => $lastReply && $lastReply->adminid ?
                        trim($lastReply->admin_firstname . ' ' . $lastReply->admin_lastname) : 'Client',
                    'message_count' => $messageCount,
                    'client_replies' => $clientReplies,
                    'staff_replies' => $staffReplies,
                    'assigned_to' => $ticket->admin ?: 'Unassigned',
                    'message_preview' => $messagePreview,
                    'related_service' => $relatedService,
                    'cc_emails' => $ccEmails,
                    'flags' => $flagsText,
                    'ip_address' => $ticket->ipaddress ?: null,
                    'details' => [
                        'Ticket' => '#' . $ticket->tid . ($ticket->c ? ' (' . $ticket->c . ')' : ''),
                        'Subject' => $ticket->title,
                        'Client' => trim($ticket->firstname . ' ' . $ticket->lastname),
                        'Email' => $ticket->email,
                        'Company' => $ticket->companyname ?: 'N/A',
                        'Phone' => $ticket->phonenumber ?: 'N/A',
                        'Country' => $ticket->country ?: 'N/A',
                        'Status' => ucfirst($ticket->status),
                        'Priority' => ucfirst($ticket->urgency),
                        'Department' => $ticket->dept_name ?: 'General',
                        'Assigned To' => $ticket->admin ?: 'Unassigned',
                        'Created' => $createdDate ?: 'N/A',
                        'Last Update' => $lastUpdateDate ?: 'N/A',
                        'Time Since Update' => $timeSinceUpdate ?: 'Just now',
                        'Last Reply By' => $lastReply && $lastReply->adminid ?
                            trim($lastReply->admin_firstname . ' ' . $lastReply->admin_lastname) : 'Client',
                        'Total Messages' => $messageCount,
                        'Client Replies' => $clientReplies,
                        'Staff Replies' => $staffReplies,
                        'Related Service' => $relatedService ?: 'None',
                        'CC Emails' => $ccEmails ?: 'None',
                        'Flags' => $flagsText ?: 'None',
                        'IP Address' => $ticket->ipaddress ?: 'N/A',
                        'Message Preview' => $messagePreview
                    ]
                ];
            }
        } catch (\Exception $e) {
            // Continue with empty data
        }

        return ['type' => 'ticket'];
    }

    /**
     * Get order data from database
     */
    private function getOrderData($orderId)
    {
        if (!$orderId) return ['type' => 'order'];

        try {
            $order = Capsule::table('tblorders')
                ->leftJoin('tblclients', 'tblorders.userid', '=', 'tblclients.id')
                ->leftJoin('tblcurrencies', 'tblclients.currency', '=', 'tblcurrencies.id')
                ->where('tblorders.id', $orderId)
                ->select([
                    'tblorders.*',
                    'tblclients.firstname', 'tblclients.lastname', 'tblclients.email',
                    'tblclients.companyname', 'tblclients.phonenumber', 'tblclients.country',
                    'tblcurrencies.code as currency_code', 'tblcurrencies.prefix', 'tblcurrencies.suffix'
                ])
                ->first();

            if ($order) {
                // Format currency display
                $currencyCode = $order->currency_code ?: 'USD';
                $prefix = $order->prefix ?: '$';
                $suffix = $order->suffix ?: '';

                $formatAmount = function($amount) use ($prefix, $suffix) {
                    return $prefix . number_format($amount, 2) . $suffix;
                };

                // Get all order items (products, addons, domains, etc.)
                $orderItems = Capsule::table('tblorderitems')
                    ->leftJoin('tblproducts', 'tblorderitems.relid', '=', 'tblproducts.id')
                    ->where('tblorderitems.orderid', $orderId)
                    ->select([
                        'tblorderitems.type', 'tblorderitems.domain', 'tblorderitems.billingcycle',
                        'tblproducts.name as product_name', 'tblorderitems.regperiod'
                    ])
                    ->get();

                // Build items list
                $itemsList = [];
                $itemsText = '';
                $firstProduct = null;
                $firstDomain = null;

                foreach ($orderItems as $item) {
                    $itemText = '';
                    if ($item->type == 'hostingaccount' || $item->type == 'server' || $item->type == 'other') {
                        $itemText = ($item->product_name ?: 'Product') .
                                   ($item->domain ? ' (' . $item->domain . ')' : '') .
                                   ($item->billingcycle ? ' - ' . ucfirst($item->billingcycle) : '');
                        if (!$firstProduct) $firstProduct = $item->product_name;
                        if (!$firstDomain && $item->domain) $firstDomain = $item->domain;
                    } elseif ($item->type == 'domain') {
                        $itemText = 'Domain: ' . $item->domain .
                                   ($item->regperiod ? ' (' . $item->regperiod . ' year' . ($item->regperiod != 1 ? 's' : '') . ')' : '');
                        if (!$firstDomain) $firstDomain = $item->domain;
                    } elseif ($item->type == 'domaintransfer') {
                        $itemText = 'Domain Transfer: ' . $item->domain;
                    } elseif ($item->type == 'domainrenew') {
                        $itemText = 'Domain Renewal: ' . $item->domain;
                    } elseif ($item->type == 'addon') {
                        $itemText = 'Addon: ' . ($item->product_name ?: 'Unknown');
                    }

                    if ($itemText) {
                        $itemsList[] = $itemText;
                    }
                }

                if (!empty($itemsList)) {
                    $itemsText = implode("\n   • ", $itemsList);
                }

                // Get associated invoice
                $invoice = Capsule::table('tblinvoiceitems')
                    ->leftJoin('tblinvoices', 'tblinvoiceitems.invoiceid', '=', 'tblinvoices.id')
                    ->where('tblinvoiceitems.relid', $orderId)
                    ->where('tblinvoiceitems.type', 'like', '%order%')
                    ->select(['tblinvoices.id as invoice_id', 'tblinvoices.invoicenum', 'tblinvoices.status as invoice_status'])
                    ->first();

                // Format dates
                $orderDate = $order->date ? date('M j, Y g:i A', strtotime($order->date)) : 'N/A';

                // Get promo code details
                $promoText = null;
                if ($order->promocode) {
                    $promoText = $order->promocode;
                    if ($order->promotype && $order->promovalue) {
                        if ($order->promotype == 'Percentage') {
                            $promoText .= ' (' . $order->promovalue . '% off)';
                        } else {
                            $promoText .= ' (' . $formatAmount($order->promovalue) . ' off)';
                        }
                    }
                }

                // Parse nameservers if present
                $nameservers = null;
                if ($order->nameservers) {
                    $ns = json_decode($order->nameservers, true);
                    if (is_array($ns) && !empty($ns)) {
                        $nameservers = implode(', ', array_filter($ns));
                    }
                }

                // Get IP address
                $ipAddress = $order->ipaddress ?: null;

                // Get fraud check status
                $fraudStatus = null;
                if ($order->fraudmodule && $order->fraudoutput) {
                    $fraudStatus = $order->fraudmodule . ': ' . $order->fraudoutput;
                }

                return [
                    'type' => 'order',
                    'client' => trim($order->firstname . ' ' . $order->lastname),
                    'email' => $order->email,
                    'company' => $order->companyname,
                    'phone' => $order->phonenumber,
                    'country' => $order->country,
                    'amount' => $formatAmount($order->amount),
                    'currency' => $currencyCode,
                    'status' => ucfirst($order->status),
                    'product' => $firstProduct,
                    'domain' => $firstDomain,
                    'payment_method' => $order->paymentmethod ?: 'None',
                    'order_date' => $orderDate,
                    'items_text' => $itemsText,
                    'items_count' => count($itemsList),
                    'invoice_id' => $invoice->invoicenum ?? null,
                    'invoice_status' => $invoice ? ucfirst($invoice->invoice_status) : null,
                    'promo_code' => $promoText,
                    'nameservers' => $nameservers,
                    'ip_address' => $ipAddress,
                    'fraud_status' => $fraudStatus,
                    'transfer_secret' => $order->transfersecret ?: null,
                    'details' => [
                        'Order' => '#' . $order->ordernum,
                        'Order Date' => $orderDate,
                        'Client' => trim($order->firstname . ' ' . $order->lastname),
                        'Email' => $order->email,
                        'Company' => $order->companyname ?: 'N/A',
                        'Phone' => $order->phonenumber ?: 'N/A',
                        'Country' => $order->country ?: 'N/A',
                        'Status' => ucfirst($order->status),
                        'Amount' => $formatAmount($order->amount),
                        'Currency' => $currencyCode,
                        'Payment Method' => $order->paymentmethod ?: 'None Selected',
                        'Items Count' => count($itemsList),
                        'Invoice' => $invoice ? '#' . $invoice->invoicenum . ' (' . ucfirst($invoice->invoice_status) . ')' : 'Not Generated',
                        'Promo Code' => $promoText ?: 'None',
                        'Nameservers' => $nameservers ?: 'Default',
                        'IP Address' => $ipAddress ?: 'N/A',
                        'Fraud Check' => $fraudStatus ?: 'None',
                        'Transfer Secret' => $order->transfersecret ?: 'N/A',
                        'Notes' => $order->notes ?: 'None'
                    ]
                ];
            }
        } catch (\Exception $e) {
            // Continue with empty data
        }

        return ['type' => 'order'];
    }

    /**
     * Generate professional message format
     */
    private function generateProfessionalMessage($notification, $enrichedData, $settings)
    {
        $format = $settings['use_professional_format'] ?? 'professional';
        $includeUrl = ($settings['include_url'] ?? 'yes') === 'yes';

        if ($format === 'custom' && !empty($settings['message_template'])) {
            return $this->processCustomTemplate($settings['message_template'], $notification, $enrichedData);
        }

        $message = '';
        $icon = $this->getTypeIcon($enrichedData['type']);

        switch ($format) {
            case 'professional':
                $message = $this->generateProfessionalFormat($notification, $enrichedData, $icon, $includeUrl);
                break;

            case 'detailed':
                $message = $this->generateDetailedFormat($notification, $enrichedData, $icon, $includeUrl);
                break;

            case 'compact':
                $message = $this->generateCompactFormat($notification, $enrichedData, $icon, $includeUrl);
                break;

            default:
                $message = $this->generateProfessionalFormat($notification, $enrichedData, $icon, $includeUrl);
        }

        return $message;
    }

    /**
     * Professional format - clean and structured with ALL details
     */
    private function generateProfessionalFormat($notification, $data, $icon, $includeUrl)
    {
        $message = "{$icon} <b>" . htmlspecialchars($notification->getTitle()) . "</b>\n\n";

        if (!empty($notification->getMessage())) {
            $cleanMessage = strip_tags($notification->getMessage());
            $cleanMessage = html_entity_decode($cleanMessage);
            $message .= htmlspecialchars($cleanMessage) . "\n\n";
        }

        // Enhanced client information
        if (!empty($data['client'])) {
            $message .= "👤 <b>Client:</b> " . htmlspecialchars($data['client']);
            if (!empty($data['email'])) {
                $message .= " (" . htmlspecialchars($data['email']) . ")";
            }
            $message .= "\n";
        }

        // Company and phone if available
        if (!empty($data['company'])) {
            $message .= "🏭 <b>Company:</b> " . htmlspecialchars($data['company']) . "\n";
        }
        if (!empty($data['phone'])) {
            $message .= "📞 <b>Phone:</b> " . htmlspecialchars($data['phone']) . "\n";
        }
        if (!empty($data['country'])) {
            $message .= "🌍 <b>Country:</b> " . htmlspecialchars($data['country']) . "\n";
        }

        // Enhanced amount with currency
        if (!empty($data['amount'])) {
            $message .= "💰 <b>Amount:</b> " . htmlspecialchars($data['amount']);
            if (!empty($data['currency'])) {
                $message .= " " . htmlspecialchars($data['currency']);
            }
            $message .= "\n";
        }

        // Enhanced status with context
        if (!empty($data['status'])) {
            $statusIcon = $this->getStatusIcon($data['status']);
            $message .= "{$statusIcon} <b>Status:</b> " . htmlspecialchars($data['status']) . "\n";
        }

        // === INVOICE-SPECIFIC INFORMATION ===
        if ($data['type'] === 'invoice') {
            if (!empty($data['invoice_date'])) {
                $message .= "📅 <b>Invoice Date:</b> " . htmlspecialchars($data['invoice_date']) . "\n";
            }
            if (!empty($data['due_date'])) {
                $message .= "⏰ <b>Due Date:</b> " . htmlspecialchars($data['due_date']) . "\n";
            }
            if (!empty($data['date_paid']) && $data['date_paid'] != 'Not Paid') {
                $message .= "✅ <b>Paid:</b> " . htmlspecialchars($data['date_paid']) . "\n";
            }

            // URGENT: Days overdue warning
            if (!empty($data['days_overdue'])) {
                $message .= "⚠️ <b>OVERDUE:</b> " . htmlspecialchars($data['days_overdue']) . "\n";
            }

            // Payment details
            if (!empty($data['payment_method']) && $data['payment_method'] != 'None') {
                $message .= "💳 <b>Payment Method:</b> " . htmlspecialchars($data['payment_method']) . "\n";
            }

            // Show tax if present
            if (!empty($data['subtotal'])) {
                $message .= "💵 <b>Subtotal:</b> " . htmlspecialchars($data['subtotal']) . "\n";
            }
            if (!empty($data['tax'])) {
                $message .= "🧾 <b>Tax:</b> " . htmlspecialchars($data['tax']) .
                           (!empty($data['tax_rate']) ? " (" . htmlspecialchars($data['tax_rate']) . ")" : "") . "\n";
            }
            if (!empty($data['credit'])) {
                $message .= "💰 <b>Credit Applied:</b> " . htmlspecialchars($data['credit']) . "\n";
            }

            // Invoice items list
            if (!empty($data['items_text']) && $data['items_count'] > 0) {
                $message .= "\n📦 <b>Items (" . $data['items_count'] . "):</b>\n   • " . htmlspecialchars($data['items_text']) . "\n";
            }
        }

        // === TICKET-SPECIFIC INFORMATION ===
        if ($data['type'] === 'ticket') {
            if (!empty($data['subject'])) {
                $message .= "📋 <b>Subject:</b> " . htmlspecialchars($data['subject']) . "\n";
            }
            if (!empty($data['priority'])) {
                $priorityIcon = $this->getPriorityIcon($data['priority']);
                $message .= "{$priorityIcon} <b>Priority:</b> " . htmlspecialchars($data['priority']) . "\n";
            }
            if (!empty($data['department'])) {
                $message .= "🏢 <b>Department:</b> " . htmlspecialchars($data['department']) . "\n";
            }
            if (!empty($data['assigned_to'])) {
                $message .= "👨‍💼 <b>Assigned To:</b> " . htmlspecialchars($data['assigned_to']) . "\n";
            }
            if (!empty($data['message_count'])) {
                $message .= "💬 <b>Total Messages:</b> " . htmlspecialchars($data['message_count']) .
                           " (" . ($data['client_replies'] ?? 0) . " client, " . ($data['staff_replies'] ?? 0) . " staff)\n";
            }
            if (!empty($data['last_reply_by'])) {
                $message .= "✍️ <b>Last Reply By:</b> " . htmlspecialchars($data['last_reply_by']) . "\n";
            }
            if (!empty($data['time_since_update'])) {
                $message .= "⏱️ <b>Last Update:</b> " . htmlspecialchars($data['time_since_update']) . "\n";
            }
            if (!empty($data['created_date'])) {
                $message .= "📅 <b>Created:</b> " . htmlspecialchars($data['created_date']) . "\n";
            }
            if (!empty($data['related_service'])) {
                $message .= "🔗 <b>Related Service:</b> " . htmlspecialchars($data['related_service']) . "\n";
            }

            // Message preview
            if (!empty($data['message_preview'])) {
                $message .= "\n💬 <b>Message:</b>\n<i>" . htmlspecialchars($data['message_preview']) . "</i>\n";
            }
        }

        // === ORDER-SPECIFIC INFORMATION ===
        if ($data['type'] === 'order') {
            if (!empty($data['order_date'])) {
                $message .= "📅 <b>Order Date:</b> " . htmlspecialchars($data['order_date']) . "\n";
            }
            if (!empty($data['payment_method']) && $data['payment_method'] != 'None') {
                $message .= "💳 <b>Payment Method:</b> " . htmlspecialchars($data['payment_method']) . "\n";
            }

            // Associated invoice
            if (!empty($data['invoice_id'])) {
                $message .= "🧾 <b>Invoice:</b> #" . htmlspecialchars($data['invoice_id']);
                if (!empty($data['invoice_status'])) {
                    $message .= " (" . htmlspecialchars($data['invoice_status']) . ")";
                }
                $message .= "\n";
            }

            // Promo code if used
            if (!empty($data['promo_code'])) {
                $message .= "🎁 <b>Promo Code:</b> " . htmlspecialchars($data['promo_code']) . "\n";
            }

            // Order items list
            if (!empty($data['items_text']) && $data['items_count'] > 0) {
                $message .= "\n📦 <b>Order Items (" . $data['items_count'] . "):</b>\n   • " . htmlspecialchars($data['items_text']) . "\n";
            }

            // Nameservers if custom
            if (!empty($data['nameservers'])) {
                $message .= "🖥️ <b>Nameservers:</b> " . htmlspecialchars($data['nameservers']) . "\n";
            }
        }

        // Add domain for hosting (orders/services)
        if (!empty($data['domain']) && !isset($data['items_text'])) {
            $message .= "🌐 <b>Domain:</b> " . htmlspecialchars($data['domain']) . "\n";
        }

        // Add product if single product (not already in items list)
        if (!empty($data['product']) && empty($data['items_text'])) {
            $message .= "📦 <b>Product:</b> " . htmlspecialchars($data['product']) . "\n";
        }

        if ($includeUrl && !empty($notification->getUrl())) {
            $actionText = $this->getActionText($data['type'] ?? 'unknown');
            $message .= "\n🔗 <a href=\"" . htmlspecialchars($notification->getUrl()) . "\">{$actionText}</a>\n";
        }

        $message .= "\n📅 " . date('M j, Y • g:i A');

        return $message;
    }

    /**
     * Detailed format - all available information
     */
    private function generateDetailedFormat($notification, $data, $icon, $includeUrl)
    {
        $message = "{$icon} <b>" . htmlspecialchars($notification->getTitle()) . "</b>\n\n";

        if (!empty($notification->getMessage())) {
            $message .= htmlspecialchars($notification->getMessage()) . "\n\n";
        }

        if (!empty($data['details'])) {
            foreach ($data['details'] as $label => $value) {
                if (!empty($value) && $value !== 'N/A') {
                    $message .= "<b>{$label}:</b> " . htmlspecialchars($value) . "\n";
                }
            }
        }

        if ($includeUrl && !empty($notification->getUrl())) {
            $message .= "\n🔗 <a href=\"" . htmlspecialchars($notification->getUrl()) . "\">View in WHMCS</a>\n";
        }

        $message .= "\n📅 " . date('F j, Y • g:i:s A');

        return $message;
    }

    /**
     * Compact format - minimal information
     */
    private function generateCompactFormat($notification, $data, $icon, $includeUrl)
    {
        $message = "{$icon} " . htmlspecialchars($notification->getTitle());

        if (!empty($data['client'])) {
            $message .= " • " . htmlspecialchars($data['client']);
        }

        if (!empty($data['amount'])) {
            $message .= " • " . htmlspecialchars($data['amount']);
        }

        if ($includeUrl && !empty($notification->getUrl())) {
            $message .= "\n🔗 <a href=\"" . htmlspecialchars($notification->getUrl()) . "\">View</a>";
        }

        return $message;
    }

    /**
     * Get appropriate icon for notification type
     */
    private function getTypeIcon($type)
    {
        $icons = [
            'invoice' => '📄',
            'ticket' => '🎫',
            'order' => '🛒',
            'payment' => '💳',
            'unknown' => '📢'
        ];

        return $icons[$type] ?? '📢';
    }

    /**
     * Send message with retry logic
     */
    private function sendWithRetry($botToken, $chatId, $message, $moduleSettings, $notificationSettings)
    {
        $maxRetries = self::MAX_RETRIES;
        $lastError = '';

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $result = $this->sendTelegramMessage(
                $botToken,
                $chatId,
                $message,
                $moduleSettings['parse_mode'] ?? 'HTML',
                false,
                $moduleSettings,
                ($notificationSettings['silent_notification'] ?? 'no') === 'yes'
            );

            if ($result['success']) {
                return $result;
            }

            $lastError = $result['error'];
            if ($attempt < $maxRetries) {
                sleep(1); // Brief pause between retries
            }
        }

        return ['success' => false, 'error' => $lastError];
    }

    /**
     * Send message to Telegram
     */
    private function sendTelegramMessage($botToken, $chatId, $message, $parseMode = 'HTML', $disableWebPreview = false, $settings = [], $silent = false)
    {
        try {
            $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

            $data = [
                'chat_id' => $chatId,
                'text' => $message,
                'disable_web_page_preview' => $disableWebPreview,
                'disable_notification' => $silent
            ];

            if ($parseMode && $parseMode !== 'None') {
                $data['parse_mode'] = $parseMode;
            }

            $options = [
                'http' => [
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method' => 'POST',
                    'content' => http_build_query($data),
                    'timeout' => self::DEFAULT_TIMEOUT,
                    'ignore_errors' => true
                ]
            ];

            $context = stream_context_create($options);
            $result = @file_get_contents($url, false, $context);

            if ($result === false) {
                return ['success' => false, 'error' => 'Network error: Could not connect to Telegram API'];
            }

            $response = json_decode($result, true);

            if (!$response || !isset($response['ok'])) {
                return ['success' => false, 'error' => 'Invalid response from Telegram API'];
            }

            if (!$response['ok']) {
                return ['success' => false, 'error' => $response['description'] ?? 'Unknown Telegram API error'];
            }

            return ['success' => true, 'message_id' => $response['result']['message_id']];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Exception: ' . $e->getMessage()];
        }
    }

    /**
     * Get chat ID with fallback
     */
    private function getChatId($notificationSettings, $moduleSettings)
    {
        $chatId = $notificationSettings['chat_id'] ?? '';
        if (empty($chatId)) {
            $chatId = $moduleSettings['default_chat_id'] ?? '';
        }
        return trim($chatId);
    }

    /**
     * Get status icon based on status text
     */
    private function getStatusIcon($status)
    {
        $status = strtolower($status);
        if (in_array($status, ['paid', 'active', 'completed', 'accepted'])) {
            return '✅';
        } elseif (in_array($status, ['pending', 'processing', 'awaiting'])) {
            return '⏳';
        } elseif (in_array($status, ['overdue', 'failed', 'cancelled', 'rejected'])) {
            return '❌';
        } elseif (in_array($status, ['suspended', 'terminated'])) {
            return '⚠️';
        }
        return '📊';
    }

    /**
     * Get priority icon based on priority level
     */
    private function getPriorityIcon($priority)
    {
        $priority = strtolower($priority);
        if (in_array($priority, ['high', 'urgent', 'critical'])) {
            return '🔴';
        } elseif (in_array($priority, ['medium', 'normal'])) {
            return '🟡';
        } elseif (in_array($priority, ['low'])) {
            return '🟢';
        }
        return '📋';
    }

    /**
     * Get action text based on notification type
     */
    private function getActionText($type)
    {
        switch (strtolower($type)) {
            case 'invoice':
                return 'View Invoice';
            case 'order':
                return 'View Order';
            case 'ticket':
            case 'support':
                return 'View Ticket';
            case 'client':
                return 'View Client';
            default:
                return 'View Details';
        }
    }

    /**
     * Debug logging
     */
    private function logDebug($message, $data = [], $settings = [])
    {
        if (($settings['enable_debug'] ?? 'no') === 'yes') {
            if (function_exists('logActivity')) {
                logActivity("WHMCS Telegram Pro v" . self::VERSION . ": {$message} - " . json_encode($data));
            }
        }
    }

    /**
     * Get dynamic field (required by interface)
     */
    public function getDynamicField($fieldName, $settings)
    {
        return [];
    }
}