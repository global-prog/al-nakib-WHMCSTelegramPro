<?php
/**
 * WHMCS Telegram Pro - Diagnostics Panel
 *
 * Admin-only page that tests delivery FROM THIS SERVER using the module's own code,
 * checks the server environment, and shows the plugin log.
 *
 * @author RAQEEB AL-NAQEEB - AL-NAKIB LTD DEV TEAM
 * @version 4.2.2
 */

require_once __DIR__ . '/../../../init.php';

if (!defined('WHMCS')) {
    http_response_code(403);
    exit('Access denied.');
}

use WHMCS\Authentication\CurrentUser;
use WHMCS\Database\Capsule;
use WHMCS\Module\Notification\WHMCSTelegramPro\WHMCSTelegramPro;

// ---------------------------------------------------------------------
// Access control
// ---------------------------------------------------------------------

if (empty($_SESSION['adminid'])) {
    http_response_code(401);
    exit('Access denied. Please log in to the WHMCS admin area first.');
}

// Every check below fails closed: if WHMCS cannot confirm the admin, the page is not shown.
$adminName = 'Admin #' . (int) $_SESSION['adminid'];
try {
    $currentUser = new CurrentUser();
    $admin = $currentUser->admin();
    $authenticated = $admin && $currentUser->isAuthenticatedAdmin();
} catch (\Throwable $e) {
    $authenticated = false;
}
if (!$authenticated) {
    http_response_code(403);
    exit('Access denied. Could not verify your WHMCS admin session. Log in to the admin area again.');
}
if (!empty($admin->username)) {
    $adminName = (string) $admin->username;
}

// checkPermission() lives in the admin function library, which init.php only loads for admin
// pages. Load it so the permission check below actually runs on this standalone page.
if (!function_exists('checkPermission') && defined('ROOTDIR') && is_file(ROOTDIR . '/includes/adminfunctions.php')) {
    require_once ROOTDIR . '/includes/adminfunctions.php';
}

// Any one of these permissions grants access. checkPermission() runs in no-redirect mode so a
// permission name that a WHMCS version does not have cannot redirect; the others still apply.
$allowed = false;
if (function_exists('checkPermission')) {
    foreach (['Configure General Settings', 'Configure Addon Modules', 'Configure Notifications'] as $permission) {
        try {
            if (checkPermission($permission, true)) {
                $allowed = true;
                break;
            }
        } catch (\Throwable $e) {
            // Unknown permission name in this WHMCS version: try the next one
        }
    }
}
if (!$allowed) {
    http_response_code(403);
    exit('Access denied. Your admin role needs the "Configure General Settings" permission.');
}

// WHMCS may already have loaded the module class (for example through its own autoloader)
if (!class_exists(WHMCSTelegramPro::class, false)) {
    require_once __DIR__ . '/WHMCSTelegramPro.php';
}

header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'self'");

