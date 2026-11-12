<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/payment-service.php';
arsenal_session_start();

function arsenal_transfer_account(array $config): ?array
{
    $bankCode = strtoupper(trim((string)($config['transfer_bank_code'] ?? '')));
    $accountName = trim((string)($config['transfer_account_name'] ?? ''));
    $accountNumber = preg_replace('/\s+/', '', (string)($config['transfer_account_number'] ?? ''));
    if ($bankCode === '' && $accountName === '' && $accountNumber === '') {
        return null;
    }
    if (!preg_match('/^[A-Z0-9_-]{1,24}$/', $bankCode)
        || $accountName === '' || mb_strlen($accountName, 'UTF-8') > 120
        || !is_string($accountNumber) || !preg_match('/^[A-Za-z0-9.-]{1,34}$/', $accountNumber)) {
        throw new RuntimeException('Cấu hình tài khoản nhận chuyển khoản chưa hợp lệ.');
    }

    return [
        'bankCode' => $bankCode,
        'accountName' => $accountName,
        'accountNumber' => $accountNumber,
    ];
}

function arsenal_order_response(array $order, bool $replayed = false): array
{
    $response = [
        'success' => true,
        'maDH' => $order['maDH'],
        'trangThaiXuLy' => $order['trangThaiXuLy'],
        'phuongThucThanhToan' => $order['phuongThucThanhToan'],
        'trangThaiThanhToan' => $order['trangThaiThanhToan'] ?? 'CHUA_THANH_TOAN',
        'tienHang' => (float)$order['tienHang'],
        'phiVanChuyen' => (float)$order['phiVanChuyen'],
        'tongPhaiTra' => (float)$order['tongPhaiTra'],
        'items' => $order['items'],
        'replayed' => $replayed,
    ];
    if ($order['phuongThucThanhToan'] === 'CHUYEN_KHOAN') {
        $qrParams = 'amount=' . (int)round((float)$order['tongPhaiTra'])
            . '&addInfo=ARSENAL%20' . rawurlencode($order['maDH'])
            . '&accountName=' . rawurlencode($order['chuTaiKhoanNhan']);
        $response['transferInstructions'] = [
            'bankCode' => $order['tenNganHangNhan'],
            'accountName' => $order['chuTaiKhoanNhan'],
            'accountNumber' => $order['soTaiKhoanNhan'],
            'transferContent' => $order['maDH'],
            'qrUrl' => 'https://img.vietqr.io/image/' . rawurlencode($order['tenNganHangNhan'])
                . '-' . rawurlencode($order['soTaiKhoanNhan']) . '-compact2.png?' . $qrParams,
            'deadline' => $order['hanChuyenKhoan'] ?? null,
        ];
    } elseif ($order['phuongThucThanhToan'] === 'MOMO') {
        foreach (['momoPayUrl' => 'payUrl', 'momoQrCodeUrl' => 'qrCodeUrl', 'momoDeeplink' => 'deeplink'] as $column => $key) {
            if (!empty($order[$column])) {
                $response['momo'][$key] = $order[$column];
            }
        }
    }

    return $response;
}

function arsenal_find_order(PDO $db, string $orderId): ?array
{
    $statement = $db->prepare(
        'SELECT dh.maDH, dh.maKH, dh.hoTenNhan, dh.dienThoaiNhan, dh.diaChiNhan,
                dh.tienHang, dh.phiVanChuyen, dh.tongPhaiTra, dh.trangThaiXuLy,
                dh.phuongThucThanhToan, dh.tenNganHangNhan, dh.chuTaiKhoanNhan,
                dh.soTaiKhoanNhan, dh.trangThaiThanhToan, dh.hanChuyenKhoan,
                dh.momoPayUrl, dh.momoQrCodeUrl, dh.momoDeeplink,
                mg.maCode AS couponCode
         FROM tblDonHang dh
         LEFT JOIN tblLichSuApDungMaGiamGia mg ON mg.maDH=dh.maDH
         WHERE dh.maDH = :maDH'
    );
    $statement->execute(['maDH' => $orderId]);
    $order = $statement->fetch();
    if (!$order) {
        return null;
    }

    $details = $db->prepare(
        'SELECT maSP, tenSPLucDat, soLuong, donGiaLucDat
         FROM tblChiTietDonHang WHERE maDH = :maDH ORDER BY maCTDH'
    );
    $details->execute(['maDH' => $orderId]);
    $order['items'] = array_map(static function (array $item): array {
        return [
            'id' => $item['maSP'],
            'name' => $item['tenSPLucDat'],
            'quantity' => (int)$item['soLuong'],
            'price' => (float)$item['donGiaLucDat'],
        ];
    }, $details->fetchAll());

    return $order;
}

