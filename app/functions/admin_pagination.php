<?php

const MARZHELP_ADMIN_PAGE_SIZE = 8;

function marzhelpNormalizeAdminPage(int $requestedPage, int $total, int $pageSize = MARZHELP_ADMIN_PAGE_SIZE): array
{
    $pageSize = max(1, $pageSize);
    $pages = max(1, (int) ceil(max(0, $total) / $pageSize));
    $page = min(max(1, $requestedPage), $pages);
    return [
        'page' => $page,
        'pages' => $pages,
        'offset' => ($page - 1) * $pageSize,
        'limit' => $pageSize,
    ];
}

function marzhelpAdminPaginationRow(int $page, int $pages, array $lang): array
{
    $row = [];
    if ($page > 1) {
        $row[] = ['text' => $lang['previous'] ?? '‹', 'callback_data' => 'manage_admins:' . ($page - 1)];
    }
    $row[] = ['text' => $page . '/' . $pages, 'callback_data' => 'noop'];
    if ($page < $pages) {
        $row[] = ['text' => $lang['next'] ?? '›', 'callback_data' => 'manage_admins:' . ($page + 1)];
    }
    return $row;
}