if (empty($_SESSION['tgpro_csrf'])) {
    $_SESSION['tgpro_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['tgpro_csrf'];

function tgpro_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Provider settings saved in WHMCS, if they can be read. The storage format is not documented,
 * so this tries plain JSON and then WHMCS decrypt(), and returns [] when neither works.
 */
function tgpro_saved_settings()
{
    try {
        $raw = Capsule::table('tblnotificationproviders')->where('name', 'WHMCSTelegramPro')->value('settings');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $settings = json_decode($raw, true);
        if (!is_array($settings) && function_exists('decrypt')) {
            $settings = json_decode((string) decrypt($raw), true);
        }
        if (!is_array($settings)) {
            return [];
        }

        // Password fields may be encrypted individually inside the settings. Keep the token only
        // when it (or its decrypted form) looks like a real bot token.
        $token = trim((string) ($settings['bot_token'] ?? ''));
        if ($token !== '' && !tgpro_looks_like_token($token) && function_exists('decrypt')) {
            $token = trim((string) decrypt($token));
        }
        $settings['bot_token'] = tgpro_looks_like_token($token) ? $token : '';
        return $settings;
    } catch (\Throwable $e) {
        return [];
    }
}

function tgpro_looks_like_token($token)
{
    return (bool) preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', (string) $token);
}

/**
 * Plain HTTPS request to api.telegram.org (no bot token) to show whether this server can reach Telegram
 */
function tgpro_network_check()
{
    $result = ['dns' => '', 'https' => '', 'ok' => false];

    $ip = gethostbyname('api.telegram.org');
    if ($ip === 'api.telegram.org' && function_exists('dns_get_record')) {
        // gethostbyname() only knows IPv4; an IPv6-only server can still resolve the name
        $records = @dns_get_record('api.telegram.org', DNS_AAAA);
        if (!empty($records[0]['ipv6'])) {
            $ip = $records[0]['ipv6'];
        }
    }
    $result['dns'] = $ip !== 'api.telegram.org' ? 'Resolved to ' . $ip : 'FAILED: api.telegram.org does not resolve on this server';

    $start = microtime(true);
    if (function_exists('curl_init') && function_exists('curl_exec')) {
        $ch = curl_init('https://api.telegram.org/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch); // no-op since PHP 8.0, deprecated in 8.5
        }
        $time = round(microtime(true) - $start, 2);

        if ($ok === false) {
            $result['https'] = 'FAILED (cURL ' . $errno . '): ' . $error . ' after ' . $time . 's';
            $result['hint'] = tgpro_curl_hint($errno);
        } else {
            $result['https'] = 'Connected: HTTP ' . $code . ' in ' . $time . 's';
            $result['ok'] = true;
        }
    } elseif (!extension_loaded('openssl')) {
        $result['https'] = 'FAILED: PHP has no usable cURL extension and no OpenSSL extension, so it cannot make HTTPS requests';
    } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        error_clear_last();
        $context = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 10, 'ignore_errors' => true]]);
        $raw = @file_get_contents('https://api.telegram.org/', false, $context);
        $time = round(microtime(true) - $start, 2);
        if ($raw === false) {
            $last = error_get_last();
            $result['https'] = 'FAILED: ' . ($last ? $last['message'] : 'could not connect') . ' after ' . $time . 's';
        } else {
            $result['https'] = 'Connected in ' . $time . 's';
            $result['ok'] = true;
        }
    } else {
        $result['https'] = 'FAILED: PHP has neither a usable cURL extension nor allow_url_fopen enabled';
    }

    return $result;
}

/**
 * What a cURL error number usually means for a request to api.telegram.org
 */
function tgpro_curl_hint($errno)
{
    switch ((int) $errno) {
        case 5:
        case 6:
            return 'The server cannot resolve the name. Check the DNS resolvers in /etc/resolv.conf.';
        case 7:
            return 'The connection was refused or blocked. Check the server firewall and aaPanel security rules for outbound port 443.';
        case 28:
            return 'The connection timed out. Outbound traffic to Telegram is most likely dropped by a firewall or blocked by the hosting provider or network.';
        case 35:
        case 60:
        case 77:
            return 'The TLS handshake or certificate check failed. Update the server CA certificates (ca-certificates package) and check for an intercepting proxy.';
        default:
            return '';
    }
}

/**
 * Last lines of the plugin log, read from the end of the file so a 5 MB log stays cheap to show
 */
function tgpro_log_tail($file, $lines = 100)
{
    if (!is_file($file) || !is_readable($file)) {
        return [];
    }
    $size = filesize($file);
    $fp = fopen($file, 'rb');
    if (!$fp) {
        return [];
    }
    fseek($fp, max(0, $size - 65536));
    $data = (string) stream_get_contents($fp);
    fclose($fp);

    $rows = explode("\n", str_replace(WHMCSTelegramPro::LOG_HEADER, '', $data));
    if ($size > 65536) {
        array_shift($rows); // first line is probably partial
    }
    $rows = array_values(array_filter($rows, 'strlen'));
    return array_slice($rows, -$lines);
}

