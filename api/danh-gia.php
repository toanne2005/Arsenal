<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/bootstrap.php';
arsenal_session_start();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $productId = trim((string)($_GET['productId'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $productId)) {
            throw new InvalidArgumentException('Mã sản phẩm không hợp lệ.');
        }
        $statement = arsenal_db()->prepare(
            "SELECT dg.tenNguoiDanhGia AS name, dg.diemSao AS rating, dg.noiDung AS comment,
                    dg.guiLuc AS createdAt
             FROM tblDanhGia dg
             WHERE dg.maSP=:product AND dg.trangThai='DA_DUYET'
             ORDER BY dg.duyetLuc DESC, dg.maDanhGia DESC"
        );
        $statement->execute(['product' => $productId]);
        arsenal_json_response(['csrfToken' => arsenal_csrf_token(), 'reviews' => $statement->fetchAll()]);
    }

    arsenal_request_method('POST');
    $payload = arsenal_read_json();
    arsenal_require_csrf($payload);
    $orderId = strtoupper(trim((string)($payload['orderId'] ?? '')));
    $productId = strtoupper(trim((string)($payload['productId'] ?? '')));
    $phone = arsenal_normalize_phone(trim((string)($payload['phone'] ?? '')));
    $rating = filter_var($payload['rating'] ?? null, FILTER_VALIDATE_INT);
    $comment = trim((string)($payload['comment'] ?? ''));
    if (!preg_match('/^[A-Z0-9_-]{1,32}$/', $orderId)
        || !preg_match('/^[A-Z0-9_-]{1,32}$/', $productId)
        || strlen($phone) > 16 || $rating === false || $rating < 1 || $rating > 5
        || $comment === '' || mb_strlen($comment, 'UTF-8') > 2000) {
        throw new InvalidArgumentException('Mã đơn, sản phẩm, số sao hoặc nội dung đánh giá không hợp lệ.');
    }

    $db = arsenal_db();
    $order = $db->prepare(
        "SELECT maDH, hoTenNhan FROM tblDonHang
         WHERE maDH=:orderId AND dienThoaiNhan=:phone AND trangThaiXuLy='DA_GIAO'"
    );
    $order->execute(['orderId' => $orderId, 'phone' => $phone]);
    $verifiedOrder = $order->fetch();
    if (!$verifiedOrder) {
        arsenal_json_response(['message' => 'Chỉ người nhận của đơn đã giao mới có thể gửi đánh giá.'], 403);
    }
    $line = $db->prepare('SELECT maSP FROM tblChiTietDonHang WHERE maDH=:orderId AND maSP=:product');
    $line->execute(['orderId' => $orderId, 'product' => $productId]);
    if (!$line->fetchColumn()) {
        arsenal_json_response(['message' => 'Sản phẩm không thuộc đơn hàng đã giao.'], 403);
    }

    $db->prepare(
        "INSERT INTO tblDanhGia
         (tenNguoiDanhGia, diemSao, noiDung, guiLuc, trangThai, maSP, maDH)
         VALUES (:name, :rating, :comment, UTC_TIMESTAMP(3), 'CHO_DUYET', :product, :orderId)"
    )->execute([
        'name' => $verifiedOrder['hoTenNhan'],
        'rating' => $rating,
        'comment' => $comment,
        'product' => $productId,
        'orderId' => $orderId,
    ]);

    arsenal_json_response(['success' => true, 'message' => 'Đánh giá đã gửi và đang chờ kiểm duyệt.'], 201);
} catch (InvalidArgumentException $error) {
    arsenal_json_response(['message' => $error->getMessage()], 422);
} catch (PDOException $error) {
    if (($error->errorInfo[1] ?? null) === 1062) {
        arsenal_json_response(['message' => 'Đơn này đã gửi đánh giá cho sản phẩm.'], 409);
    }
    error_log('ARSENAL review API: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể lưu đánh giá.'], 500);
} catch (Throwable $error) {
    error_log('ARSENAL review API: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể xử lý đánh giá.'], 500);
}
