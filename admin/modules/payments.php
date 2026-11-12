<?php require __DIR__ . '/guard.php'; ?>
<section data-admin-panel="payments" class="space-y-5">
                    <h2 class="font-orbitron text-xl">Đối soát thanh toán và hoàn tiền</h2>
                    <p class="text-sm text-mecha-gray">Đối soát chuyển khoản, MoMo và COD. COD chỉ được ghi nhận sau khi đơn đã giao; mọi thay đổi đều có nhật ký kiểm toán bất biến.</p>
                    <div class="grid gap-3 md:grid-cols-3 text-sm text-mecha-gray">
                        <p>Chuyển khoản: nhập mã giao dịch ngân hàng và tài khoản nhận.</p>
                        <p>MoMo: trạng thái cập nhật tự động từ IPN đã xác thực chữ ký.</p>
                        <p>COD: nhập mã đối soát hãng vận chuyển và dòng phân bổ.</p>
                    </div>
                    <div id="admin-payments" class="admin-payment-grid" aria-live="polite"></div>
                    <h3 class="font-orbitron text-lg">Yêu cầu hoàn</h3>
                    <div class="overflow-x-auto bg-mecha-dark border border-mecha-panel p-5"><table class="w-full text-left"><thead><tr><th>Mã yêu cầu / đơn</th><th>Nguồn / lý do</th><th>Đề nghị</th><th>Đã duyệt</th><th>Đã hoàn</th><th>Trạng thái</th><th></th></tr></thead><tbody id="admin-refunds"></tbody></table></div>
                    <h3 class="font-orbitron text-lg">Nhật ký thanh toán gần nhất</h3>
                    <div class="overflow-x-auto bg-mecha-dark border border-mecha-panel p-5"><table class="w-full text-left"><thead><tr><th>Thời điểm</th><th>Đơn</th><th>Sự kiện</th><th>Trạng thái</th><th>Lý do / căn cứ</th><th>Quản trị viên</th></tr></thead><tbody id="admin-payment-logs"></tbody></table></div>
                </section>