// ---------------------------------------------------------------------
// Actions (POST only, CSRF protected)
// ---------------------------------------------------------------------

$saved = tgpro_saved_settings();
$savedToken = trim((string) ($saved['bot_token'] ?? ''));
$savedChat = trim((string) ($saved['default_chat_id'] ?? ''));

$notice = null;       // ['ok' => bool, 'text' => string]
$network = null;
$chatInput = $savedChat;
$logFile = WHMCSTelegramPro::logFile();

// Results of the last action, carried across the redirect below (post/redirect/get), so
// refreshing the page never repeats an action such as sending a test message
if (!empty($_SESSION['tgpro_flash']) && is_array($_SESSION['tgpro_flash'])) {
    $flash = $_SESSION['tgpro_flash'];
    unset($_SESSION['tgpro_flash']);
    $notice = $flash['notice'] ?? null;
    $network = $flash['network'] ?? null;
    if (isset($flash['chat'])) {
        $chatInput = (string) $flash['chat'];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $notice = ['ok' => false, 'text' => 'The form expired. Reload the page and try again.'];
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'test') {
            $token = trim((string) ($_POST['bot_token'] ?? ''));
            $chatInput = trim((string) ($_POST['chat_id'] ?? ''));
            if ($token === '' && $savedToken !== '') {
                $token = $savedToken;
            }
            try {
                $module = new WHMCSTelegramPro();
                $result = $module->testConnection(['bot_token' => $token, 'default_chat_id' => $chatInput]);
                $notice = ['ok' => true, 'text' => 'Success from this server: ' . ($result['message'] ?? '')
                    . ($chatInput === '' ? ' (token checked; enter a chat ID to also send a message)' : '')];
            } catch (\Throwable $e) {
                $notice = ['ok' => false, 'text' => $e->getMessage()];
            }
            if (function_exists('logActivity')) {
                logActivity('WHMCS Telegram Pro: delivery test by ' . $adminName . ' - ' . ($notice['ok'] ? 'success' : 'failed'));
            }
        } elseif ($action === 'network') {
            $network = tgpro_network_check();
        } elseif ($action === 'clear_log') {
            if (is_file($logFile) && @file_put_contents($logFile, WHMCSTelegramPro::LOG_HEADER, LOCK_EX) === false) {
                $notice = ['ok' => false, 'text' => 'Could not clear the log (file not writable).'];
            } else {
                $notice = ['ok' => true, 'text' => 'Plugin log cleared.'];
                if (function_exists('logActivity')) {
                    logActivity('WHMCS Telegram Pro: plugin log cleared by ' . $adminName);
                }
            }
        }
    }

    $_SESSION['tgpro_flash'] = ['notice' => $notice, 'network' => $network, 'chat' => $chatInput];
    header('Location: admin_panel.php', true, 303);
    exit;
}

// ---------------------------------------------------------------------
// Environment checks (cheap, run on every load)
// ---------------------------------------------------------------------

$checks = [];

$whmcsVersion = '';
try {
    $whmcsVersion = (string) Capsule::table('tblconfiguration')->where('setting', 'Version')->value('value');
} catch (\Throwable $e) {
}
$checks[] = ['WHMCS version', $whmcsVersion !== '' ? $whmcsVersion : 'Unknown', null];
$checks[] = ['Module version', WHMCSTelegramPro::VERSION, null];
$checks[] = ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '7.4.0', '>=')];

