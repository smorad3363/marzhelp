<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tehran');

if (php_sapi_name() !== 'cli') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header("Location: https://t.me/marzhelp");
        exit;
    }
} 

require_once 'app/classes/marzban.php';
require_once 'app/functions/keyboards.php';
require_once 'app/functions/admin_pagination.php';
require_once 'app/security.php';
require_once 'app/bootstrap.php';

$runtimeStoragePath = $storagePath ?? (__DIR__ . '/storage');
if (is_dir($runtimeStoragePath) && is_writable($runtimeStoragePath)) {
    chdir($runtimeStoragePath);
}

$latestVersion = 'v2';

$marzbanapi = new MarzbanAPI($marzbanUrl, $marzbanAdminUsername, $marzbanAdminPassword);

$botConn = new mysqli($botDbHost, $botDbUser, $botDbPass, $botDbName);
if ($botConn->connect_error) {
    file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - Bot DB connection failed: " . $botConn->connect_error . "\n", FILE_APPEND);
    exit;
}
$botConn->set_charset("utf8");

// If you have run MySql on a different port
// $marzbanConn = new mysqli($vpnDbHost, $vpnDbUser, $vpnDbPass, $vpnDbName, $vpnDbPort);
$marzbanConn = new mysqli($vpnDbHost, $vpnDbUser, $vpnDbPass, $vpnDbName);
if ($marzbanConn->connect_error) {
    file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - VPN DB connection failed: " . $marzbanConn->connect_error . "\n", FILE_APPEND);
    exit;
}
$marzbanConn->set_charset("utf8");

function logDebug($message) {
    file_put_contents('debug.log', date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
}

function checkMarzbanConfig() {
    global $marzbanUrl, $marzbanAdminUsername, $marzbanAdminPassword;
    return !empty($marzbanUrl) && !empty($marzbanAdminUsername) && !empty($marzbanAdminPassword) &&
           $marzbanUrl !== 'https://your-marzban-server.com' &&
           $marzbanAdminUsername !== 'your_admin_username' &&
           $marzbanAdminPassword !== 'your_admin_password';
}

function getLang($userId) {
    global $botConn;

    $langCode = 'en'; 

    if ($stmt = $botConn->prepare("SELECT lang FROM marzhelp_user_states WHERE user_id = ?")) {
        $stmt->bind_param("i", $userId);
        
        if ($stmt->execute()) {
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if (in_array($row['lang'], ['fa', 'en', 'ru'])) {
                    $langCode = $row['lang'];
                }
            }
        } else {
            file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - Error executing statement: " . $stmt->error . "\n", FILE_APPEND);
        }
        
        $stmt->close();
    } else {
        file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - Error preparing statement: " . $botConn->error . "\n", FILE_APPEND);
    }

    $languageFile = __DIR__ . "/app/language/{$langCode}.php";

    if (file_exists($languageFile)) {
        $language = include $languageFile;
        return $language;
    }

    return include __DIR__ . "/app/language/en.php";
}

function sendRequest($method, $parameters) {
    global $apiURL, $botConn;
    
    $url = $apiURL . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($parameters));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - cURL error: " . curl_error($ch) . "\n", FILE_APPEND);
    }
    
    curl_close($ch);
    $result = json_decode($response, true);
    
    if (isset($result['result']['message_id']) && isset($parameters['chat_id'])) {
        $messageId = $result['result']['message_id'];
        $userId = $parameters['chat_id'];

        $stmt = $botConn->prepare("UPDATE marzhelp_user_states SET message_id = ? WHERE user_id = ?");
        $stmt->bind_param("ii", $messageId, $userId);
        $stmt->execute();
        $stmt->close();
    }
    
    return $result;
}

    function getUserRole($telegramId) {
    global $allowedUsers, $marzbanConn;
    
    if (in_array($telegramId, $allowedUsers)) {
        return 'main_admin';
    }
    
    $stmt = $marzbanConn->prepare("SELECT id FROM admins WHERE telegram_id = ?");
    $stmt->bind_param("i", $telegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $isLimitedAdmin = $result->num_rows > 0;
    $stmt->close();
    
    if ($isLimitedAdmin) {
        return 'limited_admin';
    }
    
    return 'unauthorized';
}

function triggerCheck($connection, $triggerName, $adminId) {
    $columns = [
        'prevent_user_creation' => 'prevent_user_creation',
        'admin_delete' => 'prevent_user_deletion',
        'prevent_User_Reset_Usage' => 'prevent_user_reset',
        'prevent_revoke_subscription' => 'prevent_revoke_subscription',
        'prevent_unlimited_traffic' => 'prevent_unlimited_traffic',
    ];
    if (!isset($columns[$triggerName])) {
        return false;
    }
    $column = $columns[$triggerName];
    $statement = $connection->prepare(
        "SELECT `$column` FROM marzhelp_admin_settings WHERE admin_id = ?"
    );
    $statement->bind_param('i', $adminId);
    $statement->execute();
    $row = $statement->get_result()->fetch_assoc();
    $statement->close();
    return !empty($row[$column]);
}

function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+[]{}|;:,.<>?';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

function createAdmin($userId, $chatId) {
    global $marzbanConn, $botConn;

    $lang = getLang($userId); 

    $username = handleTemporaryData('get', $userId, 'new_admin_username');
    $hashedPassword = handleTemporaryData('get', $userId, 'new_admin_password');
    $isSudo = handleTemporaryData('get', $userId, 'new_admin_sudo') ?? 0;
    $telegramId = handleTemporaryData('get', $userId, 'new_admin_telegram_id') ?? 0;
    $nothashedpassword = handleTemporaryData('get', $userId, 'new_admin_password_nothashed');
     $stmt = $botConn->prepare("SELECT state, admin_id, message_id FROM marzhelp_user_states WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $userStateResult = $stmt->get_result();
    $userState = $userStateResult->fetch_assoc();
    $stmt->close();

    if (!$username || !$hashedPassword) {
        sendRequest('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $userState['message_id'],
        ]);

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $lang['createAdmin_error_insufficient_data']
        ]);
        return;
    }

    $createdAt = date('Y-m-d H:i:s');

    $stmt = $marzbanConn->prepare("INSERT INTO admins (username, hashed_password, created_at, is_sudo, telegram_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssii", $username, $hashedPassword, $createdAt, $isSudo, $telegramId);
    
    if ($stmt->execute()) {
        $newAdminId = $stmt->insert_id;

        $promptMessageId = $userState['message_id'];

        sendRequest('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $promptMessageId
        ]);

        $successText = sprintf($lang['createAdmin_success_added'], $username, $nothashedpassword, $telegramId);

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $successText,
            'parse_mode' => 'Markdown',
            'reply_markup' => getAdminKeyboard($chatId, $newAdminId, 'active')
        ]);
    } else {
        $promptMessageId = $userState['message_id'];

        sendRequest('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $promptMessageId
        ]);

        $errorText = sprintf($lang['createAdmin_error_adding_failed'], $stmt->error);

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $errorText,
        ]);
    }
    $stmt->close();

    handleUserState('clear', $userId);

    handleTemporaryData('clear', $userId);
}

