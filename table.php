<?php
require __DIR__ . '/app/bootstrap.php';

$migrationBotDbUser = $migrationDbUser ?? $botDbUser;
$migrationBotDbPass = $migrationDbPass ?? $botDbPass;
$migrationVpnDbUser = $migrationDbUser ?? $vpnDbUser;
$migrationVpnDbPass = $migrationDbPass ?? $vpnDbPass;

$botConn = new mysqli($botDbHost, $migrationBotDbUser, $migrationBotDbPass, $botDbName);
if ($botConn->connect_error) {
    file_put_contents('bot_log.txt', date('Y-m-d H:i:s') . " - Bot DB connection failed: " . $botConn->connect_error . "\n", FILE_APPEND);
    exit(1); 
}
$botConn->set_charset("utf8");

$marzbanConn = new mysqli($vpnDbHost, $migrationVpnDbUser, $migrationVpnDbPass, $vpnDbName);
if ($marzbanConn->connect_error) {
    file_put_contents('logs.txt', date('Y-m-d H:i:s') . " - VPN DB connection failed: " . $marzbanConn->connect_error . "\n", FILE_APPEND);
    exit;
}
$marzbanConn->set_charset("utf8");

function checkAndCreateTablesAndColumns($botConn) {
    $hasCriticalError = false;

    $tableAdminSettings = "CREATE TABLE IF NOT EXISTS `admin_settings` (
        `admin_id` int NOT NULL,
        `total_traffic` bigint DEFAULT NULL,
        `used_traffic` bigint DEFAULT 0,
        `expiry_date` date DEFAULT NULL,
        `status` JSON, 
        `user_limit` bigint DEFAULT NULL,
        `hashed_password_before` varchar(255) DEFAULT NULL,
        `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `last_expiry_notification` timestamp NULL DEFAULT NULL,
        `last_traffic_notification` int DEFAULT NULL,
        `last_traffic_notify` int DEFAULT NULL,
        `calculate_volume` varchar(50) DEFAULT 'used_traffic',
        `prevent_user_creation` tinyint(1) NOT NULL DEFAULT 0,
        `prevent_user_deletion` tinyint(1) NOT NULL DEFAULT 0,
        `prevent_user_reset` tinyint(1) NOT NULL DEFAULT 0,
        `prevent_revoke_subscription` tinyint(1) NOT NULL DEFAULT 0,
        `prevent_unlimited_traffic` tinyint(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (`admin_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;";
    
    if (!$botConn->query($tableAdminSettings)) {
        echo "Critical error creating `admin_settings`: " . $botConn->error . "\n";
        $hasCriticalError = true;
    }

    $tableUserStates = "CREATE TABLE IF NOT EXISTS `user_states` (
        `user_id` bigint NOT NULL,
        `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
        `lang` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
        `state` varchar(50) DEFAULT NULL,
        `admin_id` int DEFAULT NULL,
        `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `data` text,
        `message_id` int DEFAULT NULL,
        `template_index` int DEFAULT 0,
        PRIMARY KEY (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

    if (!$botConn->query($tableUserStates)) {
        echo "Critical error creating `user_states`: " . $botConn->error . "\n";
        $hasCriticalError = true;
    }

    $tableUserTemporaries = "CREATE TABLE IF NOT EXISTS `user_temporaries` (
        `user_id` BIGINT NOT NULL,
        `user_key` varchar(50) NOT NULL,
        `value` text,
        PRIMARY KEY (`user_id`, `user_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";

    if (!$botConn->query($tableUserTemporaries)) {
        echo "Critical error creating `user_temporaries`: " . $botConn->error . "\n";
        $hasCriticalError = true;
    }

    $tableAdminUsage = "CREATE TABLE IF NOT EXISTS `admin_usage` (
        `id` bigint NOT NULL AUTO_INCREMENT,
        `admin_id` int NOT NULL,
        `used_traffic_gb` decimal(10,2) NOT NULL,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    if (!$botConn->query($tableAdminUsage)) {
        echo "Critical error creating `admin_usage`: " . $botConn->error . "\n";
        $hasCriticalError = true;
    }

    $columnsAdminSettings = [
        'hashed_password_before' => "ALTER TABLE `admin_settings` ADD `hashed_password_before` varchar(255) DEFAULT NULL;",
        'last_expiry_notification' => "ALTER TABLE `admin_settings` ADD `last_expiry_notification` timestamp NULL DEFAULT NULL;",
        'last_traffic_notification' => "ALTER TABLE `admin_settings` ADD `last_traffic_notification` int DEFAULT NULL;",
        'last_traffic_notify' => "ALTER TABLE `admin_settings` ADD `last_traffic_notify` int DEFAULT NULL;",
        'used_traffic' => "ALTER TABLE `admin_settings` ADD `used_traffic` bigint DEFAULT 0;",
        'calculate_volume' => "ALTER TABLE `admin_settings` ADD `calculate_volume` VARCHAR(50) DEFAULT 'used_traffic';",
        'prevent_user_creation' => "ALTER TABLE `admin_settings` ADD `prevent_user_creation` tinyint(1) NOT NULL DEFAULT 0;",
        'prevent_user_deletion' => "ALTER TABLE `admin_settings` ADD `prevent_user_deletion` tinyint(1) NOT NULL DEFAULT 0;",
        'prevent_user_reset' => "ALTER TABLE `admin_settings` ADD `prevent_user_reset` tinyint(1) NOT NULL DEFAULT 0;",
        'prevent_revoke_subscription' => "ALTER TABLE `admin_settings` ADD `prevent_revoke_subscription` tinyint(1) NOT NULL DEFAULT 0;",
        'prevent_unlimited_traffic' => "ALTER TABLE `admin_settings` ADD `prevent_unlimited_traffic` tinyint(1) NOT NULL DEFAULT 0;"
    ];
    $hasCriticalError = $hasCriticalError || checkAndAddColumns($botConn, 'admin_settings', $columnsAdminSettings);

    $columnsUserStates = [
        'username' => "ALTER TABLE `user_states` ADD `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL;",
        'lang' => "ALTER TABLE `user_states` ADD `lang` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL;",
        'state' => "ALTER TABLE `user_states` ADD `state` varchar(50) DEFAULT NULL;",
        'admin_id' => "ALTER TABLE `user_states` ADD `admin_id` int DEFAULT NULL;",
        'updated_at' => "ALTER TABLE `user_states` ADD `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;",
        'data' => "ALTER TABLE `user_states` ADD `data` text;",
        'message_id' => "ALTER TABLE `user_states` ADD `message_id` int DEFAULT NULL;",
        'template_index' => "ALTER TABLE `user_states` ADD COLUMN `template_index` INT DEFAULT 0 AFTER `message_id`;"
    ];
    $hasCriticalError = $hasCriticalError || checkAndAddColumns($botConn, 'user_states', $columnsUserStates);

    $columnsUserTemporaries = [
        'value' => "ALTER TABLE `user_temporaries` ADD `value` text;"
    ];
    $hasCriticalError = $hasCriticalError || checkAndAddColumns($botConn, 'user_temporaries', $columnsUserTemporaries);

    $columnStatusType = $botConn->query("SHOW COLUMNS FROM `admin_settings` LIKE 'status'")->fetch_assoc();
    if ($columnStatusType && strpos($columnStatusType['Type'], 'json') === false) {
        $cleanupQuery = "UPDATE `admin_settings` SET `status` = NULL WHERE `status` IS NOT NULL AND JSON_VALID(`status`) = 0;";
        if ($botConn->query($cleanupQuery) === TRUE) {
            echo "Invalid status values cleaned up.\n";
        } else {
            echo "Error cleaning up invalid status values: " . $botConn->error . "\n";
            $hasCriticalError = true;
        }
    
        $alterStatusQuery = "ALTER TABLE `admin_settings` MODIFY `status` JSON;";
        if ($botConn->query($alterStatusQuery) === TRUE) {
            echo "Column 'status' in 'admin_settings' modified to JSON successfully.\n";
    
            $defaultStatus = '{"data": "active", "time": "active", "users": "active"}';
            $updateExistingQuery = "UPDATE `admin_settings` SET `status` = '$defaultStatus' WHERE `status` IS NULL;";
            if ($botConn->query($updateExistingQuery) === TRUE) {
                echo "Existing records updated with default status.\n";
            } else {
                echo "Error updating existing records: " . $botConn->error . "\n";
                $hasCriticalError = true;
            }
    
            $triggerQuery = "
                DROP TRIGGER IF EXISTS set_default_status;
                CREATE TRIGGER set_default_status
                BEFORE INSERT ON admin_settings
                FOR EACH ROW
                BEGIN
                    IF NEW.status IS NULL THEN
                        SET NEW.status = '{\"data\": \"active\", \"time\": \"active\", \"users\": \"active\"}';
                    END IF;
                END;
            ";
            if ($botConn->multi_query($triggerQuery)) {
                echo "Trigger 'set_default_status' created successfully.\n";
                do {
                    if ($result = $botConn->store_result()) {
                        $result->free();
                    }
                } while ($botConn->next_result());
            } else {
                echo "Error creating trigger 'set_default_status': " . $botConn->error . "\n";
                $hasCriticalError = true;
            }
        } else {
            echo "Error modifying 'status' column in 'admin_settings': " . $botConn->error . "\n";
            $hasCriticalError = true;
        }
    }

    return $hasCriticalError;
}

function checkAndAddColumns($botConn, $tableName, $columns) {
    $hasCriticalError = false;

    foreach ($columns as $columnName => $alterQuery) {
        $result = $botConn->query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        if ($result->num_rows == 0) {
            if ($botConn->query($alterQuery) === TRUE) {
                echo "Column '$columnName' added to table '$tableName'.\n";
            } else {
                echo "Error adding column '$columnName' to table '$tableName': " . $botConn->error . "\n";
                $hasCriticalError = true;
            }
        }
    }

    return $hasCriticalError;
}

function ensureIndex($connection, $tableName, $indexName, $columns) {
    $statement = $connection->prepare(
        "SELECT 1
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND index_name = ?
         LIMIT 1"
    );
    $statement->bind_param("ss", $tableName, $indexName);
    $statement->execute();
    $exists = $statement->get_result()->num_rows > 0;
    $statement->close();

    if ($exists) {
        return true;
    }

    return $connection->query(
        "ALTER TABLE `$tableName` ADD INDEX `$indexName` ($columns)"
    ) === true;
}

function setupCronJob($scriptPath) {
    $cronJob = "* * * * * /usr/bin/php $scriptPath";
    $oldCronJob = "* * * * * /usr/bin/php /var/www/html/marzhelp/cron.php";

    $currentCronJobs = shell_exec('crontab -l 2>/dev/null') ?: ''; 

    $cronLines = explode(PHP_EOL, trim($currentCronJobs));
    $newCronLines = [];

    foreach ($cronLines as $line) {
        if (trim($line) !== $oldCronJob) {
            $newCronLines[] = $line;
        }
    }

    if (!in_array($cronJob, $newCronLines)) {
        $newCronLines[] = $cronJob;
    }

    $newCronJobs = implode(PHP_EOL, $newCronLines) . PHP_EOL;

    file_put_contents('/tmp/crontab.txt', $newCronJobs);
    exec('crontab /tmp/crontab.txt');
    unlink('/tmp/crontab.txt');
}

$hasCriticalError = checkAndCreateTablesAndColumns($botConn);

if (!ensureIndex(
    $botConn,
    'admin_usage',
    'idx_admin_usage_admin_created',
    '`admin_id`, `created_at`'
)) {
    echo "Error creating index `idx_admin_usage_admin_created`: " . $botConn->error . "\n";
    $hasCriticalError = true;
}

$createMarzhelpLimits = "
CREATE TABLE IF NOT EXISTS `marzhelp_limits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type` enum('exclude','dedicated') COLLATE utf8mb4_unicode_ci NOT NULL,
  `admin_id` int NOT NULL,
  `inbound_tag` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_limit` (`type`,`admin_id`,`inbound_tag`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `marzhelp_limits_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

if (!$marzbanConn->query($createMarzhelpLimits)) {
    echo "Error creating table `marzhelp_limits`: " . $marzbanConn->error . "\n";
    $hasCriticalError = true;
}

$createRuntimeSettings = "
CREATE TABLE IF NOT EXISTS `marzhelp_runtime_settings` (
  `setting_name` varchar(64) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
if (!$marzbanConn->query($createRuntimeSettings)) {
    echo "Error creating table `marzhelp_runtime_settings`: " . $marzbanConn->error . "\n";
    $hasCriticalError = true;
}

$marzbanConn->query(
    "INSERT IGNORE INTO marzhelp_runtime_settings (setting_name, setting_value)
     VALUES ('inbound_sync_interval', '10')"
);

$marzbanConn->query("DROP EVENT IF EXISTS `manage_inbound_limits`");
$marzbanConn->query("DROP EVENT IF EXISTS `marzhelp_manage_inbound_limits`");
$createInboundEvent = "
CREATE EVENT `marzhelp_manage_inbound_limits`
ON SCHEDULE EVERY 1 SECOND
DO
BEGIN
    DECLARE sync_interval int DEFAULT 10;

    SELECT GREATEST(1, CAST(`setting_value` AS UNSIGNED))
      INTO sync_interval
      FROM `marzhelp_runtime_settings`
     WHERE `setting_name` = 'inbound_sync_interval';

    IF MOD(UNIX_TIMESTAMP(), sync_interval) = 0 THEN
        INSERT INTO `exclude_inbounds_association` (`proxy_id`, `inbound_tag`)
        SELECT p.`id`, ml.`inbound_tag`
          FROM `marzhelp_limits` ml
          INNER JOIN `users` u ON u.`admin_id` = ml.`admin_id`
          INNER JOIN `proxies` p ON p.`user_id` = u.`id`
          LEFT JOIN `exclude_inbounds_association` eia
            ON eia.`proxy_id` = p.`id`
           AND eia.`inbound_tag` = ml.`inbound_tag`
         WHERE ml.`type` = 'exclude'
           AND eia.`proxy_id` IS NULL;

        DELETE eia
          FROM `exclude_inbounds_association` eia
          INNER JOIN `proxies` p ON p.`id` = eia.`proxy_id`
          INNER JOIN `users` u ON u.`id` = p.`user_id`
          LEFT JOIN `marzhelp_limits` ml
            ON ml.`admin_id` = u.`admin_id`
           AND ml.`inbound_tag` = eia.`inbound_tag`
           AND ml.`type` = 'exclude'
         WHERE ml.`admin_id` IS NULL
           AND NOT EXISTS (
               SELECT 1
                 FROM `marzhelp_limits` dedicated
                WHERE dedicated.`type` = 'dedicated'
                  AND dedicated.`inbound_tag` = eia.`inbound_tag`
                  AND dedicated.`admin_id` <> u.`admin_id`
           );

        INSERT INTO `exclude_inbounds_association` (`proxy_id`, `inbound_tag`)
        SELECT p.`id`, ml.`inbound_tag`
          FROM `marzhelp_limits` ml
          INNER JOIN `users` u ON u.`admin_id` <> ml.`admin_id`
          INNER JOIN `proxies` p ON p.`user_id` = u.`id`
          LEFT JOIN `exclude_inbounds_association` eia
            ON eia.`proxy_id` = p.`id`
           AND eia.`inbound_tag` = ml.`inbound_tag`
         WHERE ml.`type` = 'dedicated'
           AND eia.`proxy_id` IS NULL;

        DELETE eia
          FROM `exclude_inbounds_association` eia
          INNER JOIN `proxies` p ON p.`id` = eia.`proxy_id`
          INNER JOIN `users` u ON u.`id` = p.`user_id`
          INNER JOIN `marzhelp_limits` ml
            ON ml.`admin_id` = u.`admin_id`
           AND ml.`inbound_tag` = eia.`inbound_tag`
           AND ml.`type` = 'dedicated';
    END IF;
END
";
if (!$marzbanConn->query($createInboundEvent)) {
    echo "Error creating event `marzhelp_manage_inbound_limits`: " . $marzbanConn->error . "\n";
    $hasCriticalError = true;
}

$createDeletedUsersLedger = "
CREATE TABLE IF NOT EXISTS `marzhelp_deleted_users` (
  `user_id` int NOT NULL,
  `admin_id` int NOT NULL,
  `used_traffic_total` bigint unsigned NOT NULL DEFAULT 0,
  `allocated_traffic` bigint unsigned DEFAULT NULL,
  `deleted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_marzhelp_deleted_users_admin` (`admin_id`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

if (!$marzbanConn->query($createDeletedUsersLedger)) {
    echo "Error creating table `marzhelp_deleted_users`: " . $marzbanConn->error . "\n";
    $hasCriticalError = true;
}

$createAdminEnforcement = "
CREATE TABLE IF NOT EXISTS `marzhelp_admin_enforcement` (
  `admin_id` int NOT NULL,
  `user_limit` bigint unsigned DEFAULT NULL,
  `traffic_limit` bigint unsigned DEFAULT NULL,
  `traffic_mode` enum('used_traffic','created_traffic') NOT NULL DEFAULT 'used_traffic',
  `traffic_exhausted` tinyint(1) NOT NULL DEFAULT 0,
  `account_expired` tinyint(1) NOT NULL DEFAULT 0,
  `prevent_user_creation` tinyint(1) NOT NULL DEFAULT 0,
  `prevent_user_deletion` tinyint(1) NOT NULL DEFAULT 0,
  `prevent_user_reset` tinyint(1) NOT NULL DEFAULT 0,
  `prevent_revoke_subscription` tinyint(1) NOT NULL DEFAULT 0,
  `prevent_unlimited_traffic` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

if (!$marzbanConn->query($createAdminEnforcement)) {
    echo "Error creating table `marzhelp_admin_enforcement`: " . $marzbanConn->error . "\n";
    $hasCriticalError = true;
}

if (checkAndAddColumns($marzbanConn, 'marzhelp_admin_enforcement', [
    'account_expired' => "ALTER TABLE `marzhelp_admin_enforcement`
                          ADD `account_expired` tinyint(1) NOT NULL DEFAULT 0",
    'prevent_user_creation' => "ALTER TABLE `marzhelp_admin_enforcement`
                                ADD `prevent_user_creation` tinyint(1) NOT NULL DEFAULT 0",
    'prevent_user_deletion' => "ALTER TABLE `marzhelp_admin_enforcement`
                                ADD `prevent_user_deletion` tinyint(1) NOT NULL DEFAULT 0",
    'prevent_user_reset' => "ALTER TABLE `marzhelp_admin_enforcement`
                             ADD `prevent_user_reset` tinyint(1) NOT NULL DEFAULT 0",
    'prevent_revoke_subscription' => "ALTER TABLE `marzhelp_admin_enforcement`
                                      ADD `prevent_revoke_subscription` tinyint(1) NOT NULL DEFAULT 0",
    'prevent_unlimited_traffic' => "ALTER TABLE `marzhelp_admin_enforcement`
                                   ADD `prevent_unlimited_traffic` tinyint(1) NOT NULL DEFAULT 0"
])) {
    $hasCriticalError = true;
}

$legacyDeletionTable = $marzbanConn->query("SHOW TABLES LIKE 'user_deletions'");
if ($legacyDeletionTable && $legacyDeletionTable->num_rows > 0) {
    $legacyBackfill = "
        INSERT INTO `marzhelp_deleted_users`
            (`user_id`, `admin_id`, `used_traffic_total`, `allocated_traffic`, `deleted_at`)
        SELECT
            `user_id`,
            `admin_id`,
            SUM(COALESCE(`used_traffic`, 0) + COALESCE(`reseted_usage`, 0)),
            NULL,
            MAX(COALESCE(`deleted_at`, CURRENT_TIMESTAMP))
        FROM `user_deletions`
        WHERE `user_id` IS NOT NULL AND `admin_id` IS NOT NULL
        GROUP BY `user_id`, `admin_id`
        ON DUPLICATE KEY UPDATE
            `used_traffic_total` = GREATEST(
                `marzhelp_deleted_users`.`used_traffic_total`,
                VALUES(`used_traffic_total`)
            ),
            `deleted_at` = GREATEST(
                `marzhelp_deleted_users`.`deleted_at`,
                VALUES(`deleted_at`)
            )
    ";
    if (!$marzbanConn->query($legacyBackfill)) {
        echo "Error migrating legacy deleted-user traffic: " . $marzbanConn->error . "\n";
        $hasCriticalError = true;
    }
}

if (!$marzbanConn->query("DROP TRIGGER IF EXISTS `marzhelp_capture_user_delete`")) {
    echo "Error dropping trigger `marzhelp_capture_user_delete`: " . $marzbanConn->error . "\n";
    $hasCriticalError = true;
}

$createDeletionTrigger = "
CREATE TRIGGER `marzhelp_capture_user_delete`
BEFORE DELETE ON `users`
FOR EACH ROW
BEGIN
    DECLARE reset_usage bigint unsigned DEFAULT 0;

    SELECT COALESCE(SUM(`used_traffic_at_reset`), 0)
      INTO reset_usage
      FROM `user_usage_logs`
     WHERE `user_id` = OLD.`id`;

    INSERT INTO `marzhelp_deleted_users`
        (`user_id`, `admin_id`, `used_traffic_total`, `allocated_traffic`, `deleted_at`)
    VALUES
        (
            OLD.`id`,
            OLD.`admin_id`,
            COALESCE(OLD.`used_traffic`, 0) + reset_usage,
            OLD.`data_limit`,
            CURRENT_TIMESTAMP
        )
    ON DUPLICATE KEY UPDATE
        `admin_id` = VALUES(`admin_id`),
        `used_traffic_total` = GREATEST(
            `marzhelp_deleted_users`.`used_traffic_total`,
            VALUES(`used_traffic_total`)
        ),
        `allocated_traffic` = VALUES(`allocated_traffic`),
        `deleted_at` = VALUES(`deleted_at`);
END
";

if (!$marzbanConn->query($createDeletionTrigger)) {
    echo "Error creating trigger `marzhelp_capture_user_delete`: " . $marzbanConn->error . "\n";
    $hasCriticalError = true;
}

$legacyRestrictionTriggers = [
    'prevent_user_creation' => 'prevent_user_creation',
    'admin_delete' => 'prevent_user_deletion',
    'prevent_User_Reset_Usage' => 'prevent_user_reset',
    'prevent_revoke_subscription' => 'prevent_revoke_subscription',
    'prevent_unlimited_traffic' => 'prevent_unlimited_traffic'
];
foreach ($legacyRestrictionTriggers as $triggerName => $columnName) {
    $triggerResult = $marzbanConn->query("SHOW CREATE TRIGGER `$triggerName`");
    if ($triggerResult && ($triggerRow = $triggerResult->fetch_assoc())) {
        $triggerSql = $triggerRow['SQL Original Statement'] ?? '';
        if (preg_match('/\\bIN\\s*\\(([^)]*)\\)/i', $triggerSql, $matches)) {
            $adminIds = array_filter(
                array_map('intval', explode(',', $matches[1])),
                static fn($adminId) => $adminId > 0
            );
            $setRestriction = $botConn->prepare(
                "INSERT INTO admin_settings (admin_id, `$columnName`)
                 VALUES (?, 1)
                 ON DUPLICATE KEY UPDATE `$columnName` = 1"
            );
            foreach (array_unique($adminIds) as $adminId) {
                $setRestriction->bind_param('i', $adminId);
                $setRestriction->execute();
            }
            $setRestriction->close();
        }
    }

    if (!$marzbanConn->query("DROP TRIGGER IF EXISTS `$triggerName`")) {
        echo "Error dropping legacy trigger `$triggerName`: " . $marzbanConn->error . "\n";
        $hasCriticalError = true;
    }
}

$legacyEnforcementTriggers = [
    'user_creation_traffic',
    'user_update_traffic',
    'prevent_insert_traffic',
    'prevent_update_traffic',
    'cron_prevent_user_creation',
    'save_user_traffic_used',
    'save_user_traffic_reseted'
];
foreach ($legacyEnforcementTriggers as $triggerName) {
    if (!$marzbanConn->query("DROP TRIGGER IF EXISTS `$triggerName`")) {
        echo "Error dropping legacy trigger `$triggerName`: " . $marzbanConn->error . "\n";
        $hasCriticalError = true;
    }
}

$restrictionRows = $botConn->query(
    "SELECT
        admin_id,
        prevent_user_creation,
        prevent_user_deletion,
        prevent_user_reset,
        prevent_revoke_subscription,
        prevent_unlimited_traffic
     FROM admin_settings"
);
if ($restrictionRows) {
    $seedEnforcement = $marzbanConn->prepare(
        "INSERT INTO marzhelp_admin_enforcement
            (
                admin_id,
                prevent_user_creation,
                prevent_user_deletion,
                prevent_user_reset,
                prevent_revoke_subscription,
                prevent_unlimited_traffic
            )
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            prevent_user_creation = VALUES(prevent_user_creation),
            prevent_user_deletion = VALUES(prevent_user_deletion),
            prevent_user_reset = VALUES(prevent_user_reset),
            prevent_revoke_subscription = VALUES(prevent_revoke_subscription),
            prevent_unlimited_traffic = VALUES(prevent_unlimited_traffic)"
    );
    while ($restriction = $restrictionRows->fetch_assoc()) {
        $seedEnforcement->bind_param(
            'iiiiii',
            $restriction['admin_id'],
            $restriction['prevent_user_creation'],
            $restriction['prevent_user_deletion'],
            $restriction['prevent_user_reset'],
            $restriction['prevent_revoke_subscription'],
            $restriction['prevent_unlimited_traffic']
        );
        $seedEnforcement->execute();
    }
    $seedEnforcement->close();
    $restrictionRows->free();
}

if (!$marzbanConn->query("DROP TRIGGER IF EXISTS `marzhelp_enforce_user_insert`")) {
    echo "Error dropping trigger `marzhelp_enforce_user_insert`: " . $marzbanConn->error . "\n";
    $hasCriticalError = true;
}

$createInsertEnforcementTrigger = "
CREATE TRIGGER `marzhelp_enforce_user_insert`
BEFORE INSERT ON `users`
FOR EACH ROW
BEGIN
    DECLARE configured_user_limit bigint unsigned DEFAULT NULL;
    DECLARE configured_traffic_limit bigint unsigned DEFAULT NULL;
    DECLARE configured_traffic_mode varchar(32) DEFAULT 'used_traffic';
    DECLARE is_traffic_exhausted tinyint DEFAULT 0;
    DECLARE is_account_expired tinyint DEFAULT 0;
    DECLARE must_prevent_creation tinyint DEFAULT 0;
    DECLARE must_prevent_unlimited tinyint DEFAULT 0;
    DECLARE current_user_count bigint unsigned DEFAULT 0;
    DECLARE current_allocated_traffic bigint unsigned DEFAULT 0;

    SELECT
        `user_limit`,
        `traffic_limit`,
        `traffic_mode`,
        `traffic_exhausted`,
        `account_expired`,
        `prevent_user_creation`,
        `prevent_unlimited_traffic`
      INTO
        configured_user_limit,
        configured_traffic_limit,
        configured_traffic_mode,
        is_traffic_exhausted,
        is_account_expired,
        must_prevent_creation,
        must_prevent_unlimited
      FROM `marzhelp_admin_enforcement`
     WHERE `admin_id` = NEW.`admin_id`
     FOR UPDATE;

    IF is_account_expired = 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'MarzHelp: admin account is expired';
    END IF;

    IF must_prevent_creation = 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'MarzHelp: user creation is disabled for this admin';
    END IF;

    IF must_prevent_unlimited = 1 AND NEW.`data_limit` IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'MarzHelp: unlimited user is not allowed for this admin';
    END IF;

    IF configured_user_limit IS NOT NULL THEN
        SELECT COUNT(*) INTO current_user_count
          FROM `users`
         WHERE `admin_id` = NEW.`admin_id`;

        IF current_user_count >= configured_user_limit THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MarzHelp: admin user limit exceeded';
        END IF;
    END IF;

    IF configured_traffic_limit IS NOT NULL THEN
        IF configured_traffic_mode = 'used_traffic' AND is_traffic_exhausted = 1 THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MarzHelp: admin traffic limit exceeded';
        END IF;

        IF configured_traffic_mode = 'created_traffic' THEN
            IF NEW.`data_limit` IS NULL THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'MarzHelp: unlimited user is not allowed for this admin';
            END IF;

            SELECT
                COALESCE(SUM(`data_limit`), 0)
                + COALESCE((
                    SELECT SUM(COALESCE(`allocated_traffic`, `used_traffic_total`))
                    FROM `marzhelp_deleted_users`
                    WHERE `admin_id` = NEW.`admin_id`
                ), 0)
              INTO current_allocated_traffic
              FROM `users`
             WHERE `admin_id` = NEW.`admin_id`;

            IF current_allocated_traffic + NEW.`data_limit` > configured_traffic_limit THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'MarzHelp: admin allocated traffic limit exceeded';
            END IF;
        END IF;
    END IF;
END
";

if (!$marzbanConn->query($createInsertEnforcementTrigger)) {
    echo "Error creating trigger `marzhelp_enforce_user_insert`: " . $marzbanConn->error . "\n";
    $hasCriticalError = true;
}

if (!$marzbanConn->query("DROP TRIGGER IF EXISTS `marzhelp_enforce_user_update`")) {
    echo "Error dropping trigger `marzhelp_enforce_user_update`: " . $marzbanConn->error . "\n";
    $hasCriticalError = true;
}

$createUpdateEnforcementTrigger = "
CREATE TRIGGER `marzhelp_enforce_user_update`
BEFORE UPDATE ON `users`
FOR EACH ROW
BEGIN
    DECLARE configured_traffic_limit bigint unsigned DEFAULT NULL;
    DECLARE configured_traffic_mode varchar(32) DEFAULT 'used_traffic';
    DECLARE current_allocated_traffic bigint unsigned DEFAULT 0;
    DECLARE must_prevent_reset tinyint DEFAULT 0;
    DECLARE must_prevent_revoke tinyint DEFAULT 0;
    DECLARE must_prevent_unlimited tinyint DEFAULT 0;

    SELECT
        COALESCE(`prevent_user_reset`, 0),
        COALESCE(`prevent_revoke_subscription`, 0),
        COALESCE(`prevent_unlimited_traffic`, 0)
      INTO
        must_prevent_reset,
        must_prevent_revoke,
        must_prevent_unlimited
      FROM `marzhelp_admin_enforcement`
     WHERE `admin_id` = OLD.`admin_id`;

    IF must_prevent_reset = 1 AND NEW.`used_traffic` < OLD.`used_traffic` THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'MarzHelp: resetting user traffic is disabled';
    END IF;

    IF must_prevent_revoke = 1
       AND NOT (NEW.`sub_revoked_at` <=> OLD.`sub_revoked_at`) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'MarzHelp: revoking subscriptions is disabled';
    END IF;

    IF NOT (NEW.`admin_id` <=> OLD.`admin_id`)
       OR NOT (NEW.`data_limit` <=> OLD.`data_limit`) THEN
        SELECT
            `traffic_limit`,
            `traffic_mode`,
            `prevent_unlimited_traffic`
          INTO
            configured_traffic_limit,
            configured_traffic_mode,
            must_prevent_unlimited
          FROM `marzhelp_admin_enforcement`
         WHERE `admin_id` = NEW.`admin_id`
         FOR UPDATE;

        IF must_prevent_unlimited = 1 AND NEW.`data_limit` IS NULL THEN
            SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'MarzHelp: unlimited user is not allowed for this admin';
        END IF;

        IF configured_traffic_limit IS NOT NULL
           AND configured_traffic_mode = 'created_traffic' THEN
            IF NEW.`data_limit` IS NULL THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'MarzHelp: unlimited user is not allowed for this admin';
            END IF;

            SELECT
                COALESCE(SUM(`data_limit`), 0)
                + COALESCE((
                    SELECT SUM(COALESCE(`allocated_traffic`, `used_traffic_total`))
                    FROM `marzhelp_deleted_users`
                    WHERE `admin_id` = NEW.`admin_id`
                ), 0)
              INTO current_allocated_traffic
              FROM `users`
             WHERE `admin_id` = NEW.`admin_id`
               AND `id` <> OLD.`id`;

            IF current_allocated_traffic + NEW.`data_limit` > configured_traffic_limit THEN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'MarzHelp: admin allocated traffic limit exceeded';
            END IF;
        END IF;
    END IF;
END
";

if (!$marzbanConn->query($createUpdateEnforcementTrigger)) {
    echo "Error creating trigger `marzhelp_enforce_user_update`: " . $marzbanConn->error . "\n";
    $hasCriticalError = true;
}

if (!$marzbanConn->query("DROP TRIGGER IF EXISTS `marzhelp_enforce_user_delete`")) {
    echo "Error dropping trigger `marzhelp_enforce_user_delete`: " . $marzbanConn->error . "\n";
    $hasCriticalError = true;
}

$createDeleteEnforcementTrigger = "
CREATE TRIGGER `marzhelp_enforce_user_delete`
BEFORE DELETE ON `users`
FOR EACH ROW
BEGIN
    DECLARE must_prevent_deletion tinyint DEFAULT 0;

    SELECT COALESCE(`prevent_user_deletion`, 0)
      INTO must_prevent_deletion
      FROM `marzhelp_admin_enforcement`
     WHERE `admin_id` = OLD.`admin_id`;

    IF must_prevent_deletion = 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'MarzHelp: user deletion is disabled for this admin';
    END IF;
END
";

if (!$marzbanConn->query($createDeleteEnforcementTrigger)) {
    echo "Error creating trigger `marzhelp_enforce_user_delete`: " . $marzbanConn->error . "\n";
    $hasCriticalError = true;
}

$scriptPath = "/var/www/html/marzhelp/crons/cron.php";
setupCronJob($scriptPath);

$marzbanConn->close();
$botConn->close();

exit($hasCriticalError ? 1 : 0);
?>