function arsenal_same_order_request(array $stored, array $request, string $phone, array $items): bool
{
    if (
        $stored['hoTenNhan'] !== $request['customerName']
        || $stored['dienThoaiNhan'] !== $phone
        || $stored['diaChiNhan'] !== $request['address']
        || $stored['phuongThucThanhToan'] !== $request['paymentMethod']
        || (isset($request['customerId']) && (int)($stored['maKH'] ?? 0) !== (int)$request['customerId'])
        || strtoupper((string)($stored['couponCode'] ?? '')) !== (string)($request['couponCode'] ?? '')
        || count($stored['items']) !== count($items)
    ) {
        return false;
    }

    $storedItems = [];
    foreach ($stored['items'] as $item) {
        $storedItems[$item['id']] = (int)$item['quantity'];
    }
    foreach ($items as $item) {
        if (($storedItems[$item['id']] ?? null) !== $item['quantity']) {
            return false;
        }
    }

    return true;
}

function arsenal_existing_order_response(PDO $db, string $orderId, array $payload, string $phone, array $items): ?array
{
    $stored = arsenal_find_order($db, $orderId);
    if (!$stored) {
        return null;
    }
    if (!arsenal_same_order_request($stored, $payload, $phone, $items)) {
        return ['idempotencyConflict' => true];
    }

    return arsenal_order_response($stored, true);
}