function handleUserState($action, $userId, $state = null, $adminId = null) {
    global $botConn;

    if ($action === 'set') {
        if ($adminId !== null) {
            $sql = "INSERT INTO marzhelp_user_states (user_id, state, admin_id) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE state = ?, admin_id = ?";
            $stmt = $botConn->prepare($sql);
            $stmt->bind_param("isisi", $userId, $state, $adminId, $state, $adminId);
        } else {
            $sql = "INSERT INTO marzhelp_user_states (user_id, state) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE state = ?";
            $stmt = $botConn->prepare($sql);
            $stmt->bind_param("iss", $userId, $state, $state);
        }

        if (!$stmt->execute()) {
            file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - SQL error: " . $stmt->error . "\n", FILE_APPEND);
            $stmt->close();
            return false;
        }

        $stmt->close();
        return true;

    } elseif ($action === 'get') {
        $stmt = $botConn->prepare("SELECT state, admin_id, message_id FROM marzhelp_user_states WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $state = null;
        $adminId = null;
        $messageId = null;
        
        if ($row = $result->fetch_assoc()) {
            $state = $row['state'];
            $adminId = $row['admin_id'];
            $messageId = $row['message_id'];
        }
        
        $stmt->close();
        
        return [
            'state' => $state,
            'admin_id' => $adminId,
            'message_id' => $messageId
        ];

    } elseif ($action === 'clear') {
        $stmt = $botConn->prepare("UPDATE marzhelp_user_states SET state = NULL WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
        return true;
    }

    return false;
}

function handleTemporaryData($operation, $userId, $key = null, $value = null) {
    global $botConn;

    if ($operation === 'set') {
        $stmt = $botConn->prepare("INSERT INTO marzhelp_user_temporaries (user_id, `user_key`, `value`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = ?");
        $stmt->bind_param("isss", $userId, $key, $value, $value);
        if (!$stmt->execute()) {
            file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - SQL error: " . $stmt->error . "\n", FILE_APPEND);
        }
        $stmt->close();
    } elseif ($operation === 'get') {
        $stmt = $botConn->prepare("SELECT `value` FROM marzhelp_user_temporaries WHERE user_id = ? AND `user_key` = ?");
        $stmt->bind_param("is", $userId, $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $retrievedValue = null;
        if ($row = $result->fetch_assoc()) {
            $retrievedValue = $row['value'];
        }
        $stmt->close();
        return $retrievedValue;
    } elseif ($operation === 'clear') {
        $stmt = $botConn->prepare("DELETE FROM marzhelp_user_temporaries WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
    }
}

function setUserTemplateIndex($userId, $index) {
    global $botConn;

    $stmt = $botConn->prepare("INSERT INTO marzhelp_user_states (user_id, template_index) VALUES (?, ?) ON DUPLICATE KEY UPDATE template_index = ?");
    $stmt->bind_param("iii", $userId, $index, $index);
    $stmt->execute();
    $stmt->close();
}
function getUserTemplateIndex($userId) {
    global $botConn;

    $stmt = $botConn->prepare("SELECT template_index FROM marzhelp_user_states WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($templateIndex);
    $stmt->fetch();
    $stmt->close();

    return $templateIndex !== null ? $templateIndex : 0; 
}

function manageEventBasedOnLimits($interval = 1) {
    global $marzbanConn;
    logDebug("manageEventBasedOnLimits called with interval: $interval");

    $allowedIntervals = [1, 3, 5, 10, 30, 60];
    $interval = (int)$interval;
    if (!in_array($interval, $allowedIntervals, true)) {
        $interval = 10;
    }

    $settingName = 'inbound_sync_interval';
    $statement = $marzbanConn->prepare(
        "INSERT INTO marzhelp_runtime_settings (setting_name, setting_value)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $settingValue = (string)$interval;
    $statement->bind_param('ss', $settingName, $settingValue);
    $statement->execute();
    $statement->close();
    logDebug("manageEventBasedOnLimits completed");
}

function getAdminInfo($adminId) {
    global $marzbanConn, $botConn;

    $lang = getLang($adminId);

    $stmtAdmin = $marzbanConn->prepare("SELECT username FROM admins WHERE id = ?");
    $stmtAdmin->bind_param("i", $adminId);
    $stmtAdmin->execute();
    $adminResult = $stmtAdmin->get_result();
    if ($adminResult->num_rows === 0) {
        return false;
    }
    $admin = $adminResult->fetch_assoc();
    $adminUsername = $admin['username'];
    $stmtAdmin->close();

    $stmtSettings = $botConn->prepare(
        "SELECT
            total_traffic,
            expiry_date,
            status,
            user_limit,
            max_user_duration_days,
            calculate_volume,
            prevent_user_creation,
            prevent_user_deletion,
            prevent_user_reset,
            prevent_revoke_subscription,
            prevent_unlimited_traffic
         FROM marzhelp_admin_settings
         WHERE admin_id = ?"
    );
    $stmtSettings->bind_param("i", $adminId);
    $stmtSettings->execute();
    $settingsResult = $stmtSettings->get_result();
    $settings = $settingsResult->fetch_assoc();
    $stmtSettings->close();

    $calculateVolume = $settings['calculate_volume'] ?? 'used_traffic';

    if ($calculateVolume === 'used_traffic') {
        $stmtTraffic = $marzbanConn->prepare("
            SELECT admins.username, 
            (
                (
                    SELECT IFNULL(SUM(users.used_traffic), 0)
                    FROM users
                    WHERE users.admin_id = admins.id
                )
                +
                (
                    SELECT IFNULL(SUM(user_usage_logs.used_traffic_at_reset), 0)
                    FROM user_usage_logs
                    WHERE user_usage_logs.user_id IN (
                        SELECT id FROM users WHERE users.admin_id = admins.id
                    )
                )
                +
                (
                    SELECT IFNULL(SUM(marzhelp_deleted_users.used_traffic_total), 0)
                    FROM marzhelp_deleted_users
                    WHERE marzhelp_deleted_users.admin_id = admins.id
                )
            ) / 1073741824 AS used_traffic_gb
            FROM admins
            WHERE admins.id = ?
            GROUP BY admins.username, admins.id;
        ");
    } else {
        $stmtTraffic = $marzbanConn->prepare("
            SELECT admins.username, 
            (
                (
                    SELECT IFNULL(SUM(
                        CASE
                            WHEN users.data_limit IS NOT NULL THEN users.data_limit
                            ELSE users.used_traffic
                        END
                    ), 0)
                    FROM users
                    WHERE users.admin_id = admins.id
                )
                +
                (
                    SELECT IFNULL(SUM(user_usage_logs.used_traffic_at_reset), 0)
                    FROM user_usage_logs
                    INNER JOIN users ON users.id = user_usage_logs.user_id
                    WHERE users.admin_id = admins.id
                      AND users.data_limit IS NULL
                )
                +
                (
                    SELECT IFNULL(SUM(
                        marzhelp_deleted_users.used_traffic_total
                    ), 0)
                    FROM marzhelp_deleted_users
                    WHERE marzhelp_deleted_users.admin_id = admins.id
                )
            ) / 1073741824 AS created_traffic_gb
            FROM admins
            WHERE admins.id = ?
            GROUP BY admins.username, admins.id;
        ");
    }

    $stmtTraffic->bind_param("i", $adminId);
    $stmtTraffic->execute();
    $trafficResult = $stmtTraffic->get_result();
    $trafficData = $trafficResult->fetch_assoc();
    $stmtTraffic->close();

    $usedTraffic = isset($trafficData['used_traffic_gb']) ? round($trafficData['used_traffic_gb'], 2) : (isset($trafficData['created_traffic_gb']) ? round($trafficData['created_traffic_gb'], 2) : 0);

    $totalTraffic = isset($settings['total_traffic']) ? round($settings['total_traffic'] / 1073741824, 2) : '♾️';
    $remainingTraffic = ($totalTraffic !== '♾️') ? round($totalTraffic - $usedTraffic, 2) : '♾️';

    $expiryDate = isset($settings['expiry_date']) ? $settings['expiry_date'] : '♾️';
    $daysLeft = ($expiryDate !== '♾️') ? ceil((strtotime($expiryDate) - time()) / 86400) : '♾️';

    $statusArray = json_decode($settings['status'], true) ?? ['time' => 'active', 'data' => 'active', 'users' => 'active'];
    $status = $statusArray['users'];

    $stmtUserStats = $marzbanConn->prepare("
        SELECT
            COUNT(*) AS total_users,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_users,
            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired_users,
            SUM(
                CASE
                    WHEN online_at IS NOT NULL
                     AND online_at >= NOW() - INTERVAL 5 MINUTE
                     AND online_at <= NOW() + INTERVAL 1 MINUTE
                    THEN 1 ELSE 0
                END
            ) AS online_users
        FROM users
        WHERE admin_id = ?
    ");
    $stmtUserStats->bind_param("i", $adminId);
    $stmtUserStats->execute();
    $userStatsResult = $stmtUserStats->get_result();
    $userStats = $userStatsResult->fetch_assoc();
    $stmtUserStats->close();

    $userLimit = isset($settings['user_limit']) ? $settings['user_limit'] : '♾️';
    $remainingUserLimit = $userLimit;
    $maxUserDurationDays = isset($settings['max_user_duration_days'])
        ? (int)$settings['max_user_duration_days']
        : null;

    $preventUserCreation = !empty($settings['prevent_user_creation']);
    $preventUserReset = !empty($settings['prevent_user_reset']);
    $preventRevokeSubscription = !empty($settings['prevent_revoke_subscription']);
    $preventUnlimitedTraffic = !empty($settings['prevent_unlimited_traffic']);
    $preventUserDelete = !empty($settings['prevent_user_deletion']);

    return [
        'username' => $adminUsername,
        'userid' => $adminId,
        'usedTraffic' => $usedTraffic,
        'totalTraffic' => $totalTraffic,
        'remainingTraffic' => $remainingTraffic,
        'expiryDate' => $expiryDate,
        'daysLeft' => $daysLeft,
        'status' => $status,
        'userLimit' => $userLimit,
        'remainingUserLimit' => $remainingUserLimit,
        'maxUserDurationDays' => $maxUserDurationDays,
        'preventUserReset' => $preventUserReset,
        'preventUserCreation' => $preventUserCreation,
        'preventUserDeletion' => $preventUserDelete,
        'preventRevokeSubscription' => $preventRevokeSubscription,
        'preventUnlimitedTraffic' => $preventUnlimitedTraffic,
        'userStats' => $userStats
    ];
}

function getAdminInfoText($adminInfo, $userId) {
    global $botConn;
    $lang = getLang($userId);
    file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - Language retrieved: " . json_encode($lang) . "\n", FILE_APPEND);

    $statusText = ($adminInfo['status'] === 'active') ? $lang['active_status'] : $lang['inactive_status'];
    
    $totalTrafficGB = $adminInfo['totalTraffic'];
    $remainingTrafficGB = $adminInfo['remainingTraffic'];
    
    if (is_numeric($totalTrafficGB)) {
        $trafficText = number_format($totalTrafficGB, 2); 
    } else {
        $trafficText = $lang['unlimited'];
    }
    
    if (is_numeric($remainingTrafficGB)) {
        $remainingText = number_format($remainingTrafficGB, 2); 
    } else {
        $remainingText = $lang['unlimited'];
    }
    
    $daysText = ($adminInfo['daysLeft'] !== $lang['unlimited']) ? "`{$adminInfo['daysLeft']}` {$lang['days']}" : $lang['unlimited'];
    
    $remainingUserLimit = ($adminInfo['remainingUserLimit'] !== $lang['unlimited']) ? "{$adminInfo['remainingUserLimit']}" : $lang['unlimited'];
    $maxDurationText = $adminInfo['maxUserDurationDays'] === null
        ? $lang['unlimited']
        : $adminInfo['maxUserDurationDays'] . ' ' . $lang['days'];
    
    $stmt = $botConn->prepare("SELECT lang FROM marzhelp_user_states WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $langfa = 'en'; 
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $langfa = $row['lang'];
    }$stmt->close();
    $separator = "➖➖➖➖➖➖➖➖➖➖"; 
    if ($langfa === 'fa') {
        $separator = "‏" . $separator . "‏"; 
    } else {$separator = $separator;}

    $infoText = "🧸 **{$lang['userid']}:** `{$adminInfo['userid']}`\n";
    $infoText .= "🧸 **{$lang['username']}:** `{$adminInfo['username']}` {$statusText}\n";
    $infoText .= $separator . "\n";
    $infoText .= "📊 **{$lang['totalTraffic']}:** `{$trafficText}" . "` {$lang['createAdmin_traffic_gb']}\n";
    $infoText .= "📤 **{$lang['remainingTraffic']}**: `{$remainingText}" . "` {$lang['createAdmin_traffic_gb']}\n";
    $infoText .= "📥 **{$lang['usedTraffic']}:** `" . number_format($adminInfo['usedTraffic'], 2) . "` {$lang['createAdmin_traffic_gb']}\n";
    $infoText .= $separator . "\n"; 
    $infoText .= "👥 **{$lang['adminInfoText_userCreationLimit']}** `{$remainingUserLimit}`\n";
    $infoText .= "📅 **{$lang['max_duration_label']}** `{$maxDurationText}`\n";
    $infoText .= "⏳ **{$lang['expiryDate']}:** {$daysText} \n";
    $infoText .= $separator . "\n";    

    $userStatsText = "\n**{$lang['adminInfoText_userStatsHeader']}**\n";
    $userStatsText .= "**{$lang['adminInfoText_totalUsers']}** `{$adminInfo['userStats']['total_users']}`\n";
    $userStatsText .= "**{$lang['adminInfoText_activeUsers']}** `{$adminInfo['userStats']['active_users']}`\n";

    $expiredUsers = $adminInfo['userStats']['total_users'] - $adminInfo['userStats']['active_users'];
    $userStatsText .= "**{$lang['adminInfoText_inactiveUsers']}** `{$expiredUsers}`\n";
    $userStatsText .= "**{$lang['adminInfoText_onlineUsers']}** `{$adminInfo['userStats']['online_users']}`";

   
    return $infoText . $userStatsText;
}

/**
 * Apply bulk plan changes through Marzban's API so every user passes the
 * canonical quota, allowance, unlimited-traffic, and duration policy.
 */
function modifyAdminUsersViaApi($adminId, $field, $delta) {
    global $marzbanConn, $marzbanapi;

    if (!in_array($field, ['data_limit', 'expire'], true)) {
        throw new InvalidArgumentException('Unsupported bulk user field');
    }

    $stmt = $marzbanConn->prepare(
        "SELECT username, data_limit, expire FROM users WHERE admin_id = ? AND {$field} IS NOT NULL ORDER BY id"
    );
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $result = ['succeeded' => 0, 'failed' => 0, 'errors' => []];
    foreach ($users as $user) {
        $current = (int)$user[$field];
        $target = $current + (int)$delta;
        if ($field === 'data_limit') {
            // Zero is Marzban's unlimited sentinel; a subtraction must never
            // accidentally turn a finite account into an unlimited one.
            $target = max($target, 1);
        }
        try {
            $marzbanapi->modifyUser($user['username'], [$field => $target]);
            $result['succeeded']++;
        } catch (Throwable $exception) {
            $result['failed']++;
            $result['errors'][] = $user['username'] . ': ' . $exception->getMessage();
        }
    }

    return $result;
}

function autoCreateAdmin($chatId) {
    global $marzbanConn;

    $filePath = 'admin_credentials.txt';

    if (file_exists($filePath)) {
        $credentials = file_get_contents($filePath);
        $configMessage = "اطلاعات ادمین مرزهلپ را در فایل کانفیگ به صورت زیر قرار دهید:\n\n" .
            "```php\n" .
            $credentials .
            "\n\n```" .
            "برای ادیت فایل کانفیگ ، این کامند را وارد کنید:.\n\n" .
            "`nano /var/www/html/marzhelp/config.php`\n\n" .
           "بعد از وارد کردن اطلاعات، دوباره امتحان کنید.\n\n" . 
           "مرزهلپ برای راه اندازی نیاز به ادمین دارد بنابر این لازم است شما ادمین را ایجاد کنید و به صورت بالا در فایل config.php قرار دهید." . 
           "\n\n" .
            "لطفا ادرس پنل خود را با `https://your-marzban-server.com` جایگزین کنید.";

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "ادمین مرزهلپ قبلاً ایجاد شده است.\n\n" . $configMessage,
            'parse_mode' => 'Markdown'
        ]);
        return;
    }

    $username = 'marzhelp_' . bin2hex(random_bytes(4));
    $password = generateRandomPassword(12);
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $marzbanConn->prepare("INSERT INTO admins (username, hashed_password, created_at, is_sudo) VALUES (?, ?, NOW(), 1)");
    $stmt->bind_param("ss", $username, $hashedPassword);

    if ($stmt->execute()) {
        $credentials = "\$marzbanUrl = 'https://your-marzban-server.com';\n" .
            "\$marzbanAdminUsername = '$username';\n" .
            "\$marzbanAdminPassword = '$password';";
        file_put_contents($filePath, $credentials);

        $configMessage = "ادمین جدید مرزهلپ با موفقیت ایجاد شد. لطفاً اطلاعات زیر را در فایل `config.php` قرار دهید:\n\n" .
            "```php\n" .
            $credentials .
            "برای ادیت فایل کانفیگ ، این کامند را وارد کنید:.\n\n" .
            "`nano /var/www/html/marzhelp/config.php`\n\n" .
           "بعد از وارد کردن اطلاعات، دوباره امتحان کنید.\n\n" . 
           "مرزهلپ برای راه اندازی نیاز به ادمین دارد بنابر این لازم است شما ادمین را ایجاد کنید و به صورت بالا در فایل config.php قرار دهید." . 
           "\n\n" .
            "لطفا ادرس پنل خود را با `https://your-marzban-server.com` جایگزین کنید.";

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $configMessage,
            'parse_mode' => 'Markdown'
        ]);
    } else {
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => "خطا در ایجاد ادمین مرزهلپ: " . $stmt->error
        ]);
    }

    $stmt->close();
}

function generateStatusMessage($marzbanapi, $chatId, $lang, $sendMessage = true, $messageId = null) {
    try {
       
        $stats = $marzbanapi->getSystemStats();
        
       
        $mem_total = round($stats['mem_total'] / 1073741824, 2); 
        $mem_used = round($stats['mem_used'] / 1073741824, 2);
        $mem_free = round($mem_total - $mem_used, 2);
        
        $download_usage = round($stats['incoming_bandwidth'] / 1099511627776, 2); 
        $upload_usage = round($stats['outgoing_bandwidth'] / 1099511627776, 2);
        $total_usage = round($download_usage + $upload_usage, 2);
        
        $download_speed = round($stats['incoming_bandwidth_speed'] / 1048576, 2); 
        $upload_speed = round($stats['outgoing_bandwidth_speed'] / 1048576, 2);
        
        $statusText = "🎛 **CPU Cores:** `{$stats['cpu_cores']}`\n";
        $statusText .= "🖥 **CPU Usage:** `{$stats['cpu_usage']}%`\n";
        $statusText .= "➖➖➖➖➖➖➖\n";
        $statusText .= "📊 **Total Memory:** `{$mem_total} GB`\n";
        $statusText .= "📈 **Used Memory:** `{$mem_used} GB`\n";
        $statusText .= "📉 **Free Memory:** `{$mem_free} GB`\n";
        $statusText .= "➖➖➖➖➖➖➖\n";
        $statusText .= "⬇️ **Download Usage:** `{$download_usage} TB`\n";
        $statusText .= "⬆️ **Upload Usage:** `{$upload_usage} TB`\n";
        $statusText .= "↕️ **Total Usage:** `{$total_usage} TB`\n";
        $statusText .= "➖➖➖➖➖➖➖\n";
        $statusText .= "👥 **Total Users:** `{$stats['total_user']}`\n";
        $statusText .= "🟢 **Active Users:** `{$stats['users_active']}`\n";
        $statusText .= "🟣 **On-Hold Users:** `{$stats['users_on_hold']}`\n";
        $statusText .= "🔴 **Deactivated Users:** `{$stats['users_disabled']}`\n";
        $statusText .= "➖➖➖➖➖➖➖\n";
        $statusText .= "⏫ **Upload Speed:** `{$upload_speed} MB/s`\n";
        $statusText .= "⏬ **Download Speed:** `{$download_speed} MB/s`";

        $keyboard = getstatuskeyboard($lang);

        if ($sendMessage) {
            $params = [
                'chat_id' => $chatId,
                'text' => $statusText,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($keyboard)
            ];
            if ($messageId) {
                $params['message_id'] = $messageId;
                sendRequest('editMessageText', $params);
            } else {
                sendRequest('sendMessage', $params);
            }
        }

        return [
            'text' => $statusText,
            'keyboard' => $keyboard
        ];
    } catch (Exception $e) {
        $errorText = "Error fetching stats: {$e->getMessage()}";
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $errorText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(getMainMenuKeyboard($userId, $lang))
        ]);
        return false;
    }
}

function handleCallbackQuery($callback_query) {
    global $botConn, $marzbanConn, $allowedUsers, $botDbPass, $vpnDbPass, $apiURL, $latestVersion, $marzbanapi, $allowSystemCommands;

    $callbackId = $callback_query['id'];
    $userId = $callback_query['from']['id'];
    $data = $callback_query['data'];
    $messageId = $callback_query['message']['message_id'];
    $chatId = $callback_query['message']['chat']['id'];
    $userRole = getUserRole($userId);

    $userState = handleUserState('get', $userId);
    
    $lang = getLang($userId);

    
    if ($userRole === 'unauthorized') {
        sendRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $lang['error_unauthorized'],
            'show_alert' => false
        ]);
        return;
    }

    if ($data === 'toggle_traffic_triggers' || $data === 'save_admin_traffic') {
        sendRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => 'ثبت ترافیک حذف‌شده اکنون به‌صورت خودکار فعال است.',
            'show_alert' => true
        ]);
        return;
    }

    $systemCallbacks = [
        'update_bot',
        'update_marzban',
        'restart_marzban',
        'marzban_restart',
        'marzban_update',
        'apply_template'
    ];
    if (in_array($data, $systemCallbacks, true)) {
        if ($userRole !== 'main_admin' || empty($allowSystemCommands)) {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => 'اجرای فرمان‌های سیستم از داخل ربات غیرفعال است.',
                'show_alert' => true
            ]);
            return;
        }
    }

    $targetAdminId = marzhelpCallbackAdminId($data);
    if (
        $targetAdminId !== null
        && !marzhelpCanManageAdmin($marzbanConn, (int)$userId, $userRole, $targetAdminId)
    ) {
        sendRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $lang['error_unauthorized'],
            'show_alert' => true
        ]);
        return;
    }

    $mainAdminOnlyPrefixes = [
        'add_admin',
        'delete_admin',
        'confirm_delete_admin:',
        'delete_admin_confirmed:',
        'change_sudo:',
        'set_sudo_yes:',
        'set_sudo_no:',
        'confirm_sudo_yes:',
        'confirm_sudo_no:'
    ];
    foreach ($mainAdminOnlyPrefixes as $mainAdminOnlyPrefix) {
        if (strpos($data, $mainAdminOnlyPrefix) === 0 && $userRole !== 'main_admin') {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['error_unauthorized'],
                'show_alert' => true
            ]);
            return;
        }
    }

    $restrictionCallbacks = [
        'toggle_prevent_user_creation:' => 'prevent_user_creation',
        'toggle_prevent_user_deletion:' => 'prevent_user_deletion',
        'toggle_prevent_user_reset:' => 'prevent_user_reset',
        'toggle_prevent_revoke_subscription:' => 'prevent_revoke_subscription',
        'toggle_prevent_unlimited_traffic:' => 'prevent_unlimited_traffic'
    ];
    foreach ($restrictionCallbacks as $callbackPrefix => $columnName) {
        if (strpos($data, $callbackPrefix) !== 0) {
            continue;
        }

        $adminId = (int)substr($data, strlen($callbackPrefix));
        $toggle = $botConn->prepare(
            "INSERT INTO marzhelp_admin_settings (admin_id, `$columnName`)
             VALUES (?, 1)
             ON DUPLICATE KEY UPDATE `$columnName` = 1 - `$columnName`"
        );
        $toggle->bind_param('i', $adminId);
        $toggle->execute();
        $toggle->close();

        $readValue = $botConn->prepare(
            "SELECT `$columnName` FROM marzhelp_admin_settings WHERE admin_id = ?"
        );
        $readValue->bind_param('i', $adminId);
        $readValue->execute();
        $restrictionRow = $readValue->get_result()->fetch_assoc();
        $restrictionValue = (int)$restrictionRow[$columnName];
        $readValue->close();

        $adminInfo = getAdminInfo($adminId);
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['callbackResponse_showRestrictions'],
            'reply_markup' => getRestrictionsKeyboard(
                $adminId,
                $adminInfo['preventUserDeletion'],
                $adminInfo['preventUserCreation'],
                $adminInfo['preventUserReset'],
                $adminInfo['preventRevokeSubscription'],
                $adminInfo['preventUnlimitedTraffic'],
                $userId
            )
        ]);
        return;
    }

    if (!checkMarzbanConfig()) {
        autoCreateAdmin($chatId);
        return; 
        }

    if (strpos($data, 'show_display_only_') === 0) {
        $responseKey = substr($data, strlen('show_display_only_'));
    
        $callbackResponses = [
            'admin' => $lang['callbackResponse_adminSettings'],
            'users' => $lang['callbackResponse_showDisplayOnlyUsers'],
            'limit' => $lang['callbackResponse_showDisplayOnlyLimit']
        ];
    
        if (array_key_exists($responseKey, $callbackResponses)) {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $callbackResponses[$responseKey],
                'show_alert' => true 
            ]);
        } else {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => 'عملیات نامعتبر است.',
                'show_alert' => true 
            ]);
        }
        return;
    }
    
    if (strpos($data, 'protocol_settings:') === 0) {
        $adminId = intval(substr($data, strlen('protocol_settings:')));
    
        $adminInfo = getAdminInfo($adminId, $userId);
        if (!$adminInfo) {
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['callbackResponse_adminNotFound']
            ]);
            return;
        }
        $getprotocolsttingskeyboardtext = $lang['callbackResponse_protocolSettings'];
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $getprotocolsttingskeyboardtext,
            'reply_markup' => getprotocolsttingskeyboard($adminId, $userId)
        ]);
    }
    
    if (strpos($data, 'show_restrictions:') === 0) {
        $adminId = intval(substr($data, strlen('show_restrictions:')));
    
        $adminInfo = getAdminInfo($adminId, $userId);
        if (!$adminInfo) {
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['callbackResponse_adminNotFound']
            ]);
            return;
        }
        
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['callbackResponse_showRestrictions'],
            'reply_markup' => getRestrictionsKeyboard(
                $adminId, 
                $adminInfo['preventUserDeletion'], 
                $adminInfo['preventUserCreation'], 
                $adminInfo['preventUserReset'], 
                $adminInfo['preventRevokeSubscription'], 
                $adminInfo['preventUnlimitedTraffic'],
                $userId
            )
        ]);
    }
    
    if (strpos($data, 'set_user_limit:') === 0) {
        $adminId = intval(substr($data, strlen('set_user_limit:')));
        
        $keyboard = [
            [
                ['text' => '10', 'callback_data' => "set_user_limit_value:$adminId:10"],
                ['text' => '20', 'callback_data' => "set_user_limit_value:$adminId:20"],
                ['text' => '50', 'callback_data' => "set_user_limit_value:$adminId:50"]
            ],
            [
                ['text' => '100', 'callback_data' => "set_user_limit_value:$adminId:100"],
                ['text' => '200', 'callback_data' => "set_user_limit_value:$adminId:200"],
                ['text' => '300', 'callback_data' => "set_user_limit_value:$adminId:300"]
            ],
            [
                ['text' => $lang['set_custom_limit'], 'callback_data' => "custom_set_user_limit:$adminId"]
            ],
            [
                ['text' =>  $lang['back'], 'callback_data' => 'select_admin:' . $adminId]
            ]
        ];
        
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['select_user_limit'] ?? 'لطفاً محدودیت کاربر را انتخاب کنید:',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        return;
    }
    
    if (strpos($data, 'set_user_limit_value:') === 0) {
        list($action, $adminId, $userLimit) = explode(':', $data);
        $adminId = intval($adminId);
        $userLimit = intval($userLimit);
        
        $stmt = $botConn->prepare("INSERT INTO marzhelp_admin_settings (admin_id, user_limit) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_limit = ?");
        $stmt->bind_param("iii", $adminId, $userLimit, $userLimit);
        $stmt->execute();
        $stmt->close();
        
        $adminInfo = getAdminInfo($adminId, $userId);
        $adminInfo['adminId'] = $adminId;
        $infoText = getAdminInfoText($adminInfo, $userId);
        
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['setUserLimit_success']
        ]);
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $infoText,
            'parse_mode' => 'Markdown',
            'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
        ]);
        return;
    }
    
    if (strpos($data, 'custom_set_user_limit:') === 0) {
        $adminId = intval(substr($data, strlen('custom_set_user_limit:')));
        
        handleUserState('set', $userId, 'set_user_limit', $adminId);
        
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['createAdmin_maxUserLimit_prompt'],
            'reply_markup' => getBackToAdminManagementKeyboard($adminId, $userId)
        ]);
        return;
    }
    if (strpos($data, 'reduce_time:') === 0) {
        $adminId = intval(substr($data, strlen('reduce_time:')));
        
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['reduceUserExpiryDays_prompt'],
            'reply_markup' => getBackToAdminManagementKeyboard($adminId, $userId)
        ]);
        handleUserState('set', $userId, 'reduce_time', $adminId);
    
        return;
    }
    if (strpos($data, 'add_time:') === 0) {
        $adminId = intval(substr($data, strlen('add_time:')));
    
        $response = sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['addUserExpiryDays_prompt'],
            'reply_markup' => getBackToAdminManagementKeyboard($adminId, $userId)
        ]);
    
        handleUserState('set', $userId, 'add_time', $adminId);
    
        return;
    }
    if ($data === 'manage_admins' || strpos($data, 'manage_admins:') === 0) {
        global $marzbanAdminUsername;
        $requestedPage = 0;
        if (strpos($data, 'manage_admins:') === 0) {
            $requestedPage = (int)substr($data, strlen('manage_admins:'));
        }
        if ($requestedPage < 1) {
            $requestedPage = (int)(handleTemporaryData('get', $userId, 'admin_list_page') ?: 1);
        }

        if (in_array($userId, $allowedUsers)) {
            $countStatement = $marzbanConn->prepare('SELECT COUNT(*) AS total FROM admins WHERE username <> ?');
            $countStatement->bind_param('s', $marzbanAdminUsername);
        } else {
            $countStatement = $marzbanConn->prepare(
                'SELECT COUNT(*) AS total FROM admins WHERE telegram_id = ? AND username <> ?'
            );
            $countStatement->bind_param('is', $userId, $marzbanAdminUsername);
        }
        $countStatement->execute();
        $total = (int)$countStatement->get_result()->fetch_assoc()['total'];
        $countStatement->close();
        $pagination = marzhelpNormalizeAdminPage($requestedPage, $total);

        if (in_array($userId, $allowedUsers)) {
            $adminsStatement = $marzbanConn->prepare(
                'SELECT id, username FROM admins WHERE username <> ? ORDER BY username ASC, id ASC LIMIT ? OFFSET ?'
            );
            $adminsStatement->bind_param(
                'sii', $marzbanAdminUsername, $pagination['limit'], $pagination['offset']
            );
        } else {
            $adminsStatement = $marzbanConn->prepare(
                'SELECT id, username FROM admins WHERE telegram_id = ? AND username <> ? '
                . 'ORDER BY username ASC, id ASC LIMIT ? OFFSET ?'
            );
            $adminsStatement->bind_param(
                'isii', $userId, $marzbanAdminUsername, $pagination['limit'], $pagination['offset']
            );
        }
        $adminsStatement->execute();
        $adminsResult = $adminsStatement->get_result();
        $rows = [];
        while ($row = $adminsResult->fetch_assoc()) {
            $adminInfo = getAdminInfo((int)$row['id']);
            if (!$adminInfo) {
                continue;
            }
            $remainingTraffic = $adminInfo['remainingTraffic'] === '♾️'
                ? 'نامحدود' : number_format($adminInfo['remainingTraffic'], 2) . ' گیگ';
            $daysLeft = $adminInfo['daysLeft'] === '♾️'
                ? 'نامحدود' : $adminInfo['daysLeft'] . ' روز';
            $callback = 'select_admin:' . $row['id'] . ':' . $pagination['page'];
            $rows[] = [
                ['text' => $daysLeft, 'callback_data' => $callback],
                ['text' => $remainingTraffic, 'callback_data' => $callback],
                ['text' => $row['username'], 'callback_data' => $callback],
            ];
        }
        $adminsStatement->close();
        handleTemporaryData('set', $userId, 'admin_list_page', (string)$pagination['page']);

        $keyboardRows = [];
        if ($total > 0) {
            $keyboardRows[] = [
                ['text' => 'زمان باقی‌مانده', 'callback_data' => 'noop'],
                ['text' => 'حجم باقی‌مانده', 'callback_data' => 'noop'],
                ['text' => 'یوزرنیم', 'callback_data' => 'noop'],
            ];
            $keyboardRows = array_merge($keyboardRows, $rows);
            $keyboardRows[] = marzhelpAdminPaginationRow(
                $pagination['page'], $pagination['pages'], $lang
            );
        }
        if (in_array($userId, $allowedUsers)) {
            $keyboardRows[] = [
                ['text' => $lang['add_admin'], 'callback_data' => 'add_admin'],
                ['text' => $lang['delete_admin'], 'callback_data' => 'delete_admin:' . $pagination['page']],
            ];
        }
        $keyboardRows[] = [['text' => $lang['back'], 'callback_data' => 'back_to_main']];
        handleUserState('clear', $chatId);
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $total > 0 ? $lang['select_admin_prompt'] : $lang['no_admins'],
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboardRows]),
        ]);
        return;
    }
    if (strpos($data, 'set_max_duration:') === 0) {
        $adminId = intval(substr($data, strlen('set_max_duration:')));
        $keyboard = [
            [
                ['text' => '7', 'callback_data' => "set_max_duration_value:$adminId:7"],
                ['text' => '31', 'callback_data' => "set_max_duration_value:$adminId:31"],
                ['text' => '90', 'callback_data' => "set_max_duration_value:$adminId:90"]
            ],
            [
                ['text' => $lang['set_custom_limit'], 'callback_data' => "custom_set_max_duration:$adminId"],
                ['text' => $lang['unlimited'], 'callback_data' => "set_max_duration_value:$adminId:0"]
            ],
            [
                ['text' => $lang['back'], 'callback_data' => 'select_admin:' . $adminId]
            ]
        ];
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['max_duration_prompt'],
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        return;
    }
    if (strpos($data, 'set_max_duration_value:') === 0) {
        [, $adminId, $durationDays] = explode(':', $data);
        $adminId = intval($adminId);
        $durationDays = intval($durationDays);
        $stmt = $botConn->prepare(
            "INSERT INTO marzhelp_admin_settings (admin_id, max_user_duration_days) VALUES (?, NULLIF(?, 0)) " .
            "ON DUPLICATE KEY UPDATE max_user_duration_days = NULLIF(VALUES(max_user_duration_days), 0)"
        );
        $stmt->bind_param('ii', $adminId, $durationDays);
        $stmt->execute();
        $stmt->close();
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['max_duration_saved'],
            'reply_markup' => getBackToAdminManagementKeyboard($adminId, $userId)
        ]);
        return;
    }
    if (strpos($data, 'custom_set_max_duration:') === 0) {
        $adminId = intval(substr($data, strlen('custom_set_max_duration:')));
        handleUserState('set', $userId, 'set_max_duration', $adminId);
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['max_duration_custom_prompt'],
            'reply_markup' => getBackToAdminManagementKeyboard($adminId, $userId)
        ]);
        return;
    }

    if ($data === 'delete_admin' || strpos($data, 'delete_admin:') === 0) {
        $requestedPage = strpos($data, 'delete_admin:') === 0
            ? (int)substr($data, strlen('delete_admin:'))
            : (int)(handleTemporaryData('get', $userId, 'admin_list_page') ?: 1);
        $countResult = $marzbanConn->query('SELECT COUNT(*) AS total FROM admins WHERE is_sudo = 0');
        $total = (int)$countResult->fetch_assoc()['total'];
        $pagination = marzhelpNormalizeAdminPage($requestedPage, $total);
        $statement = $marzbanConn->prepare(
            'SELECT id, username FROM admins WHERE is_sudo = 0 ORDER BY username ASC, id ASC LIMIT ? OFFSET ?'
        );
        $statement->bind_param('ii', $pagination['limit'], $pagination['offset']);
        $statement->execute();
        $result = $statement->get_result();
        $admins = [];
        while ($row = $result->fetch_assoc()) {
            $admins[] = [
                'text' => $row['username'],
                'callback_data' => 'confirm_delete_admin:' . $row['id'] . ':' . $pagination['page'],
            ];
        }
        $statement->close();
        $keyboard = ['inline_keyboard' => array_chunk($admins, 2)];
        if ($total > 0) {
            $keyboard['inline_keyboard'][] = marzhelpAdminPaginationRow(
                $pagination['page'], $pagination['pages'], $lang
            );
        }
        $keyboard['inline_keyboard'][] = [[
            'text' => $lang['back'], 'callback_data' => 'manage_admins:' . $pagination['page'],
        ]];
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $total > 0 ? $lang['select_admin_to_delete'] : $lang['no_admins'],
            'reply_markup' => $keyboard,
        ]);
        return;
    }

    if (strpos($data, 'confirm_delete_admin:') === 0) {
        $deleteParts = explode(':', $data);
        $adminId = (int)($deleteParts[1] ?? 0);
        $deletePage = max(1, (int)($deleteParts[2] ?? 1));
    
        $stmt = $marzbanConn->prepare("SELECT username FROM admins WHERE id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $admin = $result->fetch_assoc();
        $stmt->close();
    
        if (!$admin) {
            sendRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $userState['message_id']]);    
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['admin_not_found']
            ]);
            return;
        }
    
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => $lang['confirm_yes_button'], 'callback_data' => 'delete_admin_confirmed:' . $adminId . ':' . $deletePage],
                    ['text' => $lang['confirm_no_button'], 'callback_data' => 'delete_admin:' . $deletePage]
                ]
            ]
        ];
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => sprintf($lang['confirm_delete_admin'], $admin['username']),
            'reply_markup' => $keyboard
        ]);
        return;
    }
    
    if (strpos($data, 'delete_admin_confirmed:') === 0) {
        $deleteParts = explode(':', $data);
        $adminId = (int)($deleteParts[1] ?? 0);
        $deletePage = max(1, (int)($deleteParts[2] ?? 1));
    
        $stmt = $marzbanConn->prepare("SELECT username, is_sudo FROM admins WHERE id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $admin = $result->fetch_assoc();
        $stmt->close();
    
        if (!$admin) {
            sendRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $userState['message_id']]);    
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['admin_not_found']
            ]);
            return;
        }
    
        $username = $admin['username'];
    
        if ($admin['is_sudo'] == 1) {
            $stmt = $marzbanConn->prepare("UPDATE admins SET is_sudo = 0 WHERE id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $stmt->close();
        }
    
        try {
            $response = $marzbanapi->removeAdmin($username);
    
            if (isset($response['detail']) && $response['detail'] === 'Admin removed successfully') {
                sendRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $userState['message_id']]);   
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => sprintf($lang['admin_deleted_success'], $username)
                ]);
            } else {
                sendRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $userState['message_id']]);        
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => sprintf($lang['admin_delete_failed'], $username) . "\n" . json_encode($response)
                ]);
            }
        } catch (Exception $e) {
            sendRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $userState['message_id']]);    
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['admin_delete_failed'] . "\n" . $e->getMessage()
            ]);
        }
        
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $lang['main_menu'],
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => $lang['manage_admins'], 'callback_data' => 'manage_admins:' . $deletePage]
                ]]
            ]
        ]);
        return;
    }
    
    if ($data === 'delete_admin_cancel') {
        $adminInfo = getAdminInfo($adminId, $userId);
        if (!$adminInfo) {
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['callbackResponse_adminNotFound']
            ]);
            return;
        }
        $adminInfo['adminId'] = $adminId;
        $infoText = getAdminInfoText($adminInfo, $userId);
        
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $infoText,
            'reply_markup' => getAdminKeyboard($chatId, $adminId, 'active')
        ]);
        return;
    }
    if ($data === 'back_to_main') {
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['main_menu'],
            'reply_markup' => getMainMenuKeyboard($userId)
        ]);
        return;
    }
    if (strpos($data, 'disable_inbounds:') === 0) {
        $adminId = intval(substr($data, strlen('disable_inbounds:')));
    
        $inboundsResult = $marzbanConn->query("SELECT tag FROM inbounds");
        $inbounds = [];
        while ($row = $inboundsResult->fetch_assoc()) {
            $inbounds[] = $row['tag'];
        }
    
        $keyboard = [];
        foreach ($inbounds as $inbound) {
            $keyboard[] = [
                'text' => $inbound,
                'callback_data' => 'disable_inbound_select:' . $adminId . ':' . $inbound
            ];
        }

        $keyboard = array_chunk($keyboard, 2);
        $keyboard[] = [
            ['text' => $lang['back'], 'callback_data' => 'back_to_admin_management:' . $adminId]
        ];
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['selectBindToDisable_prompt'],
            'reply_markup' => ['inline_keyboard' => $keyboard]
        ]);
        return;
    }
    if (strpos($data, 'disable_inbound_select:') === 0) {
        list(, $adminId, $inboundTag) = explode(':', $data, 3);
    
        $stmt = $marzbanConn->prepare("SELECT username FROM admins WHERE id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $adminResult = $stmt->get_result();
        $stmt->close();
    
        if ($adminResult->num_rows === 0) {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['callbackResponse_adminNotFound'],
                'show_alert' => false
            ]);
            return;
        }
        $adminRow = $adminResult->fetch_assoc();
        $adminUsername = $adminRow['username'];
    
        $inboundTagEscaped = $marzbanConn->real_escape_string($inboundTag);
        $adminUsernameEscaped = $marzbanConn->real_escape_string($adminUsername);
    
        $sql = "
            INSERT INTO exclude_inbounds_association (proxy_id, inbound_tag)
            SELECT proxies.id, '$inboundTagEscaped'
            FROM users
            INNER JOIN admins ON users.admin_id = admins.id
            INNER JOIN proxies ON proxies.user_id = users.id
            WHERE admins.username = '$adminUsernameEscaped'
            AND proxies.id NOT IN (
                SELECT proxy_id FROM exclude_inbounds_association WHERE inbound_tag = '$inboundTagEscaped'
            );
        ";
    
        if ($marzbanConn->query($sql) === TRUE) {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['inbound_disabled'],
                'show_alert' => false
            ]);
        } else {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['operation_failed'],
                'show_alert' => false
            ]);
        }
    
        $adminInfo = getAdminInfo($adminId, $userId);
        $adminInfo['adminId'] = $adminId;
        $infoText = getAdminInfoText($adminInfo, $userId);
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $infoText,
            'parse_mode' => 'Markdown',
            'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
        ]);
    
        return;
    }
    if (strpos($data, 'enable_inbound_select:') === 0) {
        list(, $adminId, $inboundTag) = explode(':', $data, 3);
    
        $stmt = $marzbanConn->prepare("SELECT username FROM admins WHERE id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $adminResult = $stmt->get_result();
        $stmt->close();
    
        if ($adminResult->num_rows === 0) {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['callbackResponse_adminNotFound'],
                'show_alert' => false
            ]);
            return;
        }
        $adminRow = $adminResult->fetch_assoc();
        $adminUsername = $adminRow['username'];
    
        $inboundTagEscaped = $marzbanConn->real_escape_string($inboundTag);
        $adminUsernameEscaped = $marzbanConn->real_escape_string($adminUsername);
    
        $sql = "
            DELETE FROM exclude_inbounds_association
            WHERE proxy_id IN (
                SELECT proxies.id
                FROM users
                INNER JOIN admins ON users.admin_id = admins.id
                INNER JOIN proxies ON proxies.user_id = users.id
                WHERE admins.username = '$adminUsernameEscaped'
            )
            AND inbound_tag = '$inboundTagEscaped';
        ";
        if ($marzbanConn->query($sql) === TRUE) {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['inbound_enabled'],
                'show_alert' => false
            ]);
        } else {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['operation_failed'],
                'show_alert' => false
            ]);
        }
    
        $adminInfo = getAdminInfo($adminId, $userId);
        $adminInfo['adminId'] = $adminId;
        $infoText = getAdminInfoText($adminInfo, $userId);
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $infoText,
            'parse_mode' => 'Markdown',
            'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
        ]);
        return;
    }
    if (strpos($data, 'enable_inbounds:') === 0) {
        $adminId = intval(substr($data, strlen('enable_inbounds:')));
    
        $inboundsResult = $marzbanConn->query("SELECT tag FROM inbounds");
        $inbounds = [];
        while ($row = $inboundsResult->fetch_assoc()) {
            $inbounds[] = $row['tag'];
        }
    
        $keyboard = [];
        foreach ($inbounds as $inbound) {
            $keyboard[] = [
                'text' => $inbound,
                'callback_data' => 'enable_inbound_select:' . $adminId . ':' . $inbound
            ];
        }
    
        $keyboard = array_chunk($keyboard, 2);
        $keyboard[] = [
            ['text' => $lang['back'], 'callback_data' => 'back_to_admin_management:' . $adminId]
        ];
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['add_inbound_prompt'],
            'reply_markup' => ['inline_keyboard' => $keyboard]
        ]);
        return;
    }
    if (strpos($data, 'toggle_disable_inbound:') === 0) {
        $inboundTag = substr($data, strlen('toggle_disable_inbound:'));
    
        $userState = handleUserState('get', $userId);

        if (
            $userRole === 'limited_admin'
            && $userState
            && !empty($userState['admin_id'])
            && !marzhelpCanManageAdmin(
                $marzbanConn,
                (int)$userId,
                $userRole,
                (int)$userState['admin_id']
            )
        ) {
            handleUserState('clear', $userId);
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['error_unauthorized']
            ]);
            return;
        }

        if ($userState && $userState['state'] === 'disable_inbounds') {
            $selectedInbounds = json_decode($userState['data'], true);
            if (!$selectedInbounds) {
                $selectedInbounds = [];
            }
    
            if (in_array($inboundTag, $selectedInbounds)) {
                $selectedInbounds = array_diff($selectedInbounds, [$inboundTag]);
            } else {
                $selectedInbounds[] = $inboundTag;
            }
    
            $newData = json_encode(array_values($selectedInbounds));
            handleUserState('update', $userId, null, $newData);
    
            $inboundsResult = $marzbanConn->query("SELECT tag FROM inbounds");
            $inbounds = [];
            while ($row = $inboundsResult->fetch_assoc()) {
                $inbounds[] = $row['tag'];
            }
    
            $keyboard = [];
            foreach ($inbounds as $inbound) {
                $isSelected = in_array($inbound, $selectedInbounds);
                $emoji = $isSelected ? '✅ ' : '';
                $keyboard[] = [
                    'text' => $emoji . $inbound,
                    'callback_data' => 'toggle_disable_inbound:' . $inbound
                ];
            }
    
            $keyboard = array_chunk($keyboard, 2);
            $keyboard[] = [
                ['text' => $lang['next_step_button'], 'callback_data' => 'confirm_disable_inbounds'],
                ['text' => $lang['back'], 'callback_data' => 'back_to_admin_management:' . $userState['admin_id']]
            ];
    
            sendRequest('editMessageReplyMarkup', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => ['inline_keyboard' => $keyboard]
            ]);
            return;
        } else {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['invalid_input'],
                'show_alert' => false
            ]);
            return;
        }
    }
    if ($data === 'confirm_disable_inbounds') {

        $userState = handleUserState('get', $userId);

        if ($userState && $userState['state'] === 'disable_inbounds') {
            $adminId = $userState['admin_id'];
            $selectedInbounds = json_decode($userState['data'], true);
            if (!$selectedInbounds || empty($selectedInbounds)) {
                sendRequest('answerCallbackQuery', [
                    'callback_query_id' => $callbackId,
                    'text' => $lang['selectMinInbound_prompt'],
                    'show_alert' => false
                ]);
                return;
            }
    
            $stmt = $marzbanConn->prepare("SELECT username FROM admins WHERE id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $adminResult = $stmt->get_result();
            $stmt->close();
    
            if ($adminResult->num_rows === 0) {
                sendRequest('answerCallbackQuery', [
                    'callback_query_id' => $callbackId,
                    'text' => $lang['callbackResponse_adminNotFound'],
                    'show_alert' => false
                ]);
                return;
            }
            $adminRow = $adminResult->fetch_assoc();
            $adminUsername = $adminRow['username'];
    
            $inboundSelects = array_map(function($inbound) use ($marzbanConn) {
                return "SELECT '" . $marzbanConn->real_escape_string($inbound) . "' AS inbound_tag";
            }, $selectedInbounds);
            $inboundUnion = implode(" UNION ALL ", $inboundSelects);
    
            $adminUsernameEscaped = $marzbanConn->real_escape_string($adminUsername);
    
            $sql = "
                INSERT INTO exclude_inbounds_association (proxy_id, inbound_tag)
                SELECT proxies.id, inbound_tag_mapping.inbound_tag
                FROM users
                INNER JOIN admins ON users.admin_id = admins.id
                INNER JOIN proxies ON proxies.user_id = users.id
                CROSS JOIN (
                    $inboundUnion
                ) AS inbound_tag_mapping
                LEFT JOIN exclude_inbounds_association eia 
                  ON eia.proxy_id = proxies.id AND eia.inbound_tag = inbound_tag_mapping.inbound_tag
                WHERE admins.username = '$adminUsernameEscaped'
                AND eia.proxy_id IS NULL;
            ";
    
            if ($marzbanConn->query($sql) === TRUE) {
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['inbound_disabled']
                ]);
            } else {
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['operation_failed']
                ]);
            }
    
            handleUserState('clear', $userId);

            $adminInfo = getAdminInfo($adminId, $userId);
            $adminInfo['adminId'] = $adminId;
            $infoText = getAdminInfoText($adminInfo, $userId);
    
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $infoText,
                'parse_mode' => 'Markdown',
                'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
            ]);
    
            return;
        } else {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['operation_failed'],
                'show_alert' => false
            ]);
            return;
        }
    }
    if (strpos($data, 'confirm_inbounds:') === 0) {
        $adminId = intval(substr($data, strlen('confirm_inbounds:')));
        $adminInfo = getAdminInfo($adminId, $userId);
        if (!$adminInfo) {
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['callbackResponse_adminNotFound']
            ]);
            return;
        }
        $adminInfo['adminId'] = $adminId;
        $infoText = $lang['inbounds_limited_success'] . "\n" . getAdminInfoText($adminInfo, $userId);
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $infoText,
            'parse_mode' => 'Markdown',
            'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
        ]);
        return;
    }
    if (strpos($data, 'select_admin:') === 0) {
        $selectParts = explode(':', $data);
        $adminId = (int)($selectParts[1] ?? 0);
        if (isset($selectParts[2])) {
            handleTemporaryData('set', $userId, 'admin_list_page', (string)max(1, (int)$selectParts[2]));
        }

        $adminInfo = getAdminInfo($adminId, $userId);
        if (!$adminInfo) {
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['callbackResponse_adminNotFound']
            ]);

            return;
        }
        $adminInfo['adminId'] = $adminId;
        $infoText = getAdminInfoText($adminInfo, $userId);
        handleUserState('clear', $chatId);

        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $infoText,
            'parse_mode' => 'Markdown',
            'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
        ]);

        return;
    }
    if ($data === 'confirm_enable_inbounds') {

        $userState = handleUserState('get', $userId);

        if ($userState && $userState['state'] === 'enable_inbounds') {
            $adminId = $userState['admin_id'];
            $selectedInbounds = json_decode($userState['data'], true);
            if (!$selectedInbounds || empty($selectedInbounds)) {
                sendRequest('answerCallbackQuery', [
                    'callback_query_id' => $callbackId,
                    'text' => $lang['selectMinInbound_prompt'],
                    'show_alert' => false
                ]);
                return;
            }
    
            $stmt = $marzbanConn->prepare("SELECT username FROM admins WHERE id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $adminResult = $stmt->get_result();
            $stmt->close();
    
            if ($adminResult->num_rows === 0) {
                sendRequest('answerCallbackQuery', [
                    'callback_query_id' => $callbackId,
                    'text' => $lang['callbackResponse_adminNotFound'],
                    'show_alert' => false
                ]);
                return;
            }
            $adminRow = $adminResult->fetch_assoc();
            $adminUsername = $adminRow['username'];
    
            $inboundTagsEscaped = array_map(function($inbound) use ($marzbanConn) {
                return "'" . $marzbanConn->real_escape_string($inbound) . "'";
            }, $selectedInbounds);
            $inboundTagsList = implode(", ", $inboundTagsEscaped);
    
            $adminUsernameEscaped = $marzbanConn->real_escape_string($adminUsername);
    
            $sql = "
                DELETE FROM exclude_inbounds_association
                WHERE proxy_id IN (
                    SELECT proxies.id
                    FROM users
                    INNER JOIN admins ON users.admin_id = admins.id
                    INNER JOIN proxies ON proxies.user_id = users.id
                    WHERE admins.username = '$adminUsernameEscaped'
                )
                AND inbound_tag IN ($inboundTagsList);
            ";
    
            if ($marzbanConn->query($sql) === TRUE) {
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['inbound_enabled']
                ]);
            } else {
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['operation_failed']
                ]);
            }
    
            handleUserState('clear', $userId);

            $adminInfo = getAdminInfo($adminId, $userId);
            $adminInfo['adminId'] = $adminId;
            $infoText = getAdminInfoText($adminInfo, $userId);
    
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $infoText,
                'parse_mode' => 'Markdown',
                'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
            ]);
    
            return;
        } else {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['operation_failed'],
                'show_alert' => false
            ]);
            return;
        }
    }
    if (strpos($data, 'back_to_admin_management:') === 0) {
        $adminId = intval(substr($data, strlen('back_to_admin_management:')));

        $adminInfo = getAdminInfo($adminId, $userId);
        if (!$adminInfo) {
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['callbackResponse_adminNotFound']
            ]);
            return;
        }
        $adminInfo['adminId'] = $adminId;
        $infoText = getAdminInfoText($adminInfo, $userId);
        handleUserState('clear', $chatId);

        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $infoText,
            'parse_mode' => 'Markdown',
            'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
        ]);
        return;
    }
    if (strpos($data, 'set_traffic:') === 0) {
        $adminId = intval(substr($data, strlen('set_traffic:')));
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['select_traffic_action'],
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => $lang['custom_subtract_traffic'], 'callback_data' => "custom_subtract_traffic:$adminId"],
                        ['text' => $lang['custom_add_traffic'], 'callback_data' => "custom_add_traffic:$adminId"]
                    ],
                    [
                        ['text' => '-500 GB', 'callback_data' => "subtract_traffic:$adminId:500"],
                        ['text' => '+500 GB', 'callback_data' => "add_traffic:$adminId:500"]
                    ],
                    [
                        ['text' => '-1 TB', 'callback_data' => "subtract_traffic:$adminId:1024"],
                        ['text' => '+1 TB', 'callback_data' => "add_traffic:$adminId:1024"]
                    ],
                    [
                        ['text' => '-5 TB', 'callback_data' => "subtract_traffic:$adminId:5120"],
                        ['text' => '+5 TB', 'callback_data' => "add_traffic:$adminId:5120"]
                    ],
                    [
                        ['text' => $lang['unlimited_traffic'], 'callback_data' => "set_traffic_unlimited:$adminId"] 
                    ],
                    [
                        ['text' => $lang['back'], 'callback_data' => 'select_admin:' . $adminId]
                    ]
                ]
            ])
        ]);
        return;
    }
    if (strpos($data, 'set_traffic_unlimited:') === 0) {
        $adminId = intval(substr($data, strlen('set_traffic_unlimited:')));
    
        $stmt = $botConn->prepare("INSERT INTO marzhelp_admin_settings (admin_id, total_traffic) VALUES (?, NULL) ON DUPLICATE KEY UPDATE total_traffic = NULL");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $stmt->close();
    
        $adminInfo = getAdminInfo($adminId, $userId);
        $adminInfo['adminId'] = $adminId;
        $infoText = getAdminInfoText($adminInfo, $userId);

        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['traffic_update_success']
        ]);
        sendRequest('sendmessage', [
            'chat_id' => $chatId,
            'text' => $infoText,
            'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status']),
            'parse_mode' => 'Markdown'
            
        ]);
    }
    
    if (strpos($data, 'add_traffic:') === 0 || strpos($data, 'subtract_traffic:') === 0) {
        list($action, $adminId, $amount) = explode(':', $data);
        $adminId = intval($adminId);
        $amount = intval($amount) * 1073741824;
    
        if ($action === 'add_traffic') {
            $stmt = $botConn->prepare("
                INSERT INTO marzhelp_admin_settings (admin_id, total_traffic)
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE total_traffic = COALESCE(total_traffic, 0) + VALUES(total_traffic)
            ");
        } else {
            $stmt = $botConn->prepare("
                INSERT INTO marzhelp_admin_settings (admin_id, total_traffic)
                VALUES (?, -?) 
                ON DUPLICATE KEY UPDATE total_traffic = COALESCE(total_traffic, 0) + VALUES(total_traffic)
            ");
        }
        $stmt->bind_param("ii", $adminId, $amount);
        $stmt->execute();
        $stmt->close();
        
        
        $adminInfo = getAdminInfo($adminId, $userId);
        $adminInfo['adminId'] = $adminId;
        $infoText = getAdminInfoText($adminInfo, $userId);
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['traffic_update_success']
        ]);
        sendRequest('sendmessage', [
            'chat_id' => $chatId,
            'text' => $infoText,
            'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status']),
            'parse_mode' => 'Markdown'
            
        ]);
        return;
    }
    
    if (strpos($data, 'custom_add_traffic:') === 0 || strpos($data, 'custom_subtract_traffic:') === 0) {
        $adminId = intval(substr($data, strpos($data, ':') + 1));
        $action = (strpos($data, 'custom_add_traffic:') === 0) ? 'custom_add' : 'custom_subtract';
    
        handleUserState('set', $userId, $action, $adminId);
    
        $promptText = ($action === 'custom_add') 
            ? sprintf($lang['addTraffic_prompt'], $adminId)
            : sprintf($lang['subtractTraffic_prompt'], $adminId);
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $promptText,
            'reply_markup' => getBackToAdminManagementKeyboard($adminId, $userId)
        ]);
        return;
    }
    
    if (strpos($data, 'set_expiry:') === 0) {
        $adminId = intval(substr($data, strlen('set_expiry:')));

        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['select_expiry_action'],
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => $lang['unlimited_expiry'], 'callback_data' => "set_expiry_unlimited:$adminId"],
                        ['text' => $lang['custom_expiry'], 'callback_data' => "custom_expiry:$adminId"]
                    ],
                    [
                        ['text' => '30 ' . $lang['days'], 'callback_data' => "set_expiry_days:$adminId:30"],
                        ['text' => '60 ' . $lang['days'], 'callback_data' => "set_expiry_days:$adminId:60"]
                    ],
                    [
                        ['text' => '90 ' . $lang['days'], 'callback_data' => "set_expiry_days:$adminId:90"],
                        ['text' => '180 ' . $lang['days'], 'callback_data' => "set_expiry_days:$adminId:180"]
                    ],
                    [
                        ['text' => $lang['back'], 'callback_data' => 'select_admin:' . $adminId]
                    ]
                ]
            ])
        ]);
        return;
    }
    if (strpos($data, 'set_expiry_unlimited:') === 0) {
        $adminId = intval(substr($data, strlen('set_expiry_unlimited:')));

        $stmt = $botConn->prepare("INSERT INTO marzhelp_admin_settings (admin_id, expiry_date) VALUES (?, NULL) ON DUPLICATE KEY UPDATE expiry_date = NULL");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $stmt->close();

        $adminInfo = getAdminInfo($adminId, $userId);
        $adminInfo['adminId'] = $adminId;
        $infoText = getAdminInfoText($adminInfo, $userId);

        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['setNewExpiry_success'],
            'parse_mode' => 'Markdown'
        ]);
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $infoText,
            'parse_mode' => 'Markdown',
            'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
        ]);
        return;
    }
    if (strpos($data, 'set_expiry_days:') === 0) {
        list(, $adminId, $days) = explode(':', $data);
        $adminId = intval($adminId);
        $days = intval($days);

        $expiryDate = date('Y-m-d', strtotime("+$days days"));

        $stmt = $botConn->prepare("INSERT INTO marzhelp_admin_settings (admin_id, expiry_date) VALUES (?, ?) ON DUPLICATE KEY UPDATE expiry_date = ?");
        $stmt->bind_param("iss", $adminId, $expiryDate, $expiryDate);
        $stmt->execute();
        $stmt->close();

        $adminInfo = getAdminInfo($adminId, $userId);
        $adminInfo['adminId'] = $adminId;
        $infoText = getAdminInfoText($adminInfo, $userId);

        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['setNewExpiry_success'],
            'parse_mode' => 'Markdown'
        ]);
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $infoText,
            'parse_mode' => 'Markdown',
            'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
        ]);
        return;
    }
    if (strpos($data, 'custom_expiry:') === 0) {
        $adminId = intval(substr($data, strlen('custom_expiry:')));

        handleUserState('set', $userId, 'set_expiry', $adminId);

        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['setExpiryDays_prompt'],
            'parse_mode' => 'Markdown',
            'reply_markup' => getBackToAdminManagementKeyboard($adminId, $userId)
        ]);
        return;
    }
    if (strpos($data, 'disable_users:') === 0) {
        $adminId = intval(substr($data, strlen('disable_users:')));
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['delete_users_confirmation'],
            'reply_markup' => getConfirmationKeyboard($adminId, $userId)
        ]);
        return;
    }
    
    if (strpos($data, 'confirm_disable_yes:') === 0) {
        $adminId = intval(substr($data, strlen('confirm_disable_yes:')));
        global $marzbanConn, $botConn, $marzbanapi;
    
        $adminInfo = getAdminInfo($adminId, $userId);
        if (!$adminInfo || !isset($adminInfo['username'])) {
            sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $lang['callbackResponse_adminNotFound']
            ]);
            return;
        }
        $adminUsername = $adminInfo['username'];
    
        try {
            $marzbanapi->disableAllActiveUsers($adminUsername);
    
            $stmt = $botConn->prepare("SELECT status FROM marzhelp_admin_settings WHERE admin_id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $result = $stmt->get_result();
            $currentStatus = json_decode($result->fetch_assoc()['status'], true) ?? ['time' => 'active', 'data' => 'active', 'users' => 'active'];
            $stmt->close();
    
            $currentStatus['users'] = 'disabled';
            $newStatus = json_encode($currentStatus);
    
            $stmt = $botConn->prepare("UPDATE marzhelp_admin_settings SET status = ? WHERE admin_id = ?");
            $stmt->bind_param("si", $newStatus, $adminId);
            $stmt->execute();
            $stmt->close();
    
            $adminInfo = getAdminInfo($adminId, $userId); 
            $adminInfo['adminId'] = $adminId;
            $adminInfo['status'] = $currentStatus['users']; 
    
            sendRequest('deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $messageId
            ]);
    
            $infoText = getAdminInfoText($adminInfo, $userId);
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $infoText,
                'parse_mode' => 'Markdown',
                'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
            ]);
        } catch (Exception $e) {
            sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => sprintf($lang['disable_users_error'], $e->getMessage())
            ]);
        }
        return;
    }
    
    if (strpos($data, 'enable_users:') === 0) {
        $adminId = intval(substr($data, strlen('enable_users:')));
        global $marzbanConn, $botConn, $marzbanapi;
    
        $adminInfo = getAdminInfo($adminId, $userId);
        if (!$adminInfo || !isset($adminInfo['username'])) {
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['callbackResponse_adminNotFound']
            ]);
            return;
        }
        $adminUsername = $adminInfo['username'];
    
        try {
            $marzbanapi->activateAllDisabledUsers($adminUsername);
    
            $stmt = $botConn->prepare("SELECT status FROM marzhelp_admin_settings WHERE admin_id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $result = $stmt->get_result();
            $currentStatus = json_decode($result->fetch_assoc()['status'], true) ?? ['time' => 'active', 'data' => 'active', 'users' => 'disabled'];
            $stmt->close();
    
            $currentStatus['users'] = 'active';
            $newStatus = json_encode($currentStatus);
    
            $stmt = $botConn->prepare("UPDATE marzhelp_admin_settings SET status = ? WHERE admin_id = ?");
            $stmt->bind_param("si", $newStatus, $adminId);
            $stmt->execute();
            $stmt->close();
    
            $adminInfo = getAdminInfo($adminId, $userId); 
            $adminInfo['adminId'] = $adminId;
            $adminInfo['status'] = $currentStatus['users']; 
    
            sendRequest('deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $messageId
            ]);
    
            $infoText = getAdminInfoText($adminInfo, $userId);
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $infoText,
                'parse_mode' => 'Markdown',
                'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
            ]);
        } catch (Exception $e) {
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => sprintf($lang['enable_users_error'], $e->getMessage())
            ]);
        }
        return;
    }
    if (strpos($data, 'limit_inbounds:') === 0) {
        logDebug("Starting limit_inbounds with data: $data");
        $adminId = intval(substr($data, strlen('limit_inbounds:')));
        $adminInfo = getAdminInfo($adminId, $userId);

        if (!$adminInfo || !isset($adminInfo['username'])) {
            logDebug("Invalid admin info for adminId: $adminId");
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['invalid_operation'],
                'show_alert' => false
            ]);
            return;
        }

        sendRequest('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);

        $cacheFile = 'ad_cache.txt';
        $cacheTimeFile = 'ad_cache_time.txt';
        $cacheLifetime = 24 * 60 * 60;
        $adText = null;

        if (file_exists($cacheFile) && file_exists($cacheTimeFile)) {
            $cacheTime = (int) file_get_contents($cacheTimeFile);
            if (time() - $cacheTime < $cacheLifetime) {
                $adText = file_get_contents($cacheFile);
                logDebug("Ad text loaded from cache: " . $adText);
            }
        }

        if ($adText === null) {
            $rawUrl = "https://raw.githubusercontent.com/smorad3363/marzhelp/dev/ad_text.txt";
            $response = @file_get_contents($rawUrl);
            if ($response !== false) {
                $adText = $response;
                file_put_contents($cacheFile, $adText);
                file_put_contents($cacheTimeFile, time());
                logDebug("Ad text fetched from GitHub and cached: " . $adText);
            }
        }

        if ($adText !== null) {
            $adResult = sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $adText
            ]);
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => 'توجه! پیام بالا دارای محتوای اسپانسری است. دسترسی شما به بخش محدودیت‌ها پس از گذشت ۵ ثانیه امکان‌پذیر خواهد بود.'
            ]);
            sleep(5);
        } else {
            logDebug("Failed to fetch ad text from GitHub, skipping sponsor message");
        }

        try {
            $inboundsData = $marzbanapi->getInbounds();
            $inbounds = [];
            foreach ($inboundsData as $protocol => $inboundList) {
                foreach ($inboundList as $inbound) {
                    if (isset($inbound['tag'])) {
                        $inbounds[] = $inbound['tag'];
                    }
                }
            }
            logDebug("Fetched inbounds from API: " . json_encode($inbounds));
        } catch (Exception $e) {
            logDebug("Error fetching inbounds from API: " . $e->getMessage());
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['error_fetching_inbounds'],
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(getMainMenuKeyboard($userId, $lang))
            ]);
            return;
        }

        $limitsResult = $marzbanConn->query("SELECT type, inbound_tag FROM marzhelp_limits WHERE admin_id = $adminId");
        if (!$limitsResult) {
            logDebug("Error in query SELECT from marzhelp_limits: " . $marzbanConn->error);
            return;
        }
        $limits = [];
        while ($row = $limitsResult->fetch_assoc()) {
            $limits[$row['inbound_tag']] = $row['type'];
        }
        logDebug("Fetched limits: " . json_encode($limits));

        $inboundButtons = [];
        foreach ($inbounds as $inbound) {
            $type = isset($limits[$inbound]) ? $limits[$inbound] : null;
            $emoji = $type == 'exclude' ? '🚫' : ($type == 'dedicated' ? '🔒' : '');
            $inboundButtons[] = [
                'text' => $emoji . $inbound,
                'callback_data' => 'toggle_inbound:' . $adminId . ':' . $inbound
            ];
        }
        $inboundRows = array_chunk($inboundButtons, 2);

        $keyboard = array_merge(
            $inboundRows,
            [
                [
                    ['text' => $lang['set_event_time'], 'callback_data' => 'set_event_time:' . $adminId]
                ],
                [
                    ['text' => $lang['next_step_button'], 'callback_data' => 'confirm_inbounds_limit:' . $adminId],
                    ['text' => $lang['back'], 'callback_data' => 'back_to_admin_management:' . $adminId]
                ]
            ]
        );

        logDebug("Generated keyboard: " . json_encode($keyboard));

        $result = sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $lang['limitInbounds_info'],
            'reply_markup' => ['inline_keyboard' => $keyboard]
        ]);
        logDebug("sendRequest result for limit_inbounds: " . json_encode($result));
        return;
    }

    if (strpos($data, 'set_event_time:') === 0) {
        logDebug("Setting event time with data: $data");
        $adminId = intval(substr($data, strlen('set_event_time:')));

        $currentInterval = 10;
        $intervalResult = $marzbanConn->query(
            "SELECT setting_value
             FROM marzhelp_runtime_settings
             WHERE setting_name = 'inbound_sync_interval'"
        );
        if ($intervalResult && ($intervalRow = $intervalResult->fetch_assoc())) {
            $currentInterval = (int)$intervalRow['setting_value'];
        }

        $intervals = [1, 3, 5, 10, 30, 60];
        $intervalButtons = [];
        foreach ($intervals as $interval) {
            $emoji = $interval == $currentInterval ? '✅' : '';
            $intervalButtons[] = [
                'text' => $emoji . $interval . ' ثانیه',
                'callback_data' => 'set_interval:' . $adminId . ':' . $interval
            ];
        }
        $intervalRows = array_chunk($intervalButtons, 2);

        $keyboard = array_merge(
            $intervalRows,
            [
                [
                    ['text' => $lang['back'], 'callback_data' => 'limit_inbounds:' . $adminId]
                ]
            ]
        );

        $result = sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['select_event_time'],
            'reply_markup' => ['inline_keyboard' => $keyboard]
        ]);
        logDebug("sendRequest result for set_event_time: " . json_encode($result));
        return;
    }

    if (strpos($data, 'set_interval:') === 0) {
        logDebug("Setting interval with data: $data");
        list(, $adminId, $interval) = explode(':', $data);
        $interval = intval($interval);

        manageEventBasedOnLimits($interval);

        try {
            $inboundsData = $marzbanapi->getInbounds();
            $inbounds = [];
            foreach ($inboundsData as $protocol => $inboundList) {
                foreach ($inboundList as $inbound) {
                    if (isset($inbound['tag'])) {
                        $inbounds[] = $inbound['tag'];
                    }
                }
            }
            logDebug("Fetched inbounds from API: " . json_encode($inbounds));
        } catch (Exception $e) {
            logDebug("Error fetching inbounds from API: " . $e->getMessage());
            sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $lang['error_fetching_inbounds'],
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(getMainMenuKeyboard($userId, $lang))
            ]);
            return;
        }

        $limitsResult = $marzbanConn->query("SELECT type, inbound_tag FROM marzhelp_limits WHERE admin_id = $adminId");
        if (!$limitsResult) {
            logDebug("Error in query SELECT from marzhelp_limits: " . $marzbanConn->error);
            return;
        }
        $limits = [];
        while ($row = $limitsResult->fetch_assoc()) {
            $limits[$row['inbound_tag']] = $row['type'];
        }

        $inboundButtons = [];
        foreach ($inbounds as $inbound) {
            $type = isset($limits[$inbound]) ? $limits[$inbound] : null;
            $emoji = $type == 'exclude' ? '🚫' : ($type == 'dedicated' ? '🔒' : '');
            $inboundButtons[] = [
                'text' => $emoji . $inbound,
                'callback_data' => 'toggle_inbound:' . $adminId . ':' . $inbound
            ];
        }
        $inboundRows = array_chunk($inboundButtons, 2);

        $keyboard = array_merge(
            $inboundRows,
            [
                [
                    ['text' => $lang['set_event_time'], 'callback_data' => 'set_event_time:' . $adminId]
                ],
                [
                    ['text' => $lang['next_step_button'], 'callback_data' => 'confirm_inbounds_limit:' . $adminId],
                    ['text' => $lang['back'], 'callback_data' => 'back_to_admin_management:' . $adminId]
                ]
            ]
        );

        $result = sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['limitInbounds_info'],
            'reply_markup' => ['inline_keyboard' => $keyboard]
        ]);

        sendRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $lang['event_time_set'],
            'show_alert' => true
        ]);
        return;
    }

    if (strpos($data, 'toggle_inbound:') === 0) {
        logDebug("Toggling inbound with data: $data");
        list(, $adminId, $inboundTag) = explode(':', $data);

        $adminInfo = getAdminInfo($adminId, $userId);
        if (!$adminInfo || !isset($adminInfo['username'])) {
            logDebug("Invalid admin info for toggle_inbound");
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['invalid_operation'],
                'show_alert' => false
            ]);
            return;
        }

        $inboundTag = $marzbanConn->real_escape_string($inboundTag);

        $limitResult = $marzbanConn->query("SELECT type FROM marzhelp_limits WHERE admin_id = $adminId AND inbound_tag = '$inboundTag'");
        if (!$limitResult) {
            logDebug("Error in query SELECT from marzhelp_limits: " . $marzbanConn->error);
            return;
        }

        if ($limitResult->num_rows > 0) {
            $currentType = $limitResult->fetch_assoc()['type'];
            if ($currentType == 'exclude') {
                $marzbanConn->query("UPDATE marzhelp_limits SET type = 'dedicated' WHERE admin_id = $adminId AND inbound_tag = '$inboundTag'");
            } else {
                $deleteLimit = $marzbanConn->prepare(
                    'DELETE FROM marzhelp_limits WHERE admin_id = ? AND inbound_tag = ?'
                );
                $deleteLimit->bind_param('is', $adminId, $inboundTag);
                $deleteLimit->execute();
                $deleteLimit->close();
            }
        } else {
            $marzbanConn->query("INSERT INTO marzhelp_limits (type, admin_id, inbound_tag) VALUES ('exclude', $adminId, '$inboundTag')");
        }

        manageEventBasedOnLimits();

        try {
            $inboundsData = $marzbanapi->getInbounds();
            $inbounds = [];
            foreach ($inboundsData as $protocol => $inboundList) {
                foreach ($inboundList as $inbound) {
                    if (isset($inbound['tag'])) {
                        $inbounds[] = $inbound['tag'];
                    }
                }
            }
            logDebug("Fetched inbounds from API: " . json_encode($inbounds));
        } catch (Exception $e) {
            logDebug("Error fetching inbounds from API: " . $e->getMessage());
            sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $lang['error_fetching_inbounds'],
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(getMainMenuKeyboard($userId, $lang))
            ]);
            return;
        }

        $limitsResult = $marzbanConn->query("SELECT type, inbound_tag FROM marzhelp_limits WHERE admin_id = $adminId");
        if (!$limitsResult) {
            logDebug("Error in query SELECT from marzhelp_limits: " . $marzbanConn->error);
            return;
        }
        $limits = [];
        while ($row = $limitsResult->fetch_assoc()) {
            $limits[$row['inbound_tag']] = $row['type'];
        }

        $inboundButtons = [];
        foreach ($inbounds as $inbound) {
            $type = isset($limits[$inbound]) ? $limits[$inbound] : null;
            $emoji = $type == 'exclude' ? '🚫' : ($type == 'dedicated' ? '🔒' : '');
            $inboundButtons[] = [
                'text' => $emoji . $inbound,
                'callback_data' => 'toggle_inbound:' . $adminId . ':' . $inbound
            ];
        }
        $inboundRows = array_chunk($inboundButtons, 2);

        $keyboard = array_merge(
            $inboundRows,
            [
                [
                    ['text' => $lang['set_event_time'], 'callback_data' => 'set_event_time:' . $adminId]
                ],
                [
                    ['text' => $lang['next_step_button'], 'callback_data' => 'confirm_inbounds_limit:' . $adminId],
                    ['text' => $lang['back'], 'callback_data' => 'back_to_admin_management:' . $adminId]
                ]
            ]
        );

        $result = sendRequest('editMessageReplyMarkup', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => ['inline_keyboard' => $keyboard]
        ]);
        logDebug("sendRequest result for toggle_inbound: " . json_encode($result));
        return;
    }

    if (strpos($data, 'confirm_inbounds_limit:') === 0) {
        logDebug("Confirming inbounds with data: $data");
        $adminId = intval(substr($data, strlen('confirm_inbounds_limit:')));

        manageEventBasedOnLimits();

        sendRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $lang['limits_updated'],
            'show_alert' => true
        ]);
        return;
    }
    if (strpos($data, 'add_protocol:') === 0) {
        $adminId = intval(substr($data, strlen('add_protocol:')));
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['add_protocol_prompt'],
            'reply_markup' => getProtocolSelectionKeyboard($adminId, 'select_add_protocol', $userId)
        ]);
        return;
    }
    if (strpos($data, 'remove_protocol:') === 0) {
        $adminId = intval(substr($data, strlen('remove_protocol:')));
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['remove_protocol_prompt'],
            'reply_markup' => getProtocolSelectionKeyboard($adminId, 'select_remove_protocol', $userId)
        ]);
        return;
    }
    if (strpos($data, 'select_add_protocol:') === 0) {
        list(, $protocol, $adminId) = explode(':', $data);
    
        $stmt = $marzbanConn->prepare("SELECT username FROM admins WHERE id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $adminResult = $stmt->get_result();
        $stmt->close();
    
        if ($adminResult->num_rows === 0) {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['callbackResponse_adminNotFound'],
                'show_alert' => false
            ]);
            return;
        }
    
        $adminRow = $adminResult->fetch_assoc();
        $adminUsername = $marzbanConn->real_escape_string($adminRow['username']); 

        $marzbanConn->query("SET foreign_key_checks = 0");
    
        $stmt = $marzbanConn->prepare("
            INSERT INTO proxies (user_id, type, settings)
            SELECT users.id, ?, CONCAT('{\"id\": \"', CONVERT(UUID(), CHAR), '\"}') 
            FROM users 
            INNER JOIN admins ON users.admin_id = admins.id 
            WHERE admins.username = ? 
            AND NOT EXISTS (
                SELECT 1 FROM proxies 
                WHERE proxies.user_id = users.id 
                AND proxies.type = ?
            );
        ");
        $stmt->bind_param("sss", $protocol, $adminUsername, $protocol);
    
        if ($stmt->execute()) {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['protocol_added'],
                'show_alert' => false
            ]);
        } else {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['protocol_add_error'],
                'show_alert' => false
            ]);
        }
        $stmt->close();
    
        $marzbanConn->query("SET foreign_key_checks = 1");
    
        $adminInfo = getAdminInfo($adminId, $userId);
        $adminInfo['adminId'] = $adminId;
        $infoText = getAdminInfoText($adminInfo, $userId);
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $infoText,
            'parse_mode' => 'Markdown',
            'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
        ]);
    
        return;
    }
    if (strpos($data, 'select_remove_protocol:') === 0) {
        list(, $protocol, $adminId) = explode(':', $data);
    
        $stmt = $marzbanConn->prepare("SELECT username FROM admins WHERE id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $adminResult = $stmt->get_result();
        $stmt->close();
    
        if ($adminResult->num_rows === 0) {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['callbackResponse_adminNotFound'],
                'show_alert' => false
            ]);
            return;
        }
    
        $adminRow = $adminResult->fetch_assoc();
        $adminUsername = $marzbanConn->real_escape_string($adminRow['username']); 
        $marzbanConn->query("SET foreign_key_checks = 0");

        $stmt = $marzbanConn->prepare("
            DELETE FROM proxies
            WHERE type = ? 
              AND user_id IN (
                SELECT users.id
                FROM users
                INNER JOIN admins ON users.admin_id = admins.id
                WHERE admins.username = ?
              );
        ");
        $stmt->bind_param("ss", $protocol, $adminUsername);
    
        if ($stmt->execute()) {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['protocol_removed'],
                'show_alert' => false
            ]);
        } else {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['protocol_remove_error'],
                'show_alert' => false
            ]);
        }
        $stmt->close();
    
        $marzbanConn->query("SET foreign_key_checks = 1");
    
        $adminInfo = getAdminInfo($adminId, $userId);
        $adminInfo['adminId'] = $adminId;
        $infoText = getAdminInfoText($adminInfo, $userId);
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $infoText,
            'parse_mode' => 'Markdown',
            'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
        ]);
    
        return;
    }
    if (strpos($data, 'add_data_limit:') === 0) {
        $adminId = intval(substr($data, strlen('add_data_limit:')));
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['setTraffic_prompt'],
            'reply_markup' => getBackToAdminManagementKeyboard($adminId, $userId)
        ]);
        handleUserState('set', $userId, 'add_data_limit', $adminId);
        return;
    }
    if (strpos($data, 'subtract_data_limit:') === 0) {
        $adminId = intval(substr($data, strlen('subtract_data_limit:')));
        
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['reduceVolume_prompt'],
            'reply_markup' => getBackToAdminManagementKeyboard($adminId, $userId)
        ]);
        handleUserState('set', $userId, 'subtract_data_limit', $adminId);
        return;
    }
    if (strpos($data, 'security:') === 0) {
        $adminId = intval(substr($data, strlen('security:')));
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['security_settings'],
            'reply_markup' => getSecurityKeyboard($adminId, $userId)
        ]);
        return;
    }
    if (strpos($data, 'change_password:') === 0) {
        $adminId = intval(substr($data, strlen('change_password:')));
        handleUserState('set', $userId, 'set_new_password', $adminId);

        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['enter_new_password'],
            'reply_markup' => getBackToAdminManagementKeyboard($adminId, $userId)
        ]);
        handleUserState('set', $userId, 'set_new_password', $adminId);
        return;
    }
    if (strpos($data, 'change_sudo:') === 0) {
        $adminId = intval(substr($data, strlen('change_sudo:')));
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['sudo_confirmation'],
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => $lang['confirm_yes_button'], 'callback_data' => 'set_sudo_yes:' . $adminId],
                        ['text' => $lang['confirm_no_button'], 'callback_data' => 'set_sudo_no:' . $adminId]
                    ],
                    [
                        ['text' => $lang['back'], 'callback_data' => 'security:' . $adminId]
                    ]
                ]
            ]
        ]);
        return;
    }
    if (strpos($data, 'set_sudo_yes:') === 0) {
        $adminId = intval(substr($data, strlen('set_sudo_yes:')));
        $marzbanConn->query("UPDATE admins SET is_sudo = 1 WHERE id = '$adminId'");
        $adminInfo = getAdminInfo($adminId, $userId);
        $adminInfo['adminId'] = $adminId;
        $infoText = getAdminInfoText($adminInfo, $userId);
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['sudo_enabled'],
            'parse_mode' => 'Markdown',
            'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
        ]);
        return;
    }
    if (strpos($data, 'set_sudo_no:') === 0) {
        $adminId = intval(substr($data, strlen('set_sudo_no:')));
        $marzbanConn->query("UPDATE admins SET is_sudo = 0 WHERE id = '$adminId'");
        $adminInfo = getAdminInfo($adminId, $userId);
        $adminInfo['adminId'] = $adminId;
        $infoText = getAdminInfoText($adminInfo, $userId);
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['sudo_disabled'],
        ]);
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $infoText,
            'parse_mode' => 'Markdown',
            'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
        ]);

        return;
    }
    if (strpos($data, 'change_telegram_id:') === 0) {
        $adminId = intval(substr($data, strlen('change_telegram_id:')));
        handleUserState('set', $userId, 'set_new_telegram_id', $adminId);

        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['enterNewTelegramId_prompt'],
            'reply_markup' => getBackToAdminManagementKeyboard($adminId, $userId)
        ]);
        handleUserState('set', $userId, 'set_new_telegram_id', $adminId);
        return;
    }
    if (strpos($data, 'change_username:') === 0) {
        $adminId = intval(substr($data, strlen('change_username:')));
        handleUserState('set', $userId, 'set_new_username', $adminId);

        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['username_prompt'],
            'reply_markup' => getBackToAdminManagementKeyboard($adminId, $userId)
        ]);
        handleUserState('set', $userId, 'set_new_username', $adminId);
        return;
    }
    if ($data === 'add_admin') {
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['add_admin_prompt'],
            'reply_markup' => getbacktoadminselectbutton($userId)
        ]);
        if (isset($response['result']['message_id'])) {
            $promptMessageId = $response['result']['message_id'];
        } else {
            $promptMessageId = $messageId;
        }
        $stateset = 'waiting_for_username';

        handleUserState('set', $userId, $stateset);


        return;
    }
    if ($data === 'generate_random_password') {
        $generatedPassword = generateRandomPassword(12);
        $hashedPassword = password_hash($generatedPassword, PASSWORD_BCRYPT);
        
        handleTemporaryData('set', $userId, 'new_admin_password', $hashedPassword);
        handleTemporaryData('set', $userId, 'new_admin_password_nothashed', $generatedPassword);
        
        $textpass = $lang['sudo_confirmation'] . "\n\n" . $lang['password_generated'] . " `$generatedPassword`";
        
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $textpass,
            'parse_mode' => 'Markdown',
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => $lang['confirm_yes_button'], 'callback_data' => 'sudo_yes'],
                        ['text' => $lang['confirm_no_button'], 'callback_data' => 'sudo_no']
                    ],
                    [
                        ['text' => $lang['back'], 'callback_data' => 'manage_admins']
                    ]
                ]
            ]
        ]);
        if (isset($response['result']['message_id'])) {
            $promptMessageId = $response['result']['message_id'];
        } else {
            $promptMessageId = $messageId;
        }
        $stateset = 'waiting_for_sudo';

        handleUserState('set', $userId, $stateset);

        return;
    }
    if ($data === 'sudo_yes') {

    handleTemporaryData('set', $userId, 'new_admin_sudo', 1);
        
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $lang['telegram_id_prompt'],
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => 'Skip', 'callback_data' => 'skip_telegram_id']
                    ],
                    [
                        ['text' => $lang['back'], 'callback_data' => 'manage_admins']
                    ]
                ]
            ]
        ]);
        if (isset($response['result']['message_id'])) {
            $promptMessageId = $response['result']['message_id'];
        } else {
            $promptMessageId = $messageId;
        }
        $stateset = 'waiting_for_telegram_id';

        handleUserState('set', $userId, $stateset);

        return;
    }
    if ($data === 'sudo_no') {
        
        handleTemporaryData('set', $userId, 'new_admin_sudo', 0);
        
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
             'text' => $lang['telegram_id_prompt'],
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => 'Skip', 'callback_data' => 'skip_telegram_id']
                    ],
                    [
                        ['text' => $lang['back'], 'callback_data' => 'manage_admins']
                    ]
                ]
            ]
        ]);
        if (isset($response['result']['message_id'])) {
            $promptMessageId = $response['result']['message_id'];
        } else {
            $promptMessageId = $messageId;
        }
        $stateset = 'waiting_for_telegram_id';
        handleUserState('set', $userId, $stateset);
        return;
    }
    if ($data === 'skip_telegram_id') {

        handleTemporaryData('set', $userId, 'new_admin_telegram_id', 0);

        
        createAdmin($userId, $chatId);
        return;
    }
    if (strpos($data, 'set_lang_') === 0) {
            $selectedLang = substr($data, 9); 
            
            $stmt = $botConn->prepare("UPDATE marzhelp_user_states SET lang = ? WHERE user_id = ?");
            $stmt->bind_param("si", $selectedLang, $userId);
            $stmt->execute();
        
            $confirmMessages = [
                'fa' => 'زبان شما با موفقیت تنظیم شد. لطفاً دستور /start را دوباره ارسال کنید.',
                'en' => 'Your language has been successfully set. Please send the /start command again.',
                'ru' => 'Ваш язык успешно установлен. Пожалуйста, отправьте команду /start снова.'
            ];
        
            $confirmationMessage = $confirmMessages[$selectedLang] ?? $confirmMessages['en'];

            $promptMessageId = $userState['message_id'];

            sendRequest('deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $promptMessageId
            ]);
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $confirmationMessage
            ]);
            return;
        }
        if ($data === 'account_info') {
            $adminInfo = getAdminInfo($userId); 
            $lang = getLang($userId); 
        
            $stmt = $botConn->prepare("SELECT username, updated_at, lang, message_id FROM marzhelp_user_states WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $username = null;
            $updated_at = null;
            $language = null;
            $promptMessageId = null;
            if ($row = $result->fetch_assoc()) {
                $username = $row['username'];
                $updated_at = $row['updated_at'];
                $language = $row['lang'];
                $promptMessageId = $row['message_id'];
            }
            
            $stmt->close();
            
            sendRequest('deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $promptMessageId
            ]);
            
            $infoText = "🧸 **User ID :** `$userId`\n";
            $infoText .= "🧸 **UserName :** @\n"; 
            $infoText .= "📅 **Latest changes :** `$updated_at`\n"; 
            $infoText .= "🌐 **Current language :** `$language`\n"; 
        
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $infoText,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '🔄 change language', 'callback_data' => 'change_language'],
                            ['text' => $lang['back'], 'callback_data' => 'back_to_main']
                        ]
                    ]
                ])
            ]);
        }
        if ($data === 'change_language') {
            
            $stmt = $botConn->prepare("SELECT username, updated_at, lang, message_id FROM marzhelp_user_states WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $username = null;
            $updated_at = null;
            $language = null;
            $promptMessageId = null;
            if ($row = $result->fetch_assoc()) {
                $username = $row['username'];
                $updated_at = $row['updated_at'];
                $language = $row['lang'];
                $promptMessageId = $row['message_id'];
            }
            
            $stmt->close();

            $langSelectionText = "Please select your language:\nПожалуйста, выберите язык:\nلطفاً زبان خود را انتخاب کنید:";

            sendRequest('deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $promptMessageId
            ]);
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $langSelectionText,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '🇮🇷 فارسی', 'callback_data' => 'set_lang_fa'],
                            ['text' => '🇬🇧 English', 'callback_data' => 'set_lang_en'],
                            ['text' => '🇷🇺 Русский', 'callback_data' => 'set_lang_ru']
                        ],
                        [
                            ['text' => $lang['back'], 'callback_data' => 'account_info']
                        ]
                    ]
                ])
            ]);
        }
        if ($data === 'settings') {
            sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $lang['settings_menu'] . "\n🟢 Bot version: " . $latestVersion,
                'reply_markup' => json_encode(getSettingsMenuKeyboard($userId))
            ]);
        
            return;
        }
        if ($data === 'update_bot') {
            sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $lang['update_in_progress']
            ]);
        
            $command = "bash /var/www/html/marzhelp/update.sh 2>&1";
            exec($command, $output, $return_var);
        
            if ($return_var === 0) {
                sendRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $userState['message_id']]);
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['update_success'] . " $latestVersion"
                ]);
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['settings_menu'] . "\n🟢 Bot version: " . $latestVersion,
                    'reply_markup' => json_encode(getSettingsMenuKeyboard($userId))
                ]);
            } else {
                error_log("MarzHelp update failed: " . implode("\n", $output));
                sendRequest('deleteMessage', ['chat_id' => $chatId, 'message_id' => $userState['message_id']]);
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['update_failed']
                ]);
            }
        
            return;
        }
        if ($data === 'backup') {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => $lang['marzhelp_backup'], 'callback_data' => 'marzhelp_backup'],
                        ['text' => $lang['marzban_backup'], 'callback_data' => 'marzban_backup']
                    ],
                    [
                        ['text' => $lang['back'], 'callback_data' => 'settings']
                    ]
                ]
            ];
        
           /* sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $lang['backup_settings'],
                'reply_markup' => $keyboard
            ]); */
        
            sendRequest('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $userState['message_id'],
                'text' => 'This option is not available.'
            ]);
        
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['settings_menu'] . "\n🟢 Bot version: " . $latestVersion,
                'reply_markup' => json_encode(getSettingsMenuKeyboard($userId))
            ]);
        }
        
        
        if ($data === 'update_marzban') {

            $command = 'sudo /usr/local/bin/marzban update 2>&1';
            $output = shell_exec($command);
            
              $outputText = $output ?? '';
            
              file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - Marzban update output:\n" . $outputText . "\n", FILE_APPEND);
            
    
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $userState['message_id'],
            'text' => /*'This option is not available.'*/ $lang['marzban_update_success']
        ]);
    
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $lang['settings_menu'] . "\n🟢 Bot version: " . $latestVersion,
            'reply_markup' => json_encode(getSettingsMenuKeyboard($userId))
        ]);
    }

    if ($data === 'restart_marzban') {

    $command = 'sudo marzban restart > /dev/null 2>&1 &';

    exec($command, $output, $return_var);

    $outputText = implode("\n", $output);

    file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - Marzban restart output:\n" . $outputText . "\n", FILE_APPEND);


    sendRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $userState['message_id'],
        'text' => $lang['marzban_restart_success']
    ]);

    sendRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => $lang['settings_menu'] . "\n🟢 Bot version: " . $latestVersion,
        'reply_markup' => json_encode(getSettingsMenuKeyboard($userId))
    ]);
}
if (strpos($data, 'change_template') === 0) {

    sendRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $userState['message_id'],
        'text' => '🥺این بخش درحال حاضر غیرفعال میباشد.'
    ]);
    
    sendRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => $lang['settings_menu'] . "\n🟢 Bot version: " . $latestVersion,
        'reply_markup' => json_encode(getSettingsMenuKeyboard($userId))
    ]);

    $templates = [
        [
            'image' => 'screenshot.jpg',
            'command' => 'sudo wget -N -P /var/lib/marzban/templates/subscription/ https://raw.githubusercontent.com/x0sina/marzban-sub/main/index1.html'
        ],
        [
            'image' => 'screenshot.jpg',
            'command' => 'sudo wget -N -P /var/lib/marzban/templates/subscription/ https://raw.githubusercontent.com/x0sina/marzban-sub/main/index2.html'
        ],
    ];

    $currentIndex = 0;
    $templateCount = count($templates);

   /* sendRequest('sendPhoto', [
        'chat_id' => $chatId,
        'photo' => $templates[$currentIndex]['image'],
        'caption' => sprintf($lang['template_caption'], $currentIndex + 1, $templateCount),
        'reply_markup' => json_encode(getTemplateMenuKeyboard($currentIndex, $templateCount, $userId))  
    ]);
    */
    return;
}
if (strpos($data, 'template_') === 0) {
    $templates = [
        [
            'image' => 'screenshot.jpg',
            'command' => 'sudo wget -N -P /var/lib/marzban/templates/subscription/ https://raw.githubusercontent.com/x0sina/marzban-sub/main/index1.html'
        ],
        [
            'image' => 'screenshot.jpg',
            'command' => 'sudo wget -N -P /var/lib/marzban/templates/subscription/ https://raw.githubusercontent.com/x0sina/marzban-sub/main/index2.html'
        ],
    ];

    $currentIndex = getUserTemplateIndex($userId);
    $templateCount = count($templates);

    if ($data === 'template_next') {
        $currentIndex = ($currentIndex + 1) % $templateCount;
    } elseif ($data === 'template_prev') {
        $currentIndex = ($currentIndex - 1 + $templateCount) % $templateCount;
    } elseif ($data === 'apply_template') {
        $command = $templates[$currentIndex]['command'];
        exec($command, $output, $status);

        sendRequest('answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => $status === 0 ? $lang['template_applied'] : $lang['template_error'],
            'show_alert' => true
        ]);

        return;
    }

    sendRequest('editMessageMedia', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'media' => [
            'type' => 'photo',
            'media' => $templates[$currentIndex]['image'],
            'caption' => sprintf($lang['template_caption'], $currentIndex + 1, $templateCount)
        ],
        'reply_markup' => getTemplateMenuKeyboard($currentIndex, $templateCount, $userId)
    ]);

    return;
}
if (strpos($data, 'disable_users_') === 0) {
    $adminId = str_replace('disable_users_', '', $data);
    if (in_array($userId, $allowedUsers)) {
        global $marzbanConn, $botConn, $marzbanapi;

        $adminInfo = getAdminInfo($adminId, $userId);
        if (!$adminInfo || !isset($adminInfo['username'])) {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['callbackResponse_adminNotFound'],
                'show_alert' => true
            ]);
            return;
        }
        $adminUsername = $adminInfo['username'];

        try {
            $marzbanapi->disableAllActiveUsers($adminUsername);

            $stmt = $botConn->prepare("SELECT status, hashed_password_before FROM marzhelp_admin_settings WHERE admin_id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $currentStatus = json_decode($row['status'], true) ?? ['time' => 'active', 'data' => 'active', 'users' => 'active'];
            $currentStatus['hashed_password_before'] = $row['hashed_password_before'];
            $stmt->close();

            $currentStatus['users'] = 'disabled';
            $newStatus = json_encode($currentStatus);

            $stmt = $botConn->prepare("UPDATE marzhelp_admin_settings SET status = ? WHERE admin_id = ?");
            $stmt->bind_param("si", $newStatus, $adminId);
            $stmt->execute();
            $stmt->close();

            $newKeyboard = getAdminExpireKeyboard($adminId, $userId);

            sendRequest('editMessageReplyMarkup', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => json_encode($newKeyboard)
            ]);

            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['users_disabled'],
                'show_alert' => true
            ]);
        } catch (Exception $e) {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => sprintf($lang['disable_users_error'], $e->getMessage()),
                'show_alert' => true
            ]);
        }
    }
    return;
}

