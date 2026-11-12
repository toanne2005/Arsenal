<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/payment-service.php';
arsenal_session_start();

function arsenal_admin_product_payload(array $payload): array
{
    $id = strtoupper(trim((string)($payload['id'] ?? '')));
    $name = trim((string)($payload['name'] ?? ''));
    $status = strtoupper(trim((string)($payload['status'] ?? 'AN')));
    $gallery = $payload['gallery'] ?? [];
    $description = trim((string)($payload['description'] ?? ''));
    $specifications = trim((string)($payload['specifications'] ?? ''));
    $image = trim((string)($payload['image'] ?? ''));
    $scale = trim((string)($payload['scale'] ?? ''));
    $complexityRaw = $payload['complexity'] ?? null;
    $gradeRaw = $payload['gradeId'] ?? null;
    $seriesRaw = $payload['seriesId'] ?? null;
    $category = filter_var($payload['categoryId'] ?? null, FILTER_VALIDATE_INT);
    $reference = $payload['referencePrice'] ?? null;
    $price = $payload['price'] ?? null;
    $preorder = !empty($payload['preorder']) ? 1 : 0;
    $featured = !empty($payload['featured']) ? 1 : 0;

    if (!preg_match('/^[A-Z0-9_-]{1,32}$/', $id)) {
        throw new InvalidArgumentException('Mã sản phẩm chỉ được gồm chữ in hoa, số, gạch ngang hoặc gạch dưới.');
    }
    if ($name === '' || mb_strlen($name, 'UTF-8') > 180) {
        throw new InvalidArgumentException('Tên sản phẩm là bắt buộc và tối đa 180 ký tự.');
    }
    if (!in_array($status, ['SAP_MO_BAN', 'DANG_BAN', 'AN', 'NGUNG_KINH_DOANH'], true)) {
        throw new InvalidArgumentException('Trạng thái sản phẩm không hợp lệ.');
    }
    if ($category === false || $category < 1) {
        throw new InvalidArgumentException('Danh mục không hợp lệ.');
    }
    if ($status === 'DANG_BAN' && ($price === null || $price === '' || !is_numeric($price) || (float)$price < 0)) {
        throw new InvalidArgumentException('Sản phẩm đang bán cần có giá bán không âm.');
    }
    if ($price !== null && $price !== '' && (!is_numeric($price) || (float)$price < 0)) {
        throw new InvalidArgumentException('Giá bán không hợp lệ.');
    }
    if ($reference !== null && $reference !== '' && (!is_numeric($reference) || (float)$reference < 0)) {
        throw new InvalidArgumentException('Giá tham khảo không hợp lệ.');
    }
    if ($image !== '' && !arsenal_admin_valid_image_url($image)) {
        throw new InvalidArgumentException('URL ảnh đại diện không hợp lệ.');
    }
    if (!is_array($gallery) || count($gallery) > 20) {
        throw new InvalidArgumentException('Bộ ảnh tối đa 20 URL.');
    }
    foreach ($gallery as $url) {
        if (!is_string($url) || !arsenal_admin_valid_image_url(trim($url))) {
            throw new InvalidArgumentException('URL trong bộ ảnh không hợp lệ.');
        }
    }
    if ($scale !== '' && mb_strlen($scale, 'UTF-8') > 20) {
        throw new InvalidArgumentException('Tỷ lệ tối đa 20 ký tự.');
    }
    $complexity = $complexityRaw === null || $complexityRaw === '' ? null : filter_var($complexityRaw, FILTER_VALIDATE_INT);
    if ($complexity !== null && ($complexity === false || $complexity < 1 || $complexity > 5)) {
        throw new InvalidArgumentException('Độ phức tạp lắp ráp từ 1 đến 5.');
    }
    $grade = $gradeRaw === null || $gradeRaw === '' ? null : filter_var($gradeRaw, FILTER_VALIDATE_INT);
    $series = $seriesRaw === null || $seriesRaw === '' ? null : filter_var($seriesRaw, FILTER_VALIDATE_INT);
    if ($grade === false || $series === false) {
        throw new InvalidArgumentException('Phân hạng hoặc dòng Mecha không hợp lệ.');
    }

    return [
        'id' => $id,
        'name' => $name,
        'status' => $status,
        'categoryId' => $category,
        'stock' => 0,
        'price' => $price === null || $price === '' ? null : number_format((float)$price, 2, '.', ''),
        'referencePrice' => $reference === null || $reference === '' ? null : number_format((float)$reference, 2, '.', ''),
        'image' => $image === '' ? null : $image,
        'gallery' => json_encode(array_values($gallery), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        'description' => $description === '' ? null : $description,
        'specifications' => $specifications === '' ? null : $specifications,
        'scale' => $scale === '' ? null : $scale,
        'complexity' => $complexity,
        'gradeId' => $grade,
        'seriesId' => $series,
        'preorder' => $preorder,
        'featured' => $featured,
    ];
}

function arsenal_admin_valid_image_url(string $url): bool
{
    if ($url === '' || strlen($url) > 512 || preg_match('/[\x00-\x20<>"\']/', $url)) {
        return false;
    }
    if (str_starts_with($url, '/')) {
        return !str_starts_with($url, '//');
    }

    $parts = parse_url($url);

    return is_array($parts)
        && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        && !empty($parts['host']);
}

function arsenal_admin_save_product(PDO $db, array $product, bool $isUpdate): void
{
    $category = $db->prepare('SELECT maDM FROM tblDanhMuc WHERE maDM = :id AND hoatDong = 1');
    $category->execute(['id' => $product['categoryId']]);
    if (!$category->fetchColumn()) {
        throw new InvalidArgumentException('Danh mục không tồn tại hoặc đang ngừng hoạt động.');
    }
    if ($product['gradeId'] !== null) {
        $grade = $db->prepare('SELECT maPhanHang FROM tblPhanHangGunpla WHERE maPhanHang = :id AND hoatDong = 1');
        $grade->execute(['id' => $product['gradeId']]);
        if (!$grade->fetchColumn()) {
            throw new InvalidArgumentException('Phân hạng không tồn tại hoặc đang ngừng hoạt động.');
        }
    }
    if ($product['seriesId'] !== null) {
        $series = $db->prepare('SELECT maDong FROM tblDongMecha WHERE maDong = :id AND hoatDong = 1');
        $series->execute(['id' => $product['seriesId']]);
        if (!$series->fetchColumn()) {
            throw new InvalidArgumentException('Dòng Mecha không tồn tại hoặc đang ngừng hoạt động.');
        }
    }

    $columns = '(maSP, tenSP, moTa, anhDaiDienUrl, anhBoSung, thongSoKyThuat, tyLe,
                doPhucTapLapRap, giaBan, tonKhaDung, trangThai, giaThamKhao, nhanDatTruoc,
                uuTienGoiY, themLuc, maDM, maPhanHang, maDong)';
    $values = '(:maSP, :tenSP, :moTa, :anhDaiDienUrl, :anhBoSung, :thongSoKyThuat, :tyLe,
                :doPhucTapLapRap, :giaBan, :tonKhaDung, :trangThai, :giaThamKhao, :nhanDatTruoc,
                :uuTienGoiY, UTC_TIMESTAMP(3), :maDM, :maPhanHang, :maDong)';
    $parameters = [
        'maSP' => $product['id'],
        'tenSP' => $product['name'],
        'moTa' => $product['description'],
        'anhDaiDienUrl' => $product['image'],
        'anhBoSung' => $product['gallery'],
        'thongSoKyThuat' => $product['specifications'],
        'tyLe' => $product['scale'],
        'doPhucTapLapRap' => $product['complexity'],
        'giaBan' => $product['price'],
        'tonKhaDung' => $product['stock'],
        'trangThai' => $product['status'],
        'giaThamKhao' => $product['referencePrice'],
        'nhanDatTruoc' => $product['preorder'],
        'uuTienGoiY' => $product['featured'],
        'maDM' => $product['categoryId'],
        'maPhanHang' => $product['gradeId'],
        'maDong' => $product['seriesId'],
    ];

    if ($isUpdate) {
        unset($parameters['maSP']);
        $db->prepare(
            'UPDATE tblSanPham SET tenSP=:tenSP, moTa=:moTa, anhDaiDienUrl=:anhDaiDienUrl,
             anhBoSung=:anhBoSung, thongSoKyThuat=:thongSoKyThuat, tyLe=:tyLe,
             doPhucTapLapRap=:doPhucTapLapRap, giaBan=:giaBan,
             trangThai=:trangThai, giaThamKhao=:giaThamKhao, nhanDatTruoc=:nhanDatTruoc,
             uuTienGoiY=:uuTienGoiY, maDM=:maDM, maPhanHang=:maPhanHang, maDong=:maDong
             WHERE maSP=:productId'
        )->execute(array_diff_key($parameters, ['tonKhaDung' => true]) + ['productId' => $product['id']]);
        return;
    }

    $db->prepare("INSERT INTO tblSanPham $columns VALUES $values")->execute($parameters);
}

function arsenal_admin_order(PDO $db, string $orderId, bool $lock = false): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $orderId)) {
        throw new InvalidArgumentException('Mã đơn hàng không hợp lệ.');
    }

    $statement = $db->prepare(
        'SELECT maDH, datLuc, hoTenNhan, dienThoaiNhan, diaChiNhan, emailNhan,
                phuongThucThanhToan, tienHang, tienGiam, tienHangSauGiam, phiVanChuyen,
                tongPhaiTra, hanChuyenKhoan, trangThaiXuLy, trangThaiThanhToan, soTienDaPhanBo, tonKhoDaGiu,
                maVanDon, ghiChuGiaoHang, giaoThanhCongLuc,
                lyDoHuy, maKH
         FROM tblDonHang WHERE maDH=:id' . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute(['id' => $orderId]);
    $order = $statement->fetch();
    if (!$order) {
        throw new InvalidArgumentException('Không tìm thấy đơn hàng.');
    }

    return $order;
}

