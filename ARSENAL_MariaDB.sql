-- ARSENAL | Tạo CSDL cho PHP trên XAMPP
-- MariaDB 10.4+ hoặc MySQL 8.0.16+ để thực thi đầy đủ CHECK. Lược đồ theo Mục 3 của hồ sơ ARSENAL.
-- Nhập từ thẻ Import ở cấp máy chủ phpMyAdmin. File tạo CSDL arsenal và 22 bảng.
-- Dùng cho CSDL mới hoặc nhập lại cùng lược đồ. Bảng cùng tên đã có được bỏ qua.
-- PHP ghi DATETIME(3) theo UTC và quản lý giao dịch đặt đơn, kho, mã giảm và thanh toán.
-- Các khoản thu/hoàn và nhật ký điều chỉnh được ghi theo quy tắc giao dịch ở Mục 3.

SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS `arsenal`
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `arsenal`;

-- 3.1 tblQuanTriVien | Quản trị viên
CREATE TABLE IF NOT EXISTS `tblQuanTriVien` (
    `maQTV` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenDangNhap` VARCHAR(64) NOT NULL,
    `hoTen` VARCHAR(120) NOT NULL,
    `matKhauBam` VARCHAR(255) NOT NULL,
    `trangThai` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'HOAT_DONG',
    `quyen` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `taoLuc` DATETIME(3) NOT NULL,
    PRIMARY KEY (`maQTV`),
    UNIQUE KEY `uq_QuanTriVien_tenDangNhap` (`tenDangNhap`),
    CONSTRAINT `ck_QuanTriVien_trangThai` CHECK (trangThai IN ('HOAT_DONG', 'BI_KHOA')),
    CONSTRAINT `ck_QuanTriVien_quyen` CHECK (JSON_VALID(quyen) = 1 AND JSON_TYPE(quyen) = 'ARRAY')
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.2 tblKhachHang | Khách hàng
CREATE TABLE IF NOT EXISTS `tblKhachHang` (
    `maKH` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hoTen` VARCHAR(120) NOT NULL,
    `dienThoai` VARCHAR(16) NOT NULL,
    `email` VARCHAR(254) NOT NULL,
    `diaChi` VARCHAR(255) NULL,
    `matKhauBam` VARCHAR(255) NOT NULL,
    `dangKyLuc` DATETIME(3) NOT NULL,
    `trangThai` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'HOAT_DONG',
    PRIMARY KEY (`maKH`),
    UNIQUE KEY `uq_KhachHang_email` (`email`),
    CONSTRAINT `ck_KhachHang_trangThai` CHECK (trangThai IN ('HOAT_DONG', 'BI_KHOA'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.3 tblDanhMuc | Danh mục sản phẩm
CREATE TABLE IF NOT EXISTS `tblDanhMuc` (
    `maDM` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenDanhMuc` VARCHAR(120) NOT NULL,
    `moTa` LONGTEXT NULL,
    `hoatDong` TINYINT(1) NOT NULL DEFAULT 1,
    `maDMCha` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`maDM`),
    CONSTRAINT `ck_DanhMuc_hoatDong` CHECK (hoatDong IN (0, 1)),
    CONSTRAINT `fk_DanhMuc_maDMCha` FOREIGN KEY (`maDMCha`)
        REFERENCES `tblDanhMuc` (`maDM`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.4 tblPhanHangGunpla | Phân hạng Gunpla
CREATE TABLE IF NOT EXISTS `tblPhanHangGunpla` (
    `maPhanHang` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kyHieu` VARCHAR(12) NOT NULL,
    `tenPhanHang` VARCHAR(100) NOT NULL,
    `moTa` LONGTEXT NULL,
    `hoatDong` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`maPhanHang`),
    UNIQUE KEY `uq_PhanHangGunpla_kyHieu` (`kyHieu`),
    CONSTRAINT `ck_PhanHangGunpla_hoatDong` CHECK (hoatDong IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.5 tblDongMecha | Dòng Mecha
CREATE TABLE IF NOT EXISTS `tblDongMecha` (
    `maDong` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenDong` VARCHAR(150) NOT NULL,
    `gioiThieu` LONGTEXT NULL,
    `anhDaiDienUrl` VARCHAR(512) NULL,
    `hoatDong` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`maDong`),
    UNIQUE KEY `uq_DongMecha_tenDong` (`tenDong`),
    CONSTRAINT `ck_DongMecha_hoatDong` CHECK (hoatDong IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.6 tblSanPham | Sản phẩm
CREATE TABLE IF NOT EXISTS `tblSanPham` (
    `maSP` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `tenSP` VARCHAR(180) NOT NULL,
    `moTa` LONGTEXT NULL,
    `anhDaiDienUrl` VARCHAR(512) NULL,
    `anhBoSung` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
    `thongSoKyThuat` LONGTEXT NULL,
    `tyLe` VARCHAR(20) NULL,
    `doPhucTapLapRap` TINYINT UNSIGNED NULL,
    `giaBan` DECIMAL(18,2) NULL,
    `tonKhaDung` INT UNSIGNED NOT NULL DEFAULT 0,
    `trangThai` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `giaThamKhao` DECIMAL(18,2) NULL,
    `ngayDuKienPhatHanh` DATE NULL,
    `nhanDatTruoc` TINYINT(1) NOT NULL DEFAULT 0,
    `uuTienGoiY` TINYINT(1) NOT NULL DEFAULT 0,
    `themLuc` DATETIME(3) NOT NULL,
    `maDM` BIGINT UNSIGNED NOT NULL,
    `maPhanHang` BIGINT UNSIGNED NULL,
    `maDong` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`maSP`),
    KEY `ix_SanPham_themLuc` (`themLuc`),
    CONSTRAINT `ck_SanPham_anhBoSung` CHECK (JSON_VALID(anhBoSung) = 1 AND JSON_TYPE(anhBoSung) = 'ARRAY'),
    CONSTRAINT `ck_SanPham_doPhucTapLapRap` CHECK (doPhucTapLapRap BETWEEN 1 AND 5),
    CONSTRAINT `ck_SanPham_giaBan` CHECK (giaBan >= 0),
    CONSTRAINT `ck_SanPham_trangThai` CHECK (trangThai IN ('SAP_MO_BAN', 'DANG_BAN', 'AN', 'NGUNG_KINH_DOANH')),
    CONSTRAINT `ck_SanPham_giaThamKhao` CHECK (giaThamKhao >= 0),
    CONSTRAINT `ck_SanPham_nhanDatTruoc` CHECK (nhanDatTruoc IN (0, 1)),
    CONSTRAINT `ck_SanPham_uuTienGoiY` CHECK (uuTienGoiY IN (0, 1)),
    CONSTRAINT `ck_SanPham_giaDangBan` CHECK (trangThai <> 'DANG_BAN' OR giaBan IS NOT NULL),
    CONSTRAINT `fk_SanPham_maDM` FOREIGN KEY (`maDM`)
        REFERENCES `tblDanhMuc` (`maDM`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_SanPham_maPhanHang` FOREIGN KEY (`maPhanHang`)
        REFERENCES `tblPhanHangGunpla` (`maPhanHang`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_SanPham_maDong` FOREIGN KEY (`maDong`)
        REFERENCES `tblDongMecha` (`maDong`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.8 tblGioHang | Giỏ hàng
CREATE TABLE IF NOT EXISTS `tblGioHang` (
    `maGioHang` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `taoLuc` DATETIME(3) NOT NULL,
    `capNhatLuc` DATETIME(3) NOT NULL,
    `maKH` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`maGioHang`),
    UNIQUE KEY `uq_GioHang_maKH` (`maKH`),
    CONSTRAINT `fk_GioHang_maKH` FOREIGN KEY (`maKH`)
        REFERENCES `tblKhachHang` (`maKH`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.9 tblChiTietGioHang | Chi tiết giỏ hàng
CREATE TABLE IF NOT EXISTS `tblChiTietGioHang` (
    `maCTGH` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `soLuong` INT UNSIGNED NOT NULL,
    `donGiaThamChieu` DECIMAL(18,2) NOT NULL,
    `maGioHang` BIGINT UNSIGNED NOT NULL,
    `maSP` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`maCTGH`),
    UNIQUE KEY `uq_ChiTietGioHang_maGioHang_maSP` (`maGioHang`, `maSP`),
    CONSTRAINT `ck_ChiTietGioHang_soLuong` CHECK (soLuong > 0),
    CONSTRAINT `ck_ChiTietGioHang_donGiaThamChieu` CHECK (donGiaThamChieu >= 0),
    CONSTRAINT `fk_ChiTietGioHang_maGioHang` FOREIGN KEY (`maGioHang`)
        REFERENCES `tblGioHang` (`maGioHang`) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT `fk_ChiTietGioHang_maSP` FOREIGN KEY (`maSP`)
        REFERENCES `tblSanPham` (`maSP`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.10 tblDonHang | Đơn hàng
CREATE TABLE IF NOT EXISTS `tblDonHang` (
    `maDH` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `datLuc` DATETIME(3) NOT NULL,
    `hoTenNhan` VARCHAR(120) NOT NULL,
    `dienThoaiNhan` VARCHAR(16) NOT NULL,
    `diaChiNhan` VARCHAR(255) NOT NULL,
    `emailNhan` VARCHAR(254) NULL,
    `phuongThucThanhToan` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `tienHang` DECIMAL(18,2) NOT NULL,
    `tienGiam` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `tienHangSauGiam` DECIMAL(18,2) GENERATED ALWAYS AS (tienHang - tienGiam) STORED,
    `phiVanChuyen` DECIMAL(18,2) NOT NULL,
    `tongPhaiTra` DECIMAL(18,2) GENERATED ALWAYS AS (tienHang - tienGiam + phiVanChuyen) STORED,
    `trangThaiXuLy` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CHO_XAC_NHAN',
    `maVanDon` VARCHAR(64) NULL,
    `ghiChuGiaoHang` VARCHAR(500) NULL,
    `giaoThanhCongLuc` DATETIME(3) NULL,
    `yeuCauHuyLuc` DATETIME(3) NULL,
    `trangThaiYeuCauHuy` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CHUA_CO',
    `huyLuc` DATETIME(3) NULL,
    `lyDoHuy` VARCHAR(500) NULL,
    `hanChuyenKhoan` DATETIME(3) GENERATED ALWAYS AS (CASE WHEN phuongThucThanhToan = 'CHUYEN_KHOAN' THEN DATE_ADD(datLuc, INTERVAL 24 HOUR) ELSE NULL END) STORED,
    `tenNganHangNhan` VARCHAR(120) NULL,
    `chuTaiKhoanNhan` VARCHAR(120) NULL,
    `soTaiKhoanNhan` VARCHAR(34) NULL,
    `maKH` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`maDH`),
    KEY `ix_DonHang_phuongThucThanhToan_giaoThanhCongLuc` (`phuongThucThanhToan`, `giaoThanhCongLuc`),
    KEY `ix_DonHang_trangThaiXuLy_hanChuyenKhoan` (`trangThaiXuLy`, `hanChuyenKhoan`),
    CONSTRAINT `ck_DonHang_phuongThucThanhToan` CHECK (phuongThucThanhToan IN ('COD', 'CHUYEN_KHOAN')),
    CONSTRAINT `ck_DonHang_tienHang` CHECK (tienHang >= 0),
    CONSTRAINT `ck_DonHang_tienGiam` CHECK (tienGiam BETWEEN 0 AND tienHang),
    CONSTRAINT `ck_DonHang_phiVanChuyen` CHECK (phiVanChuyen > 0),
    CONSTRAINT `ck_DonHang_trangThaiXuLy` CHECK (trangThaiXuLy IN ('CHO_XAC_NHAN', 'DA_XAC_NHAN', 'DANG_GIAO', 'DA_GIAO', 'DA_HUY')),
    CONSTRAINT `ck_DonHang_trangThaiYeuCauHuy` CHECK (trangThaiYeuCauHuy IN ('CHUA_CO', 'CHO_DUYET', 'CHAP_NHAN', 'TU_CHOI')),
    CONSTRAINT `ck_DonHang_tkChuyenKhoan` CHECK (phuongThucThanhToan <> 'CHUYEN_KHOAN' OR (tenNganHangNhan IS NOT NULL AND chuTaiKhoanNhan IS NOT NULL AND soTaiKhoanNhan IS NOT NULL)),
    CONSTRAINT `fk_DonHang_maKH` FOREIGN KEY (`maKH`)
        REFERENCES `tblKhachHang` (`maKH`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.7 tblLichSuTonKho | Lịch sử tồn kho
CREATE TABLE IF NOT EXISTS `tblLichSuTonKho` (
    `maLSTK` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `loaiBienDong` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `soLuongThayDoi` INT NOT NULL,
    `soLuongTruoc` INT UNSIGNED NOT NULL,
    `soLuongSau` INT UNSIGNED NOT NULL,
    `thucHienLuc` DATETIME(3) NOT NULL,
    `lyDo` VARCHAR(500) NOT NULL,
    `maSP` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `maQTVThucHien` BIGINT UNSIGNED NOT NULL,
    `maDH` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (`maLSTK`),
    KEY `ix_LichSuTonKho_maSP_thucHienLuc` (`maSP`, `thucHienLuc`),
    CONSTRAINT `ck_LichSuTonKho_loaiBienDong` CHECK (loaiBienDong IN ('NHAP', 'GIU_DON', 'TRA_HUY', 'TRA_SAU_GIAO', 'DIEU_CHINH')),
    CONSTRAINT `ck_LichSuTonKho_soLuongThayDoi` CHECK (soLuongThayDoi <> 0),
    CONSTRAINT `ck_LichSuTonKho_soLuongSau` CHECK (soLuongSau = CAST(soLuongTruoc AS SIGNED) + soLuongThayDoi),
    CONSTRAINT `fk_LichSuTonKho_maSP` FOREIGN KEY (`maSP`)
        REFERENCES `tblSanPham` (`maSP`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_LichSuTonKho_maQTVThucHien` FOREIGN KEY (`maQTVThucHien`)
        REFERENCES `tblQuanTriVien` (`maQTV`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_LichSuTonKho_maDH` FOREIGN KEY (`maDH`)
        REFERENCES `tblDonHang` (`maDH`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.11 tblChiTietDonHang | Chi tiết đơn hàng
CREATE TABLE IF NOT EXISTS `tblChiTietDonHang` (
    `maCTDH` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `maSPLucDat` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `tenSPLucDat` VARCHAR(180) NOT NULL,
    `soLuong` INT UNSIGNED NOT NULL,
    `donGiaLucDat` DECIMAL(18,2) NOT NULL,
    `thanhTien` DECIMAL(18,2) GENERATED ALWAYS AS (soLuong * donGiaLucDat) STORED,
    `maDH` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `maSP` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`maCTDH`),
    UNIQUE KEY `uq_ChiTietDonHang_maDH_maSP` (`maDH`, `maSP`),
    CONSTRAINT `ck_ChiTietDonHang_soLuong` CHECK (soLuong > 0),
    CONSTRAINT `ck_ChiTietDonHang_donGiaLucDat` CHECK (donGiaLucDat >= 0),
    CONSTRAINT `fk_ChiTietDonHang_maDH` FOREIGN KEY (`maDH`)
        REFERENCES `tblDonHang` (`maDH`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_ChiTietDonHang_maSP` FOREIGN KEY (`maSP`)
        REFERENCES `tblSanPham` (`maSP`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.12 tblGiaoDichThanhToan | Giao dịch thanh toán
CREATE TABLE IF NOT EXISTS `tblGiaoDichThanhToan` (
    `maGDTT` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `soTienThucNhan` DECIMAL(18,2) NOT NULL,
    `phuongThuc` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `thucNhanLuc` DATETIME(3) NOT NULL,
    `ghiNhanLuc` DATETIME(3) NOT NULL,
    `canCuDoiChieu` VARCHAR(500) NOT NULL,
    `maNganHangNhan` VARCHAR(24) NULL,
    `soTaiKhoanNhan` VARCHAR(34) NULL,
    `maGDNganHang` VARCHAR(80) NULL,
    `donViVanChuyen` VARCHAR(50) NULL,
    `maChungTuCOD` VARCHAR(80) NULL,
    `maVanDonDoiSoat` VARCHAR(64) NULL,
    `dongPhanBoCOD` VARCHAR(64) NULL,
    `khoaChungTuThu` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `ketQuaDoiChieu` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `maDH` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `maQTVXacNhan` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`maGDTT`),
    KEY `ix_GiaoDichThanhToan_maDH_thucNhanLuc_maGDTT` (`maDH`, `thucNhanLuc`, `maGDTT`),
    KEY `ix_GiaoDichThanhToan_khoaChungTuThu` (`khoaChungTuThu`),
    CONSTRAINT `ck_GiaoDichThanhToan_soTienThucNhan` CHECK (soTienThucNhan > 0),
    CONSTRAINT `ck_GiaoDichThanhToan_phuongThuc` CHECK (phuongThuc IN ('COD', 'CHUYEN_KHOAN')),
    CONSTRAINT `ck_GiaoDichThanhToan_ghiNhanLuc` CHECK (ghiNhanLuc >= thucNhanLuc),
    CONSTRAINT `ck_GiaoDichThanhToan_ketQuaDoiChieu` CHECK (ketQuaDoiChieu IN ('DU_DIEU_KIEN', 'SAU_HAN', 'DON_DA_HUY')),
    CONSTRAINT `ck_GiaoDichThanhToan_chungTuCK` CHECK (phuongThuc <> 'CHUYEN_KHOAN' OR (maNganHangNhan IS NOT NULL AND soTaiKhoanNhan IS NOT NULL AND maGDNganHang IS NOT NULL)),
    CONSTRAINT `ck_GiaoDichThanhToan_chungTuCOD` CHECK (phuongThuc <> 'COD' OR (donViVanChuyen IS NOT NULL AND maChungTuCOD IS NOT NULL AND (maVanDonDoiSoat IS NOT NULL OR dongPhanBoCOD IS NOT NULL))),
    CONSTRAINT `fk_GiaoDichThanhToan_maDH` FOREIGN KEY (`maDH`)
        REFERENCES `tblDonHang` (`maDH`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_GiaoDichThanhToan_maQTVXacNhan` FOREIGN KEY (`maQTVXacNhan`)
        REFERENCES `tblQuanTriVien` (`maQTV`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.13 tblYeuCauHoanTien | Yêu cầu hoàn tiền
CREATE TABLE IF NOT EXISTS `tblYeuCauHoanTien` (
    `maYCHT` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nguon` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `lyDo` VARCHAR(500) NOT NULL,
    `minhChungUrl` VARCHAR(512) NULL,
    `taoLuc` DATETIME(3) NOT NULL,
    `soTienDeNghi` DECIMAL(18,2) NOT NULL,
    `tienHangCanHoan` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `phiVanChuyenCanHoan` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `tienChuaPhanBoCanHoan` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `soTienCanHoan` DECIMAL(18,2) GENERATED ALWAYS AS (tienHangCanHoan + phiVanChuyenCanHoan + tienChuaPhanBoCanHoan) STORED,
    `trangThai` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `xuLyLuc` DATETIME(3) NULL,
    `maDH` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `maQTVXuLy` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`maYCHT`),
    CONSTRAINT `ck_YeuCauHoanTien_nguon` CHECK (nguon IN ('HUY_DON', 'KHOAN_THU_CAN_TRA', 'SAU_GIAO')),
    CONSTRAINT `ck_YeuCauHoanTien_soTienDeNghi` CHECK (soTienDeNghi > 0),
    CONSTRAINT `ck_YeuCauHoanTien_tienHangCanHoan` CHECK (tienHangCanHoan >= 0),
    CONSTRAINT `ck_YeuCauHoanTien_phiVanChuyenCanHoan` CHECK (phiVanChuyenCanHoan >= 0),
    CONSTRAINT `ck_YeuCauHoanTien_tienChuaPhanBoCanHoan` CHECK (tienChuaPhanBoCanHoan >= 0),
    CONSTRAINT `ck_YeuCauHoanTien_trangThai` CHECK (trangThai IN ('CHO_DUYET', 'CHO_HOAN', 'DANG_HOAN', 'DA_HOAN_XONG', 'TU_CHOI')),
    CONSTRAINT `ck_YeuCauHoanTien_xuLyLuc` CHECK (xuLyLuc IS NULL OR xuLyLuc >= taoLuc),
    CONSTRAINT `fk_YeuCauHoanTien_maDH` FOREIGN KEY (`maDH`)
        REFERENCES `tblDonHang` (`maDH`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_YeuCauHoanTien_maQTVXuLy` FOREIGN KEY (`maQTVXuLy`)
        REFERENCES `tblQuanTriVien` (`maQTV`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.14 tblLanHoanTien | Lần hoàn tiền
CREATE TABLE IF NOT EXISTS `tblLanHoanTien` (
    `maLHT` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tienHangDaHoan` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `phiVanChuyenDaHoan` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `tienChuaPhanBoDaHoan` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `tongDaHoan` DECIMAL(18,2) GENERATED ALWAYS AS (tienHangDaHoan + phiVanChuyenDaHoan + tienChuaPhanBoDaHoan) STORED,
    `phuongThucHoan` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `thucHoanLuc` DATETIME(3) NOT NULL,
    `ghiNhanLuc` DATETIME(3) NOT NULL,
    `nguonChungTuHoan` VARCHAR(80) NOT NULL,
    `maChungTuHoan` VARCHAR(80) NOT NULL,
    `khoaChungTuHoan` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `lyDo` VARCHAR(500) NOT NULL,
    `maYCHT` BIGINT UNSIGNED NOT NULL,
    `maQTVThucHien` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`maLHT`),
    KEY `ix_LanHoanTien_thucHoanLuc` (`thucHoanLuc`),
    KEY `ix_LanHoanTien_khoaChungTuHoan` (`khoaChungTuHoan`),
    CONSTRAINT `ck_LanHoanTien_tienHangDaHoan` CHECK (tienHangDaHoan >= 0),
    CONSTRAINT `ck_LanHoanTien_phiVanChuyenDaHoan` CHECK (phiVanChuyenDaHoan >= 0),
    CONSTRAINT `ck_LanHoanTien_tienChuaPhanBoDaHoan` CHECK (tienChuaPhanBoDaHoan >= 0),
    CONSTRAINT `ck_LanHoanTien_tongDaHoan` CHECK (tienHangDaHoan + phiVanChuyenDaHoan + tienChuaPhanBoDaHoan > 0),
    CONSTRAINT `ck_LanHoanTien_phuongThucHoan` CHECK (phuongThucHoan IN ('TIEN_MAT', 'CHUYEN_KHOAN')),
    CONSTRAINT `ck_LanHoanTien_ghiNhanLuc` CHECK (ghiNhanLuc >= thucHoanLuc),
    CONSTRAINT `fk_LanHoanTien_maYCHT` FOREIGN KEY (`maYCHT`)
        REFERENCES `tblYeuCauHoanTien` (`maYCHT`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_LanHoanTien_maQTVThucHien` FOREIGN KEY (`maQTVThucHien`)
        REFERENCES `tblQuanTriVien` (`maQTV`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.15 tblNhatKyThanhToan | Nhật ký thanh toán
CREATE TABLE IF NOT EXISTS `tblNhatKyThanhToan` (
    `maNKTT` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `loaiSuKien` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `nguon` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `ghiLuc` DATETIME(3) NOT NULL,
    `trangThaiTruoc` VARCHAR(32) NULL,
    `trangThaiSau` VARCHAR(32) NULL,
    `lyDo` VARCHAR(500) NOT NULL,
    `canCu` VARCHAR(500) NULL,
    `maDH` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `maQTV` BIGINT UNSIGNED NULL,
    `maGDTT` BIGINT UNSIGNED NULL,
    `maYCHT` BIGINT UNSIGNED NULL,
    `maLHT` BIGINT UNSIGNED NULL,
    `maNhatKyGoc` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`maNKTT`),
    UNIQUE KEY `uq_NhatKyThanhToan_loaiSuKien_maNhatKyGoc` (`loaiSuKien`, `maNhatKyGoc`),
    KEY `ix_NhatKyThanhToan_maDH_ghiLuc` (`maDH`, `ghiLuc`),
    CONSTRAINT `ck_NhatKyThanhToan_loaiSuKien` CHECK (loaiSuKien IN ('GHI_THU', 'TAO_YEU_CAU', 'CAP_NHAT_YEU_CAU', 'DUYET_YEU_CAU', 'TU_CHOI_YEU_CAU', 'GHI_HOAN', 'DOI_TRANG_THAI', 'VO_HIEU', 'THAY_THE')),
    CONSTRAINT `ck_NhatKyThanhToan_nguon` CHECK (nguon IN ('QUAN_TRI', 'TU_DONG')),
    CONSTRAINT `ck_NhatKyThanhToan_nguoiThaoTac` CHECK (nguon <> 'QUAN_TRI' OR maQTV IS NOT NULL),
    CONSTRAINT `ck_NhatKyThanhToan_doiTuong` CHECK ((maGDTT IS NOT NULL) + (maYCHT IS NOT NULL) + (maLHT IS NOT NULL) <= 1),
    CONSTRAINT `ck_NhatKyThanhToan_dieuChinh` CHECK ((loaiSuKien IN ('VO_HIEU', 'THAY_THE') AND maNhatKyGoc IS NOT NULL AND canCu IS NOT NULL) OR (loaiSuKien NOT IN ('VO_HIEU', 'THAY_THE') AND maNhatKyGoc IS NULL)),
    CONSTRAINT `fk_NhatKyThanhToan_maDH` FOREIGN KEY (`maDH`)
        REFERENCES `tblDonHang` (`maDH`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_NhatKyThanhToan_maQTV` FOREIGN KEY (`maQTV`)
        REFERENCES `tblQuanTriVien` (`maQTV`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_NhatKyThanhToan_maGDTT` FOREIGN KEY (`maGDTT`)
        REFERENCES `tblGiaoDichThanhToan` (`maGDTT`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_NhatKyThanhToan_maYCHT` FOREIGN KEY (`maYCHT`)
        REFERENCES `tblYeuCauHoanTien` (`maYCHT`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_NhatKyThanhToan_maLHT` FOREIGN KEY (`maLHT`)
        REFERENCES `tblLanHoanTien` (`maLHT`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_NhatKyThanhToan_maNhatKyGoc` FOREIGN KEY (`maNhatKyGoc`)
        REFERENCES `tblNhatKyThanhToan` (`maNKTT`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.16 tblYeuCauDatTruoc | Yêu cầu đặt trước
CREATE TABLE IF NOT EXISTS `tblYeuCauDatTruoc` (
    `maYCDT` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `hoTenNguoiDat` VARCHAR(120) NOT NULL,
    `dienThoaiLienHe` VARCHAR(16) NOT NULL,
    `emailLienHe` VARCHAR(254) NULL,
    `soLuong` INT UNSIGNED NOT NULL,
    `guiLuc` DATETIME(3) NOT NULL,
    `trangThai` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CHO_XAC_NHAN',
    `soLanLienHe` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `lienHeDauLuc` DATETIME(3) NULL,
    `lienHeGanNhatLuc` DATETIME(3) NULL,
    `hanPhanHoi` DATETIME(3) GENERATED ALWAYS AS (DATE_ADD(lienHeDauLuc, INTERVAL 7 DAY)) STORED,
    `yeuCauHuyLuc` DATETIME(3) NULL,
    `ghiChu` VARCHAR(500) NULL,
    `chuyenDonLuc` DATETIME(3) NULL,
    `maSP` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `maDHHinhThanh` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    PRIMARY KEY (`maYCDT`),
    UNIQUE KEY `uq_YeuCauDatTruoc_maDHHinhThanh` (`maDHHinhThanh`),
    CONSTRAINT `ck_YeuCauDatTruoc_soLuong` CHECK (soLuong > 0),
    CONSTRAINT `ck_YeuCauDatTruoc_trangThai` CHECK (trangThai IN ('CHO_XAC_NHAN', 'DA_XAC_NHAN', 'DA_HUY', 'DA_CHUYEN_DON')),
    CONSTRAINT `ck_YeuCauDatTruoc_soLanLienHe` CHECK (soLanLienHe <= 2),
    CONSTRAINT `fk_YeuCauDatTruoc_maSP` FOREIGN KEY (`maSP`)
        REFERENCES `tblSanPham` (`maSP`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_YeuCauDatTruoc_maDHHinhThanh` FOREIGN KEY (`maDHHinhThanh`)
        REFERENCES `tblDonHang` (`maDH`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.17 tblMaGiamGia | Mã giảm giá
CREATE TABLE IF NOT EXISTS `tblMaGiamGia` (
    `maCode` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `loaiGiam` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `giaTriGiam` DECIMAL(18,2) NOT NULL,
    `tienHangToiThieu` DECIMAL(18,2) NOT NULL DEFAULT 0,
    `mucGiamToiDa` DECIMAL(18,2) NULL,
    `luotToiDa` INT UNSIGNED NOT NULL,
    `luotDangGiu` INT UNSIGNED NOT NULL DEFAULT 0,
    `luotDaSuDung` INT UNSIGNED NOT NULL DEFAULT 0,
    `gioiHanTheoDienThoai` INT UNSIGNED NOT NULL,
    `batDauLuc` DATETIME(3) NOT NULL,
    `ketThucLuc` DATETIME(3) NOT NULL,
    `hoatDong` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`maCode`),
    CONSTRAINT `ck_MaGiamGia_loaiGiam` CHECK (loaiGiam IN ('PHAN_TRAM', 'SO_TIEN')),
    CONSTRAINT `ck_MaGiamGia_giaTriGiam` CHECK (giaTriGiam > 0 AND (loaiGiam <> 'PHAN_TRAM' OR giaTriGiam <= 100)),
    CONSTRAINT `ck_MaGiamGia_tienHangToiThieu` CHECK (tienHangToiThieu >= 0),
    CONSTRAINT `ck_MaGiamGia_mucGiamToiDa` CHECK (mucGiamToiDa > 0),
    CONSTRAINT `ck_MaGiamGia_luotToiDa` CHECK (luotToiDa > 0),
    CONSTRAINT `ck_MaGiamGia_luotDaSuDung` CHECK (luotDangGiu + luotDaSuDung <= luotToiDa),
    CONSTRAINT `ck_MaGiamGia_gioiHanTheoDienThoai` CHECK (gioiHanTheoDienThoai > 0),
    CONSTRAINT `ck_MaGiamGia_ketThucLuc` CHECK (ketThucLuc > batDauLuc),
    CONSTRAINT `ck_MaGiamGia_hoatDong` CHECK (hoatDong IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.18 tblLichSuApDungMaGiamGia | Lịch sử áp dụng mã giảm giá
CREATE TABLE IF NOT EXISTS `tblLichSuApDungMaGiamGia` (
    `maLuotApDung` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `dienThoaiChuanHoa` VARCHAR(16) NOT NULL,
    `tienGiam` DECIMAL(18,2) NOT NULL,
    `giuLuc` DATETIME(3) NOT NULL,
    `suDungLuc` DATETIME(3) NULL,
    `hoanLuotLuc` DATETIME(3) NULL,
    `trangThaiLuot` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'DANG_GIU',
    `maCode` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `maDH` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`maLuotApDung`),
    UNIQUE KEY `uq_LichSuApDungMaGiamGia_maDH` (`maDH`),
    KEY `ix_LichSuApDungMaGiamGia_maCode_dienThoaiChuanHoa_trangThaiLuot` (`maCode`, `dienThoaiChuanHoa`, `trangThaiLuot`),
    CONSTRAINT `ck_LichSuApDungMaGiamGia_tienGiam` CHECK (tienGiam >= 0),
    CONSTRAINT `ck_LichSuApDungMaGiamGia_trangThaiLuot` CHECK (trangThaiLuot IN ('DANG_GIU', 'DA_SU_DUNG', 'DA_HOAN_LUOT')),
    CONSTRAINT `fk_LichSuApDungMaGiamGia_maCode` FOREIGN KEY (`maCode`)
        REFERENCES `tblMaGiamGia` (`maCode`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_LichSuApDungMaGiamGia_maDH` FOREIGN KEY (`maDH`)
        REFERENCES `tblDonHang` (`maDH`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.19 tblDanhGia | Đánh giá sản phẩm
CREATE TABLE IF NOT EXISTS `tblDanhGia` (
    `maDanhGia` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenNguoiDanhGia` VARCHAR(120) NOT NULL,
    `diemSao` TINYINT UNSIGNED NOT NULL,
    `noiDung` LONGTEXT NULL,
    `guiLuc` DATETIME(3) NOT NULL,
    `trangThai` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'CHO_DUYET',
    `duyetLuc` DATETIME(3) NULL,
    `lyDoKiemDuyet` VARCHAR(500) NULL,
    `maSP` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `maDH` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `maQTVKiemDuyet` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`maDanhGia`),
    UNIQUE KEY `uq_DanhGia_maDH_maSP` (`maDH`, `maSP`),
    CONSTRAINT `ck_DanhGia_diemSao` CHECK (diemSao BETWEEN 1 AND 5),
    CONSTRAINT `ck_DanhGia_trangThai` CHECK (trangThai IN ('CHO_DUYET', 'DA_DUYET', 'TU_CHOI')),
    CONSTRAINT `fk_DanhGia_maSP` FOREIGN KEY (`maSP`)
        REFERENCES `tblSanPham` (`maSP`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_DanhGia_maDH` FOREIGN KEY (`maDH`)
        REFERENCES `tblDonHang` (`maDH`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_DanhGia_maQTVKiemDuyet` FOREIGN KEY (`maQTVKiemDuyet`)
        REFERENCES `tblQuanTriVien` (`maQTV`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_DanhGia_ChiTietDonHang` FOREIGN KEY (`maDH`, `maSP`)
        REFERENCES `tblChiTietDonHang` (`maDH`, `maSP`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.20 tblDanhSachYeuThich | Mục yêu thích
CREATE TABLE IF NOT EXISTS `tblDanhSachYeuThich` (
    `maYeuThich` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `danhDauLuc` DATETIME(3) NOT NULL,
    `maKH` BIGINT UNSIGNED NOT NULL,
    `maSP` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`maYeuThich`),
    UNIQUE KEY `uq_DanhSachYeuThich_maKH_maSP` (`maKH`, `maSP`),
    CONSTRAINT `fk_DanhSachYeuThich_maKH` FOREIGN KEY (`maKH`)
        REFERENCES `tblKhachHang` (`maKH`) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT `fk_DanhSachYeuThich_maSP` FOREIGN KEY (`maSP`)
        REFERENCES `tblSanPham` (`maSP`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.21 tblQuyTacGoiYAI | Quy tắc và cấu hình tư vấn AI
CREATE TABLE IF NOT EXISTS `tblQuyTacGoiYAI` (
    `maQuyTacAI` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `khoaCauHinh` VARCHAR(64) NOT NULL,
    `loaiCauHinh` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `tenMuc` VARCHAR(150) NOT NULL,
    `trinhDo` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `nganSachTu` DECIMAL(18,2) NULL,
    `nganSachDen` DECIMAL(18,2) NULL,
    `doPhucTapToiDa` TINYINT UNSIGNED NULL,
    `noiDungQuyTac` LONGTEXT NULL,
    `cauHoiFAQ` VARCHAR(500) NULL,
    `cauTraLoiFAQ` LONGTEXT NULL,
    `kichHoat` TINYINT(1) NOT NULL DEFAULT 1,
    `doUuTien` INT UNSIGNED NOT NULL DEFAULT 0,
    `capNhatLuc` DATETIME(3) NOT NULL,
    `maDong` BIGINT UNSIGNED NULL,
    `maQTVCapNhat` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`maQuyTacAI`),
    UNIQUE KEY `uq_QuyTacGoiYAI_khoaCauHinh` (`khoaCauHinh`),
    CONSTRAINT `ck_QuyTacGoiYAI_loaiCauHinh` CHECK (loaiCauHinh IN ('GOI_Y', 'FAQ', 'CONG_TAC')),
    CONSTRAINT `ck_QuyTacGoiYAI_trinhDo` CHECK (trinhDo IN ('NGUOI_MOI', 'TRUNG_CAP', 'NANG_CAO')),
    CONSTRAINT `ck_QuyTacGoiYAI_nganSachTu` CHECK (nganSachTu >= 0),
    CONSTRAINT `ck_QuyTacGoiYAI_nganSachDen` CHECK (nganSachDen IS NULL OR (nganSachDen >= 0 AND (nganSachTu IS NULL OR nganSachDen >= nganSachTu))),
    CONSTRAINT `ck_QuyTacGoiYAI_doPhucTapToiDa` CHECK (doPhucTapToiDa BETWEEN 1 AND 5),
    CONSTRAINT `ck_QuyTacGoiYAI_kichHoat` CHECK (kichHoat IN (0, 1)),
    CONSTRAINT `ck_QuyTacGoiYAI_duLieuFAQ` CHECK (loaiCauHinh <> 'FAQ' OR (cauHoiFAQ IS NOT NULL AND cauTraLoiFAQ IS NOT NULL)),
    CONSTRAINT `ck_QuyTacGoiYAI_trinhDoQuyTac` CHECK (loaiCauHinh = 'GOI_Y' OR trinhDo IS NULL),
    CONSTRAINT `fk_QuyTacGoiYAI_maDong` FOREIGN KEY (`maDong`)
        REFERENCES `tblDongMecha` (`maDong`) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT `fk_QuyTacGoiYAI_maQTVCapNhat` FOREIGN KEY (`maQTVCapNhat`)
        REFERENCES `tblQuanTriVien` (`maQTV`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 3.22 tblLichSuTuVanAI | Lịch sử tư vấn AI
CREATE TABLE IF NOT EXISTS `tblLichSuTuVanAI` (
    `maTuVan` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `taoLuc` DATETIME(3) NOT NULL,
    `cauHoiDaLoc` LONGTEXT NOT NULL,
    `phanHoiDaLoc` LONGTEXT NOT NULL,
    `sanPhamGoiYSnapshot` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `nguonPhanHoi` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `huuIch` TINYINT(1) NULL,
    `maQuyTacAI` BIGINT UNSIGNED NULL,
    PRIMARY KEY (`maTuVan`),
    KEY `ix_LichSuTuVanAI_taoLuc` (`taoLuc`),
    CONSTRAINT `ck_LichSuTuVanAI_sanPhamGoiYSnapshot` CHECK (JSON_VALID(sanPhamGoiYSnapshot) = 1 AND JSON_TYPE(sanPhamGoiYSnapshot) = 'ARRAY'),
    CONSTRAINT `ck_LichSuTuVanAI_nguonPhanHoi` CHECK (nguonPhanHoi IN ('AI', 'DU_PHONG')),
    CONSTRAINT `ck_LichSuTuVanAI_huuIch` CHECK (huuIch IN (0, 1)),
    CONSTRAINT `fk_LichSuTuVanAI_maQuyTacAI` FOREIGN KEY (`maQuyTacAI`)
        REFERENCES `tblQuyTacGoiYAI` (`maQuyTacAI`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
