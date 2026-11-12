<?php require __DIR__ . '/guard.php'; ?>
<section data-admin-panel="analytics" class="space-y-5">
                    <form id="admin-analytics-form" class="flex flex-wrap items-end gap-4 bg-mecha-dark border border-mecha-panel p-5">
                        <label class="form-field">Từ ngày (UTC)<input name="from" type="date" required></label>
                        <label class="form-field">Đến ngày (UTC)<input name="to" type="date" required></label>
                        <button class="button button-primary">Xem báo cáo</button>
                        <a id="admin-revenue-csv" class="button button-secondary" href="#">Tải CSV doanh thu</a>
                        <a id="admin-inventory-csv" class="button button-secondary" href="../api/admin.php?view=inventory-csv">Tải CSV tồn kho</a>
                    </form>
                    <div id="admin-analytics-summary" class="grid md:grid-cols-3 gap-4"></div>
                    <div class="overflow-x-auto bg-mecha-dark border border-mecha-panel p-5"><table class="w-full text-left"><thead><tr><th>Ngày</th><th>Đơn tạo</th><th>Đã giao</th><th>Doanh thu</th><th>Thực thu</th><th>Thực hoàn</th></tr></thead><tbody id="admin-analytics-days"></tbody></table></div>
                </section>
