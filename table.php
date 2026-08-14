<?php

/**
 * Canonical-schema verifier and one-time legacy data importer.
 * Marzban Alembic migrations own every table; this script contains no DDL.
 */

require __DIR__ . '/app/bootstrap.php';

const MARZHELP_SOURCE_ID = 'smorad3363-marzban';
const MARZHELP_SCHEMA_VERSION = '1';

function failSchema(string $message): void
{
    fwrite(STDERR, "MarzHelp compatibility error: {$message}\n");
    exit(1);
}

function validIdentifier(string $value, string $label): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $value)) {
        failSchema("invalid {$label}");
    }
    return $value;
}

function normalizedDatabaseHost(string $host): string
{
    $normalized = strtolower(trim($host));
    return in_array($normalized, ['localhost', '127.0.0.1', '::1'], true) ? 'local' : $normalized;
}

function tableExists(mysqli $connection, string $database, string $table): bool
{
    $statement = $connection->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1'
    );
    $statement->bind_param('ss', $database, $table);
    $statement->execute();
    $exists = $statement->get_result()->num_rows === 1;
    $statement->close();
    return $exists;
}

function requireCanonicalSchema(mysqli $connection, string $database): void
{
    $requiredTables = [
        'marzhelp_metadata', 'marzhelp_admin_settings', 'marzhelp_user_states',
        'marzhelp_user_temporaries', 'marzhelp_admin_usage', 'marzhelp_limits',
        'marzhelp_runtime_settings', 'marzhelp_deleted_users',
        'marzhelp_accounting_transactions',
    ];
    foreach ($requiredTables as $table) {
        if (!tableExists($connection, $database, $table)) {
            failSchema(
                "required table {$table} is missing. Install compatible Marzban and run Alembic upgrade head first"
            );
        }
    }

    $result = $connection->query(
        "SELECT `key`, `value` FROM `marzhelp_metadata` WHERE `key` IN ('source_id', 'schema_version')"
    );
    $metadata = [];
    while ($row = $result->fetch_assoc()) {
        $metadata[$row['key']] = $row['value'];
    }
    if (($metadata['source_id'] ?? '') !== MARZHELP_SOURCE_ID) {
        failSchema('Marzban source marker is incompatible');
    }
    if (($metadata['schema_version'] ?? '') !== MARZHELP_SCHEMA_VERSION) {
        failSchema('MarzHelp schema version is incompatible');
    }
}

function importMarker(string $legacySource): string
{
    return 'legacy_import_' . hash('sha256', $legacySource);
}

function markerExists(mysqli $connection, string $marker): bool
{
    $statement = $connection->prepare('SELECT 1 FROM marzhelp_metadata WHERE `key` = ? LIMIT 1');
    $statement->bind_param('s', $marker);
    $statement->execute();
    $exists = $statement->get_result()->num_rows === 1;
    $statement->close();
    return $exists;
}

function scalar(mysqli $connection, string $query): int
{
    $result = $connection->query($query);
    if (!$result) {
        throw new RuntimeException($connection->error);
    }
    return (int) ($result->fetch_row()[0] ?? 0);
}

function transferRows(
    mysqli $source,
    mysqli $target,
    string $select,
    string $insert,
    array $fields
): int {
    $result = $source->query($select);
    if (!$result) {
        throw new RuntimeException($source->error);
    }
    $statement = $target->prepare($insert);
    if (!$statement) {
        throw new RuntimeException($target->error);
    }
    $transferred = 0;
    while ($row = $result->fetch_assoc()) {
        $values = array_map(static fn(string $field) => $row[$field] ?? null, $fields);
        $references = [];
        foreach ($values as $index => $_value) {
            $references[$index] = &$values[$index];
        }
        $statement->bind_param(str_repeat('s', count($values)), ...$references);
        if (!$statement->execute()) {
            throw new RuntimeException($statement->error);
        }
        ++$transferred;
    }
    $statement->close();
    $result->free();
    return $transferred;
}

