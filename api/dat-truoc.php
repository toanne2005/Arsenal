<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/bootstrap.php';
arsenal_session_start();

function arsenal_preorder_replay(PDO $db, string $requestId, array $request): ?array
{
    $statement = $db->prepare(
        'SELECT maYCDT, hoTenNguoiDat, dienThoaiLienHe, emailLienHe, soLuong, maSP
         FROM tblYeuCauDatTruoc WHERE maYCDT=:id'
    );
    $statement->execute(['id' => $requestId]);
    $stored = $statement->fetch();
    if (!$stored) {
        return null;
    }
    if ($stored['hoTenNguoiDat'] !== $request['name']
        || $stored['dienThoaiLienHe'] !== $request['phone']
        || (string)($stored['emailLienHe'] ?? '') !== $request['email']
        || (int)$stored['soLuong'] !== $request['quantity']
        || $stored['maSP'] !== $request['productId']) {
        arsenal_json_response(['message' => 'Mã yêu cầu đã được dùng với thông tin khác.'], 409);
    }

    return ['success' => true, 'requestId' => $requestId, 'replayed' => true];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $products = arsenal_db()->query(
            "SELECT maSP AS id, tenSP AS name, giaThamKhao AS referencePrice,
                    ngayDuKienPhatHanh AS releaseDate
             FROM tblSanPham
             WHERE trangThai='SAP_MO_BAN' AND nhanDatTruoc=1
             ORDER BY ngayDuKienPhatHanh, tenSP"
        )->fetchAll();
        arsenal_json_response(['csrfToken' => arsenal_csrf_token(), 'products' => $products]);
    }

    arsenal_request_method('POST');
    $payload = arsenal_read_json();
    arsenal_require_csrf($payload);
    $productId = trim((string)($payload['productId'] ?? ''));
    $name = trim((string)($payload['name'] ?? ''));
    $phone = arsenal_normalize_phone(trim((string)($payload['phone'] ?? '')));
    $email = trim((string)($payload['email'] ?? ''));
    $quantity = filter_var($payload['quantity'] ?? null, FILTER_VALIDATE_INT);
    $requestKey = strtoupper(trim((string)($payload['requestId'] ?? '')));
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $productId)
        || $name === '' || mb_strlen($name, 'UTF-8') > 120
        || $quantity === false || $quantity < 1 || $quantity > 20
        || !preg_match('/^[A-F0-9]{24}$/', $requestKey)
        || strlen($phone) > 16 || ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254))) {
        throw new InvalidArgumentException('Thông tin đặt trước không hợp lệ.');
    }

    $db = arsenal_db();
    $requestId = 'PRE-' . $requestKey;
    $request = [
        'name' => $name,
        'phone' => $phone,
        'email' => $email,
        'quantity' => $quantity,
        'productId' => $productId,
    ];
    $replayed = arsenal_preorder_replay($db, $requestId, $request);
    if ($replayed !== null) {
        arsenal_json_response($replayed);
    }
    $product = $db->prepare(
        "SELECT maSP FROM tblSanPham
         WHERE maSP=:id AND trangThai='SAP_MO_BAN' AND nhanDatTruoc=1"
    );
    $product->execute(['id' => $productId]);
    if (!$product->fetchColumn()) {
        arsenal_json_response(['message' => 'Sản phẩm hiện không nhận đặt trước.'], 409);
    }

    $db->prepare(
        "INSERT INTO tblYeuCauDatTruoc
         (maYCDT, hoTenNguoiDat, dienThoaiLienHe, emailLienHe, soLuong, guiLuc, trangThai, maSP)
         VALUES (:id, :name, :phone, :email, :quantity, UTC_TIMESTAMP(3), 'CHO_XAC_NHAN', :product)"
    )->execute([
        'id' => $requestId,
        'name' => $name,
        'phone' => $phone,
        'email' => $email === '' ? null : $email,
        'quantity' => $quantity,
        'product' => $productId,
    ]);

    arsenal_json_response(['success' => true, 'requestId' => $requestId, 'replayed' => false], 201);
} catch (InvalidArgumentException $error) {
    arsenal_json_response(['message' => $error->getMessage()], 422);
} catch (PDOException $error) {
    if (($error->errorInfo[1] ?? null) === 1062 && isset($requestId, $request)) {
        $replayed = arsenal_preorder_replay(arsenal_db(), $requestId, $request);
        if ($replayed !== null) {
            arsenal_json_response($replayed);
        }
    }
    error_log('ARSENAL preorder API: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể lưu yêu cầu đặt trước.'], 500);
} catch (Throwable $error) {
    error_log('ARSENAL preorder API: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể xử lý yêu cầu đặt trước.'], 500);
}
