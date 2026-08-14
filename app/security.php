<?php

/**
 * Security helpers that do not depend on Telegram or database bootstrap code.
 */

function marzhelpRequestHeader(string $name): ?string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey])) {
        return trim($_SERVER[$serverKey]);
    }

    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $headerName => $value) {
            if (strcasecmp($headerName, $name) === 0 && is_string($value)) {
                return trim($value);
            }
        }
    }

    return null;
}

function marzhelpValidateWebhookSecret(?string $expectedSecret): bool
{
    if ($expectedSecret === null || strlen($expectedSecret) < 32) {
        return false;
    }

    $providedSecret = marzhelpRequestHeader('X-Telegram-Bot-Api-Secret-Token');

    return $providedSecret !== null && hash_equals($expectedSecret, $providedSecret);
}

/**
 * Return the admin id carried by an admin-scoped callback.
 *
 * Only known callback prefixes are accepted. This avoids interpreting unrelated
 * numeric callback values, such as a language or pagination value, as admin ids.
 */
function marzhelpCallbackAdminId(string $callbackData): ?int
{
    $colonPrefixes = [
        'select_admin',
        'back_to_admin_management',
        'protocol_settings',
        'show_restrictions',
        'toggle_prevent_revoke_subscription',
        'toggle_prevent_user_creation',
        'toggle_prevent_unlimited_traffic',
        'toggle_prevent_user_deletion',
        'toggle_prevent_user_reset',
        'set_user_limit',
        'set_user_limit_value',
        'custom_set_user_limit',
        'reduce_time',
        'add_time',
        'set_traffic',
        'set_traffic_unlimited',
        'subtract_traffic',
        'add_traffic',
        'custom_subtract_traffic',
        'custom_add_traffic',
        'set_expiry',
        'set_expiry_unlimited',
        'set_expiry_days',
        'custom_expiry',
        'disable_users',
        'confirm_disable_yes',
        'enable_users',
        'security',
        'change_password',
        'change_sudo',
        'confirm_sudo_yes',
        'confirm_sudo_no',
        'change_telegram_id',
        'change_username',
        'set_sudo_yes',
        'set_sudo_no',
        'confirm_delete_admin',
        'delete_admin_confirmed',
        'disable_inbound',
        'disable_inbounds',
        'disable_inbound_select',
        'enable_inbound',
        'enable_inbounds',
        'enable_inbound_select',
        'limit_inbounds',
        'set_event_time',
        'set_interval',
        'toggle_inbound',
        'confirm_inbounds_limit',
        'confirm_inbounds',
        'add_protocol',
        'remove_protocol',
        'add_data_limit',
        'subtract_data_limit',
        'calculate_volume',
    ];

    $prefixExpression = implode('|', array_map(
        static fn(string $prefix): string => preg_quote($prefix, '/'),
        $colonPrefixes
    ));

    if (preg_match('/^(?:' . $prefixExpression . '):(\d+)(?::|$)/', $callbackData, $matches)) {
        return (int)$matches[1];
    }

    if (preg_match(
        '/^(?:select_add_protocol|select_remove_protocol|set_calculate_volume):[^:]+:(\d+)$/',
        $callbackData,
        $matches
    )) {
        return (int)$matches[1];
    }

    $underscorePrefixes = [
        'disable_users',
        'enable_users',
        'change_password',
        'restore_password',
        'confirm_delete_admin',
        'delete_admin_confirmed',
    ];

    $prefixExpression = implode('|', array_map(
        static fn(string $prefix): string => preg_quote($prefix, '/'),
        $underscorePrefixes
    ));

    if (preg_match('/^(?:' . $prefixExpression . ')_(\d+)(?:_|$)/', $callbackData, $matches)) {
        return (int)$matches[1];
    }

    return null;
}

function marzhelpLimitedAdminId(mysqli $connection, int $telegramId): ?int
{
    $statement = $connection->prepare(
        'SELECT id FROM admins WHERE telegram_id = ? ORDER BY id LIMIT 1'
    );
    if (!$statement) {
        return null;
    }

    $statement->bind_param('i', $telegramId);
    if (!$statement->execute()) {
        $statement->close();
        return null;
    }

    $result = $statement->get_result();
    $row = $result->fetch_assoc();
    $statement->close();

    return $row ? (int)$row['id'] : null;
}

function marzhelpCanManageAdmin(
    mysqli $connection,
    int $telegramId,
    string $role,
    int $targetAdminId
): bool {
    if ($role === 'main_admin') {
        return true;
    }

    if ($role !== 'limited_admin') {
        return false;
    }

    $statement = $connection->prepare(
        'SELECT 1 FROM admins WHERE id = ? AND telegram_id = ? LIMIT 1'
    );
    if (!$statement) {
        return false;
    }

    $statement->bind_param('ii', $targetAdminId, $telegramId);
    if (!$statement->execute()) {
        $statement->close();
        return false;
    }

    $allowed = $statement->get_result()->num_rows === 1;
    $statement->close();

    return $allowed;
}
