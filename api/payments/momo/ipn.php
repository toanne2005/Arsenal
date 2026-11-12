<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once dirname(__DIR__, 2) . '/payment-service.php';

function arsenal_momo_ipn_signing_data(array $payload, string $accessKey): string
{
    $fields = [
        'accessKey' => $accessKey,
        'amount' => $payload['amount'] ?? '',
        'extraData' => $payload['extraData'] ?? '',
        'message' => $payload['message'] ?? '',
        'orderId' => $payload['orderId'] ?? '',
        'orderInfo' => $payload['orderInfo'] ?? '',
        'orderType' => $payload['orderType'] ?? '',
        'partnerCode' => $payload['partnerCode'] ?? '',
        'payType' => $payload['payType'] ?? '',
        'requestId' => $payload['requestId'] ?? '',
        'responseTime' => $payload['responseTime'] ?? '',
        'resultCode' => $payload['resultCode'] ?? '',
        'transId' => $payload['transId'] ?? '',
    ];
    $parts = [];
    foreach ($fields as $key => $value) {
        $parts[] = $key . '=' . (string)$value;
    }

    return implode('&', $parts);
}

try {
    arsenal_request_method('POST');
    $payload = arsenal_read_json();
    $config = arsenal_config();
    if (!arsenal_payment_momo_ready($config)) {
        throw new RuntimeException('MoMo chưa được cấu hình.');
    }
    $signature = (string)($payload['signature'] ?? '');
    $expected = hash_hmac(
        'sha256',
        arsenal_momo_ipn_signing_data($payload, (string)$config['momo_access_key']),
        (string)$config['momo_secret_key']
    );
    if ($signature === '' || !hash_equals($expected, $signature)) {
        arsenal_json_response(['resultCode' => 1, 'message' => 'Invalid signature'], 401);
    }
    if ((string)($payload['partnerCode'] ?? '') !== (string)$config['momo_partner_code']) {
        arsenal_json_response(['resultCode' => 1, 'message' => 'Invalid partner'], 401);
    }
    if ((int)($payload['resultCode'] ?? -1) !== 0) {
        arsenal_json_response(['resultCode' => 0, 'message' => 'Acknowledged']);
    }

    $orderId = trim((string)($payload['orderId'] ?? ''));
    $transId = trim((string)($payload['transId'] ?? ''));
    $amount = filter_var($payload['amount'] ?? null, FILTER_VALIDATE_INT);
    if (!preg_match('/^ARS-[A-F0-9]{24}$/', $orderId) || $transId === ''
        || mb_strlen($transId, 'UTF-8') > 80 || $amount === false || $amount < 1) {
        throw new InvalidArgumentException('Thông tin giao dịch MoMo không hợp lệ.');
    }

    $db = arsenal_db();
    $db->beginTransaction();
    try {
        $orderQuery = $db->prepare('SELECT * FROM tblDonHang WHERE maDH=:id FOR UPDATE');
        $orderQuery->execute(['id' => $orderId]);
        $order = $orderQuery->fetch();
        if (!$order || $order['phuongThucThanhToan'] !== 'MOMO'
            || (int)round((float)$order['tongTienPhaiTra']) !== $amount
            || (string)($order['maMomoRequest'] ?? '') !== (string)($payload['requestId'] ?? '')) {
            throw new InvalidArgumentException('Đơn MoMo không tồn tại hoặc số tiền không khớp.');
        }
        $idempotency = hash('sha256', 'MOMO|' . $transId);
        $duplicate = $db->prepare('SELECT maGDTT FROM tblGiaoDichThanhToan WHERE khoaIdempotency=:key FOR UPDATE');
        $duplicate->execute(['key' => $idempotency]);
        if ($duplicate->fetchColumn()) {
            $db->commit();
            arsenal_json_response(['resultCode' => 0, 'message' => 'Already processed']);
        }
        $due = arsenal_money_cents($order['tongTienPhaiTra']);
        $receivedBefore = arsenal_money_cents($order['soTienDaPhanBo']);
        $legacyResult = in_array($order['trangThaiXuLy'], ['DA_HUY', 'HOAN_TRA'], true)
            ? 'DON_DA_HUY'
            : 'DU_DIEU_KIEN';
        $allocationStatus = $amount * 100 < max(0, $due - $receivedBefore) ? 'THIEU'
            : ($amount * 100 > max(0, $due - $receivedBefore) ? 'DU' : 'KHOP_DU');
        $key = $idempotency;
        $db->prepare(
            'INSERT INTO tblGiaoDichThanhToan
             (soTienThucNhan, phuongThuc, thucNhanLuc, ghiNhanLuc, canCuDoiChieu,
              maGDNganHang, khoaChungTuThu, ketQuaDoiChieu, maDH, maQTVXacNhan,
              taiKhoanNhan, maDoiSoat, ketQuaPhanBo, conHieuLuc, khoaIdempotency)
             VALUES (:amount, \'MOMO\', UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), :evidence,
              :transId, :receiptKey, :legacyResult, :orderId, NULL, NULL,
              :requestId, :allocation, 1, :idempotency)'
        )->execute([
            'amount' => arsenal_format_money($amount * 100),
            'evidence' => 'MoMo IPN transId=' . $transId,
            'transId' => $transId,
            'receiptKey' => $key,
            'legacyResult' => $legacyResult,
            'orderId' => $orderId,
            'requestId' => substr((string)($payload['requestId'] ?? ''), 0, 80),
            'allocation' => $allocationStatus,
            'idempotency' => $idempotency,
        ]);
        $paymentId = (int)$db->lastInsertId();
        $totals = arsenal_payment_sync_order($db, $orderId);
        arsenal_payment_log($db, 'IPN_MOMO', 'TU_DONG', $orderId,
            'MoMo IPN được xác thực và ghi nhận.', $order['trangThaiThanhToan'],
            $totals['status'], $paymentId, null, $transId, 'momo-ipn');
        arsenal_payment_create_excess_refund(
            $db,
            $orderId,
            $paymentId,
            $totals['receivedCents'],
            $totals['allocatedCents'],
            $transId
        );
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
    arsenal_json_response(['resultCode' => 0, 'message' => 'Success']);
} catch (InvalidArgumentException $error) {
    arsenal_json_response(['resultCode' => 1, 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('ARSENAL MoMo IPN: ' . $error->getMessage());
    arsenal_json_response(['resultCode' => 1, 'message' => 'Processing error'], 500);
}
