<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/payment-service.php';
arsenal_session_start();

try {
    arsenal_request_method('POST');
    $payload = arsenal_read_json();
    $adminId = arsenal_require_admin(['THANH_TOAN']);
    arsenal_require_csrf($payload);
    $orderId = strtoupper(trim((string)($payload['maDH'] ?? '')));
    if (!preg_match('/^[A-Z0-9_-]{1,32}$/', $orderId)) {
        throw new InvalidArgumentException('Mã đơn hàng không hợp lệ.');
    }
    $result = arsenal_payment_record_reconciled(
        arsenal_db(),
        $adminId,
        $orderId,
        $payload,
        PAYMENT_RECONCILE_METHOD
    );
    arsenal_json_response(['success' => true] + $result);
} catch (InvalidArgumentException $error) {
    arsenal_json_response(['message' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log('ARSENAL payment reconciliation: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể lưu chứng từ đối soát.'], 500);
}
