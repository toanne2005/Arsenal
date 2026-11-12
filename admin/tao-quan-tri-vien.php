<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/bootstrap.php';

if ($argc < 3 || trim((string)getenv('ARSENAL_ADMIN_INITIAL_PASSWORD')) === '') {
    fwrite(STDERR, "Usage: set ARSENAL_ADMIN_INITIAL_PASSWORD temporarily, then run php admin/tao-quan-tri-vien.php <username> <full-name>\n");
    exit(2);
}

$username = trim((string)$argv[1]);
$name = trim((string)$argv[2]);
$password = (string)getenv('ARSENAL_ADMIN_INITIAL_PASSWORD');
if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username) || $name === '' || mb_strlen($name, 'UTF-8') > 120) {
    fwrite(STDERR, "Invalid administrator username or name.\n");
    exit(2);
}
if (strlen($password) < 12 || strlen($password) > 72) {
    fwrite(STDERR, "Initial administrator password must be 12-72 bytes.\n");
    exit(2);
}

$db = arsenal_db();
if ((int)$db->query('SELECT COUNT(*) FROM tblQuanTriVien')->fetchColumn() > 0) {
    fwrite(STDERR, "Administrator bootstrap is disabled because an administrator already exists.\n");
    exit(1);
}

$statement = $db->prepare(
    "INSERT INTO tblQuanTriVien (tenDangNhap, hoTen, matKhauBam, trangThai, quyen, taoLuc)
     VALUES (:username, :name, :password, 'HOAT_DONG', :permissions, UTC_TIMESTAMP(3))"
);
$statement->execute([
    'username' => $username,
    'name' => $name,
    'password' => password_hash($password, PASSWORD_DEFAULT),
    'permissions' => json_encode([
        'SAN_PHAM', 'DANH_MUC', 'KHACH_HANG', 'DON_HANG', 'KHO',
        'DAT_TRUOC', 'KHUYEN_MAI', 'DANH_GIA', 'THANH_TOAN', 'BAO_CAO', 'GOI_Y_AI',
    ], JSON_THROW_ON_ERROR),
]);

fwrite(STDOUT, "Initial administrator created.\n");
