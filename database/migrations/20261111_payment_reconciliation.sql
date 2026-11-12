-- Run once after ARSENAL_MariaDB.sql on MariaDB 10.4+.
-- Adds the payment state model while retaining legacy columns used by the current admin.
USE `arsenal`;

ALTER TABLE `tblDonHang`
    DROP CONSTRAINT `ck_DonHang_phuongThucThanhToan`,
    DROP CONSTRAINT `ck_DonHang_trangThaiXuLy`,
    ADD COLUMN `tongTienPhaiTra` DECIMAL(18,2)
        GENERATED ALWAYS AS (tienHang - tienGiam + phiVanChuyen) STORED,
    ADD COLUMN `trangThaiThanhToan` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin
        NOT NULL DEFAULT 'CHUA_THANH_TOAN',
    ADD COLUMN `soTienDaThu` DECIMAL(18,2) NOT NULL DEFAULT 0,
    ADD COLUMN `soTienDaPhanBo` DECIMAL(18,2) NOT NULL DEFAULT 0,
    ADD COLUMN `soTienDaHoan` DECIMAL(18,2) NOT NULL DEFAULT 0,
    ADD COLUMN `huongDanChuyenKhoan` VARCHAR(512) NULL,
    ADD COLUMN `thoiDiemGiaoThanhCong` DATETIME(3) NULL,
    ADD COLUMN `thoiDiemNhanDuTien` DATETIME(3) NULL,
    ADD COLUMN `ngayGhiNhanDoanhThu` DATE NULL,
    ADD COLUMN `tonKhoDaGiu` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `maMomoRequest` VARCHAR(64) NULL,
    ADD COLUMN `momoPayUrl` TEXT NULL,
    ADD COLUMN `momoQrCodeUrl` TEXT NULL,
    ADD COLUMN `momoDeeplink` TEXT NULL,
    ADD UNIQUE KEY `uq_DonHang_maMomoRequest` (`maMomoRequest`),
    ADD CONSTRAINT `ck_DonHang_phuongThucThanhToan`
        CHECK (phuongThucThanhToan IN ('COD', 'CHUYEN_KHOAN', 'MOMO')),
    ADD CONSTRAINT `ck_DonHang_trangThaiXuLy`
        CHECK (trangThaiXuLy IN ('CHO_XAC_NHAN', 'DA_XAC_NHAN', 'DANG_GIAO', 'DA_GIAO', 'DA_HUY', 'HOAN_TRA')),
    ADD CONSTRAINT `ck_DonHang_trangThaiThanhToan`
        CHECK (trangThaiThanhToan IN ('CHUA_THANH_TOAN', 'CHO_CHUYEN_KHOAN', 'THANH_TOAN_MOT_PHAN',
          'DA_THANH_TOAN_DU', 'THANH_TOAN_DU_THUA', 'DANG_HOAN_TIEN', 'DA_HOAN_TIEN')),
    ADD CONSTRAINT `ck_DonHang_tienDaThu` CHECK (soTienDaThu >= 0),
    ADD CONSTRAINT `ck_DonHang_tienDaPhanBo` CHECK (soTienDaPhanBo >= 0),
    ADD CONSTRAINT `ck_DonHang_tienDaHoan` CHECK (soTienDaHoan >= 0),
    ADD CONSTRAINT `ck_DonHang_tonKhoDaGiu` CHECK (tonKhoDaGiu IN (0, 1));

ALTER TABLE `tblGiaoDichThanhToan`
    DROP CONSTRAINT `ck_GiaoDichThanhToan_phuongThuc`,
    DROP CONSTRAINT `ck_GiaoDichThanhToan_chungTuCK`,
    MODIFY COLUMN `maQTVXacNhan` BIGINT UNSIGNED NULL,
    ADD COLUMN `taiKhoanNhan` VARCHAR(120) NULL,
    ADD COLUMN `maDoiSoat` VARCHAR(80) NULL,
    ADD COLUMN `ketQuaPhanBo` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'KHOP_DU',
    ADD COLUMN `conHieuLuc` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN `khoaIdempotency` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD UNIQUE KEY `uq_GiaoDichThanhToan_khoaIdempotency` (`khoaIdempotency`),
    ADD CONSTRAINT `ck_GiaoDichThanhToan_phuongThuc`
        CHECK (phuongThuc IN ('COD', 'CHUYEN_KHOAN', 'MOMO')),
    ADD CONSTRAINT `ck_GiaoDichThanhToan_chungTuCK`
        CHECK (phuongThuc <> 'CHUYEN_KHOAN' OR
          (maNganHangNhan IS NOT NULL AND soTaiKhoanNhan IS NOT NULL AND maGDNganHang IS NOT NULL)),
    ADD CONSTRAINT `ck_GiaoDichThanhToan_chungTuMomo`
        CHECK (phuongThuc <> 'MOMO' OR maGDNganHang IS NOT NULL),
    ADD CONSTRAINT `ck_GiaoDichThanhToan_ketQuaPhanBo`
        CHECK (ketQuaPhanBo IN ('KHOP_DU', 'THIEU', 'DU')),
    ADD CONSTRAINT `ck_GiaoDichThanhToan_conHieuLuc` CHECK (conHieuLuc IN (0, 1));

