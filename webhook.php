<?php

require __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/security.php';

if (!marzhelpValidateWebhookSecret($webhookSecret ?? null)) {
    http_response_code(403);
    exit;
}

$rawUpdate = file_get_contents('php://input');
$update = json_decode($rawUpdate, true);
if (!is_array($update)) {
    http_response_code(400);
    exit;
}

require_once __DIR__ . '/bot.php';

try {
    if (isset($update['message']) && is_array($update['message'])) {
        handleMessage($update['message']);
    } elseif (isset($update['callback_query']) && is_array($update['callback_query'])) {
        handleCallbackQuery($update['callback_query']);
    }
} catch (Throwable $e) {
    error_log('MarzHelp webhook error: ' . $e->getMessage());
    http_response_code(500);
}
