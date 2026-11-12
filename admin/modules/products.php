<?php require __DIR__ . '/guard.php'; ?>
<section data-admin-panel="products" class="space-y-6">
                    <form id="admin-product-form" class="grid md:grid-cols-2 xl:grid-cols-3 gap-4 bg-mecha-dark border border-mecha-panel p-7 cut-corner">
                        <h2 class="md:col-span-2 xl:col-span-3 font-orbitron text-xl uppercase">Sản phẩm · thêm / sửa</h2>
                        <label class="form-field">Mã (ASCII, duy nhất)<input name="id" maxlength="32" pattern="[A-Za-z0-9_-]+" required></label>
                        <label class="form-field">Tên<input name="name" maxlength="180" required></label>
                        <label class="form-field">Trạng thái<select name="status"><option value="DANG_BAN">Đang bán</option><option value="SAP_MO_BAN">Sắp mở bán</option><option value="AN">Ẩn</option><option value="NGUNG_KINH_DOANH">Ngừng kinh doanh</option></select></label>
                        <label class="form-field">Giá bán<input name="price" type="number" min="0" step="0.01"></label>
                        <label class="form-field">Giá tham khảo<input name="referencePrice" type="number" min="0" step="0.01"></label>
                        <p class="text-sm text-mecha-gray">Tồn kho được cập nhật riêng ở trang Kho để lưu đầy đủ lịch sử.</p>
                        <label class="form-field">Danh mục<select name="categoryId" required></select></label>
                        <label class="form-field">Phân hạng<select name="gradeId"><option value="">Không áp dụng</option></select></label>
                        <label class="form-field">Dòng Mecha<select name="seriesId"><option value="">Không áp dụng</option></select></label>
                        <label class="form-field">Tỷ lệ<input name="scale" maxlength="20"></label>
                        <label class="form-field">Độ phức tạp (1–5)<input name="complexity" type="number" min="1" max="5"></label>
                        <label class="form-field">Ảnh đại diện (JPEG, PNG, WebP · tối đa 5 MB)<input name="mainImageFile" type="file" accept="image/jpeg,image/png,image/webp"></label>
                        <input name="image" type="hidden">
                        <div id="admin-product-image-preview" class="md:col-span-2 xl:col-span-3 flex flex-wrap gap-3" aria-live="polite"></div>
                        <label class="form-field md:col-span-2">Ảnh bổ sung (JPEG, PNG, WebP · tối đa 5 MB/ảnh)<input name="galleryImageFiles" type="file" accept="image/jpeg,image/png,image/webp" multiple></label>
                        <textarea name="gallery" hidden></textarea>
                        <div id="admin-product-gallery-preview" class="md:col-span-2 xl:col-span-3 flex flex-wrap gap-3" aria-live="polite"></div>
                        <label class="form-field md:col-span-2">Thông số kỹ thuật<textarea name="specifications" rows="3"></textarea></label>
                        <label class="form-field md:col-span-2">Mô tả<textarea name="description" rows="3"></textarea></label>
                        <label class="flex items-center gap-2"><input name="preorder" type="checkbox"> Nhận đặt trước</label>
                        <label class="flex items-center gap-2"><input name="featured" type="checkbox"> Ưu tiên gợi ý</label>
                        <div class="md:col-span-2 xl:col-span-3 flex gap-3">
                            <button class="button button-primary" type="submit">Lưu sản phẩm</button>
                            <button class="button button-secondary" type="reset">Xóa biểu mẫu</button>
                        </div>
                    </form>
                    <div class="overflow-x-auto bg-mecha-dark border border-mecha-panel p-5">
                        <table class="w-full text-left"><thead><tr><th>Mã</th><th>Sản phẩm</th><th>Danh mục</th><th>Giá</th><th>Tồn</th><th>Trạng thái</th><th></th></tr></thead><tbody id="admin-products"></tbody></table>
                    </div>
                </section>