function arsenal_validate_coupon(PDO $db, string $code, string $phone, int $subtotalCents, bool $lock = false): array
{
    if ($code === '') {
        return ['code' => null, 'discountCents' => 0];
    }
    if (!preg_match('/^[A-Z0-9_-]{1,32}$/', $code)) {
        throw new InvalidArgumentException('Mã giảm giá không hợp lệ.');
    }

    $query = $db->prepare(
        'SELECT maCode, loaiGiam, giaTriGiam, tienHangToiThieu, mucGiamToiDa,
                luotToiDa, luotDangGiu, luotDaSuDung, gioiHanTheoDienThoai,
                batDauLuc, ketThucLuc, hoatDong
         FROM tblMaGiamGia WHERE maCode=:code' . ($lock ? ' FOR UPDATE' : '')
    );
    $query->execute(['code' => $code]);
    $coupon = $query->fetch();
    if (!$coupon
        || !(int)$coupon['hoatDong']
        || (int)$coupon['luotDaSuDung'] + (int)$coupon['luotDangGiu'] >= (int)$coupon['luotToiDa']
        || $subtotalCents < arsenal_money_cents($coupon['tienHangToiThieu'])
        || strtotime($coupon['batDauLuc'] . ' UTC') > time()
        || strtotime($coupon['ketThucLuc'] . ' UTC') < time()) {
        throw new InvalidArgumentException('Mã giảm giá không tồn tại, hết hạn hoặc chưa đủ điều kiện.');
    }

    $uses = $db->prepare(
        "SELECT COUNT(*) FROM tblLichSuApDungMaGiamGia
         WHERE maCode=:code AND dienThoaiChuanHoa=:phone
           AND trangThaiLuot IN ('DANG_GIU','DA_SU_DUNG')"
    );
    $uses->execute(['code' => $code, 'phone' => $phone]);
    if ((int)$uses->fetchColumn() >= (int)$coupon['gioiHanTheoDienThoai']) {
        throw new InvalidArgumentException('Số điện thoại đã đạt giới hạn sử dụng mã giảm giá.');
    }

    $value = arsenal_money_cents($coupon['giaTriGiam']);
    $discount = $coupon['loaiGiam'] === 'PHAN_TRAM'
        ? (int)round($subtotalCents * $value / 10000)
        : $value;
    if ($coupon['mucGiamToiDa'] !== null) {
        $discount = min($discount, arsenal_money_cents($coupon['mucGiamToiDa']));
    }

    return ['code' => $coupon['maCode'], 'discountCents' => min($subtotalCents, $discount)];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $config = arsenal_config();
        $transferAccount = arsenal_transfer_account($config);
        arsenal_json_response([
            'shippingFee' => (float)($config['shipping_fee'] ?? 0),
            'paymentMethods' => array_values(array_filter([
                'COD',
                $transferAccount === null ? null : 'CHUYEN_KHOAN',
                arsenal_payment_momo_ready($config) ? 'MOMO' : null,
            ])),
            'transferAccount' => $transferAccount,
            'csrfToken' => arsenal_csrf_token(),
        ]);
    }

    arsenal_request_method('POST');
    $payload = arsenal_read_json();
    $customer = arsenal_current_customer();
    if ($customer !== null) {
        arsenal_require_csrf($payload);
    }
    $name = trim((string)($payload['customerName'] ?? ''));
    $phone = arsenal_normalize_phone(trim((string)($payload['phone'] ?? '')));
    $address = trim((string)($payload['address'] ?? ''));
    $requestKey = strtoupper(trim((string)($payload['requestId'] ?? '')));
    $couponCode = strtoupper(trim((string)($payload['couponCode'] ?? '')));
    $paymentMethod = strtoupper(trim((string)($payload['paymentMethod'] ?? 'COD')));
    if (!in_array($paymentMethod, ['COD', 'CHUYEN_KHOAN', 'MOMO'], true)) {
        throw new InvalidArgumentException('Phương thức thanh toán không hợp lệ.');
    }
    if ($paymentMethod === 'MOMO' && !arsenal_payment_momo_ready(arsenal_config())) {
        throw new InvalidArgumentException('Cửa hàng chưa cấu hình MoMo Sandbox.');
    }
    $transferAccount = $paymentMethod === 'CHUYEN_KHOAN'
        ? arsenal_transfer_account(arsenal_config())
        : null;
    if ($paymentMethod === 'CHUYEN_KHOAN' && $transferAccount === null) {
        throw new InvalidArgumentException('Cửa hàng chưa cấu hình tài khoản nhận chuyển khoản.');
    }
    $rawItems = $payload['items'] ?? null;

    if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
        throw new InvalidArgumentException('Họ tên người nhận là bắt buộc và tối đa 120 ký tự.');
    }
    if ($address === '' || mb_strlen($address, 'UTF-8') > 255) {
        throw new InvalidArgumentException('Địa chỉ nhận hàng là bắt buộc và tối đa 255 ký tự.');
    }
    if (!preg_match('/^[A-F0-9]{24}$/', $requestKey)) {
        throw new InvalidArgumentException('Mã yêu cầu đặt hàng không hợp lệ.');
    }
    if (!is_array($rawItems) || count($rawItems) < 1 || count($rawItems) > 50) {
        throw new InvalidArgumentException('Giỏ hàng phải có từ 1 đến 50 sản phẩm.');
    }

    $items = [];
    foreach ($rawItems as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('Thông tin sản phẩm trong giỏ không hợp lệ.');
        }
        $productId = trim((string)($item['id'] ?? ''));
        $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT);
        $price = arsenal_money_cents($item['price'] ?? null);
        if ($productId === '' || strlen($productId) > 32 || $quantity === false || $quantity < 1 || $quantity > 99) {
            throw new InvalidArgumentException('Mã sản phẩm hoặc số lượng không hợp lệ.');
        }
        if (isset($items[$productId])) {
            throw new InvalidArgumentException('Mỗi sản phẩm chỉ được xuất hiện một lần trong giỏ hàng.');
        }
        $items[$productId] = [
            'id' => $productId,
            'quantity' => $quantity,
            'clientPrice' => $price,
        ];
    }

    $orderId = 'ARS-' . $requestKey;
    $db = arsenal_db();
    $db->beginTransaction();

    $requestIdentity = [
        'customerName' => $name,
        'address' => $address,
        'couponCode' => $couponCode,
        'paymentMethod' => $paymentMethod,
    ];
    if ($customer !== null) {
        $requestIdentity['customerId'] = (int)$customer['maKH'];
    }
    $existing = arsenal_existing_order_response($db, $orderId, $requestIdentity, $phone, array_values($items));
    if ($existing !== null) {
        $db->commit();
        if (!empty($existing['idempotencyConflict'])) {
            arsenal_json_response([
                'code' => 'IDEMPOTENCY_CONFLICT',
                'message' => 'Mã yêu cầu này đã được dùng cho thông tin đặt hàng khác.',
            ], 409);
        }
        if ($paymentMethod === 'MOMO') {
            try {
                $existing['momo'] = arsenal_payment_create_momo_checkout($db, $orderId);
            } catch (Throwable $paymentError) {
                error_log('ARSENAL MoMo checkout replay: ' . $paymentError->getMessage());
                arsenal_json_response([
                    'code' => 'MOMO_CHECKOUT_PENDING',
                    'message' => $paymentError->getMessage(),
                    'maDH' => $orderId,
                ], 502);
            }
        }
        arsenal_json_response($existing);
    }

    $placeholders = implode(',', array_fill(0, count($items), '?'));
    $productQuery = $db->prepare(
        "SELECT maSP, tenSP, giaBan, tonKhaDung, trangThai
         FROM tblSanPham WHERE maSP IN ($placeholders) FOR UPDATE"
    );
    $productQuery->execute(array_keys($items));
    $products = [];
    foreach ($productQuery->fetchAll() as $product) {
        $products[$product['maSP']] = $product;
    }

    $currentItems = [];
    $subtotalCents = 0;
    $priceChanged = false;
    foreach ($items as $productId => $item) {
        $product = $products[$productId] ?? null;
        if (!$product || $product['trangThai'] !== 'DANG_BAN' || $product['giaBan'] === null) {
            $db->rollBack();
            arsenal_json_response([
                'code' => 'PRODUCT_UNAVAILABLE',
                'message' => 'Một sản phẩm trong giỏ không còn được bán. Hãy tải lại giỏ hàng.',
            ], 409);
        }

        $stock = (int)$product['tonKhaDung'];
        if ($item['quantity'] > $stock) {
            $db->rollBack();
            arsenal_json_response([
                'code' => 'INSUFFICIENT_STOCK',
                'message' => sprintf('Sản phẩm %s chỉ còn %d sản phẩm khả dụng.', $product['tenSP'], $stock),
                'maSP' => $productId,
                'tonKhaDung' => $stock,
            ], 409);
        }

        $priceCents = arsenal_money_cents($product['giaBan']);
        $priceChanged = $priceChanged || $priceCents !== $item['clientPrice'];
        $subtotalCents += $priceCents * $item['quantity'];
        $currentItems[] = [
            'id' => $productId,
            'name' => $product['tenSP'],
            'quantity' => $item['quantity'],
            'price' => $priceCents / 100,
        ];
    }

    $config = arsenal_config();
    $shippingCents = arsenal_money_cents($config['shipping_fee'] ?? null);
    $coupon = arsenal_validate_coupon($db, $couponCode, $phone, $subtotalCents);
    $discountCents = $coupon['discountCents'];
    $totalCents = $subtotalCents - $discountCents + $shippingCents;
    $confirmedCents = array_key_exists('confirmedTotal', $payload) && $payload['confirmedTotal'] !== null
        ? arsenal_money_cents($payload['confirmedTotal'])
        : null;

    if ($confirmedCents !== $totalCents) {
        $db->rollBack();
        arsenal_json_response([
            'code' => $priceChanged ? 'PRICE_CHANGED' : 'CONFIRM_TOTAL',
            'message' => $priceChanged
                ? 'Giá hoặc tổng tiền đã thay đổi. Hãy xác nhận tổng tiền mới trước khi đặt hàng.'
                : 'Hãy xác nhận tổng tiền và phí vận chuyển trước khi đặt hàng.',
            'tienHang' => $subtotalCents / 100,
            'tienGiam' => $discountCents / 100,
            'phiVanChuyen' => $shippingCents / 100,
            'tongPhaiTra' => $totalCents / 100,
            'items' => $currentItems,
        ], 409);
    }

    $insertOrder = $db->prepare(
        "INSERT INTO tblDonHang
               (maDH, datLuc, hoTenNhan, dienThoaiNhan, diaChiNhan, emailNhan, phuongThucThanhToan,
                tienHang, tienGiam, phiVanChuyen, trangThaiXuLy, tenNganHangNhan,
               chuTaiKhoanNhan, soTaiKhoanNhan, maKH, trangThaiThanhToan, huongDanChuyenKhoan,
               tonKhoDaGiu)
         VALUES
               (:maDH, UTC_TIMESTAMP(3), :hoTenNhan, :dienThoaiNhan, :diaChiNhan, :emailNhan, :paymentMethod,
                :tienHang, :tienGiam, :phiVanChuyen, 'CHO_XAC_NHAN', :bank,
                :accountName, :accountNumber, :maKH, :paymentStatus, :instructions, 0)"
    );
    $insertOrder->execute([
        'maDH' => $orderId,
        'hoTenNhan' => $name,
        'dienThoaiNhan' => $phone,
        'diaChiNhan' => $address,
        'emailNhan' => $customer['email'] ?? null,
        'paymentMethod' => $paymentMethod,
        'tienHang' => arsenal_format_money($subtotalCents),
        'tienGiam' => arsenal_format_money($discountCents),
        'phiVanChuyen' => arsenal_format_money($shippingCents),
        'bank' => $transferAccount['bankCode'] ?? null,
        'accountName' => $transferAccount['accountName'] ?? null,
        'accountNumber' => $transferAccount['accountNumber'] ?? null,
        'maKH' => $customer['maKH'] ?? null,
        'paymentStatus' => $paymentMethod === 'CHUYEN_KHOAN' ? 'CHO_CHUYEN_KHOAN' : 'CHUA_THANH_TOAN',
        'instructions' => $transferAccount === null ? null : implode(' · ', [
            $transferAccount['bankCode'], $transferAccount['accountName'], $transferAccount['accountNumber'],
        ]),
    ]);

    $insertDetail = $db->prepare(
        'INSERT INTO tblChiTietDonHang (maDH, maSP, maSPLucDat, tenSPLucDat, soLuong, donGiaLucDat)
         VALUES (:maDH, :maSP, :maSPLucDat, :tenSPLucDat, :soLuong, :donGiaLucDat)'
    );
    foreach ($currentItems as $item) {
        $insertDetail->execute([
            'maDH' => $orderId,
            'maSP' => $item['id'],
            'maSPLucDat' => $item['id'],
            'tenSPLucDat' => $item['name'],
            'soLuong' => $item['quantity'],
            'donGiaLucDat' => number_format($item['price'], 2, '.', ''),
        ]);
    }

    if ($paymentMethod !== 'COD') {
        foreach ($currentItems as $item) {
            $product = $products[$item['id']];
            $before = (int)$product['tonKhaDung'];
            $after = $before - (int)$item['quantity'];
            $db->prepare('UPDATE tblSanPham SET tonKhaDung=:after WHERE maSP=:product AND tonKhaDung>=:quantity')
                ->execute(['after' => $after, 'product' => $item['id'], 'quantity' => $item['quantity']]);
            if ((int)$db->query('SELECT ROW_COUNT()')->fetchColumn() !== 1) {
                throw new RuntimeException('Không thể giữ tồn kho cho đơn thanh toán trước.');
            }
            $db->prepare(
                'INSERT INTO tblLichSuTonKho
                 (loaiBienDong, soLuongThayDoi, soLuongTruoc, soLuongSau, thucHienLuc,
                  lyDo, maSP, maQTVThucHien, maDH)
                 VALUES (\'GIU_DON\', :delta, :before, :after, UTC_TIMESTAMP(3),
                  :reason, :product, NULL, :orderId)'
            )->execute([
                'delta' => -(int)$item['quantity'],
                'before' => $before,
                'after' => $after,
                'reason' => 'Giữ tồn khi tạo đơn thanh toán trước ' . $orderId,
                'product' => $item['id'],
                'orderId' => $orderId,
            ]);
        }
        $db->prepare('UPDATE tblDonHang SET tonKhoDaGiu=1 WHERE maDH=:id')->execute(['id' => $orderId]);
    }

    if ($coupon['code'] !== null) {
        $lockedCoupon = arsenal_validate_coupon($db, $coupon['code'], $phone, $subtotalCents, true);
        if ($lockedCoupon['discountCents'] !== $discountCents) {
            $db->rollBack();
            arsenal_json_response(['message' => 'Điều kiện mã giảm giá vừa thay đổi. Hãy thử lại.'], 409);
        }
        $db->prepare(
            'UPDATE tblMaGiamGia SET luotDangGiu=luotDangGiu+1 WHERE maCode=:code'
        )->execute(['code' => $coupon['code']]);
        $db->prepare(
            "INSERT INTO tblLichSuApDungMaGiamGia
             (dienThoaiChuanHoa, tienGiam, giuLuc, trangThaiLuot, maCode, maDH)
             VALUES (:phone, :discount, UTC_TIMESTAMP(3), 'DANG_GIU', :code, :orderId)"
        )->execute([
            'phone' => $phone,
            'discount' => arsenal_format_money($discountCents),
            'code' => $coupon['code'],
            'orderId' => $orderId,
        ]);
    }

    arsenal_payment_log($db, 'TAO_DON', 'TU_DONG', $orderId,
        'Tạo đơn hàng và ghi nhận phương thức thanh toán.', null,
        $paymentMethod === 'CHUYEN_KHOAN' ? 'CHO_CHUYEN_KHOAN' : 'CHUA_THANH_TOAN',
        null, null, null, 'checkout');
    $db->commit();
    $response = [
        'success' => true,
        'maDH' => $orderId,
        'trangThaiXuLy' => 'CHO_XAC_NHAN',
        'phuongThucThanhToan' => $paymentMethod,
        'tienHang' => $subtotalCents / 100,
        'tienGiam' => $discountCents / 100,
        'phiVanChuyen' => $shippingCents / 100,
        'tongPhaiTra' => $totalCents / 100,
        'items' => $currentItems,
        'replayed' => false,
    ];
    if ($paymentMethod === 'CHUYEN_KHOAN') {
        $deadlineQuery = $db->prepare('SELECT hanChuyenKhoan FROM tblDonHang WHERE maDH=:id');
        $deadlineQuery->execute(['id' => $orderId]);
        $deadline = $deadlineQuery->fetchColumn();
        $response['transferInstructions'] = [
            'bankCode' => $transferAccount['bankCode'],
            'accountName' => $transferAccount['accountName'],
            'accountNumber' => $transferAccount['accountNumber'],
            'transferContent' => $orderId,
            'qrUrl' => 'https://img.vietqr.io/image/' . rawurlencode($transferAccount['bankCode'])
                . '-' . rawurlencode($transferAccount['accountNumber']) . '-compact2.png?'
                . 'amount=' . (int)round($totalCents / 100)
                . '&addInfo=ARSENAL%20' . rawurlencode($orderId)
                . '&accountName=' . rawurlencode($transferAccount['accountName']),
            'deadline' => $deadline,
        ];
    }
    if ($paymentMethod === 'MOMO') {
        try {
            $response['momo'] = arsenal_payment_create_momo_checkout($db, $orderId);
        } catch (Throwable $paymentError) {
            error_log('ARSENAL MoMo checkout: ' . $paymentError->getMessage());
            arsenal_json_response([
                'code' => 'MOMO_CHECKOUT_PENDING',
                'message' => $paymentError->getMessage(),
                'maDH' => $orderId,
            ], 502);
        }
    }
    arsenal_json_response($response, 201);
} catch (InvalidArgumentException $error) {
    arsenal_json_response(['message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062 && isset($orderId, $items, $name, $address, $phone)) {
        try {
            $replayIdentity = [
                'customerName' => $name,
                'address' => $address,
            ];
            if ($customer !== null) {
                $replayIdentity['customerId'] = (int)$customer['maKH'];
            }
            $replayed = arsenal_existing_order_response(arsenal_db(), $orderId, $replayIdentity, $phone, array_values($items));
            if ($replayed !== null) {
                if (!empty($replayed['idempotencyConflict'])) {
                    arsenal_json_response([
                        'code' => 'IDEMPOTENCY_CONFLICT',
                        'message' => 'Mã yêu cầu này đã được dùng cho thông tin đặt hàng khác.',
                    ], 409);
                }
                arsenal_json_response($replayed);
            }
        } catch (Throwable $replayError) {
            error_log('ARSENAL order replay: ' . $replayError->getMessage());
        }
    }

    error_log('ARSENAL order create: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể lưu đơn hàng. Vui lòng thử lại bằng cùng một yêu cầu.'], 500);
}