if (strpos($data, 'enable_users_') === 0) {
    $adminId = str_replace('enable_users_', '', $data);
    if (in_array($userId, $allowedUsers)) {
        global $marzbanConn, $botConn, $marzbanapi;

        $adminInfo = getAdminInfo($adminId, $userId);
        if (!$adminInfo || !isset($adminInfo['username'])) {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['callbackResponse_adminNotFound'],
                'show_alert' => true
            ]);
            return;
        }
        $adminUsername = $adminInfo['username'];

        try {
            $marzbanapi->activateAllDisabledUsers($adminUsername);

            $stmt = $botConn->prepare("SELECT status, hashed_password_before FROM marzhelp_admin_settings WHERE admin_id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $currentStatus = json_decode($row['status'], true) ?? ['time' => 'active', 'data' => 'active', 'users' => 'disabled'];
            $currentStatus['hashed_password_before'] = $row['hashed_password_before'];
            $stmt->close();

            $currentStatus['users'] = 'active';
            $newStatus = json_encode($currentStatus);

            $stmt = $botConn->prepare("UPDATE marzhelp_admin_settings SET status = ? WHERE admin_id = ?");
            $stmt->bind_param("si", $newStatus, $adminId);
            $stmt->execute();
            $stmt->close();

            $newKeyboard = getAdminExpireKeyboard($adminId, $userId);

            sendRequest('editMessageReplyMarkup', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => json_encode($newKeyboard)
            ]);

            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['users_enabled'],
                'show_alert' => true
            ]);
        } catch (Exception $e) {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => sprintf($lang['enable_users_error'], $e->getMessage()),
                'show_alert' => true
            ]);
        }
    }
    return;
}

