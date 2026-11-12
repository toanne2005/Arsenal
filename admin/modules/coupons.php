<?php require __DIR__ . '/guard.php'; ?>
<section data-admin-panel="coupons" class="space-y-5">
                    <form id="admin-coupon-form" class="grid md:grid-cols-2 xl:grid-cols-3 gap-4 bg-mecha-dark border border-mecha-panel p-6">
                        <h2 class="md:col-span-2 xl:col-span-3 font-orbitron text-xl">Mã giảm giá · tạo / sửa</h2>
                        <label class="form-field">Mã (ASCII)<input name="code" maxlength="32" pattern="[A-Za-z0-9_-]+" required></label>
                        <label class="form-field">Kiểu giảm<select name="type"><option value="SO_TIEN">Số tiền</option><option value="PHAN_TRAM">Phần trăm</option></select></label>
                        <label class="form-field">Giá trị<input name="value" type="number" min="0.01" step="0.01" required></label>
                        <label class="form-field">Đơn hàng tối thiểu<input name="minimumOrder" type="number" min="0" step="0.01" value="0"></label>
                        <label class="form-field">Mức giảm tối đa (tùy chọn)<input name="maximumDiscount" type="number" min="0.01" step="0.01"></label>
                        <label class="form-field">Tổng lượt tối đa<input name="maximumUses" type="number" min="1" step="1" required></label>
                        <label class="form-field">Giới hạn mỗi số điện thoại<input name="perPhoneLimit" type="number" min="1" step="1" required></label>
                        <label class="form-field">Bắt đầu (giờ địa phương)<input name="startsAt" type="datetime-local" required></label>
                        <label class="form-field">Kết thúc (giờ địa phương)<input name="endsAt" type="datetime-local" required></label>
                        <label class="flex items-center gap-2"><input name="active" type="checkbox" checked> Đang hoạt động</label>
                        <div class="md:col-span-2 xl:col-span-3 flex gap-3"><button class="button button-primary">Lưu mã</button><button class="button button-secondary" type="reset">Tạo mới</button></div>
                    </form>
                    <div class="overflow-x-auto bg-mecha-dark border border-mecha-panel p-5"><table class="w-full text-left"><thead><tr><th>Mã</th><th>Loại / giá trị</th><th>Lượt giữ / dùng / tối đa</th><th>Thời hạn</th><th>Trạng thái</th><th></th></tr></thead><tbody id="admin-coupons"></tbody></table></div>
                </section>
