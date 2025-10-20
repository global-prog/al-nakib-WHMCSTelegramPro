<?php
/**
 * WHMCS Telegram Pro - Secure Admin Panel
 * Restricted to WHMCS admin users only
 *
 * @author RAQEEB AL-NAQEEB - AL-NAKIB LTD DEV TEAM
 * @version 4.1.0
 */

// Include WHMCS init file
require_once __DIR__ . '/../../../init.php';

// Check if WHMCS is loaded
if (!defined('WHMCS')) {
    header('HTTP/1.0 403 Forbidden');
    die('Access denied. This file must be accessed through WHMCS.');
}

// Check if user is logged in as admin
if (!isset($_SESSION['adminid']) || empty($_SESSION['adminid'])) {
    header('HTTP/1.0 401 Unauthorized');
    die('Access denied. Admin login required. Please log in to WHMCS admin area first.');
}

// Additional admin verification using WHMCS authentication
use WHMCS\Authentication\CurrentUser;
try {
    $currentUser = new CurrentUser();
    if (!$currentUser->admin() || !$currentUser->isAuthenticatedAdmin()) {
        header('HTTP/1.0 403 Forbidden');
        die('Access denied. Admin privileges required.');
    }
} catch (Exception $e) {
    // Fallback to session check if CurrentUser fails
    if (!isset($_SESSION['adminid']) || !isset($_SESSION['adminusername'])) {
        header('HTTP/1.0 403 Forbidden');
        die('Authentication error. Please log in to WHMCS admin area.');
    }
}

// Check admin permissions for addon configuration (if function exists)
if (function_exists('checkPermission') && !checkPermission('Configure Addons')) {
    header('HTTP/1.0 403 Forbidden');
    die('Access denied. You need "Configure Addons" permission to access this panel.');
}