function importLegacyData(
    mysqli $connection,
    mysqli $legacyConnection,
    string $legacySource,
    string $legacyDatabase,
    string $canonicalDatabase
): void
{
    $marker = importMarker($legacySource);
    if (markerExists($connection, $marker)) {
        echo "Legacy MarzHelp data was already imported from {$legacySource}.\n";
        return;
    }

    $legacyTables = ['admin_settings', 'user_states', 'user_temporaries', 'admin_usage'];
    $present = array_values(array_filter(
        $legacyTables,
        static fn(string $table): bool => tableExists($legacyConnection, $legacyDatabase, $table)
    ));
    if ($present === []) {
        echo "No legacy MarzHelp tables found in {$legacyDatabase}; nothing to import.\n";
        return;
    }

    $legacy = '`' . validIdentifier($legacyDatabase, 'legacy database name') . '`';
    $canonical = '`' . validIdentifier($canonicalDatabase, 'canonical database name') . '`';
    $counts = [];
    $legacyConnection->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
    $connection->begin_transaction();
    try {
        if (in_array('admin_settings', $present, true)) {
            $counts['admin_settings'] = transferRows(
                $legacyConnection,
                $connection,
                "SELECT `admin_id`, `total_traffic`, COALESCE(`used_traffic`, 0) AS `used_traffic`,
                        `expiry_date`, `status`, `user_limit`, `hashed_password_before`,
                        COALESCE(`updated_at`, CURRENT_TIMESTAMP) AS `updated_at`,
                        `last_expiry_notification`, `last_traffic_notification`, `last_traffic_notify`,
                        COALESCE(`calculate_volume`, 'used_traffic') AS `calculate_volume`,
                        COALESCE(`prevent_user_creation`, 0) AS `prevent_user_creation`,
                        COALESCE(`prevent_user_deletion`, 0) AS `prevent_user_deletion`,
                        COALESCE(`prevent_user_reset`, 0) AS `prevent_user_reset`,
                        COALESCE(`prevent_revoke_subscription`, 0) AS `prevent_revoke_subscription`,
                        COALESCE(`prevent_unlimited_traffic`, 0) AS `prevent_unlimited_traffic`
                   FROM {$legacy}.`admin_settings`",
                "INSERT INTO {$canonical}.`marzhelp_admin_settings`
                    (`admin_id`, `total_traffic`, `used_traffic`, `expiry_date`, `status`, `user_limit`,
                     `hashed_password_before`, `updated_at`, `last_expiry_notification`,
                     `last_traffic_notification`, `last_traffic_notify`, `calculate_volume`,
                     `prevent_user_creation`, `prevent_user_deletion`, `prevent_user_reset`,
                     `prevent_revoke_subscription`, `prevent_unlimited_traffic`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    `total_traffic` = VALUES(`total_traffic`), `used_traffic` = VALUES(`used_traffic`),
                    `expiry_date` = VALUES(`expiry_date`), `status` = VALUES(`status`),
                    `user_limit` = VALUES(`user_limit`), `hashed_password_before` = VALUES(`hashed_password_before`),
                    `last_expiry_notification` = VALUES(`last_expiry_notification`),
                    `last_traffic_notification` = VALUES(`last_traffic_notification`),
                    `last_traffic_notify` = VALUES(`last_traffic_notify`),
                    `calculate_volume` = VALUES(`calculate_volume`),
                    `prevent_user_creation` = VALUES(`prevent_user_creation`),
                    `prevent_user_deletion` = VALUES(`prevent_user_deletion`),
                    `prevent_user_reset` = VALUES(`prevent_user_reset`),
                    `prevent_revoke_subscription` = VALUES(`prevent_revoke_subscription`),
                    `prevent_unlimited_traffic` = VALUES(`prevent_unlimited_traffic`)",
                [
                    'admin_id', 'total_traffic', 'used_traffic', 'expiry_date', 'status', 'user_limit',
                    'hashed_password_before', 'updated_at', 'last_expiry_notification',
                    'last_traffic_notification', 'last_traffic_notify', 'calculate_volume',
                    'prevent_user_creation', 'prevent_user_deletion', 'prevent_user_reset',
                    'prevent_revoke_subscription', 'prevent_unlimited_traffic',
                ]
            );
        }

        if (in_array('user_states', $present, true)) {
            $counts['user_states'] = transferRows(
                $legacyConnection,
                $connection,
                "SELECT `user_id`, `username`, `lang`, `state`, `admin_id`,
                        COALESCE(`updated_at`, CURRENT_TIMESTAMP) AS `updated_at`, `data`, `message_id`,
                        COALESCE(`template_index`, 0) AS `template_index`
                   FROM {$legacy}.`user_states`",
                "INSERT INTO {$canonical}.`marzhelp_user_states`
                    (`user_id`, `username`, `lang`, `state`, `admin_id`, `updated_at`, `data`, `message_id`, `template_index`)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    `username` = VALUES(`username`), `lang` = VALUES(`lang`), `state` = VALUES(`state`),
                    `admin_id` = VALUES(`admin_id`), `updated_at` = VALUES(`updated_at`),
                    `data` = VALUES(`data`), `message_id` = VALUES(`message_id`),
                    `template_index` = VALUES(`template_index`)",
                ['user_id', 'username', 'lang', 'state', 'admin_id', 'updated_at', 'data', 'message_id', 'template_index']
            );
        }

        if (in_array('user_temporaries', $present, true)) {
            $counts['user_temporaries'] = transferRows(
                $legacyConnection,
                $connection,
                "SELECT `user_id`, `user_key`, `value` FROM {$legacy}.`user_temporaries`",
                "INSERT INTO {$canonical}.`marzhelp_user_temporaries` (`user_id`, `user_key`, `value`)
                 VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                ['user_id', 'user_key', 'value']
            );
        }

        if (in_array('admin_usage', $present, true)) {
            $counts['admin_usage'] = transferRows(
                $legacyConnection,
                $connection,
                "SELECT `id`, `admin_id`, `used_traffic_gb`,
                        COALESCE(`created_at`, CURRENT_TIMESTAMP) AS `created_at`
                   FROM {$legacy}.`admin_usage`",
                "INSERT IGNORE INTO {$canonical}.`marzhelp_admin_usage`
                    (`id`, `admin_id`, `used_traffic_gb`, `created_at`) VALUES (?, ?, ?, ?)",
                ['id', 'admin_id', 'used_traffic_gb', 'created_at']
            );
        }

        $targets = [
            'admin_settings' => 'marzhelp_admin_settings', 'user_states' => 'marzhelp_user_states',
            'user_temporaries' => 'marzhelp_user_temporaries', 'admin_usage' => 'marzhelp_admin_usage',
        ];
        foreach ($counts as $legacyTable => $sourceCount) {
            $targetCount = scalar($connection, "SELECT COUNT(*) FROM {$canonical}.`{$targets[$legacyTable]}`");
            if ($targetCount < $sourceCount) {
                throw new RuntimeException("row-count verification failed for {$legacyTable}");
            }
        }

        $summary = json_encode(['source' => $legacySource, 'counts' => $counts], JSON_THROW_ON_ERROR);
        $statement = $connection->prepare(
            'INSERT INTO marzhelp_metadata (`key`, `value`, `updated_at`) VALUES (?, ?, CURRENT_TIMESTAMP)'
        );
        $statement->bind_param('ss', $marker, $summary);
        $statement->execute();
        $statement->close();
        $connection->commit();
        $legacyConnection->commit();
        echo "Legacy data imported and verified. Legacy database remains untouched and is obsolete.\n";
    } catch (Throwable $exception) {
        $connection->rollback();
        $legacyConnection->rollback();
        failSchema('legacy import failed: ' . $exception->getMessage());
    }
}

$databaseUser = !empty($migrationDbUser) ? $migrationDbUser : $vpnDbUser;
$databasePassword = !empty($migrationDbPass) ? $migrationDbPass : $vpnDbPass;
$canonicalDatabase = validIdentifier($vpnDbName, 'Marzban database name');
$legacyDatabase = validIdentifier($botDbName ?? $vpnDbName, 'legacy database name');
$connection = new mysqli($vpnDbHost, $databaseUser, $databasePassword, $canonicalDatabase);
if ($connection->connect_error) {
    failSchema('cannot connect to the Marzban database');
}
$connection->set_charset('utf8mb4');
requireCanonicalSchema($connection, $canonicalDatabase);

$legacyHost = $botDbHost ?? $vpnDbHost;
$sameDatabase = $legacyDatabase === $canonicalDatabase
    && normalizedDatabaseHost($legacyHost) === normalizedDatabaseHost($vpnDbHost);
if (!$sameDatabase) {
    $legacyUser = $botDbUser ?? $vpnDbUser;
    $legacyPassword = $botDbPass ?? $vpnDbPass;
    $legacyConnection = new mysqli($legacyHost, $legacyUser, $legacyPassword, $legacyDatabase);
    if ($legacyConnection->connect_error) {
        failSchema("cannot connect to legacy database {$legacyHost}/{$legacyDatabase}; legacy data was not changed");
    }
    $legacyConnection->set_charset('utf8mb4');
    importLegacyData(
        $connection,
        $legacyConnection,
        $legacyHost . '/' . $legacyDatabase,
        $legacyDatabase,
        $canonicalDatabase
    );
    $legacyConnection->close();
}
$connection->close();
echo "MarzHelp schema version 1 verified. No schema changes were made.\n";
