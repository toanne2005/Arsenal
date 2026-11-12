<?php require __DIR__ . '/guard.php'; ?>
<section data-admin-panel="ai" class="space-y-5">
                    <p class="text-sm text-mecha-gray">Đây là cấu hình quy tắc/FAQ và lịch sử tư vấn. Chưa kết nối dịch vụ mô hình AI bên ngoài.</p>
                    <form id="admin-ai-form" class="grid md:grid-cols-2 xl:grid-cols-3 gap-4 bg-mecha-dark border border-mecha-panel p-6">
                        <input type="hidden" name="id">
                        <h2 class="md:col-span-2 xl:col-span-3 font-orbitron text-xl">Quy tắc và FAQ</h2>
                        <label class="form-field">Khóa cấu hình<input name="key" maxlength="64" required></label>
                        <label class="form-field">Loại<select name="type"><option value="GOI_Y">Gợi ý</option><option value="FAQ">FAQ</option><option value="CONG_TAC">Công tắc</option></select></label>
                        <label class="form-field">Tên mục<input name="name" maxlength="150" required></label>
                        <label class="form-field">Trình độ<select name="level"><option value="">Không áp dụng</option><option value="NGUOI_MOI">Người mới</option><option value="TRUNG_CAP">Trung cấp</option><option value="NANG_CAO">Nâng cao</option></select></label>
                        <label class="form-field">Ngân sách từ<input name="budgetFrom" type="number" min="0" step="0.01"></label>
                        <label class="form-field">Ngân sách đến<input name="budgetTo" type="number" min="0" step="0.01"></label>
                        <label class="form-field">Độ phức tạp tối đa<input name="maxComplexity" type="number" min="1" max="5"></label>
                        <label class="form-field">Dòng Mecha ID (tùy chọn)<input name="seriesId" type="number" min="1"></label>
                        <label class="form-field">Độ ưu tiên<input name="priority" type="number" min="0" step="1" value="0"></label>
                        <label class="form-field md:col-span-2 xl:col-span-3">Nội dung quy tắc<textarea name="ruleContent" rows="3"></textarea></label>
                        <label class="form-field md:col-span-2 xl:col-span-3">Câu hỏi FAQ<textarea name="faqQuestion" rows="2"></textarea></label>
                        <label class="form-field md:col-span-2 xl:col-span-3">Câu trả lời FAQ<textarea name="faqAnswer" rows="3"></textarea></label>
                        <label class="flex items-center gap-2"><input name="active" type="checkbox" checked> Kích hoạt</label>
                        <div class="md:col-span-2 xl:col-span-3 flex gap-3"><button class="button button-primary">Lưu cấu hình</button><button class="button button-secondary" type="reset">Tạo mới</button></div>
                    </form>
                    <div id="admin-ai-rules" class="grid md:grid-cols-2 gap-4"></div>
                    <h3 class="font-orbitron text-lg">Lịch sử tư vấn gần đây</h3>
                    <div class="overflow-x-auto bg-mecha-dark border border-mecha-panel p-5"><table class="w-full text-left"><thead><tr><th>Thời điểm</th><th>Câu hỏi đã lọc</th><th>Phản hồi</th><th>Nguồn</th><th>Hữu ích</th><th>Quy tắc</th></tr></thead><tbody id="admin-ai-history"></tbody></table></div>
                </section>