$curlUsable = function_exists('curl_init') && function_exists('curl_exec');
if ($curlUsable && function_exists('curl_version')) {
    $curl = curl_version();
    $checks[] = ['cURL', 'Enabled (' . ($curl['version'] ?? '?') . ', ' . ($curl['ssl_version'] ?? 'no SSL') . ')', true];
} elseif (function_exists('curl_init')) {
    $checks[] = ['cURL', 'Installed but curl_exec is disabled (falls back to allow_url_fopen)', null];
} else {
    $checks[] = ['cURL', 'Not installed (falls back to allow_url_fopen)', null];
}
$fopen = filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
$checks[] = ['allow_url_fopen', $fopen ? 'On' : 'Off', $curlUsable ? null : $fopen];
$checks[] = ['OpenSSL', extension_loaded('openssl') ? 'Enabled' : 'Missing', extension_loaded('openssl')];
$checks[] = ['mbstring', extension_loaded('mbstring') ? 'Enabled' : 'Not installed (optional)', null];

$logDir = dirname($logFile);
$logWritable = is_dir($logDir) ? is_writable($logDir) : is_writable(__DIR__);
$logSize = is_file($logFile) ? filesize($logFile) : 0;
$checks[] = ['Plugin log', ($logWritable ? 'Writable' : 'NOT writable') . ', ' . round($logSize / 1048576, 2) . ' MB of 5 MB', $logWritable];

try {
    $table = Capsule::schema()->hasTable(WHMCSTelegramPro::DEDUP_TABLE);
    $checks[] = ['Duplicate prevention table', $table ? 'Present' : 'Created on first notification', $table ? true : null];
} catch (\Throwable $e) {
    $checks[] = ['Duplicate prevention table', 'Could not check: ' . $e->getMessage(), false];
}

$checks[] = ['Saved provider settings', $savedToken !== '' ? 'Found (token will be used if the field below is left empty)' : 'Not readable here; enter the token below', null];

