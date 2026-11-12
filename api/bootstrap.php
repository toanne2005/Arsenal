<?php

declare(strict_types=1);

function arsenal_config(): array
{
    static $config;
    if ($config === null) {
        $configPath = __DIR__ . '/config.local.php';
        if (!is_file($configPath)) {
            throw new RuntimeException('Thiếu cấu hình cục bộ api/config.local.php.');
        }
        $config = require $configPath;
    }

    return $config;
}

function arsenal_db(): PDO
{
    static $db;
    if ($db instanceof PDO) {
        return $db;
    }

    $config = arsenal_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db_host'],
        $config['db_port'],
        $config['db_name']
    );
    $db = new PDO($dsn, $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $db;
}

function arsenal_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function arsenal_read_json(): array
{
    try {
        $payload = json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new InvalidArgumentException('Dữ liệu JSON không hợp lệ.');
    }

    if (!is_array($payload)) {
        throw new InvalidArgumentException('Dữ liệu gửi lên không hợp lệ.');
    }

    return $payload;
}

function arsenal_normalize_phone(string $value): string
{
    if (!preg_match('/^[0-9\s()+.-]+$/', $value)) {
        throw new InvalidArgumentException('Số điện thoại không hợp lệ.');
    }

    $phone = preg_replace('/\D+/', '', $value);
    if (!is_string($phone) || strlen($phone) < 9 || strlen($phone) > 15) {
        throw new InvalidArgumentException('Số điện thoại cần có từ 9 đến 15 chữ số.');
    }

    return $phone;
}

function arsenal_money_cents($value): int
{
    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 1000000000000) {
        throw new InvalidArgumentException('Giá trị tiền không hợp lệ.');
    }

    return (int)round((float)$value * 100);
}

function arsenal_format_money(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

function arsenal_request_method(string $method): void
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $method) {
        header('Allow: ' . $method);
        arsenal_json_response(['message' => 'Phương thức yêu cầu không được hỗ trợ.'], 405);
    }
}

function arsenal_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function arsenal_csrf_token(): string
{
    arsenal_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function arsenal_require_csrf(array $payload = []): void
{
    $expected = arsenal_csrf_token();
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $payload['csrfToken'] ?? '');
    if ($provided === '' || !hash_equals($expected, $provided)) {
        arsenal_json_response(['message' => 'Phiên bảo mật hết hạn. Hãy tải lại trang và thử lại.'], 403);
    }
}

function arsenal_regenerate_csrf(): string
{
    arsenal_session_start();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    return $_SESSION['csrf_token'];
}

function arsenal_require_customer(): int
{
    arsenal_session_start();
    if (($_SESSION['arsenal_role'] ?? null) !== 'customer' || empty($_SESSION['arsenal_customer_id'])) {
        arsenal_json_response(['message' => 'Vui lòng đăng nhập tài khoản khách hàng.'], 401);
    }

    $customerId = (int)$_SESSION['arsenal_customer_id'];
    $statement = arsenal_db()->prepare(
        "SELECT maKH FROM tblKhachHang WHERE maKH = :maKH AND trangThai = 'HOAT_DONG'"
    );
    $statement->execute(['maKH' => $customerId]);
    if (!$statement->fetchColumn()) {
        unset($_SESSION['arsenal_customer_id'], $_SESSION['arsenal_role']);
        arsenal_json_response(['message' => 'Tài khoản khách hàng đã bị khóa.'], 401);
    }

    return $customerId;
}

function arsenal_require_admin(array $permissions = []): int
{
    arsenal_session_start();
    if (($_SESSION['arsenal_role'] ?? null) !== 'admin' || empty($_SESSION['arsenal_admin_id'])) {
        arsenal_json_response(['message' => 'Vui lòng đăng nhập quản trị viên.'], 401);
    }

    $adminId = (int)$_SESSION['arsenal_admin_id'];
    $statement = arsenal_db()->prepare(
        "SELECT quyen FROM tblQuanTriVien WHERE maQTV = :maQTV AND trangThai = 'HOAT_DONG'"
    );
    $statement->execute(['maQTV' => $adminId]);
    $rawPermissions = $statement->fetchColumn();
    $granted = is_string($rawPermissions) ? json_decode($rawPermissions, true) : null;
    if (!is_array($granted)) {
        arsenal_json_response(['message' => 'Tài khoản quản trị không có quyền hợp lệ.'], 403);
    }
    $granted = arsenal_admin_permissions($granted);
    if (!in_array('*', $granted, true) && !in_array('ADMIN', $granted, true)) {
        foreach ($permissions as $permission) {
            if (!in_array($permission, $granted, true)) {
                arsenal_json_response(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
            }
        }
    }

    return $adminId;
}

function arsenal_admin_permissions(array $permissions): array
{
    $legacyFullAdmin = ['SAN_PHAM', 'DANH_MUC', 'KHACH_HANG', 'DON_HANG', 'KHO'];
    $currentFullAdmin = [
        'SAN_PHAM', 'DANH_MUC', 'KHACH_HANG', 'DON_HANG', 'KHO', 'DAT_TRUOC',
        'KHUYEN_MAI', 'DANH_GIA', 'THANH_TOAN', 'BAO_CAO', 'GOI_Y_AI',
    ];
    sort($permissions);
    sort($legacyFullAdmin);
    if ($permissions === $legacyFullAdmin) {
        return array_merge($permissions, [
            'DAT_TRUOC', 'KHUYEN_MAI', 'DANH_GIA', 'THANH_TOAN', 'BAO_CAO', 'GOI_Y_AI',
        ]);
    }

    sort($currentFullAdmin);
    if ($permissions === $currentFullAdmin) {
        return $permissions;
    }

    return $permissions;
}

function arsenal_current_customer(): ?array
{
    arsenal_session_start();
    if (($_SESSION['arsenal_role'] ?? null) !== 'customer' || empty($_SESSION['arsenal_customer_id'])) {
        return null;
    }

    $statement = arsenal_db()->prepare(
        "SELECT maKH, hoTen, dienThoai, email, diaChi
         FROM tblKhachHang WHERE maKH = :maKH AND trangThai = 'HOAT_DONG'"
    );
    $statement->execute(['maKH' => (int)$_SESSION['arsenal_customer_id']]);
    $customer = $statement->fetch();
    if (!$customer) {
        unset($_SESSION['arsenal_customer_id'], $_SESSION['arsenal_role']);
        return null;
    }

    return $customer;
}