ALTER TABLE `tblLichSuTonKho`
    MODIFY COLUMN `maQTVThucHien` BIGINT UNSIGNED NULL;

ALTER TABLE `tblYeuCauHoanTien`
    ADD COLUMN `maGDTTNguon` BIGINT UNSIGNED NULL,
    ADD UNIQUE KEY `uq_YeuCauHoanTien_maGDTTNguon` (`maGDTTNguon`),
    ADD CONSTRAINT `fk_YeuCauHoanTien_maGDTTNguon` FOREIGN KEY (`maGDTTNguon`)
        REFERENCES `tblGiaoDichThanhToan` (`maGDTT`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `tblNhatKyThanhToan`
    DROP CONSTRAINT `ck_NhatKyThanhToan_loaiSuKien`,
    ADD COLUMN `nguoiThucHien` VARCHAR(120) NULL,
    ADD COLUMN `canCuDieuChinh` VARCHAR(500) NULL,
    ADD COLUMN `maBanGhiVoHieu` BIGINT UNSIGNED NULL,
    ADD COLUMN `maBanGhiThayThe` BIGINT UNSIGNED NULL,
    ADD CONSTRAINT `ck_NhatKyThanhToan_loaiSuKien`
        CHECK (loaiSuKien IN ('GHI_THU', 'TAO_YEU_CAU', 'CAP_NHAT_YEU_CAU', 'DUYET_YEU_CAU',
          'TU_CHOI_YEU_CAU', 'GHI_HOAN', 'DOI_TRANG_THAI', 'VO_HIEU', 'THAY_THE',
          'TAO_DON', 'TAO_GIAO_DICH', 'IPN_MOMO', 'DOI_SOAT', 'HUY_QUA_HAN'));

UPDATE `tblDonHang` dh
LEFT JOIN (
    SELECT maDH,
           SUM(CASE WHEN conHieuLuc=1 THEN soTienThucNhan ELSE 0 END) AS received,
           SUM(CASE WHEN conHieuLuc=1 AND ketQuaDoiChieu='DU_DIEU_KIEN'
                    THEN soTienThucNhan ELSE 0 END) AS allocated
    FROM `tblGiaoDichThanhToan`
    GROUP BY maDH
) gd ON gd.maDH=dh.maDH
SET dh.soTienDaThu=COALESCE(gd.received, 0),
    dh.soTienDaPhanBo=LEAST(COALESCE(gd.allocated, 0), dh.tongTienPhaiTra),
    dh.trangThaiThanhToan=CASE
        WHEN COALESCE(gd.allocated, 0)=0 AND dh.phuongThucThanhToan='CHUYEN_KHOAN' THEN 'CHO_CHUYEN_KHOAN'
        WHEN COALESCE(gd.allocated, 0)=0 THEN 'CHUA_THANH_TOAN'
        WHEN gd.allocated<dh.tongTienPhaiTra THEN 'THANH_TOAN_MOT_PHAN'
        WHEN gd.allocated=dh.tongTienPhaiTra THEN 'DA_THANH_TOAN_DU'
        ELSE 'THANH_TOAN_DU_THUA'
    END,
    dh.thoiDiemNhanDuTien=CASE WHEN COALESCE(gd.allocated, 0)>=dh.tongTienPhaiTra THEN UTC_TIMESTAMP(3) ELSE NULL END,
    dh.ngayGhiNhanDoanhThu=CASE
        WHEN COALESCE(gd.allocated, 0)>=dh.tongTienPhaiTra AND dh.trangThaiXuLy='DA_GIAO' THEN UTC_DATE()
        ELSE NULL
    END;

UPDATE `tblDonHang` dh
JOIN (
    SELECT yc.maDH, SUM(ht.tongDaHoan) AS refunded
    FROM `tblYeuCauHoanTien` yc
    INNER JOIN `tblLanHoanTien` ht ON ht.maYCHT=yc.maYCHT
    GROUP BY yc.maDH
) rf ON rf.maDH=dh.maDH
SET dh.soTienDaHoan=rf.refunded,
    dh.trangThaiThanhToan=CASE
        WHEN rf.refunded>=dh.soTienDaThu AND dh.soTienDaThu>0 THEN 'DA_HOAN_TIEN'
        ELSE 'DANG_HOAN_TIEN'
    END;

DELIMITER //
CREATE TRIGGER `trg_NhatKyThanhToan_no_update`
BEFORE UPDATE ON `tblNhatKyThanhToan`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NhatKyThanhToan is append-only';
END//
CREATE TRIGGER `trg_NhatKyThanhToan_no_delete`
BEFORE DELETE ON `tblNhatKyThanhToan`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'NhatKyThanhToan is append-only';
END//
DELIMITER ;