$logLines = tgpro_log_tail($logFile);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WHMCS Telegram Pro - Diagnostics</title>
<style>
    body { font-family: -apple-system, "Segoe UI", Roboto, Arial, sans-serif; background: #f4f6f8; color: #1f2933; margin: 0; padding: 24px; }
    .wrap { max-width: 980px; margin: 0 auto; }
    h1 { font-size: 22px; margin: 0 0 4px; }
    h2 { font-size: 16px; margin: 0 0 12px; }
    .muted { color: #616e7c; font-size: 13px; }
    .card { background: #fff; border: 1px solid #d9e2ec; border-radius: 6px; padding: 18px 20px; margin: 16px 0; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    td { padding: 7px 8px; border-top: 1px solid #edf0f3; vertical-align: top; }
    td:first-child { width: 230px; color: #52606d; }
    .ok { color: #1b7f3b; font-weight: 600; }
    .bad { color: #b42318; font-weight: 600; }
    .notice { padding: 12px 14px; border-radius: 6px; margin: 16px 0; font-size: 14px; }
    .notice.ok { background: #e6f4ea; border: 1px solid #b7dfc1; color: #1b5e20; font-weight: normal; }
    .notice.bad { background: #fdecea; border: 1px solid #f5c2c0; color: #8a1c1c; font-weight: normal; }
    label { display: block; font-size: 13px; color: #52606d; margin: 10px 0 4px; }
    input[type=text], input[type=password] { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #bcccdc; border-radius: 4px; font-size: 14px; }
    button { margin-top: 12px; padding: 8px 16px; border: 0; border-radius: 4px; background: #2563eb; color: #fff; font-size: 14px; cursor: pointer; }
    button.secondary { background: #52606d; }
    button.danger { background: #b42318; }
    pre { background: #1f2933; color: #e4e7eb; padding: 12px; border-radius: 4px; font-size: 12px; line-height: 1.5; max-height: 420px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
    form.inline { display: inline; }
</style>
</head>
<body>
<div class="wrap">
    <h1>WHMCS Telegram Pro - Diagnostics</h1>
    <div class="muted">Signed in as <?php echo tgpro_h($adminName); ?>. Every test on this page runs on this server, the same way real notifications are sent.</div>

    <?php if ($notice) { ?>
        <div class="notice <?php echo $notice['ok'] ? 'ok' : 'bad'; ?>"><?php echo tgpro_h($notice['text']); ?></div>
    <?php } ?>

    <div class="card">
        <h2>Server environment</h2>
        <table>
            <?php foreach ($checks as $check) { ?>
                <tr>
                    <td><?php echo tgpro_h($check[0]); ?></td>
                    <td class="<?php echo $check[2] === null ? '' : ($check[2] ? 'ok' : 'bad'); ?>"><?php echo tgpro_h($check[1]); ?></td>
                </tr>
            <?php } ?>
        </table>
    </div>

    <div class="card">
        <h2>Connection to Telegram</h2>
        <div class="muted">Checks DNS and HTTPS from this server to api.telegram.org. No bot token is sent.</div>
        <?php if ($network) { ?>
            <table style="margin-top: 10px;">
                <tr><td>DNS</td><td class="<?php echo strpos($network['dns'], 'FAILED') === 0 ? 'bad' : 'ok'; ?>"><?php echo tgpro_h($network['dns']); ?></td></tr>
                <tr><td>HTTPS</td><td class="<?php echo $network['ok'] ? 'ok' : 'bad'; ?>"><?php echo tgpro_h($network['https']); ?></td></tr>
            </table>
            <?php if (!$network['ok']) { ?>
                <?php if (!empty($network['hint'])) { ?>
                    <p class="bad"><?php echo tgpro_h($network['hint']); ?></p>
                <?php } ?>
                <p class="muted">This server cannot reach Telegram. Check the server firewall and outbound HTTPS (port 443), ask your hosting provider whether Telegram is blocked in the data centre, and make sure PHP has cURL with OpenSSL.</p>
            <?php } ?>
        <?php } ?>
        <form method="post">
            <input type="hidden" name="csrf" value="<?php echo tgpro_h($csrf); ?>">
            <input type="hidden" name="action" value="network">
            <button type="submit" class="secondary">Check connection</button>
        </form>
    </div>

    <div class="card">
        <h2>Send a test message</h2>
        <div class="muted">Uses the module's own code path. Leave the token empty to use the saved provider settings when they are readable.</div>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo tgpro_h($csrf); ?>">
            <input type="hidden" name="action" value="test">
            <label for="bot_token">Bot token</label>
            <input type="password" id="bot_token" name="bot_token" placeholder="<?php echo $savedToken !== '' ? 'Saved token will be used' : '123456789:ABC...'; ?>">
            <label for="chat_id">Chat ID (leave empty to only check the token)</label>
            <input type="text" id="chat_id" name="chat_id" value="<?php echo tgpro_h($chatInput); ?>" placeholder="-1001234567890">
            <button type="submit">Send test from this server</button>
        </form>
    </div>

    <div class="card">
        <h2>Plugin log</h2>
        <div class="muted"><?php echo tgpro_h('modules/notifications/WHMCSTelegramPro/logs/error_log.php'); ?> - last <?php echo count($logLines); ?> entries, newest at the bottom. The file never exceeds 5 MB.</div>
        <?php if ($logLines) { ?>
            <pre><?php echo tgpro_h(implode("\n", $logLines)); ?></pre>
            <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?php echo tgpro_h($csrf); ?>">
                <input type="hidden" name="action" value="clear_log">
                <button type="submit" class="danger">Clear log</button>
            </form>
        <?php } else { ?>
            <p class="muted">No entries yet.</p>
        <?php } ?>
    </div>

    <div class="card">
        <h2>Setup</h2>
        <table>
            <tr><td>1. Provider</td><td>Setup &gt; Staff Management &gt; Notification Providers &gt; WHMCS Telegram Pro: enter the bot token and default chat ID, then Test Settings.</td></tr>
            <tr><td>2. Rules</td><td>Setup &gt; Notifications: create a rule, choose WHMCS Telegram Pro as the provider, pick events and a message format.</td></tr>
            <tr><td>3. Troubleshooting</td><td>Enable Debug Logging in the provider settings temporarily, trigger an event, then read the plugin log above.</td></tr>
        </table>
    </div>
</div>
</body>
</html>