function arsenal_admin_order_lines(PDO $db, string $orderId, bool $lock = false): array
{
    $statement = $db->prepare(
        'SELECT maSP AS productId, tenSPLucDat AS productName, soLuong AS quantity,
                donGiaLucDat AS unitPrice, thanhTien AS lineTotal
         FROM tblChiTietDonHang WHERE maDH=:id ORDER BY maSP' . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute(['id' => $orderId]);

    return $statement->fetchAll();
}

function arsenal_admin_order_payment_totals(PDO $db, string $orderId): array
{
    $received = $db->prepare(
        'SELECT COALESCE(SUM(soTienThucNhan), 0)
         FROM tblGiaoDichThanhToan WHERE maDH=:id AND conHieuLuc=1'
    );
    $received->execute(['id' => $orderId]);
    $refunded = $db->prepare(
        'SELECT COALESCE(SUM(ht.tongDaHoan), 0)
         FROM tblLanHoanTien ht
         INNER JOIN tblYeuCauHoanTien yc ON yc.maYCHT=ht.maYCHT
         WHERE yc.maDH=:id'
    );
    $refunded->execute(['id' => $orderId]);

    return [
        'received' => (string)$received->fetchColumn(),
        'refunded' => (string)$refunded->fetchColumn(),
    ];
}

function arsenal_admin_order_detail(PDO $db, string $orderId): array
{
    $order = arsenal_admin_order($db, $orderId);
    $order['items'] = arsenal_admin_order_lines($db, $orderId);
    $totals = arsenal_admin_order_payment_totals($db, $orderId);
    $order['totalReceived'] = $totals['received'];
    $order['totalRefunded'] = $totals['refunded'];
    $order['payment'] = arsenal_admin_payment_summary($db, $orderId);

    return $order;
}

function arsenal_admin_change_stock(PDO $db, int $adminId, string $productId, int $delta, string $type, string $reason, ?string $orderId = null): void
{
    $statement = $db->prepare('SELECT tonKhaDung FROM tblSanPham WHERE maSP=:id FOR UPDATE');
    $statement->execute(['id' => $productId]);
    $before = $statement->fetchColumn();
    if ($before === false) {
        throw new InvalidArgumentException('Không tìm thấy sản phẩm trong đơn.');
    }
    $before = (int)$before;
    $after = $before + $delta;
    if ($delta === 0 || $after < 0 || $after > 4294967295) {
        throw new InvalidArgumentException('Số lượng tồn sau biến động không hợp lệ.');
    }

    $db->prepare('UPDATE tblSanPham SET tonKhaDung=:stock WHERE maSP=:id')
        ->execute(['stock' => $after, 'id' => $productId]);
    $db->prepare(
        'INSERT INTO tblLichSuTonKho
         (loaiBienDong, soLuongThayDoi, soLuongTruoc, soLuongSau, thucHienLuc, lyDo,
          maSP, maQTVThucHien, maDH)
         VALUES (:type, :delta, :before, :after, UTC_TIMESTAMP(3), :reason, :product, :admin, :orderId)'
    )->execute([
        'type' => $type,
        'delta' => $delta,
        'before' => $before,
        'after' => $after,
        'reason' => $reason,
        'product' => $productId,
        'admin' => $adminId,
        'orderId' => $orderId,
    ]);
}

function arsenal_admin_release_coupon(PDO $db, string $orderId): void
{
    $statement = $db->prepare(
        'SELECT maCode, trangThaiLuot FROM tblLichSuApDungMaGiamGia
         WHERE maDH=:id FOR UPDATE'
    );
    $statement->execute(['id' => $orderId]);
    $coupon = $statement->fetch();
    if (!$coupon || !in_array($coupon['trangThaiLuot'], ['DANG_GIU', 'DA_SU_DUNG'], true)) {
        return;
    }

    if ($coupon['trangThaiLuot'] === 'DANG_GIU') {
        $db->prepare('UPDATE tblMaGiamGia SET luotDangGiu=luotDangGiu-1 WHERE maCode=:code AND luotDangGiu>0')
            ->execute(['code' => $coupon['maCode']]);
    } else {
        $db->prepare('UPDATE tblMaGiamGia SET luotDaSuDung=luotDaSuDung-1 WHERE maCode=:code AND luotDaSuDung>0')
            ->execute(['code' => $coupon['maCode']]);
    }
    $db->prepare(
        "UPDATE tblLichSuApDungMaGiamGia
         SET trangThaiLuot='DA_HOAN_LUOT', hoanLuotLuc=UTC_TIMESTAMP(3)
         WHERE maDH=:id AND trangThaiLuot IN ('DANG_GIU','DA_SU_DUNG')"
    )->execute(['id' => $orderId]);
}

function arsenal_admin_confirm_order(PDO $db, int $adminId, string $orderId): void
{
    $db->beginTransaction();
    try {
        $order = arsenal_admin_order($db, $orderId, true);
        if ($order['trangThaiXuLy'] !== 'CHO_XAC_NHAN') {
            throw new InvalidArgumentException('Đơn không còn ở trạng thái chờ xác nhận.');
        }
        $items = arsenal_admin_order_lines($db, $orderId, true);
        if (!$items) {
            throw new InvalidArgumentException('Đơn hàng không có sản phẩm.');
        }

        if ($order['phuongThucThanhToan'] === 'CHUYEN_KHOAN') {
            $paid = $db->prepare(
                "SELECT COALESCE(SUM(soTienThucNhan), 0) FROM tblGiaoDichThanhToan
                 WHERE maDH=:id AND phuongThuc='CHUYEN_KHOAN'
                   AND ketQuaDoiChieu='DU_DIEU_KIEN' AND thucNhanLuc<=:deadline"
            );
            $paid->execute(['id' => $orderId, 'deadline' => $order['hanChuyenKhoan']]);
            $eligible = arsenal_money_cents($paid->fetchColumn());
            if ($eligible < arsenal_money_cents($order['tongPhaiTra'])) {
                throw new InvalidArgumentException('Đơn chuyển khoản chưa được đối chiếu đủ tiền đúng hạn.');
            }
        } elseif ($order['phuongThucThanhToan'] === 'MOMO'
            && arsenal_money_cents($order['soTienDaPhanBo']) < arsenal_money_cents($order['tongPhaiTra'])) {
            throw new InvalidArgumentException('Đơn MoMo chưa được IPN xác nhận đã thanh toán đủ.');
        }

        if (!(int)$order['tonKhoDaGiu']) {
            foreach ($items as $item) {
                arsenal_admin_change_stock(
                    $db,
                    $adminId,
                    $item['productId'],
                    -(int)$item['quantity'],
                    'GIU_DON',
                    'Trừ tồn khi xác nhận đơn ' . $orderId,
                    $orderId
                );
            }
        }

        $db->prepare(
            "UPDATE tblDonHang SET trangThaiXuLy='DA_XAC_NHAN', tonKhoDaGiu=1
             WHERE maDH=:id AND trangThaiXuLy='CHO_XAC_NHAN'"
        )->execute(['id' => $orderId]);
        if ($db->query('SELECT ROW_COUNT()')->fetchColumn() !== 1) {
            throw new InvalidArgumentException('Trạng thái đơn đã thay đổi, hãy tải lại.');
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
            if ($db->query('SELECT ROW_COUNT()')->fetchColumn() !== 1) {
                throw new InvalidArgumentException('Lượt mã giảm giá không còn hợp lệ.');
            }
            $db->prepare(
                "UPDATE tblLichSuApDungMaGiamGia
                 SET trangThaiLuot='DA_SU_DUNG', suDungLuc=UTC_TIMESTAMP(3)
                 WHERE maDH=:id AND trangThaiLuot='DANG_GIU'"
            )->execute(['id' => $orderId]);
        }

        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function arsenal_admin_cancel_order(PDO $db, int $adminId, string $orderId, string $reason, array $returns): void
{
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
        throw new InvalidArgumentException('Lý do hủy hoặc ghi chú kiểm hàng là bắt buộc (tối đa 500 ký tự).');
    }

    $db->beginTransaction();
    try {
        $order = arsenal_admin_order($db, $orderId, true);
        $status = $order['trangThaiXuLy'];
        if (!in_array($status, ['CHO_XAC_NHAN', 'DA_XAC_NHAN', 'DANG_GIAO'], true)) {
            throw new InvalidArgumentException('Chỉ có thể hủy đơn đang chờ xác nhận, đã xác nhận hoặc đang giao.');
        }
        $items = arsenal_admin_order_lines($db, $orderId, true);
        $returnMap = [];
        if ($status === 'DANG_GIAO') {
            if (count($returns) > count($items)) {
                throw new InvalidArgumentException('Có mặt hàng nhận lại không thuộc đơn.');
            }
            foreach ($returns as $return) {
                if (!is_array($return)) {
                    throw new InvalidArgumentException('Chi tiết hàng nhận lại không hợp lệ.');
                }
                $id = (string)($return['productId'] ?? '');
                $quantity = filter_var($return['quantity'] ?? null, FILTER_VALIDATE_INT);
                if ($quantity === false || $quantity < 0 || isset($returnMap[$id])) {
                    throw new InvalidArgumentException('Số lượng hàng nhận lại không hợp lệ.');
                }
                $returnMap[$id] = $quantity;
            }
        }

        foreach ($returnMap as $id => $quantity) {
            $line = null;
            foreach ($items as $item) {
                if ($item['productId'] === $id) {
                    $line = $item;
                    break;
                }
            }
            if (!$line || $quantity > (int)$line['quantity']) {
                throw new InvalidArgumentException('Số lượng nhận lại vượt số đã giao hoặc sản phẩm không thuộc đơn.');
            }
        }

        if ($status === 'DA_XAC_NHAN' || ($status === 'CHO_XAC_NHAN' && (int)$order['tonKhoDaGiu'] === 1)) {
            foreach ($items as $item) {
                arsenal_admin_change_stock(
                    $db,
                    $adminId,
                    $item['productId'],
                    (int)$item['quantity'],
                    'TRA_HUY',
                    'Hoàn tồn đơn chưa bàn giao ' . $orderId . ': ' . $reason,
                    $orderId
                );
            }
        } elseif ($status === 'DANG_GIAO') {
            foreach ($items as $item) {
                $quantity = (int)($returnMap[$item['productId']] ?? 0);
                if ($quantity > 0) {
                    arsenal_admin_change_stock(
                        $db,
                        $adminId,
                        $item['productId'],
                        $quantity,
                        'TRA_HUY',
                        'Nhận lại hàng đủ điều kiện bán từ đơn ' . $orderId . ': ' . $reason,
                        $orderId
                    );
                }
            }
        }

        arsenal_admin_release_coupon($db, $orderId);
        $db->prepare(
            "UPDATE tblDonHang
             SET trangThaiXuLy='DA_HUY', tonKhoDaGiu=0, huyLuc=UTC_TIMESTAMP(3), lyDoHuy=:reason
             WHERE maDH=:id AND trangThaiXuLy=:status"
        )->execute(['reason' => $reason, 'id' => $orderId, 'status' => $status]);
        if ($db->query('SELECT ROW_COUNT()')->fetchColumn() !== 1) {
            throw new InvalidArgumentException('Trạng thái đơn đã thay đổi, hãy tải lại.');
        }

        $payments = arsenal_admin_order_payment_totals($db, $orderId);
        $refundStatus = arsenal_admin_refund_totals($db, $orderId);
        $toRefund = arsenal_money_cents($payments['received'])
            - $refundStatus['refunded']
            - $refundStatus['pending'];
        if ($toRefund > 0) {
            arsenal_admin_create_refund_request(
                $db,
                $adminId,
                $orderId,
                'HUY_DON',
                'Hoàn khoản đã thu khi hủy đơn: ' . $reason,
                $toRefund
            );
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function arsenal_admin_stock_change(PDO $db, int $adminId, string $productId, int $delta, string $type, string $reason): void
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $productId)) {
        throw new InvalidArgumentException('Mã sản phẩm không hợp lệ.');
    }
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
        throw new InvalidArgumentException('Lý do nhập hoặc điều chỉnh tồn là bắt buộc (tối đa 500 ký tự).');
    }

    $db->beginTransaction();
    try {
        arsenal_admin_change_stock($db, $adminId, $productId, $delta, $type, $reason);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function arsenal_admin_log_payment_event(PDO $db, string $type, string $reason, string $orderId, int $adminId, ?int $paymentId = null, ?int $refundRequestId = null, ?int $refundId = null, ?string $before = null, ?string $after = null, ?string $evidence = null): void
{
    $db->prepare(
        'INSERT INTO tblNhatKyThanhToan
         (loaiSuKien, nguon, ghiLuc, trangThaiTruoc, trangThaiSau, lyDo, canCu,
          maDH, maQTV, maGDTT, maYCHT, maLHT)
         VALUES (:type, \'QUAN_TRI\', UTC_TIMESTAMP(3), :before, :after, :reason,
                 :evidence, :orderId, :admin, :payment, :refundRequest, :refund)'
    )->execute([
        'type' => $type,
        'before' => $before,
        'after' => $after,
        'reason' => $reason,
        'evidence' => $evidence,
        'orderId' => $orderId,
        'admin' => $adminId,
        'payment' => $paymentId,
        'refundRequest' => $refundRequestId,
        'refund' => $refundId,
    ]);
}

function arsenal_admin_payment_summary(PDO $db, string $orderId): array
{
    $order = arsenal_admin_order($db, $orderId);
    $payments = $db->prepare(
        'SELECT maGDTT AS id, soTienThucNhan AS amount, phuongThuc AS method,
                thucNhanLuc AS receivedAt, ghiNhanLuc AS recordedAt, canCuDoiChieu AS evidence,
                ketQuaDoiChieu AS reconciliationStatus, ketQuaPhanBo AS allocationStatus,
                conHieuLuc AS active, maNganHangNhan AS bank,
                maGDNganHang AS bankReference, donViVanChuyen AS carrier,
                maChungTuCOD AS codDocument, maVanDonDoiSoat AS trackingNumber,
                dongPhanBoCOD AS allocationLine
         FROM tblGiaoDichThanhToan WHERE maDH=:id AND conHieuLuc=1
         ORDER BY thucNhanLuc, maGDTT'
    );
    $payments->execute(['id' => $orderId]);
    $paymentRows = $payments->fetchAll();
    $eligible = 0;
    foreach ($paymentRows as $payment) {
        if ($payment['reconciliationStatus'] === 'DU_DIEU_KIEN') {
            $eligible += arsenal_money_cents($payment['amount']);
        }
    }

    $refunds = $db->prepare(
        'SELECT yc.maYCHT AS id, yc.nguon AS source, yc.lyDo AS reason,
                yc.soTienDeNghi AS requestedAmount, yc.tienHangCanHoan AS goodsAmount,
                yc.phiVanChuyenCanHoan AS shippingAmount,
                yc.tienChuaPhanBoCanHoan AS unallocatedAmount,
                yc.soTienCanHoan AS approvedAmount, yc.trangThai AS status,
                yc.taoLuc AS createdAt,
                COALESCE(SUM(ht.tongDaHoan), 0) AS refundedAmount
         FROM tblYeuCauHoanTien yc
         LEFT JOIN tblLanHoanTien ht ON ht.maYCHT=yc.maYCHT
         WHERE yc.maDH=:id
         GROUP BY yc.maYCHT, yc.nguon, yc.lyDo, yc.soTienDeNghi,
                  yc.tienHangCanHoan, yc.phiVanChuyenCanHoan,
                  yc.tienChuaPhanBoCanHoan, yc.trangThai, yc.taoLuc
         ORDER BY yc.taoLuc DESC, yc.maYCHT DESC'
    );
    $refunds->execute(['id' => $orderId]);
    $refundRows = $refunds->fetchAll();

    return [
        'payments' => $paymentRows,
        'refundRequests' => $refundRows,
        'eligibleReceived' => arsenal_format_money(min($eligible, arsenal_money_cents($order['tongPhaiTra']))),
        'unallocatedReceived' => arsenal_format_money(max(0, $eligible - arsenal_money_cents($order['tongPhaiTra']))),
        'paymentStatus' => $eligible <= 0
            ? 'CHUA_THANH_TOAN'
            : ($eligible < arsenal_money_cents($order['tongPhaiTra']) ? 'THANH_TOAN_MOT_PHAN' : 'DA_THANH_TOAN'),
    ];
}

function arsenal_admin_money_payload($value, string $label): int
{
    try {
        return arsenal_money_cents($value);
    } catch (InvalidArgumentException $error) {
        throw new InvalidArgumentException($label . ' không hợp lệ.');
    }
}

function arsenal_admin_utc_datetime(string $value, string $label): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?Z$/', $value)) {
        throw new InvalidArgumentException($label . ' phải là thời điểm UTC hợp lệ, không ở tương lai.');
    }
    $format = strlen($value) === 20 ? '!Y-m-d\TH:i:s\Z' : '!Y-m-d\TH:i\Z';
    $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
        || $date->getTimestamp() > time()) {
        throw new InvalidArgumentException($label . ' phải là thời điểm UTC hợp lệ, không ở tương lai.');
    }

    return $date->format('Y-m-d H:i:s');
}

function arsenal_admin_coupon_datetime(string $value, string $label): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?Z$/', $value)) {
        throw new InvalidArgumentException($label . ' phải là thời điểm UTC hợp lệ.');
    }
    $format = strlen($value) === 20 ? '!Y-m-d\TH:i:s\Z' : '!Y-m-d\TH:i\Z';
    $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
        throw new InvalidArgumentException($label . ' phải là thời điểm UTC hợp lệ.');
    }

    return $date->format('Y-m-d H:i:s');
}

function arsenal_admin_valid_date_range(string $from, string $to): bool
{
    foreach ([$from, $to] as $value) {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $date->format('Y-m-d') !== $value) {
            return false;
        }
    }

    return $from <= $to && (strtotime($to . ' UTC') - strtotime($from . ' UTC')) <= 366 * 86400;
}

function arsenal_admin_create_refund_request(PDO $db, int $adminId, string $orderId, string $source, string $reason, int $amountCents): ?int
{
    if ($amountCents <= 0) {
        return null;
    }
    $statement = $db->prepare(
        "INSERT INTO tblYeuCauHoanTien
         (nguon, lyDo, taoLuc, soTienDeNghi, tienChuaPhanBoCanHoan, trangThai, maDH, maQTVXuLy)
         VALUES (:source, :reason, UTC_TIMESTAMP(3), :requested, :unallocated, 'CHO_DUYET', :orderId, :admin)"
    );
    $amount = arsenal_format_money($amountCents);
    $statement->execute([
        'source' => $source,
        'reason' => $reason,
        'requested' => $amount,
        'unallocated' => $amount,
        'orderId' => $orderId,
        'admin' => $adminId,
    ]);
    $requestId = (int)$db->lastInsertId();
    arsenal_admin_log_payment_event(
        $db,
        'TAO_YEU_CAU',
        $reason,
        $orderId,
        $adminId,
        null,
        $requestId,
        null,
        null,
        'CHO_DUYET',
        $amount
    );
    $currentStatus = (string)arsenal_admin_order($db, $orderId)['trangThaiThanhToan'];
    if ($currentStatus !== 'DANG_HOAN_TIEN') {
        $db->prepare(
            "UPDATE tblDonHang SET trangThaiThanhToan='DANG_HOAN_TIEN' WHERE maDH=:id"
        )->execute(['id' => $orderId]);
        arsenal_payment_log($db, 'DOI_TRANG_THAI', 'QUAN_TRI', $orderId,
            'Có yêu cầu hoàn tiền đang chờ xử lý.', $currentStatus, 'DANG_HOAN_TIEN',
            null, $adminId, $amount, 'admin:' . $adminId);
    }

    return $requestId;
}

function arsenal_admin_refund_totals(PDO $db, string $orderId): array
{
    $statement = $db->prepare(
        'SELECT COALESCE(SUM(ht.tongDaHoan),0)
         FROM tblLanHoanTien ht
         INNER JOIN tblYeuCauHoanTien yc ON yc.maYCHT=ht.maYCHT WHERE yc.maDH=:id'
    );
    $statement->execute(['id' => $orderId]);
    $refunded = arsenal_money_cents($statement->fetchColumn());
    $pending = $db->prepare(
        "SELECT COALESCE(SUM(
                    CASE WHEN yc.trangThai='CHO_DUYET' THEN yc.soTienDeNghi
                         WHEN yc.trangThai IN ('CHO_HOAN','DANG_HOAN','DA_HOAN_XONG') THEN yc.soTienCanHoan
                         ELSE 0 END
                    - COALESCE(ht.refunded,0)
                ),0)
         FROM tblYeuCauHoanTien yc
         LEFT JOIN (
             SELECT maYCHT, SUM(tongDaHoan) AS refunded
             FROM tblLanHoanTien GROUP BY maYCHT
         ) ht ON ht.maYCHT=yc.maYCHT
         WHERE yc.maDH=:id AND yc.trangThai<>'TU_CHOI'"
    );
    $pending->execute(['id' => $orderId]);

    return ['refunded' => $refunded, 'pending' => arsenal_money_cents($pending->fetchColumn())];
}

