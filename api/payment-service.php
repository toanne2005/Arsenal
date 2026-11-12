<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function arsenal_payment_log(
    PDO $db,
    string $type,
    string $source,
    string $orderId,
    string $reason,
    ?string $before,
    ?string $after,
    ?int $paymentId = null,
    ?int $adminId = null,
    ?string $evidence = null,
    ?string $actor = null,
    ?string $adjustment = null,
    ?int $voidedId = null,
    ?int $replacementId = null
): void {
    $db->prepare(
        'INSERT INTO tblNhatKyThanhToan
         (loaiSuKien, nguon, ghiLuc, trangThaiTruoc, trangThaiSau, lyDo, canCu,
          maDH, maQTV, maGDTT, nguoiThucHien, canCuDieuChinh, maBanGhiVoHieu, maBanGhiThayThe)
         VALUES (:type, :source, UTC_TIMESTAMP(3), :before, :after, :reason, :evidence,
                 :orderId, :adminId, :paymentId, :actor, :adjustment, :voidedId, :replacementId)'
    )->execute([
        'type' => $type,
        'source' => $source,
        'before' => $before,
        'after' => $after,
        'reason' => $reason,
        'evidence' => $evidence,
        'orderId' => $orderId,
        'adminId' => $adminId,
        'paymentId' => $paymentId,
        'actor' => $actor,
        'adjustment' => $adjustment,
        'voidedId' => $voidedId,
        'replacementId' => $replacementId,
    ]);
}

function arsenal_payment_sync_order(PDO $db, string $orderId): array
{
    $statement = $db->prepare(
        'SELECT maDH, phuongThucThanhToan, trangThaiXuLy, trangThaiThanhToan,
                tongTienPhaiTra, soTienDaThu, soTienDaPhanBo
         FROM tblDonHang WHERE maDH=:id FOR UPDATE'
    );
    $statement->execute(['id' => $orderId]);
    $order = $statement->fetch();
    if (!$order) {
        throw new InvalidArgumentException('Không tìm thấy đơn hàng.');
    }

    $received = $db->prepare(
        'SELECT COALESCE(SUM(soTienThucNhan), 0)
         FROM tblGiaoDichThanhToan WHERE maDH=:id AND conHieuLuc=1'
    );
    $received->execute(['id' => $orderId]);
    $receivedCents = arsenal_money_cents($received->fetchColumn());
    $eligible = $db->prepare(
        'SELECT COALESCE(SUM(soTienThucNhan), 0)
         FROM tblGiaoDichThanhToan
         WHERE maDH=:id AND conHieuLuc=1 AND ketQuaDoiChieu=\'DU_DIEU_KIEN\''
    );
    $eligible->execute(['id' => $orderId]);
    $eligibleCents = arsenal_money_cents($eligible->fetchColumn());
    $refunds = $db->prepare(
        'SELECT yc.maYCHT, yc.soTienCanHoan, yc.trangThai,
                COALESCE(SUM(ht.tongDaHoan), 0) AS refunded
         FROM tblYeuCauHoanTien yc
         LEFT JOIN tblLanHoanTien ht ON ht.maYCHT=yc.maYCHT
         WHERE yc.maDH=:id
         GROUP BY yc.maYCHT, yc.soTienCanHoan, yc.trangThai'
    );
    $refunds->execute(['id' => $orderId]);
    $refundedCents = 0;
    $pendingRefundCents = 0;
    foreach ($refunds->fetchAll() as $refund) {
        $refunded = arsenal_money_cents($refund['refunded']);
        $refundedCents += $refunded;
        if ($refund['trangThai'] !== 'TU_CHOI') {
            $pendingRefundCents += max(0, arsenal_money_cents($refund['soTienCanHoan']) - $refunded);
        }
    }
    $dueCents = arsenal_money_cents($order['tongTienPhaiTra']);
    $allocatedCents = min($eligibleCents, $dueCents);
    if ($receivedCents > 0 && $refundedCents >= $receivedCents) {
        $newStatus = 'DA_HOAN_TIEN';
    } elseif ($pendingRefundCents > 0 || $refundedCents > 0) {
        $newStatus = 'DANG_HOAN_TIEN';
    } else {
        $newStatus = $eligibleCents === 0
            ? ($order['phuongThucThanhToan'] === 'CHUYEN_KHOAN' ? 'CHO_CHUYEN_KHOAN' : 'CHUA_THANH_TOAN')
            : ($eligibleCents < $dueCents ? 'THANH_TOAN_MOT_PHAN'
                : ($eligibleCents === $dueCents ? 'DA_THANH_TOAN_DU' : 'THANH_TOAN_DU_THUA'));
    }

    $oldStatus = $order['trangThaiThanhToan'];
    $db->prepare(
        'UPDATE tblDonHang SET soTienDaThu=:received, soTienDaPhanBo=:allocated, soTienDaHoan=:refunded,
            trangThaiThanhToan=:status,
            thoiDiemNhanDuTien=CASE WHEN :fullyPaid=1 THEN COALESCE(thoiDiemNhanDuTien, UTC_TIMESTAMP(3)) ELSE NULL END,
            ngayGhiNhanDoanhThu=CASE WHEN :revenue=1 THEN COALESCE(ngayGhiNhanDoanhThu, UTC_DATE()) ELSE NULL END
         WHERE maDH=:id'
    )->execute([
        'received' => arsenal_format_money($receivedCents),
        'allocated' => arsenal_format_money($allocatedCents),
        'refunded' => arsenal_format_money($refundedCents),
        'status' => $newStatus,
        'fullyPaid' => $eligibleCents >= $dueCents ? 1 : 0,
        'revenue' => $eligibleCents >= $dueCents && $order['trangThaiXuLy'] === 'DA_GIAO' ? 1 : 0,
        'id' => $orderId,
    ]);
    if ($oldStatus !== $newStatus) {
        arsenal_payment_log($db, 'DOI_TRANG_THAI', 'TU_DONG', $orderId,
            'Tự động tính lại trạng thái từ tổng giao dịch đang hiệu lực.',
            $oldStatus, $newStatus, null, null, null, 'payment-service');
    }

    return [
        'status' => $newStatus,
        'receivedCents' => $receivedCents,
        'eligibleCents' => $eligibleCents,
        'allocatedCents' => $allocatedCents,
        'dueCents' => $dueCents,
    ];
}

