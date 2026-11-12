<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/bootstrap.php';
arsenal_request_method('GET');

try {
    $db = arsenal_db();
    $sql = <<<'SQL'
        SELECT
            sp.maSP AS id,
            sp.tenSP AS name,
            sp.giaBan AS price,
            sp.tonKhaDung AS stock,
            sp.anhDaiDienUrl AS image,
            sp.anhBoSung AS gallery,
            sp.tyLe AS scale,
            sp.moTa AS description,
            sp.thongSoKyThuat AS specifications,
            dm.tenDanhMuc AS category,
            ph.kyHieu AS gradeCode,
            ph.tenPhanHang AS grade,
            dong.tenDong AS series
        FROM tblSanPham AS sp
        INNER JOIN tblDanhMuc AS dm ON dm.maDM = sp.maDM AND dm.hoatDong = 1
        LEFT JOIN tblPhanHangGunpla AS ph
            ON ph.maPhanHang = sp.maPhanHang AND ph.hoatDong = 1
        LEFT JOIN tblDongMecha AS dong
            ON dong.maDong = sp.maDong AND dong.hoatDong = 1
        WHERE sp.trangThai = 'DANG_BAN'
        ORDER BY sp.themLuc DESC, sp.maSP
        SQL;

    $products = $db->query($sql)->fetchAll();
    arsenal_json_response($products);
} catch (Throwable $error) {
    error_log('ARSENAL catalog: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể tải danh sách sản phẩm.'], 500);
}