if (strpos($data, 'change_password_') === 0) {
    $adminId = str_replace('change_password_', '', $data);
    if (in_array($userId, $allowedUsers)) {
        $stmt = $botConn->prepare("SELECT hashed_password_before, status FROM marzhelp_admin_settings WHERE admin_id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $hashedPasswordBefore = $row['hashed_password_before'];
        $currentStatus = json_decode($row['status'], true) ?? ['time' => 'active', 'data' => 'active', 'users' => 'active'];
        $stmt->close();

        $lang = getLang($userId);

        if (empty($hashedPasswordBefore)) {
            $newPassword = generateRandomPassword();
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

            $stmt = $marzbanConn->prepare("SELECT hashed_password FROM admins WHERE id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $stmt->bind_result($currentPassword);
            $stmt->fetch();
            $stmt->close();

            $stmt = $botConn->prepare("UPDATE marzhelp_admin_settings SET hashed_password_before = ? WHERE admin_id = ?");
            $stmt->bind_param("si", $currentPassword, $adminId);
            $stmt->execute();
            $stmt->close();

            $stmt = $marzbanConn->prepare("UPDATE admins SET hashed_password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashedPassword, $adminId);
            $stmt->execute();
            $stmt->close();

            $newKeyboard = getAdminExpireKeyboard($adminId, $userId);

            sendRequest('editMessageReplyMarkup', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => json_encode($newKeyboard)
            ]);

            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['password_changed'] . " : " . $newPassword,
                'show_alert' => true
            ]);
        } else {
            
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['password_already_changed'], 
                'show_alert' => true
            ]);
        }
    }
    return;
}

