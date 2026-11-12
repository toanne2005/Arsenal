<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/payment-service.php';
$db = arsenal_db();
$orders = $db->query(
    "SELECT maDH FROM tblDonHang
     WHERE phuongThucThanhToan='CHUYEN_KHOAN'
       AND trangThaiXuLy='CHO_XAC_NHAN'
       AND hanChuyenKhoan < UTC_TIMESTAMP(3)
       AND soTienDaThu=0
     ORDER BY hanChuyenKhoan LIMIT 200"
)->fetchAll(PDO::FETCH_COLUMN);
$expired = 0;

foreach ($orders as $orderId) {
    $db->beginTransaction();
    try {
        $orderQuery = $db->prepare(
            "SELECT maDH FROM tblDonHang
             WHERE maDH=:id AND phuongThucThanhToan='CHUYEN_KHOAN'
               AND trangThaiXuLy='CHO_XAC_NHAN' AND hanChuyenKhoan<UTC_TIMESTAMP(3)
               AND soTienDaThu=0 AND tonKhoDaGiu=1 FOR UPDATE"
        );
        $orderQuery->execute(['id' => $orderId]);
        if (!$orderQuery->fetchColumn()) {
            $db->commit();
            continue;
        }
        $items = $db->prepare('SELECT maSP, soLuong FROM tblChiTietDonHang WHERE maDH=:id FOR UPDATE');
        $items->execute(['id' => $orderId]);
        foreach ($items->fetchAll() as $item) {
            $stock = $db->prepare('SELECT tonKhaDung FROM tblSanPham WHERE maSP=:product FOR UPDATE');
            $stock->execute(['product' => $item['maSP']]);
            $before = $stock->fetchColumn();
            if ($before === false) {
                throw new RuntimeException('Thiếu sản phẩm ' . $item['maSP'] . ' khi hoàn tồn đơn ' . $orderId);
            }
            $after = (int)$before + (int)$item['soLuong'];
            if ($after > 4294967295) {
                throw new RuntimeException('Tồn kho vượt giới hạn kiểu dữ liệu khi hoàn đơn ' . $orderId);
            }
            $db->prepare('UPDATE tblSanPham SET tonKhaDung=:after WHERE maSP=:product')
                ->execute(['after' => $after, 'product' => $item['maSP']]);
            $db->prepare(
                'INSERT INTO tblLichSuTonKho
                 (loaiBienDong, soLuongThayDoi, soLuongTruoc, soLuongSau, thucHienLuc,
                  lyDo, maSP, maQTVThucHien, maDH)
                 VALUES (\'TRA_HUY\', :delta, :before, :after, UTC_TIMESTAMP(3),
                  :reason, :product, NULL, :orderId)'
            )->execute([
                'delta' => (int)$item['soLuong'],
                'before' => (int)$before,
                'after' => $after,
                'reason' => 'Hoàn tồn tự động do đơn chuyển khoản quá hạn.',
                'product' => $item['maSP'],
                'orderId' => $orderId,
            ]);
        }
        $coupon = $db->prepare(
            "SELECT maCode FROM tblLichSuApDungMaGiamGia
             WHERE maDH=:id AND trangThaiLuot='DANG_GIU' FOR UPDATE"
        );
        $coupon->execute(['id' => $orderId]);
        $code = $coupon->fetchColumn();
        if ($code !== false) {
            $db->prepare('UPDATE tblMaGiamGia SET luotDangGiu=luotDangGiu-1 WHERE maCode=:code AND luotDangGiu>0')
                ->execute(['code' => $code]);
            if ((int)$db->query('SELECT ROW_COUNT()')->fetchColumn() !== 1) {
                throw new RuntimeException('Không thể giải phóng lượt mã giảm giá của đơn ' . $orderId);
            }
            $db->prepare(
                "UPDATE tblLichSuApDungMaGiamGia
                 SET trangThaiLuot='DA_HOAN_LUOT', hoanLuotLuc=UTC_TIMESTAMP(3)
                 WHERE maDH=:id AND trangThaiLuot='DANG_GIU'"
            )->execute(['id' => $orderId]);
        }
        $db->prepare(
            "UPDATE tblDonHang SET trangThaiXuLy='DA_HUY', tonKhoDaGiu=0,
                    huyLuc=UTC_TIMESTAMP(3), lyDoHuy='Quá hạn chuyển khoản 24 giờ'
             WHERE maDH=:id"
        )->execute(['id' => $orderId]);
        arsenal_payment_log($db, 'HUY_QUA_HAN', 'TU_DONG', (string)$orderId,
            'Tự động hủy đơn chuyển khoản quá hạn, chưa thu tiền; hoàn tồn kho và lượt mã giảm giá.',
            'CHO_XAC_NHAN', 'DA_HUY', null, null, null, 'transfer-expiry-worker');
        $db->commit();
        $expired++;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('ARSENAL expire transfer order ' . $orderId . ': ' . $error->getMessage());
    }
}

fwrite(STDOUT, "Expired orders: {$expired}\n");
