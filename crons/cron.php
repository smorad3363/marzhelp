<?php
date_default_timezone_set('Asia/Tehran');

require __DIR__ . '/../app/bootstrap.php';

$runtimeStoragePath = $storagePath ?? (__DIR__ . '/../storage');
if (is_dir($runtimeStoragePath) && is_writable($runtimeStoragePath)) {
    chdir($runtimeStoragePath);
}

$cronLock = fopen(sys_get_temp_dir() . '/marzhelp-cron.lock', 'c');
if ($cronLock === false || !flock($cronLock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
register_shutdown_function(static function () use ($cronLock): void {
    flock($cronLock, LOCK_UN);
    fclose($cronLock);
});

class Database {
    private static $instances = [];
    private $connection;
    private $name;

    private function __construct($host, $user, $pass, $dbname) {
        $this->name = $dbname;
        $this->connect($host, $user, $pass, $dbname);
    }

    public static function getInstance($host, $user, $pass, $dbname) {
        $key = "$host:$dbname";
        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = new self($host, $user, $pass, $dbname);
        }
        return self::$instances[$key];
    }

    private function connect($host, $user, $pass, $dbname) {
        try {
            $this->connection = new mysqli($host, $user, $pass, $dbname);
            if ($this->connection->connect_error) {
                throw new Exception("DB connection failed: " . $this->connection->connect_error);
            }
            $this->connection->set_charset("utf8mb4");
        } catch (Exception $e) {
            $this->logError($e->getMessage());
            exit;
        }
    }

    public function getConnection() {
        return $this->connection;
    }

    public function logError($message) {
        file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - [$this->name] $message\n", FILE_APPEND);
    }

    public function __destruct() {
        if ($this->connection) {
            $this->connection->close();
        }
    }
}

class Notification {
    private $apiURL;
    private $dbBot; // This will be a mysqli connection object
    private const HEADERS = ["Content-Type: application/json"];

    public function __construct($apiURL, $dbBot) {
        $this->apiURL = $apiURL;
        $this->dbBot = $dbBot;
    }

    public function sendMessage($chat_id, $message) {
        $parameters = [
            'chat_id' => $chat_id,
            'text' => $message
        ];
        $method = 'sendMessage';
        $result = $this->sendRequest($method, $parameters);
        
        if (!$result) {
            $this->logError("Failed to send message to chat_id $chat_id: $message");
        }
        return $result;
    }

    public function sendInlineKeyboard($chat_id, $message, $keyboard) {
        $parameters = [
            'chat_id' => $chat_id,
            'text' => $message,
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ];
        $method = 'sendMessage';
        $result = $this->sendRequest($method, $parameters);
        
        if (!$result) {
            $this->logError("Failed to send inline keyboard to chat_id $chat_id: $message");
        }
        return $result;
    }

    private function sendRequest($method, $parameters) {
        try {
            $url = $this->apiURL . $method;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POSTFIELDS => json_encode($parameters),
                CURLOPT_HTTPHEADER => self::HEADERS,
                CURLOPT_RETURNTRANSFER => true
            ]);
            
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                throw new Exception("cURL error: " . curl_error($ch));
            }
            curl_close($ch);
            
            $result = json_decode($response, true);
            $this->updateMessageId($result, $parameters);
            return $result;
        } catch (Exception $e) {
            $this->logError($e->getMessage());
            return false;
        }
    }

    private function updateMessageId($result, $parameters) {
        if (isset($result['result']['message_id']) && isset($parameters['chat_id'])) {
            $messageId = $result['result']['message_id'];
            $userId = $parameters['chat_id'];
            
            $stmt = $this->dbBot->prepare("UPDATE marzhelp_user_states SET message_id = ? WHERE user_id = ?");
            if ($stmt) {
                $stmt->bind_param("ii", $messageId, $userId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    private function logError($message) {
        file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - Notification: $message\n", FILE_APPEND);
    }
}

class PanelManager {
    private $dbMarzban; // This will be a Database object
    private $dbBot;     // This will be a Database object
    private $notification;
    private $allowedUsers;
    private const INFINITY = '♾️';

    public function __construct($dbMarzban, $dbBot, $notification, $allowedUsers) {
        $this->dbMarzban = $dbMarzban;
        $this->dbBot = $dbBot;
        $this->notification = $notification;
        $this->allowedUsers = $allowedUsers;
    }

    private function getLang($userId) {
        $langCode = 'en';
    
        $stmt = $this->dbBot->getConnection()->prepare("SELECT lang FROM marzhelp_user_states WHERE user_id = ?");
        if ($stmt) {
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
                $this->dbBot->logError("Error executing statement: " . $stmt->error);
            }
            $stmt->close();
        } else {
            $this->dbBot->logError("Error preparing statement: " . $this->dbBot->getConnection()->error);
        }
    
        $languageFile = dirname(__DIR__) . "/app/language/{$langCode}.php";
    
        if (file_exists($languageFile)) {
            return include $languageFile;
        }
    
        return include dirname(__DIR__) . "/app/language/en.php";
    }

    private function fetchTelegramId($adminId) {
        $stmt = $this->dbMarzban->getConnection()->prepare("SELECT telegram_id FROM admins WHERE id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $telegramId = $result->num_rows > 0 ? $result->fetch_assoc()['telegram_id'] : null;
        $stmt->close();
        return $telegramId;
    }

    private function getAdminInfo($adminId) {
        try {
            $adminData = $this->fetchAdminData($adminId);
            if (!$adminData) return false;
    
            $trafficData = $this->calculateTraffic($adminId);
            $settings = $this->fetchSettings($adminId);
            $userStats = $this->fetchUserStats($adminId);
    
            return $this->formatAdminInfo($adminId, $adminData, $trafficData, $settings, $userStats);
        } catch (Exception $e) {
            $this->dbMarzban->logError($e->getMessage());
            return false;
        }
    }

    private function fetchAdminData($adminId) {
        $stmt = $this->dbMarzban->getConnection()->prepare("SELECT username FROM admins WHERE id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->num_rows > 0 ? $result->fetch_assoc() : false;
        $stmt->close();
        return $data;
    }

    private function calculateTraffic($adminId) {
        $stmtSettings = $this->dbBot->getConnection()->prepare("SELECT calculate_volume FROM marzhelp_admin_settings WHERE admin_id = ?");
        $stmtSettings->bind_param("i", $adminId);
        $stmtSettings->execute();
        $settingsResult = $stmtSettings->get_result();
        $settings = $settingsResult->fetch_assoc();
        $stmtSettings->close();
    
        $calculateVolume = $settings['calculate_volume'] ?? 'used_traffic';
    
        if ($calculateVolume === 'used_traffic') {
            $stmt = $this->dbMarzban->getConnection()->prepare("
                SELECT (
                    IFNULL((SELECT SUM(users.used_traffic) FROM users WHERE users.admin_id = admins.id), 0) +
                    IFNULL((SELECT SUM(user_usage_logs.used_traffic_at_reset) FROM user_usage_logs 
                            WHERE user_usage_logs.user_id IN (SELECT id FROM users WHERE users.admin_id = admins.id)), 0) +
                    IFNULL((SELECT SUM(marzhelp_deleted_users.used_traffic_total)
                            FROM marzhelp_deleted_users
                            WHERE marzhelp_deleted_users.admin_id = admins.id), 0)
                ) / 1073741824 AS used_traffic_gb
                FROM admins WHERE admins.id = ?");
        } else { 
            $stmt = $this->dbMarzban->getConnection()->prepare("
                SELECT (
                    IFNULL((SELECT SUM(
                        CASE 
                            WHEN users.data_limit IS NOT NULL THEN users.data_limit 
                            ELSE users.used_traffic 
                        END
                    ) FROM users WHERE users.admin_id = admins.id), 0) +
                    IFNULL((
                        SELECT SUM(user_usage_logs.used_traffic_at_reset)
                        FROM user_usage_logs
                        INNER JOIN users ON users.id = user_usage_logs.user_id
                        WHERE users.admin_id = admins.id
                          AND users.data_limit IS NULL
                    ), 0) +
                    IFNULL((
                        SELECT SUM(marzhelp_deleted_users.used_traffic_total)
                        FROM marzhelp_deleted_users
                        WHERE marzhelp_deleted_users.admin_id = admins.id
                    ), 0)
                ) / 1073741824 AS created_traffic_gb
                FROM admins WHERE admins.id = ?");
        }
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
    
        return $data ?? ['used_traffic_gb' => 0, 'created_traffic_gb' => 0];
    }

    private function fetchSettings($adminId) {
        $stmt = $this->dbBot->getConnection()->prepare("SELECT total_traffic, expiry_date, status, user_limit, calculate_volume, hashed_password_before, 
                                      last_traffic_notification, last_expiry_notification 
                                      FROM marzhelp_admin_settings WHERE admin_id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->num_rows > 0 ? $result->fetch_assoc() : null;
        $stmt->close();
        return $data;
    }

    private function fetchUserStats($adminId) {
        $stmt = $this->dbMarzban->getConnection()->prepare("
            SELECT COUNT(*) AS total_users,
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
            FROM users WHERE admin_id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        return $data ?? ['total_users' => 0, 'active_users' => 0, 'expired_users' => 0, 'online_users' => 0];
    }

    private function formatAdminInfo($adminId, $admin, $traffic, $settings, $userStats) {
        if ($settings === null) {
            // FIX: This call now works correctly because $this->dbMarzban is a Database object.
            $this->dbMarzban->logError("Settings not found for admin ID: $adminId");
            return false;
        }

        $calculateVolume = $settings['calculate_volume'] ?? 'used_traffic';
    
        if ($calculateVolume === 'used_traffic') {
            $usedTraffic = round($traffic['used_traffic_gb'] ?? 0, 2);
        } else {
            $usedTraffic = round($traffic['created_traffic_gb'] ?? 0, 2);
        }
    
        $totalTraffic = $settings['total_traffic'] > 0 ? round($settings['total_traffic'] / 1073741824, 2) : self::INFINITY;
        $remainingTraffic = $totalTraffic !== self::INFINITY ? round($totalTraffic - $usedTraffic, 2) : self::INFINITY;
        
        $expiryDate = $settings['expiry_date'] ?? self::INFINITY;
        $daysLeft = $expiryDate !== self::INFINITY ? ceil((strtotime($expiryDate) - time()) / 86400) : self::INFINITY;
        
        $userLimit = $settings['user_limit'] ?? 0;
        
        $statusRaw = $settings['status'] ?? null;
        if ($statusRaw && is_string($statusRaw)) {
            $statusDecoded = json_decode($statusRaw, true);
            $status = is_array($statusDecoded) ? $statusDecoded : ['time' => 'active', 'data' => 'active', 'users' => 'active'];
        } else {
            $status = ['time' => 'active', 'data' => 'active', 'users' => 'active'];
        }
        
        return [
            'username' => $admin['username'],
            'userid' => $adminId,
            'usedTraffic' => $usedTraffic,
            'totalTraffic' => $totalTraffic,
            'remainingTraffic' => $remainingTraffic,
            'expiryDate' => $expiryDate,
            'daysLeft' => $daysLeft,
            'status' => json_encode($status),
            'hashed_password_before' => $settings['hashed_password_before'] ?? null, 
            'last_traffic_notification' => $settings['last_traffic_notification'] ?? null,
            'last_expiry_notification' => $settings['last_expiry_notification'] ?? null,
            'userStats' => $userStats,
            'userLimit' => $userLimit
        ];
    }

    public function gettingadmininfo($adminId) {
        $stmtAdmin = $this->dbMarzban->getConnection()->prepare("SELECT username FROM admins WHERE id = ?");
        $stmtAdmin->bind_param("i", $adminId);
        $stmtAdmin->execute();
        $adminResult = $stmtAdmin->get_result();
        if ($adminResult->num_rows === 0) {
            $stmtAdmin->close();
            return false;
        }
        $admin = $adminResult->fetch_assoc();
        $adminUsername = $admin['username'];
        $stmtAdmin->close();
    
        $stmtSettings = $this->dbBot->getConnection()->prepare("SELECT total_traffic, expiry_date, status, user_limit, calculate_volume FROM marzhelp_admin_settings WHERE admin_id = ?");
        $stmtSettings->bind_param("i", $adminId);
        $stmtSettings->execute();
        $settingsResult = $stmtSettings->get_result();
        $settings = $settingsResult->fetch_assoc() ?: [];
        $stmtSettings->close();
    
        $calculateVolume = $settings['calculate_volume'] ?? 'used_traffic';
    
        if ($calculateVolume === 'used_traffic') {
            $stmtTraffic = $this->dbMarzban->getConnection()->prepare("
                SELECT admins.username, 
                (
                    IFNULL((SELECT SUM(users.used_traffic) FROM users WHERE users.admin_id = admins.id), 0) +
                    IFNULL((SELECT SUM(user_usage_logs.used_traffic_at_reset) FROM user_usage_logs 
                            WHERE user_usage_logs.user_id IN (SELECT id FROM users WHERE users.admin_id = admins.id)), 0) +
                    IFNULL((SELECT SUM(marzhelp_deleted_users.used_traffic_total)
                            FROM marzhelp_deleted_users
                            WHERE marzhelp_deleted_users.admin_id = admins.id), 0)
                ) / 1073741824 AS used_traffic_gb
                FROM admins
                WHERE admins.id = ?
                GROUP BY admins.username, admins.id");
        } else {
            $stmtTraffic = $this->dbMarzban->getConnection()->prepare("
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
        $trafficData = $trafficResult->fetch_assoc() ?: [];
        $stmtTraffic->close();
    
        $usedTraffic = isset($trafficData['used_traffic_gb']) ? round($trafficData['used_traffic_gb'], 2) : (isset($trafficData['created_traffic_gb']) ? round($trafficData['created_traffic_gb'], 2) : 0);
        $totalTraffic = isset($settings['total_traffic']) && $settings['total_traffic'] > 0 ? round($settings['total_traffic'] / 1073741824, 2) : self::INFINITY;
        $remainingTraffic = $totalTraffic !== self::INFINITY ? round($totalTraffic - $usedTraffic, 2) : self::INFINITY;
    
        $expiryDate = $settings['expiry_date'] ?? self::INFINITY;
        $daysLeft = $expiryDate !== self::INFINITY ? ceil((strtotime($expiryDate) - time()) / 86400) : self::INFINITY;
    
        $statusArray = isset($settings['status']) ? json_decode($settings['status'], true) : ['time' => 'active', 'data' => 'active', 'users' => 'active'];
        $status = $statusArray['users'] ?? 'active';
    
        $stmtUserStats = $this->dbMarzban->getConnection()->prepare("
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
            WHERE admin_id = ?");
        $stmtUserStats->bind_param("i", $adminId);
        $stmtUserStats->execute();
        $userStatsResult = $stmtUserStats->get_result();
        $userStats = $userStatsResult->fetch_assoc() ?: ['total_users' => 0, 'active_users' => 0, 'expired_users' => 0, 'online_users' => 0];
        $stmtUserStats->close();
    
        $userLimit = $settings['user_limit'] ?? self::INFINITY;
        $remainingUserLimit = $userLimit;
    
        $preventUserCreation = $this->triggerCheck('prevent_user_creation', $adminId);
        $preventUserReset = $this->triggerCheck('prevent_User_Reset_Usage', $adminId);
        $preventRevokeSubscription = $this->triggerCheck('prevent_revoke_subscription', $adminId);
        $preventUnlimitedTraffic = $this->triggerCheck('prevent_unlimited_traffic', $adminId);
        $preventUserDelete = $this->triggerCheck('admin_delete', $adminId);
    
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
            'preventUserReset' => $preventUserReset,
            'preventUserCreation' => $preventUserCreation,
            'preventUserDeletion' => $preventUserDelete,
            'preventRevokeSubscription' => $preventRevokeSubscription,
            'preventUnlimitedTraffic' => $preventUnlimitedTraffic,
            'userStats' => $userStats
        ];
    }

    private function triggerCheck($triggerName, $adminId) {
        $columns = [
            'prevent_user_creation' => 'prevent_user_creation',
            'admin_delete' => 'prevent_user_deletion',
            'prevent_User_Reset_Usage' => 'prevent_user_reset',
            'prevent_revoke_subscription' => 'prevent_revoke_subscription',
            'prevent_unlimited_traffic' => 'prevent_unlimited_traffic',
        ];
        if (!isset($columns[$triggerName])) return false;
        $column = $columns[$triggerName];
        $statement = $this->dbBot->getConnection()->prepare(
            "SELECT `$column` FROM marzhelp_admin_settings WHERE admin_id = ?"
        );
        $statement->bind_param("i", $adminId);
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();
        return !empty($row[$column]);
    }
    private function getAdminKeyboard($adminId, $status) {
        $telegramId = $this->fetchTelegramId($adminId);
        if ($telegramId) {
            $lang = $this->getLang($telegramId);
        } else {
            $firstOwnerId = reset($this->allowedUsers);
            $lang = $this->getLang($firstOwnerId);
        }
    
        $stmt = $this->dbBot->getConnection()->prepare("SELECT status, hashed_password_before FROM marzhelp_admin_settings WHERE admin_id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
    
        $currentStatus = $row && $row['status'] ? json_decode($row['status'], true) : ['time' => 'active', 'data' => 'active', 'users' => 'active'];
        $usersButtonText = ($currentStatus['users'] === 'active') ? $lang['disable_users_button'] : $lang['enable_users_button'];
    
        $hashedPasswordBefore = $row['hashed_password_before'] ?? null;
        $passwordButtonText = ($hashedPasswordBefore) ? $lang['restore_password'] : $lang['change_password_temp'];
    
        return [
            [
                ['text' => $usersButtonText, 'callback_data' => ($currentStatus['users'] === 'active') ? "disable_users_{$adminId}" : "enable_users_{$adminId}"],
                ['text' => $passwordButtonText, 'callback_data' => ($hashedPasswordBefore) ? "restore_password_{$adminId}" : "change_password_{$adminId}"]
            ]
        ];
    }

    private function managePanelExtension($adminId, $adminInfo) {
        if ($adminInfo['expiryDate'] === self::INFINITY) return;

        $expiryTimestamp = strtotime($adminInfo['expiryDate']);
        $daysLeft = ceil(($expiryTimestamp - time()) / 86400); 

        $statusRaw = $adminInfo['status'];
        if (is_string($statusRaw)) {
            $currentStatus = json_decode($statusRaw, true) ?? ['time' => 'active', 'data' => 'active', 'users' => 'active'];
        } else {
            $currentStatus = ['time' => 'active', 'data' => 'active', 'users' => 'active'];
        }

        if ($daysLeft <= 0 && $currentStatus['time'] !== 'expired') {
            $telegramId = $this->fetchTelegramId($adminId);
            if ($telegramId) {
                $lang = $this->getLang($telegramId);
            } else {
                $firstOwnerId = reset($this->allowedUsers);
                $lang = $this->getLang($firstOwnerId);
            }
            $message = sprintf($lang['panel_expired_notify'], $adminInfo['username'], $adminId);

            $keyboard = $this->getAdminKeyboard($adminId, $currentStatus);

            foreach ($this->allowedUsers as $ownerId) {
                $this->notification->sendInlineKeyboard($ownerId, $message, $keyboard);
            }

            $currentStatus['time'] = 'expired';
            $newStatus = json_encode($currentStatus);

            $stmt = $this->dbBot->getConnection()->prepare("UPDATE marzhelp_admin_settings SET status = ? WHERE admin_id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $newStatus, $adminId);
                $stmt->execute();
                $stmt->close();
            }
        } elseif ($daysLeft > 0 && $currentStatus['time'] === 'expired') {
            $currentStatus['time'] = 'active';
            $newStatus = json_encode($currentStatus);

            $stmt = $this->dbBot->getConnection()->prepare("UPDATE marzhelp_admin_settings SET status = ? WHERE admin_id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $newStatus, $adminId);
                $stmt->execute();
                $stmt->close();
            }

            $telegramId = $this->fetchTelegramId($adminId);
            if ($telegramId) {
                $lang = $this->getLang($telegramId);
            } else {
                $firstOwnerId = reset($this->allowedUsers);
                $lang = $this->getLang($firstOwnerId);
            }
            $message = sprintf($lang['panel_renewed_notify'], $adminInfo['username'], $adminId);
            foreach ($this->allowedUsers as $ownerId) {
                $this->notification->sendMessage($ownerId, $message);
            }
        }
    }

    public function manageTrafficUsage($adminId, $adminInfo) {
        $stmt = $this->dbBot->getConnection()->prepare("SELECT status, total_traffic FROM marzhelp_admin_settings WHERE admin_id = ?");
        $stmt->bind_param("i", $adminId);
        $stmt->execute();
        $result = $stmt->get_result();
        $settings = $result->fetch_assoc();
        $stmt->close();
    
        if (!$settings) return 0;
    
        $currentStatus = json_decode($settings['status'], true) ?? ['data' => 'active', 'time' => 'active', 'users' => 'active'];
        $totalTraffic = $settings['total_traffic'] > 0 ? round($settings['total_traffic'] / 1073741824, 2) : self::INFINITY;
    
        if ($totalTraffic === self::INFINITY) return self::INFINITY;

        $usedTraffic = round($adminInfo['usedTraffic'], 2);
        $remainingTraffic = round($totalTraffic - $usedTraffic, 2);
    
        if ($remainingTraffic <= 0 && $currentStatus['data'] !== 'exhausted') {
            $lang = $this->getLang(reset($this->allowedUsers));
            $message = sprintf($lang['traffic_exhausted_notify'], $adminInfo['username'], $adminId);
            $keyboard = $this->getAdminKeyboard($adminId, $currentStatus);
    
            foreach ($this->allowedUsers as $ownerId) {
                $this->notification->sendInlineKeyboard($ownerId, $message, $keyboard);
            }
    
            $currentStatus['data'] = 'exhausted';
            $newStatus = json_encode($currentStatus);
            $stmt = $this->dbBot->getConnection()->prepare("UPDATE marzhelp_admin_settings SET status = ? WHERE admin_id = ?");
            $stmt->bind_param("si", $newStatus, $adminId);
            $stmt->execute();
            $stmt->close();
    
        } elseif ($remainingTraffic > 0 && $currentStatus['data'] === 'exhausted') {
            $currentStatus['data'] = 'active';
            $newStatus = json_encode($currentStatus);
            $stmt = $this->dbBot->getConnection()->prepare("UPDATE marzhelp_admin_settings SET status = ? WHERE admin_id = ?");
            $stmt->bind_param("si", $newStatus, $adminId);
            $stmt->execute();
            $stmt->close();
    
        }
    
        return $remainingTraffic;
    }

    private function notifyAdmins() {
        $adminsResult = $this->dbMarzban->getConnection()->query("SELECT id, telegram_id FROM admins WHERE telegram_id IS NOT NULL");
        if (!$adminsResult) return;

        while ($admin = $adminsResult->fetch_assoc()) {
            $adminId = $admin['id'];
            $telegramId = $admin['telegram_id'];
            $adminInfo = $this->getAdminInfo($adminId);
            if (!$adminInfo) continue;

            $this->notifyTraffic($adminId, $adminInfo, $telegramId);
            $this->notifyExpiry($adminId, $adminInfo, $telegramId);
        }
        $adminsResult->free();
    }

    private function notifyTraffic($adminId, $adminInfo, $telegramId) {
        if ($adminInfo['totalTraffic'] === self::INFINITY) return;

        $remainingTraffic = $adminInfo['remainingTraffic'];
        if (!is_numeric($remainingTraffic)) return;

        $lastTrafficNotification = $adminInfo['last_traffic_notification'];

        if ($remainingTraffic > 300 && $lastTrafficNotification !== null) {
            $stmt = $this->dbBot->getConnection()->prepare("UPDATE marzhelp_admin_settings SET last_traffic_notification = NULL WHERE admin_id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $stmt->close();
            return;
        }

        $threshold = 0;
        if ($remainingTraffic <= 300 && $remainingTraffic > 200 && $lastTrafficNotification != 300) {
            $threshold = 300;
        } elseif ($remainingTraffic <= 200 && $remainingTraffic > 100 && $lastTrafficNotification != 200) {
            $threshold = 200;
        } elseif ($remainingTraffic <= 100 && $remainingTraffic > 0 && $lastTrafficNotification != 100) {
            $threshold = 100;
        }

        if ($threshold > 0) {
            $lang = $this->getLang($telegramId);
            $message = sprintf($lang['traffic_warning'], $adminInfo['username'], $threshold);
            $this->notification->sendMessage($telegramId, $message);

            $stmt = $this->dbBot->getConnection()->prepare("UPDATE marzhelp_admin_settings SET last_traffic_notification = ? WHERE admin_id = ?");
            $stmt->bind_param("ii", $threshold, $adminId);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function notifyExpiry($adminId, $adminInfo, $telegramId) {
        if ($adminInfo['expiryDate'] === self::INFINITY) return;

        $daysLeft = $adminInfo['daysLeft'];
        if (!is_numeric($daysLeft)) return;

        $lastExpiryNotification = $adminInfo['last_expiry_notification'];

        if ($daysLeft > 7 && $lastExpiryNotification !== null) {
            $stmt = $this->dbBot->getConnection()->prepare("UPDATE marzhelp_admin_settings SET last_expiry_notification = NULL WHERE admin_id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $stmt->close();
            return;
        }

        $daysThreshold = 0;
        if ($daysLeft <= 7 && $daysLeft > 3 && $lastExpiryNotification === null) {
             $daysThreshold = 7;
        } elseif ($daysLeft <= 3 && $daysLeft > 1 && ($lastExpiryNotification === null || strtotime($lastExpiryNotification) < strtotime('-4 days'))) {
             $daysThreshold = 3;
        } elseif ($daysLeft <= 1 && $daysLeft > 0 && ($lastExpiryNotification === null || strtotime($lastExpiryNotification) < strtotime('-2 days'))) {
             $daysThreshold = 1;
        }

        if ($daysThreshold > 0) {
            $lang = $this->getLang($telegramId);
            $message = sprintf($lang['panel_expiry_warning'], $adminInfo['username'], $daysThreshold);
            $this->notification->sendMessage($telegramId, $message);

            $stmt = $this->dbBot->getConnection()->prepare("UPDATE marzhelp_admin_settings SET last_expiry_notification = NOW() WHERE admin_id = ?");
            $stmt->bind_param("i", $adminId);
            $stmt->execute();
            $stmt->close();
        }
    }

    private function ensureMarzbanAdminIsSudo($marzbanAdminUsername) {
        $stmt = $this->dbMarzban->getConnection()->prepare("SELECT id, is_sudo FROM admins WHERE username = ?");
        $stmt->bind_param("s", $marzbanAdminUsername);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $this->dbMarzban->logError("Admin with username '$marzbanAdminUsername' not found.");
            $stmt->close();
            return;
        }

        $admin = $result->fetch_assoc();
        $stmt->close();

        if ($admin['is_sudo'] != 1) {
            $stmt = $this->dbMarzban->getConnection()->prepare("UPDATE admins SET is_sudo = 1 WHERE id = ?");
            $stmt->bind_param("i", $admin['id']);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - Updated is_sudo to 1 for admin '$marzbanAdminUsername' (ID: {$admin['id']})\n", FILE_APPEND);
            } else {
                $this->dbMarzban->logError("Failed to update is_sudo for admin '$marzbanAdminUsername' (ID: {$admin['id']})");
            }
            $stmt->close();
        }
    }

    /** Synchronize inbound restrictions with DML only; Marzban owns the schema. */
    private function syncInboundLimits() {
        $connection = $this->dbMarzban->getConnection();
        $queries = [
            "INSERT INTO exclude_inbounds_association (proxy_id, inbound_tag)
             SELECT p.id, ml.inbound_tag
             FROM marzhelp_limits ml
             INNER JOIN users u ON u.admin_id = ml.admin_id
             INNER JOIN proxies p ON p.user_id = u.id
             LEFT JOIN exclude_inbounds_association eia
               ON eia.proxy_id = p.id AND eia.inbound_tag = ml.inbound_tag
             WHERE ml.type = 'exclude' AND eia.proxy_id IS NULL",
            "DELETE eia FROM exclude_inbounds_association eia
             INNER JOIN proxies p ON p.id = eia.proxy_id
             INNER JOIN users u ON u.id = p.user_id
             LEFT JOIN marzhelp_limits ml
               ON ml.admin_id = u.admin_id
              AND ml.inbound_tag = eia.inbound_tag
              AND ml.type = 'exclude'
             WHERE ml.admin_id IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM marzhelp_limits dedicated
                   WHERE dedicated.type = 'dedicated'
                     AND dedicated.inbound_tag = eia.inbound_tag
                     AND dedicated.admin_id <> u.admin_id
               )",
            "INSERT INTO exclude_inbounds_association (proxy_id, inbound_tag)
             SELECT p.id, ml.inbound_tag
             FROM marzhelp_limits ml
             INNER JOIN users u ON u.admin_id <> ml.admin_id
             INNER JOIN proxies p ON p.user_id = u.id
             LEFT JOIN exclude_inbounds_association eia
               ON eia.proxy_id = p.id AND eia.inbound_tag = ml.inbound_tag
             WHERE ml.type = 'dedicated' AND eia.proxy_id IS NULL",
            "DELETE eia FROM exclude_inbounds_association eia
             INNER JOIN proxies p ON p.id = eia.proxy_id
             INNER JOIN users u ON u.id = p.user_id
             INNER JOIN marzhelp_limits ml
               ON ml.admin_id = u.admin_id
              AND ml.inbound_tag = eia.inbound_tag
              AND ml.type = 'dedicated'",
        ];

        foreach ($queries as $query) {
            if (!$connection->query($query)) {
                $this->dbMarzban->logError('Inbound limit sync failed: ' . $connection->error);
                return false;
            }
        }
        return true;
    }
    
    public function managePanels() {
        global $marzbanAdminUsername;
        $this->syncInboundLimits();
        $currentMinute = (int)date('i');
        $currentTime = date('H:i');
        $adminsResult = $this->dbMarzban->getConnection()->query("SELECT id FROM admins");
        if (!$adminsResult) return;

        $allAdmins = [];
        while ($admin = $adminsResult->fetch_assoc()) {
            $allAdmins[] = $admin;
        }
        $adminsResult->free();

        foreach ($allAdmins as $admin) {
            $adminId = $admin['id'];
            $adminInfo = $this->getAdminInfo($adminId);
            if (!$adminInfo) continue;
        
            $this->managePanelExtension($adminId, $adminInfo);
            $this->manageTrafficUsage($adminId, $adminInfo);

            if ($currentTime === '00:00') {
                $stmt = $this->dbMarzban->getConnection()->prepare("SELECT telegram_id, username FROM admins WHERE id = ?");
                $stmt->bind_param("i", $adminId);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $adminDetails = $result->fetch_assoc();
                    $telegramId = $adminDetails['telegram_id'];
                    $username = $adminDetails['username'];
    
                    if (!empty($telegramId)) {
                        $userLimit = isset($adminInfo['userLimit']) && $adminInfo['userLimit'] !== self::INFINITY ? (int)$adminInfo['userLimit'] : 0;
                        if ($userLimit > 0) {
                            $totalUsers = isset($adminInfo['userStats']['total_users']) ? (int)$adminInfo['userStats']['total_users'] : 0;
                            $remainingSlots = $userLimit;
        
                            if ($remainingSlots > 0 && $remainingSlots <= 5) {
                                $lang = $this->getLang($telegramId);
                                $message = sprintf($lang['user_limit_warning'], $username);
                                $this->notification->sendMessage($telegramId, $message);
                            }
                        }
                    }
                }
                $stmt->close();
            }
        }

        $this->notifyAdmins();

        if ($currentTime === '03:05') {
            $this->dbBot->getConnection()->query(
                "DELETE FROM marzhelp_admin_usage
                 WHERE created_at < NOW() - INTERVAL 400 DAY"
            );
        }
    
        if ($currentMinute % 15 === 0) {
            foreach ($allAdmins as $admin) {
                $adminInfo = $this->getAdminInfo($admin['id']);
                if (!$adminInfo) continue;
    
                $usedTraffic = $adminInfo['usedTraffic'];
    
                $stmt = $this->dbBot->getConnection()->prepare("INSERT INTO marzhelp_admin_usage (admin_id, used_traffic_gb) VALUES (?, ?)");
                if ($stmt) {
                    $stmt->bind_param("id", $admin['id'], $usedTraffic);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }
}

// =================================================================
// SCRIPT EXECUTION
// =================================================================

// FIX 1: Get the full Database wrapper objects.
$dbMarzbanInstance = Database::getInstance($vpnDbHost, $vpnDbUser, $vpnDbPass, $vpnDbName);
$dbBotInstance = Database::getInstance($botDbHost, $botDbUser, $botDbPass, $botDbName);

// The Notification class expects a raw mysqli connection.
$notification = new Notification($apiURL, $dbBotInstance->getConnection());

// FIX 2: Pass the full Database wrapper objects to PanelManager.
$panelManager = new PanelManager($dbMarzbanInstance, $dbBotInstance, $notification, $allowedUsers);

$panelManager->managePanels();