if (strpos($data, 'restore_password_') === 0) {
    $adminId = str_replace('restore_password_', '', $data);
    if (in_array($userId, $allowedUsers)) {
        $stmt = $botConn->prepare("SELECT hashed_password_before, status FROM marzhelp_admin_settings WHERE admin_id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $hashedPasswordBefore = $row['hashed_password_before'];
        $currentStatus = json_decode($row['status'], true) ?? ['time' => 'active', 'data' => 'active', 'users' => 'active'];
        $stmt->close();

        $lang = getLang($userId); 

        if ($hashedPasswordBefore) {
            $stmt = $marzbanConn->prepare("UPDATE admins SET hashed_password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashedPasswordBefore, $adminId);
            $stmt->execute();
            $stmt->close();

            $stmt = $botConn->prepare("UPDATE marzhelp_admin_settings SET hashed_password_before = NULL WHERE admin_id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $stmt->close();

            $newKeyboard = getAdminExpireKeyboard($adminId, $userId);

            sendRequest('editMessageReplyMarkup', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'reply_markup' => json_encode($newKeyboard)
            ]);

            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => $lang['password_changed'], 
                'show_alert' => true
            ]);
        } else {
            sendRequest('answerCallbackQuery', [
                'callback_query_id' => $callbackId,
                'text' => 'password not found.', 
                'show_alert' => true
            ]);
        }
    }
    return;
}