// Log admin access for security audit (if function exists)
if (function_exists('logActivity')) {
    logActivity("Admin {$_SESSION['adminusername']} accessed WHMCS Telegram Pro admin panel");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WHMCS Telegram Pro - Admin Panel</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .main-container {
            max-width: 1000px;
            margin: 0 auto;
        }
        .header-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .test-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .btn-success-custom {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            color: white;
            font-weight: bold;
            border-radius: 25px;
            padding: 12px 30px;
            transition: all 0.3s ease;
        }
        .btn-success-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
            color: white;
        }
        .result-box {
            margin-top: 20px;
            padding: 15px;
            border-radius: 8px;
            display: none;
        }
        .result-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .result-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .admin-info {
            background: #e3f2fd;
            border: 1px solid #90caf9;
            color: #1565c0;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Admin Info -->
        <div class="admin-info">
            <h6><i class="fas fa-user-shield"></i> Admin Access - Secure Panel</h6>
            <p class="mb-0">
                <strong>Logged in as:</strong> <?php echo htmlspecialchars($_SESSION['adminusername']); ?> |
                <strong>Session ID:</strong> <?php echo substr($_SESSION['adminid'], 0, 8); ?>*** |
                <strong>Access Time:</strong> <?php echo date('Y-m-d H:i:s'); ?>
            </p>
        </div>

        <!-- Header -->
        <div class="header-card">
            <h1><i class="fas fa-check-circle text-success"></i> WHMCS Telegram Pro</h1>
            <p class="lead mb-0">Secure Admin Panel - Real WHMCS Integration v4.1.0</p>
        </div>

        <div class="row">
            <!-- Bot Testing -->
            <div class="col-md-6">
                <div class="test-card">
                    <h4><i class="fas fa-robot text-success"></i> Bot Token Test</h4>
                    <p>Test your Telegram bot configuration.</p>

                    <div class="mb-3">
                        <label class="form-label">Bot Token</label>
                        <input type="password" id="bot_token" class="form-control"
                               placeholder="1234567890:ABCDEFGHIJKLMNOPQRSTUVWXYZ">
                    </div>

                    <button onclick="testBot()" class="btn btn-success-custom">
                        <i class="fas fa-check"></i> Test Bot
                    </button>

                    <div id="bot_result" class="result-box"></div>
                </div>
            </div>

            <!-- Message Testing -->
            <div class="col-md-6">
                <div class="test-card">
                    <h4><i class="fas fa-paper-plane text-info"></i> Send Test Message</h4>
                    <p>Send a test notification message.</p>

                    <div class="mb-3">
                        <label class="form-label">Bot Token</label>
                        <input type="password" id="msg_token" class="form-control">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Chat ID</label>
                        <input type="text" id="chat_id" class="form-control"
                               placeholder="-1001234567890">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Test Message</label>
                        <textarea id="test_message" class="form-control" rows="3">✅ <b>WHMCS Telegram Pro - Test Message</b>

Your Telegram configuration is working perfectly!

📅 Tested by: <?php echo htmlspecialchars($_SESSION['adminusername']); ?> at <?php echo date('Y-m-d H:i:s'); ?></textarea>
                    </div>

                    <button onclick="sendTest()" class="btn btn-success-custom">
                        <i class="fas fa-paper-plane"></i> Send Test
                    </button>

                    <div id="msg_result" class="result-box"></div>
                </div>
            </div>
        </div>

        <!-- WHMCS Integration Guide -->
        <div class="test-card">
            <h4><i class="fas fa-cogs text-primary"></i> WHMCS Integration Setup</h4>

            <div class="alert alert-success">
                <h5><i class="fas fa-check-circle"></i> Step-by-Step Integration</h5>

                <div class="row">
                    <div class="col-md-6">
                        <h6>1. Configure Provider</h6>
                        <ol>
                            <li>Go to <strong>Setup → Staff Management → Notification Providers</strong></li>
                            <li>Find <strong>"WHMCS Telegram Pro"</strong></li>
                            <li>Click <strong>"Settings"</strong></li>
                            <li>Enter your bot token and default chat ID</li>
                            <li>Click <strong>"Test Settings"</strong> to verify</li>
                        </ol>
                    </div>
                    <div class="col-md-6">
                        <h6>2. Create Notification Rules</h6>
                        <ol>
                            <li>Go to <strong>Setup → Notifications → Configure Notifications</strong></li>
                            <li>Click <strong>"Create New Notification Rule"</strong></li>
                            <li>Select <strong>"WHMCS Telegram Pro"</strong> as provider</li>
                            <li>Choose events (Invoice Created, Ticket Opened, etc.)</li>
                            <li>Configure message template and settings</li>
                        </ol>
                    </div>
                </div>
            </div>

            <div class="alert alert-info">
                <h6><i class="fas fa-info-circle"></i> Available WHMCS Events</h6>
                <div class="row">
                    <div class="col-md-4">
                        <strong>Invoice Events:</strong>
                        <ul>
                            <li>Invoice Created</li>
                            <li>Invoice Paid</li>
                            <li>Invoice Cancelled</li>
                            <li>Payment Reminder</li>
                            <li>Overdue Notices</li>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <strong>Support Events:</strong>
                        <ul>
                            <li>Ticket Opened</li>
                            <li>User Reply</li>
                            <li>Admin Reply</li>
                            <li>Ticket Closed</li>
                        </ul>
                    </div>
                    <div class="col-md-4">
                        <strong>Other Events:</strong>
                        <ul>
                            <li>Order Placed</li>
                            <li>Service Events</li>
                            <li>Domain Events</li>
                            <li>Client Signup</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test WHMCS Events -->
        <div class="test-card">
            <h4><i class="fas fa-play text-warning"></i> Test WHMCS Events</h4>
            <p>To test if real notifications work:</p>

            <div class="alert alert-warning">
                <strong>Testing Real Events:</strong>
                <ol>
                    <li>Complete the setup steps above</li>
                    <li>Create a test invoice in WHMCS</li>
                    <li>Mark the invoice as paid</li>
                    <li>Open a support ticket</li>
                    <li>Check your Telegram chat for notifications</li>
                </ol>
            </div>

            <div class="alert alert-primary">
                <strong>Debug Information:</strong>
                <ul>
                    <li>Enable "Debug Logging" in provider settings</li>
                    <li>Check WHMCS Activity Log for notification attempts</li>
                    <li>Look for "WHMCS Telegram Pro" entries in the logs</li>
                </ul>
            </div>
        </div>

        <!-- Security Notice -->
        <div class="alert alert-danger">
            <h6><i class="fas fa-shield-alt"></i> Security Notice</h6>
            <p class="mb-0">
                🔒 This admin panel is restricted to WHMCS administrators only.
                All access attempts are logged for security purposes.
                Do not share bot tokens or access credentials.
                <strong>Admin Only Access: </strong> Verified at <?php echo date('Y-m-d H:i:s'); ?>
            </p>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        function showResult(elementId, success, message) {
            const element = document.getElementById(elementId);
            element.className = success ? 'result-box result-success' : 'result-box result-error';
            element.innerHTML = `<strong>${success ? '✅ Success' : '❌ Error'}:</strong> ${message}`;
            element.style.display = 'block';
        }

        function testBot() {
            const token = document.getElementById('bot_token').value;

            if (!token) {
                showResult('bot_result', false, 'Please enter a bot token');
                return;
            }

            fetch(`https://api.telegram.org/bot${token}/getMe`)
                .then(response => response.json())
                .then(data => {
                    if (data.ok) {
                        showResult('bot_result', true,
                            `Bot is valid!<br>Username: @${data.result.username}<br>Name: ${data.result.first_name}`);
                    } else {
                        showResult('bot_result', false, data.description || 'Invalid token');
                    }
                })
                .catch(error => {
                    showResult('bot_result', false, 'Network error: ' + error.message);
                });
        }

        function sendTest() {
            const token = document.getElementById('msg_token').value;
            const chatId = document.getElementById('chat_id').value;
            const message = document.getElementById('test_message').value;

            if (!token || !chatId || !message) {
                showResult('msg_result', false, 'Please fill all fields');
                return;
            }

            const url = `https://api.telegram.org/bot${token}/sendMessage`;
            const data = {
                chat_id: chatId,
                text: message,
                parse_mode: 'HTML'
            };

            fetch(url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    showResult('msg_result', true,
                        `Message sent successfully!<br>Message ID: ${data.result.message_id}`);
                } else {
                    showResult('msg_result', false, data.description || 'Failed to send message');
                }
            })
            .catch(error => {
                showResult('msg_result', false, 'Network error: ' + error.message);
            });
        }
    </script>
</body>
</html>