<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/bootstrap.php';
arsenal_request_method('GET');

try {
    $series = arsenal_db()->query(
        'SELECT tenDong AS name, gioiThieu AS description, anhDaiDienUrl AS image
         FROM tblDongMecha
         WHERE hoatDong = 1
         ORDER BY tenDong'
    )->fetchAll();

    arsenal_json_response($series);
} catch (Throwable $error) {
    error_log('ARSENAL Mecha series: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể tải danh sách dòng Mecha.'], 500);
}