if (strpos($data, 'calculate_volume:') === 0) {
    list(, $adminId) = explode(':', $data);
    $adminId = (int)$adminId;

    $keyboard = getCalculateVolumeKeyboard($adminId, $chatId);

    $adminInfo = getAdminInfo($adminId, $userId);
    $adminInfo['adminId'] = $adminId;
    $infoText = getAdminInfoText($adminInfo, $userId);

    sendRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $infoText,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($keyboard)
    ]);

}

if (strpos($data, 'set_calculate_volume:') === 0) {
    list(, $type, $adminId) = explode(':', $data);

    $adminId = (int)$adminId;

    $stmt = $botConn->prepare("UPDATE marzhelp_admin_settings SET calculate_volume = ? WHERE admin_id = ?");
    $stmt->bind_param("si", $type, $adminId);
    $stmt->execute();
    $stmt->close();

    $adminInfo = getAdminInfo($adminId, $userId);
    $adminInfo['adminId'] = $adminId;
    $infoText = getAdminInfoText($adminInfo, $userId);

    $keyboard = getCalculateVolumeKeyboard($adminId, $chatId);

    sendRequest('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $infoText,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode($keyboard)
    ]);
    }
if ($data === 'show_status') {
    generateStatusMessage($marzbanapi, $chatId, $lang, true, $messageId);
    return;
}

