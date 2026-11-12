<?php require __DIR__ . '/guard.php'; ?>
<section data-admin-panel="orders" class="space-y-5">
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <h2 class="font-orbitron text-xl">Quản lý đơn hàng</h2>
                        <label class="form-field">Lọc trạng thái<select id="admin-order-filter"><option value="">Tất cả</option><option value="CHO_XAC_NHAN">Chờ xác nhận</option><option value="DA_XAC_NHAN">Đã xác nhận</option><option value="DANG_GIAO">Đang giao</option><option value="DA_GIAO">Đã giao</option><option value="DA_HUY">Đã hủy</option></select></label>
                    </div>
                    <div class="overflow-x-auto bg-mecha-dark border border-mecha-panel p-5">
                        <table class="w-full text-left"><thead><tr><th>Mã đơn</th><th>Người nhận</th><th>Ngày đặt</th><th>Thanh toán</th><th>Tổng</th><th>Trạng thái</th><th></th></tr></thead><tbody id="admin-orders"></tbody></table>
                    </div>
                    <section id="admin-order-detail" class="bg-mecha-dark border border-mecha-panel p-6 space-y-4" hidden></section>
                </section>
