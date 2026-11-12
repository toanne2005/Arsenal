<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/bootstrap.php';
arsenal_request_method('POST');

try {
    $payload = arsenal_read_json();
    $orderId = strtoupper(trim((string)($payload['orderId'] ?? '')));
    $phone = arsenal_normalize_phone(trim((string)($payload['phone'] ?? '')));
    if (!preg_match('/^ARS-[A-F0-9]{24}$/', $orderId)) {
        throw new InvalidArgumentException('Mã đơn không đúng định dạng.');
    }

    $statement = arsenal_db()->prepare(
        'SELECT maDH, datLuc, trangThaiXuLy, phuongThucThanhToan, trangThaiThanhToan,
                soTienDaThu, soTienDaPhanBo, tienHang, tienGiam,
                phiVanChuyen, tongPhaiTra, hoTenNhan, diaChiNhan,
                tenNganHangNhan, chuTaiKhoanNhan, soTaiKhoanNhan
         FROM tblDonHang WHERE maDH = :maDH AND dienThoaiNhan = :dienThoaiNhan'
    );
    $statement->execute([
        'maDH' => $orderId,
        'dienThoaiNhan' => $phone,
    ]);
    $order = $statement->fetch();
    if (!$order) {
        arsenal_json_response([
            'code' => 'ORDER_NOT_FOUND',
            'message' => 'Không tìm thấy đơn hàng phù hợp. Vui lòng kiểm tra mã đơn và số điện thoại.',
        ], 404);
    }

    $details = arsenal_db()->prepare(
        'SELECT maSP, tenSPLucDat, soLuong, donGiaLucDat, thanhTien
         FROM tblChiTietDonHang WHERE maDH = :maDH ORDER BY maCTDH'
    );
    $details->execute(['maDH' => $orderId]);
    $order['items'] = array_map(static function (array $item): array {
        return [
            'id' => $item['maSP'],
            'name' => $item['tenSPLucDat'],
            'quantity' => (int)$item['soLuong'],
            'unitPrice' => (float)$item['donGiaLucDat'],
            'lineTotal' => (float)$item['thanhTien'],
        ];
    }, $details->fetchAll());

    arsenal_json_response($order);
} catch (InvalidArgumentException $error) {
    arsenal_json_response(['message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('ARSENAL order lookup: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể tra cứu đơn hàng.'], 500);
}
