<?php

require dirname(__DIR__) . '/app/security.php';

function assertSameValue($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(
            STDERR,
            $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL
        );
        exit(1);
    }
}

$secret = str_repeat('a', 64);
$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $secret;
assertSameValue(true, marzhelpValidateWebhookSecret($secret), 'valid webhook secret');
assertSameValue(false, marzhelpValidateWebhookSecret(str_repeat('b', 64)), 'invalid webhook secret');
assertSameValue(false, marzhelpValidateWebhookSecret('short'), 'short webhook secret');

assertSameValue(42, marzhelpCallbackAdminId('select_admin:42'), 'select callback');
assertSameValue(42, marzhelpCallbackAdminId('add_traffic:42:500'), 'traffic callback');
assertSameValue(42, marzhelpCallbackAdminId('disable_users_42'), 'user callback');
assertSameValue(42, marzhelpCallbackAdminId('show_restrictions:42'), 'restriction callback');
assertSameValue(42, marzhelpCallbackAdminId('confirm_delete_admin:42'), 'delete callback');
assertSameValue(42, marzhelpCallbackAdminId('select_add_protocol:vless:42'), 'protocol callback');
assertSameValue(42, marzhelpCallbackAdminId('set_calculate_volume:used_traffic:42'), 'volume callback');
assertSameValue(42, marzhelpCallbackAdminId('set_max_duration:42'), 'duration callback');
assertSameValue(null, marzhelpCallbackAdminId('set_lang_fa'), 'non-admin callback');
assertSameValue(null, marzhelpCallbackAdminId('unknown:42'), 'unknown callback');

fwrite(STDOUT, "security tests passed\n");
