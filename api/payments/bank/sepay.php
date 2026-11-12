<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once dirname(__DIR__, 2) . '/payment-service.php';

function arsenal_sepay_response(array $payload, int $status = 200): void
{
    arsenal_json_response($payload, $status);
}

function arsenal_sepay_timestamp(string $value): string
{
    if ($value === '') {
        throw new InvalidArgumentException('Webhook SePay thiếu thời điểm giao dịch.');
    }

    try {
        $time = new DateTimeImmutable($value, new DateTimeZone('Asia/Ho_Chi_Minh'));
    } catch (Throwable $error) {
        throw new InvalidArgumentException('Thời điểm giao dịch SePay không hợp lệ.');
    }

    $utc = $time->setTimezone(new DateTimeZone('UTC'));
    if ($utc->getTimestamp() > time() + 300) {
        throw new InvalidArgumentException('Thời điểm giao dịch SePay nằm trong tương lai.');
    }

    return $utc->format('Y-m-d H:i:s');
}

function arsenal_sepay_auto_confirm_order(PDO $db, string $orderId, array $totals, string $transactionId): bool
{
    if ($totals['eligibleCents'] < $totals['dueCents']) {
        return false;
    }

    $query = $db->prepare(
        'SELECT phuongThucThanhToan, trangThaiXuLy, trangThaiThanhToan, tonKhoDaGiu
         FROM tblDonHang WHERE maDH=:id FOR UPDATE'
    );
    $query->execute(['id' => $orderId]);
    $order = $query->fetch();
    if (!$order || $order['phuongThucThanhToan'] !== 'CHUYEN_KHOAN'
        || $order['trangThaiXuLy'] !== 'CHO_XAC_NHAN' || !(int)$order['tonKhoDaGiu']) {
        return false;
    }

    $coupon = $db->prepare(
        "SELECT maCode FROM tblLichSuApDungMaGiamGia
         WHERE maDH=:id AND trangThaiLuot='DANG_GIU' FOR UPDATE"
    );
    $coupon->execute(['id' => $orderId]);
    $code = $coupon->fetchColumn();
    if ($code !== false) {
        $db->prepare(
            'UPDATE tblMaGiamGia SET luotDangGiu=luotDangGiu-1, luotDaSuDung=luotDaSuDung+1
             WHERE maCode=:code AND luotDangGiu>0'
        )->execute(['code' => $code]);
        if ((int)$db->query('SELECT ROW_COUNT()')->fetchColumn() !== 1) {
            throw new RuntimeException('Không thể chốt lượt mã giảm giá sau khi thanh toán.');
        }
        $db->prepare(
            "UPDATE tblLichSuApDungMaGiamGia
             SET trangThaiLuot='DA_SU_DUNG', suDungLuc=UTC_TIMESTAMP(3)
             WHERE maDH=:id AND trangThaiLuot='DANG_GIU'"
        )->execute(['id' => $orderId]);
    }

    $db->prepare(
        "UPDATE tblDonHang SET trangThaiXuLy='DA_XAC_NHAN'
         WHERE maDH=:id AND trangThaiXuLy='CHO_XAC_NHAN' AND tonKhoDaGiu=1"
    )->execute(['id' => $orderId]);
    if ((int)$db->query('SELECT ROW_COUNT()')->fetchColumn() !== 1) {
        throw new RuntimeException('Không thể tự xác nhận đơn đã thanh toán.');
    }

    arsenal_payment_log(
        $db,
        'DOI_SOAT',
        'TU_DONG',
        $orderId,
        'SePay xác thực đã nhận đủ tiền; đơn được tự động chuyển sang DA_XAC_NHAN.',
        $order['trangThaiThanhToan'],
        $order['trangThaiThanhToan'],
        null,
        null,
        'SePay transaction id=' . $transactionId,
        'sepay-webhook'
    );

    return true;
}

