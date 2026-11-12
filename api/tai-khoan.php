<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/bootstrap.php';
arsenal_session_start();

function arsenal_email(string $value): string
{
    $email = mb_strtolower(trim($value), 'UTF-8');
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Địa chỉ email không hợp lệ.');
    }

    return $email;
}

function arsenal_customer_password(string $password): void
{
    if (strlen($password) < 10 || strlen($password) > 72) {
        throw new InvalidArgumentException('Mật khẩu cần dài từ 10 đến 72 byte.');
    }
}

function arsenal_get_customer_cart(PDO $db, int $customerId): array
{
    $statement = $db->prepare(
        'SELECT ct.maSP AS id, sp.tenSP AS name, ct.donGiaThamChieu AS price, ct.soLuong AS quantity
         FROM tblGioHang gh
         INNER JOIN tblChiTietGioHang ct ON ct.maGioHang = gh.maGioHang
         INNER JOIN tblSanPham sp ON sp.maSP = ct.maSP
         WHERE gh.maKH = :maKH ORDER BY sp.tenSP'
    );
    $statement->execute(['maKH' => $customerId]);

    return array_map(static function (array $item): array {
        return [
            'id' => $item['id'],
            'name' => $item['name'],
            'price' => (float)$item['price'],
            'quantity' => (int)$item['quantity'],
        ];
    }, $statement->fetchAll());
}

function arsenal_get_customer_favorites(PDO $db, int $customerId): array
{
    $statement = $db->prepare(
        'SELECT maSP FROM tblDanhSachYeuThich WHERE maKH = :maKH ORDER BY danhDauLuc DESC'
    );
    $statement->execute(['maKH' => $customerId]);

    return array_column($statement->fetchAll(), 'maSP');
}

function arsenal_save_customer_cart(PDO $db, int $customerId, array $items): void
{
    if (count($items) > 50) {
        throw new InvalidArgumentException('Giỏ hàng tối đa 50 sản phẩm.');
    }

    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('Sản phẩm trong giỏ không hợp lệ.');
        }
        $id = trim((string)($item['id'] ?? ''));
        $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
        if ($id === '' || strlen($id) > 32 || $quantity === false || $quantity < 1 || $quantity > 99) {
            throw new InvalidArgumentException('Mã sản phẩm hoặc số lượng giỏ hàng không hợp lệ.');
        }
        $normalized[$id] = $quantity;
    }

    $db->beginTransaction();
    $db->prepare('SELECT maKH FROM tblKhachHang WHERE maKH = :maKH FOR UPDATE')
        ->execute(['maKH' => $customerId]);
    $findCart = $db->prepare('SELECT maGioHang FROM tblGioHang WHERE maKH = :maKH FOR UPDATE');
    $findCart->execute(['maKH' => $customerId]);
    $cartId = $findCart->fetchColumn();
    if (!$cartId) {
        $db->prepare(
            'INSERT INTO tblGioHang (taoLuc, capNhatLuc, maKH) VALUES (UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), :maKH)'
        )->execute(['maKH' => $customerId]);
        $cartId = (int)$db->lastInsertId();
    }

    $db->prepare('DELETE FROM tblChiTietGioHang WHERE maGioHang = :maGioHang')
        ->execute(['maGioHang' => $cartId]);
    if ($normalized) {
        $ids = array_keys($normalized);
        $query = $db->prepare(
            'SELECT maSP, giaBan FROM tblSanPham WHERE maSP IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
        );
        $query->execute($ids);
        $products = [];
        foreach ($query->fetchAll() as $product) {
            $products[$product['maSP']] = $product;
        }

        $insert = $db->prepare(
            'INSERT INTO tblChiTietGioHang (soLuong, donGiaThamChieu, maGioHang, maSP)
             VALUES (:soLuong, :donGia, :maGioHang, :maSP)'
        );
        foreach ($normalized as $id => $quantity) {
            if (!isset($products[$id])) {
                throw new InvalidArgumentException('Có sản phẩm không còn tồn tại trong giỏ.');
            }
            $price = $products[$id]['giaBan'] ?? 0;
            $insert->execute([
                'soLuong' => $quantity,
                'donGia' => $price,
                'maGioHang' => $cartId,
                'maSP' => $id,
            ]);
        }
    }

    $db->prepare('UPDATE tblGioHang SET capNhatLuc = UTC_TIMESTAMP(3) WHERE maGioHang = :maGioHang')
        ->execute(['maGioHang' => $cartId]);
    $db->commit();
}

