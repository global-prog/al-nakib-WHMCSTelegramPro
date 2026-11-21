<?php
/**
 * WHMCS Telegram Pro - Professional Notification System
 * Complete WHMCS notification solution with comprehensive data extraction
 *
 * @author RAQEEB AL-NAQEEB - AL-NAKIB LTD DEV TEAM
 * @version 4.2.2
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

    const VERSION = '4.2.2';
    const CONNECT_TIMEOUT = 5;
    const DEFAULT_TIMEOUT = 10;
    const MAX_RETRIES = 2;
    const RETRY_BUDGET = 15; // seconds; no new attempt starts after this
    // Telegram allows 4096 characters after entity parsing; raw HTML is always longer, so this is safe
    const MAX_MESSAGE_LENGTH = 4000;
    const DEDUP_TABLE = 'mod_whmcstelegrampro_sent';
    const LOG_MAX_BYTES = 5242880; // 5 MB cap for logs/error_log.php
    const LOG_MAX_ENTRY_BYTES = 65536;

    // First line of the log file: stops the log being served if requested over the web
    const LOG_HEADER = "<?php http_response_code(404); exit; ?>\n";

    /** @var int Log size cap in bytes (property so it can be lowered in tests) */
    private $logMaxBytes = self::LOG_MAX_BYTES;

    /** @var bool Debug logging flag for the current request */
    private $debug = false;

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
            'bot_token' => [
                'FriendlyName' => 'Bot Token',
                'Type' => 'password',
                'Description' => 'Your Telegram Bot Token from @BotFather. '
                    . 'WHMCS Telegram Pro v' . self::VERSION . ' - '
                    . '<a href="../modules/notifications/WHMCSTelegramPro/admin_panel.php" target="_blank" rel="noopener">'
                    . 'Open the diagnostics panel</a> to test delivery from this server and read the plugin log.',
            ],
            'default_chat_id' => [
                'FriendlyName' => 'Default Chat ID',
                'Type' => 'text',
                'Description' => 'Default chat/channel ID (e.g., -1001234567890)',
            ],
            'enable_debug' => [
                'FriendlyName' => 'Enable Debug Logging',
                'Type' => 'yesno',
                'Description' => 'Write detailed notification and data lookup logs to logs/error_log.php in the module folder (capped at 5 MB)',
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
                'Description' => 'Block identical notifications to the same chat within this window',
            ],
        ];
    }

    /**
     * Test connection
     */
    public function testConnection($settings)
    {
        $botToken = trim((string) ($settings['bot_token'] ?? ''));
        $chatId = trim((string) ($settings['default_chat_id'] ?? ''));

        $problem = $this->validateCredentials($botToken, $chatId);
        if ($problem !== '') {
            throw new \Exception($problem);
        }

        $response = $this->apiRequest($botToken, 'getMe', []);
        if (empty($response['ok'])) {
            $reason = $response['description'] ?? 'Unknown error';
            throw new \Exception((int) ($response['error_code'] ?? 0) === 0
                ? 'Could not reach Telegram API: ' . $reason
                : 'Invalid bot token: ' . $reason);
        }

        if ($chatId !== '') {
            $testMessage = "<b>WHMCS Telegram Pro v" . self::VERSION . " - Test Connection</b>\n\n"
                . "Configuration verified successfully.\n"
                . "By RAQEEB AL-NAQEEB - AL-NAKIB LTD\n\n"
                . date('Y-m-d H:i:s');

            $result = $this->sendTelegramMessage($botToken, $chatId, $testMessage, false);
            if (!$result['success']) {
                throw new \Exception('Bot is valid but sending to the chat failed: ' . $result['error']);
            }
        }

        return [
            'success' => true,
            'message' => 'Bot: @' . ($response['result']['username'] ?? 'unknown') . ($chatId !== '' ? ' | Test message sent' : ''),
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
                'Description' => 'Variables: {title}, {message}, {client}, {amount}, {status}, {type}, {url}, {date}, plus any enriched field (e.g. {due_date}, {priority}). Telegram HTML tags allowed (b, i, u, a, code); values are escaped.',
                'Rows' => '4',
            ],
            'include_url' => [
                'FriendlyName' => 'Include Direct Link',
                'Type' => 'yesno',
                'Description' => 'Add direct link to WHMCS item',
                'Default' => 'on',
            ],
            'silent_notification' => [
                'FriendlyName' => 'Silent Notification',
                'Type' => 'yesno',
                'Description' => 'Send without sound',
            ],
        ];
    }

    /**
     * Send notification. Failures are logged (plugin log file + short Activity Log line) instead of being thrown.
     */
    public function sendNotification(
        NotificationInterface $notification,
        $moduleSettings,
        $notificationSettings
    ) {
        $this->debug = $this->isEnabled($moduleSettings['enable_debug'] ?? '');
        $claimId = null;
        $sent = false;

        try {
            $botToken = trim((string) ($moduleSettings['bot_token'] ?? ''));
            $chatId = $this->getChatId($notificationSettings, $moduleSettings);

            $problem = $this->validateCredentials($botToken, $chatId);
            if ($problem !== '') {
                throw new \Exception($problem);
            }
            if ($chatId === '') {
                throw new \Exception('No chat ID configured: set a Default Chat ID or a Chat ID on the notification rule');
            }

            $entity = $this->detectEntity($notification);

            // Enrichment runs before the duplicate check because the hash uses the ticket's latest
            // reply ID: two different replies to one ticket can carry identical WHMCS text
            $enrichedData = $this->extractAndEnrichData($notification, $entity);

            // Chat ID is part of the hash so two rules sending the same event to different chats don't block each other
            $notificationHash = $this->generateNotificationHash($notification, $entity, $chatId, $enrichedData);
            $claim = $this->claimNotification($notificationHash, $moduleSettings);
            if ($claim['duplicate']) {
                $this->logDebug('Duplicate blocked', ['hash' => $notificationHash]);
                return;
            }
            $claimId = $claim['id'];

            $this->logDebug('Processing', $this->debugSummary($enrichedData));

            $message = $this->generateProfessionalMessage($notification, $enrichedData, $notificationSettings);

            $result = $this->sendWithRetry(
                $botToken,
                $chatId,
                $message,
                $this->isEnabled($notificationSettings['silent_notification'] ?? '')
            );

            if (!$result['success']) {
                throw new \Exception($result['error']);
            }

            $sent = true;
            $this->logDebug('Sent', ['message_id' => $result['message_id'], 'hash' => $notificationHash]);

        } catch (\Throwable $e) {
            // Never rethrow: some WHMCS versions don't catch provider exceptions, so a Telegram
            // outage would abort the admin/client action that triggered the notification.
            // The handling itself is guarded too: logging can fail (e.g. a dropped DB connection).
            try {
                if (!$sent) {
                    // Release the claim so the next occurrence of this event can still be delivered
                    $this->releaseClaim($claimId);
                }
                $this->logError('Notification not sent: ' . $e->getMessage());
            } catch (\Throwable $ignored) {
                // Nothing else can be done safely here
            }
        }
    }

    /**
     * Path of the plugin log (also read by admin_panel.php)
     */
    public static function logFile()
    {
        return __DIR__ . '/logs/error_log.php';
    }

    /**
     * Get dynamic field (required by interface; this module has no dynamic fields)
     */
    public function getDynamicField($fieldName, $settings)
    {
        return ['values' => []];
    }

    // ------------------------------------------------------------------
    // Entity detection
    // ------------------------------------------------------------------

    /**
     * Work out which WHMCS record the notification is about.
     * Checks the notification URL, then attribute URLs, then the title.
     *
     * @return array ['type' => string, 'id' => int|null, 'lookup' => string|null]
     */
    private function detectEntity($notification)
    {
        $urls = [(string) $notification->getUrl()];
        foreach ($this->getAttributeList($notification) as $attribute) {
            if ($attribute['url'] !== '') {
                $urls[] = $attribute['url'];
            }
        }

        $patterns = [
            'invoice' => '/invoices\.php\?(?:.*&)?id=(\d+)/',
            'ticket' => '/supporttickets\.php\?(?:.*&)?id=(\d+)/',
            'order' => '/orders\.php\?(?:.*&)?id=(\d+)/',
            'service' => '/clientsservices\.php\?(?:.*&)?id=(\d+)/',
            'domain' => '/clientsdomains\.php\?(?:.*&)?id=(\d+)/',
            'client' => '/clientssummary\.php\?(?:.*&)?userid=(\d+)/',
        ];

        foreach ($urls as $url) {
            foreach ($patterns as $type => $pattern) {
                if (preg_match($pattern, $url, $matches)) {
                    return ['type' => $type, 'id' => (int) $matches[1], 'lookup' => null];
                }
            }
        }

        // Title fallbacks: these carry a display number (invoice number, order number, ticket mask), not a row ID
        $title = (string) $notification->getTitle();
        if (preg_match('/Invoice #(\S+)/i', $title, $matches)) {
            return ['type' => 'invoice', 'id' => null, 'lookup' => rtrim($matches[1], '.,:')];
        }
        if (preg_match('/Order #(\S+)/i', $title, $matches)) {
            return ['type' => 'order', 'id' => null, 'lookup' => rtrim($matches[1], '.,:')];
        }
        if (preg_match('/Ticket #([A-Za-z0-9-]+)/i', $title, $matches)) {
            return ['type' => 'ticket', 'id' => null, 'lookup' => $matches[1]];
        }

        return ['type' => 'unknown', 'id' => null, 'lookup' => null];
    }

    /**
     * Normalise notification attributes (NotificationAttributeInterface objects) into plain arrays
     */
    private function getAttributeList($notification)
    {
        $list = [];
        $attributes = $notification->getAttributes();
        if (!is_iterable($attributes)) {
            return $list;
        }

        foreach ($attributes as $attribute) {
            if (!is_object($attribute) || !method_exists($attribute, 'getLabel')) {
                continue;
            }
            // Attribute values are plain text (e.g. "Name <email>"), so decode entities but keep angle brackets
            $label = trim(html_entity_decode((string) $attribute->getLabel(), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $value = trim(html_entity_decode((string) (method_exists($attribute, 'getValue') ? $attribute->getValue() : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($label === '' || $value === '') {
                continue;
            }
            $list[] = [
                'label' => $label,
                'value' => $value,
                'url' => method_exists($attribute, 'getUrl') ? (string) $attribute->getUrl() : '',
            ];
        }

        return $list;
    }

    // ------------------------------------------------------------------
    // Duplicate prevention
    // ------------------------------------------------------------------

    /**
     * Generate unique hash for notification deduplication. For tickets the latest reply ID is included,
     * so a new reply is never mistaken for a repeat of the previous one, while a repeated copy of the
     * same event (same database state) still produces the same hash.
     */
    private function generateNotificationHash($notification, $entity, $chatId, array $data = [])
    {
        $parts = [
            'chat_id' => $chatId,
            'entity' => $entity['type'] . ':' . ($entity['id'] ?? $entity['lookup'] ?? ''),
            'title' => (string) $notification->getTitle(),
            'message' => substr((string) $notification->getMessage(), 0, 100),
        ];
        if (($data['type'] ?? '') === 'ticket' && !empty($data['last_reply_id'])) {
            $parts['reply'] = (int) $data['last_reply_id'];
        }
        return md5(serialize($parts));
    }

    /**
     * Claim a notification hash before sending, so each event reaches a chat once per window.
     *
     * The claim row is inserted first and then checked against earlier rows: if two requests handle
     * the same event at the same moment, both insert, and the one with the higher ID sees the other's
     * row and backs off. A plain "check, then insert" would let both through.
     *
     * @return array ['duplicate' => bool, 'id' => int|null] id is the claim row, released if sending fails
     */
    private function claimNotification($hash, $settings)
    {
        $windows = [
            'strict' => 10,
            'medium' => 5,
            'light' => 2,
        ];

        $prevention = $settings['duplicate_prevention'] ?? 'medium';
        if ($prevention === 'off') {
            return ['duplicate' => false, 'id' => null];
        }
        $since = date('Y-m-d H:i:s', time() - ($windows[$prevention] ?? 5) * 60);
        $id = null;

        try {
            if (!$this->ensureDedupTable()) {
                return ['duplicate' => false, 'id' => null];
            }

            // Cheap path: an earlier copy is already recorded
            $exists = Capsule::table(self::DEDUP_TABLE)
                ->where('hash', $hash)
                ->where('created_at', '>=', $since)
                ->exists();
            if ($exists) {
                return ['duplicate' => true, 'id' => null];
            }

            $id = (int) Capsule::table(self::DEDUP_TABLE)->insertGetId([
                'hash' => $hash,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Another request may have passed the check above at the same moment
            $earlier = Capsule::table(self::DEDUP_TABLE)
                ->where('hash', $hash)
                ->where('id', '<', $id)
                ->where('created_at', '>=', $since)
                ->exists();
            if ($earlier) {
                $this->releaseClaim($id);
                return ['duplicate' => true, 'id' => null];
            }

            // Housekeeping only: a failure here must not cost the claim made above
            try {
                Capsule::table(self::DEDUP_TABLE)
                    ->where('created_at', '<', date('Y-m-d H:i:s', time() - 86400))
                    ->delete();
            } catch (\Throwable $e) {
                $this->logDebug('Duplicate table cleanup failed', ['error' => $e->getMessage()]);
            }

            return ['duplicate' => false, 'id' => $id];
        } catch (\Throwable $e) {
            // If the check can't run, send rather than silently drop the notification. A row inserted
            // before the failure is returned so it is released if sending fails, instead of blocking
            // the next delivery of this event for the whole window.
            $this->logDebug('Duplicate check failed', ['error' => $e->getMessage()]);
            return ['duplicate' => false, 'id' => $id ?: null];
        }
    }

    /**
     * Undo a claim made by claimNotification() when the message was not delivered
     */
    private function releaseClaim($claimId)
    {
        if (!$claimId) {
            return;
        }
        try {
            Capsule::table(self::DEDUP_TABLE)->where('id', $claimId)->delete();
        } catch (\Throwable $e) {
            $this->logDebug('Failed to release duplicate claim', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Create the dedup table on first use. Versions before 4.2.0 stored hashes in tblconfiguration,
     * which WHMCS loads on every request, so those rows are removed at the same time.
     */
    private function ensureDedupTable()
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        try {
            $schema = Capsule::schema();
            if (!$schema->hasTable(self::DEDUP_TABLE)) {
                $schema->create(self::DEDUP_TABLE, function ($table) {
                    $table->increments('id');
                    $table->string('hash', 32)->index();
                    $table->dateTime('created_at')->index();
                });

                Capsule::table('tblconfiguration')
                    ->where('setting', 'like', 'alnakib\_%')
                    ->delete();
            }
            $ready = true;
        } catch (\Throwable $e) {
            // Another request may have created the table at the same moment ("already exists")
            try {
                $ready = Capsule::schema()->hasTable(self::DEDUP_TABLE);
            } catch (\Throwable $inner) {
                $ready = false;
            }
            if (!$ready) {
                $this->logDebug('Could not create dedup table', ['error' => $e->getMessage()]);
            }
        }

        return $ready;
    }

    // ------------------------------------------------------------------
    // Data extraction
    // ------------------------------------------------------------------

    /**
     * Extract and enrich notification data with real WHMCS data
     */
    private function extractAndEnrichData($notification, $entity)
    {
        $enriched = [
            'title' => (string) $notification->getTitle(),
            'message' => (string) $notification->getMessage(),
            'url' => (string) $notification->getUrl(),
            'type' => $entity['type'],
            'client' => '',
            'amount' => '',
            'status' => '',
            'details' => [],
            'attributes' => $this->getAttributeList($notification),
        ];

        try {
            switch ($entity['type']) {
                case 'invoice':
                    $enriched = array_merge($enriched, $this->getInvoiceData($entity['id'], $entity['lookup']));
                    break;
                case 'ticket':
                    $enriched = array_merge($enriched, $this->getTicketData($entity['id'], $entity['lookup']));
                    break;
                case 'order':
                    $enriched = array_merge($enriched, $this->getOrderData($entity['id'], $entity['lookup']));
                    break;
            }
        } catch (\Throwable $e) {
            $this->logDebug('Data extraction error', ['type' => $entity['type'], 'error' => $e->getMessage()]);
        }

        return $enriched;
    }

    /**
     * Get invoice data from database
     */
    private function getInvoiceData($invoiceId, $lookup = null)
    {
        try {
            $query = Capsule::table('tblinvoices')
                ->leftJoin('tblclients', 'tblinvoices.userid', '=', 'tblclients.id')
                ->leftJoin('tblcurrencies', 'tblclients.currency', '=', 'tblcurrencies.id')
                ->select([
                    'tblinvoices.*',
                    'tblclients.firstname', 'tblclients.lastname', 'tblclients.email',
                    'tblclients.companyname', 'tblclients.phonenumber', 'tblclients.country',
                    'tblcurrencies.code as currency_code', 'tblcurrencies.prefix', 'tblcurrencies.suffix',
                ]);

            if ($invoiceId) {
                $invoice = (clone $query)->where('tblinvoices.id', $invoiceId)->first();
            } elseif ($lookup !== null && $lookup !== '') {
                // A title carries the displayed invoice number. Match that first and fall back to the
                // row ID only when no invoice has that number, so "105" never picks the wrong invoice.
                $invoice = (clone $query)->where('tblinvoices.invoicenum', $lookup)->first();
                if (!$invoice && ctype_digit($lookup)) {
                    $invoice = (clone $query)->where('tblinvoices.id', (int) $lookup)->first();
                }
            } else {
                return ['type' => 'invoice'];
            }
        } catch (\Throwable $e) {
            $this->logDebug('Invoice lookup failed', ['error' => $e->getMessage()]);
            return ['type' => 'invoice'];
        }

        if (!$invoice) {
            return ['type' => 'invoice'];
        }

        list($prefix, $suffix, $currencyCode, $currencyFormat) = $this->currencyFor($invoice);
        $money = function ($amount) use ($prefix, $suffix, $currencyFormat) {
            return $this->formatMoney($amount, $prefix, $suffix, $currencyFormat);
        };

        $items = [];
        try {
            $rows = Capsule::table('tblinvoiceitems')
                ->where('invoiceid', $invoice->id)
                ->select(['description', 'amount'])
                ->get();
            foreach ($rows as $row) {
                $items[] = $this->plainText($row->description) . ': ' . $money($row->amount);
            }
        } catch (\Throwable $e) {
            $this->logDebug('Invoice items lookup failed', ['error' => $e->getMessage()]);
        }

        $invoiceNumber = ($invoice->invoicenum ?? '') !== '' ? $invoice->invoicenum : $invoice->id;
        $invoiceDate = $this->formatDate($invoice->date ?? null, 'M j, Y');
        $dueDate = $this->formatDate($invoice->duedate ?? null, 'M j, Y');
        $datePaid = $this->formatDate($invoice->datepaid ?? null, 'M j, Y g:i A');

        $daysOverdue = '';
        $dueTime = $this->toTimestamp($invoice->duedate ?? null);
        if (($invoice->status ?? '') === 'Unpaid' && $dueTime && time() > $dueTime) {
            $days = (int) floor((time() - $dueTime) / 86400);
            if ($days > 0) {
                $daysOverdue = $days . ' day' . ($days != 1 ? 's' : '') . ' overdue';
            }
        }

        $client = $this->clientName($invoice);
        $status = ucfirst((string) ($invoice->status ?? ''));
        $paymentMethod = $this->gatewayName((string) ($invoice->paymentmethod ?? ''));
        $tax = (float) ($invoice->tax ?? 0);
        $tax2 = (float) ($invoice->tax2 ?? 0);
        $credit = (float) ($invoice->credit ?? 0);

        return [
            'type' => 'invoice',
            'userid' => (int) ($invoice->userid ?? 0),
            'invoice_number' => (string) $invoiceNumber,
            'client' => $client,
            'email' => (string) ($invoice->email ?? ''),
            'company' => (string) ($invoice->companyname ?? ''),
            'phone' => (string) ($invoice->phonenumber ?? ''),
            'country' => (string) ($invoice->country ?? ''),
            'amount' => $money($invoice->total ?? 0),
            'currency' => $currencyCode,
            'status' => $status,
            'due_date' => $dueDate,
            'date_paid' => $datePaid,
            'invoice_date' => $invoiceDate,
            'payment_method' => $paymentMethod,
            'subtotal' => $money($invoice->subtotal ?? 0),
            'credit' => $credit > 0 ? $money($credit) : '',
            'tax' => $tax > 0 ? $money($tax) : '',
            'tax2' => $tax2 > 0 ? $money($tax2) : '',
            'tax_rate' => (float) ($invoice->taxrate ?? 0) > 0 ? (float) $invoice->taxrate . '%' : '',
            'tax_rate2' => (float) ($invoice->taxrate2 ?? 0) > 0 ? (float) $invoice->taxrate2 . '%' : '',
            'notes' => $this->plainText($invoice->notes ?? ''),
            'items' => $items,
            'items_count' => count($items),
            'days_overdue' => $daysOverdue,
            'details' => [
                'Invoice' => '#' . $invoiceNumber,
                'Client' => $client,
                'Email' => $invoice->email ?? '',
                'Company' => $invoice->companyname ?? '',
                'Phone' => $invoice->phonenumber ?? '',
                'Country' => $invoice->country ?? '',
                'Invoice Date' => $invoiceDate,
                'Due Date' => $dueDate,
                'Date Paid' => $datePaid,
                'Status' => $status,
                'Subtotal' => $money($invoice->subtotal ?? 0),
                'Tax' => $tax > 0 ? $money($tax) . ' (' . (float) $invoice->taxrate . '%)' : '',
                'Tax 2' => $tax2 > 0 ? $money($tax2) . ' (' . (float) $invoice->taxrate2 . '%)' : '',
                'Credit Applied' => $credit > 0 ? $money($credit) : '',
                'Total' => $money($invoice->total ?? 0),
                'Currency' => $currencyCode,
                'Payment Method' => $paymentMethod,
                'Items Count' => count($items),
                'Days Overdue' => $daysOverdue,
                'Notes' => $this->plainText($invoice->notes ?? ''),
            ],
        ];
    }

    /**
     * Get ticket data from database
     */
    private function getTicketData($ticketId, $mask = null)
    {
        try {
            $query = Capsule::table('tbltickets')
                ->leftJoin('tblclients', 'tbltickets.userid', '=', 'tblclients.id')
                ->leftJoin('tblticketdepartments', 'tbltickets.did', '=', 'tblticketdepartments.id')
                ->select([
                    'tbltickets.*',
                    'tblclients.firstname', 'tblclients.lastname', 'tblclients.email as client_email',
                    'tblclients.companyname', 'tblclients.phonenumber', 'tblclients.country',
                    'tblticketdepartments.name as dept_name',
                ]);

            if ($ticketId) {
                $query->where('tbltickets.id', $ticketId);
            } elseif ($mask !== null && $mask !== '') {
                $query->where('tbltickets.tid', $mask);
            } else {
                return ['type' => 'ticket'];
            }

            $ticket = $query->first();
        } catch (\Throwable $e) {
            $this->logDebug('Ticket lookup failed', ['error' => $e->getMessage()]);
            return ['type' => 'ticket'];
        }

        if (!$ticket) {
            return ['type' => 'ticket'];
        }

        // Guest tickets have no client record; fall back to the name/email on the ticket
        $client = $this->clientName($ticket);
        if ($client === '') {
            $client = (string) ($ticket->name ?? '');
        }
        $email = (string) (($ticket->client_email ?? '') !== '' ? $ticket->client_email : ($ticket->email ?? ''));

        $firstMessage = $this->plainText($ticket->message ?? '');
        $messagePreview = $this->truncateText($firstMessage, 200);

        // Reply statistics. The opening message lives on tbltickets, so it counts as one client message.
        $replyCount = 0;
        $staffReplies = 0;
        $lastReplyBy = '';
        $latestMessage = $firstMessage;
        $latestMessageBy = $client !== '' ? $client : 'Client';
        $lastReply = null;
        try {
            $replyCount = Capsule::table('tblticketreplies')->where('tid', $ticket->id)->count();
            $lastReply = Capsule::table('tblticketreplies')
                ->where('tid', $ticket->id)
                ->orderBy('date', 'desc')
                ->orderBy('id', 'desc')
                ->first();
        } catch (\Throwable $e) {
            $this->logDebug('Ticket replies lookup failed', ['error' => $e->getMessage()]);
        }

        if ($lastReply) {
            $clientLabel = $client !== '' ? $client : (($lastReply->name ?? '') !== '' ? (string) $lastReply->name : 'Client');

            // Staff replies carry the admin's name in `admin` (or an ID in `adminid` on some schemas)
            try {
                $staffColumn = null;
                if (Capsule::schema()->hasColumn('tblticketreplies', 'admin')) {
                    $staffColumn = 'admin';
                } elseif (Capsule::schema()->hasColumn('tblticketreplies', 'adminid')) {
                    $staffColumn = 'adminid';
                }

                if ($staffColumn) {
                    $staffReplies = Capsule::table('tblticketreplies')
                        ->where('tid', $ticket->id)
                        ->whereNotNull($staffColumn)
                        ->where($staffColumn, '!=', $staffColumn === 'admin' ? '' : 0)
                        ->count();

                    $staff = $lastReply->{$staffColumn} ?? '';
                    if ($staffColumn === 'admin') {
                        $lastReplyBy = $staff !== '' && $staff !== null ? (string) $staff : $clientLabel;
                    } else {
                        $lastReplyBy = (int) $staff > 0 ? ($this->adminName((int) $staff) ?: 'Staff') : $clientLabel;
                    }
                }
            } catch (\Throwable $e) {
                $this->logDebug('Ticket staff replies lookup failed', ['error' => $e->getMessage()]);
            }

            $replyText = $this->plainText($lastReply->message ?? '');
            if ($replyText !== '') {
                $latestMessage = $replyText;
                // Author unknown when the staff column can't be read: show the text without a name
                $latestMessageBy = $lastReplyBy;
            }
        }
        $messageCount = $replyCount + 1;
        $clientReplies = max(0, $messageCount - $staffReplies);

        // Related service: stored as "S<hosting id>" or "D<domain id>"
        $relatedService = '';
        try {
            $service = (string) ($ticket->service ?? '');
            if (preg_match('/^([SD])(\d+)$/', $service, $matches)) {
                if ($matches[1] === 'S') {
                    $row = Capsule::table('tblhosting')
                        ->leftJoin('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                        ->where('tblhosting.id', (int) $matches[2])
                        ->select(['tblproducts.name as product_name', 'tblhosting.domain'])
                        ->first();
                    if ($row) {
                        $relatedService = trim(($row->product_name ?? 'Service') . (($row->domain ?? '') !== '' ? ' (' . $row->domain . ')' : ''));
                    }
                } else {
                    $domain = Capsule::table('tbldomains')->where('id', (int) $matches[2])->value('domain');
                    if ($domain) {
                        $relatedService = 'Domain: ' . $domain;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logDebug('Ticket related service lookup failed', ['error' => $e->getMessage()]);
        }

        // Assignment: `flag` holds the assigned admin's ID
        $assignedTo = 'Unassigned';
        if ((int) ($ticket->flag ?? 0) > 0) {
            $assignedTo = $this->adminName((int) $ticket->flag) ?: 'Admin #' . (int) $ticket->flag;
        }

        $createdDate = $this->formatDate($ticket->date ?? null, 'M j, Y g:i A');
        $lastUpdateDate = $this->formatDate($ticket->lastreply ?? null, 'M j, Y g:i A');

        $timeSinceUpdate = '';
        $lastReplyTime = $this->toTimestamp($ticket->lastreply ?? null);
        if ($lastReplyTime) {
            $diff = max(0, time() - $lastReplyTime);
            if ($diff < 3600) {
                $minutes = (int) floor($diff / 60);
                $timeSinceUpdate = $minutes . ' minute' . ($minutes != 1 ? 's' : '') . ' ago';
            } elseif ($diff < 86400) {
                $hours = (int) floor($diff / 3600);
                $timeSinceUpdate = $hours . ' hour' . ($hours != 1 ? 's' : '') . ' ago';
            } else {
                $days = (int) floor($diff / 86400);
                $timeSinceUpdate = $days . ' day' . ($days != 1 ? 's' : '') . ' ago';
            }
        }

        $flags = [];
        if ((int) ($ticket->merged_ticket_id ?? 0) > 0) {
            $flags[] = 'Merged';
        }
        $flagsText = implode(', ', $flags);

        $status = (string) ($ticket->status ?? '');
        $priority = ucfirst((string) ($ticket->urgency ?? ''));
        $department = (string) (($ticket->dept_name ?? '') !== '' ? $ticket->dept_name : 'General');
        $subject = $this->plainText($ticket->title ?? '');
        $ticketNumber = (string) ($ticket->tid ?? $ticket->id);

        return [
            'type' => 'ticket',
            'client' => $client,
            'email' => $email,
            'company' => (string) ($ticket->companyname ?? ''),
            'phone' => (string) ($ticket->phonenumber ?? ''),
            'country' => (string) ($ticket->country ?? ''),
            'status' => $status,
            'priority' => $priority,
            'department' => $department,
            'subject' => $subject,
            'ticket_id' => $ticketNumber,
            'created_date' => $createdDate,
            'last_update' => $lastUpdateDate,
            'time_since_update' => $timeSinceUpdate,
            'last_reply_by' => $lastReplyBy,
            'message_count' => $messageCount,
            'client_replies' => $clientReplies,
            'staff_replies' => $staffReplies,
            'assigned_to' => $assignedTo,
            'message_preview' => $messagePreview,
            'latest_message' => $this->truncateText($latestMessage, 400),
            'latest_message_by' => $latestMessageBy,
            'last_reply_id' => $lastReply ? (int) ($lastReply->id ?? 0) : 0,
            'userid' => (int) ($ticket->userid ?? 0),
            'related_service' => $relatedService,
            'cc_emails' => (string) ($ticket->cc ?? ''),
            'flags' => $flagsText,
            'details' => [
                'Ticket' => '#' . $ticketNumber,
                'Subject' => $subject,
                'Client' => $client,
                'Email' => $email,
                'Company' => $ticket->companyname ?? '',
                'Phone' => $ticket->phonenumber ?? '',
                'Country' => $ticket->country ?? '',
                'Status' => $status,
                'Priority' => $priority,
                'Department' => $department,
                'Assigned To' => $assignedTo,
                'Created' => $createdDate,
                'Last Update' => $lastUpdateDate,
                'Time Since Update' => $timeSinceUpdate,
                'Last Reply By' => $lastReplyBy,
                'Replies' => $messageCount ? $messageCount . ' (' . $clientReplies . ' client, ' . $staffReplies . ' staff)' : '',
                'Related Service' => $relatedService,
                'CC Emails' => $ticket->cc ?? '',
                'Flags' => $flagsText,
                'Message Preview' => $messagePreview,
            ],
        ];
    }

    /**
     * Get order data from database
     */
    private function getOrderData($orderId, $lookup = null)
    {
        try {
            $query = Capsule::table('tblorders')
                ->leftJoin('tblclients', 'tblorders.userid', '=', 'tblclients.id')
                ->leftJoin('tblcurrencies', 'tblclients.currency', '=', 'tblcurrencies.id')
                ->select([
                    'tblorders.*',
                    'tblclients.firstname', 'tblclients.lastname', 'tblclients.email',
                    'tblclients.companyname', 'tblclients.phonenumber', 'tblclients.country',
                    'tblcurrencies.code as currency_code', 'tblcurrencies.prefix', 'tblcurrencies.suffix',
                ]);

            if ($orderId) {
                $order = (clone $query)->where('tblorders.id', $orderId)->first();
            } elseif ($lookup !== null && ctype_digit($lookup)) {
                // Order number first, row ID only as a fallback (see getInvoiceData)
                $order = (clone $query)->where('tblorders.ordernum', $lookup)->first();
                if (!$order) {
                    $order = (clone $query)->where('tblorders.id', (int) $lookup)->first();
                }
            } else {
                return ['type' => 'order'];
            }
        } catch (\Throwable $e) {
            $this->logDebug('Order lookup failed', ['error' => $e->getMessage()]);
            return ['type' => 'order'];
        }

        if (!$order) {
            return ['type' => 'order'];
        }

        list($prefix, $suffix, $currencyCode, $currencyFormat) = $this->currencyFor($order);
        $money = function ($amount) use ($prefix, $suffix, $currencyFormat) {
            return $this->formatMoney($amount, $prefix, $suffix, $currencyFormat);
        };

        // Order contents live in the service, domain and addon tables, linked by orderid
        $items = [];
        $firstProduct = '';
        $firstDomain = '';

        try {
            $services = Capsule::table('tblhosting')
                ->leftJoin('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->where('tblhosting.orderid', $order->id)
                ->select(['tblproducts.name as product_name', 'tblhosting.domain', 'tblhosting.billingcycle'])
                ->get();
            foreach ($services as $service) {
                $name = (string) ($service->product_name ?? 'Product');
                $items[] = $name
                    . (($service->domain ?? '') !== '' ? ' (' . $service->domain . ')' : '')
                    . (($service->billingcycle ?? '') !== '' ? ' - ' . $service->billingcycle : '');
                if ($firstProduct === '') {
                    $firstProduct = $name;
                }
                if ($firstDomain === '' && ($service->domain ?? '') !== '') {
                    $firstDomain = $service->domain;
                }
            }
        } catch (\Throwable $e) {
            $this->logDebug('Order services lookup failed', ['error' => $e->getMessage()]);
        }

        try {
            $domains = Capsule::table('tbldomains')
                ->where('orderid', $order->id)
                ->select(['domain', 'type', 'registrationperiod'])
                ->get();
            foreach ($domains as $domain) {
                $period = (int) ($domain->registrationperiod ?? 0);
                $domainActions = ['Register' => 'Domain Registration', 'Transfer' => 'Domain Transfer'];
                $items[] = ($domainActions[$domain->type ?? ''] ?? 'Domain') . ': ' . $domain->domain
                    . ($period ? ' (' . $period . ' year' . ($period != 1 ? 's' : '') . ')' : '');
                if ($firstDomain === '') {
                    $firstDomain = $domain->domain;
                }
            }
        } catch (\Throwable $e) {
            $this->logDebug('Order domains lookup failed', ['error' => $e->getMessage()]);
        }

        try {
            $addons = Capsule::table('tblhostingaddons')
                ->leftJoin('tbladdons', 'tblhostingaddons.addonid', '=', 'tbladdons.id')
                ->where('tblhostingaddons.orderid', $order->id)
                ->select(['tblhostingaddons.name as custom_name', 'tbladdons.name as addon_name'])
                ->get();
            foreach ($addons as $addon) {
                $name = ($addon->custom_name ?? '') !== '' ? $addon->custom_name : ($addon->addon_name ?? 'Addon');
                $items[] = 'Addon: ' . $name;
            }
        } catch (\Throwable $e) {
            $this->logDebug('Order addons lookup failed', ['error' => $e->getMessage()]);
        }

        // Associated invoice
        $invoiceNumber = '';
        $invoiceStatus = '';
        if ((int) ($order->invoiceid ?? 0) > 0) {
            try {
                $invoice = Capsule::table('tblinvoices')
                    ->where('id', (int) $order->invoiceid)
                    ->select(['id', 'invoicenum', 'status'])
                    ->first();
                if ($invoice) {
                    $invoiceNumber = ($invoice->invoicenum ?? '') !== '' ? $invoice->invoicenum : (string) $invoice->id;
                    $invoiceStatus = (string) $invoice->status;
                }
            } catch (\Throwable $e) {
                $this->logDebug('Order invoice lookup failed', ['error' => $e->getMessage()]);
            }
        }

        $promoText = '';
        if (($order->promocode ?? '') !== '') {
            $promoText = $order->promocode;
            $promoValue = (float) ($order->promovalue ?? 0);
            switch ((string) ($order->promotype ?? '')) {
                case 'Percentage':
                    $promoText .= $promoValue > 0 ? ' (' . $promoValue . '% off)' : '';
                    break;
                case 'Fixed Amount':
                    $promoText .= $promoValue > 0 ? ' (' . $money($promoValue) . ' off)' : '';
                    break;
                case 'Price Override':
                    $promoText .= ' (price set to ' . $money($promoValue) . ')';
                    break;
                case 'Free Setup':
                    $promoText .= ' (free setup)';
                    break;
            }
        }

        // Nameservers are stored comma-separated; accept JSON too
        $nameservers = '';
        $rawNameservers = (string) ($order->nameservers ?? '');
        if ($rawNameservers !== '') {
            $decoded = json_decode($rawNameservers, true);
            $list = is_array($decoded) ? $decoded : explode(',', $rawNameservers);
            $nameservers = implode(', ', array_filter(array_map('trim', $list)));
        }

        $orderDate = $this->formatDate($order->date ?? null, 'M j, Y g:i A');
        $client = $this->clientName($order);
        $status = (string) ($order->status ?? '');
        $paymentMethod = $this->gatewayName((string) ($order->paymentmethod ?? ''));
        $orderNumber = (string) (($order->ordernum ?? '') !== '' ? $order->ordernum : $order->id);

        return [
            'type' => 'order',
            'userid' => (int) ($order->userid ?? 0),
            'order_number' => $orderNumber,
            'client' => $client,
            'email' => (string) ($order->email ?? ''),
            'company' => (string) ($order->companyname ?? ''),
            'phone' => (string) ($order->phonenumber ?? ''),
            'country' => (string) ($order->country ?? ''),
            'amount' => $money($order->amount ?? 0),
            'currency' => $currencyCode,
            'status' => $status,
            'product' => $firstProduct,
            'domain' => $firstDomain,
            'payment_method' => $paymentMethod,
            'order_date' => $orderDate,
            'items' => $items,
            'items_count' => count($items),
            'invoice_id' => $invoiceNumber,
            'invoice_status' => $invoiceStatus,
            'promo_code' => $promoText,
            'nameservers' => $nameservers,
            'fraud_module' => (string) ($order->fraudmodule ?? ''),
            'details' => [
                'Order' => '#' . $orderNumber,
                'Order Date' => $orderDate,
                'Client' => $client,
                'Email' => $order->email ?? '',
                'Company' => $order->companyname ?? '',
                'Phone' => $order->phonenumber ?? '',
                'Country' => $order->country ?? '',
                'Status' => $status,
                'Amount' => $money($order->amount ?? 0),
                'Currency' => $currencyCode,
                'Payment Method' => $paymentMethod,
                'Items Count' => count($items),
                'Invoice' => $invoiceNumber !== '' ? '#' . $invoiceNumber . ($invoiceStatus !== '' ? ' (' . $invoiceStatus . ')' : '') : '',
                'Promo Code' => $promoText,
                'Nameservers' => $nameservers,
                'Fraud Check' => $order->fraudmodule ?? '',
                'Notes' => $this->plainText($order->notes ?? ''),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Message generation (always Telegram HTML)
    // ------------------------------------------------------------------

    /**
     * Generate professional message format
     */
    private function generateProfessionalMessage($notification, $enrichedData, $settings)
    {
        $format = $settings['use_professional_format'] ?? 'professional';
        $includeUrl = $this->isEnabled($settings['include_url'] ?? 'on');

        if ($format === 'custom' && trim((string) ($settings['message_template'] ?? '')) !== '') {
            $message = $this->processCustomTemplate($settings['message_template'], $notification, $enrichedData);
        } elseif ($format === 'detailed') {
            $message = $this->generateDetailedFormat($notification, $enrichedData, $includeUrl);
        } elseif ($format === 'compact') {
            $message = $this->generateCompactFormat($notification, $enrichedData, $includeUrl);
        } else {
            $message = $this->generateProfessionalFormat($notification, $enrichedData, $includeUrl);
        }

        // Telegram rejects a message with no visible text (for example a custom template whose
        // placeholders are all empty for this event), so fall back to the default layout
        if ($this->plainText((string) $message) === '') {
            $message = $this->generateProfessionalFormat($notification, $enrichedData, $includeUrl);
        }

        return $this->limitLength($message);
    }

    /**
     * Notification title, or a generic headline when WHMCS supplied none (an empty <b></b> shows nothing)
     */
    private function headline($notification)
    {
        $title = trim((string) $notification->getTitle());
        return $title !== '' ? $title : 'WHMCS Notification';
    }

    /**
     * Custom template - replace {variable} placeholders with escaped values.
     * The template itself is admin-authored and may contain HTML markup.
     */
    private function processCustomTemplate($template, $notification, $data)
    {
        $vars = [
            'title' => (string) $notification->getTitle(),
            'message' => $this->plainText($notification->getMessage()),
            'url' => (string) $notification->getUrl(),
            'date' => date('M j, Y g:i A'),
        ];

        // Every scalar enriched field (client, amount, status, due_date, ...) is available
        foreach ($data as $key => $value) {
            if (!isset($vars[$key]) && (is_scalar($value) || $value === null)) {
                $vars[$key] = (string) $value;
            }
        }
        if (!empty($data['items']) && is_array($data['items'])) {
            $vars['items'] = implode("\n", $data['items']);
        }

        // Telegram has no <br> tag; admins often type one, and it would make Telegram reject the
        // formatting of the whole message, so turn it into a line break first
        $template = preg_replace('#<br\s*/?>#i', "\n", (string) $template);

        // Single pass over the template: known placeholders get escaped values, placeholders that
        // don't apply to this notification type (e.g. {due_date} on a ticket) become empty. Inserted
        // values are never re-scanned, so client text such as "{test}" is kept as written.
        return preg_replace_callback('/\{([a-z0-9_]+)\}/', function ($matches) use ($vars) {
            return isset($vars[$matches[1]]) ? $this->e($vars[$matches[1]]) : '';
        }, $template);
    }

    /**
     * Professional format (default). Layout, separated by blank lines:
     *   headline + one-line summary ("Invoice #12 | Unpaid | $110.00 USD")
     *   WHMCS message text (skipped when it only repeats the headline)
     *   key facts
     *   item list / latest ticket message
     *   link
     */
    private function generateProfessionalFormat($notification, $data, $includeUrl)
    {
        $title = trim((string) $notification->getTitle());
        $blocks = [];

        $head = '<b>' . $this->e($this->headline($notification)) . '</b>';
        $summary = $this->summaryLine($data);
        if ($summary !== '') {
            $head .= "\n" . $this->e($summary);
        }
        $blocks[] = $head;

        $body = $this->plainText($notification->getMessage());
        if ($body !== '' && !$this->repeatsTitle($body, $title . ' ' . $summary) && !$this->repeatsLatestMessage($body, $data)) {
            $blocks[] = $this->e($this->truncateText($body, 500));
        }

        $adminBase = $this->adminBaseUrl((string) $notification->getUrl());
        switch ($data['type']) {
            case 'invoice':
                $lines = $this->invoiceLines($data, $adminBase);
                break;
            case 'ticket':
                $lines = $this->ticketLines($data, $adminBase);
                break;
            case 'order':
                $lines = $this->orderLines($data, $adminBase);
                break;
            default:
                $lines = [];
        }

        // Nothing enriched from the database: use the details WHMCS attached to the event
        if (empty($data['client']) && !empty($data['attributes'])) {
            foreach ($data['attributes'] as $attribute) {
                $value = $attribute['url'] !== ''
                    ? $this->link($this->absoluteUrl($attribute['url'], $adminBase), $attribute['value'])
                    : $this->e($attribute['value']);
                $lines[] = '<b>' . $this->e($attribute['label']) . ':</b> ' . $value;
            }
        }
        if ($lines) {
            $blocks[] = implode("\n", $lines);
        }

        if (!empty($data['items'])) {
            $blocks[] = $this->itemList($data['type'] === 'order' ? 'Order Items' : 'Items', $data['items']);
        }

        if ($data['type'] === 'ticket' && !empty($data['latest_message'])) {
            $by = (string) ($data['latest_message_by'] ?? '');
            $blocks[] = '<b>Latest message' . ($by !== '' ? ' from ' . $this->e($by) : '') . ":</b>\n"
                . '<blockquote>' . $this->e($data['latest_message']) . '</blockquote>';
        }

        if ($includeUrl && $this->isHttpUrl($notification->getUrl())) {
            $blocks[] = $this->link($notification->getUrl(), $this->getActionText($data['type']));
        }

        return implode("\n\n", $blocks);
    }

    /**
     * One-line identity of the record, e.g. "Ticket #123456 | Open | High priority"
     */
    private function summaryLine($data)
    {
        $parts = [];

        switch ($data['type']) {
            case 'invoice':
                if (!empty($data['invoice_number'])) {
                    $parts[] = 'Invoice #' . $data['invoice_number'];
                }
                $parts[] = $data['status'] ?? '';
                $parts[] = $this->amountWithCurrency($data);
                break;
            case 'ticket':
                if (!empty($data['ticket_id'])) {
                    $parts[] = 'Ticket #' . $data['ticket_id'];
                }
                $parts[] = $data['status'] ?? '';
                $parts[] = !empty($data['priority']) ? $data['priority'] . ' priority' : '';
                break;
            case 'order':
                if (!empty($data['order_number'])) {
                    $parts[] = 'Order #' . $data['order_number'];
                }
                $parts[] = $data['status'] ?? '';
                $parts[] = $this->amountWithCurrency($data);
                break;
        }

        return implode(' | ', array_filter(array_map('strval', $parts), 'strlen'));
    }

    private function invoiceLines($data, $adminBase)
    {
        $lines = $this->clientLines($data, $adminBase);

        // Breakdown only when it differs from the total shown in the summary line
        if (!empty($data['tax']) || !empty($data['credit'])) {
            $lines[] = $this->field('Subtotal', $data['subtotal'] ?? '');
            if (!empty($data['tax'])) {
                $lines[] = $this->field('Tax', $data['tax'] . (!empty($data['tax_rate']) ? ' (' . $data['tax_rate'] . ')' : ''));
            }
            if (!empty($data['tax2'])) {
                $lines[] = $this->field('Tax 2', $data['tax2'] . (!empty($data['tax_rate2']) ? ' (' . $data['tax_rate2'] . ')' : ''));
            }
            if (!empty($data['credit'])) {
                $lines[] = $this->field('Credit Applied', $data['credit']);
            }
        }

        if (!empty($data['invoice_date'])) {
            $lines[] = $this->field('Invoice Date', $data['invoice_date']);
        }
        if (!empty($data['due_date'])) {
            $due = $data['due_date'];
            if (!empty($data['days_overdue'])) {
                $lines[] = '<b>Due Date:</b> ' . $this->e($due) . ' <b>(' . $this->e($data['days_overdue']) . ')</b>';
            } else {
                $lines[] = $this->field('Due Date', $due);
            }
        }
        if (!empty($data['date_paid'])) {
            $lines[] = $this->field('Paid On', $data['date_paid']);
        }
        if (!empty($data['payment_method'])) {
            $lines[] = $this->field('Payment Method', $data['payment_method']);
        }

        return $lines;
    }

    private function ticketLines($data, $adminBase)
    {
        $lines = [];
        if (!empty($data['subject'])) {
            $lines[] = $this->field('Subject', $data['subject']);
        }
        $lines = array_merge($lines, $this->clientLines($data, $adminBase));

        foreach (['department' => 'Department', 'assigned_to' => 'Assigned To', 'related_service' => 'Related Service'] as $key => $label) {
            if (!empty($data[$key])) {
                $lines[] = $this->field($label, $data[$key]);
            }
        }
        if (!empty($data['message_count'])) {
            $lines[] = $this->field('Messages', $data['message_count'] . ' (' . ($data['client_replies'] ?? 0) . ' from client, ' . ($data['staff_replies'] ?? 0) . ' from staff)');
        }
        if (!empty($data['created_date'])) {
            $lines[] = $this->field('Opened', $data['created_date']);
        }
        if (!empty($data['time_since_update'])) {
            $lines[] = $this->field('Last Reply', trim(($data['last_reply_by'] ?? '') . ', ' . $data['time_since_update'], ', '));
        }

        return $lines;
    }

    private function orderLines($data, $adminBase)
    {
        $lines = $this->clientLines($data, $adminBase);

        $orderFields = [
            'country' => 'Country',
            'order_date' => 'Order Date',
            'payment_method' => 'Payment Method',
        ];
        foreach ($orderFields as $key => $label) {
            if (!empty($data[$key])) {
                $lines[] = $this->field($label, $data[$key]);
            }
        }
        if (!empty($data['invoice_id'])) {
            $lines[] = $this->field('Invoice', '#' . $data['invoice_id'] . (!empty($data['invoice_status']) ? ' (' . $data['invoice_status'] . ')' : ''));
        }
        foreach (['promo_code' => 'Promo Code', 'nameservers' => 'Nameservers'] as $key => $label) {
            if (!empty($data[$key])) {
                $lines[] = $this->field($label, $data[$key]);
            }
        }

        return $lines;
    }

    /**
     * "Client: Name (email)" with the name linked to the client profile when possible, plus company
     */
    private function clientLines($data, $adminBase)
    {
        $lines = [];
        if (empty($data['client'])) {
            return $lines;
        }

        $name = $this->e($data['client']);
        if ($adminBase !== '' && !empty($data['userid'])) {
            $name = $this->link($adminBase . 'clientssummary.php?userid=' . (int) $data['userid'], $data['client']);
        }
        $lines[] = '<b>Client:</b> ' . $name . (!empty($data['email']) ? ' (' . $this->e($data['email']) . ')' : '');

        if (!empty($data['company'])) {
            $lines[] = $this->field('Company', $data['company']);
        }

        return $lines;
    }

    private function amountWithCurrency($data)
    {
        $amount = (string) ($data['amount'] ?? '');
        if ($amount !== '' && !empty($data['currency']) && strpos($amount, (string) $data['currency']) === false) {
            $amount .= ' ' . $data['currency'];
        }
        return $amount;
    }

    /**
     * True when the WHMCS message adds nothing beyond the headline and summary line,
     * e.g. "Invoice #1042 is overdue" under "Invoice Overdue / Invoice #1042 | Unpaid"
     */
    private function repeatsTitle($body, $known)
    {
        $filler = ['a', 'an', 'the', 'is', 'are', 'has', 'have', 'been', 'was', 'were', 'to', 'for', 'of', 'on', 'new', 'added', 'created', 'placed', 'opened', 'submitted'];
        // Unicode-aware so messages in Arabic or other scripts are compared word by word, not dropped
        $words = function ($text) {
            $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
            $parts = preg_split('/[^\p{L}\p{N}]+/u', $text);
            // Invalid UTF-8 makes preg_split fail: treat the whole text as one word so it is kept
            return $parts === false ? [$text] : array_filter($parts, 'strlen');
        };

        $knownWords = array_flip($words($known));
        foreach ($words($body) as $word) {
            if (!isset($knownWords[$word]) && !in_array($word, $filler, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * True when the WHMCS message is the same text as the ticket's latest message, which the
     * professional format already shows in its own block (so it would appear twice)
     */
    private function repeatsLatestMessage($body, $data)
    {
        if (($data['type'] ?? '') !== 'ticket' || empty($data['latest_message'])) {
            return false;
        }
        $normalise = function ($text) {
            $text = preg_replace('/\s+/u', ' ', (string) $text);
            $text = $text === null ? '' : $text; // invalid UTF-8: compare nothing
            return trim(preg_replace('/\.\.\.$/', '', trim($text)));
        };
        $body = $normalise($body);
        $latest = $normalise($data['latest_message']);
        if ($body === '' || $latest === '') {
            return false;
        }
        // latest_message is cut at 400 characters, so either text may be the shorter one
        return strpos($latest, $body) === 0 || strpos($body, $latest) === 0;
    }

    /**
     * Admin area base URL (".../admin/") taken from a notification URL, or '' if it can't be derived
     */
    private function adminBaseUrl($url)
    {
        return preg_match('#^(https?://[^?\#]+/)[^/?\#]+\.php#i', $url, $matches) ? $matches[1] : '';
    }

    /**
     * Detailed format - all available information
     */
    private function generateDetailedFormat($notification, $data, $includeUrl)
    {
        $message = '<b>' . $this->e($this->headline($notification)) . "</b>\n\n";

        $body = $this->plainText($notification->getMessage());
        if ($body !== '') {
            $message .= $this->e($this->truncateText($body, 1000)) . "\n\n";
        }

        // WHMCS attributes first, skipping any the database details below already cover
        $detailLabels = [];
        foreach ($data['details'] ?? [] as $label => $value) {
            if ($this->plainText($value) !== '') {
                $detailLabels[strtolower((string) $label)] = true;
            }
        }
        foreach ($data['attributes'] ?? [] as $attribute) {
            if (!isset($detailLabels[strtolower($attribute['label'])])) {
                $message .= $this->field($attribute['label'], $attribute['value']) . "\n";
            }
        }

        foreach ($data['details'] ?? [] as $label => $value) {
            $value = $this->plainText($value);
            if ($value !== '' && $value !== 'N/A') {
                $message .= $this->field($label, preg_replace('/\s+/', ' ', $value)) . "\n";
            }
        }

        if (!empty($data['items'])) {
            $message .= "\n" . $this->itemList('Items', $data['items']) . "\n";
        }

        if ($includeUrl && $this->isHttpUrl($notification->getUrl())) {
            $message .= "\n" . $this->link($notification->getUrl(), 'View in WHMCS');
        }

        return rtrim($message);
    }

    /**
     * Compact format - one line plus link, e.g. "Invoice Paid | Invoice #12 | Paid | $110.00 USD | Jo Client"
     */
    private function generateCompactFormat($notification, $data, $includeUrl)
    {
        $parts = [$this->e($this->headline($notification))];

        $summary = $this->summaryLine($data);
        if ($summary !== '') {
            $parts[] = $this->e($summary);
        }
        if (!empty($data['client'])) {
            $parts[] = $this->e($data['client']);
        } elseif (!empty($data['attributes'])) {
            // No database record: use the first details WHMCS attached (e.g. client, product)
            foreach (array_slice($data['attributes'], 0, 2) as $attribute) {
                $parts[] = $this->e($attribute['value']);
            }
        }

        $message = '<b>' . array_shift($parts) . '</b>' . ($parts ? ' | ' . implode(' | ', $parts) : '');

        if ($includeUrl && $this->isHttpUrl($notification->getUrl())) {
            $message .= "\n" . $this->link($notification->getUrl(), $this->getActionText($data['type']));
        }

        return $message;
    }

    /**
     * Trim over-long messages at a line boundary. Telegram's limit counts UTF-16 units, so length is
     * measured the same way (characters such as emoji in client text count twice). Built-in formats keep
     * every tag on one line except the ticket <blockquote>, which is closed again if the cut lands inside it.
     */
    private function limitLength($message)
    {
        $limit = self::MAX_MESSAGE_LENGTH - 40;
        if ($this->telegramLength($message) <= self::MAX_MESSAGE_LENGTH) {
            return $message;
        }

        $cut = $this->textCut($message, $limit);
        while ($cut !== '' && $this->telegramLength($cut) > $limit) {
            $cut = $this->textCut($cut, max(0, $this->textLength($cut) - ($this->telegramLength($cut) - $limit)));
        }
        $lastBreak = strrpos($cut, "\n");
        if ($lastBreak !== false && $lastBreak > 0) {
            $cut = substr($cut, 0, $lastBreak);
        }
        if (substr_count($cut, '<blockquote>') > substr_count($cut, '</blockquote>')) {
            $cut .= '</blockquote>';
        }

        return $cut . "\n\n<i>(message truncated)</i>";
    }

    /**
     * Length as Telegram counts it: UTF-16 code units
     */
    private function telegramLength($text)
    {
        $supplementary = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $text);
        return $this->textLength($text) + ($supplementary === false ? 0 : $supplementary);
    }

    private function field($label, $value)
    {
        return '<b>' . $this->e($label) . ':</b> ' . $this->e($value);
    }

    /**
     * Bulleted list, capped so a large invoice or order can't crowd out the rest of the message
     */
    private function itemList($label, array $items, $max = 15)
    {
        $text = '<b>' . $this->e($label) . ' (' . count($items) . '):</b>';
        foreach (array_slice($items, 0, $max) as $item) {
            $text .= "\n- " . $this->e(preg_replace('/\s+/', ' ', (string) $item));
        }
        if (count($items) > $max) {
            $text .= "\n- <i>and " . (count($items) - $max) . ' more</i>';
        }
        return $text;
    }

    /**
     * Telegram rejects the whole message if a link is not an absolute http(s) URL,
     * so anything else is shown as plain text instead
     */
    private function link($url, $text)
    {
        if (!$this->isHttpUrl($url)) {
            return $this->e($text);
        }
        return '<a href="' . $this->e(trim((string) $url)) . '">' . $this->e($text) . '</a>';
    }

    private function isHttpUrl($url)
    {
        return (bool) preg_match('#^https?://[^\s<>"]+$#i', trim((string) $url));
    }

    /**
     * Make a WHMCS URL absolute using the admin base URL ("clientssummary.php?userid=3" -> "https://.../admin/clientssummary.php?userid=3")
     */
    private function absoluteUrl($url, $adminBase)
    {
        $url = trim((string) $url);
        if ($url === '' || preg_match('#^https?://#i', $url) || $adminBase === '') {
            return $url;
        }
        if ($url[0] === '/') {
            $parts = parse_url($adminBase);
            return isset($parts['scheme'], $parts['host'])
                ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $url
                : $url;
        }
        return $adminBase . preg_replace('#^\./#', '', $url);
    }

    /**
     * Get action text based on notification type
     */
    private function getActionText($type)
    {
        $actions = [
            'invoice' => 'View Invoice',
            'order' => 'View Order',
            'ticket' => 'View Ticket',
            'service' => 'View Service',
            'domain' => 'View Domain',
            'client' => 'View Client',
        ];

        return $actions[strtolower((string) $type)] ?? 'View Details';
    }

    // ------------------------------------------------------------------
    // Telegram API
    // ------------------------------------------------------------------

    /**
     * Send message with retry logic. Sending runs inside the WHMCS request that triggered the
     * notification, so no new attempt starts once RETRY_BUDGET seconds have been spent.
     */
    private function sendWithRetry($botToken, $chatId, $message, $silent)
    {
        $lastError = '';
        $started = microtime(true);

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $result = $this->sendTelegramMessage($botToken, $chatId, $message, $silent);
            if ($result['success']) {
                return $result;
            }

            $lastError = $result['error'];
            $chatId = $result['chat_id'] ?? $chatId;
            $wait = max(1, min(5, (int) $result['retry_after']));
            if (!$result['retryable'] || $attempt >= self::MAX_RETRIES
                || microtime(true) - $started + $wait > self::RETRY_BUDGET) {
                break;
            }
            sleep($wait);
        }

        return ['success' => false, 'error' => $lastError];
    }

    /**
     * Send message to Telegram. If Telegram rejects the HTML, the message is resent as plain text.
     */
    private function sendTelegramMessage($botToken, $chatId, $message, $silent = false)
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
            'link_preview_options' => json_encode(['is_disabled' => true]),
            'disable_notification' => $silent ? 'true' : 'false',
        ];

        $response = $this->apiRequest($botToken, 'sendMessage', $params);

        // A group upgraded to a supergroup gets a new chat ID; Telegram reports it once per failed call
        $newChatId = $response['parameters']['migrate_to_chat_id'] ?? null;
        if (empty($response['ok']) && $newChatId) {
            $this->logError('Chat ' . $chatId . ' was upgraded to a supergroup. Update the Chat ID setting to ' . $newChatId
                . ' (messages are being redirected until then)');
            $params['chat_id'] = (string) $newChatId;
            $response = $this->apiRequest($botToken, 'sendMessage', $params);
        }

        if (empty($response['ok']) && stripos((string) ($response['description'] ?? ''), "can't parse entities") !== false) {
            $this->logDebug('HTML rejected by Telegram, resending as plain text', ['error' => $response['description']]);
            unset($params['parse_mode']);
            $params['text'] = $this->htmlToPlain($message);
            $response = $this->apiRequest($botToken, 'sendMessage', $params);
        }

        if (!empty($response['ok'])) {
            return ['success' => true, 'message_id' => $response['result']['message_id'] ?? null];
        }

        $code = (int) ($response['error_code'] ?? 0);
        return [
            'success' => false,
            'error' => $response['description'] ?? 'Unknown Telegram API error',
            // Only rate limits and Telegram server errors are retried. Network failures are not:
            // a blocked or unreachable API would otherwise stall the WHMCS page on every attempt
            'retryable' => $code === 429 || $code >= 500,
            'retry_after' => (int) ($response['parameters']['retry_after'] ?? 1),
            // After a supergroup migration, retries go straight to the new chat
            'chat_id' => $params['chat_id'],
        ];
    }

    /**
     * Plain-text version of a Telegram HTML message for the resend after Telegram rejected the markup.
     * Links become "text (url)" so the link back to WHMCS is not lost.
     */
    private function htmlToPlain($message)
    {
        $text = preg_replace_callback('#<a\s[^>]*href\s*=\s*"([^"]*)"[^>]*>(.*?)</a>#is', function ($matches) {
            $url = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $label = trim(html_entity_decode(strip_tags($matches[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($label === '' || $label === $url) {
                return $this->e($url);
            }
            return $matches[2] . ' (' . $this->e($url) . ')';
        }, (string) $message);
        if ($text === null) {
            $text = (string) $message;
        }

        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Call a Bot API method. Uses cURL when available (allow_url_fopen is often disabled), streams otherwise.
     * Always returns an array shaped like a Telegram response.
     */
    private function apiRequest($botToken, $method, array $params)
    {
        $url = 'https://api.telegram.org/bot' . $botToken . '/' . $method;
        $body = http_build_query($params);

        // Hardened hosts sometimes list curl_exec in disable_functions while curl_init stays available
        if (function_exists('curl_init') && function_exists('curl_exec')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => self::DEFAULT_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $raw = curl_exec($ch);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            // curl_close() is a no-op since PHP 8.0 and deprecated in PHP 8.5
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }

            if ($raw === false) {
                return [
                    'ok' => false,
                    'error_code' => 0,
                    'description' => 'Network error (cURL ' . $curlErrno . '): ' . str_replace($botToken, '***', $curlError),
                ];
            }
        } else {
            if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
                return [
                    'ok' => false,
                    'error_code' => 0,
                    'description' => 'Network error: PHP has neither a usable cURL extension nor allow_url_fopen enabled',
                ];
            }
            if (!extension_loaded('openssl')) {
                return [
                    'ok' => false,
                    'error_code' => 0,
                    'description' => 'Network error: PHP has no usable cURL extension and no OpenSSL extension, so it cannot make HTTPS requests',
                ];
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $body,
                    'timeout' => self::DEFAULT_TIMEOUT,
                    'ignore_errors' => true,
                ],
            ]);
            error_clear_last();
            $raw = @file_get_contents($url, false, $context);

            if ($raw === false) {
                // PHP's warning includes the request URL, which contains the bot token
                $lastError = error_get_last();
                $reason = $lastError ? str_replace($botToken, '***', $lastError['message']) : 'could not connect to Telegram API';
                return ['ok' => false, 'error_code' => 0, 'description' => 'Network error: ' . $reason];
            }
        }

        $response = json_decode($raw, true);
        if (!is_array($response) || !isset($response['ok'])) {
            return ['ok' => false, 'error_code' => 0, 'description' => 'Invalid response from Telegram API'];
        }

        return $response;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Get chat ID with fallback
     */
    private function getChatId($notificationSettings, $moduleSettings)
    {
        $chatId = trim((string) ($notificationSettings['chat_id'] ?? ''));
        if ($chatId === '') {
            $chatId = trim((string) ($moduleSettings['default_chat_id'] ?? ''));
        }
        return $chatId;
    }

    /**
     * WHMCS stores checked yesno fields as "on"; older saved values may be "yes"
     */
    private function isEnabled($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['on', 'yes', '1', 'true'], true);
    }

    /**
     * Escape for Telegram HTML (null-safe for PHP 8.1+)
     */
    private function e($value)
    {
        return htmlspecialchars((string) $value, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Strip markup and decode entities from WHMCS-supplied text
     */
    private function plainText($value)
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\r\n?/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    private function truncateText($text, $length)
    {
        return $this->textLength($text) > $length ? rtrim($this->textCut($text, $length)) . '...' : $text;
    }

    /**
     * UTF-8 character length and prefix; work without the mbstring extension
     */
    private function textLength($text)
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }
        $count = preg_match_all('/./su', $text);
        return $count === false ? strlen($text) : $count;
    }

    private function textCut($text, $length)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $length, 'UTF-8');
        }
        return preg_match('/^.{0,' . (int) $length . '}/su', $text, $matches) ? $matches[0] : substr($text, 0, $length);
    }

    /**
     * Currency prefix/suffix/code/format from the joined client currency, falling back to the WHMCS default currency
     *
     * @return array [prefix, suffix, code, format]
     */
    private function currencyFor($row)
    {
        if (($row->currency_code ?? '') !== '') {
            $code = (string) $row->currency_code;
            return [(string) ($row->prefix ?? ''), (string) ($row->suffix ?? ''), $code, $this->currencyFormat($code)];
        }

        try {
            $default = Capsule::table('tblcurrencies')->where('default', 1)->select(['code', 'prefix', 'suffix'])->first();
            if ($default) {
                $code = (string) ($default->code ?? '');
                return [(string) ($default->prefix ?? ''), (string) ($default->suffix ?? ''), $code, $this->currencyFormat($code)];
            }
        } catch (\Throwable $e) {
            $this->logDebug('Default currency lookup failed', ['error' => $e->getMessage()]);
        }

        return ['', '', '', 2];
    }

    /**
     * WHMCS currency format setting: 1 = 1234.56, 2 = 1,234.56, 3 = 1.234,56, 4 = 1,234.
     * Read separately so a schema difference can only affect formatting, never the lookup itself.
     */
    private function currencyFormat($code)
    {
        static $cache = [];
        if ($code === '') {
            return 2;
        }
        if (!isset($cache[$code])) {
            $format = 2;
            try {
                $value = Capsule::table('tblcurrencies')->where('code', $code)->value('format');
                if (in_array((int) $value, [1, 2, 3, 4], true)) {
                    $format = (int) $value;
                }
            } catch (\Throwable $e) {
                $this->logDebug('Currency format lookup failed', ['error' => $e->getMessage()]);
            }
            $cache[$code] = $format;
        }
        return $cache[$code];
    }

    private function formatMoney($amount, $prefix, $suffix, $format = 2)
    {
        $amount = (float) $amount;
        switch ((int) $format) {
            case 1:
                $number = number_format($amount, 2, '.', '');
                break;
            case 3:
                $number = number_format($amount, 2, ',', '.');
                break;
            case 4:
                $number = number_format($amount, 0, '.', ',');
                break;
            default:
                $number = number_format($amount, 2, '.', ',');
        }
        return trim($prefix . $number . $suffix);
    }

    /**
     * Convert a WHMCS date/datetime to a timestamp; zero dates become null
     */
    private function toTimestamp($value)
    {
        $value = (string) $value;
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }
        $time = strtotime($value);
        return $time === false ? null : $time;
    }

    private function formatDate($value, $format)
    {
        $time = $this->toTimestamp($value);
        return $time ? date($format, $time) : '';
    }

    private function clientName($row)
    {
        $name = trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));
        return $name !== '' ? $name : trim((string) ($row->companyname ?? ''));
    }

    /**
     * Catch obviously wrong settings before calling Telegram, with a message the admin can act on.
     * An empty chat ID is allowed here (testConnection can run without one).
     *
     * @return string Empty when valid, otherwise the problem
     */
    private function validateCredentials($botToken, $chatId)
    {
        if ($botToken === '') {
            return 'No bot token configured';
        }
        if (!preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $botToken)) {
            return 'Bot token format is invalid (expected 123456789:ABC... from @BotFather)';
        }
        if ($chatId !== '' && !preg_match('/^(-?\d+|@[A-Za-z][A-Za-z0-9_]{4,})$/', $chatId)) {
            // Only safe characters are echoed: this text reaches the Activity Log and WHMCS test results
            $shown = preg_replace('/[^A-Za-z0-9@_-]/', '', substr($chatId, 0, 40));
            return 'Chat ID "' . $shown . '" is invalid (expected a number such as -1001234567890, or @channelname)';
        }
        return '';
    }

    /**
     * Enough to diagnose a notification without copying client personal data into the log
     */
    private function debugSummary(array $data)
    {
        $summary = [
            'type' => $data['type'] ?? 'unknown',
            'record' => $data['invoice_number'] ?? $data['ticket_id'] ?? $data['order_number'] ?? null,
            'status' => $data['status'] ?? '',
            'client_found' => !empty($data['client']),
            'items' => isset($data['items']) ? count($data['items']) : 0,
            'attributes' => isset($data['attributes']) ? count($data['attributes']) : 0,
        ];
        return array_filter($summary, function ($value) {
            return $value !== null && $value !== '';
        });
    }

    /**
     * Display name of a payment gateway ("paypal" -> "PayPal"), as configured in WHMCS
     */
    private function gatewayName($gateway)
    {
        static $cache = [];
        if ($gateway === '') {
            return '';
        }
        if (isset($cache[$gateway])) {
            return $cache[$gateway];
        }

        $name = '';
        try {
            $name = (string) Capsule::table('tblpaymentgateways')
                ->where('gateway', $gateway)
                ->where('setting', 'name')
                ->value('value');
        } catch (\Throwable $e) {
            $this->logDebug('Gateway name lookup failed', ['error' => $e->getMessage()]);
        }

        return $cache[$gateway] = $name !== '' ? $name : ucfirst($gateway);
    }

    private function adminName($adminId)
    {
        static $cache = [];
        if (isset($cache[$adminId])) {
            return $cache[$adminId];
        }

        $name = '';
        try {
            $admin = Capsule::table('tbladmins')->where('id', $adminId)->select(['firstname', 'lastname'])->first();
            $name = $admin ? trim(($admin->firstname ?? '') . ' ' . ($admin->lastname ?? '')) : '';
        } catch (\Throwable $e) {
            $this->logDebug('Admin lookup failed', ['error' => $e->getMessage()]);
        }

        return $cache[$adminId] = $name;
    }

    /**
     * Failure logging (always on): full text to the plugin log file, a short line to the Activity Log
     */
    private function logError($message)
    {
        $written = $this->writeLog('ERROR', $message);

        // Short pointer in the Activity Log so failures are visible in WHMCS; full text lives in the log file
        $summary = 'WHMCS Telegram Pro v' . self::VERSION . ': ' . $this->textCut($message, 300)
            . ($written ? ' (details: modules/notifications/WHMCSTelegramPro/logs/error_log.php)' : '');
        try {
            if (function_exists('logActivity')) {
                logActivity($summary);
            } elseif (!$written) {
                error_log($summary);
            }
        } catch (\Throwable $e) {
            // The Activity Log is a database write and can fail; the file log above still has the entry
            if (!$written) {
                error_log($summary);
            }
        }
    }

    /**
     * Debug logging to the plugin log file (only when enabled in provider settings)
     */
    private function logDebug($message, $data = [])
    {
        if (!$this->debug) {
            return;
        }

        $line = $message . ' - ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!$this->writeLog('DEBUG', $line) && function_exists('logActivity')) {
            logActivity('WHMCS Telegram Pro v' . self::VERSION . ': ' . $this->textCut($line, 1000));
        }
    }

    /**
     * Append to logs/error_log.php, never letting it grow past $logMaxBytes (5 MB).
     * When full, the oldest entries are dropped and the newest half is kept.
     * The file starts with a PHP exit line so it can't be downloaded over the web (works on nginx too).
     *
     * @return bool false if the log could not be written (e.g. folder not writable)
     */
    private function writeLog($level, $message)
    {
        $header = self::LOG_HEADER;
        // Logged text can include client-supplied content; break up PHP open tags as a second line
        // of defence behind the exit header
        $message = str_replace(["\r", "\n", '<?'], [' ', ' ', '< ?'], (string) $message);
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $level . ' v' . self::VERSION . ': ' . $message . "\n";
        if (strlen($line) > self::LOG_MAX_ENTRY_BYTES) {
            $line = substr($line, 0, self::LOG_MAX_ENTRY_BYTES - 16) . " ...[cut]\n";
        }

        $file = self::logFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return false;
        }

        try {
            flock($fp, LOCK_EX);
            $stat = fstat($fp);
            $size = (int) ($stat['size'] ?? 0);

            if ($size === 0) {
                fwrite($fp, $header);
            } elseif ($size + strlen($line) > $this->logMaxBytes) {
                // Keep the newest half, starting at a whole line
                fseek($fp, max(strlen($header), $size - (int) ($this->logMaxBytes / 2)));
                $tail = (string) stream_get_contents($fp);
                $firstBreak = strpos($tail, "\n");
                $tail = $firstBreak === false ? '' : substr($tail, $firstBreak + 1);

                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, $header . '[' . date('Y-m-d H:i:s') . "] INFO: older entries removed to keep this log under "
                    . round($this->logMaxBytes / 1048576, 1) . " MB\n" . $tail);
            }

            fseek($fp, 0, SEEK_END);
            fwrite($fp, $line);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            return true;
        } catch (\Throwable $e) {
            @fclose($fp);
            return false;
        }
    }
}