try {
    arsenal_request_method('POST');
    $config = arsenal_config();
    $secret = trim((string)($config['sepay_webhook_secret'] ?? ''));
    if ($secret === '') {
        arsenal_sepay_response(['success' => false, 'message' => 'Webhook SePay chưa được cấu hình.'], 503);
    }

    $timestamp = (string)($_SERVER['HTTP_X_SEPAY_TIMESTAMP'] ?? '');
    $signature = (string)($_SERVER['HTTP_X_SEPAY_SIGNATURE'] ?? '');
    $rawBody = (string)file_get_contents('php://input');
    if (!preg_match('/^[0-9]{1,12}$/', $timestamp)
        || abs(time() - (int)$timestamp) > 300) {
        arsenal_sepay_response(['success' => false, 'message' => 'Webhook SePay hết hạn hoặc thiếu timestamp.'], 401);
    }
    $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    if ($signature === '' || !hash_equals($expected, $signature)) {
        arsenal_sepay_response(['success' => false, 'message' => 'Chữ ký webhook SePay không hợp lệ.'], 401);
    }

    try {
        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new InvalidArgumentException('Dữ liệu webhook SePay không hợp lệ.');
    }
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Dữ liệu webhook SePay không hợp lệ.');
    }
    if (strtolower(trim((string)($payload['transferType'] ?? ''))) !== 'in') {
        arsenal_sepay_response(['success' => true, 'ignored' => true]);
    }

    $transactionId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
    $amountRaw = $payload['transferAmount'] ?? null;
    $amount = filter_var($amountRaw, FILTER_VALIDATE_INT);
    $content = trim((string)($payload['content'] ?? ''));
    if ($transactionId === false || $transactionId < 1 || $amount === false || $amount < 1
        || !preg_match('/(?<![A-Z0-9])ARS-?([A-F0-9]{24})(?![A-Z0-9])/i', $content, $orderMatch)) {
        arsenal_sepay_response(['success' => true, 'ignored' => true]);
    }

    $orderId = 'ARS-' . strtoupper($orderMatch[1]);
    $accountNumber = preg_replace('/\s+/', '', (string)($payload['accountNumber'] ?? ''));
    if (!is_string($accountNumber) || $accountNumber === '') {
        throw new InvalidArgumentException('Webhook SePay thiếu số tài khoản nhận.');
    }
    $receivedAt = arsenal_sepay_timestamp(trim((string)($payload['transactionDate'] ?? '')));
    $bankReference = trim((string)($payload['referenceCode'] ?? ''));
    if ($bankReference === '') {
        $bankReference = 'SEPAY-' . $transactionId;
    }
    if (mb_strlen($bankReference, 'UTF-8') > 80) {
        throw new InvalidArgumentException('Mã giao dịch ngân hàng SePay không hợp lệ.');
    }

    $db = arsenal_db();
    $db->beginTransaction();
    try {
        $orderQuery = $db->prepare('SELECT * FROM tblDonHang WHERE maDH=:id FOR UPDATE');
        $orderQuery->execute(['id' => $orderId]);
        $order = $orderQuery->fetch();
        if (!$order || $order['phuongThucThanhToan'] !== 'CHUYEN_KHOAN') {
            $db->commit();
            arsenal_sepay_response(['success' => true, 'ignored' => true]);
        }
        $expectedAccount = preg_replace('/\s+/', '', (string)$order['soTaiKhoanNhan']);
        if (!is_string($expectedAccount) || !hash_equals($expectedAccount, $accountNumber)) {
            throw new InvalidArgumentException('Tài khoản nhận trong webhook không khớp đơn hàng.');
        }
        if (strtotime($receivedAt . ' UTC') < strtotime($order['datLuc'] . ' UTC')) {
            throw new InvalidArgumentException('Thời điểm nhận tiền SePay trước khi tạo đơn.');
        }

        $idempotency = hash('sha256', 'SEPAY|' . $transactionId);
        $duplicate = $db->prepare(
            'SELECT maDH FROM tblGiaoDichThanhToan WHERE khoaIdempotency=:key FOR UPDATE'
        );
        $duplicate->execute(['key' => $idempotency]);
        $duplicateOrderId = $duplicate->fetchColumn();
        if ($duplicateOrderId !== false) {
            if ((string)$duplicateOrderId !== $orderId) {
                throw new InvalidArgumentException('Mã giao dịch SePay đã được gắn với đơn khác.');
            }
            $db->commit();
            arsenal_sepay_response(['success' => true, 'duplicate' => true]);
        }

        $isLate = strtotime($receivedAt . ' UTC') > strtotime($order['hanChuyenKhoan'] . ' UTC');
        $eligible = !in_array($order['trangThaiXuLy'], ['DA_HUY', 'HOAN_TRA'], true) && !$isLate;
        $legacyResult = in_array($order['trangThaiXuLy'], ['DA_HUY', 'HOAN_TRA'], true)
            ? 'DON_DA_HUY'
            : ($isLate ? 'SAU_HAN' : 'DU_DIEU_KIEN');
        $prior = arsenal_payment_sync_order($db, $orderId);
        $nextAmount = $prior['eligibleCents'] + ($eligible ? $amount * 100 : 0);
        $allocationStatus = $nextAmount < $prior['dueCents'] ? 'THIEU'
            : ($nextAmount > $prior['dueCents'] ? 'DU' : 'KHOP_DU');
        $evidence = 'SePay transaction id=' . $transactionId
            . '; reference=' . $bankReference
            . '; content=' . mb_substr($content, 0, 250, 'UTF-8');
        $db->prepare(
            'INSERT INTO tblGiaoDichThanhToan
             (soTienThucNhan, phuongThuc, thucNhanLuc, ghiNhanLuc, canCuDoiChieu,
              maNganHangNhan, soTaiKhoanNhan, maGDNganHang, taiKhoanNhan, maDoiSoat,
              khoaChungTuThu, ketQuaDoiChieu, maDH, maQTVXacNhan, ketQuaPhanBo,
              conHieuLuc, khoaIdempotency)
             VALUES (:amount, \'CHUYEN_KHOAN\', :receivedAt, UTC_TIMESTAMP(3), :evidence,
              :bankCode, :accountNumber, :reference, :accountAlias, :sepayId, :receiptKey, :result,
              :orderId, NULL, :allocation, 1, :idempotency)'
        )->execute([
            'amount' => arsenal_format_money($amount * 100),
            'receivedAt' => $receivedAt,
            'evidence' => $evidence,
            'bankCode' => $order['tenNganHangNhan'],
            'accountNumber' => $accountNumber,
            'reference' => $bankReference,
            'accountAlias' => $accountNumber,
            'sepayId' => (string)$transactionId,
            'receiptKey' => $idempotency,
            'result' => $legacyResult,
            'orderId' => $orderId,
            'allocation' => $allocationStatus,
            'idempotency' => $idempotency,
        ]);
        $paymentId = (int)$db->lastInsertId();
        $totals = arsenal_payment_sync_order($db, $orderId);
        arsenal_payment_log(
            $db,
            'DOI_SOAT',
            'TU_DONG',
            $orderId,
            'SePay gửi webhook giao dịch chuyển khoản đã xác thực.',
            $order['trangThaiThanhToan'],
            $totals['status'],
            $paymentId,
            null,
            $evidence,
            'sepay-webhook'
        );
        arsenal_payment_create_excess_refund(
            $db,
            $orderId,
            $paymentId,
            $totals['receivedCents'],
            $totals['allocatedCents'],
            $evidence
        );
        $autoConfirmed = arsenal_sepay_auto_confirm_order($db, $orderId, $totals, (string)$transactionId);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    arsenal_sepay_response(['success' => true, 'autoConfirmed' => $autoConfirmed]);
} catch (InvalidArgumentException $error) {
    arsenal_sepay_response(['success' => false, 'message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('ARSENAL SePay webhook: ' . $error->getMessage());
    arsenal_sepay_response(['success' => false, 'message' => 'Không thể xử lý webhook SePay.'], 500);
}