try {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        $customer = arsenal_current_customer();
        arsenal_json_response([
            'csrfToken' => arsenal_csrf_token(),
            'customer' => $customer,
            'favorites' => $customer ? arsenal_get_customer_favorites(arsenal_db(), (int)$customer['maKH']) : [],
            'cart' => $customer ? arsenal_get_customer_cart(arsenal_db(), (int)$customer['maKH']) : [],
        ]);
    }

    arsenal_request_method('POST');
    $payload = arsenal_read_json();
    arsenal_require_csrf($payload);
    $action = (string)($payload['action'] ?? '');
    $db = arsenal_db();

    if ($action === 'register') {
        $name = trim((string)($payload['name'] ?? ''));
        $phone = arsenal_normalize_phone(trim((string)($payload['phone'] ?? '')));
        $email = arsenal_email((string)($payload['email'] ?? ''));
        $address = trim((string)($payload['address'] ?? ''));
        $password = (string)($payload['password'] ?? '');
        arsenal_customer_password($password);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120 || strlen($phone) > 16 || mb_strlen($address, 'UTF-8') > 255) {
            throw new InvalidArgumentException('Họ tên, số điện thoại hoặc địa chỉ không hợp lệ.');
        }

        $statement = $db->prepare(
            "INSERT INTO tblKhachHang (hoTen, dienThoai, email, diaChi, matKhauBam, dangKyLuc, trangThai)
             VALUES (:hoTen, :dienThoai, :email, :diaChi, :matKhauBam, UTC_TIMESTAMP(3), 'HOAT_DONG')"
        );
        $statement->execute([
            'hoTen' => $name,
            'dienThoai' => $phone,
            'email' => $email,
            'diaChi' => $address === '' ? null : $address,
            'matKhauBam' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        session_regenerate_id(true);
        $_SESSION['arsenal_role'] = 'customer';
        $_SESSION['arsenal_customer_id'] = (int)$db->lastInsertId();
        $customer = arsenal_current_customer();
        arsenal_json_response([
            'success' => true,
            'customer' => $customer,
            'csrfToken' => arsenal_regenerate_csrf(),
            'favorites' => [],
            'cart' => [],
        ], 201);
    }

    if ($action === 'login') {
        $email = arsenal_email((string)($payload['email'] ?? ''));
        $statement = $db->prepare(
            'SELECT maKH, matKhauBam FROM tblKhachHang
             WHERE email = :email AND trangThai = :trangThai'
        );
        $statement->execute(['email' => $email, 'trangThai' => 'HOAT_DONG']);
        $customer = $statement->fetch();
        if (!$customer || !password_verify((string)($payload['password'] ?? ''), $customer['matKhauBam'])) {
            arsenal_json_response(['message' => 'Email hoặc mật khẩu không chính xác.'], 401);
        }
        session_regenerate_id(true);
        unset($_SESSION['arsenal_admin_id']);
        $_SESSION['arsenal_role'] = 'customer';
        $_SESSION['arsenal_customer_id'] = (int)$customer['maKH'];
        $profile = arsenal_current_customer();
        arsenal_json_response([
            'success' => true,
            'customer' => $profile,
            'csrfToken' => arsenal_regenerate_csrf(),
            'favorites' => arsenal_get_customer_favorites($db, (int)$customer['maKH']),
            'cart' => arsenal_get_customer_cart($db, (int)$customer['maKH']),
        ]);
    }

    $customerId = arsenal_require_customer();
    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], '', $params['secure'], $params['httponly']);
        }
        session_destroy();
        arsenal_json_response(['success' => true]);
    }

    if ($action === 'profile') {
        $name = trim((string)($payload['name'] ?? ''));
        $phone = arsenal_normalize_phone(trim((string)($payload['phone'] ?? '')));
        $email = arsenal_email((string)($payload['email'] ?? ''));
        $address = trim((string)($payload['address'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120 || strlen($phone) > 16 || mb_strlen($address, 'UTF-8') > 255) {
            throw new InvalidArgumentException('Thông tin hồ sơ không hợp lệ.');
        }
        $statement = $db->prepare(
            'UPDATE tblKhachHang SET hoTen = :hoTen, dienThoai = :dienThoai, email = :email, diaChi = :diaChi
             WHERE maKH = :maKH AND trangThai = :trangThai'
        );
        $statement->execute([
            'hoTen' => $name,
            'dienThoai' => $phone,
            'email' => $email,
            'diaChi' => $address === '' ? null : $address,
            'maKH' => $customerId,
            'trangThai' => 'HOAT_DONG',
        ]);
        arsenal_json_response(['success' => true, 'customer' => arsenal_current_customer()]);
    }

    if ($action === 'password') {
        $currentPassword = (string)($payload['currentPassword'] ?? '');
        $newPassword = (string)($payload['newPassword'] ?? '');
        arsenal_customer_password($newPassword);
        $statement = $db->prepare('SELECT matKhauBam FROM tblKhachHang WHERE maKH = :maKH');
        $statement->execute(['maKH' => $customerId]);
        $hash = $statement->fetchColumn();
        if (!is_string($hash) || !password_verify($currentPassword, $hash)) {
            arsenal_json_response(['message' => 'Mật khẩu hiện tại không đúng.'], 422);
        }
        $db->prepare('UPDATE tblKhachHang SET matKhauBam = :hash WHERE maKH = :maKH')
            ->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'maKH' => $customerId]);
        arsenal_json_response(['success' => true, 'message' => 'Đã đổi mật khẩu.']);
    }

    if ($action === 'favorites') {
        $ids = $payload['items'] ?? null;
        if (!is_array($ids) || count($ids) > 200) {
            throw new InvalidArgumentException('Danh sách yêu thích không hợp lệ.');
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn($id): string => trim((string)$id),
            $ids
        ), static fn(string $id): bool => $id !== '' && strlen($id) <= 32)));

        $db->beginTransaction();
        $db->prepare('DELETE FROM tblDanhSachYeuThich WHERE maKH = :maKH')->execute(['maKH' => $customerId]);
        if ($ids) {
            $productQuery = $db->prepare(
                'SELECT maSP FROM tblSanPham WHERE maSP IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'
            );
            $productQuery->execute($ids);
            $validIds = array_column($productQuery->fetchAll(), 'maSP');
            $insert = $db->prepare(
                'INSERT INTO tblDanhSachYeuThich (danhDauLuc, maKH, maSP)
                 VALUES (UTC_TIMESTAMP(3), :maKH, :maSP)'
            );
            foreach ($validIds as $id) {
                $insert->execute(['maKH' => $customerId, 'maSP' => $id]);
            }
        }
        $db->commit();
        arsenal_json_response(['success' => true, 'favorites' => arsenal_get_customer_favorites($db, $customerId)]);
    }

    if ($action === 'cart') {
        $items = $payload['items'] ?? null;
        if (!is_array($items)) {
            throw new InvalidArgumentException('Giỏ hàng không hợp lệ.');
        }
        arsenal_save_customer_cart($db, $customerId, $items);
        arsenal_json_response(['success' => true, 'cart' => arsenal_get_customer_cart($db, $customerId)]);
    }

    if ($action === 'orders') {
        $statement = $db->prepare(
            'SELECT dh.maDH, dh.datLuc, dh.trangThaiXuLy, dh.phuongThucThanhToan,
                    dh.tienHang, dh.tienGiam, dh.phiVanChuyen, dh.tongPhaiTra,
                    COUNT(ct.maCTDH) AS soDong
             FROM tblDonHang dh
             LEFT JOIN tblChiTietDonHang ct ON ct.maDH = dh.maDH
             WHERE dh.maKH = :maKH
             GROUP BY dh.maDH, dh.datLuc, dh.trangThaiXuLy, dh.phuongThucThanhToan,
                      dh.tienHang, dh.tienGiam, dh.phiVanChuyen, dh.tongPhaiTra
             ORDER BY dh.datLuc DESC'
        );
        $statement->execute(['maKH' => $customerId]);
        arsenal_json_response(['success' => true, 'orders' => $statement->fetchAll()]);
    }

    arsenal_json_response(['message' => 'Thao tác tài khoản không hợp lệ.'], 400);
} catch (InvalidArgumentException $error) {
    arsenal_json_response(['message' => $error->getMessage()], 422);
} catch (PDOException $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    if (($error->errorInfo[1] ?? null) === 1062) {
        arsenal_json_response(['message' => 'Email này đã được đăng ký.'], 409);
    }
    error_log('ARSENAL customer account: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể cập nhật tài khoản.'], 500);
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('ARSENAL customer account: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể xử lý yêu cầu tài khoản.'], 500);
}