function arsenal_admin_save_reference(PDO $db, string $kind, array $payload): void
{
    if ($kind === 'category') {
        $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string)($payload['name'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $active = !empty($payload['active']) ? 1 : 0;
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120 || mb_strlen($description, 'UTF-8') > 5000) {
            throw new InvalidArgumentException('Tên danh mục hoặc mô tả không hợp lệ.');
        }
        if ($id === false || $id === null) {
            $db->prepare('INSERT INTO tblDanhMuc (tenDanhMuc, moTa, hoatDong) VALUES (:name, :description, :active)')
                ->execute(['name' => $name, 'description' => $description ?: null, 'active' => $active]);
        } else {
            $db->prepare('UPDATE tblDanhMuc SET tenDanhMuc=:name, moTa=:description, hoatDong=:active WHERE maDM=:id')
                ->execute(['name' => $name, 'description' => $description ?: null, 'active' => $active, 'id' => $id]);
        }
        return;
    }

    if ($kind === 'grade') {
        $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        $code = strtoupper(trim((string)($payload['code'] ?? '')));
        $name = trim((string)($payload['name'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $active = !empty($payload['active']) ? 1 : 0;
        if (!preg_match('/^[A-Z0-9-]{1,12}$/', $code) || $name === '' || mb_strlen($name, 'UTF-8') > 100) {
            throw new InvalidArgumentException('Ký hiệu hoặc tên phân hạng không hợp lệ.');
        }
        $params = ['code' => $code, 'name' => $name, 'description' => $description ?: null, 'active' => $active];
        if ($id === false || $id === null) {
            $db->prepare('INSERT INTO tblPhanHangGunpla (kyHieu, tenPhanHang, moTa, hoatDong) VALUES (:code,:name,:description,:active)')
                ->execute($params);
        } else {
            $db->prepare('UPDATE tblPhanHangGunpla SET kyHieu=:code,tenPhanHang=:name,moTa=:description,hoatDong=:active WHERE maPhanHang=:id')
                ->execute($params + ['id' => $id]);
        }
        return;
    }

    if ($kind === 'series') {
        $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string)($payload['name'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $image = trim((string)($payload['image'] ?? ''));
        $active = !empty($payload['active']) ? 1 : 0;
        if ($name === '' || mb_strlen($name, 'UTF-8') > 150 || ($image !== '' && !arsenal_admin_valid_image_url($image))) {
            throw new InvalidArgumentException('Tên dòng Mecha hoặc URL ảnh không hợp lệ.');
        }
        $params = ['name' => $name, 'description' => $description ?: null, 'image' => $image ?: null, 'active' => $active];
        if ($id === false || $id === null) {
            $db->prepare('INSERT INTO tblDongMecha (tenDong, gioiThieu, anhDaiDienUrl, hoatDong) VALUES (:name,:description,:image,:active)')
                ->execute($params);
        } else {
            $db->prepare('UPDATE tblDongMecha SET tenDong=:name,gioiThieu=:description,anhDaiDienUrl=:image,hoatDong=:active WHERE maDong=:id')
                ->execute($params + ['id' => $id]);
        }
        return;
    }

    throw new InvalidArgumentException('Loại danh mục không hợp lệ.');
}

function arsenal_admin_save_coupon(PDO $db, array $payload): void
{
    $code = strtoupper(trim((string)($payload['code'] ?? '')));
    $type = strtoupper(trim((string)($payload['type'] ?? '')));
    $value = trim((string)($payload['value'] ?? ''));
    $minimumOrder = arsenal_admin_money_payload($payload['minimumOrder'] ?? 0, 'Giá trị đơn tối thiểu');
    $maximumDiscountRaw = $payload['maximumDiscount'] ?? '';
    $maximumDiscount = $maximumDiscountRaw === '' || $maximumDiscountRaw === null
        ? null
        : arsenal_admin_money_payload($maximumDiscountRaw, 'Mức giảm tối đa');
    $maximumUses = filter_var($payload['maximumUses'] ?? null, FILTER_VALIDATE_INT);
    $perPhoneLimit = filter_var($payload['perPhoneLimit'] ?? null, FILTER_VALIDATE_INT);
    $startsAt = arsenal_admin_coupon_datetime((string)($payload['startsAt'] ?? ''), 'Thời gian bắt đầu');
    $endsAt = arsenal_admin_coupon_datetime((string)($payload['endsAt'] ?? ''), 'Thời gian kết thúc');
    $active = !empty($payload['active']) ? 1 : 0;
    if (!preg_match('/^[A-Z0-9_-]{1,32}$/', $code)
        || !in_array($type, ['PHAN_TRAM', 'SO_TIEN'], true)
        || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $value)
        || (float)$value <= 0 || ($type === 'PHAN_TRAM' && (float)$value > 100)
        || $minimumOrder < 0 || ($maximumDiscount !== null && $maximumDiscount <= 0)
        || $maximumUses === false || $maximumUses < 1
        || $perPhoneLimit === false || $perPhoneLimit < 1
        || strtotime($endsAt . ' UTC') <= strtotime($startsAt . ' UTC')) {
        throw new InvalidArgumentException('Thông tin mã giảm giá không hợp lệ.');
    }

    $db->beginTransaction();
    try {
        $existing = $db->prepare(
            'SELECT luotDangGiu, luotDaSuDung FROM tblMaGiamGia WHERE maCode=:code FOR UPDATE'
        );
        $existing->execute(['code' => $code]);
        $current = $existing->fetch();
        if ($current && $maximumUses < (int)$current['luotDangGiu'] + (int)$current['luotDaSuDung']) {
            throw new InvalidArgumentException('Giới hạn lượt phải lớn hơn hoặc bằng lượt đang giữ cộng lượt đã dùng.');
        }
        $statement = $current
            ? $db->prepare(
                'UPDATE tblMaGiamGia SET loaiGiam=:type, giaTriGiam=:value,
                        tienHangToiThieu=:minimum, mucGiamToiDa=:maximum,
                        luotToiDa=:uses, gioiHanTheoDienThoai=:phoneLimit,
                        batDauLuc=:starts, ketThucLuc=:ends, hoatDong=:active
                 WHERE maCode=:code'
            )
            : $db->prepare(
                'INSERT INTO tblMaGiamGia
                 (maCode, loaiGiam, giaTriGiam, tienHangToiThieu, mucGiamToiDa,
                  luotToiDa, luotDangGiu, luotDaSuDung, gioiHanTheoDienThoai,
                  batDauLuc, ketThucLuc, hoatDong)
                 VALUES (:code, :type, :value, :minimum, :maximum, :uses, 0, 0,
                         :phoneLimit, :starts, :ends, :active)'
            );
        $statement->execute([
            'code' => $code,
            'type' => $type,
            'value' => number_format((float)$value, 2, '.', ''),
            'minimum' => arsenal_format_money($minimumOrder),
            'maximum' => $maximumDiscount === null ? null : arsenal_format_money($maximumDiscount),
            'uses' => $maximumUses,
            'phoneLimit' => $perPhoneLimit,
            'starts' => $startsAt,
            'ends' => $endsAt,
            'active' => $active,
        ]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

function arsenal_admin_save_ai_rule(PDO $db, int $adminId, array $payload): void
{
    $id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT);
    $key = trim((string)($payload['key'] ?? ''));
    $type = strtoupper(trim((string)($payload['type'] ?? '')));
    $name = trim((string)($payload['name'] ?? ''));
    $level = strtoupper(trim((string)($payload['level'] ?? '')));
    $budgetFromRaw = $payload['budgetFrom'] ?? '';
    $budgetToRaw = $payload['budgetTo'] ?? '';
    $complexityRaw = $payload['maxComplexity'] ?? '';
    $ruleContent = trim((string)($payload['ruleContent'] ?? ''));
    $faqQuestion = trim((string)($payload['faqQuestion'] ?? ''));
    $faqAnswer = trim((string)($payload['faqAnswer'] ?? ''));
    $priority = filter_var($payload['priority'] ?? 0, FILTER_VALIDATE_INT);
    $seriesRaw = $payload['seriesId'] ?? '';
    $seriesId = $seriesRaw === '' || $seriesRaw === null ? null : filter_var($seriesRaw, FILTER_VALIDATE_INT);
    $complexity = $complexityRaw === '' || $complexityRaw === null
        ? null
        : filter_var($complexityRaw, FILTER_VALIDATE_INT);
    if (($id !== false && $id !== null && $id < 1) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key)
        || !in_array($type, ['GOI_Y', 'FAQ', 'CONG_TAC'], true)
        || $name === '' || mb_strlen($name, 'UTF-8') > 150
        || ($level !== '' && !in_array($level, ['NGUOI_MOI', 'TRUNG_CAP', 'NANG_CAO'], true))
        || ($type !== 'GOI_Y' && $level !== '')
        || ($type === 'FAQ' && ($faqQuestion === '' || $faqAnswer === ''))
        || ($complexity !== null && ($complexity === false || $complexity < 1 || $complexity > 5))
        || ($priority === false || $priority < 0)
        || ($seriesId === false)
        || ($ruleContent !== '' && mb_strlen($ruleContent, 'UTF-8') > 20000)
        || ($faqQuestion !== '' && mb_strlen($faqQuestion, 'UTF-8') > 500)
        || ($faqAnswer !== '' && mb_strlen($faqAnswer, 'UTF-8') > 20000)) {
        throw new InvalidArgumentException('Thông tin quy tắc tư vấn không hợp lệ.');
    }
    $budgetFrom = $budgetFromRaw === '' || $budgetFromRaw === null
        ? null
        : arsenal_admin_money_payload($budgetFromRaw, 'Ngân sách tối thiểu');
    $budgetTo = $budgetToRaw === '' || $budgetToRaw === null
        ? null
        : arsenal_admin_money_payload($budgetToRaw, 'Ngân sách tối đa');
    if (($budgetFrom !== null && $budgetFrom < 0)
        || ($budgetTo !== null && $budgetTo < 0)
        || ($budgetFrom !== null && $budgetTo !== null && $budgetTo < $budgetFrom)) {
        throw new InvalidArgumentException('Khoảng ngân sách không hợp lệ.');
    }
    $params = [
        'key' => $key,
        'type' => $type,
        'name' => $name,
        'level' => $level === '' ? null : $level,
        'budgetFrom' => $budgetFrom === null ? null : arsenal_format_money($budgetFrom),
        'budgetTo' => $budgetTo === null ? null : arsenal_format_money($budgetTo),
        'complexity' => $complexity,
        'ruleContent' => $ruleContent === '' ? null : $ruleContent,
        'faqQuestion' => $faqQuestion === '' ? null : $faqQuestion,
        'faqAnswer' => $faqAnswer === '' ? null : $faqAnswer,
        'active' => !empty($payload['active']) ? 1 : 0,
        'priority' => $priority,
        'seriesId' => $seriesId,
        'admin' => $adminId,
    ];
    if ($id === false || $id === null) {
        $db->prepare(
            'INSERT INTO tblQuyTacGoiYAI
             (khoaCauHinh, loaiCauHinh, tenMuc, trinhDo, nganSachTu, nganSachDen,
              doPhucTapToiDa, noiDungQuyTac, cauHoiFAQ, cauTraLoiFAQ,
              kichHoat, doUuTien, capNhatLuc, maDong, maQTVCapNhat)
             VALUES (:key, :type, :name, :level, :budgetFrom, :budgetTo,
                     :complexity, :ruleContent, :faqQuestion, :faqAnswer,
                     :active, :priority, UTC_TIMESTAMP(3), :seriesId, :admin)'
        )->execute($params);
    } else {
        $db->prepare(
            'UPDATE tblQuyTacGoiYAI
             SET khoaCauHinh=:key, loaiCauHinh=:type, tenMuc=:name, trinhDo=:level,
                 nganSachTu=:budgetFrom, nganSachDen=:budgetTo,
                 doPhucTapToiDa=:complexity, noiDungQuyTac=:ruleContent,
                 cauHoiFAQ=:faqQuestion, cauTraLoiFAQ=:faqAnswer,
                 kichHoat=:active, doUuTien=:priority, capNhatLuc=UTC_TIMESTAMP(3),
                 maDong=:seriesId, maQTVCapNhat=:admin
             WHERE maQuyTacAI=:id'
        )->execute($params + ['id' => $id]);
    }
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $adminId = arsenal_admin_require_or_null();
        $data = ['csrfToken' => arsenal_csrf_token(), 'admin' => null];
        if ($adminId !== null) {
            $profile = arsenal_db()->prepare(
                'SELECT maQTV, tenDangNhap, hoTen, quyen FROM tblQuanTriVien WHERE maQTV = :id'
            );
            $profile->execute(['id' => $adminId]);
            $admin = $profile->fetch();
            if ($admin) {
                $data['admin'] = [
                    'id' => $admin['maQTV'],
                    'username' => $admin['tenDangNhap'],
                    'name' => $admin['hoTen'],
                    'permissions' => arsenal_admin_permissions(json_decode($admin['quyen'], true) ?: []),
                ];
                $view = (string)($_GET['view'] ?? '');
                if ($view === 'catalog') {
                    arsenal_require_admin(['SAN_PHAM']);
                    $data['categories'] = arsenal_db()->query(
                        'SELECT maDM AS id, tenDanhMuc AS name, moTa AS description, hoatDong AS active
                         FROM tblDanhMuc ORDER BY tenDanhMuc'
                    )->fetchAll();
                    $data['grades'] = arsenal_db()->query(
                        'SELECT maPhanHang AS id, kyHieu AS code, tenPhanHang AS name, moTa AS description, hoatDong AS active
                         FROM tblPhanHangGunpla ORDER BY kyHieu'
                    )->fetchAll();
                    $data['series'] = arsenal_db()->query(
                        'SELECT maDong AS id, tenDong AS name, gioiThieu AS description,
                                anhDaiDienUrl AS image, hoatDong AS active
                         FROM tblDongMecha ORDER BY tenDong'
                    )->fetchAll();
                    $data['products'] = arsenal_db()->query(
                        'SELECT sp.maSP AS id, sp.tenSP AS name, sp.moTa AS description,
                                sp.anhDaiDienUrl AS image, sp.anhBoSung AS gallery,
                                sp.thongSoKyThuat AS specifications, sp.tyLe AS scale,
                                sp.doPhucTapLapRap AS complexity, sp.giaBan AS price,
                                sp.tonKhaDung AS stock, sp.trangThai AS status,
                                sp.giaThamKhao AS referencePrice, sp.nhanDatTruoc AS preorder,
                                sp.uuTienGoiY AS featured, sp.maDM AS categoryId,
                                sp.maPhanHang AS gradeId, sp.maDong AS seriesId
                         FROM tblSanPham sp ORDER BY sp.themLuc DESC, sp.maSP'
                    )->fetchAll();
                } elseif ($view === 'references') {
                    arsenal_require_admin(['DANH_MUC']);
                    $data['categories'] = arsenal_db()->query(
                        'SELECT maDM AS id, tenDanhMuc AS name, moTa AS description, hoatDong AS active
                         FROM tblDanhMuc ORDER BY tenDanhMuc'
                    )->fetchAll();
                    $data['grades'] = arsenal_db()->query(
                        'SELECT maPhanHang AS id, kyHieu AS code, tenPhanHang AS name, moTa AS description, hoatDong AS active
                         FROM tblPhanHangGunpla ORDER BY kyHieu'
                    )->fetchAll();
                    $data['series'] = arsenal_db()->query(
                        'SELECT maDong AS id, tenDong AS name, gioiThieu AS description,
                                anhDaiDienUrl AS image, hoatDong AS active
                         FROM tblDongMecha ORDER BY tenDong'
                    )->fetchAll();
                } elseif ($view === 'customers') {
                    arsenal_require_admin(['KHACH_HANG']);
                    $data['customers'] = arsenal_db()->query(
                        'SELECT kh.maKH AS id, kh.hoTen AS name, kh.dienThoai AS phone,
                                kh.email, kh.diaChi AS address, kh.trangThai AS status,
                                kh.dangKyLuc AS registeredAt,
                                COUNT(dh.maDH) AS orderCount
                         FROM tblKhachHang kh
                         LEFT JOIN tblDonHang dh ON dh.maKH = kh.maKH
                         GROUP BY kh.maKH, kh.hoTen, kh.dienThoai, kh.email, kh.diaChi,
                                  kh.trangThai, kh.dangKyLuc
                         ORDER BY kh.dangKyLuc DESC'
                    )->fetchAll();
                } elseif ($view === 'orders') {
                    arsenal_require_admin(['DON_HANG']);
                    $status = strtoupper(trim((string)($_GET['status'] ?? '')));
                    $statuses = ['CHO_XAC_NHAN', 'DA_XAC_NHAN', 'DANG_GIAO', 'DA_GIAO', 'DA_HUY'];
                    if ($status !== '' && !in_array($status, $statuses, true)) {
                        throw new InvalidArgumentException('Bộ lọc trạng thái đơn không hợp lệ.');
                    }
                    $query = 'SELECT dh.maDH AS id, dh.datLuc AS placedAt, dh.hoTenNhan AS customerName,
                                     dh.dienThoaiNhan AS phone, dh.phuongThucThanhToan AS paymentMethod,
                                     dh.tongPhaiTra AS total, dh.trangThaiXuLy AS status,
                                     COUNT(ct.maCTDH) AS itemCount
                              FROM tblDonHang dh
                              LEFT JOIN tblChiTietDonHang ct ON ct.maDH=dh.maDH';
                    if ($status !== '') {
                        $query .= ' WHERE dh.trangThaiXuLy=:status';
                    }
                    $query .= ' GROUP BY dh.maDH, dh.datLuc, dh.hoTenNhan, dh.dienThoaiNhan,
                                        dh.phuongThucThanhToan, dh.tongPhaiTra, dh.trangThaiXuLy
                                ORDER BY dh.datLuc DESC LIMIT 200';
                    $statement = arsenal_db()->prepare($query);
                    $statement->execute($status === '' ? [] : ['status' => $status]);
                    $data['orders'] = $statement->fetchAll();
                } elseif ($view === 'order') {
                    arsenal_require_admin(['DON_HANG']);
                    $data['order'] = arsenal_admin_order_detail(arsenal_db(), (string)($_GET['id'] ?? ''));
                } elseif ($view === 'inventory') {
                    arsenal_require_admin(['KHO']);
                    $data['products'] = arsenal_db()->query(
                        "SELECT sp.maSP AS id, sp.tenSP AS name, sp.tonKhaDung AS availableStock,
                                COALESCE(giu.soLuong, 0) AS heldByConfirmedOrders
                         FROM tblSanPham sp
                         LEFT JOIN (
                             SELECT ct.maSP, SUM(ct.soLuong) AS soLuong
                             FROM tblChiTietDonHang ct
                             INNER JOIN tblDonHang dh ON dh.maDH=ct.maDH
                             WHERE dh.trangThaiXuLy IN ('DA_XAC_NHAN','DANG_GIAO')
                             GROUP BY ct.maSP
                         ) giu ON giu.maSP=sp.maSP
                         ORDER BY sp.tenSP, sp.maSP"
                    )->fetchAll();
                    $productId = trim((string)($_GET['productId'] ?? ''));
                    if ($productId !== '' && !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $productId)) {
                        throw new InvalidArgumentException('Mã sản phẩm lọc lịch sử không hợp lệ.');
                    }
                    $historyQuery = 'SELECT ls.maLSTK AS id, ls.loaiBienDong AS type,
                                            ls.soLuongThayDoi AS quantityChange,
                                            ls.soLuongTruoc AS beforeQuantity, ls.soLuongSau AS afterQuantity,
                                            ls.thucHienLuc AS performedAt, ls.lyDo AS reason,
                                            ls.maSP AS productId, sp.tenSP AS productName,
                                            ls.maDH AS orderId, qtv.hoTen AS adminName
                                     FROM tblLichSuTonKho ls
                                     INNER JOIN tblSanPham sp ON sp.maSP=ls.maSP
                                     INNER JOIN tblQuanTriVien qtv ON qtv.maQTV=ls.maQTVThucHien';
                    if ($productId !== '') {
                        $historyQuery .= ' WHERE ls.maSP=:productId';
                    }
                    $historyQuery .= ' ORDER BY ls.thucHienLuc DESC, ls.maLSTK DESC LIMIT 300';
                    $history = arsenal_db()->prepare($historyQuery);
                    $history->execute($productId === '' ? [] : ['productId' => $productId]);
                    $data['history'] = $history->fetchAll();
                } elseif ($view === 'preorders') {
                    arsenal_require_admin(['DAT_TRUOC']);
                    $data['preorders'] = arsenal_db()->query(
                        'SELECT y.maYCDT AS id, y.hoTenNguoiDat AS customerName,
                                y.dienThoaiLienHe AS phone, y.emailLienHe AS email,
                                y.soLuong AS quantity, y.guiLuc AS submittedAt,
                                y.trangThai AS status, y.soLanLienHe AS contactCount,
                                y.lienHeDauLuc AS firstContactAt, y.lienHeGanNhatLuc AS lastContactAt,
                                y.hanPhanHoi AS responseDeadline, y.ghiChu AS note,
                                y.chuyenDonLuc AS convertedAt, y.maDHHinhThanh AS orderId,
                                sp.maSP AS productId, sp.tenSP AS productName,
                                sp.trangThai AS productStatus, sp.tonKhaDung AS availableStock,
                                sp.giaBan AS currentPrice, sp.giaThamKhao AS referencePrice
                         FROM tblYeuCauDatTruoc y
                         INNER JOIN tblSanPham sp ON sp.maSP=y.maSP
                         ORDER BY y.guiLuc DESC LIMIT 300'
                    )->fetchAll();
                } elseif ($view === 'coupons') {
                    arsenal_require_admin(['KHUYEN_MAI']);
                    $data['coupons'] = arsenal_db()->query(
                        'SELECT maCode AS code, loaiGiam AS type, giaTriGiam AS value,
                                tienHangToiThieu AS minimumOrder, mucGiamToiDa AS maximumDiscount,
                                luotToiDa AS maximumUses, luotDangGiu AS heldUses,
                                luotDaSuDung AS usedUses, gioiHanTheoDienThoai AS perPhoneLimit,
                                batDauLuc AS startsAt, ketThucLuc AS endsAt, hoatDong AS active
                         FROM tblMaGiamGia ORDER BY batDauLuc DESC'
                    )->fetchAll();
                } elseif ($view === 'reviews') {
                    arsenal_require_admin(['DANH_GIA']);
                    $data['reviews'] = arsenal_db()->query(
                        'SELECT dg.maDanhGia AS id, dg.tenNguoiDanhGia AS customerName,
                                dg.diemSao AS rating, dg.noiDung AS comment, dg.guiLuc AS submittedAt,
                                dg.trangThai AS status, dg.lyDoKiemDuyet AS moderationReason,
                                dg.maSP AS productId, sp.tenSP AS productName, dg.maDH AS orderId,
                                dh.dienThoaiNhan AS phone
                         FROM tblDanhGia dg
                         INNER JOIN tblSanPham sp ON sp.maSP=dg.maSP
                         INNER JOIN tblDonHang dh ON dh.maDH=dg.maDH
                         ORDER BY FIELD(dg.trangThai, \'CHO_DUYET\', \'DA_DUYET\', \'TU_CHOI\'),
                                  dg.guiLuc DESC LIMIT 500'
                    )->fetchAll();
                } elseif ($view === 'payments') {
                    arsenal_require_admin(['THANH_TOAN']);
                    $data['payments'] = arsenal_db()->query(
                        "SELECT dh.maDH AS orderId, dh.datLuc AS placedAt, dh.hoTenNhan AS customerName,
                                dh.dienThoaiNhan AS phone, dh.phuongThucThanhToan AS method,
                                dh.tongPhaiTra AS amountDue, dh.hanChuyenKhoan AS transferDeadline,
                                dh.trangThaiXuLy AS orderStatus, dh.trangThaiThanhToan AS paymentStatus,
                                COALESCE(SUM(gd.soTienThucNhan), 0) AS totalReceived,
                                COALESCE(SUM(CASE WHEN gd.ketQuaDoiChieu='DU_DIEU_KIEN'
                                                  THEN gd.soTienThucNhan ELSE 0 END), 0) AS eligibleReceived
                                 FROM tblDonHang dh
                                 LEFT JOIN tblGiaoDichThanhToan gd ON gd.maDH=dh.maDH AND gd.conHieuLuc=1
                         GROUP BY dh.maDH, dh.datLuc, dh.hoTenNhan, dh.dienThoaiNhan,
                                  dh.phuongThucThanhToan, dh.tongPhaiTra, dh.hanChuyenKhoan,
                                  dh.trangThaiXuLy, dh.trangThaiThanhToan
                         ORDER BY dh.datLuc DESC LIMIT 500"
                    )->fetchAll();
                    $data['paymentRecords'] = arsenal_db()->query(
                        'SELECT maGDTT AS id, maDH AS orderId, soTienThucNhan AS amount,
                                phuongThuc AS method, maGDNganHang AS reference,
                                maDoiSoat AS reconciliationId, ketQuaDoiChieu AS reconciliationStatus,
                                ketQuaPhanBo AS allocationStatus, conHieuLuc AS active
                         FROM tblGiaoDichThanhToan
                         ORDER BY ghiNhanLuc DESC, maGDTT DESC LIMIT 1500'
                    )->fetchAll();
                    $data['refundRequests'] = arsenal_db()->query(
                        "SELECT yc.maYCHT AS id, yc.maDH AS orderId, yc.nguon AS source,
                                yc.lyDo AS reason, yc.soTienDeNghi AS requestedAmount,
                                yc.soTienCanHoan AS approvedAmount, yc.trangThai AS status,
                                yc.taoLuc AS createdAt, dh.phuongThucThanhToan AS paymentMethod,
                                COALESCE(SUM(ht.tongDaHoan),0) AS refundedAmount
                         FROM tblYeuCauHoanTien yc
                         INNER JOIN tblDonHang dh ON dh.maDH=yc.maDH
                         LEFT JOIN tblLanHoanTien ht ON ht.maYCHT=yc.maYCHT
                         GROUP BY yc.maYCHT, yc.maDH, yc.nguon, yc.lyDo, yc.soTienDeNghi,
                                  yc.soTienCanHoan, yc.trangThai, yc.taoLuc, dh.phuongThucThanhToan
                         ORDER BY yc.taoLuc DESC LIMIT 300"
                    )->fetchAll();
                    $data['paymentLogs'] = arsenal_db()->query(
                        'SELECT nk.maNKTT AS id, nk.ghiLuc AS recordedAt, nk.loaiSuKien AS eventType,
                                nk.trangThaiTruoc AS previousStatus, nk.trangThaiSau AS currentStatus,
                                nk.lyDo AS reason, nk.canCu AS evidence, nk.maDH AS orderId,
                                qtv.hoTen AS adminName
                         FROM tblNhatKyThanhToan nk
                         LEFT JOIN tblQuanTriVien qtv ON qtv.maQTV=nk.maQTV
                         ORDER BY nk.ghiLuc DESC, nk.maNKTT DESC LIMIT 500'
                    )->fetchAll();
                } elseif ($view === 'ai-rules') {
                    arsenal_require_admin(['GOI_Y_AI']);
                    $data['aiRules'] = arsenal_db()->query(
                        'SELECT maQuyTacAI AS id, khoaCauHinh AS `key`, loaiCauHinh AS type,
                                tenMuc AS name, trinhDo AS level, nganSachTu AS budgetFrom,
                                nganSachDen AS budgetTo, doPhucTapToiDa AS maxComplexity,
                                noiDungQuyTac AS ruleContent, cauHoiFAQ AS faqQuestion,
                                cauTraLoiFAQ AS faqAnswer, kichHoat AS active,
                                doUuTien AS priority, maDong AS seriesId
                         FROM tblQuyTacGoiYAI ORDER BY doUuTien DESC, tenMuc'
                    )->fetchAll();
                    $data['series'] = arsenal_db()->query(
                        'SELECT maDong AS id, tenDong AS name FROM tblDongMecha ORDER BY tenDong'
                    )->fetchAll();
                    $data['aiHistory'] = arsenal_db()->query(
                        'SELECT ls.maTuVan AS id, ls.taoLuc AS createdAt, ls.cauHoiDaLoc AS question,
                                ls.phanHoiDaLoc AS response, ls.sanPhamGoiYSnapshot AS products,
                                ls.nguonPhanHoi AS source, ls.huuIch AS helpful,
                                qt.tenMuc AS ruleName
                         FROM tblLichSuTuVanAI ls
                         LEFT JOIN tblQuyTacGoiYAI qt ON qt.maQuyTacAI=ls.maQuyTacAI
                         ORDER BY ls.taoLuc DESC LIMIT 200'
                    )->fetchAll();
                } elseif ($view === 'analytics') {
                    arsenal_require_admin(['BAO_CAO']);
                    $from = (string)($_GET['from'] ?? gmdate('Y-m-01'));
                    $to = (string)($_GET['to'] ?? gmdate('Y-m-d'));
                    if (!arsenal_admin_valid_date_range($from, $to)) {
                        throw new InvalidArgumentException('Khoảng báo cáo không hợp lệ (tối đa 367 ngày).');
                    }
                    $data['analytics'] = arsenal_admin_analytics(arsenal_db(), $from, $to);
                } elseif ($view === 'inventory-csv') {
                    arsenal_require_admin(['BAO_CAO']);
                    arsenal_admin_inventory_csv(arsenal_db(), (string)($_GET['productId'] ?? ''));
                } elseif ($view === 'revenue-csv') {
                    arsenal_require_admin(['BAO_CAO']);
                    $from = (string)($_GET['from'] ?? gmdate('Y-m-01'));
                    $to = (string)($_GET['to'] ?? gmdate('Y-m-d'));
                    if (!arsenal_admin_valid_date_range($from, $to)) {
                        throw new InvalidArgumentException('Khoảng báo cáo không hợp lệ (tối đa 367 ngày).');
                    }
                    arsenal_admin_revenue_csv(arsenal_db(), $from, $to);
                }
            }
        }
        arsenal_json_response($data);
    }

    arsenal_request_method('POST');
    $payload = arsenal_read_json();
    arsenal_require_csrf($payload);
    $action = (string)($payload['action'] ?? '');
    $db = arsenal_db();

    if ($action === 'login') {
        $username = trim((string)($payload['username'] ?? ''));
        $password = (string)($payload['password'] ?? '');
        $statement = $db->prepare(
            "SELECT maQTV, matKhauBam FROM tblQuanTriVien
             WHERE tenDangNhap = :username AND trangThai = 'HOAT_DONG'"
        );
        $statement->execute(['username' => $username]);
        $admin = $statement->fetch();
        if (!$admin || !password_verify($password, $admin['matKhauBam'])) {
            arsenal_json_response(['message' => 'Tên đăng nhập hoặc mật khẩu không chính xác.'], 401);
        }
        session_regenerate_id(true);
        unset($_SESSION['arsenal_customer_id']);
        $_SESSION['arsenal_role'] = 'admin';
        $_SESSION['arsenal_admin_id'] = (int)$admin['maQTV'];
        $name = $db->prepare('SELECT hoTen, tenDangNhap, quyen FROM tblQuanTriVien WHERE maQTV=:id');
        $name->execute(['id' => (int)$admin['maQTV']]);
        $profile = $name->fetch();
        arsenal_json_response([
            'success' => true,
            'admin' => [
                'id' => $admin['maQTV'],
                'username' => $profile['tenDangNhap'],
                'name' => $profile['hoTen'],
                'permissions' => arsenal_admin_permissions(json_decode($profile['quyen'], true) ?: []),
            ],
            'csrfToken' => arsenal_regenerate_csrf(),
        ]);
    }

    if ($action === 'logout') {
        if (($_SESSION['arsenal_role'] ?? null) === 'admin') {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], '', $params['secure'], $params['httponly']);
            }
            session_destroy();
            arsenal_json_response(['success' => true]);
        }
        arsenal_json_response(['message' => 'Phiên quản trị chưa đăng nhập.'], 401);
    }

    if ($action === 'confirm-order') {
        $adminId = arsenal_require_admin(['DON_HANG']);
        $orderId = trim((string)($payload['orderId'] ?? ''));
        arsenal_admin_confirm_order($db, $adminId, $orderId);
        arsenal_json_response(['success' => true, 'order' => arsenal_admin_order_detail($db, $orderId)]);
    }

    if ($action === 'dispatch-order') {
        arsenal_require_admin(['DON_HANG']);
        $orderId = trim((string)($payload['orderId'] ?? ''));
        $tracking = trim((string)($payload['trackingNumber'] ?? ''));
        $note = trim((string)($payload['note'] ?? ''));
        if ($tracking === '' || mb_strlen($tracking, 'UTF-8') > 64 || mb_strlen($note, 'UTF-8') > 500) {
            throw new InvalidArgumentException('Mã vận đơn là bắt buộc (tối đa 64 ký tự); ghi chú tối đa 500 ký tự.');
        }
        $db->beginTransaction();
        try {
            $order = arsenal_admin_order($db, $orderId, true);
            if ($order['trangThaiXuLy'] !== 'DA_XAC_NHAN') {
                throw new InvalidArgumentException('Chỉ có thể bàn giao đơn đã xác nhận cho đơn vị vận chuyển.');
            }
            $db->prepare(
                "UPDATE tblDonHang SET trangThaiXuLy='DANG_GIAO', maVanDon=:tracking,
                        ghiChuGiaoHang=:note
                 WHERE maDH=:id AND trangThaiXuLy='DA_XAC_NHAN'"
            )->execute([
                'tracking' => $tracking,
                'note' => $note === '' ? null : $note,
                'id' => $orderId,
            ]);
            if ((int)$db->query('SELECT ROW_COUNT()')->fetchColumn() !== 1) {
                throw new InvalidArgumentException('Trạng thái đơn đã thay đổi, hãy tải lại.');
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        arsenal_json_response(['success' => true, 'order' => arsenal_admin_order_detail($db, $orderId)]);
    }

    if ($action === 'deliver-order') {
        arsenal_require_admin(['DON_HANG']);
        $orderId = trim((string)($payload['orderId'] ?? ''));
        $note = trim((string)($payload['note'] ?? ''));
        if (mb_strlen($note, 'UTF-8') > 500) {
            throw new InvalidArgumentException('Ghi chú giao hàng tối đa 500 ký tự.');
        }
        $db->beginTransaction();
        try {
            $order = arsenal_admin_order($db, $orderId, true);
            if ($order['trangThaiXuLy'] !== 'DANG_GIAO') {
                throw new InvalidArgumentException('Chỉ có thể xác nhận giao thành công cho đơn đang giao.');
            }
            $db->prepare(
                "UPDATE tblDonHang SET trangThaiXuLy='DA_GIAO',
                        giaoThanhCongLuc=UTC_TIMESTAMP(3),
                        thoiDiemGiaoThanhCong=UTC_TIMESTAMP(3),
                        ghiChuGiaoHang=COALESCE(NULLIF(:note,''), ghiChuGiaoHang)
                 WHERE maDH=:id AND trangThaiXuLy='DANG_GIAO'"
            )->execute(['note' => $note, 'id' => $orderId]);
            if ((int)$db->query('SELECT ROW_COUNT()')->fetchColumn() !== 1) {
                throw new InvalidArgumentException('Trạng thái đơn đã thay đổi, hãy tải lại.');
            }
            arsenal_payment_sync_order($db, $orderId);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        arsenal_json_response(['success' => true, 'order' => arsenal_admin_order_detail($db, $orderId)]);
    }

    if ($action === 'cancel-order') {
        $adminId = arsenal_require_admin(['DON_HANG']);
        $orderId = trim((string)($payload['orderId'] ?? ''));
        $returns = $payload['returns'] ?? [];
        if (!is_array($returns)) {
            throw new InvalidArgumentException('Danh sách hàng nhận lại không hợp lệ.');
        }
        arsenal_admin_cancel_order($db, $adminId, $orderId, (string)($payload['reason'] ?? ''), $returns);
        arsenal_json_response(['success' => true, 'order' => arsenal_admin_order_detail($db, $orderId)]);
    }

    if ($action === 'record-payment') {
        $adminId = arsenal_require_admin(['THANH_TOAN']);
        $orderId = trim((string)($payload['orderId'] ?? ''));
        $amountCents = arsenal_admin_money_payload($payload['amount'] ?? null, 'Số tiền thực nhận');
        $method = strtoupper(trim((string)($payload['method'] ?? '')));
        $receivedAt = arsenal_admin_utc_datetime((string)($payload['receivedAt'] ?? ''), 'Thời điểm thực nhận');
        $evidence = trim((string)($payload['evidence'] ?? ''));
        if ($amountCents <= 0 || !in_array($method, ['COD', 'CHUYEN_KHOAN'], true)
            || $evidence === '' || mb_strlen($evidence, 'UTF-8') > 500) {
            throw new InvalidArgumentException('Khoản thu phải có số tiền dương, phương thức và căn cứ đối chiếu.');
        }

        $db->beginTransaction();
        try {
            $order = arsenal_admin_order($db, $orderId, true);
            if ($order['phuongThucThanhToan'] !== $method) {
                throw new InvalidArgumentException('Phương thức khoản thu không khớp với đơn hàng.');
            }
            if (strtotime($receivedAt . ' UTC') < strtotime($order['datLuc'] . ' UTC')) {
                throw new InvalidArgumentException('Thời điểm thực nhận không thể trước lúc tạo đơn.');
            }
            $bank = $bankAccount = $bankReference = $carrier = $codDocument = $tracking = $allocation = null;
            if ($method === 'CHUYEN_KHOAN') {
                $bank = trim((string)($payload['bank'] ?? ''));
                $bankAccount = trim((string)($payload['bankAccount'] ?? ''));
                $bankReference = trim((string)($payload['bankReference'] ?? ''));
                if ($bank === '' || mb_strlen($bank, 'UTF-8') > 24
                    || $bankAccount === '' || mb_strlen($bankAccount, 'UTF-8') > 34
                    || $bankReference === '' || mb_strlen($bankReference, 'UTF-8') > 80) {
                    throw new InvalidArgumentException('Ngân hàng nhận và mã giao dịch ngân hàng là bắt buộc.');
                }
                if ($order['tenNganHangNhan'] !== null && $order['tenNganHangNhan'] !== $bank) {
                    throw new InvalidArgumentException('Ngân hàng nhận không khớp thông tin đơn hàng.');
                }
                if ($order['soTaiKhoanNhan'] !== null && $order['soTaiKhoanNhan'] !== $bankAccount) {
                    throw new InvalidArgumentException('Tài khoản nhận không khớp thông tin đơn hàng.');
                }
                $duplicate = $db->prepare(
                    'SELECT maGDTT FROM tblGiaoDichThanhToan
                     WHERE maNganHangNhan=:bank AND maGDNganHang=:reference FOR UPDATE'
                );
                $duplicate->execute(['bank' => $bank, 'reference' => $bankReference]);
                if ($duplicate->fetchColumn()) {
                    throw new InvalidArgumentException('Mã giao dịch ngân hàng này đã được ghi nhận.');
                }
            } else {
                $carrier = trim((string)($payload['carrier'] ?? ''));
                $codDocument = trim((string)($payload['codDocument'] ?? ''));
                $tracking = trim((string)($payload['trackingNumber'] ?? ''));
                $allocation = trim((string)($payload['allocationLine'] ?? ''));
                if ($carrier === '' || mb_strlen($carrier, 'UTF-8') > 50
                    || $codDocument === '' || mb_strlen($codDocument, 'UTF-8') > 80
                    || (($tracking === '') === ($allocation === ''))
                    || mb_strlen($tracking, 'UTF-8') > 64 || mb_strlen($allocation, 'UTF-8') > 64) {
                    throw new InvalidArgumentException('Đối soát COD cần đơn vị vận chuyển, chứng từ và đúng một mã vận đơn hoặc dòng phân bổ.');
                }
                if (!in_array($order['trangThaiXuLy'], ['DA_GIAO', 'DA_HUY'], true)) {
                    throw new InvalidArgumentException('Chỉ đối soát COD cho đơn đã giao hoặc đơn hủy có khoản thu thực tế.');
                }
            }

            $evidenceKey = $method === 'CHUYEN_KHOAN'
                ? hash('sha256', 'BANK|' . mb_strtolower($bank, 'UTF-8') . '|' . mb_strtolower($bankAccount, 'UTF-8') . '|' . mb_strtolower($bankReference, 'UTF-8'))
                : hash('sha256', 'COD|' . mb_strtolower($carrier, 'UTF-8') . '|' . mb_strtolower($codDocument, 'UTF-8') . '|' . mb_strtolower($allocation !== '' ? $allocation : $tracking, 'UTF-8'));
            $evidenceLock = $db->prepare(
                'SELECT maGDTT FROM tblGiaoDichThanhToan WHERE khoaChungTuThu=:key FOR UPDATE'
            );
            $evidenceLock->execute(['key' => $evidenceKey]);
            if ($evidenceLock->fetchColumn()) {
                throw new InvalidArgumentException('Chứng từ thu hoặc dòng phân bổ này đã được dùng.');
            }

            $reconciliation = $order['trangThaiXuLy'] === 'DA_HUY'
                ? 'DON_DA_HUY'
                : ($method === 'CHUYEN_KHOAN'
                    && strtotime($receivedAt . ' UTC') > strtotime($order['hanChuyenKhoan'] . ' UTC')
                    ? 'SAU_HAN'
                    : 'DU_DIEU_KIEN');
            $db->prepare(
                'INSERT INTO tblGiaoDichThanhToan
                 (soTienThucNhan, phuongThuc, thucNhanLuc, ghiNhanLuc, canCuDoiChieu,
                  maNganHangNhan, soTaiKhoanNhan, maGDNganHang, donViVanChuyen,
                  maChungTuCOD, maVanDonDoiSoat, dongPhanBoCOD, khoaChungTuThu,
                  ketQuaDoiChieu, maDH, maQTVXacNhan)
                 VALUES (:amount, :method, :receivedAt, UTC_TIMESTAMP(3), :evidence,
                         :bank, :account, :bankReference, :carrier, :codDocument,
                         :tracking, :allocation, :evidenceKey, :result, :orderId, :admin)'
            )->execute([
                'amount' => arsenal_format_money($amountCents),
                'method' => $method,
                'receivedAt' => $receivedAt,
                'evidence' => $evidence,
                'bank' => $bank,
                'account' => $bankAccount,
                'bankReference' => $bankReference,
                'carrier' => $carrier,
                'codDocument' => $codDocument,
                'tracking' => $tracking === '' ? null : $tracking,
                'allocation' => $allocation === '' ? null : $allocation,
                'evidenceKey' => $evidenceKey,
                'result' => $reconciliation,
                'orderId' => $orderId,
                'admin' => $adminId,
            ]);
            $paymentId = (int)$db->lastInsertId();
            arsenal_payment_sync_order($db, $orderId);
            arsenal_admin_log_payment_event(
                $db,
                'GHI_THU',
                'Ghi nhận khoản thu đối soát ' . $method,
                $orderId,
                $adminId,
                $paymentId,
                null,
                null,
                null,
                $reconciliation,
                $evidence
            );

            $totals = arsenal_admin_order_payment_totals($db, $orderId);
            $refundTotals = arsenal_admin_refund_totals($db, $orderId);
            $due = arsenal_money_cents($order['tongPhaiTra']);
            $eligible = $method === 'CHUYEN_KHOAN' && $reconciliation === 'DU_DIEU_KIEN'
                ? min($due, $amountCents)
                : 0;
            $alreadyEligible = 0;
            $eligibleRows = $db->prepare(
                "SELECT soTienThucNhan FROM tblGiaoDichThanhToan
                 WHERE maDH=:id AND ketQuaDoiChieu='DU_DIEU_KIEN'"
            );
            $eligibleRows->execute(['id' => $orderId]);
            foreach ($eligibleRows->fetchAll(PDO::FETCH_COLUMN) as $rowAmount) {
                $alreadyEligible += arsenal_money_cents($rowAmount);
            }
            $recognizedEligible = min($due, $alreadyEligible);
            $rawReceived = arsenal_money_cents($totals['received']);
            $refundLiability = $order['trangThaiXuLy'] === 'DA_HUY'
                ? $rawReceived - $refundTotals['refunded'] - $refundTotals['pending']
                : $rawReceived - $recognizedEligible - $refundTotals['refunded'] - $refundTotals['pending'];
            if ($refundLiability > 0) {
                arsenal_admin_create_refund_request(
                    $db,
                    $adminId,
                    $orderId,
                    $order['trangThaiXuLy'] === 'DA_HUY' ? 'HUY_DON' : 'KHOAN_THU_CAN_TRA',
                    $reconciliation === 'SAU_HAN'
                        ? 'Hoàn khoản chuyển khoản nhận sau hạn.'
                        : ($reconciliation === 'DON_DA_HUY'
                            ? 'Hoàn khoản thu gắn với đơn đã hủy.'
                            : 'Hoàn khoản thu vượt số tiền phải trả.'),
                    $refundLiability
                );
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        arsenal_json_response([
            'success' => true,
            'reconciliationStatus' => $reconciliation,
            'payment' => arsenal_admin_payment_summary($db, $orderId),
        ]);
    }

    if ($action === 'void-payment') {
        $adminId = arsenal_require_admin(['THANH_TOAN']);
        $paymentId = filter_var($payload['paymentId'] ?? null, FILTER_VALIDATE_INT);
        $reason = trim((string)($payload['reason'] ?? ''));
        $adjustment = trim((string)($payload['canCuDieuChinh'] ?? ''));
        $replacementId = isset($payload['replacementPaymentId'])
            ? filter_var($payload['replacementPaymentId'], FILTER_VALIDATE_INT)
            : null;
        if (!$paymentId || $reason === '' || $adjustment === ''
            || mb_strlen($reason, 'UTF-8') > 500 || mb_strlen($adjustment, 'UTF-8') > 500
            || ($replacementId !== null && (!$replacementId || $replacementId === $paymentId))) {
            throw new InvalidArgumentException('Giao dịch, lý do hoặc căn cứ điều chỉnh không hợp lệ.');
        }

        $db->beginTransaction();
        try {
            $orderIdQuery = $db->prepare('SELECT maDH FROM tblGiaoDichThanhToan WHERE maGDTT=:id');
            $orderIdQuery->execute(['id' => $paymentId]);
            $orderId = $orderIdQuery->fetchColumn();
            if ($orderId === false) {
                throw new InvalidArgumentException('Không tìm thấy giao dịch cần vô hiệu.');
            }
            $order = arsenal_admin_order($db, (string)$orderId, true);
            $paymentQuery = $db->prepare(
                'SELECT maGDTT, maDH, phuongThuc, conHieuLuc FROM tblGiaoDichThanhToan
                 WHERE maGDTT=:id FOR UPDATE'
            );
            $paymentQuery->execute(['id' => $paymentId]);
            $payment = $paymentQuery->fetch();
            if (!$payment || !(int)$payment['conHieuLuc']) {
                throw new InvalidArgumentException('Giao dịch đã bị vô hiệu hoặc không còn tồn tại.');
            }
            $refundLink = $db->prepare('SELECT maYCHT FROM tblYeuCauHoanTien WHERE maGDTTNguon=:id FOR UPDATE');
            $refundLink->execute(['id' => $paymentId]);
            if ($refundLink->fetchColumn()) {
                throw new InvalidArgumentException('Hãy xử lý yêu cầu hoàn liên kết trước khi vô hiệu giao dịch.');
            }
            if ($replacementId !== null) {
                $replacement = $db->prepare(
                    'SELECT maGDTT FROM tblGiaoDichThanhToan
                     WHERE maGDTT=:replacement AND maDH=:orderId AND phuongThuc=:method
                       AND conHieuLuc=1 FOR UPDATE'
                );
                $replacement->execute([
                    'replacement' => $replacementId,
                    'orderId' => $orderId,
                    'method' => $payment['phuongThuc'],
                ]);
                if (!$replacement->fetchColumn()) {
                    throw new InvalidArgumentException('Giao dịch thay thế phải đang hiệu lực và thuộc cùng đơn.');
                }
            }
            $origin = $db->prepare(
                'SELECT maNKTT FROM tblNhatKyThanhToan
                 WHERE maGDTT=:paymentId ORDER BY ghiLuc, maNKTT LIMIT 1'
            );
            $origin->execute(['paymentId' => $paymentId]);
            $originId = $origin->fetchColumn();
            if ($originId === false) {
                throw new InvalidArgumentException('Giao dịch thiếu nhật ký gốc nên không thể vô hiệu an toàn.');
            }
            $db->prepare('UPDATE tblGiaoDichThanhToan SET conHieuLuc=0 WHERE maGDTT=:id')
                ->execute(['id' => $paymentId]);
            $totals = arsenal_payment_sync_order($db, (string)$orderId);
            $db->prepare(
                'INSERT INTO tblNhatKyThanhToan
                 (loaiSuKien, nguon, ghiLuc, trangThaiTruoc, trangThaiSau, lyDo,
                  canCu, maDH, maQTV, maGDTT, maNhatKyGoc, nguoiThucHien,
                  canCuDieuChinh, maBanGhiVoHieu, maBanGhiThayThe)
                 VALUES (\'VO_HIEU\', \'QUAN_TRI\', UTC_TIMESTAMP(3), :before, :after,
                  :reason, :evidence, :orderId, :adminId, :paymentId, :originId,
                  :actor, :adjustment, :voidedId, :replacementId)'
            )->execute([
                'before' => $order['trangThaiThanhToan'],
                'after' => $totals['status'],
                'reason' => $reason,
                'evidence' => $adjustment,
                'orderId' => $orderId,
                'adminId' => $adminId,
                'paymentId' => $paymentId,
                'originId' => $originId,
                'actor' => 'admin:' . $adminId,
                'adjustment' => $adjustment,
                'voidedId' => $paymentId,
                'replacementId' => $replacementId,
            ]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        arsenal_json_response(['success' => true, 'trangThaiThanhToan' => $totals['status']]);
    }

    if ($action === 'create-refund-request') {
        $adminId = arsenal_require_admin(['THANH_TOAN']);
        $orderId = trim((string)($payload['orderId'] ?? ''));
        $source = strtoupper(trim((string)($payload['source'] ?? 'SAU_GIAO')));
        $reason = trim((string)($payload['reason'] ?? ''));
        $goods = arsenal_admin_money_payload($payload['goodsAmount'] ?? 0, 'Tiền hàng cần hoàn');
        $shipping = arsenal_admin_money_payload($payload['shippingAmount'] ?? 0, 'Phí vận chuyển cần hoàn');
        $unallocated = arsenal_admin_money_payload($payload['unallocatedAmount'] ?? 0, 'Tiền chưa phân bổ cần hoàn');
        $amount = $goods + $shipping + $unallocated;
        if (!in_array($source, ['SAU_GIAO', 'KHOAN_THU_CAN_TRA', 'HUY_DON'], true)
            || $amount <= 0 || $reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
            throw new InvalidArgumentException('Nguồn, lý do và số tiền yêu cầu hoàn không hợp lệ.');
        }
        $db->beginTransaction();
        try {
            $order = arsenal_admin_order($db, $orderId, true);
            if ($source === 'SAU_GIAO' && $order['trangThaiXuLy'] !== 'DA_GIAO') {
                throw new InvalidArgumentException('Yêu cầu hoàn sau giao chỉ áp dụng cho đơn đã giao.');
            }
            if ($source === 'HUY_DON' && $order['trangThaiXuLy'] !== 'DA_HUY') {
                throw new InvalidArgumentException('Yêu cầu hoàn do hủy chỉ áp dụng cho đơn đã hủy.');
            }
            $received = arsenal_admin_order_payment_totals($db, $orderId);
            $totals = arsenal_admin_refund_totals($db, $orderId);
            if ($amount > arsenal_money_cents($received['received']) - $totals['refunded'] - $totals['pending']) {
                throw new InvalidArgumentException('Số yêu cầu hoàn vượt khoản đã thu còn lại sau các yêu cầu đang xử lý.');
            }
            $db->prepare(
                "INSERT INTO tblYeuCauHoanTien
                 (nguon, lyDo, taoLuc, soTienDeNghi, tienHangCanHoan,
                  phiVanChuyenCanHoan, tienChuaPhanBoCanHoan, trangThai, maDH, maQTVXuLy)
                 VALUES (:source, :reason, UTC_TIMESTAMP(3), :requested, :goods,
                         :shipping, :unallocated, 'CHO_DUYET', :orderId, :admin)"
            )->execute([
                'source' => $source,
                'reason' => $reason,
                'requested' => arsenal_format_money($amount),
                'goods' => arsenal_format_money($goods),
                'shipping' => arsenal_format_money($shipping),
                'unallocated' => arsenal_format_money($unallocated),
                'orderId' => $orderId,
                'admin' => $adminId,
            ]);
            $requestId = (int)$db->lastInsertId();
            arsenal_admin_log_payment_event(
                $db,
                'TAO_YEU_CAU',
                $reason,
                $orderId,
                $adminId,
                null,
                $requestId,
                null,
                null,
                'CHO_DUYET'
            );
            $currentPaymentStatus = (string)arsenal_admin_order($db, $orderId)['trangThaiThanhToan'];
            if ($currentPaymentStatus !== 'DANG_HOAN_TIEN') {
                $db->prepare(
                    "UPDATE tblDonHang SET trangThaiThanhToan='DANG_HOAN_TIEN' WHERE maDH=:id"
                )->execute(['id' => $orderId]);
                arsenal_payment_log($db, 'DOI_TRANG_THAI', 'QUAN_TRI', $orderId,
                    'Có yêu cầu hoàn tiền đang chờ xử lý.', $currentPaymentStatus, 'DANG_HOAN_TIEN',
                    null, $adminId, $reason, 'admin:' . $adminId);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        arsenal_json_response(['success' => true, 'refundRequestId' => $requestId]);
    }

    if ($action === 'review-refund-request') {
        $adminId = arsenal_require_admin(['THANH_TOAN']);
        $requestId = filter_var($payload['refundRequestId'] ?? null, FILTER_VALIDATE_INT);
        $decision = strtoupper(trim((string)($payload['decision'] ?? '')));
        $reason = trim((string)($payload['reason'] ?? ''));
        if (!$requestId || !in_array($decision, ['APPROVE', 'REJECT'], true)
            || $reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
            throw new InvalidArgumentException('Yêu cầu hoàn, quyết định hoặc lý do không hợp lệ.');
        }
        $db->beginTransaction();
        try {
            $orderIdQuery = $db->prepare('SELECT maDH FROM tblYeuCauHoanTien WHERE maYCHT=:id');
            $orderIdQuery->execute(['id' => $requestId]);
            $relatedOrderId = $orderIdQuery->fetchColumn();
            if ($relatedOrderId === false) {
                throw new InvalidArgumentException('Không tìm thấy yêu cầu hoàn.');
            }
            arsenal_admin_order($db, (string)$relatedOrderId, true);
            $statement = $db->prepare(
                'SELECT maYCHT, maDH, nguon, soTienDeNghi, trangThai
                 FROM tblYeuCauHoanTien WHERE maYCHT=:id FOR UPDATE'
            );
            $statement->execute(['id' => $requestId]);
            $request = $statement->fetch();
            if (!$request || $request['trangThai'] !== 'CHO_DUYET') {
                throw new InvalidArgumentException('Yêu cầu hoàn không còn chờ duyệt.');
            }
            $before = $request['trangThai'];
            $after = $decision === 'APPROVE' ? 'CHO_HOAN' : 'TU_CHOI';
            if ($decision === 'APPROVE') {
                $received = arsenal_admin_order_payment_totals($db, $request['maDH']);
                $totals = arsenal_admin_refund_totals($db, $request['maDH']);
                $available = arsenal_money_cents($received['received']) - $totals['refunded'] - $totals['pending']
                    + arsenal_money_cents($request['soTienDeNghi']);
                $approved = arsenal_money_cents($request['soTienDeNghi']);
                if ($approved > $available) {
                    throw new InvalidArgumentException('Số yêu cầu vượt khoản đã thu còn lại.');
                }
                $db->prepare(
                    "UPDATE tblYeuCauHoanTien
                     SET trangThai='CHO_HOAN',
                         xuLyLuc=UTC_TIMESTAMP(3), maQTVXuLy=:admin
                     WHERE maYCHT=:id AND trangThai='CHO_DUYET'"
                )->execute(['admin' => $adminId, 'id' => $requestId]);
            } else {
                $db->prepare(
                    "UPDATE tblYeuCauHoanTien
                     SET trangThai='TU_CHOI', xuLyLuc=UTC_TIMESTAMP(3), maQTVXuLy=:admin,
                         lyDo=CONCAT(lyDo, ' | Từ chối: ', :reason)
                     WHERE maYCHT=:id AND trangThai='CHO_DUYET'"
                )->execute(['admin' => $adminId, 'reason' => $reason, 'id' => $requestId]);
            }
            arsenal_admin_log_payment_event(
                $db,
                $decision === 'APPROVE' ? 'DUYET_YEU_CAU' : 'TU_CHOI_YEU_CAU',
                $reason,
                $request['maDH'],
                $adminId,
                null,
                $requestId,
                null,
                $before,
                $after
            );
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        arsenal_json_response(['success' => true, 'status' => $after]);
    }

    if ($action === 'record-refund') {
        $adminId = arsenal_require_admin(['THANH_TOAN']);
        $requestId = filter_var($payload['refundRequestId'] ?? null, FILTER_VALIDATE_INT);
        $goods = arsenal_admin_money_payload($payload['goodsAmount'] ?? 0, 'Tiền hàng hoàn');
        $shipping = arsenal_admin_money_payload($payload['shippingAmount'] ?? 0, 'Phí vận chuyển hoàn');
        $unallocated = arsenal_admin_money_payload($payload['unallocatedAmount'] ?? 0, 'Tiền chưa phân bổ hoàn');
        $amount = $goods + $shipping + $unallocated;
        $method = strtoupper(trim((string)($payload['method'] ?? '')));
        $source = trim((string)($payload['receiptSource'] ?? ''));
        $reference = trim((string)($payload['receiptReference'] ?? ''));
        $reason = trim((string)($payload['reason'] ?? ''));
        $actualAt = arsenal_admin_utc_datetime((string)($payload['actualAt'] ?? ''), 'Thời điểm thực hoàn');
        if (!$requestId || $amount <= 0 || !in_array($method, ['TIEN_MAT', 'CHUYEN_KHOAN'], true)
            || $source === '' || mb_strlen($source, 'UTF-8') > 80
            || $reference === '' || mb_strlen($reference, 'UTF-8') > 80
            || $reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
            throw new InvalidArgumentException('Thông tin thực hoàn hoặc chứng từ không hợp lệ.');
        }
        $db->beginTransaction();
        try {
            $orderIdQuery = $db->prepare('SELECT maDH FROM tblYeuCauHoanTien WHERE maYCHT=:id');
            $orderIdQuery->execute(['id' => $requestId]);
            $relatedOrderId = $orderIdQuery->fetchColumn();
            if ($relatedOrderId === false) {
                throw new InvalidArgumentException('Không tìm thấy yêu cầu hoàn.');
            }
            arsenal_admin_order($db, (string)$relatedOrderId, true);
            $statement = $db->prepare(
                'SELECT maYCHT, maDH, taoLuc, trangThai, tienHangCanHoan,
                        phiVanChuyenCanHoan, tienChuaPhanBoCanHoan, soTienCanHoan
                 FROM tblYeuCauHoanTien WHERE maYCHT=:id FOR UPDATE'
            );
            $statement->execute(['id' => $requestId]);
            $request = $statement->fetch();
            if (!$request || !in_array($request['trangThai'], ['CHO_HOAN', 'DANG_HOAN'], true)) {
                throw new InvalidArgumentException('Yêu cầu hoàn chưa được duyệt hoặc đã hoàn xong.');
            }
            if (strtotime($actualAt . ' UTC') < strtotime($request['taoLuc'] . ' UTC')) {
                throw new InvalidArgumentException('Thời điểm thực hoàn không thể trước lúc tạo yêu cầu.');
            }
            $hash = hash('sha256', mb_strtolower($source, 'UTF-8') . '|' . mb_strtolower($reference, 'UTF-8'));
            $duplicate = $db->prepare(
                'SELECT maLHT FROM tblLanHoanTien WHERE khoaChungTuHoan=:hash FOR UPDATE'
            );
            $duplicate->execute(['hash' => $hash]);
            if ($duplicate->fetchColumn()) {
                throw new InvalidArgumentException('Chứng từ hoàn này đã được ghi nhận.');
            }
            $existing = $db->prepare(
                'SELECT COALESCE(SUM(tienHangDaHoan),0) AS goods,
                        COALESCE(SUM(phiVanChuyenDaHoan),0) AS shipping,
                        COALESCE(SUM(tienChuaPhanBoDaHoan),0) AS unallocated,
                        COALESCE(SUM(tongDaHoan),0) AS total
                 FROM tblLanHoanTien WHERE maYCHT=:id'
            );
            $existing->execute(['id' => $requestId]);
            $alreadyRefunded = $existing->fetch();
            $alreadyRefundedForRequest = arsenal_money_cents($alreadyRefunded['total']);
            if ($amount > arsenal_money_cents($request['soTienCanHoan']) - $alreadyRefundedForRequest) {
                throw new InvalidArgumentException('Số hoàn vượt phần còn lại đã duyệt.');
            }
            foreach ([
                ['goods', 'tienHangCanHoan', $goods, 'tiền hàng'],
                ['shipping', 'phiVanChuyenCanHoan', $shipping, 'phí vận chuyển'],
                ['unallocated', 'tienChuaPhanBoCanHoan', $unallocated, 'tiền chưa phân bổ'],
            ] as [$refundedField, $approvedField, $requestedAmount, $label]) {
                if ($requestedAmount > arsenal_money_cents($request[$approvedField])
                    - arsenal_money_cents($alreadyRefunded[$refundedField])) {
                    throw new InvalidArgumentException('Khoản hoàn ' . $label . ' vượt số tiền đã duyệt còn lại.');
                }
            }
            $received = arsenal_admin_order_payment_totals($db, $request['maDH']);
            $totals = arsenal_admin_refund_totals($db, $request['maDH']);
            if ($amount > arsenal_money_cents($received['received']) - $totals['refunded']) {
                throw new InvalidArgumentException('Số hoàn vượt tổng tiền đã thực nhận còn lại.');
            }
            $db->prepare(
                'INSERT INTO tblLanHoanTien
                 (tienHangDaHoan, phiVanChuyenDaHoan, tienChuaPhanBoDaHoan,
                  phuongThucHoan, thucHoanLuc, ghiNhanLuc, nguonChungTuHoan,
                  maChungTuHoan, khoaChungTuHoan, lyDo, maYCHT, maQTVThucHien)
                 VALUES (:goods, :shipping, :unallocated, :method, :actualAt,
                         UTC_TIMESTAMP(3), :source, :reference, :hash, :reason, :requestId, :admin)'
            )->execute([
                'goods' => arsenal_format_money($goods),
                'shipping' => arsenal_format_money($shipping),
                'unallocated' => arsenal_format_money($unallocated),
                'method' => $method,
                'actualAt' => $actualAt,
                'source' => $source,
                'reference' => $reference,
                'hash' => $hash,
                'reason' => $reason,
                'requestId' => $requestId,
                'admin' => $adminId,
            ]);
            $refundId = (int)$db->lastInsertId();
            $newTotal = $alreadyRefundedForRequest + $amount;
            $after = $newTotal >= arsenal_money_cents($request['soTienCanHoan']) ? 'DA_HOAN_XONG' : 'DANG_HOAN';
            $db->prepare(
                'UPDATE tblYeuCauHoanTien SET trangThai=:status, xuLyLuc=UTC_TIMESTAMP(3), maQTVXuLy=:admin
                 WHERE maYCHT=:id'
            )->execute(['status' => $after, 'admin' => $adminId, 'id' => $requestId]);
            $allRefunds = $db->prepare(
                'SELECT COALESCE(SUM(ht.tongDaHoan), 0)
                 FROM tblLanHoanTien ht
                 INNER JOIN tblYeuCauHoanTien yc ON yc.maYCHT=ht.maYCHT
                 WHERE yc.maDH=:orderId'
            );
            $allRefunds->execute(['orderId' => $request['maDH']]);
            $refundedCents = arsenal_money_cents($allRefunds->fetchColumn());
            $orderPayments = arsenal_admin_order_payment_totals($db, $request['maDH']);
            $receivedCents = arsenal_money_cents($orderPayments['received']);
            $orderPaymentStatus = $refundedCents >= $receivedCents && $receivedCents > 0
                ? 'DA_HOAN_TIEN'
                : ($refundedCents > 0 ? 'DANG_HOAN_TIEN' : null);
            if ($orderPaymentStatus !== null) {
                $currentStatus = (string)arsenal_admin_order($db, $request['maDH'])['trangThaiThanhToan'];
                $db->prepare(
                    'UPDATE tblDonHang SET soTienDaHoan=:refunded, trangThaiThanhToan=:status WHERE maDH=:id'
                )->execute([
                    'refunded' => arsenal_format_money($refundedCents),
                    'status' => $orderPaymentStatus,
                    'id' => $request['maDH'],
                ]);
                arsenal_payment_log($db, 'GHI_HOAN', 'QUAN_TRI', $request['maDH'],
                    'Cập nhật trạng thái thanh toán theo tổng tiền đã hoàn.',
                    $currentStatus, $orderPaymentStatus, null, $adminId,
                    $source . ' / ' . $reference, 'admin:' . $adminId);
            }
            arsenal_admin_log_payment_event(
                $db,
                'GHI_HOAN',
                $reason,
                $request['maDH'],
                $adminId,
                null,
                null,
                $refundId,
                $request['trangThai'],
                $after,
                $source . ' / ' . $reference
            );
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        arsenal_json_response(['success' => true, 'status' => $after]);
    }

    if ($action === 'save-coupon') {
        arsenal_require_admin(['KHUYEN_MAI']);
        if (!is_array($payload['coupon'] ?? null)) {
            throw new InvalidArgumentException('Dữ liệu mã giảm giá không hợp lệ.');
        }
        arsenal_admin_save_coupon($db, $payload['coupon'] ?? []);
        arsenal_json_response(['success' => true]);
    }

    if ($action === 'moderate-review') {
        $adminId = arsenal_require_admin(['DANH_GIA']);
        $reviewId = filter_var($payload['reviewId'] ?? null, FILTER_VALIDATE_INT);
        $status = strtoupper(trim((string)($payload['status'] ?? '')));
        $reason = trim((string)($payload['reason'] ?? ''));
        if (!$reviewId || !in_array($status, ['DA_DUYET', 'TU_CHOI'], true)
            || ($status === 'TU_CHOI' && $reason === '')
            || mb_strlen($reason, 'UTF-8') > 500) {
            throw new InvalidArgumentException('Trạng thái kiểm duyệt hoặc lý do từ chối không hợp lệ.');
        }
        $db->beginTransaction();
        try {
            $review = $db->prepare(
                'SELECT trangThai FROM tblDanhGia WHERE maDanhGia=:id FOR UPDATE'
            );
            $review->execute(['id' => $reviewId]);
            $current = $review->fetchColumn();
            if ($current !== 'CHO_DUYET') {
                throw new InvalidArgumentException('Đánh giá không còn chờ kiểm duyệt.');
            }
            $db->prepare(
                'UPDATE tblDanhGia SET trangThai=:status, duyetLuc=UTC_TIMESTAMP(3),
                        lyDoKiemDuyet=:reason, maQTVKiemDuyet=:admin
                 WHERE maDanhGia=:id AND trangThai=\'CHO_DUYET\''
            )->execute([
                'status' => $status,
                'reason' => $status === 'TU_CHOI' ? $reason : null,
                'admin' => $adminId,
                'id' => $reviewId,
            ]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        arsenal_json_response(['success' => true]);
    }

    if (str_starts_with($action, 'preorder-')) {
        $adminId = arsenal_require_admin(['DAT_TRUOC']);
        $preorderId = trim((string)($payload['preorderId'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $preorderId)) {
            throw new InvalidArgumentException('Mã yêu cầu đặt trước không hợp lệ.');
        }
        $convertedOrderId = null;
        $db->beginTransaction();
        try {
            $statement = $db->prepare(
                'SELECT y.*, sp.tenSP, sp.giaBan, sp.tonKhaDung, sp.trangThai AS productStatus
                 FROM tblYeuCauDatTruoc y INNER JOIN tblSanPham sp ON sp.maSP=y.maSP
                 WHERE y.maYCDT=:id FOR UPDATE'
            );
            $statement->execute(['id' => $preorderId]);
            $preorder = $statement->fetch();
            if (!$preorder) {
                throw new InvalidArgumentException('Không tìm thấy yêu cầu đặt trước.');
            }
            if ($action === 'preorder-confirm') {
                if ($preorder['trangThai'] !== 'CHO_XAC_NHAN') {
                    throw new InvalidArgumentException('Yêu cầu không còn chờ xác nhận.');
                }
                $db->prepare(
                    "UPDATE tblYeuCauDatTruoc SET trangThai='DA_XAC_NHAN'
                     WHERE maYCDT=:id AND trangThai='CHO_XAC_NHAN'"
                )->execute(['id' => $preorderId]);
            } elseif ($action === 'preorder-contact') {
                if ($preorder['trangThai'] !== 'DA_XAC_NHAN'
                    || (int)$preorder['soLanLienHe'] >= 2
                    || ($preorder['hanPhanHoi'] !== null
                        && strtotime($preorder['hanPhanHoi'] . ' UTC') < time())) {
                    throw new InvalidArgumentException('Không thể liên hệ thêm: yêu cầu chưa xác nhận, đã quá hạn hoặc đủ hai lần liên hệ.');
                }
                $note = trim((string)($payload['note'] ?? ''));
                if ($note === '' || mb_strlen($note, 'UTF-8') > 500) {
                    throw new InvalidArgumentException('Ghi chú nội dung liên hệ là bắt buộc (tối đa 500 ký tự).');
                }
                if ((int)$preorder['soLanLienHe'] === 0) {
                    $db->prepare(
                        'UPDATE tblYeuCauDatTruoc
                         SET soLanLienHe=1, lienHeDauLuc=UTC_TIMESTAMP(3),
                             lienHeGanNhatLuc=UTC_TIMESTAMP(3), ghiChu=:note
                         WHERE maYCDT=:id AND trangThai=\'DA_XAC_NHAN\' AND soLanLienHe=0'
                    )->execute(['note' => $note, 'id' => $preorderId]);
                } else {
                    $db->prepare(
                        'UPDATE tblYeuCauDatTruoc
                         SET soLanLienHe=soLanLienHe+1, lienHeGanNhatLuc=UTC_TIMESTAMP(3),
                             ghiChu=CONCAT(COALESCE(ghiChu, \'\'), \' | \', :note)
                         WHERE maYCDT=:id AND trangThai=\'DA_XAC_NHAN\' AND soLanLienHe=1'
                    )->execute(['note' => $note, 'id' => $preorderId]);
                }
            } elseif ($action === 'preorder-cancel') {
                $reason = trim((string)($payload['reason'] ?? ''));
                if (!in_array($preorder['trangThai'], ['CHO_XAC_NHAN', 'DA_XAC_NHAN'], true)
                    || $reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
                    throw new InvalidArgumentException('Chỉ hủy yêu cầu đang chờ hoặc đã xác nhận và cần lý do.');
                }
                $db->prepare(
                    "UPDATE tblYeuCauDatTruoc
                     SET trangThai='DA_HUY', yeuCauHuyLuc=UTC_TIMESTAMP(3),
                         ghiChu=CONCAT(COALESCE(ghiChu, ''), ' | Hủy: ', :reason)
                     WHERE maYCDT=:id AND trangThai=:status"
                )->execute(['reason' => $reason, 'id' => $preorderId, 'status' => $preorder['trangThai']]);
            } elseif ($action === 'preorder-convert') {
                $address = trim((string)($payload['address'] ?? ''));
                if ($preorder['trangThai'] !== 'DA_XAC_NHAN'
                    || (int)$preorder['soLanLienHe'] < 1
                    || $preorder['hanPhanHoi'] === null
                    || strtotime($preorder['hanPhanHoi'] . ' UTC') < time()) {
                    throw new InvalidArgumentException('Chỉ chuyển thành đơn sau khi xác nhận và liên hệ trong thời hạn phản hồi.');
                }
                if ($address === '' || mb_strlen($address, 'UTF-8') > 255) {
                    throw new InvalidArgumentException('Địa chỉ giao hàng là bắt buộc (tối đa 255 ký tự).');
                }
                if ($preorder['productStatus'] !== 'DANG_BAN'
                    || $preorder['giaBan'] === null
                    || (int)$preorder['tonKhaDung'] < (int)$preorder['soLuong']) {
                    throw new InvalidArgumentException('Sản phẩm chưa mở bán hoặc tồn khả dụng không đủ; chưa tạo đơn.');
                }
                $price = arsenal_money_cents($preorder['giaBan']);
                $quantity = (int)$preorder['soLuong'];
                $subtotal = $price * $quantity;
                $shipping = arsenal_money_cents(arsenal_config()['shipping_fee'] ?? null);
                $orderId = 'ARS-' . strtoupper(bin2hex(random_bytes(12)));
                $db->prepare(
                    "INSERT INTO tblDonHang
                     (maDH, datLuc, hoTenNhan, dienThoaiNhan, diaChiNhan, emailNhan,
                      phuongThucThanhToan, tienHang, tienGiam, phiVanChuyen,
                      trangThaiXuLy, maKH)
                     VALUES (:id, UTC_TIMESTAMP(3), :name, :phone, :address, :email,
                             'COD', :subtotal, 0, :shipping, 'CHO_XAC_NHAN', NULL)"
                )->execute([
                    'id' => $orderId,
                    'name' => $preorder['hoTenNguoiDat'],
                    'phone' => $preorder['dienThoaiLienHe'],
                    'address' => $address,
                    'email' => $preorder['emailLienHe'],
                    'subtotal' => arsenal_format_money($subtotal),
                    'shipping' => arsenal_format_money($shipping),
                ]);
                $db->prepare(
                    'INSERT INTO tblChiTietDonHang
                     (maDH, maSP, maSPLucDat, tenSPLucDat, soLuong, donGiaLucDat)
                     VALUES (:orderId, :productId, :snapshotId, :name, :quantity, :price)'
                )->execute([
                    'orderId' => $orderId,
                    'productId' => $preorder['maSP'],
                    'snapshotId' => $preorder['maSP'],
                    'name' => $preorder['tenSP'],
                    'quantity' => $quantity,
                    'price' => arsenal_format_money($price),
                ]);
                $db->prepare(
                    "UPDATE tblYeuCauDatTruoc
                     SET trangThai='DA_CHUYEN_DON', chuyenDonLuc=UTC_TIMESTAMP(3),
                         maDHHinhThanh=:orderId
                     WHERE maYCDT=:id AND trangThai='DA_XAC_NHAN' AND maDHHinhThanh IS NULL"
                )->execute(['orderId' => $orderId, 'id' => $preorderId]);
                $convertedOrderId = $orderId;
            } else {
                throw new InvalidArgumentException('Thao tác đặt trước không hợp lệ.');
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        arsenal_json_response(['success' => true, 'orderId' => $convertedOrderId]);
    }

    if ($action === 'save-ai-rule') {
        $adminId = arsenal_require_admin(['GOI_Y_AI']);
        if (!is_array($payload['rule'] ?? null)) {
            throw new InvalidArgumentException('Dữ liệu quy tắc tư vấn không hợp lệ.');
        }
        arsenal_admin_save_ai_rule($db, $adminId, $payload['rule'] ?? []);
        arsenal_json_response(['success' => true]);
    }

    if ($action === 'stock-receipt' || $action === 'stock-adjustment') {
        $adminId = arsenal_require_admin(['KHO']);
        $productId = trim((string)($payload['productId'] ?? ''));
        if ($action === 'stock-receipt') {
            $quantity = filter_var($payload['quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($quantity === false || $quantity < 1 || $quantity > 2147483647) {
                throw new InvalidArgumentException('Số lượng nhập phải là số nguyên dương.');
            }
            $delta = $quantity;
            $type = 'NHAP';
        } else {
            $delta = filter_var($payload['delta'] ?? null, FILTER_VALIDATE_INT);
            if ($delta === false || $delta === 0 || $delta < -2147483647 || $delta > 2147483647) {
                throw new InvalidArgumentException('Điều chỉnh tồn phải là số nguyên khác 0.');
            }
            $type = 'DIEU_CHINH';
        }
        arsenal_admin_stock_change(
            $db,
            $adminId,
            $productId,
            $delta,
            $type,
            (string)($payload['reason'] ?? '')
        );
        arsenal_json_response(['success' => true]);
    }

    if ($action === 'save-product') {
        arsenal_require_admin(['SAN_PHAM']);
        $product = arsenal_admin_product_payload($payload['product'] ?? []);
        $isUpdate = !empty($payload['isUpdate']);
        arsenal_admin_save_product($db, $product, $isUpdate);
        arsenal_json_response(['success' => true]);
    }

    if ($action === 'save-reference') {
        arsenal_require_admin(['DANH_MUC']);
        arsenal_admin_save_reference($db, (string)($payload['kind'] ?? ''), $payload['record'] ?? []);
        arsenal_json_response(['success' => true]);
    }

    if ($action === 'customer-status') {
        arsenal_require_admin(['KHACH_HANG']);
        $customerId = filter_var($payload['customerId'] ?? null, FILTER_VALIDATE_INT);
        $status = (string)($payload['status'] ?? '');
        if (!$customerId || !in_array($status, ['HOAT_DONG', 'BI_KHOA'], true)) {
            throw new InvalidArgumentException('Khách hàng hoặc trạng thái không hợp lệ.');
        }
        $db->prepare('UPDATE tblKhachHang SET trangThai=:status WHERE maKH=:id')
            ->execute(['status' => $status, 'id' => $customerId]);
        arsenal_json_response(['success' => true]);
    }

    arsenal_json_response(['message' => 'Thao tác quản trị không hợp lệ.'], 400);
} catch (InvalidArgumentException $error) {
    arsenal_json_response(['message' => $error->getMessage()], 422);
} catch (PDOException $error) {
    if (($error->errorInfo[1] ?? null) === 1062) {
        arsenal_json_response(['message' => 'Mã hoặc tên này đã tồn tại.'], 409);
    }
    error_log('ARSENAL admin API: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể cập nhật dữ liệu quản trị.'], 500);
} catch (Throwable $error) {
    error_log('ARSENAL admin API: ' . $error->getMessage());
    arsenal_json_response(['message' => 'Không thể xử lý yêu cầu quản trị.'], 500);
}

function arsenal_admin_require_or_null(): ?int
{
    if (($_SESSION['arsenal_role'] ?? null) !== 'admin' || empty($_SESSION['arsenal_admin_id'])) {
        return null;
    }
    $statement = arsenal_db()->prepare(
        "SELECT maQTV FROM tblQuanTriVien WHERE maQTV=:id AND trangThai='HOAT_DONG'"
    );
    $statement->execute(['id' => (int)$_SESSION['arsenal_admin_id']]);
    $id = $statement->fetchColumn();

    return $id === false ? null : (int)$id;
}

function arsenal_admin_inventory_csv(PDO $db, string $productId): void
{
    if ($productId !== '' && !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $productId)) {
        throw new InvalidArgumentException('Mã sản phẩm lọc không hợp lệ.');
    }
    $sql = 'SELECT sp.maSP, sp.tenSP, sp.tonKhaDung, sp.trangThai,
                   COALESCE(giu.soLuong,0) AS soLuongDangXuLy
            FROM tblSanPham sp
            LEFT JOIN (
                SELECT ct.maSP, SUM(ct.soLuong) AS soLuong
                FROM tblChiTietDonHang ct
                INNER JOIN tblDonHang dh ON dh.maDH=ct.maDH
                WHERE dh.trangThaiXuLy IN (\'DA_XAC_NHAN\',\'DANG_GIAO\')
                GROUP BY ct.maSP
            ) giu ON giu.maSP=sp.maSP';
    if ($productId !== '') {
        $sql .= ' WHERE sp.maSP=:id';
    }
    $sql .= ' ORDER BY sp.tenSP, sp.maSP';
    $statement = $db->prepare($sql);
    $statement->execute($productId === '' ? [] : ['id' => $productId]);
    $rows = $statement->fetchAll();

    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="arsenal-inventory.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Không mở được luồng xuất CSV.');
    }
    fputcsv($output, ['Ma san pham', 'Ten san pham', 'Ton kha dung', 'Dang xu ly don', 'Trang thai']);
    foreach ($rows as $row) {
        fputcsv($output, array_values($row));
    }
    fclose($output);
    exit;
}

function arsenal_admin_analytics(PDO $db, string $from, string $to): array
{
    $stats = $db->query(
        "SELECT (SELECT COUNT(*) FROM tblSanPham) AS productCount,
                (SELECT COUNT(*) FROM tblDonHang) AS orderCount,
                (SELECT COUNT(*) FROM tblDonHang WHERE trangThaiXuLy='CHO_XAC_NHAN') AS pendingOrders,
                (SELECT COUNT(*) FROM tblKhachHang) AS customerCount,
                (SELECT COALESCE(SUM(tonKhaDung),0) FROM tblSanPham) AS availableStock"
    )->fetch();
    $start = $from . ' 00:00:00';
    $endExclusive = gmdate('Y-m-d', strtotime($to . ' UTC') + 86400) . ' 00:00:00';
    $received = $db->prepare(
        'SELECT COALESCE(SUM(soTienThucNhan),0) FROM tblGiaoDichThanhToan
         WHERE thucNhanLuc>=:start AND thucNhanLuc<:end'
    );
    $received->execute(['start' => $start, 'end' => $endExclusive]);
    $refund = $db->prepare(
        'SELECT COALESCE(SUM(ht.tongDaHoan),0)
         FROM tblLanHoanTien ht WHERE ht.thucHoanLuc>=:start AND ht.thucHoanLuc<:end'
    );
    $refund->execute(['start' => $start, 'end' => $endExclusive]);
    $delivered = $db->prepare(
        "SELECT COUNT(*) FROM tblDonHang
         WHERE trangThaiXuLy='DA_GIAO' AND giaoThanhCongLuc>=:start AND giaoThanhCongLuc<:end"
    );
    $delivered->execute(['start' => $start, 'end' => $endExclusive]);

    $orders = $db->query(
        "SELECT dh.maDH, dh.tongPhaiTra, dh.tienHangSauGiam, dh.giaoThanhCongLuc
         FROM tblDonHang dh
         WHERE dh.trangThaiXuLy='DA_GIAO' AND dh.giaoThanhCongLuc IS NOT NULL
           AND dh.giaoThanhCongLuc<" . $db->quote($endExclusive)
    )->fetchAll();
    $paymentRows = $db->query(
        "SELECT maDH, soTienThucNhan, thucNhanLuc
         FROM tblGiaoDichThanhToan
         WHERE ketQuaDoiChieu='DU_DIEU_KIEN' AND thucNhanLuc<" . $db->quote($endExclusive) . "
         ORDER BY maDH, thucNhanLuc, maGDTT"
    )->fetchAll();
    $paymentsByOrder = [];
    foreach ($paymentRows as $payment) {
        $paymentsByOrder[$payment['maDH']][] = $payment;
    }
    $recognizedAt = [];
    $daily = [];
    foreach ($orders as $order) {
        $day = substr($order['giaoThanhCongLuc'], 0, 10);
        $daily[$day] ??= ['gross' => 0, 'refund' => 0, 'orders' => 0, 'delivered' => 0, 'cashReceived' => 0, 'cashRefunded' => 0];
        $daily[$day]['delivered']++;
    }
    foreach ($orders as $order) {
        $sum = 0;
        $paidAt = null;
        foreach ($paymentsByOrder[$order['maDH']] ?? [] as $payment) {
            $sum += arsenal_money_cents($payment['soTienThucNhan']);
            if ($sum >= arsenal_money_cents($order['tongPhaiTra'])) {
                $paidAt = $payment['thucNhanLuc'];
                break;
            }
        }
        if ($paidAt === null) {
            continue;
        }
        $earnedAt = max(strtotime($order['giaoThanhCongLuc'] . ' UTC'), strtotime($paidAt . ' UTC'));
        $recognizedAt[$order['maDH']] = $earnedAt;
        $day = gmdate('Y-m-d', $earnedAt);
        $daily[$day] ??= ['gross' => 0, 'refund' => 0, 'orders' => 0, 'delivered' => 0, 'cashReceived' => 0, 'cashRefunded' => 0];
        $daily[$day]['gross'] += arsenal_money_cents($order['tienHangSauGiam']);
        $daily[$day]['orders']++;
    }
    $cashRows = $db->prepare(
        'SELECT DATE(thucNhanLuc) AS day, COALESCE(SUM(soTienThucNhan),0) AS amount
         FROM tblGiaoDichThanhToan WHERE thucNhanLuc>=:start AND thucNhanLuc<:end GROUP BY DATE(thucNhanLuc)'
    );
    $cashRows->execute(['start' => $start, 'end' => $endExclusive]);
    foreach ($cashRows->fetchAll() as $row) {
        $daily[$row['day']] ??= ['gross' => 0, 'refund' => 0, 'orders' => 0, 'delivered' => 0, 'cashReceived' => 0, 'cashRefunded' => 0];
        $daily[$row['day']]['cashReceived'] = arsenal_money_cents($row['amount']);
    }
    $cashRefundRows = $db->prepare(
        'SELECT DATE(thucHoanLuc) AS day, COALESCE(SUM(tongDaHoan),0) AS amount
         FROM tblLanHoanTien WHERE thucHoanLuc>=:start AND thucHoanLuc<:end GROUP BY DATE(thucHoanLuc)'
    );
    $cashRefundRows->execute(['start' => $start, 'end' => $endExclusive]);
    foreach ($cashRefundRows->fetchAll() as $row) {
        $daily[$row['day']] ??= ['gross' => 0, 'refund' => 0, 'orders' => 0, 'delivered' => 0, 'cashReceived' => 0, 'cashRefunded' => 0];
        $daily[$row['day']]['cashRefunded'] = arsenal_money_cents($row['amount']);
    }

    $refundRows = $db->query(
        'SELECT yc.maDH, ht.tienHangDaHoan, ht.thucHoanLuc
         FROM tblLanHoanTien ht
         INNER JOIN tblYeuCauHoanTien yc ON yc.maYCHT=ht.maYCHT
         WHERE ht.thucHoanLuc<' . $db->quote($endExclusive)
    )->fetchAll();
    foreach ($refundRows as $row) {
        $recognized = $recognizedAt[$row['maDH']] ?? null;
        if ($recognized === null) {
            continue;
        }
        $refundedAt = strtotime($row['thucHoanLuc'] . ' UTC');
        $day = gmdate('Y-m-d', max($recognized, $refundedAt));
        $daily[$day] ??= ['gross' => 0, 'refund' => 0, 'orders' => 0, 'delivered' => 0, 'cashReceived' => 0, 'cashRefunded' => 0];
        $daily[$day]['refund'] += arsenal_money_cents($row['tienHangDaHoan']);
    }
    $revenueRows = [];
    $grossTotal = 0;
    $refundTotal = 0;
    for ($timestamp = strtotime($from . ' UTC'); $timestamp <= strtotime($to . ' UTC'); $timestamp += 86400) {
        $day = gmdate('Y-m-d', $timestamp);
        $gross = (int)($daily[$day]['gross'] ?? 0);
        $reversed = (int)($daily[$day]['refund'] ?? 0);
        $revenueRows[] = [
            'date' => $day,
            'grossGoodsRevenue' => arsenal_format_money($gross),
            'refundedGoods' => arsenal_format_money($reversed),
            'netGoodsRevenue' => arsenal_format_money($gross - $reversed),
            'ordersRecognized' => (int)($daily[$day]['orders'] ?? 0),
            'deliveredOrders' => (int)($daily[$day]['delivered'] ?? 0),
            'cashReceived' => arsenal_format_money((int)($daily[$day]['cashReceived'] ?? 0)),
            'cashRefunded' => arsenal_format_money((int)($daily[$day]['cashRefunded'] ?? 0)),
        ];
        $grossTotal += $gross;
        $refundTotal += $reversed;
    }

    $best = $db->prepare(
        "SELECT ct.maSP AS productId, ct.tenSPLucDat AS productName, SUM(ct.soLuong) AS quantity
         FROM tblChiTietDonHang ct
         INNER JOIN tblDonHang dh ON dh.maDH=ct.maDH
         WHERE dh.trangThaiXuLy='DA_GIAO' AND dh.giaoThanhCongLuc>=:start AND dh.giaoThanhCongLuc<:end
         GROUP BY ct.maSP, ct.tenSPLucDat ORDER BY quantity DESC, ct.tenSPLucDat LIMIT 10"
    );
    $best->execute(['start' => $start, 'end' => $endExclusive]);
    $outstanding = $db->prepare(
        "SELECT COUNT(*) AS orderCount,
                COALESCE(SUM(GREATEST(dh.tongPhaiTra-COALESCE(p.received,0),0)),0) AS amount
         FROM tblDonHang dh
         LEFT JOIN (
             SELECT maDH, SUM(soTienThucNhan) AS received FROM tblGiaoDichThanhToan
             WHERE phuongThuc='COD' AND ketQuaDoiChieu='DU_DIEU_KIEN' AND thucNhanLuc<:paymentCutoff
             GROUP BY maDH
         ) p ON p.maDH=dh.maDH
         WHERE dh.phuongThucThanhToan='COD' AND dh.trangThaiXuLy='DA_GIAO'
           AND dh.giaoThanhCongLuc<:deliveryCutoff
           AND COALESCE(p.received,0)<dh.tongPhaiTra"
    );
    $outstanding->execute(['paymentCutoff' => $endExclusive, 'deliveryCutoff' => $endExclusive]);
    $codOutstanding = $outstanding->fetch();
    $cashflow = [
        'received' => (string)$received->fetchColumn(),
        'refunded' => (string)$refund->fetchColumn(),
    ];

    return [
        'from' => $from,
        'to' => $to,
        'stats' => $stats,
        'deliveredOrders' => (int)$delivered->fetchColumn(),
        'grossGoodsRevenue' => arsenal_format_money($grossTotal),
        'refundedGoods' => arsenal_format_money($refundTotal),
        'netGoodsRevenue' => arsenal_format_money($grossTotal - $refundTotal),
        'cashflow' => [
            'received' => $cashflow['received'],
            'refunded' => $cashflow['refunded'],
            'net' => arsenal_format_money(arsenal_money_cents($cashflow['received']) - arsenal_money_cents($cashflow['refunded'])),
        ],
        'codOutstanding' => [
            'orderCount' => (int)$codOutstanding['orderCount'],
            'amount' => (string)$codOutstanding['amount'],
            'asOf' => $to,
        ],
        'bestSellers' => $best->fetchAll(),
        'revenueRows' => $revenueRows,
    ];
}

function arsenal_admin_revenue_csv(PDO $db, string $from, string $to): void
{
    $report = arsenal_admin_analytics($db, $from, $to);
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="arsenal-revenue.csv"');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Không mở được luồng xuất CSV.');
    }
    fputcsv($output, [
        'Ngay',
        'Doanh thu tien hang',
        'Tien hang hoan',
        'Doanh thu sau hoan',
        'Don ghi nhan doanh thu',
        'Don giao thanh cong',
        'Thuc thu',
        'Thuc hoan',
    ]);
    foreach ($report['revenueRows'] as $row) {
        fputcsv($output, array_values($row));
    }
    fclose($output);
    exit;
}
