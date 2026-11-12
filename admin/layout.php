<?php

declare(strict_types=1);

if (!isset($adminPage)) {
    http_response_code(404);
    exit;
}
$adminModuleFiles = [
    'products' => 'products.php',
    'references' => 'references.php',
    'customers' => 'customers.php',
    'orders' => 'orders.php',
    'inventory' => 'inventory.php',
    'preorders' => 'preorders.php',
    'coupons' => 'coupons.php',
    'reviews' => 'reviews.php',
    'payments' => 'payments.php',
    'analytics' => 'analytics.php',
    'ai' => 'ai.php',
];
if (!isset($adminModuleFiles[$adminPage])) {
    http_response_code(404);
    exit;
}

?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Quản trị ARSENAL</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Tektur:wght@600;700;800&family=Orbitron:wght@400;500;700;900&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="../site-config.js"></script>
    <link rel="stylesheet" href="../site.css?v=20261111-tektur-headings-1">
    <script src="panel.js?v=20261111-mecha-series-images-2" defer></script>
</head>
<body class="font-rajdhani relative" data-admin-current-page="<?= htmlspecialchars($adminPage, ENT_QUOTES, 'UTF-8') ?>">
    <main class="min-h-screen pt-12 pb-20 bg-tech-pattern">
        <section class="site-container max-w-7xl">
            <header class="admin-topbar mb-8 flex flex-wrap items-center justify-between gap-4 border-b border-mecha-panel pb-6">
                <div>
                    <a href="../index.html" class="text-mecha-gold">← ARSENAL</a>
                    <h1 class="mt-3 text-3xl font-orbitron font-black uppercase">Quản trị hệ thống</h1>
                    <p id="admin-identity" class="mt-2 text-mecha-gray">Đang kiểm tra phiên...</p>
                </div>
                <button id="admin-logout" class="button button-secondary" type="button" hidden>Đăng xuất</button>
            </header>
            <p id="admin-message" role="status" aria-live="polite" class="mb-5 text-mecha-gold"></p>
            <form id="admin-login" class="max-w-xl space-y-4 bg-mecha-dark border border-mecha-panel p-7 cut-corner" hidden>
                <h2 class="font-orbitron text-xl uppercase">Đăng nhập quản trị</h2>
                <label class="form-field">Tên đăng nhập<input name="username" autocomplete="username" maxlength="64" required></label>
                <label class="form-field">Mật khẩu<input name="password" type="password" autocomplete="current-password" maxlength="72" required></label>
                <button class="button button-primary w-full" type="submit">Đăng nhập</button>
                <p class="text-sm text-mecha-gray">Không có tài khoản mặc định. Tài khoản đầu tiên được tạo bằng công cụ CLI.</p>
            </form>
            <div id="admin-dashboard" hidden>
                <nav class="admin-nav mb-6 flex flex-wrap gap-3" aria-label="Điều hướng quản trị">
                    <a class="button button-secondary" data-admin-page="products" href="san-pham.php">Sản phẩm</a>
                    <a class="button button-secondary" data-admin-page="references" href="danh-muc.php">Danh mục</a>
                    <a class="button button-secondary" data-admin-page="customers" href="khach-hang.php">Khách hàng</a>
                    <a class="button button-secondary" data-admin-page="orders" href="don-hang.php">Đơn hàng</a>
                    <a class="button button-secondary" data-admin-page="inventory" href="kho.php">Kho</a>
                    <a class="button button-secondary" data-admin-page="preorders" href="dat-truoc.php">Đặt trước</a>
                    <a class="button button-secondary" data-admin-page="coupons" href="ma-giam-gia.php">Mã giảm giá</a>
                    <a class="button button-secondary" data-admin-page="reviews" href="danh-gia.php">Đánh giá</a>
                    <a class="button button-secondary" data-admin-page="payments" href="thanh-toan.php">Thanh toán / hoàn</a>
                    <a class="button button-secondary" data-admin-page="analytics" href="bao-cao.php">Báo cáo</a>
                    <a class="button button-secondary" data-admin-page="ai" href="tu-van-ai.php">Tư vấn AI</a>
                </nav>
                <?php
                define('ARSENAL_ADMIN_PAGE', true);
                require __DIR__ . '/modules/' . $adminModuleFiles[$adminPage];
                ?>
            </div>
        </section>
    </main>
</body>
</html>
