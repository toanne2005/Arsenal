<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/bootstrap.php';
arsenal_request_method('GET');

try {
    $grades = arsenal_db()->query(
        'SELECT kyHieu AS code, tenPhanHang AS name, moTa AS description
         FROM tblPhanHangGunpla
         WHERE hoatDong = 1
         ORDER BY kyHieu'
    )->fetchAll();

    arsenal_json_response($grades);
} catch (Throwable $error) {
    error_log('ARSENAL grades: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể tải danh sách phân hạng.'], 500);
}