function arsenal_payment_momo_ready(array $config): bool
{
    foreach (['momo_partner_code', 'momo_access_key', 'momo_secret_key'] as $key) {
        if (trim((string)($config[$key] ?? '')) === '') {
            return false;
        }
    }

    $base = parse_url((string)($config['public_base_url'] ?? ''));

    return function_exists('curl_init')
        && is_array($base)
        && strtolower((string)($base['scheme'] ?? '')) === 'https'
        && !empty($base['host'])
        && !isset($base['query'])
        && !isset($base['fragment']);
}

function arsenal_payment_create_excess_refund(PDO $db, string $orderId, int $paymentId, int $receivedCents, int $allocatedCents, string $evidence): void
{
    $requests = $db->prepare(
        "SELECT COALESCE(SUM(soTienCanHoan), 0)
         FROM tblYeuCauHoanTien
         WHERE maDH=:id AND nguon='KHOAN_THU_CAN_TRA' AND trangThai<>'TU_CHOI'"
    );
    $requests->execute(['id' => $orderId]);
    $alreadyRequestedCents = arsenal_money_cents($requests->fetchColumn());
    $unrequested = $receivedCents - $allocatedCents - $alreadyRequestedCents;
    if ($unrequested <= 0) {
        return;
    }

    $refund = $db->prepare(
        'INSERT INTO tblYeuCauHoanTien
         (nguon, lyDo, taoLuc, soTienDeNghi, tienChuaPhanBoCanHoan, trangThai, maDH, maGDTTNguon)
         VALUES (\'KHOAN_THU_CAN_TRA\', :reason, UTC_TIMESTAMP(3), :requested, :unallocated, \'CHO_DUYET\', :orderId, :paymentId)'
    );
    $refund->execute([
        'reason' => 'Hoàn phần thu vượt số tiền phải trả.',
        'requested' => arsenal_format_money($unrequested),
        'unallocated' => arsenal_format_money($unrequested),
        'orderId' => $orderId,
        'paymentId' => $paymentId,
    ]);
    $currentStatus = $db->prepare('SELECT trangThaiThanhToan FROM tblDonHang WHERE maDH=:id FOR UPDATE');
    $currentStatus->execute(['id' => $orderId]);
    $before = (string)$currentStatus->fetchColumn();
    if ($before !== 'DANG_HOAN_TIEN') {
        $db->prepare("UPDATE tblDonHang SET trangThaiThanhToan='DANG_HOAN_TIEN' WHERE maDH=:id")
            ->execute(['id' => $orderId]);
        arsenal_payment_log($db, 'DOI_TRANG_THAI', 'TU_DONG', $orderId,
            'Có yêu cầu hoàn tiền tự động đang chờ xử lý.', $before, 'DANG_HOAN_TIEN',
            $paymentId, null, $evidence, 'payment-service');
    }
    arsenal_payment_log($db, 'TAO_YEU_CAU', 'TU_DONG', $orderId,
        'Tự động tạo yêu cầu hoàn phần tiền chưa phân bổ.', null, 'CHO_DUYET',
        $paymentId, null, $evidence, 'payment-service');
}

function arsenal_payment_momo_signature(array $fields, string $secret): string
{
    $message = [];
    foreach ($fields as $key => $value) {
        $message[] = $key . '=' . (string)$value;
    }

    return hash_hmac('sha256', implode('&', $message), $secret);
}

function arsenal_payment_create_momo_checkout(PDO $db, string $orderId): array
{
    $config = arsenal_config();
    if (!arsenal_payment_momo_ready($config)) {
        throw new RuntimeException('MoMo chưa được cấu hình đầy đủ trong api/config.local.php.');
    }
    $db->beginTransaction();
    try {
        $query = $db->prepare('SELECT * FROM tblDonHang WHERE maDH=:id FOR UPDATE');
        $query->execute(['id' => $orderId]);
        $order = $query->fetch();
        if (!$order || $order['phuongThucThanhToan'] !== 'MOMO') {
            throw new InvalidArgumentException('Đơn MoMo không hợp lệ.');
        }
        if (!empty($order['momoPayUrl']) && !empty($order['momoQrCodeUrl']) && !empty($order['momoDeeplink'])) {
            $db->commit();
            return [
                'payUrl' => $order['momoPayUrl'],
                'qrCodeUrl' => $order['momoQrCodeUrl'],
                'deeplink' => $order['momoDeeplink'],
            ];
        }
        $requestId = trim((string)($order['maMomoRequest'] ?? ''));
        if ($requestId === '') {
            $requestId = bin2hex(random_bytes(16));
            $db->prepare('UPDATE tblDonHang SET maMomoRequest=:requestId WHERE maDH=:id')
                ->execute(['requestId' => $requestId, 'id' => $orderId]);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    $baseUrl = rtrim((string)($config['public_base_url'] ?? ''), '/');
    $baseParts = parse_url($baseUrl);
    if (!is_array($baseParts) || strtolower((string)($baseParts['scheme'] ?? '')) !== 'https'
        || empty($baseParts['host']) || isset($baseParts['query']) || isset($baseParts['fragment'])) {
        throw new RuntimeException('Hãy cấu hình public_base_url là HTTPS công khai để MoMo gọi IPN.');
    }
    $partner = (string)$config['momo_partner_code'];
    $accessKey = (string)$config['momo_access_key'];
    $amount = (string)(int)round((float)$order['tongTienPhaiTra']);
    $extraData = base64_encode($orderId);
    $ipnUrl = $baseUrl . '/api/payments/momo/ipn';
    $redirectUrl = $baseUrl . '/gio-hang.html?payment=momo&order=' . rawurlencode($orderId);
    $fields = [
        'accessKey' => $accessKey,
        'amount' => $amount,
        'extraData' => $extraData,
        'ipnUrl' => $ipnUrl,
        'orderId' => $orderId,
        'orderInfo' => 'Thanh toan don hang ARSENAL ' . $orderId,
        'partnerCode' => $partner,
        'redirectUrl' => $redirectUrl,
        'requestId' => $requestId,
        'requestType' => 'captureWallet',
    ];
    $payload = $fields;
    $payload['lang'] = 'vi';
    $payload['signature'] = arsenal_payment_momo_signature($fields, (string)$config['momo_secret_key']);
    $endpoint = (string)($config['momo_create_url'] ?? 'https://test-payment.momo.vn/v2/gateway/api/create');
    $curl = curl_init($endpoint);
    if ($curl === false) {
        throw new RuntimeException('Không khởi tạo được kết nối MoMo.');
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $responseBody = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if (!is_string($responseBody) || $status < 200 || $status >= 300) {
        error_log('ARSENAL MoMo create failed: HTTP ' . $status . ' ' . $curlError);
        throw new RuntimeException('MoMo chưa tạo được yêu cầu thanh toán. Hãy thử lại bằng cùng mã đơn.');
    }
    try {
        $result = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('MoMo trả về phản hồi không hợp lệ.');
    }
    if (!is_array($result) || (int)($result['resultCode'] ?? -1) !== 0
        || empty($result['payUrl']) || empty($result['qrCodeUrl']) || empty($result['deeplink'])) {
        throw new RuntimeException('MoMo từ chối yêu cầu thanh toán: ' . (string)($result['message'] ?? 'Lỗi chưa xác định.'));
    }
    $db->prepare(
        'UPDATE tblDonHang SET maMomoRequest=:requestId, momoPayUrl=:payUrl,
            momoQrCodeUrl=:qr, momoDeeplink=:deeplink WHERE maDH=:id'
    )->execute([
        'requestId' => $requestId,
        'payUrl' => $result['payUrl'],
        'qr' => $result['qrCodeUrl'],
        'deeplink' => $result['deeplink'],
        'id' => $orderId,
    ]);

    return ['payUrl' => $result['payUrl'], 'qrCodeUrl' => $result['qrCodeUrl'], 'deeplink' => $result['deeplink']];
}

function arsenal_payment_record_reconciled(
    PDO $db,
    int $adminId,
    string $orderId,
    array $payload,
    string $method
): array {
    $amount = arsenal_money_cents($payload['soTienThucNhan'] ?? null);
    if ($amount <= 0 || $amount % 100 !== 0) {
        throw new InvalidArgumentException('Số tiền thực nhận phải là số VND nguyên dương.');
    }
    $evidence = trim((string)($payload['canCuDoiChieu'] ?? ''));
    if ($evidence === '' || mb_strlen($evidence, 'UTF-8') > 500) {
        throw new InvalidArgumentException('Căn cứ đối chiếu là bắt buộc (tối đa 500 ký tự).');
    }
    $receivedAt = trim((string)($payload['thoiDiemThucNhan'] ?? ''));
    if ($receivedAt === '') {
        $receivedAt = gmdate('Y-m-d H:i:s');
    } else {
        $timestamp = strtotime($receivedAt);
        if ($timestamp === false) {
            throw new InvalidArgumentException('Thời điểm thực nhận không hợp lệ.');
        }
        $receivedAt = gmdate('Y-m-d H:i:s', $timestamp);
    }

    $db->beginTransaction();
    try {
        $lock = $db->prepare('SELECT * FROM tblDonHang WHERE maDH=:id FOR UPDATE');
        $lock->execute(['id' => $orderId]);
        $order = $lock->fetch();
        if (!$order || $order['phuongThucThanhToan'] !== $method) {
            throw new InvalidArgumentException('Không tìm thấy đơn hoặc phương thức thanh toán không khớp.');
        }
        if ($method === 'COD' && $order['trangThaiXuLy'] !== 'DA_GIAO') {
            throw new InvalidArgumentException('Chỉ đối soát COD cho đơn đã giao.');
        }
        if (strtotime($receivedAt . ' UTC') < strtotime($order['datLuc'] . ' UTC')) {
            throw new InvalidArgumentException('Thời điểm thực nhận không thể trước lúc tạo đơn.');
        }
        if ($method === 'CHUYEN_KHOAN') {
            $bank = trim((string)($payload['taiKhoanNhan'] ?? ''));
            $bankCode = trim((string)($payload['maNganHangNhan'] ?? $order['tenNganHangNhan'] ?? ''));
            $reference = trim((string)($payload['maGDNganHang'] ?? ''));
            if ($bank === '' || $bankCode === '' || $reference === ''
                || mb_strlen($bank, 'UTF-8') > 34 || mb_strlen($bankCode, 'UTF-8') > 24
                || mb_strlen($reference, 'UTF-8') > 80) {
                throw new InvalidArgumentException('Tài khoản nhận và mã giao dịch ngân hàng là bắt buộc.');
            }
            if ($order['tenNganHangNhan'] !== null && $order['tenNganHangNhan'] !== $bankCode) {
                throw new InvalidArgumentException('Mã ngân hàng nhận không khớp cấu hình trên đơn hàng.');
            }
            if ($order['soTaiKhoanNhan'] !== null && $order['soTaiKhoanNhan'] !== $bank) {
                throw new InvalidArgumentException('Tài khoản nhận không khớp tài khoản đã lưu trên đơn.');
            }
            $carrier = $codDocument = $allocation = null;
            $bankAccount = $bank;
            $idempotency = hash('sha256', 'BANK|' . mb_strtolower($bankCode) . '|' . mb_strtolower($bank) . '|' . mb_strtolower($reference));
            $transactionRef = $reference;
        } else {
            $carrier = trim((string)($payload['donViVanChuyen'] ?? ''));
            $allocation = trim((string)($payload['dongPhanBoCOD'] ?? ''));
            $bankCode = $bankAccount = null;
            $codDocument = trim((string)($payload['maDoiSoat'] ?? ''));
            if ($carrier === '' || $codDocument === '' || $allocation === ''
                || mb_strlen($carrier, 'UTF-8') > 50 || mb_strlen($codDocument, 'UTF-8') > 80
                || mb_strlen($allocation, 'UTF-8') > 64) {
                throw new InvalidArgumentException('COD cần đơn vị vận chuyển, mã đối soát và dòng phân bổ.');
            }
            $idempotency = hash('sha256', 'COD|' . mb_strtolower($carrier) . '|' . mb_strtolower($codDocument) . '|' . mb_strtolower($allocation));
            $transactionRef = null;
        }
        $duplicate = $db->prepare('SELECT maGDTT FROM tblGiaoDichThanhToan WHERE khoaIdempotency=:key FOR UPDATE');
        $duplicate->execute(['key' => $idempotency]);
        if ($duplicate->fetchColumn()) {
            throw new InvalidArgumentException('Chứng từ đối soát này đã được ghi nhận.');
        }
        $isLate = $method === 'CHUYEN_KHOAN'
            && strtotime($receivedAt . ' UTC') > strtotime($order['hanChuyenKhoan'] . ' UTC');
        $legacyResult = in_array($order['trangThaiXuLy'], ['DA_HUY', 'HOAN_TRA'], true)
            ? 'DON_DA_HUY'
            : ($isLate ? 'SAU_HAN' : 'DU_DIEU_KIEN');
        $prior = arsenal_payment_sync_order($db, $orderId);
        $nextAmount = $prior['eligibleCents'] + ($legacyResult === 'DU_DIEU_KIEN' ? $amount : 0);
        $allocationStatus = $nextAmount < $prior['dueCents'] ? 'THIEU'
            : ($nextAmount > $prior['dueCents'] ? 'DU' : 'KHOP_DU');
        $insert = $db->prepare(
            'INSERT INTO tblGiaoDichThanhToan
             (soTienThucNhan, phuongThuc, thucNhanLuc, ghiNhanLuc, canCuDoiChieu,
              maNganHangNhan, soTaiKhoanNhan, maGDNganHang, donViVanChuyen,
              maChungTuCOD, dongPhanBoCOD, khoaChungTuThu, ketQuaDoiChieu,
              maDH, maQTVXacNhan, taiKhoanNhan, maDoiSoat, ketQuaPhanBo, conHieuLuc, khoaIdempotency)
             VALUES (:amount, :method, :receivedAt, UTC_TIMESTAMP(3), :evidence,
              :bank, :account, :reference, :carrier, :codDocument, :allocation,
              :evidenceKey, :legacyResult, :orderId, :adminId, :accountName,
              :reconciliation, :allocationStatus, 1, :idempotency)'
        );
        $insert->execute([
            'amount' => arsenal_format_money($amount),
            'method' => $method,
            'receivedAt' => $receivedAt,
            'evidence' => $evidence,
            'bank' => $bankCode,
            'account' => $bankAccount,
            'reference' => $transactionRef,
            'carrier' => $carrier,
            'codDocument' => $codDocument,
            'allocation' => $allocation,
            'evidenceKey' => $idempotency,
            'legacyResult' => $legacyResult,
            'orderId' => $orderId,
            'adminId' => $adminId,
            'accountName' => $method === 'CHUYEN_KHOAN' ? $bankAccount : null,
            'reconciliation' => $method === 'COD' ? $codDocument : $reference,
            'allocationStatus' => $allocationStatus,
            'idempotency' => $idempotency,
        ]);
        $paymentId = (int)$db->lastInsertId();
        $totals = arsenal_payment_sync_order($db, $orderId);
        arsenal_payment_log($db, 'DOI_SOAT', 'QUAN_TRI', $orderId,
            'Quản trị viên ghi nhận giao dịch đối soát.', $order['trangThaiThanhToan'],
            $totals['status'], $paymentId, $adminId, $evidence, 'admin:' . $adminId);
        arsenal_payment_create_excess_refund(
            $db,
            $orderId,
            $paymentId,
            $totals['receivedCents'],
            $totals['allocatedCents'],
            $evidence
        );
        $db->commit();

        return ['maGDTT' => $paymentId, 'trangThaiThanhToan' => $totals['status']];
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
