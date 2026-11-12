<?php require __DIR__ . '/guard.php'; ?>
<section data-admin-panel="inventory" class="space-y-5">
                    <h2 class="font-orbitron text-xl">Tồn kho</h2>
                    <p class="text-sm text-mecha-gray">Tồn khả dụng đã được trừ khi xác nhận đơn; số lượng đơn đang xử lý chỉ để đối chiếu, không cộng lại vào tồn.</p>
                    <div class="grid lg:grid-cols-2 gap-5">
                        <form id="admin-stock-receipt" class="space-y-3 bg-mecha-dark border border-mecha-panel p-5">
                            <h3 class="font-orbitron">Nhập kho</h3>
                            <label class="form-field">Sản phẩm<select name="productId" required></select></label>
                            <label class="form-field">Số lượng nhập<input name="quantity" type="number" min="1" step="1" required></label>
                            <label class="form-field">Lý do / ghi chú<input name="reason" maxlength="500" required></label>
                            <button class="button button-primary">Ghi nhận nhập</button>
                        </form>
                        <form id="admin-stock-adjustment" class="space-y-3 bg-mecha-dark border border-mecha-panel p-5">
                            <h3 class="font-orbitron">Điều chỉnh kiểm kê</h3>
                            <label class="form-field">Sản phẩm<select name="productId" required></select></label>
                            <label class="form-field">Thay đổi (+/-)<input name="delta" type="number" step="1" required></label>
                            <label class="form-field">Lý do<input name="reason" maxlength="500" required></label>
                            <button class="button button-secondary">Ghi nhận điều chỉnh</button>
                        </form>
                    </div>
                    <div class="overflow-x-auto bg-mecha-dark border border-mecha-panel p-5">
                        <table class="w-full text-left"><thead><tr><th>Mã</th><th>Sản phẩm</th><th>Tồn khả dụng</th><th>Đang xử lý đơn</th></tr></thead><tbody id="admin-inventory"></tbody></table>
                    </div>
                    <div class="overflow-x-auto bg-mecha-dark border border-mecha-panel p-5">
                        <h3 class="font-orbitron mb-3">Lịch sử tồn kho (300 biến động gần nhất)</h3>
                        <table class="w-full text-left"><thead><tr><th>Thời điểm</th><th>Sản phẩm</th><th>Loại</th><th>Trước</th><th>Thay đổi</th><th>Sau</th><th>Đơn</th><th>Lý do</th><th>Người thực hiện</th></tr></thead><tbody id="admin-stock-history"></tbody></table>
                    </div>
                </section>
