(() => {
    const message = document.getElementById('admin-message');
    let csrfToken = '';
    let admin = null;
    let catalog = null;
    const productPreviewUrls = [];
    let seriesPreviewUrl = '';

    function notify(text, isError = false) {
        message.textContent = text;
        message.classList.toggle('text-red-400', isError);
        message.classList.toggle('text-mecha-gold', !isError);
    }

    async function getState(view = '', parameters = {}) {
        const query = new URLSearchParams({ view, ...parameters });
        const url = view ? `../api/admin.php?${query}` : '../api/admin.php';
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Không thể tải dữ liệu quản trị.');
        if (result.csrfToken) csrfToken = result.csrfToken;
        return result;
    }

    async function post(action, data = {}) {
        const response = await fetch('../api/admin.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ action, ...data })
        });
        const result = await response.json();
        if (result.csrfToken) csrfToken = result.csrfToken;
        if (!response.ok) throw new Error(result.message || 'Thao tác không thành công.');
        return result;
    }

    async function postPayment(url, data) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Không thể đối soát khoản thu.');
        return result;
    }

    function addCell(row, value) {
        const cell = document.createElement('td');
        cell.className = 'border-b border-mecha-panel p-2 text-mecha-gray';
        cell.textContent = String(value ?? '');
        row.append(cell);
        return cell;
    }

    function addOption(select, value, label) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        select.append(option);
    }

    function formatMoney(value) {
        return `${Number(value || 0).toLocaleString('vi-VN')} ₫`;
    }

    function formatDate(value) {
        if (!value) return '—';
        const date = new Date(`${String(value).replace(' ', 'T')}Z`);
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('vi-VN');
    }

    function localDateTimeValue(date = new Date(), includeSeconds = false) {
        const shifted = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
        return shifted.toISOString().slice(0, includeSeconds ? 19 : 16);
    }

    function utcInputValue(value) {
        if (!value) return '';
        const date = new Date(`${String(value).replace(' ', 'T')}Z`);
        return Number.isNaN(date.getTime()) ? '' : localDateTimeValue(date);
    }

    function utcIsoFromLocal(value) {
        const date = new Date(value);
        if (!value || Number.isNaN(date.getTime())) throw new Error('Thời điểm không hợp lệ.');
        return date.toISOString().replace(/\.\d{3}Z$/, 'Z');
    }

    function inputField(parent, labelText, name, type = 'text', required = true, value = '') {
        const label = document.createElement('label');
        label.className = 'form-field';
        label.textContent = labelText;
        const input = document.createElement('input');
        input.name = name;
        input.type = type;
        input.required = required;
        input.value = value;
        if (type === 'number') input.step = '0.01';
        if (type === 'datetime-local') input.step = '1';
        label.append(input);
        parent.append(label);
        return input;
    }

    function addActionButton(parent, text, callback) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'button button-secondary';
        button.textContent = text;
        button.addEventListener('click', callback);
        parent.append(button);
        return button;
    }

    function renderSeriesImagePreview(form) {
        const preview = form.querySelector('[data-series-image-preview]');
        if (!preview) return;
        if (seriesPreviewUrl) URL.revokeObjectURL(seriesPreviewUrl);
        seriesPreviewUrl = '';
        preview.replaceChildren();

        const file = form.elements.namedItem('imageFile').files[0];
        const url = file ? URL.createObjectURL(file) : form.elements.namedItem('image').value.trim();
        if (!url) return;
        if (file) seriesPreviewUrl = url;

        const image = document.createElement('img');
        image.src = url;
        image.alt = 'Ảnh xem trước dòng Mecha';
        image.addEventListener('error', () => {
            preview.replaceChildren();
            const text = document.createElement('p');
            text.className = 'text-sm text-red-400';
            text.textContent = 'Không thể tải ảnh xem trước. Hãy kiểm tra URL hoặc chọn ảnh khác.';
            preview.append(text);
        }, { once: true });
        preview.append(image);
    }

    function renderProductImagePreviews() {
        const form = document.getElementById('admin-product-form');
        if (!form) return;
        productPreviewUrls.splice(0).forEach(url => URL.revokeObjectURL(url));

        function createRemoveButton(label, remove) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'admin-image-remove';
            button.textContent = '×';
            button.setAttribute('aria-label', `Xóa ${label}`);
            button.title = `Xóa ${label}`;
            button.addEventListener('click', remove);
            return button;
        }

        const mainPreview = document.getElementById('admin-product-image-preview');
        mainPreview.replaceChildren();
        const mainImageInput = form.elements.namedItem('mainImageFile');
        const mainFile = mainImageInput.files[0];
        const mainUrl = mainFile ? URL.createObjectURL(mainFile) : form.elements.namedItem('image').value;
        if (mainFile) productPreviewUrls.push(mainUrl);
        if (mainUrl) {
            const figure = document.createElement('figure');
            figure.className = 'admin-image-preview';
            const image = document.createElement('img');
            image.src = mainUrl;
            image.alt = 'Ảnh đại diện sản phẩm';
            image.className = 'h-24 w-24 object-contain';
            figure.append(
                image,
                createRemoveButton('ảnh đại diện', () => {
                    if (mainFile) mainImageInput.value = '';
                    else form.elements.namedItem('image').value = '';
                    renderProductImagePreviews();
                })
            );
            mainPreview.append(figure);
        }

        const galleryPreview = document.getElementById('admin-product-gallery-preview');
        galleryPreview.replaceChildren();
        const galleryInput = form.elements.namedItem('gallery');
        const galleryUrls = String(galleryInput.value || '')
            .split(/\r?\n/).map(url => url.trim()).filter(Boolean);
        const galleryFileInput = form.elements.namedItem('galleryImageFiles');
        const galleryFiles = [...galleryFileInput.files];
        const entries = [
            ...galleryUrls.map((url, index) => ({ url, name: 'Ảnh hiện có', type: 'saved', index })),
            ...galleryFiles.map((file, index) => {
                const url = URL.createObjectURL(file);
                productPreviewUrls.push(url);
                return { url, name: file.name, type: 'selected', index };
            })
        ];
        for (const entry of entries) {
            const figure = document.createElement('figure');
            figure.className = 'admin-image-preview flex items-center gap-2 p-2';
            const image = document.createElement('img');
            image.src = entry.url;
            image.alt = entry.name;
            image.className = 'h-16 w-16 object-contain';
            const caption = document.createElement('figcaption');
            caption.className = 'max-w-40 break-all text-xs text-mecha-gray';
            caption.textContent = entry.name;
            figure.append(
                image,
                caption,
                createRemoveButton(entry.name, () => {
                    if (entry.type === 'saved') {
                        galleryInput.value = galleryUrls.filter((_, index) => index !== entry.index).join('\n');
                    } else {
                        const transfer = new DataTransfer();
                        galleryFiles.forEach((file, index) => {
                            if (index !== entry.index) transfer.items.add(file);
                        });
                        galleryFileInput.files = transfer.files;
                    }
                    renderProductImagePreviews();
                })
            );
            galleryPreview.append(figure);
        }
    }

    async function uploadProductImages(form) {
        const mainFile = form.elements.namedItem('mainImageFile').files[0];
        const galleryFiles = [...form.elements.namedItem('galleryImageFiles').files];
        if (!mainFile && galleryFiles.length === 0) {
            return { mainImage: null, galleryImages: [] };
        }
        const body = new FormData();
        if (mainFile) body.append('mainImage', mainFile);
        for (const file of galleryFiles) body.append('galleryImages[]', file);
        const response = await fetch('../api/admin-upload.php', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
            body
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Không thể tải ảnh sản phẩm lên.');
        return result;
    }

    function populateProductSelects() {
        const form = document.getElementById('admin-product-form');
        const category = form.elements.namedItem('categoryId');
        const grade = form.elements.namedItem('gradeId');
        const series = form.elements.namedItem('seriesId');
        category.replaceChildren();
        grade.replaceChildren();
        series.replaceChildren();
        addOption(grade, '', 'Không áp dụng');
        addOption(series, '', 'Không áp dụng');
        for (const row of catalog.categories.filter(item => Number(item.active))) addOption(category, row.id, row.name);
        for (const row of catalog.grades.filter(item => Number(item.active))) addOption(grade, row.id, `${row.code} — ${row.name}`);
        for (const row of catalog.series.filter(item => Number(item.active))) addOption(series, row.id, row.name);
    }

    function renderProducts() {
        const tbody = document.getElementById('admin-products');
        tbody.replaceChildren();
        for (const product of catalog.products) {
            const row = document.createElement('tr');
            const category = catalog.categories.find(item => String(item.id) === String(product.categoryId));
            addCell(row, product.id);
            addCell(row, product.name);
            addCell(row, category?.name || '—');
            addCell(row, product.price === null ? '—' : `${Number(product.price).toLocaleString('vi-VN')} ₫`);
            addCell(row, product.stock);
            addCell(row, product.status);
            const actions = document.createElement('td');
            actions.className = 'border-b border-mecha-panel p-2';
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'button button-secondary';
            edit.textContent = 'Sửa';
            edit.addEventListener('click', () => editProduct(product));
            actions.append(edit);
            row.append(actions);
            tbody.append(row);
        }
    }

    function editProduct(product) {
        const form = document.getElementById('admin-product-form');
        const gallery = JSON.parse(product.gallery || '[]');
        for (const [key, value] of Object.entries({
            id: product.id,
            name: product.name,
            status: product.status,
            price: product.price,
            referencePrice: product.referencePrice,
            categoryId: product.categoryId,
            gradeId: product.gradeId,
            seriesId: product.seriesId,
            scale: product.scale,
            complexity: product.complexity,
            image: product.image,
            gallery: gallery.join('\n'),
            specifications: product.specifications,
            description: product.description
        })) {
            form.elements.namedItem(key).value = value ?? '';
        }
        form.elements.namedItem('preorder').checked = Number(product.preorder) === 1;
        form.elements.namedItem('featured').checked = Number(product.featured) === 1;
        form.elements.namedItem('mainImageFile').value = '';
        form.elements.namedItem('galleryImageFiles').value = '';
        renderProductImagePreviews();
        form.dataset.update = 'true';
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function renderReferenceList(kind) {
        const config = {
            category: { rows: catalog.categories, target: 'admin-categories' },
            grade: { rows: catalog.grades, target: 'admin-grades' },
            series: { rows: catalog.series, target: 'admin-series' }
        }[kind];
        const container = document.getElementById(config.target);
        container.replaceChildren();
        for (const item of config.rows) {
            const row = document.createElement('div');
            row.className = 'flex items-center justify-between gap-2 border-b border-mecha-panel py-2';
            if (kind === 'series' && item.image) {
                const image = document.createElement('img');
                image.src = item.image;
                image.alt = '';
                image.className = 'h-12 w-16 shrink-0 border border-mecha-panel object-cover';
                row.append(image);
            }
            const label = document.createElement('span');
            label.className = 'text-mecha-gray';
            label.textContent = `${item.code ? `${item.code} — ` : ''}${item.name} · ${Number(item.active) ? 'Đang hoạt động' : 'Đã ẩn'}`;
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'button button-secondary';
            edit.textContent = 'Sửa';
            edit.addEventListener('click', () => {
                const form = document.querySelector(`[data-reference-form][data-kind="${kind}"]`);
                for (const [key, value] of Object.entries({
                    id: item.id,
                    name: item.name,
                    code: item.code,
                    description: item.description,
                    image: item.image
                })) {
                    const input = form.elements.namedItem(key);
                    if (input) input.value = value ?? '';
                }
                const imageFile = form.elements.namedItem('imageFile');
                if (imageFile) imageFile.value = '';
                renderSeriesImagePreview(form);
                form.elements.namedItem('active').checked = Number(item.active) === 1;
                form.dataset.update = 'true';
            });
            row.append(label, edit);
            container.append(row);
        }
    }

    async function loadCatalog() {
        const [products, references] = await Promise.all([
            can('SAN_PHAM') ? getState('catalog') : Promise.resolve(null),
            can('DANH_MUC') ? getState('references') : Promise.resolve(null)
        ]);
        catalog = {
            products: products?.products || [],
            categories: references?.categories || products?.categories || [],
            grades: references?.grades || products?.grades || [],
            series: references?.series || products?.series || []
        };
        if (can('SAN_PHAM') && document.getElementById('admin-product-form')) {
            populateProductSelects();
            renderProducts();
        }
        if (can('DANH_MUC') && document.getElementById('admin-categories')) {
            renderReferenceList('category');
            renderReferenceList('grade');
            renderReferenceList('series');
        }
    }

    function can(permission) {
        return admin.permissions.includes('*') || admin.permissions.includes('ADMIN') || admin.permissions.includes(permission);
    }

    async function loadCustomers() {
        const result = await getState('customers');
        const tbody = document.getElementById('admin-customers');
        tbody.replaceChildren();
        for (const customer of result.customers || []) {
            const row = document.createElement('tr');
            const contact = document.createElement('td');
            contact.className = 'border-b border-mecha-panel p-2 text-mecha-gray';
            contact.textContent = `${customer.phone} · ${customer.email}`;
            addCell(row, customer.name);
            row.append(contact);
            addCell(row, customer.orderCount);
            addCell(row, customer.status);
            const actions = document.createElement('td');
            actions.className = 'border-b border-mecha-panel p-2';
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'button button-secondary';
            toggle.textContent = customer.status === 'HOAT_DONG' ? 'Khóa' : 'Mở khóa';
            toggle.addEventListener('click', async () => {
                try {
                    await post('customer-status', {
                        customerId: customer.id,
                        status: customer.status === 'HOAT_DONG' ? 'BI_KHOA' : 'HOAT_DONG'
                    });
                    await loadCustomers();
                    notify('Đã cập nhật trạng thái khách hàng.');
                } catch (error) {
                    notify(error.message, true);
                }
            });
            actions.append(toggle);
            row.append(actions);
            tbody.append(row);
        }
    }

    async function loadOrders() {
        const status = document.getElementById('admin-order-filter').value;
        const result = await getState('orders', status ? { status } : {});
        const tbody = document.getElementById('admin-orders');
        tbody.replaceChildren();
        for (const order of result.orders || []) {
            const row = document.createElement('tr');
            addCell(row, order.id);
            addCell(row, order.customerName);
            addCell(row, new Date(`${String(order.placedAt).replace(' ', 'T')}Z`).toLocaleString('vi-VN'));
            addCell(row, order.paymentMethod);
            addCell(row, `${Number(order.total).toLocaleString('vi-VN')} ₫`);
            addCell(row, order.status);
            const action = document.createElement('td');
            action.className = 'border-b border-mecha-panel p-2';
            const open = document.createElement('button');
            open.type = 'button';
            open.className = 'button button-secondary';
            open.textContent = 'Chi tiết';
            open.addEventListener('click', () => loadOrderDetail(order.id));
            action.append(open);
            row.append(action);
            tbody.append(row);
        }
    }

    async function loadOrderDetail(orderId) {
        const result = await getState('order', { id: orderId });
        const order = result.order;
        const panel = document.getElementById('admin-order-detail');
        panel.replaceChildren();
        panel.hidden = false;
        const heading = document.createElement('h3');
        heading.className = 'font-orbitron text-xl';
        heading.textContent = `Đơn ${order.maDH}`;
        panel.append(heading);
        const summary = document.createElement('p');
        summary.className = 'text-mecha-gray';
        summary.textContent = `${order.hoTenNhan} · ${order.dienThoaiNhan} · ${order.diaChiNhan} · ${order.phuongThucThanhToan} · ${order.trangThaiXuLy} · Tổng ${Number(order.tongPhaiTra).toLocaleString('vi-VN')} ₫ · Đã thu ${Number(order.totalReceived).toLocaleString('vi-VN')} ₫ · Đã hoàn ${Number(order.totalRefunded).toLocaleString('vi-VN')} ₫`;
        panel.append(summary);

        const table = document.createElement('table');
        table.className = 'w-full text-left';
        const body = document.createElement('tbody');
        for (const item of order.items) {
            const row = document.createElement('tr');
            addCell(row, item.productId);
            addCell(row, item.productName);
            addCell(row, item.quantity);
            addCell(row, `${Number(item.unitPrice).toLocaleString('vi-VN')} ₫`);
            addCell(row, `${Number(item.lineTotal).toLocaleString('vi-VN')} ₫`);
            body.append(row);
        }
        table.append(body);
        panel.append(table);

        const actions = document.createElement('div');
        actions.className = 'flex flex-wrap gap-3';
        if (order.trangThaiXuLy === 'CHO_XAC_NHAN') {
            actions.append(makeOrderAction('Xác nhận đơn và trừ tồn', 'confirm-order', order.maDH));
        }
        if (order.trangThaiXuLy === 'DA_XAC_NHAN') {
            const dispatch = document.createElement('form');
            dispatch.className = 'flex flex-wrap items-end gap-3';
            dispatch.innerHTML = '<label class="form-field">Mã vận đơn<input name="trackingNumber" maxlength="64" required></label><label class="form-field">Ghi chú giao hàng<input name="note" maxlength="500"></label><button class="button button-primary">Bàn giao vận chuyển</button>';
            dispatch.addEventListener('submit', async event => {
                event.preventDefault();
                const values = Object.fromEntries(new FormData(dispatch).entries());
                await runOrderAction('dispatch-order', { orderId: order.maDH, ...values });
            });
            actions.append(dispatch);
        }
        if (order.trangThaiXuLy === 'DANG_GIAO') {
            const delivered = document.createElement('form');
            delivered.className = 'flex flex-wrap items-end gap-3';
            delivered.innerHTML = '<label class="form-field">Ghi chú giao hàng<input name="note" maxlength="500"></label><button class="button button-primary">Xác nhận đã giao</button>';
            delivered.addEventListener('submit', async event => {
                event.preventDefault();
                await runOrderAction('deliver-order', {
                    orderId: order.maDH,
                    note: new FormData(delivered).get('note')
                });
            });
            actions.append(delivered);
        }
        if (['CHO_XAC_NHAN', 'DA_XAC_NHAN', 'DANG_GIAO'].includes(order.trangThaiXuLy)) {
            const cancel = document.createElement('form');
            cancel.className = 'w-full space-y-3 border-t border-mecha-panel pt-4';
            const title = document.createElement('h4');
            title.className = 'font-orbitron';
            title.textContent = 'Hủy đơn';
            cancel.append(title);
            if (order.trangThaiXuLy === 'DANG_GIAO') {
                const note = document.createElement('p');
                note.className = 'text-sm text-mecha-gray';
                note.textContent = 'Chỉ nhập số lượng từng mặt hàng đã nhận và kiểm tra đủ điều kiện bán; hàng hỏng không được cộng lại vào tồn.';
                cancel.append(note);
                for (const item of order.items) {
                    const label = document.createElement('label');
                    label.className = 'form-field';
                    label.textContent = `${item.productName} · nhận lại tối đa ${item.quantity}`;
                    const input = document.createElement('input');
                    input.type = 'number';
                    input.min = '0';
                    input.max = String(item.quantity);
                    input.step = '1';
                    input.value = '0';
                    input.dataset.productId = item.productId;
                    label.append(input);
                    cancel.append(label);
                }
            }
            const reason = document.createElement('label');
            reason.className = 'form-field';
            reason.textContent = 'Lý do hủy / tình trạng hàng nhận lại';
            const reasonInput = document.createElement('textarea');
            reasonInput.name = 'reason';
            reasonInput.maxLength = 500;
            reasonInput.required = true;
            reason.append(reasonInput);
            cancel.append(reason);
            const submit = document.createElement('button');
            submit.type = 'submit';
            submit.className = 'button button-secondary';
            submit.textContent = 'Hủy đơn';
            cancel.append(submit);
            cancel.addEventListener('submit', async event => {
                event.preventDefault();
                const returns = [...cancel.querySelectorAll('[data-product-id]')].map(input => ({
                    productId: input.dataset.productId,
                    quantity: Number(input.value)
                }));
                await runOrderAction('cancel-order', {
                    orderId: order.maDH,
                    reason: reasonInput.value,
                    returns
                });
            });
            actions.append(cancel);
        }
        panel.append(actions);
    }

    function makeOrderAction(label, action, orderId) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'button button-primary';
        button.textContent = label;
        button.addEventListener('click', () => runOrderAction(action, { orderId }));
        return button;
    }

    async function runOrderAction(action, data) {
        try {
            const result = await post(action, data);
            await loadOrders();
            await loadOrderDetail(result.order.maDH);
            notify('Đã cập nhật đơn hàng.');
        } catch (error) {
            notify(error.message, true);
        }
    }

    async function loadInventory() {
        const result = await getState('inventory');
        const tbody = document.getElementById('admin-inventory');
        tbody.replaceChildren();
        for (const product of result.products || []) {
            const row = document.createElement('tr');
            addCell(row, product.id);
            addCell(row, product.name);
            addCell(row, product.availableStock);
            addCell(row, product.heldByConfirmedOrders);
            tbody.append(row);
        }

        for (const formId of ['admin-stock-receipt', 'admin-stock-adjustment']) {
            const select = document.querySelector(`#${formId} [name="productId"]`);
            const selected = select.value;
            select.replaceChildren();
            for (const product of result.products || []) addOption(select, product.id, `${product.id} — ${product.name} (${product.availableStock})`);
            if ((result.products || []).some(product => product.id === selected)) select.value = selected;
        }

        const history = document.getElementById('admin-stock-history');
        history.replaceChildren();
        for (const entry of result.history || []) {
            const row = document.createElement('tr');
            addCell(row, new Date(`${String(entry.performedAt).replace(' ', 'T')}Z`).toLocaleString('vi-VN'));
            addCell(row, `${entry.productId} — ${entry.productName}`);
            addCell(row, entry.type);
            addCell(row, entry.beforeQuantity);
            addCell(row, entry.quantityChange);
            addCell(row, entry.afterQuantity);
            addCell(row, entry.orderId || '—');
            addCell(row, entry.reason);
            addCell(row, entry.adminName);
            history.append(row);
        }
    }

    async function loadPreorders() {
        const result = await getState('preorders');
        const tbody = document.getElementById('admin-preorders');
        tbody.replaceChildren();
        for (const preorder of result.preorders || []) {
            const row = document.createElement('tr');
            addCell(row, preorder.id);
            addCell(row, `${preorder.customerName} · ${preorder.phone}`);
            addCell(row, `${preorder.productId} — ${preorder.productName}`);
            addCell(row, preorder.quantity);
            addCell(row, preorder.status);
            addCell(row, preorder.contactCount);
            addCell(row, formatDate(preorder.responseDeadline));
            const actions = document.createElement('td');
            actions.className = 'border-b border-mecha-panel p-2';
            if (preorder.status === 'CHO_XAC_NHAN') {
                addActionButton(actions, 'Xác nhận', async () => {
                    try {
                        await post('preorder-confirm', { preorderId: preorder.id });
                        await loadPreorders();
                        notify('Đã xác nhận yêu cầu đặt trước.');
                    } catch (error) {
                        notify(error.message, true);
                    }
                });
            }
            if (preorder.status === 'DA_XAC_NHAN') {
                addActionButton(actions, 'Ghi nhận liên hệ', async () => {
                    const note = window.prompt('Ghi nội dung lần liên hệ:');
                    if (note === null) return;
                    try {
                        await post('preorder-contact', { preorderId: preorder.id, note });
                        await loadPreorders();
                        notify('Đã ghi nhận liên hệ.');
                    } catch (error) {
                        notify(error.message, true);
                    }
                });
                if (Number(preorder.contactCount) > 0) {
                    addActionButton(actions, 'Chuyển thành đơn COD', async () => {
                        const address = window.prompt('Nhập địa chỉ giao hàng:');
                        if (address === null) return;
                        try {
                            const created = await post('preorder-convert', { preorderId: preorder.id, address });
                            await loadPreorders();
                            notify(`Đã tạo đơn ${created.orderId}.`);
                        } catch (error) {
                            notify(error.message, true);
                        }
                    });
                }
            }
            if (['CHO_XAC_NHAN', 'DA_XAC_NHAN'].includes(preorder.status)) {
                addActionButton(actions, 'Hủy', async () => {
                    const reason = window.prompt('Lý do hủy yêu cầu:');
                    if (reason === null) return;
                    try {
                        await post('preorder-cancel', { preorderId: preorder.id, reason });
                        await loadPreorders();
                        notify('Đã hủy yêu cầu đặt trước.');
                    } catch (error) {
                        notify(error.message, true);
                    }
                });
            }
            row.append(actions);
            tbody.append(row);
        }
    }

    async function loadCoupons() {
        const result = await getState('coupons');
        const tbody = document.getElementById('admin-coupons');
        tbody.replaceChildren();
        for (const coupon of result.coupons || []) {
            const row = document.createElement('tr');
            addCell(row, coupon.code);
            addCell(row, `${coupon.type} · ${coupon.value}`);
            addCell(row, `${coupon.heldUses} / ${coupon.usedUses} / ${coupon.maximumUses}`);
            addCell(row, `${formatDate(coupon.startsAt)} – ${formatDate(coupon.endsAt)}`);
            addCell(row, Number(coupon.active) ? 'Đang hoạt động' : 'Đã tắt');
            const actions = document.createElement('td');
            actions.className = 'border-b border-mecha-panel p-2';
            addActionButton(actions, 'Sửa', () => {
                const form = document.getElementById('admin-coupon-form');
                for (const [key, value] of Object.entries({
                    code: coupon.code,
                    type: coupon.type,
                    value: coupon.value,
                    minimumOrder: coupon.minimumOrder,
                    maximumDiscount: coupon.maximumDiscount,
                    maximumUses: coupon.maximumUses,
                    perPhoneLimit: coupon.perPhoneLimit,
                    startsAt: utcInputValue(coupon.startsAt),
                    endsAt: utcInputValue(coupon.endsAt)
                })) {
                    form.elements.namedItem(key).value = value ?? '';
                }
                form.elements.namedItem('active').checked = Number(coupon.active) === 1;
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
            row.append(actions);
            tbody.append(row);
        }
    }

    async function loadReviews() {
        const result = await getState('reviews');
        const tbody = document.getElementById('admin-reviews');
        tbody.replaceChildren();
        for (const review of result.reviews || []) {
            const row = document.createElement('tr');
            addCell(row, `${review.productName} · ${review.orderId}`);
            addCell(row, `${review.customerName} · ${review.phone}`);
            addCell(row, review.rating);
            addCell(row, review.comment || '—');
            addCell(row, review.status);
            const actions = document.createElement('td');
            actions.className = 'border-b border-mecha-panel p-2';
            if (review.status === 'CHO_DUYET') {
                addActionButton(actions, 'Duyệt', async () => {
                    try {
                        await post('moderate-review', { reviewId: review.id, status: 'DA_DUYET' });
                        await loadReviews();
                        notify('Đã duyệt đánh giá.');
                    } catch (error) {
                        notify(error.message, true);
                    }
                });
                addActionButton(actions, 'Từ chối', async () => {
                    const reason = window.prompt('Lý do từ chối:');
                    if (reason === null) return;
                    try {
                        await post('moderate-review', { reviewId: review.id, status: 'TU_CHOI', reason });
                        await loadReviews();
                        notify('Đã từ chối đánh giá.');
                    } catch (error) {
                        notify(error.message, true);
                    }
                });
            }
            row.append(actions);
            tbody.append(row);
        }
    }

    function makePaymentForm(order) {
        const form = document.createElement('form');
        form.className = 'payment-entry-form';
        inputField(form, 'Số tiền thực nhận (VND)', 'soTienThucNhan', 'number');
        const date = inputField(form, 'Thời điểm thực nhận', 'thoiDiemThucNhan', 'datetime-local', true, localDateTimeValue(new Date(), true));
        inputField(form, 'Căn cứ đối chiếu', 'canCuDoiChieu', 'text');
        let endpoint;
        if (order.method === 'CHUYEN_KHOAN') {
            endpoint = '../api/payments/bank/reconcile';
            inputField(form, 'Mã ngân hàng nhận', 'maNganHangNhan', 'text');
            inputField(form, 'Tài khoản nhận', 'taiKhoanNhan', 'text');
            inputField(form, 'Mã giao dịch ngân hàng', 'maGDNganHang', 'text');
        } else {
            endpoint = '../api/payments/cod/reconcile';
            inputField(form, 'Đơn vị vận chuyển', 'donViVanChuyen', 'text');
            inputField(form, 'Mã đối soát', 'maDoiSoat', 'text');
            inputField(form, 'Dòng phân bổ COD', 'dongPhanBoCOD', 'text');
        }
        const submit = document.createElement('button');
        submit.className = 'button button-primary';
        submit.textContent = 'Ghi nhận khoản thu';
        form.append(submit);
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const values = Object.fromEntries(new FormData(form).entries());
            try {
                values.thoiDiemThucNhan = utcIsoFromLocal(date.value);
                await postPayment(endpoint, { maDH: order.orderId, ...values });
                await loadPayments();
                notify('Đã ghi khoản thu và cập nhật trạng thái đối soát.');
            } catch (error) {
                notify(error.message, true);
            }
        });
        return form;
    }

    function paymentMetric(label, value, emphasis = false) {
        const metric = document.createElement('div');
        metric.className = `payment-metric${emphasis ? ' payment-metric-emphasis' : ''}`;
        const caption = document.createElement('span');
        caption.textContent = label;
        const amount = document.createElement('strong');
        amount.textContent = value;
        metric.append(caption, amount);
        return metric;
    }

    function makePaymentCard(payment) {
        const card = document.createElement('article');
        card.className = 'admin-payment-card';

        const header = document.createElement('header');
        header.className = 'payment-card-header';
        const identity = document.createElement('div');
        const orderId = document.createElement('h3');
        orderId.className = 'payment-order-id';
        orderId.textContent = payment.orderId;
        const customer = document.createElement('p');
        customer.className = 'payment-customer';
        customer.textContent = `${payment.customerName} · ${payment.phone}`;
        identity.append(orderId, customer);

        const badges = document.createElement('div');
        badges.className = 'payment-badges';
        for (const label of [payment.method, payment.paymentStatus, payment.orderStatus]) {
            const badge = document.createElement('span');
            badge.className = 'payment-badge';
            badge.textContent = label;
            badges.append(badge);
        }
        header.append(identity, badges);

        const metrics = document.createElement('div');
        metrics.className = 'payment-metrics';
        metrics.append(
            paymentMetric('Khách phải trả', formatMoney(payment.amountDue), true),
            paymentMetric('Đủ điều kiện ghi nhận', formatMoney(payment.eligibleReceived)),
            paymentMetric('Đã thực nhận', formatMoney(payment.totalReceived))
        );

        const actions = document.createElement('div');
        actions.className = 'payment-card-actions';
        for (const record of payment.records || []) {
            const details = document.createElement('details');
            details.className = 'payment-entry';
            const summary = document.createElement('summary');
            summary.textContent = `Giao dịch #${record.id} · ${record.method} · ${formatMoney(record.amount)}`;
            const description = document.createElement('p');
            description.className = 'my-2 text-sm text-mecha-gray';
            description.textContent = `${record.reconciliationStatus} · ${record.allocationStatus} · ${record.reference || record.reconciliationId || 'Không có mã tham chiếu'}`;
            details.append(summary, description);
            if (Number(record.active) === 1) {
                addActionButton(details, 'Vô hiệu chứng từ', async () => {
                    const reason = window.prompt('Lý do vô hiệu giao dịch:');
                    if (reason === null) return;
                    const evidence = window.prompt('Căn cứ điều chỉnh:');
                    if (evidence === null) return;
                    try {
                        await post('void-payment', {
                            paymentId: record.id,
                            reason,
                            canCuDieuChinh: evidence
                        });
                        await loadPayments();
                        notify('Đã vô hiệu giao dịch và ghi nhật ký điều chỉnh.');
                    } catch (error) {
                        notify(error.message, true);
                    }
                });
            }
            actions.append(details);
        }
        if (payment.method === 'CHUYEN_KHOAN'
            || (payment.method === 'COD' && payment.orderStatus === 'DA_GIAO')) {
            const details = document.createElement('details');
            details.className = 'payment-entry';
            const summary = document.createElement('summary');
            summary.textContent = `Ghi nhận ${payment.method}`;
            details.append(summary, makePaymentForm(payment));
            actions.append(details);
        }
        addActionButton(actions, 'Tạo yêu cầu hoàn', async () => {
            const amount = window.prompt('Số tiền đề nghị hoàn:');
            if (amount === null) return;
            const reason = window.prompt('Lý do yêu cầu hoàn:');
            if (reason === null) return;
            try {
                await post('create-refund-request', {
                    orderId: payment.orderId,
                    source: payment.orderStatus === 'DA_HUY' ? 'HUY_DON' : 'SAU_GIAO',
                    reason,
                    unallocatedAmount: amount
                });
                await loadPayments();
                notify('Đã tạo yêu cầu hoàn chờ duyệt.');
            } catch (error) {
                notify(error.message, true);
            }
        });

        card.append(header, metrics, actions);
        return card;
    }

    async function loadPayments() {
        const result = await getState('payments');
        const paymentsGrid = document.getElementById('admin-payments');
        paymentsGrid.replaceChildren();
        const recordsByOrder = new Map();
        for (const record of result.paymentRecords || []) {
            const records = recordsByOrder.get(record.orderId) || [];
            records.push(record);
            recordsByOrder.set(record.orderId, records);
        }
        const payments = (result.payments || []).map(payment => ({
            ...payment,
            records: recordsByOrder.get(payment.orderId) || []
        }));
        if (payments.length) {
            payments.forEach(payment => paymentsGrid.append(makePaymentCard(payment)));
        } else {
            const empty = document.createElement('p');
            empty.className = 'payment-empty-state';
            empty.textContent = 'Chưa có đơn hàng cần đối soát.';
            paymentsGrid.append(empty);
        }

        const refundsBody = document.getElementById('admin-refunds');
        refundsBody.replaceChildren();
        for (const refund of result.refundRequests || []) {
            const row = document.createElement('tr');
            addCell(row, `#${refund.id} · ${refund.orderId}`);
            addCell(row, `${refund.source} · ${refund.reason}`);
            addCell(row, formatMoney(refund.requestedAmount));
            addCell(row, formatMoney(refund.approvedAmount));
            addCell(row, formatMoney(refund.refundedAmount));
            addCell(row, refund.status);
            const actions = document.createElement('td');
            actions.className = 'border-b border-mecha-panel p-2';
            if (refund.status === 'CHO_DUYET') {
                addActionButton(actions, 'Duyệt hoàn', async () => {
                    const reason = window.prompt('Lý do duyệt:');
                    if (reason === null) return;
                    try {
                        await post('review-refund-request', {
                            refundRequestId: refund.id,
                            decision: 'APPROVE',
                            reason
                        });
                        await loadPayments();
                        notify('Đã duyệt yêu cầu hoàn.');
                    } catch (error) {
                        notify(error.message, true);
                    }
                });
                addActionButton(actions, 'Từ chối', async () => {
                    const reason = window.prompt('Lý do từ chối:');
                    if (reason === null) return;
                    try {
                        await post('review-refund-request', {
                            refundRequestId: refund.id,
                            decision: 'REJECT',
                            reason
                        });
                        await loadPayments();
                        notify('Đã từ chối yêu cầu hoàn.');
                    } catch (error) {
                        notify(error.message, true);
                    }
                });
            } else if (['CHO_HOAN', 'DANG_HOAN'].includes(refund.status)) {
                const form = document.createElement('form');
                form.className = 'min-w-64 space-y-2';
                inputField(form, 'Tiền hàng hoàn', 'goodsAmount', 'number', false);
                inputField(form, 'Phí vận chuyển hoàn', 'shippingAmount', 'number', false);
                inputField(form, 'Tiền chưa phân bổ hoàn', 'unallocatedAmount', 'number', false);
                const method = document.createElement('select');
                method.name = 'method';
                addOption(method, 'CHUYEN_KHOAN', 'Chuyển khoản');
                addOption(method, 'TIEN_MAT', 'Tiền mặt');
                form.append(method);
                inputField(form, 'Nguồn chứng từ hoàn', 'receiptSource');
                inputField(form, 'Mã chứng từ hoàn', 'receiptReference');
                inputField(form, 'Lý do', 'reason');
                const actual = inputField(form, 'Thực hoàn lúc', 'actualAt', 'datetime-local', true, localDateTimeValue(new Date(), true));
                const submit = document.createElement('button');
                submit.className = 'button button-primary';
                submit.textContent = 'Ghi nhận hoàn';
                form.append(submit);
                form.addEventListener('submit', async event => {
                    event.preventDefault();
                    const values = Object.fromEntries(new FormData(form).entries());
                    try {
                        values.actualAt = utcIsoFromLocal(actual.value);
                        await post('record-refund', {
                            refundRequestId: refund.id,
                            method: values.method,
                            receiptSource: values.receiptSource,
                            receiptReference: values.receiptReference,
                            reason: values.reason,
                            actualAt: values.actualAt,
                            goodsAmount: values.goodsAmount || 0,
                            shippingAmount: values.shippingAmount || 0,
                            unallocatedAmount: values.unallocatedAmount || 0
                        });
                        await loadPayments();
                        notify('Đã ghi nhận lần hoàn tiền.');
                    } catch (error) {
                        notify(error.message, true);
                    }
                });
                actions.append(form);
            }
            row.append(actions);
            refundsBody.append(row);
        }

        const logs = document.getElementById('admin-payment-logs');
        logs.replaceChildren();
        for (const item of result.paymentLogs || []) {
            const row = document.createElement('tr');
            addCell(row, formatDate(item.recordedAt));
            addCell(row, item.orderId);
            addCell(row, item.eventType);
            addCell(row, `${item.previousStatus || '—'} → ${item.currentStatus || '—'}`);
            addCell(row, `${item.reason}${item.evidence ? ` · ${item.evidence}` : ''}`);
            addCell(row, item.adminName || '—');
            logs.append(row);
        }
    }

    async function loadAnalytics() {
        const form = document.getElementById('admin-analytics-form');
        if (!form.elements.from.value || !form.elements.to.value) {
            const now = new Date();
            form.elements.to.value = now.toISOString().slice(0, 10);
            form.elements.from.value = `${now.getUTCFullYear()}-${String(now.getUTCMonth() + 1).padStart(2, '0')}-01`;
        }
        const parameters = { from: form.elements.from.value, to: form.elements.to.value };
        const result = await getState('analytics', parameters);
        const analytics = result.analytics;
        const summary = document.getElementById('admin-analytics-summary');
        summary.replaceChildren();
        const cards = [
            ['Doanh thu hàng sau hoàn', formatMoney(analytics.netGoodsRevenue)],
            ['Thực thu / thực hoàn', `${formatMoney(analytics.cashflow.received)} / ${formatMoney(analytics.cashflow.refunded)}`],
            ['COD còn phải thu', `${formatMoney(analytics.codOutstanding.amount)} · ${analytics.codOutstanding.orderCount} đơn`],
            ['Đơn đã giao trong kỳ', analytics.deliveredOrders],
            ['Tồn khả dụng', analytics.stats.availableStock],
            ['Khách hàng', analytics.stats.customerCount]
        ];
        for (const [title, value] of cards) {
            const card = document.createElement('div');
            card.className = 'bg-mecha-dark border border-mecha-panel p-4';
            const heading = document.createElement('p');
            heading.className = 'text-sm text-mecha-gray';
            heading.textContent = title;
            const amount = document.createElement('strong');
            amount.className = 'mt-2 block text-mecha-gold';
            amount.textContent = String(value);
            card.append(heading, amount);
            summary.append(card);
        }
        const days = document.getElementById('admin-analytics-days');
        days.replaceChildren();
        for (const item of analytics.revenueRows || []) {
            const row = document.createElement('tr');
            addCell(row, item.date);
            addCell(row, item.ordersRecognized);
            addCell(row, item.deliveredOrders);
            addCell(row, formatMoney(item.grossGoodsRevenue));
            addCell(row, formatMoney(item.cashReceived));
            addCell(row, `${formatMoney(item.cashRefunded)} · hàng ${formatMoney(item.refundedGoods)}`);
            days.append(row);
        }
        document.getElementById('admin-revenue-csv').href =
            `../api/admin.php?${new URLSearchParams({ view: 'revenue-csv', ...parameters })}`;
    }

    async function loadAiRules() {
        const result = await getState('ai-rules');
        const container = document.getElementById('admin-ai-rules');
        container.replaceChildren();
        for (const rule of result.aiRules || []) {
            const card = document.createElement('article');
            card.className = 'bg-mecha-dark border border-mecha-panel p-4';
            const title = document.createElement('h3');
            title.className = 'font-orbitron';
            title.textContent = `${rule.name} · ${rule.key}`;
            const details = document.createElement('p');
            details.className = 'my-2 text-sm text-mecha-gray';
            details.textContent = `${rule.type} · ${rule.active ? 'Đang bật' : 'Đang tắt'} · ưu tiên ${rule.priority}`;
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'button button-secondary';
            edit.textContent = 'Sửa';
            edit.addEventListener('click', () => {
                const form = document.getElementById('admin-ai-form');
                for (const [key, value] of Object.entries(rule)) {
                    const input = form.elements.namedItem(key);
                    if (input && input.type !== 'checkbox') input.value = value ?? '';
                }
                form.elements.namedItem('active').checked = Number(rule.active) === 1;
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
            card.append(title, details, edit);
            container.append(card);
        }
        const history = document.getElementById('admin-ai-history');
        history.replaceChildren();
        for (const entry of result.aiHistory || []) {
            const row = document.createElement('tr');
            addCell(row, formatDate(entry.createdAt));
            addCell(row, entry.question);
            addCell(row, entry.response);
            addCell(row, entry.source);
            addCell(row, entry.helpful ?? '—');
            addCell(row, entry.ruleName || '—');
            history.append(row);
        }
    }

    async function loadActiveTab(tab) {
        if (tab === 'customers') await loadCustomers();
        if (tab === 'orders') await loadOrders();
        if (tab === 'inventory') await loadInventory();
        if (tab === 'preorders') await loadPreorders();
        if (tab === 'coupons') await loadCoupons();
        if (tab === 'reviews') await loadReviews();
        if (tab === 'payments') await loadPayments();
        if (tab === 'analytics') await loadAnalytics();
        if (tab === 'ai') await loadAiRules();
        if (tab === 'products' || tab === 'references') {
            if (!catalog) await loadCatalog();
        }
    }

    function showDashboard() {
        document.getElementById('admin-login').hidden = true;
        document.getElementById('admin-dashboard').hidden = false;
        document.getElementById('admin-logout').hidden = false;
        document.getElementById('admin-identity').textContent = `Đã đăng nhập: ${admin.name} (${admin.username})`;
        const permissions = {
            products: 'SAN_PHAM',
            references: 'DANH_MUC',
            customers: 'KHACH_HANG',
            orders: 'DON_HANG',
            inventory: 'KHO',
            preorders: 'DAT_TRUOC',
            coupons: 'KHUYEN_MAI',
            reviews: 'DANH_GIA',
            payments: 'THANH_TOAN',
            analytics: 'BAO_CAO',
            ai: 'GOI_Y_AI'
        };
        for (const [page, permission] of Object.entries(permissions)) {
            const link = document.querySelector(`[data-admin-page="${page}"]`);
            if (link) link.hidden = !can(permission);
        }
        const currentPage = document.body.dataset.adminCurrentPage;
        if (can(permissions[currentPage] || '')) return currentPage;
        const firstPage = document.querySelector('[data-admin-page]:not([hidden])');
        if (firstPage) {
            window.location.replace(firstPage.href);
            return null;
        }
        notify('Tài khoản chưa được cấp quyền quản trị.');
        return null;
    }

    async function initialize() {
        try {
            const result = await getState();
            admin = result.admin;
            if (!admin) {
                document.getElementById('admin-login').hidden = false;
                document.getElementById('admin-identity').textContent = 'Chưa đăng nhập';
                return;
            }
            const firstTab = showDashboard();
            if (firstTab) await loadActiveTab(firstTab);
            else notify('Tài khoản chưa được cấp quyền quản trị.');
        } catch (error) {
            notify(error.message, true);
        }
    }

    document.getElementById('admin-login').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            const result = await post('login', data);
            csrfToken = result.csrfToken;
            admin = result.admin;
            const firstTab = showDashboard();
            if (firstTab) await loadActiveTab(firstTab);
            else notify('Tài khoản chưa được cấp quyền quản trị.');
            form.reset();
            notify('Đăng nhập quản trị thành công.');
        } catch (error) {
            notify(error.message, true);
        }
    });

    document.getElementById('admin-logout').addEventListener('click', async () => {
        try {
            await post('logout');
            window.location.reload();
        } catch (error) {
            notify(error.message, true);
        }
    });

    function bind(id, eventName, handler) {
        document.getElementById(id)?.addEventListener(eventName, handler);
    }

    bind('admin-product-form', 'reset', event => {
        delete event.currentTarget.dataset.update;
        window.setTimeout(renderProductImagePreviews, 0);
    });

    bind('admin-product-form', 'submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const fields = Object.fromEntries(new FormData(form).entries());
        delete fields.mainImageFile;
        delete fields.galleryImageFiles;
        const gallery = String(fields.gallery || '').split(/\r?\n/).map(url => url.trim()).filter(Boolean);
        try {
            const uploaded = await uploadProductImages(form);
            if (uploaded.mainImage) fields.image = uploaded.mainImage;
            gallery.push(...uploaded.galleryImages);
            await post('save-product', {
                isUpdate: form.dataset.update === 'true',
                product: {
                    ...fields,
                    gallery,
                    preorder: form.elements.namedItem('preorder').checked,
                    featured: form.elements.namedItem('featured').checked
                }
            });
            form.reset();
            await loadCatalog();
            notify('Đã lưu sản phẩm.');
        } catch (error) {
            notify(error.message, true);
        }
    });

    bind('admin-product-form', 'change', event => {
        if (event.target.name === 'mainImageFile' || event.target.name === 'galleryImageFiles') {
            renderProductImagePreviews();
        }
    });

    document.querySelectorAll('[data-reference-form]').forEach(form => {
        form.addEventListener('reset', event => {
            delete event.currentTarget.dataset.update;
            if (event.currentTarget.dataset.kind === 'series') {
                window.setTimeout(() => renderSeriesImagePreview(event.currentTarget), 0);
            }
        });
        if (form.dataset.kind === 'series') {
            form.addEventListener('input', event => {
                if (event.target.name === 'image') {
                    if (event.target.value.trim()) form.elements.namedItem('imageFile').value = '';
                    renderSeriesImagePreview(form);
                }
            });
            form.addEventListener('change', event => {
                if (event.target.name === 'imageFile') {
                    if (event.target.files.length) form.elements.namedItem('image').value = '';
                    renderSeriesImagePreview(form);
                }
            });
        }
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const fields = Object.fromEntries(new FormData(form).entries());
            const imageFile = form.elements.namedItem('imageFile');
            delete fields.imageFile;
            try {
                if (imageFile && imageFile.files[0]) {
                    const body = new FormData();
                    body.append('seriesImage', imageFile.files[0]);
                    const response = await fetch('../api/admin-upload.php', {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
                        body
                    });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.message || 'Không thể tải ảnh dòng Mecha lên.');
                    fields.image = result.seriesImage;
                }
                await post('save-reference', {
                    kind: form.dataset.kind,
                    record: { ...fields, active: form.elements.namedItem('active').checked }
                });
                form.reset();
                await loadCatalog();
                notify('Đã lưu danh mục.');
            } catch (error) {
                notify(error.message, true);
            }
        });
    });

    bind('admin-order-filter', 'change', async () => {
        try {
            await loadOrders();
        } catch (error) {
            notify(error.message, true);
        }
    });

    bind('admin-stock-receipt', 'submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const values = Object.fromEntries(new FormData(form).entries());
        try {
            await post('stock-receipt', { ...values, quantity: Number(values.quantity) });
            form.reset();
            await loadInventory();
            notify('Đã ghi nhận nhập kho.');
        } catch (error) {
            notify(error.message, true);
        }
    });

    bind('admin-stock-adjustment', 'submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const values = Object.fromEntries(new FormData(form).entries());
        try {
            await post('stock-adjustment', { ...values, delta: Number(values.delta) });
            form.reset();
            await loadInventory();
            notify('Đã ghi nhận điều chỉnh tồn.');
        } catch (error) {
            notify(error.message, true);
        }
    });

    bind('admin-coupon-form', 'submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const coupon = Object.fromEntries(new FormData(form).entries());
        try {
            coupon.startsAt = utcIsoFromLocal(coupon.startsAt);
            coupon.endsAt = utcIsoFromLocal(coupon.endsAt);
            coupon.active = form.elements.namedItem('active').checked;
            await post('save-coupon', { coupon });
            form.reset();
            await loadCoupons();
            notify('Đã lưu mã giảm giá.');
        } catch (error) {
            notify(error.message, true);
        }
    });

    bind('admin-analytics-form', 'submit', async event => {
        event.preventDefault();
        try {
            await loadAnalytics();
            notify('Đã tải báo cáo theo bộ lọc ngày UTC.');
        } catch (error) {
            notify(error.message, true);
        }
    });

    bind('admin-ai-form', 'submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const rule = Object.fromEntries(new FormData(form).entries());
        rule.active = form.elements.namedItem('active').checked;
        try {
            await post('save-ai-rule', { rule });
            form.reset();
            await loadAiRules();
            notify('Đã lưu cấu hình quy tắc tư vấn.');
        } catch (error) {
            notify(error.message, true);
        }
    });

    bind('admin-coupon-form', 'reset', event => {
        event.currentTarget.elements.namedItem('active').checked = true;
    });

    bind('admin-ai-form', 'reset', event => {
        event.currentTarget.elements.namedItem('active').checked = true;
    });

    initialize();
})();