if ($data === 'restart_xray') {
    try {
        $marzbanapi->restartCore();

        sendRequest('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $lang['xray_restart_success'],
            'parse_mode' => 'Markdown'
        ]);
        generateStatusMessage($marzbanapi, $chatId, $lang, true);
    } catch (Exception $e) {
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => "Error restarting Xray: {$e->getMessage()}",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(getMainMenuKeyboard($userId, $lang))
        ]);
    }
    return;
}

if ($data === 'marzban_restart') {
    try {
        $command = 'sudo marzban restart > /dev/null 2>&1 &';
        exec($command, $output, $return_var);
        $outputText = implode("\n", $output);
        file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - Marzban restart output:\n" . $outputText . "\n", FILE_APPEND);

        sendRequest('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);
        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $lang['marzban_restart_success'],
            'parse_mode' => 'Markdown'
        ]);

        sleep(30);

        generateStatusMessage($marzbanapi, $chatId, $lang, true);
    } catch (Exception $e) {
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => "Error restarting Marzban: {$e->getMessage()}",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(getMainMenuKeyboard($userId, $lang))
        ]);
    }
    return;
}

if ($data === 'marzban_update') {
    try {
        $command = 'sudo /usr/local/bin/marzban update 2>&1';
        $output = shell_exec($command);
        $outputText = $output ?: "No output from command";
        file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - Marzban update output:\n" . $outputText . "\n", FILE_APPEND);

        sendRequest('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ]);

        sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $lang['marzban_update_success'],
            'parse_mode' => 'Markdown'
        ]);

        sleep(30);

        generateStatusMessage($marzbanapi, $chatId, $lang, true);
    } catch (Exception $e) {
        sendRequest('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => "Error updating Marzban: {$e->getMessage()}",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(getMainMenuKeyboard($userId, $lang))
        ]);
    }
    return;
}
}

    function handleMessage($message) {
        global $botConn, $marzbanConn, $marzbanapi;
    
        $chatId = $message['chat']['id'];
        $text = trim($message['text'] ?? '');
        $userId = $message['from']['id'];

        $lang = getLang($userId);

        $userRole = getUserRole($userId);
    
        if ($userRole === 'unauthorized') {
            file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - Unauthorized user: $userId\n", FILE_APPEND);
            sendRequest('sendMessage', ['chat_id' => $chatId, 'text' => $lang['error_unauthorized']]);
            exit;
        }
    
        $userState = handleUserState('get', $userId);

        if (
            $userRole === 'limited_admin'
            && $userState
            && !empty($userState['admin_id'])
            && !marzhelpCanManageAdmin(
                $marzbanConn,
                (int)$userId,
                $userRole,
                (int)$userState['admin_id']
            )
        ) {
            handleUserState('clear', $userId);
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['error_unauthorized']
            ]);
            return;
        }

        $mainAdminOnlyStates = [
            'waiting_for_username',
            'waiting_for_password',
            'waiting_for_sudo',
            'waiting_for_telegram_id'
        ];
        if (
            $userRole !== 'main_admin'
            && $userState
            && in_array($userState['state'], $mainAdminOnlyStates, true)
        ) {
            handleUserState('clear', $userId);
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['error_unauthorized']
            ]);
            return;
        }

        if ($userState) {

            if (!checkMarzbanConfig()) {
                autoCreateAdmin($chatId);
                return; 
                }

            if ($userState['state'] === 'add_data_limit') {
                $dataLimit = floatval($text); 
                if ($dataLimit > 0) {
                    $adminId = $userState['admin_id'];
                    $promptMessageId = $userState['message_id'];
                    $dataLimitBytes = $dataLimit * 1073741824;
    
                    $bulkResult = modifyAdminUsersViaApi($adminId, 'data_limit', (int)$dataLimitBytes);
                    if ($bulkResult['failed'] === 0) {

                        sendRequest('deleteMessage', [
                            'chat_id' => $chatId,
                            'message_id' => $promptMessageId
                        ]);
    
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['data_limit_added']
                    ]);
    
                    $adminInfo = getAdminInfo($adminId, $userId);
                    $adminInfo['adminId'] = $adminId;
                    $infoText = getAdminInfoText($adminInfo, $userId);
    
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $infoText,
                        'parse_mode' => 'Markdown',
                        'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
                    ]);
    
                handleUserState('clear', $userId);
                } else {
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['operation_failed'] . implode("\n", $bulkResult['errors'])
                    ]);
                }
                    return;
                } else {
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['invalid_input']
                    ]);
                    return;
                }
            }
            if ($userState['state'] === 'subtract_data_limit') {
                $dataLimit = floatval($text); 
                if ($dataLimit > 0) {
                    $dataLimitBytes = $dataLimit * 1073741824;
                    $promptMessageId = $userState['message_id'];
                    $adminId = $userState['admin_id'];

    
                    $bulkResult = modifyAdminUsersViaApi($adminId, 'data_limit', -(int)$dataLimitBytes);
                    if ($bulkResult['failed'] === 0) {
                    $adminId = $userState['admin_id'];
                    $promptMessageId = $userState['message_id'];

                    sendRequest('deleteMessage', [
                        'chat_id' => $chatId,
                        'message_id' => $promptMessageId
                    ]);
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['data_limit_subtracted']
                    ]);
    
                    $adminInfo = getAdminInfo($adminId, $userId);
                    $adminInfo['adminId'] = $adminId;
                    $infoText = getAdminInfoText($adminInfo, $userId);
    
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $infoText,
                        'parse_mode' => 'Markdown',
                        'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
                    ]);
    
                    handleUserState('clear', $userId);
                    } else {
                        sendRequest('sendMessage', [
                            'chat_id' => $chatId,
                            'text' => $lang['operation_failed'] . implode("\n", $bulkResult['errors'])
                        ]);
                    }
            return;
                } else {
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['invalid_input']
                    ]);
                    return;
                }
            }
            if ($userState['state'] === 'set_user_limit') {
                $userLimit = intval($text);
                if ($userLimit > 0) {
                    $adminId = $userState['admin_id'];
                    $promptMessageId = $userState['message_id'];

                    $stmt = $botConn->prepare("INSERT INTO marzhelp_admin_settings (admin_id, user_limit) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_limit = ?");
                    $stmt->bind_param("iii", $adminId, $userLimit, $userLimit);
                    $stmt->execute();
                    $stmt->close();
                    $adminInfo = getAdminInfo($adminId, $userId);
                    $adminInfo['adminId'] = $adminId;
                    $infoText = getAdminInfoText($adminInfo, $userId);

                    sendRequest('deleteMessage', [
                        'chat_id' => $chatId,
                        'message_id' => $promptMessageId
                    ]);

                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['setUserLimit_success'],
                    ]);
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $infoText,
                        'parse_mode' => 'Markdown',
                        'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
                    ]);

                    handleUserState('clear', $userId);

                } else {
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['invalid_input']
                    ]);
                }
                return;
            }
            if ($userState['state'] === 'add_time') {
                $days = intval($text);
                if ($days > 0) {
                    $adminId = $userState['admin_id'];
                    $secondsToAdd = 86400 * $days;
                    $promptMessageId = $userState['message_id'];

                    $bulkResult = modifyAdminUsersViaApi($adminId, 'expire', $secondsToAdd);
                    if ($bulkResult['failed'] === 0) {

                        sendRequest('deleteMessage', [
                            'chat_id' => $chatId,
                            'message_id' => $promptMessageId
                        ]);
    
                        sendRequest('sendMessage', [
                            'chat_id' => $chatId,
                            'text' => $lang['setExpiryDays_success']
                        ]);
                    } else {
                        sendRequest('sendMessage', [
                            'chat_id' => $chatId,
                            'text' => $lang['operation_failed'] . implode("\n", $bulkResult['errors'])
                        ]);
                    }
    
                    $adminInfo = getAdminInfo($adminId, $userId);
                    $adminInfo['adminId'] = $adminId;
                    $infoText = getAdminInfoText($adminInfo, $userId);
    
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $infoText,
                        'parse_mode' => 'Markdown',
                        'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
                    ]);
    
                    handleUserState('clear', $userId);

                    return;
                } else {
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['invalid_input']
                    ]);
                    return;
                }
            }
            if ($userState['state'] === 'set_max_duration') {
                $durationDays = intval($text);
                if ($durationDays > 0) {
                    $adminId = (int)$userState['admin_id'];
                    $stmt = $botConn->prepare(
                        "INSERT INTO marzhelp_admin_settings (admin_id, max_user_duration_days) VALUES (?, ?) " .
                        "ON DUPLICATE KEY UPDATE max_user_duration_days = VALUES(max_user_duration_days)"
                    );
                    $stmt->bind_param('ii', $adminId, $durationDays);
                    $stmt->execute();
                    $stmt->close();
                    handleUserState('clear', $userId);
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['max_duration_saved'],
                        'reply_markup' => getBackToAdminManagementKeyboard($adminId, $userId)
                    ]);
                } else {
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['invalid_input']
                    ]);
                }
                return;
            }
            if ($userState['state'] === 'reduce_time') {
                $days = intval($text);
                if ($days > 0) {
                    $adminId = $userState['admin_id'];
                    $secondsToReduce = 86400 * $days;
                    $promptMessageId = $userState['message_id'];
    
                    $bulkResult = modifyAdminUsersViaApi($adminId, 'expire', -$secondsToReduce);
                    if ($bulkResult['failed'] === 0) {

                        sendRequest('deleteMessage', [
                            'chat_id' => $chatId,
                            'message_id' => $promptMessageId
                        ]);
    
                        sendRequest('deleteMessage', [
                            'chat_id' => $chatId,
                            'message_id' => $promptMessageId
                        ]);
                        sendRequest('sendMessage', [
                            'chat_id' => $chatId,
                            'text' => $lang['reduceExpiryDays_success']
                        ]);
                    } else {
                        sendRequest('sendMessage', [
                            'chat_id' => $chatId,
                            'text' => $lang['operation_failed'] . implode("\n", $bulkResult['errors'])
                        ]);
                    }
    
                    $adminInfo = getAdminInfo($adminId, $userId);
                    $adminInfo['adminId'] = $adminId;
                    $infoText = getAdminInfoText($adminInfo, $userId);
    
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $infoText,
                        'parse_mode' => 'Markdown',
                        'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
                    ]);
    
                    handleUserState('clear', $userId);

                    return;
                } else {
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['invalid_input']
                    ]);
                    return;
                }
            }
            if ($userState['state'] === 'custom_add' || $userState['state'] === 'custom_subtract') {
                $traffic = floatval($text);
                if ($traffic > 0) {
                    $adminId = $userState['admin_id'];
                    $promptMessageId = $userState['message_id'];
                    $totalTrafficBytes = $traffic * 1073741824;
            
                    if ($userState['state'] === 'custom_add') {
                        $stmt = $botConn->prepare("
                            INSERT INTO marzhelp_admin_settings (admin_id, total_traffic)
                            VALUES (?, ?) 
                            ON DUPLICATE KEY UPDATE total_traffic = COALESCE(total_traffic, 0) + VALUES(total_traffic)
                        ");
                    } else {
                        $stmt = $botConn->prepare("
                            INSERT INTO marzhelp_admin_settings (admin_id, total_traffic)
                            VALUES (?, -?) 
                            ON DUPLICATE KEY UPDATE total_traffic = COALESCE(total_traffic, 0) + VALUES(total_traffic)
                        ");
                    }
                    $stmt->bind_param("ii", $adminId, $totalTrafficBytes);
                    $stmt->execute();
                    $stmt->close();
                    
                    
            
                    sendRequest('deleteMessage', [
                        'chat_id' => $chatId,
                        'message_id' => $promptMessageId
                    ]);
            
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['setNewTraffic_success']
                    ]);
            
                    $adminInfo = getAdminInfo($adminId, $userId);
                    $adminInfo['adminId'] = $adminId;
                    $infoText = getAdminInfoText($adminInfo, $userId);
            
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $infoText,
                        'parse_mode' => 'Markdown',
                        'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
                    ]);
            
                    handleUserState('clear', $userId);
                    return;
                } else {
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['invalid_input']
                    ]);
                    return;
                }
            }
            
            if ($userState['state'] === 'set_expiry') {
                $days = intval($text);
                if ($days > 0) {
                    $adminId = $userState['admin_id'];
                    $expiryDate = date('Y-m-d', strtotime("+$days days"));
                    $promptMessageId = $userState['message_id'];

                    $stmt = $botConn->prepare("INSERT INTO marzhelp_admin_settings (admin_id, expiry_date) VALUES (?, ?) ON DUPLICATE KEY UPDATE expiry_date = ?");
                    $stmt->bind_param("iss", $adminId, $expiryDate, $expiryDate);
                    $stmt->execute();
                    $stmt->close();

                    sendRequest('deleteMessage', [
                        'chat_id' => $chatId,
                        'message_id' => $promptMessageId
                    ]);
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['setNewExpiry_success'],
                        'parse_mode' => 'Markdown'
                    ]);

                    $adminInfo = getAdminInfo($adminId, $userId);
                    $adminInfo['adminId'] = $adminId;
                    $infoText = getAdminInfoText($adminInfo, $userId);

                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $infoText,
                        'parse_mode' => 'Markdown',
                        'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
                    ]);

                    handleUserState('clear', $userId);
                    return;
                } else {
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['invalid_input'],
                        'parse_mode' => 'Markdown'
                    ]);
                    return;
                }
            }
        if ($userState['state'] === 'set_new_password') {
            $hashedPassword = password_hash($text, PASSWORD_BCRYPT);
            $adminId = $userState['admin_id'];
            $stmt = $marzbanConn->prepare("UPDATE admins SET hashed_password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashedPassword, $adminId);
            $stmt->execute();
            $stmt->close();
            $promptMessageId = $userState['message_id'];

            sendRequest('deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $promptMessageId
            ]);

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['password_changed']
            ]);
            $adminInfo = getAdminInfo($adminId, $userId);
            $adminInfo['adminId'] = $adminId;
            $infoText = getAdminInfoText($adminInfo, $userId);
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $infoText,
                'parse_mode' => 'Markdown',
                'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
            ]);
            handleUserState('clear', $userId);
            return;
        }
        if ($userState['state'] === 'set_new_telegram_id') {
            if (is_numeric($text)) {
                $telegramId = intval($text);
                $adminId = $userState['admin_id'];
                $stmt = $marzbanConn->prepare("UPDATE admins SET telegram_id = ? WHERE id = ?");
                $stmt->bind_param("ii", $telegramId, $adminId);
                $stmt->execute();
                $stmt->close();
                $promptMessageId = $userState['message_id'];

                sendRequest('deleteMessage', [
                    'chat_id' => $chatId,
                    'message_id' => $promptMessageId
                ]);
    
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['telegram_id_changed']
                ]);
                $adminInfo = getAdminInfo($adminId, $userId);
                $adminInfo['adminId'] = $adminId;
                $infoText = getAdminInfoText($adminInfo, $userId);
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $infoText,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
                ]);
                handleUserState('clear', $userId);

            } else {
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['invalid_input']
                ]);
            }
            return;
        }
        if ($userState['state'] === 'set_new_username') {
            $newUsername = $text;
            $adminId = $userState['admin_id'];
            $stmt = $marzbanConn->prepare("UPDATE admins SET username = ? WHERE id = ?");
            $stmt->bind_param("si", $newUsername, $adminId);
            $stmt->execute();
            $stmt->close();
            $promptMessageId = $userState['message_id'];

            sendRequest('deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $promptMessageId
            ]);

            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['username_changed']
            ]);
            $adminInfo = getAdminInfo($adminId, $userId);
            $adminInfo['adminId'] = $adminId;
            $infoText = getAdminInfoText($adminInfo, $userId);
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $infoText,
                'parse_mode' => 'Markdown',
                'reply_markup' => getAdminKeyboard($chatId, $adminId, $adminInfo['status'])
            ]);
            handleUserState('clear', $userId);
            return;
        }
        if ($userState['state'] === 'waiting_for_username') {
            if (preg_match('/^[a-zA-Z0-9]+$/', $text)) {
                $username = $text;
                $adminId = $userState['admin_id'];
                
                $stmt = $marzbanConn->prepare("SELECT id FROM admins WHERE username = ?");
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $promptMessageId = $userState['message_id'];

                    sendRequest('deleteMessage', [
                        'chat_id' => $chatId,
                        'message_id' => $promptMessageId
                    ]);
        
                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['username_taken'],
                        'reply_markup' => getbacktoadminselectbutton($userId)
                    ]);

                    $stateset = 'waiting_for_username';
                    handleUserState('set', $userId, $stateset);
            
                    return;
                }
                $stmt->close();
                
                handleTemporaryData('set', $userId, 'new_admin_username', $username);
                
                handleUserState('set', $userId, 'waiting_for_password');

                $promptMessageId = $userState['message_id'];

                sendRequest('deleteMessage', [
                    'chat_id' => $chatId,
                    'message_id' => $promptMessageId
                ]);
    
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['password_prompt'],
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => 'Generate Random', 'callback_data' => 'generate_random_password']
                            ],
                            [
                                ['text' => $lang['back'], 'callback_data' => 'manage_admins']
                            ]
                        ]
                    ]
                ]);
                $stateset = 'waiting_for_password';
                handleUserState('set', $userId, $stateset);
                return;
            } else {
                $adminId = $userState['admin_id'];
                $promptMessageId = $userState['message_id'];

                sendRequest('deleteMessage', [
                    'chat_id' => $chatId,
                    'message_id' => $promptMessageId
                ]);
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['invalid_username'],
                    'reply_markup' => getbacktoadminselectbutton($userId)
                ]);
                if (isset($response['result']['message_id'])) {
                    $promptMessageId = $response['result']['message_id'];
                } else {
                    $promptMessageId = $userState['message_id'];
                }
                $stateset = 'waiting_for_username';
                handleUserState('set', $userId, $stateset);
               
                return;
            }
        }
        
        if ($userState['state'] === 'waiting_for_password') {
            if (preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $text)) {
                $hashedPassword = password_hash($text, PASSWORD_BCRYPT);

                handleTemporaryData('set', $userId, 'new_admin_password', $hashedPassword);
                
                $promptMessageId = $userState['message_id'];

                sendRequest('deleteMessage', [
                    'chat_id' => $chatId,
                    'message_id' => $promptMessageId
                ]);
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['sudo_confirmation'],
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => $lang['confirm_yes_button'], 'callback_data' => 'sudo_yes'],
                                ['text' => $lang['confirm_no_button'], 'callback_data' => 'sudo_no']
                            ],
                            [
                                ['text' => $lang['back'], 'callback_data' => 'manage_admins']
                            ]
                        ]
                    ]
                ]);
                $stateset = 'waiting_for_sudo';
                handleUserState('set', $userId, $stateset);
                return;
            } else {
                $adminId = $userState['admin_id'];
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['invalid_password'],
                    'reply_markup' => [
                        'inline_keyboard' => [
                            [
                                ['text' => 'Generate Random', 'callback_data' => 'generate_random_password']
                            ],
                            [
                                ['text' => $lang['back'], 'callback_data' => 'manage_admins']
                            ]
                        ]
                    ]
                ]);
                $stateset = 'waiting_for_sudo';
                handleUserState('set', $userId, $stateset);
                return;
            }
        }
        if ($userState['state'] === 'waiting_for_sudo') {
            return;
        }
        if ($userState['state'] === 'waiting_for_telegram_id') {
            $adminId = $userState['admin_id'];
            if (is_numeric($text)) {
                $telegramId = intval($text);
                
                handleTemporaryData('set', $userId, 'new_admin_telegram_id', $telegramId);
                
                createAdmin($userId, $chatId);
                return;
            } elseif (strtolower($text) === 'skip') {

                handleTemporaryData('set', $userId, 'new_admin_telegram_id', 0);
                
                createAdmin($userId, $chatId);
                return;
            } else {
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['enterValidTelegramId_prompt'],
                    'reply_markup' => getbacktoadminselectbutton($userId)
                ]);
                return;
            }
        }
       /* if ($userState['state'] === 'awaiting_sql_upload' && isset($message['document'])) {
            $file_id = $message['document']['file_id'];
            $file_path = getFilePath($file_id);

            file_put_contents('/var/www/html/marzhelp/backups/marzhelp.sql', fopen($file_path, 'r'));
        
            $command = "mysql -u root -p$botDbPass marzhelp < /var/www/html/marzhelp/backups/marzhelp.sql";
            exec($command, $output, $return_var);
            if ($return_var === 0) {
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['restore_success']
                ]);
            } else {
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['restore_failed']
                ]);
            }
            handleUserState('clear', $userId);
            sendRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $lang['main_menu'],
                'reply_markup' => getMainMenuKeyboard($userId)
            ]);
            return;
        }*/
        if ($text === '/start') {
            $stmt = $botConn->prepare("SELECT lang FROM marzhelp_user_states WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $lang = null;
        
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $lang = $row['lang'];
            } else {
                $stmt = $botConn->prepare("INSERT INTO marzhelp_user_states (user_id, lang, state) VALUES (?, NULL, NULL)");
                $stmt->bind_param("i", $userId);
                $stmt->execute();
            }
        
            $stmt->close();
        
            if (empty($lang)) {
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "سلام! خوش آمدید به ربات marzhelp.\nلطفاً زبان خود را انتخاب کنید.\n\nHello! Welcome to marzhelp bot.\nPlease select your language.\n\nПривет! Добро пожаловать в бот marzhelp.\nПожалуйста, выберите ваш язык.",
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                ['text' => '🇮🇷 فارسی', 'callback_data' => 'set_lang_fa'],
                                ['text' => '🇬🇧 English', 'callback_data' => 'set_lang_en'],
                                ['text' => '🇷🇺 Русский', 'callback_data' => 'set_lang_ru']
                            ]
                        ]
                    ])
                ]);
        
                return;
            }
        
            $lang = getLang($userId);
        
            if ($userRole === 'main_admin') {
                sendRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $lang['main_menu'],
                    'reply_markup' => getMainMenuKeyboard($userId)
                ]);
        
            } elseif ($userRole === 'limited_admin') {

                    sendRequest('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $lang['main_menu'],
                        'reply_markup' => getMainMenuKeyboard($userId)
                    ]);
                }
            }
        }
    }
