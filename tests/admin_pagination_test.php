<?php

require dirname(__DIR__) . '/app/functions/admin_pagination.php';

function assertPagination(int $total, int $requested, int $page, int $pages, int $offset): void
{
    $actual = marzhelpNormalizeAdminPage($requested, $total);
    foreach (['page' => $page, 'pages' => $pages, 'offset' => $offset, 'limit' => 8] as $key => $value) {
        if ($actual[$key] !== $value) {
            throw new RuntimeException("{$total}/{$requested}: {$key} expected {$value}, got {$actual[$key]}");
        }
    }
    $row = marzhelpAdminPaginationRow($actual['page'], $actual['pages'], []);
    foreach ($row as $button) {
        if (strlen($button['callback_data']) > 64) {
            throw new RuntimeException('Telegram callback exceeds 64 bytes');
        }
    }
}

assertPagination(0, 1, 1, 1, 0);
assertPagination(1, 99, 1, 1, 0);
assertPagination(20, 2, 2, 3, 8);
assertPagination(100, 13, 13, 13, 96);
assertPagination(500, 999, 63, 63, 496);
assertPagination(500, -10, 1, 63, 0);

fwrite(STDOUT, "admin pagination tests passed\n");
