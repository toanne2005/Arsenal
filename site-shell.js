const navigation = [
    { label: 'Trang chủ', href: 'index.html' },
    { label: 'Phân hạng', href: 'phan-hang.html' },
    { label: 'Dòng Mecha', href: 'dong-mecha.html' },
    { label: 'Dụng cụ', href: 'dung-cu.html' },
    { label: 'Đặt trước', href: 'dat-truoc.html' }
];

const TOOL_PRODUCT_CATEGORIES = [
    'Kìm cắt mô hình',
    'Dao và dũa',
    'Nhíp và cọ',
    'Sơn và bút panel line',
    'Thảm cắt',
    'Hộp phụ kiện'
];

const TOOL_PRODUCT_FILTERS = {
    nipper: { label: 'Kìm cắt mô hình', category: 'Kìm cắt mô hình' },
    'knife-file': { label: 'Dao và dũa', category: 'Dao và dũa' },
    'tweezers-brush': { label: 'Nhíp và cọ', category: 'Nhíp và cọ' },
    'paint-panel-line': { label: 'Sơn và bút panel line', category: 'Sơn và bút panel line' },
    'cutting-mat': { label: 'Thảm cắt', category: 'Thảm cắt' },
    'accessory-box': { label: 'Hộp phụ kiện', category: 'Hộp phụ kiện' }
};

const isDemoCatalog = window.location.protocol === 'file:';
const catalogStorageSuffix = isDemoCatalog ? '' : '-vnd';
let customerSession = null;
let customerCsrfToken = '';
let customerSessionRequest = null;
let commerceCsrfToken = '';

const STORAGE_KEYS = {
    cart: 'arsenal-cart' + catalogStorageSuffix,
    orders: 'arsenal-orders' + catalogStorageSuffix,
    checkoutAttempt: 'arsenal-checkout-attempt' + catalogStorageSuffix,
    preorders: 'arsenal-preorders',
    savedProducts: 'arsenal-saved-products' + catalogStorageSuffix,
    reviews: 'arsenal-reviews' + catalogStorageSuffix,
    newsletter: 'arsenal-newsletter',
    messages: 'arsenal-messages'
};

const defaultCatalog = [
    { id: 'M01', name: 'PG UNLEASHED RX-78-2', grade: 'PERFECT GRADE', scale: '1/60', series: 'MOBILE SUIT GUNDAM', price: 275, status: 'CÒN HÀNG', image: 'https://images.unsplash.com/photo-1546561892-65bf811416b9?ixlib=rb-4.0.3&auto=format&fit=crop&w=600&q=80' },
    { id: 'M02', name: 'MGEX STRIKE FREEDOM', grade: 'MASTER GRADE EXTREME', scale: '1/100', series: 'GUNDAM SEED DESTINY', price: 150, status: 'SẮP HẾT', image: 'https://images.unsplash.com/photo-1614728263952-84ea256f9679?ixlib=rb-4.0.3&auto=format&fit=crop&w=600&q=80' },
    { id: 'M03', name: 'RG HI-NU GUNDAM', grade: 'REAL GRADE', scale: '1/144', series: 'CHAR\'S COUNTERATTACK', price: 45, status: 'VỪA VỀ', image: 'https://images.unsplash.com/photo-1587588354456-ae376af71a25?ixlib=rb-4.0.3&auto=format&fit=crop&w=600&q=80' },
    { id: 'M04', name: 'HG AERIAL REBUILD', grade: 'HIGH GRADE', scale: '1/144', series: 'WITCH FROM MERCURY', price: 22, status: 'ĐẶT TRƯỚC', image: 'https://images.unsplash.com/photo-1587588354456-ae376af71a25?ixlib=rb-4.0.3&auto=format&fit=crop&w=600&q=80' }
];

let productCatalog = isDemoCatalog ? defaultCatalog : [];
let catalogState = isDemoCatalog ? 'ready' : 'waiting';
const [lowerPriceLimit, upperPriceLimit] = isDemoCatalog ? [50, 150] : [500000, 1500000];
const priceFormatter = new Intl.NumberFormat(isDemoCatalog ? 'en-US' : 'vi-VN', {
    style: 'currency',
    currency: isDemoCatalog ? 'USD' : 'VND',
    maximumFractionDigits: 2
});

function safeParse(value, fallback) {
    try {
        return JSON.parse(value || 'null') ?? fallback;
    } catch (error) {
        return fallback;
    }
}

function getStoredArray(key) {
    const value = safeParse(localStorage.getItem(key), []);
    return Array.isArray(value) ? value : [];
}

function getCart() {
    return getStoredArray(STORAGE_KEYS.cart);
}

function saveCart(items) {
    localStorage.setItem(STORAGE_KEYS.cart, JSON.stringify(items));
    syncCustomerData('cart', items);
}

function getSavedProducts() {
    const saved = safeParse(localStorage.getItem(STORAGE_KEYS.savedProducts), []);
    return Array.isArray(saved) ? saved : [];
}

function saveSavedProducts(items) {
    localStorage.setItem(STORAGE_KEYS.savedProducts, JSON.stringify(items));
    syncCustomerData('favorites', items);
}

async function initializeCustomerSession() {
    if (customerSessionRequest) return customerSessionRequest;
    customerSessionRequest = (async () => {
        if (isDemoCatalog) return null;
        try {
            const response = await fetch('api/tai-khoan.php', { headers: { Accept: 'application/json' } });
            const state = await response.json();
            if (!response.ok) throw new Error(state.message || 'Không thể xác thực tài khoản.');
            customerCsrfToken = state.csrfToken || '';
            customerSession = state.customer || null;
            if (!customerSession) return null;
            document.querySelectorAll('#checkout-form').forEach(form => {
                for (const [field, value] of [
                    ['customerName', customerSession.hoTen],
                    ['phone', customerSession.dienThoai],
                    ['address', customerSession.diaChi]
                ]) {
                    const input = form.elements.namedItem(field);
                    if (input && value && !input.value) input.value = value;
                }
            });

            const cartOwnerKey = 'arsenal-cart-owner' + catalogStorageSuffix;
            const favoriteOwnerKey = 'arsenal-favorites-owner' + catalogStorageSuffix;
            const sameCartOwner = localStorage.getItem(cartOwnerKey) === String(customerSession.maKH);
            let mergedCart;
            if (sameCartOwner) {
                mergedCart = getCart();
            } else {
                const guestCart = getCart();
                const accountCart = Array.isArray(state.cart) ? state.cart : [];
                const combined = new Map(accountCart.map(item => [String(item.id), { ...item }]));
                for (const item of guestCart) {
                    const key = String(item.id);
                    const existing = combined.get(key);
                    if (existing) existing.quantity = Math.min(99, Number(existing.quantity) + Number(item.quantity));
                    else combined.set(key, item);
                }
                mergedCart = [...combined.values()];
            }
            const sameFavoritesOwner = localStorage.getItem(favoriteOwnerKey) === String(customerSession.maKH);
            const mergedFavorites = sameFavoritesOwner
                ? getSavedProducts()
                : [...new Set([...getSavedProducts(), ...(state.favorites || []).map(String)])];
            localStorage.setItem(STORAGE_KEYS.cart, JSON.stringify(mergedCart));
            localStorage.setItem(STORAGE_KEYS.savedProducts, JSON.stringify(mergedFavorites));
            localStorage.setItem(cartOwnerKey, String(customerSession.maKH));
            localStorage.setItem(favoriteOwnerKey, String(customerSession.maKH));
            await Promise.all([
                syncCustomerData('cart', mergedCart),
                syncCustomerData('favorites', mergedFavorites)
            ]);
            return customerSession;
        } catch (error) {
            console.error('ARSENAL customer session:', error);
            return null;
        }
    })();

    return customerSessionRequest;
}

async function syncCustomerData(action, items) {
    if (!customerSession || !customerCsrfToken || isDemoCatalog) return;
    try {
        const response = await fetch('api/tai-khoan.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-Token': customerCsrfToken
            },
            body: JSON.stringify({ action, items })
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || `Không thể đồng bộ ${action}.`);
    } catch (error) {
        console.error(`ARSENAL customer ${action} sync:`, error);
    }
}

function getOrders() {
    return getStoredArray(STORAGE_KEYS.orders);
}

function saveOrders(items) {
    localStorage.setItem(STORAGE_KEYS.orders, JSON.stringify(items));
}

function getPreorders() {
    return getStoredArray(STORAGE_KEYS.preorders);
}

function savePreorders(items) {
    localStorage.setItem(STORAGE_KEYS.preorders, JSON.stringify(items));
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    })[character]);
}

function getProductReviews(productId) {
    const reviews = safeParse(localStorage.getItem(STORAGE_KEYS.reviews), {});
    return reviews && typeof reviews === 'object' && Array.isArray(reviews[productId]) ? reviews[productId] : [];
}

async function loadProductReviews(productId, root) {
    if (isDemoCatalog || !root) return;
    try {
        const response = await fetch(`api/danh-gia.php?productId=${encodeURIComponent(productId)}`, {
            headers: { Accept: 'application/json' }
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Không thể tải đánh giá.');
        commerceCsrfToken = result.csrfToken;
        const reviews = result.reviews || [];
        const average = reviews.length
            ? (reviews.reduce((sum, review) => sum + Number(review.rating || 0), 0) / reviews.length).toFixed(1)
            : 'Chưa có';
        const heading = root.querySelector('.review-section h3');
        const list = root.querySelector('.review-list');
        if (heading) heading.innerHTML = `Đánh giá sản phẩm <span class="text-mecha-gold">${average} · ${reviews.length}</span>`;
        if (list) {
            list.innerHTML = reviews.length
                ? reviews.map(review => `
                    <article class="review-entry">
                        <div class="review-entry-heading">
                            <strong>${escapeHtml(review.name || 'Khách')}</strong>
                            <span>${'★'.repeat(Math.max(0, Math.min(5, Number(review.rating) || 0)))}${'☆'.repeat(5 - Math.max(0, Math.min(5, Number(review.rating) || 0)))}</span>
                        </div>
                        <p>${escapeHtml(review.comment)}</p>
                        <small>${escapeHtml(new Date(`${String(review.createdAt).replace(' ', 'T')}Z`).toLocaleDateString('vi-VN'))}</small>
                    </article>
                `).join('')
                : '<p class="text-mecha-gray">Chưa có đánh giá được duyệt.</p>';
        }
    } catch (error) {
        console.error('ARSENAL review load failed:', error);
        showToast('Không thể tải đánh giá sản phẩm.');
    }
}

function formatPrice(value) {
    return priceFormatter.format(value ?? 0);
}

function getNumericPrice(value) {
    return Number(String(value ?? 0).replace(/[^0-9.]/g, '')) || 0;
}

function createCheckoutRequestId() {
    if (!window.crypto?.getRandomValues) {
        throw new Error('Trình duyệt không hỗ trợ mã hóa an toàn để gửi đơn.');
    }

    const bytes = new Uint8Array(12);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('').toUpperCase();
}

function getCheckoutAttempt() {
    const saved = safeParse(localStorage.getItem(STORAGE_KEYS.checkoutAttempt), null);
    if (saved && /^[A-F0-9]{24}$/.test(saved.requestId) &&
        (saved.confirmedTotal === null || Number.isFinite(Number(saved.confirmedTotal)))) {
        return saved;
    }

    const attempt = { requestId: createCheckoutRequestId(), confirmedTotal: null, paymentMethod: 'COD' };
    localStorage.setItem(STORAGE_KEYS.checkoutAttempt, JSON.stringify(attempt));
    return attempt;
}

function stopPaymentStatusPolling() {
    window.clearInterval(window.arsenalPaymentStatusTimer);
    window.arsenalPaymentStatusTimer = null;
}

async function refreshPaymentModalStatus(orderId, phone, dueAmount) {
    const status = document.getElementById('payment-modal-status');
    const qr = document.getElementById('payment-modal-qr');
    const qrFrame = document.getElementById('payment-modal-qr-frame');
    const paid = document.getElementById('payment-modal-paid');
    const title = document.getElementById('payment-modal-title');
    const instructions = document.getElementById('payment-modal-instructions');
    const countdown = document.getElementById('payment-modal-countdown');
    const actions = document.getElementById('payment-modal-actions');
    const modal = document.getElementById('payment-modal');
    if (!status || !modal || modal.hidden) return;

    try {
        const response = await fetch('api/tra-cuu-don.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            cache: 'no-store',
            body: JSON.stringify({ orderId, phone })
        });
        const orderState = await response.json();
        if (!response.ok) throw new Error(orderState.message || 'Không thể kiểm tra trạng thái thanh toán.');

        const allocated = Number(orderState.soTienDaPhanBo || 0);
        const isPaid = allocated >= Number(dueAmount)
            && ['DA_THANH_TOAN_DU', 'THANH_TOAN_DU_THUA', 'DANG_HOAN_TIEN', 'DA_HOAN_TIEN']
                .includes(orderState.trangThaiThanhToan);
        if (isPaid) {
            if (title) title.textContent = 'Thanh toán thành công';
            if (qr) qr.hidden = true;
            if (paid) paid.hidden = false;
            if (qrFrame) qrFrame.classList.add('is-paid');
            status.textContent = orderState.trangThaiThanhToan === 'DANG_HOAN_TIEN'
                ? `Đã nhận ${formatPrice(allocated)}. Đơn đã xác nhận; phần tiền thừa đang chờ hoàn.`
                : `Đã nhận đủ ${formatPrice(allocated)}. Đơn hàng đã được xác nhận.`;
            if (instructions) instructions.textContent = 'Giao dịch đã được hệ thống xác nhận.';
            status.classList.remove('text-mecha-gray');
            status.classList.add('text-green-400');
            if (countdown) countdown.textContent = '';
            if (actions) actions.replaceChildren();
            if (window.arsenalPaymentCountdown) window.clearInterval(window.arsenalPaymentCountdown);
            stopPaymentStatusPolling();
            return;
        }

        if (orderState.trangThaiXuLy === 'DA_HUY') {
            status.textContent = 'Đơn đã bị hủy. Vui lòng liên hệ cửa hàng nếu bạn đã chuyển tiền.';
            stopPaymentStatusPolling();
            return;
        }

        if (Number(orderState.soTienDaThu || 0) > 0) {
            status.textContent = `Đã nhận ${formatPrice(orderState.soTienDaThu)} / ${formatPrice(dueAmount)}. Đang chờ thanh toán đủ.`;
        } else {
            status.textContent = 'Chưa nhận được tiền. Trạng thái sẽ tự cập nhật sau khi ngân hàng xác nhận.';
        }
    } catch (error) {
        console.error('ARSENAL payment status refresh failed:', error);
    }
}

function showPaymentModal(order, phone) {
    const modal = document.getElementById('payment-modal');
    const qr = document.getElementById('payment-modal-qr');
    const title = document.getElementById('payment-modal-title');
    const orderLabel = document.getElementById('payment-modal-order');
    const instructions = document.getElementById('payment-modal-instructions');
    const countdown = document.getElementById('payment-modal-countdown');
    const status = document.getElementById('payment-modal-status');
    const qrFrame = document.getElementById('payment-modal-qr-frame');
    const paid = document.getElementById('payment-modal-paid');
    const actions = document.getElementById('payment-modal-actions');
    if (!modal || !qr || !title || !orderLabel || !instructions || !countdown || !status
        || !qrFrame || !paid || !actions) return false;
    const transfer = order.transferInstructions;
    const momo = order.momo;
    if (!transfer && !momo) return false;

    stopPaymentStatusPolling();
    paid.hidden = true;
    qrFrame.classList.remove('is-paid');
    status.textContent = 'Đang chờ xác nhận thanh toán…';
    status.classList.remove('text-green-400');
    status.classList.add('text-mecha-gray');
    title.textContent = transfer ? 'Quét QR chuyển khoản' : 'Thanh toán MoMo';
    orderLabel.textContent = `Đơn ${order.maDH} · ${formatPrice(order.tongPhaiTra)}`;
    qr.hidden = false;
    qr.src = transfer ? transfer.qrUrl : momo.qrCodeUrl;
    instructions.textContent = transfer
        ? `${transfer.bankCode} · ${transfer.accountName} · ${transfer.accountNumber} · Nội dung: ${transfer.transferContent}`
        : 'Quét mã bằng ứng dụng MoMo hoặc mở liên kết thanh toán.';
    actions.replaceChildren();
    countdown.textContent = '';
    if (momo?.payUrl) {
        const link = document.createElement('a');
        link.className = 'button button-primary';
        link.href = momo.payUrl;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = 'Mở thanh toán MoMo';
        actions.append(link);
    }
    if (momo?.deeplink) {
        const link = document.createElement('a');
        link.className = 'button button-secondary';
        link.href = momo.deeplink;
        link.textContent = 'Mở ứng dụng MoMo';
        actions.append(link);
    }
    if (transfer.deadline) {
        const deadline = new Date(`${String(transfer.deadline).replace(' ', 'T')}Z`).getTime();
        const update = () => {
            const remaining = Math.max(0, deadline - Date.now());
            const hours = Math.floor(remaining / 3600000);
            const minutes = Math.floor((remaining % 3600000) / 60000);
            const seconds = Math.floor((remaining % 60000) / 1000);
            countdown.textContent = remaining
                ? `Hạn chuyển khoản còn ${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
                : 'Đã hết hạn chuyển khoản. Đơn sẽ được xử lý theo chính sách cửa hàng.';
        };
        update();
        window.clearInterval(window.arsenalPaymentCountdown);
        window.arsenalPaymentCountdown = window.setInterval(update, 1000);
    } else {
        window.clearInterval(window.arsenalPaymentCountdown);
    }
    modal.hidden = false;
    document.body.classList.add('payment-modal-open');
    refreshPaymentModalStatus(order.maDH, phone, order.tongPhaiTra);
    window.arsenalPaymentStatusTimer = window.setInterval(
        () => refreshPaymentModalStatus(order.maDH, phone, order.tongPhaiTra),
        5000
    );
    return true;
}

function createPaymentMethodPicker(select) {
    const label = select.closest('label');
    const grid = label?.parentElement;
    if (!label || !grid) return null;

    select.hidden = true;
    const picker = document.createElement('div');
    picker.className = 'payment-method-picker field-full';
    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'payment-method-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.setAttribute('aria-label', 'Chọn phương thức thanh toán');
    const arrow = document.createElement('span');
    arrow.className = 'payment-method-arrow';
    arrow.setAttribute('aria-hidden', 'true');
    const menu = document.createElement('div');
    menu.className = 'payment-method-menu';
    menu.setAttribute('role', 'listbox');
    menu.hidden = true;
    picker.append(trigger, arrow, menu);
    label.insertAdjacentElement('afterend', picker);

    const close = (restoreFocus = false) => {
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        if (restoreFocus) trigger.focus();
    };
    const open = () => {
        menu.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        menu.querySelector('[aria-selected="true"]')?.focus();
    };
    const renderOptions = () => {
        menu.replaceChildren();
        [...select.options].forEach(option => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'payment-method-option';
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', String(option.value === select.value));
            item.textContent = option.textContent;
            item.addEventListener('click', () => {
                select.value = option.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                updateTrigger();
                close(true);
            });
            item.addEventListener('keydown', event => {
                const options = [...menu.querySelectorAll('.payment-method-option')];
                const index = options.indexOf(item);
                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    options[(index + (event.key === 'ArrowDown' ? 1 : options.length - 1)) % options.length]?.focus();
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    close(true);
                } else if (event.key === 'Tab') {
                    close();
                }
            });
            menu.append(item);
        });
        updateTrigger();
    };
    const updateTrigger = () => {
        const selected = select.selectedOptions[0];
        trigger.replaceChildren(document.createTextNode(selected?.textContent || 'Chọn phương thức thanh toán'), arrow);
        menu.querySelectorAll('.payment-method-option').forEach(item => {
            item.setAttribute('aria-selected', String(item.textContent === selected?.textContent));
        });
    };

    trigger.addEventListener('click', () => {
        if (menu.hidden) open();
        else close();
    });
    trigger.addEventListener('keydown', event => {
        if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            open();
        } else if (event.key === 'Escape') {
            event.stopPropagation();
            close();
        }
    });
    document.addEventListener('click', event => {
        if (!picker.contains(event.target)) close();
    });
    select.addEventListener('change', updateTrigger);
    renderOptions();
    return renderOptions;
}

async function initializeCheckoutPayment() {
    try {
        const response = await fetch('api/dat-hang.php', { headers: { Accept: 'application/json' } });
        const config = await response.json();
        if (!response.ok) throw new Error(config.message || 'Không thể tải phương thức thanh toán.');
        document.querySelectorAll('#checkout-form').forEach(form => {
            const select = form.elements.namedItem('paymentMethod');
            const details = form.querySelector('.checkout-transfer-details');
            if (!select || !details) return;
            const renderPaymentOptions = createPaymentMethodPicker(select);
            if (config.paymentMethods?.includes('CHUYEN_KHOAN') && config.transferAccount) {
                const option = document.createElement('option');
                option.value = 'CHUYEN_KHOAN';
                option.textContent = 'Chuyển khoản ngân hàng (VietQR)';
                select.append(option);
            }
            if (config.paymentMethods?.includes('MOMO')) {
                const option = document.createElement('option');
                option.value = 'MOMO';
                option.textContent = 'Ví điện tử MoMo';
                select.append(option);
            }
            renderPaymentOptions?.();
            const account = config.transferAccount;
            const updateDetails = () => {
                details.hidden = !['CHUYEN_KHOAN', 'MOMO'].includes(select.value);
                details.textContent = details.hidden || !account
                    ? (select.value === 'MOMO' ? 'Bạn sẽ nhận mã QR MoMo sau khi tạo đơn.' : '')
                    : `Chuyển khoản tới ${account.bankCode} · ${account.accountName} · ${account.accountNumber}. Hệ thống tạo QR sau khi xác nhận đơn.`;
            };
            select.addEventListener('change', updateDetails);
            updateDetails();
        });
    } catch (error) {
        console.error('ARSENAL checkout payment config:', error);
    }
}

function getPreorderAttempt() {
    const key = 'arsenalPreorderAttempt';
    const saved = safeParse(localStorage.getItem(key), null);
    if (saved && /^[A-F0-9]{24}$/.test(saved.requestId)) {
        return saved;
    }

    const attempt = { requestId: createCheckoutRequestId() };
    localStorage.setItem(key, JSON.stringify(attempt));
    return attempt;
}

function sameProductId(left, right) {
    return isDemoCatalog
        ? String(left).toLowerCase() === String(right).toLowerCase()
        : String(left) === String(right);
}

function findProduct(productId) {
    return productCatalog.find(item => sameProductId(item.id, productId)) || null;
}

function catalogImage(value) {
    if (value) {
        try {
            const url = new URL(value, window.location.href);
            if (url.protocol === 'http:' || url.protocol === 'https:') return url.href;
        } catch {
            // Invalid image addresses use the local placeholder.
        }
    }
    return 'images/logodoan.png';
}

function productGallery(value, mainImage) {
    let images = value;
    if (typeof images === 'string') {
        try {
            images = JSON.parse(images);
        } catch (error) {
            console.error('ARSENAL product gallery data is invalid:', error);
            return [];
        }
    }
    if (!Array.isArray(images)) return [];
    return [...new Set(images
        .filter(image => typeof image === 'string' && image.trim() !== '')
        .map(catalogImage)
        .filter(image => image !== mainImage))];
}

function productImageGalleryMarkup(product) {
    const images = [product.image, ...(Array.isArray(product.gallery) ? product.gallery : [])];
    return `
        <div class="product-gallery" data-product-gallery>
            <div class="product-detail-image">
                <img data-product-main-image src="${escapeHtml(product.image)}" alt="${escapeHtml(product.name)}" onerror="this.onerror=null;this.src='images/logodoan.png'">
                ${images.length > 1 ? `<span class="product-gallery-count" data-product-image-count>01 <span>/ ${String(images.length).padStart(2, '0')}</span></span>` : ''}
            </div>
            ${images.length > 1 ? `<div class="product-gallery-thumbnails" aria-label="Ảnh sản phẩm">
                ${images.map((image, index) => `
                    <button type="button" class="product-gallery-thumbnail${index === 0 ? ' is-active' : ''}" data-product-image="${escapeHtml(image)}" data-product-image-index="${index + 1}" aria-label="Xem ảnh ${index + 1} của ${escapeHtml(product.name)}" aria-pressed="${index === 0}">
                        <img src="${escapeHtml(image)}" alt="" loading="lazy" onerror="this.closest('button').hidden=true">
                    </button>
                `).join('')}
            </div>` : ''}
        </div>
    `;
}

function catalogNotice(emptyMessage) {
    if (catalogState === 'error') {
        return `<div class="search-empty col-span-full" role="status">
            <p>Chưa tải được sản phẩm. Hãy mở web bằng Apache XAMPP và kiểm tra cơ sở dữ liệu arsenal.</p>
            <button type="button" class="button button-secondary mt-4" data-retry-catalog>Thử lại</button>
        </div>`;
    }

    const message = catalogState === 'ready' ? emptyMessage : 'Đang tải sản phẩm…';
    return `<div class="search-empty col-span-full" role="status">${escapeHtml(message)}</div>`;
}

function productCategoryLabel(product) {
    return [product.category, product.grade].filter(Boolean).join(' · ') || 'Mô hình';
}

function isToolProduct(product) {
    const category = String(product.category || '').toLocaleLowerCase('vi');
    return TOOL_PRODUCT_CATEGORIES.some(toolCategory => toolCategory.toLocaleLowerCase('vi') === category);
}

function renderUnitProductCard(product, savedProducts) {
    const id = escapeHtml(product.id);
    const name = escapeHtml(product.name);
    const saved = savedProducts.includes(String(product.id));
    const hasStock = product.stock !== 0;
    const statusTone = product.status === 'ĐẶT TRƯỚC' ? 'is-preorder'
        : product.status === 'SẮP HẾT' || !hasStock ? 'is-low-stock'
            : 'is-available';

    return `
        <article class="product-card home-unit-card unit-product-card group relative flex flex-col cursor-pointer" data-product-card="${id}" tabindex="0" aria-label="Xem chi tiết ${name}">
            <div class="unit-image-stage">
                <span class="unit-status ${statusTone}"><i class="unit-status-light" aria-hidden="true"></i>${escapeHtml(product.status)}</span>
                <img src="${escapeHtml(product.image)}" alt="" class="mecha-img" onerror="this.onerror=null;this.src='images/logodoan.png'">
            </div>
            <div class="unit-card-details">
                <div class="unit-card-classification">
                    <span>${escapeHtml(productCategoryLabel(product))}</span>
                    ${product.scale ? `<span class="unit-scale">${escapeHtml(product.scale)}</span>` : ''}
                </div>
                <h3><a href="chi-tiet.html?id=${encodeURIComponent(product.id)}">${name}</a></h3>
                <div class="unit-series">
                    <span>Dòng</span>
                    <strong>${product.series ? escapeHtml(product.series) : 'ARSENAL STANDARD'}</strong>
                </div>
                <div class="unit-card-footer">
                    <div class="unit-price">
                        <span>Giá bán</span>
                        <strong>${formatPrice(product.price)}</strong>
                    </div>
                    <div class="product-actions">
                        <button type="button" data-toggle-favorite="${id}" aria-label="Lưu ${name} vào yêu thích" class="favorite-btn ${saved ? 'is-saved' : ''}" aria-pressed="${saved}">
                            <i class="${saved ? 'fa-solid' : 'fa-regular'} fa-heart"></i>
                        </button>
                        <button type="button" data-add-cart="${id}" aria-label="Thêm ${name} vào giỏ hàng" ${hasStock ? '' : 'disabled'}>
                            <i class="fa-solid fa-cart-shopping text-sm"></i>
                        </button>
                    </div>
                </div>
            </div>
        </article>`;
}

function renderHomeProducts() {
    const container = document.getElementById('product-container');
    if (!container) return;

    const savedProducts = getSavedProducts();
    const featuredProducts = productCatalog.filter(product => !isToolProduct(product)).slice(0, 4);
    container.classList.toggle('has-three-featured-products', featuredProducts.length === 3);
    container.innerHTML = featuredProducts.length
        ? featuredProducts.map(product => renderUnitProductCard(product, savedProducts)).join('')
        : catalogNotice('Chưa có mô hình đang bán.');

    document.dispatchEvent(new Event('arsenal:catalog-rendered'));
}

function updateCartBadge() {
    const count = getCart().reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    document.querySelectorAll('[data-cart-badge]').forEach(element => {
        element.textContent = count;
        element.hidden = count === 0;
    });
}

function showToast(message) {
    const container = document.getElementById('site-toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = 'site-toast';
    toast.textContent = message;
    container.appendChild(toast);

    window.setTimeout(() => {
        toast.classList.add('is-visible');
    }, 20);

    window.setTimeout(() => {
        toast.remove();
    }, 2200);
}

function renderCartSummary() {
    const cart = getCart();
    const subtotal = cart.reduce((sum, item) => sum + (Number(item.price || 0) * Number(item.quantity || 0)), 0);

    const summary = document.getElementById('cart-page-summary') || document.getElementById('cart-summary');
    if (!summary) return;

    summary.innerHTML = `
        <div class="flex justify-between text-mecha-gray text-xs uppercase tracking-[0.2em]"><span>Tiền hàng dự kiến</span><span>${formatPrice(subtotal)}</span></div>
        <div class="mt-2 flex justify-between text-mecha-gray text-xs uppercase tracking-[0.2em]"><span>Phí vận chuyển</span><span>Xác nhận khi đặt hàng</span></div>
        <div class="mt-3 border-t border-mecha-panel pt-3 text-xs text-mecha-gray">Máy chủ sẽ xác nhận tổng tiền trước khi tạo đơn.</div>
    `;
}

function renderCart() {
    const drawer = document.getElementById('cart-page-items') || document.getElementById('cart-drawer-body');
    const cart = getCart();
    if (!drawer) {
        updateCartBadge();
        return;
    }

    if (!cart.length) {
        drawer.innerHTML = `
            <div class="border border-dashed border-mecha-panel p-8 text-center text-mecha-gray uppercase tracking-[0.2em]">
                <p>Giỏ hàng trống</p>
                <button type="button" data-action="search" class="button button-primary mt-5">Khám phá mô hình</button>
            </div>
        `;
        renderCartSummary();
        updateCartBadge();
        return;
    }

    drawer.innerHTML = cart.map(item => {
        const product = findProduct(item.id) || { id: item.id, name: item.name || 'Sản phẩm', image: 'images/logodoan.png', grade: 'Mô hình', scale: '' };
        const id = escapeHtml(product.id);
        const name = escapeHtml(product.name);
        return `
            <div class="cart-item">
                <div class="cart-thumb">
                    <img src="${escapeHtml(product.image)}" alt="${name}" onerror="this.onerror=null;this.src='images/logodoan.png'">
                </div>
                <div class="cart-item-body">
                    <div class="cart-item-top">
                        <h4>${name}</h4>
                        <button type="button" data-remove-cart="${id}" aria-label="Xóa ${name}" class="cart-remove">×</button>
                    </div>
                    <p>${escapeHtml([product.category, product.grade, product.scale].filter(Boolean).join(' · '))}</p>
                    <div class="cart-controls">
                        <div class="quantity-box">
                            <button type="button" data-cart-qty="decrease" data-product-id="${id}" aria-label="Giảm số lượng">−</button>
                            <span>${Number(item.quantity) || 0}</span>
                            <button type="button" data-cart-qty="increase" data-product-id="${id}" aria-label="Tăng số lượng">+</button>
                        </div>
                        <strong>${formatPrice(Number(item.price || 0) * Number(item.quantity || 0))}</strong>
                    </div>
                </div>
            </div>
        `;
    }).join('');

    renderCartSummary();
    updateCartBadge();
}

function addToCart(productId, quantity = 1) {
    const product = findProduct(productId);
    if (!product) {
        showToast('Không tìm thấy sản phẩm.');
        return;
    }
    if (!Number.isInteger(quantity) || quantity < 1) return;

    const cart = getCart();
    const existing = cart.find(item => sameProductId(item.id, productId));
    const nextQuantity = Number(existing?.quantity || 0) + quantity;
    if (product.stock !== undefined && nextQuantity > product.stock) {
        showToast('Số lượng vượt quá tồn kho hiện có.');
        return;
    }

    if (existing) {
        existing.quantity = nextQuantity;
        existing.price = getNumericPrice(product.price);
    } else {
        cart.push({
            id: product.id,
            name: product.name,
            price: getNumericPrice(product.price),
            quantity
        });
    }

    saveCart(cart);
    renderCart();
    showToast(`${product.name} đã thêm vào giỏ hàng.`);
}

function updateCartQuantity(productId, change) {
    const cart = getCart();
    const item = cart.find(entry => sameProductId(entry.id, productId));
    if (!item) return;

    const nextQuantity = Number(item.quantity) + change;
    const product = findProduct(productId);
    if (!isDemoCatalog && change > 0 && (!product || nextQuantity > product.stock)) {
        showToast('Không thể tăng số lượng. Hãy kiểm tra lại tồn kho.');
        return;
    }

    item.quantity = nextQuantity;
    if (item.quantity <= 0) {
        const nextCart = cart.filter(entry => !sameProductId(entry.id, productId));
        saveCart(nextCart);
    } else {
        saveCart(cart);
    }

    renderCart();
}

function removeFromCart(productId) {
    const nextCart = getCart().filter(item => !sameProductId(item.id, productId));
    saveCart(nextCart);
    renderCart();
    showToast('Sản phẩm đã được xóa khỏi giỏ hàng.');
}

function openCart() {
    const overlay = document.getElementById('site-overlay');
    const panel = document.getElementById('cart-drawer');
    if (!overlay || !panel) return;
    overlay.classList.add('is-open');
    panel.classList.add('is-open');
    renderCart();
}

function closePanels() {
    const overlay = document.getElementById('site-overlay');
    const searchModal = document.getElementById('search-modal');
    const cartPanel = document.getElementById('cart-drawer');
    overlay?.classList.remove('is-open');
    searchModal?.classList.remove('is-open');
    cartPanel?.classList.remove('is-open');
}

function openSearch() {
    const overlay = document.getElementById('site-overlay');
    const modal = document.getElementById('search-modal');
    if (!overlay || !modal) return;
    overlay.classList.add('is-open');
    modal.classList.add('is-open');
    const input = document.getElementById('product-search');
    if (input) {
        window.setTimeout(() => input.focus(), 60);
    }
}

function updateSearchResults() {
    const getSearchControl = id => document.getElementById(id);
    const results = document.getElementById('search-results');
    if (!results) return;
    if (catalogState !== 'ready') {
        results.innerHTML = catalogNotice('Chưa có sản phẩm đang bán.');
        return;
    }

    const normalized = String(getSearchControl('product-search')?.value || '').trim().toLowerCase();
    const grade = String(getSearchControl('product-grade')?.value || '').toLowerCase();
    const series = String(getSearchControl('product-series')?.value || '').toLowerCase();
    const priceRange = String(getSearchControl('product-price')?.value || '');
    const favoritesOnly = Boolean(getSearchControl('favorites-only')?.checked);
    const sort = getSearchControl('product-sort')?.value || 'featured';
    const saved = getSavedProducts();
    const matchesFilter = (value, filter) => !filter || (isDemoCatalog
        ? value.toLowerCase().includes(filter)
        : value.toLowerCase() === filter);
    const filtered = productCatalog.filter(item => {
        const price = getNumericPrice(item.price);
        return [item.id, item.name, item.gradeCode, item.grade, item.series, item.scale, item.category].join(' ').toLowerCase().includes(normalized)
            && matchesFilter(item.grade, grade)
            && matchesFilter(item.series, series)
            && (!priceRange || (priceRange === 'low' && price < lowerPriceLimit)
                || (priceRange === 'middle' && price >= lowerPriceLimit && price <= upperPriceLimit)
                || (priceRange === 'high' && price > upperPriceLimit))
            && (!favoritesOnly || saved.includes(String(item.id)));
    });

    filtered.sort((a, b) => {
        if (sort === 'price-asc') return getNumericPrice(a.price) - getNumericPrice(b.price);
        if (sort === 'price-desc') return getNumericPrice(b.price) - getNumericPrice(a.price);
        if (sort === 'name') return a.name.localeCompare(b.name);
        return productCatalog.indexOf(a) - productCatalog.indexOf(b);
    });

    if (!filtered.length) {
        results.innerHTML = '<div class="search-empty">Không tìm thấy mô hình phù hợp.</div>';
        return;
    }

    results.innerHTML = filtered.map(item => `
        <a class="search-result-item" href="chi-tiet.html?id=${encodeURIComponent(item.id)}" aria-label="Xem chi tiết ${escapeHtml(item.name)}">
            <div class="search-result-thumb"><img src="${escapeHtml(item.image)}" alt="${escapeHtml(item.name)}" onerror="this.onerror=null;this.src='images/logodoan.png'"></div>
            <div>
                <strong>${escapeHtml(item.name)}</strong>
                <span>${escapeHtml(item.grade || item.category)}</span>
                <small>${formatPrice(item.price)}</small>
            </div>
        </a>
    `).join('');
}

function renderFavoriteProducts() {
    const results = document.getElementById('favorite-products');
    if (!results) return;
    if (catalogState !== 'ready') {
        results.innerHTML = catalogNotice('Chưa có mô hình yêu thích.');
        return;
    }

    const saved = getSavedProducts();
    const products = productCatalog.filter(item => saved.includes(String(item.id)));
    if (!products.length) {
        results.innerHTML = `
            <div class="border border-dashed border-mecha-panel p-8 text-center text-mecha-gray">
                <p class="uppercase tracking-[0.2em]">Chưa có mô hình yêu thích</p>
                <button type="button" data-action="search" class="button button-primary mt-5">Tìm mô hình</button>
            </div>
        `;
        return;
    }

    results.innerHTML = products.map(item => `
        <article class="product-card bg-mecha-dark border border-mecha-panel cut-corner flex flex-col">
            <a href="chi-tiet.html?id=${encodeURIComponent(item.id)}" class="block p-5">
                <div class="h-52 bg-mecha-black flex items-center justify-center p-4">
                    <img src="${escapeHtml(item.image)}" alt="${escapeHtml(item.name)}" class="mecha-img h-full w-full object-contain" onerror="this.onerror=null;this.src='images/logodoan.png'">
                </div>
                <h2 class="mt-4 font-orbitron font-bold text-white">${escapeHtml(item.name)}</h2>
                <p class="mt-2 text-sm text-mecha-gray">${escapeHtml([item.grade || item.category, item.scale].filter(Boolean).join(' · '))}</p>
                <strong class="mt-3 block font-orbitron text-mecha-gold">${formatPrice(item.price)}</strong>
            </a>
            <div class="flex gap-3 p-5 pt-0">
                <button type="button" class="button button-secondary" data-toggle-favorite="${escapeHtml(item.id)}" aria-pressed="true">Bỏ yêu thích</button>
                <button type="button" class="button button-primary" data-add-cart="${escapeHtml(item.id)}" ${item.stock === 0 ? 'disabled' : ''}>${item.stock === 0 ? 'Hết hàng' : 'Thêm vào giỏ'}</button>
            </div>
        </article>
    `).join('');
}

function getSearchPanelMarkup() {
    return `
        <div id="search-modal" class="site-search-modal" aria-label="Tìm kiếm sản phẩm">
            <div class="site-search-header">
                <div>
                    <p class="font-orbitron text-[10px] uppercase tracking-[0.3em] text-mecha-gold">Search</p>
                    <h3 class="font-orbitron text-2xl uppercase">Tìm kiếm</h3>
                </div>
                <button type="button" class="close-panel" aria-label="Đóng tìm kiếm" data-close-panel="search">×</button>
            </div>
            <div class="product-filters" aria-label="Lọc và sắp xếp sản phẩm">
                <label>
                    <span>Tìm sản phẩm</span>
                    <input id="product-search" type="search" placeholder="Tên, grade hoặc series" aria-label="Tìm sản phẩm">
                </label>
                <label>
                    <span>Grade</span>
                    <select id="product-grade">
                        <option value="">Tất cả Grade</option>
                        <option value="HIGH GRADE">HG</option>
                        <option value="REAL GRADE">RG</option>
                        <option value="MASTER GRADE">MG</option>
                        <option value="PERFECT GRADE">PG</option>
                    </select>
                </label>
                <label>
                    <span>Series</span>
                    <select id="product-series">
                        <option value="">Tất cả series</option>
                        <option value="MOBILE SUIT GUNDAM">Mobile Suit Gundam</option>
                        <option value="GUNDAM SEED">Gundam SEED</option>
                        <option value="CHAR'S COUNTERATTACK">Char's Counterattack</option>
                        <option value="WITCH FROM MERCURY">The Witch from Mercury</option>
                    </select>
                </label>
                <label>
                    <span>Khoảng giá</span>
                    <select id="product-price">
                        <option value="">Mọi mức giá</option>
                        <option value="low">Dưới ${formatPrice(lowerPriceLimit)}</option>
                        <option value="middle">${formatPrice(lowerPriceLimit)} – ${formatPrice(upperPriceLimit)}</option>
                        <option value="high">Trên ${formatPrice(upperPriceLimit)}</option>
                    </select>
                </label>
                <label>
                    <span>Sắp xếp</span>
                    <select id="product-sort">
                        <option value="featured">Nổi bật</option>
                        <option value="price-asc">Giá tăng dần</option>
                        <option value="price-desc">Giá giảm dần</option>
                        <option value="name">Tên A–Z</option>
                    </select>
                </label>
                <label class="product-favorites-filter">
                    <input id="favorites-only" type="checkbox">
                    <span>Chỉ xem yêu thích</span>
                </label>
            </div>
            <div id="search-results" class="search-results"></div>
        </div>
    `;
}

function initializePanels() {
    if (document.getElementById('site-panels')) return;

    const currentPage = window.location.pathname.split('/').pop() || 'index.html';
    const isHomePage = currentPage === 'index.html';

    if (isHomePage) {
        document.body.insertAdjacentHTML('beforeend', `
        <div id="site-panels">
            <div id="site-overlay" aria-hidden="true"></div>
            <aside id="cart-drawer" class="site-panel" aria-label="Giỏ hàng">
                <div class="site-panel-header">
                    <div>
                        <p class="font-orbitron text-[10px] uppercase tracking-[0.3em] text-mecha-gold">Console</p>
                        <h3 class="font-orbitron text-2xl uppercase">Giỏ hàng</h3>
                    </div>
                    <button type="button" class="close-panel" aria-label="Đóng giỏ hàng" data-close-panel="cart">×</button>
                </div>
                <div id="cart-drawer-body" class="site-panel-body"></div>
                <div class="site-panel-footer">
                    <div id="cart-summary" class="cart-summary"></div>
                    <p class="demo-notice">Giá và phí vận chuyển được xác nhận trên máy chủ. Chuyển khoản chỉ hiện khi cửa hàng đã cấu hình tài khoản nhận.</p>
                    <form id="checkout-form">
                        <div class="field-grid">
                            <label>
                                <span>Họ tên</span>
                                <input type="text" name="customerName" placeholder="Nguyễn Văn A" required>
                            </label>
                            <label>
                                <span>Số điện thoại</span>
                                <input type="tel" name="phone" placeholder="0909 123 456" required>
                            </label>
                            <label class="field-full">
                                <span>Địa chỉ nhận hàng</span>
                                <textarea name="address" rows="3" placeholder="Số nhà, đường, quận, tỉnh" required></textarea>
                            </label>
                            <label class="field-full">
                                <span>Mã giảm giá (nếu có)</span>
                                <input type="text" name="couponCode" maxlength="32" autocomplete="off">
                            </label>
                            <label class="field-full">
                                <span>Phương thức thanh toán</span>
                                <select name="paymentMethod">
                                    <option value="COD">Thanh toán khi nhận hàng (COD)</option>
                                </select>
                            </label>
                        </div>
                        <p class="checkout-transfer-details field-full" hidden></p>
                        <button type="submit" class="button button-primary w-full">Đặt hàng</button>
                    </form>
                </div>
            </aside>
            ${getSearchPanelMarkup()}
            <section id="product-detail-modal" class="site-search-modal product-detail-modal" role="dialog" aria-modal="true" aria-labelledby="product-detail-title" aria-hidden="true">
                <div id="product-detail-content"></div>
            </section>
            <div id="site-toast-container" class="site-toast-container" aria-live="polite"></div>
        </div>
    `);
    } else {
        document.body.insertAdjacentHTML('beforeend', `
            <div id="site-panels">
                <div id="site-overlay" aria-hidden="true"></div>
                ${getSearchPanelMarkup()}
                <div id="site-toast-container" class="site-toast-container" aria-live="polite"></div>
            </div>
        `);
    }

    document.getElementById('site-overlay')?.addEventListener('click', closePanels);
    document.querySelectorAll('[data-close-panel]').forEach(button => {
        button.addEventListener('click', () => {
            closePanels();
        });
    });

    document.addEventListener('input', event => {
        if (event.target && event.target.id === 'product-search') {
            updateSearchResults();
        }
    });

    document.addEventListener('click', event => {
        if (event.target.closest('[data-retry-catalog]')) loadProductCatalog();

        const actionButton = event.target.closest('[data-action]');
        if (actionButton) {
            const action = actionButton.dataset.action;
            if (action === 'search') openSearch();
            if (action === 'cart') openCart();
        }

        const cartQtyHandler = event.target.closest('[data-cart-qty]');
        if (cartQtyHandler) {
            const { productId, cartQty } = cartQtyHandler.dataset;
            updateCartQuantity(productId, cartQty === 'increase' ? 1 : -1);
        }

        const removeButton = event.target.closest('[data-remove-cart]');
        if (removeButton) {
            removeFromCart(removeButton.dataset.removeCart);
        }

        const addToCartButton = event.target.closest('[data-add-cart]');
        if (addToCartButton) {
            addToCart(addToCartButton.dataset.addCart, Number(addToCartButton.dataset.quantity || 1));
        }

        const wishlistButton = event.target.closest('[data-toggle-favorite]');
        if (wishlistButton) {
            const id = wishlistButton.dataset.toggleFavorite;
            const saved = getSavedProducts();
            const next = saved.includes(id) ? saved.filter(item => item !== id) : [...saved, id];
            saveSavedProducts(next);
            const isSaved = next.includes(id);
            const icon = wishlistButton.querySelector('i');
            wishlistButton.classList.toggle('is-saved', isSaved);
            if (icon) {
                icon.classList.toggle('fa-solid', isSaved);
                icon.classList.toggle('fa-regular', !isSaved);
            }
            wishlistButton.setAttribute('aria-pressed', String(isSaved));
            updateSearchResults();
            renderFavoriteProducts();
            showToast(isSaved ? 'Đã lưu vào yêu thích.' : 'Đã bỏ khỏi yêu thích.');
        }

        const detailButton = event.target.closest('[data-product-detail]');
        if (detailButton) {
            openProductDetail(detailButton.dataset.productDetail);
        }

        const detailClose = event.target.closest('[data-close-detail]');
        if (detailClose) {
            closeProductDetail();
        }

        const productImageButton = event.target.closest('[data-product-image]');
        if (productImageButton) {
            const gallery = productImageButton.closest('[data-product-gallery]');
            const mainImage = gallery?.querySelector('[data-product-main-image]');
            if (mainImage) {
                mainImage.src = productImageButton.dataset.productImage;
                gallery.querySelectorAll('[data-product-image]').forEach(button => {
                    const active = button === productImageButton;
                    button.classList.toggle('is-active', active);
                    button.setAttribute('aria-pressed', String(active));
                });
                const count = gallery.querySelector('[data-product-image-count]');
                if (count) {
                    count.innerHTML = `${String(productImageButton.dataset.productImageIndex).padStart(2, '0')} <span>/ ${String(gallery.querySelectorAll('[data-product-image]').length).padStart(2, '0')}</span>`;
                }
            }
        }

        const card = event.target.closest('[data-product-card]');
        if (card && !event.target.closest('button, a, input, select')) {
            window.location.href = `chi-tiet.html?id=${encodeURIComponent(card.dataset.productCard)}`;
        }
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closePanels();
            closeProductDetail();
            const paymentModal = document.getElementById('payment-modal');
            if (paymentModal) paymentModal.hidden = true;
            document.body.classList.remove('payment-modal-open');
            window.clearInterval(window.arsenalPaymentCountdown);
            stopPaymentStatusPolling();
        }

        if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('[data-product-card]')) {
            event.preventDefault();
            window.location.href = `chi-tiet.html?id=${encodeURIComponent(event.target.dataset.productCard)}`;
        }
    });

    document.addEventListener('input', event => {
        if (event.target.matches('#product-search, #product-grade, #product-series, #product-price, #product-sort, #favorites-only')) {
            updateSearchResults();
        }
    });

    document.addEventListener('change', event => {
        if (event.target.matches('#product-search, #product-grade, #product-series, #product-price, #product-sort, #favorites-only')) {
            updateSearchResults();
        }
    });

    document.addEventListener('submit', async event => {
        if (event.target && event.target.id === 'checkout-form') {
            event.preventDefault();
            const form = event.target;
            const cart = getCart();
            if (!cart.length) {
                showToast('Giỏ hàng trống.');
                return;
            }

            if (isDemoCatalog) {
                const formData = new FormData(form);
                const customerName = String(formData.get('customerName') || '').trim();
                const phone = String(formData.get('phone') || '').trim();
                const address = String(formData.get('address') || '').trim();
                if (!customerName || !phone || !address) {
                    showToast('Vui lòng điền đầy đủ thông tin nhận hàng.');
                    return;
                }

                const order = {
                    id: `ARS-${Date.now().toString().slice(-6)}`,
                    customerName,
                    phone,
                    address,
                    items: cart,
                    createdAt: new Date().toISOString(),
                    status: 'Đã tiếp nhận',
                    total: cart.reduce((sum, item) => sum + Number(item.price || 0) * Number(item.quantity || 0), 0)
                };
                const orders = getOrders();
                orders.unshift(order);
                saveOrders(orders);
                saveCart([]);
                form.reset();
                renderCart();
                closePanels();
                showToast(`Đã lưu đơn demo trên trình duyệt. Mã tra cứu: ${order.id}`);
                return;
            }

            if (cart.some(item => {
                const product = findProduct(item.id);
                return !product || Number(item.quantity) > product.stock;
            })) {
                showToast('Hãy kiểm tra lại tình trạng và số lượng sản phẩm trong giỏ.');
                return;
            }

            const formData = new FormData(form);
            const customerName = String(formData.get('customerName') || '').trim();
            const phone = String(formData.get('phone') || '').trim();
            const address = String(formData.get('address') || '').trim();
            const couponCode = String(formData.get('couponCode') || '').trim().toUpperCase();
            const paymentMethod = String(formData.get('paymentMethod') || 'COD').trim().toUpperCase();

            if (!customerName || !phone || !address) {
                showToast('Vui lòng điền đầy đủ thông tin nhận hàng.');
                return;
            }

            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) submitButton.disabled = true;
            try {
                let attempt = getCheckoutAttempt();
                if ((attempt.couponCode || '') !== couponCode || (attempt.paymentMethod || 'COD') !== paymentMethod) {
                    attempt = { requestId: createCheckoutRequestId(), confirmedTotal: null, couponCode, paymentMethod };
                    localStorage.setItem(STORAGE_KEYS.checkoutAttempt, JSON.stringify(attempt));
                }
                const payload = {
                    requestId: attempt.requestId,
                    customerName,
                    phone,
                    address,
                    couponCode,
                    paymentMethod,
                    items: cart.map(item => ({
                        id: String(item.id),
                        quantity: Number(item.quantity),
                        price: Number(item.price)
                    })),
                    confirmedTotal: attempt.confirmedTotal
                };

                let result = null;
                await initializeCustomerSession();
                for (let attemptCount = 0; attemptCount < 3; attemptCount += 1) {
                    const response = await fetch('api/dat-hang.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            ...(customerSession && customerCsrfToken ? { 'X-CSRF-Token': customerCsrfToken } : {})
                        },
                        body: JSON.stringify(payload)
                    });
                    result = await response.json();

                    if (response.status === 409 && ['PRICE_CHANGED', 'CONFIRM_TOTAL'].includes(result.code)) {
                        const confirmed = window.confirm(
                            `${result.code === 'PRICE_CHANGED' ? 'Giá hoặc tổng tiền đã thay đổi.' : 'Xác nhận tổng tiền đơn hàng.'}\n` +
                            `Tiền hàng: ${formatPrice(result.tienHang)}\n` +
                            `Giảm giá: ${formatPrice(result.tienGiam || 0)}\n` +
                            `Phí vận chuyển: ${formatPrice(result.phiVanChuyen)}\nTổng thanh toán: ${formatPrice(result.tongPhaiTra)}\n\n` +
                            'Bạn có xác nhận đặt hàng với tổng tiền mới không?'
                        );
                        if (!confirmed) {
                            showToast('Đơn chưa được tạo vì bạn chưa xác nhận tổng tiền mới.');
                            return;
                        }

                        payload.confirmedTotal = Number(result.tongPhaiTra);
                        attempt.confirmedTotal = payload.confirmedTotal;
                        localStorage.setItem(STORAGE_KEYS.checkoutAttempt, JSON.stringify(attempt));
                        continue;
                    }

                    if (!response.ok) {
                        if (result.code === 'IDEMPOTENCY_CONFLICT') {
                            localStorage.removeItem(STORAGE_KEYS.checkoutAttempt);
                            showToast('Thông tin gửi lại khác yêu cầu đã dùng mã này. Hãy xác nhận lại đơn để nhận mã yêu cầu mới.');
                            return;
                        }
                        showToast(result.message || 'Không thể tạo đơn hàng. Vui lòng thử lại.');
                        return;
                    }

                    localStorage.removeItem(STORAGE_KEYS.checkoutAttempt);
                    saveCart([]);
                    form.reset();
                    renderCart();
                    closePanels();
                    if (!showPaymentModal(result, phone)) {
                        showToast(`Đặt hàng thành công. Mã đơn: ${result.maDH} · Tổng phải trả: ${formatPrice(result.tongPhaiTra)}`);
                    }
                    return;
                }

                showToast(result?.message || 'Giá tiếp tục thay đổi. Vui lòng tải lại giỏ hàng và thử lại.');
            } catch (error) {
                console.error('ARSENAL checkout failed:', error);
                showToast('Không kết nối được máy chủ đặt hàng. Giỏ hàng vẫn được giữ nguyên; hãy thử lại.');
            } finally {
                if (submitButton) submitButton.disabled = false;
            }
        }

        if (event.target && event.target.id === 'preorder-form') {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(event.target);
            const payload = {
                productId: String(formData.get('productId') || ''),
                name: String(formData.get('name') || '').trim(),
                phone: String(formData.get('phone') || '').trim(),
                email: String(formData.get('email') || '').trim(),
                quantity: Number(formData.get('quantity') || 1)
            };
            const button = form.querySelector('button[type="submit"]');
            if (!payload.productId || !payload.name || !payload.phone) {
                showToast('Vui lòng điền đủ thông tin đặt trước.');
                return;
            }
            if (button) button.disabled = true;
            try {
                const attempt = getPreorderAttempt();
                payload.requestId = attempt.requestId;
                if (!commerceCsrfToken) {
                    const state = await fetch('api/dat-truoc.php', { headers: { Accept: 'application/json' } });
                    const data = await state.json();
                    if (!state.ok) throw new Error(data.message || 'Không thể khởi tạo yêu cầu đặt trước.');
                    commerceCsrfToken = data.csrfToken;
                }
                const response = await fetch('api/dat-truoc.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-Token': commerceCsrfToken
                    },
                    body: JSON.stringify(payload)
                });

                const result = await response.json();
                if (!response.ok) {
                    if (response.status === 409) localStorage.removeItem('arsenalPreorderAttempt');
                    throw new Error(result.message || 'Không thể gửi yêu cầu đặt trước.');
                }
                localStorage.removeItem('arsenalPreorderAttempt');
                form.reset();
                showToast(`Đã gửi yêu cầu đặt trước. Mã yêu cầu: ${result.requestId}`);
            } catch (error) {
                console.error('ARSENAL preorder failed:', error);
                showToast(error.message || 'Không kết nối được máy chủ đặt trước.');
            } finally {
                if (button) button.disabled = false;
            }
        }

        if (event.target && event.target.id === 'order-tracker-form') {
            event.preventDefault();
            const formData = new FormData(event.target);
            const orderId = String(formData.get('orderId') || '').trim();
            const phone = String(formData.get('phone') || '').trim();
            const result = document.getElementById('tracker-result');

            if (!result) return;
            result.innerHTML = '<div class="tracker-empty">Đang tra cứu đơn hàng…</div>';

            try {
                const response = await fetch('api/tra-cuu-don.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ orderId, phone })
                });
                const order = await response.json();
                if (!response.ok) {
                    result.innerHTML = `<div class="tracker-empty">${escapeHtml(order.message || 'Không thể tra cứu đơn hàng.')}</div>`;
                    return;
                }

                const itemsMarkup = order.items.map(item => `
                    <div class="flex justify-between gap-4 border-t border-mecha-panel py-2">
                        <span>${escapeHtml(item.name)} × ${Number(item.quantity)}</span>
                        <strong>${formatPrice(item.lineTotal)}</strong>
                    </div>
                `).join('');
                const transferMarkup = order.phuongThucThanhToan === 'CHUYEN_KHOAN'
                    ? `<div class="mt-3 border-t border-mecha-panel pt-3">
                        <p><strong>Ngân hàng:</strong> ${escapeHtml(order.tenNganHangNhan || '')}</p>
                        <p><strong>Chủ tài khoản:</strong> ${escapeHtml(order.chuTaiKhoanNhan || '')}</p>
                        <p><strong>Số tài khoản:</strong> ${escapeHtml(order.soTaiKhoanNhan || '')}</p>
                        <p><strong>Nội dung chuyển khoản:</strong> ${escapeHtml(order.maDH)}</p>
                    </div>`
                    : '';
                result.innerHTML = `
                    <div class="tracker-card">
                        <div class="tracker-header"><span>Mã đơn</span><strong>${escapeHtml(order.maDH)}</strong></div>
                        <div class="tracker-body">
                            <p><strong>Khách hàng:</strong> ${escapeHtml(order.hoTenNhan)}</p>
                            <p><strong>Trạng thái:</strong> ${escapeHtml(order.trangThaiXuLy)}</p>
                            <p><strong>Thanh toán:</strong> ${escapeHtml(order.phuongThucThanhToan)} · ${escapeHtml(order.trangThaiThanhToan || 'CHUA_THANH_TOAN')}${order.phuongThucThanhToan === 'COD' ? ' (thanh toán khi nhận hàng)' : ''}</p>
                            <p><strong>Thời gian:</strong> ${escapeHtml(new Date(`${String(order.datLuc).replace(' ', 'T')}Z`).toLocaleString('vi-VN'))}</p>
                            <p><strong>Địa chỉ:</strong> ${escapeHtml(order.diaChiNhan)}</p>
                            <div class="mt-4">${itemsMarkup}</div>
                            <p class="mt-3"><strong>Tiền hàng:</strong> ${formatPrice(order.tienHang)}</p>
                            <p><strong>Phí vận chuyển:</strong> ${formatPrice(order.phiVanChuyen)}</p>
                            <p><strong>Tổng phải trả:</strong> ${formatPrice(order.tongPhaiTra)}</p>
                            ${transferMarkup}
                        </div>
                    </div>
                `;
            } catch (error) {
                console.error('ARSENAL order lookup failed:', error);
                result.innerHTML = '<div class="tracker-empty">Không kết nối được máy chủ tra cứu. Vui lòng thử lại.</div>';
            }
        }

        if (event.target && event.target.id === 'review-form') {
            event.preventDefault();
            const form = event.target;
            const formData = new FormData(event.target);
            const productId = String(formData.get('productId') || '');
            const orderId = String(formData.get('orderId') || '').trim();
            const phone = String(formData.get('phone') || '').trim();
            const rating = Number(formData.get('rating'));
            const comment = String(formData.get('comment') || '').trim();
            if (!productId || !orderId || !phone || rating < 1 || rating > 5 || !comment) {
                showToast('Cần mã đơn đã giao, số điện thoại người nhận, số sao và nhận xét.');
                return;
            }
            const button = form.querySelector('button[type="submit"]');
            if (button) button.disabled = true;
            try {
                if (!commerceCsrfToken) {
                    const state = await fetch(`api/danh-gia.php?productId=${encodeURIComponent(productId)}`, {
                        headers: { Accept: 'application/json' }
                    });
                    const data = await state.json();
                    if (!state.ok) throw new Error(data.message || 'Không thể khởi tạo đánh giá.');
                    commerceCsrfToken = data.csrfToken;
                }
                const response = await fetch('api/danh-gia.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-Token': commerceCsrfToken
                    },
                    body: JSON.stringify({ productId, orderId, phone, rating, comment })
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || 'Không thể gửi đánh giá.');
                form.reset();
                showToast(result.message);
            } catch (error) {
                console.error('ARSENAL review submit failed:', error);
                showToast(error.message || 'Không kết nối được máy chủ đánh giá.');
            } finally {
                if (button) button.disabled = false;
            }
        }

        if (event.target && event.target.id === 'newsletter-form') {
            event.preventDefault();
            const email = String(new FormData(event.target).get('email') || '').trim().toLowerCase();
            const subscriptions = getStoredArray(STORAGE_KEYS.newsletter);
            if (subscriptions.includes(email)) {
                showToast('Email này đã đăng ký trên trình duyệt.');
                return;
            }
            subscriptions.push(email);
            localStorage.setItem(STORAGE_KEYS.newsletter, JSON.stringify(subscriptions));
            event.target.reset();
            showToast('Đã lưu đăng ký demo trên trình duyệt.');
        }

        if (event.target && event.target.id === 'contact-form') {
            event.preventDefault();
            const formData = new FormData(event.target);
            const messages = getStoredArray(STORAGE_KEYS.messages);
            messages.unshift({
                name: String(formData.get('name') || '').trim(),
                email: String(formData.get('email') || '').trim(),
                message: String(formData.get('message') || '').trim(),
                createdAt: new Date().toISOString()
            });
            localStorage.setItem(STORAGE_KEYS.messages, JSON.stringify(messages));
            event.target.reset();
            showToast('Đã lưu tin nhắn demo trên trình duyệt.');
        }
    });

    const searchInput = document.getElementById('product-search');
    if (searchInput) {
        updateSearchResults();
    }

    document.querySelectorAll('[data-toggle-favorite]').forEach(button => {
        const id = button.dataset.toggleFavorite;
        const isSaved = getSavedProducts().includes(id);
        button.classList.toggle('is-saved', isSaved);
        const icon = button.querySelector('i');
        if (icon) {
            icon.classList.toggle('fa-solid', isSaved);
            icon.classList.toggle('fa-regular', !isSaved);
        }
        button.setAttribute('aria-pressed', String(isSaved));
    });

    updateSearchResults();
}

function closeProductDetail() {
    const modal = document.getElementById('product-detail-modal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
}

function openProductDetail(productId) {
    const product = findProduct(productId);
    const modal = document.getElementById('product-detail-modal');
    const content = document.getElementById('product-detail-content');
    if (!product || !modal || !content) return;
    const reviews = getProductReviews(product.id);
    const average = reviews.length
        ? (reviews.reduce((sum, review) => sum + Number(review.rating || 0), 0) / reviews.length).toFixed(1)
        : 'Chưa có';
    const reviewMarkup = reviews.length
        ? reviews.map(review => `
            <article class="review-entry">
                <div class="review-entry-heading">
                    <strong>${escapeHtml(review.name || 'Khách')}</strong>
                    <span>${'★'.repeat(Math.max(0, Math.min(5, Number(review.rating) || 0)))}${'☆'.repeat(5 - Math.max(0, Math.min(5, Number(review.rating) || 0)))}</span>
                </div>
                <p>${escapeHtml(review.comment)}</p>
                <small>${escapeHtml(new Date(review.createdAt).toLocaleDateString('vi-VN'))}</small>
            </article>
        `).join('')
        : '<p class="text-mecha-gray">Chưa có đánh giá. Hãy chia sẻ nhận xét đầu tiên.</p>';

    content.innerHTML = `
        <div class="site-search-header">
            <div>
                <p class="font-orbitron text-[10px] uppercase tracking-[0.3em] text-mecha-gold">Thông tin sản phẩm</p>
                <h2 id="product-detail-title" class="font-orbitron text-xl uppercase">${escapeHtml(product.name)}</h2>
            </div>
            <button type="button" class="close-panel" aria-label="Đóng chi tiết" data-close-detail>×</button>
        </div>
        <div class="product-detail-layout">
            ${productImageGalleryMarkup(product)}
            <div class="product-detail-info">
                <p class="font-orbitron text-mecha-gold">${escapeHtml([product.category, product.grade, product.scale].filter(Boolean).join(' · '))}</p>
                <p class="mt-2 text-mecha-gray">${escapeHtml(product.series)}</p>
                <p class="mt-4">${escapeHtml(product.status || 'Tình trạng đang cập nhật')}</p>
                <strong class="mt-4 block font-orbitron text-2xl">${formatPrice(product.price)}</strong>
                <div class="product-actions mt-5">
                    <button type="button" data-toggle-favorite="${escapeHtml(product.id)}" aria-label="Lưu ${escapeHtml(product.name)} vào yêu thích" class="favorite-btn" aria-pressed="false"><i class="fa-regular fa-heart"></i></button>
                    <button type="button" data-add-cart="${escapeHtml(product.id)}" class="button button-primary" ${product.stock === 0 ? 'disabled' : ''}>${product.stock === 0 ? 'Hết hàng' : 'Thêm vào giỏ'}</button>
                </div>
            </div>
        </div>
        <section class="review-section">
            <h3 class="font-orbitron font-bold uppercase">Đánh giá sản phẩm <span class="text-mecha-gold">${average} · ${reviews.length}</span></h3>
            <div class="review-list">${reviewMarkup}</div>
            <form id="review-form" class="review-form">
                <input type="hidden" name="productId" value="${escapeHtml(product.id)}">
                <label>Mã đơn đã giao<input name="orderId" maxlength="32" required></label>
                <label>Số điện thoại người nhận<input name="phone" type="tel" maxlength="20" required></label>
                <label>Điểm đánh giá<select name="rating" required><option value="">Chọn số sao</option><option value="5">5 sao</option><option value="4">4 sao</option><option value="3">3 sao</option><option value="2">2 sao</option><option value="1">1 sao</option></select></label>
                <label class="field-full">Nhận xét<textarea name="comment" rows="3" maxlength="1000" required placeholder="Chia sẻ cảm nhận của bạn"></textarea></label>
                <button type="submit" class="button button-secondary">Gửi đánh giá</button>
            </form>
        </section>
        <p class="demo-notice">Chỉ khách của đơn đã giao mới được đánh giá. Nội dung được hiển thị sau khi cửa hàng kiểm duyệt.</p>
    `;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    const favoriteButton = content.querySelector('[data-toggle-favorite]');
    const isSaved = getSavedProducts().includes(String(product.id));
    favoriteButton.classList.toggle('is-saved', isSaved);
    favoriteButton.setAttribute('aria-pressed', String(isSaved));
    favoriteButton.querySelector('i').classList.toggle('fa-solid', isSaved);
    favoriteButton.querySelector('i').classList.toggle('fa-regular', !isSaved);
    loadProductReviews(product.id, content);
}

function renderProductDetailPage(productId) {
    const content = document.getElementById('product-detail-page');
    if (!content) return;
    if (catalogState !== 'ready') {
        content.innerHTML = catalogNotice('Chưa có thông tin sản phẩm.');
        return;
    }
    const product = findProduct(productId);
    if (!product) {
        content.innerHTML = '<div class="border border-dashed border-mecha-panel p-8 text-center text-mecha-gray">Không tìm thấy mô hình này. <button type="button" data-action="search" class="text-mecha-gold underline">Quay lại tìm kiếm</button></div>';
        return;
    }

    const reviews = getProductReviews(product.id);
    const average = reviews.length
        ? (reviews.reduce((sum, review) => sum + Number(review.rating || 0), 0) / reviews.length).toFixed(1)
        : 'Chưa có';
    const reviewMarkup = reviews.length
        ? reviews.map(review => `
            <article class="review-entry">
                <div class="review-entry-heading">
                    <strong>${escapeHtml(review.name || 'Khách')}</strong>
                    <span>${'★'.repeat(Math.max(0, Math.min(5, Number(review.rating) || 0)))}${'☆'.repeat(5 - Math.max(0, Math.min(5, Number(review.rating) || 0)))}</span>
                </div>
                <p>${escapeHtml(review.comment)}</p>
                <small>${escapeHtml(new Date(review.createdAt).toLocaleDateString('vi-VN'))}</small>
            </article>
        `).join('')
        : '<p class="text-mecha-gray">Chưa có đánh giá. Hãy chia sẻ nhận xét đầu tiên.</p>';

    const isSaved = getSavedProducts().includes(String(product.id));
    content.innerHTML = `
        <button type="button" data-action="search" class="inline-flex items-center gap-2 text-mecha-gold font-orbitron text-xs uppercase tracking-widest hover:text-white"><i class="fa-solid fa-arrow-left"></i> Quay lại tìm kiếm</button>
        <div class="product-detail-layout mt-6">
            ${productImageGalleryMarkup(product)}
            <div class="product-detail-info bg-mecha-dark border border-mecha-panel p-6 md:p-8">
                <p class="font-orbitron text-[10px] uppercase tracking-[0.3em] text-mecha-gold">Thông tin sản phẩm</p>
                <h2 class="mt-3 font-orbitron text-2xl md:text-3xl font-black uppercase">${escapeHtml(product.name)}</h2>
                <p class="mt-5 font-orbitron text-mecha-gold">${escapeHtml([product.category, product.grade, product.scale].filter(Boolean).join(' · '))}</p>
                <p class="mt-2 text-mecha-gray">${escapeHtml(product.series)}</p>
                <p class="mt-4">${escapeHtml(product.status || 'Tình trạng đang cập nhật')}</p>
                <strong class="mt-5 block font-orbitron text-2xl">${formatPrice(product.price)}</strong>
                ${product.description ? `<p class="mt-5 whitespace-pre-line">${escapeHtml(product.description)}</p>` : ''}
                ${product.specifications ? `<p class="mt-4 whitespace-pre-line text-mecha-gray">${escapeHtml(product.specifications)}</p>` : ''}
                <div class="product-actions mt-6">
                    <button type="button" data-toggle-favorite="${escapeHtml(product.id)}" aria-label="Lưu ${escapeHtml(product.name)} vào yêu thích" class="favorite-btn ${isSaved ? 'is-saved' : ''}" aria-pressed="${isSaved}"><i class="${isSaved ? 'fa-solid' : 'fa-regular'} fa-heart"></i></button>
                    <button type="button" data-add-cart="${escapeHtml(product.id)}" class="button button-primary" ${product.stock === 0 ? 'disabled' : ''}>${product.stock === 0 ? 'Hết hàng' : 'Thêm vào giỏ'}</button>
                </div>
            </div>
        </div>
        <section class="review-section bg-mecha-dark border border-mecha-panel p-6 md:p-8">
            <h3 class="font-orbitron font-bold uppercase">Đánh giá sản phẩm <span class="text-mecha-gold">${average} · ${reviews.length}</span></h3>
            <div class="review-list">${reviewMarkup}</div>
            <form id="review-form" class="review-form">
                <input type="hidden" name="productId" value="${escapeHtml(product.id)}">
                <label class="form-field">Mã đơn đã giao<input name="orderId" maxlength="32" required></label>
                <label class="form-field">Số điện thoại người nhận<input name="phone" type="tel" maxlength="20" required></label>
                <label class="form-field">Điểm đánh giá<select name="rating" required><option value="">Chọn số sao</option><option value="5">5 sao</option><option value="4">4 sao</option><option value="3">3 sao</option><option value="2">2 sao</option><option value="1">1 sao</option></select></label>
                <label class="form-field field-full">Nhận xét<textarea name="comment" rows="3" maxlength="1000" required placeholder="Chia sẻ cảm nhận của bạn"></textarea></label>
                <button type="submit" class="button button-secondary">Gửi đánh giá</button>
            </form>
            <p class="demo-notice">Chỉ khách của đơn đã giao mới được đánh giá. Nội dung được hiển thị sau khi cửa hàng kiểm duyệt.</p>
        </section>
    `;
    loadProductReviews(product.id, content);
}

async function initializePreorderForm() {
    const form = document.getElementById('preorder-form');
    if (!form) return;
    const select = form.elements.namedItem('productId');
    const submit = form.querySelector('button[type="submit"]');
    try {
        const response = await fetch('api/dat-truoc.php', { headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || 'Không thể tải sản phẩm đặt trước.');
        commerceCsrfToken = result.csrfToken;
        select.replaceChildren();
        for (const product of result.products || []) {
            const option = document.createElement('option');
            option.value = product.id;
            option.textContent = `${product.name}${product.referencePrice ? ` · tham khảo ${formatPrice(product.referencePrice)}` : ''}${product.releaseDate ? ` · dự kiến ${product.releaseDate}` : ''}`;
            select.append(option);
        }
        if (submit) submit.disabled = select.options.length === 0;
        if (select.options.length === 0) {
            const message = document.createElement('p');
            message.className = 'text-mecha-gray';
            message.textContent = 'Hiện chưa có sản phẩm nào đang nhận đặt trước.';
            form.before(message);
        }
    } catch (error) {
        console.error('ARSENAL preorder catalog failed:', error);
        if (submit) submit.disabled = true;
        const message = document.createElement('p');
        message.className = 'text-red-400';
        message.textContent = 'Không thể tải danh sách đặt trước. Vui lòng tải lại trang.';
        form.before(message);
    }
}

class SiteHeader extends HTMLElement {
    connectedCallback() {
        const currentPage = window.location.pathname.split('/').pop() || 'index.html';
        const links = navigation.map(({ label, href }) => {
            const active = href === currentPage;
            return `<a href="${href}" ${active ? 'aria-current="page"' : ''} class="nav-link${active ? ' is-active' : ''}">${label}</a>`;
        }).join('');

        this.innerHTML = `
            <nav id="navbar" class="fixed w-full z-50 transition-all duration-300 border-b border-mecha-panel bg-mecha-black/90 backdrop-blur-md">
                <div class="w-full h-1 bg-mecha-gold"></div>
                <div class="w-full flex justify-between px-4 text-[10px] font-orbitron text-mecha-gray border-b border-mecha-panel pb-1 pt-1 tracking-widest uppercase">
                    <span>SYS.VER 9.0.2 // ONLINE</span>
                    <span>SECURE CONNECTION</span>
                </div>
                <div class="site-container">
                    <div class="nav-layout">
                        <a href="index.html" class="nav-brand" aria-label="ARSENAL - Trang chủ">
                            <img src="images/logodoan.png" alt="ARSENAL" class="nav-logo">
                        </a>
                        <div class="nav-links">
                            ${links}
                        </div>
                        <div class="nav-actions">
                            <button type="button" data-action="search" aria-label="Tìm kiếm mô hình" title="Tìm kiếm" class="nav-action-button text-mecha-white hover:text-mecha-gold transition-colors">
                                <i class="fa-solid fa-crosshairs text-lg"></i>
                            </button>
                            <a href="yeu-thich.html" aria-label="Mô hình yêu thích" title="Yêu thích" class="nav-action-button text-mecha-white hover:text-mecha-gold transition-colors">
                                <i class="fa-regular fa-heart text-lg"></i>
                            </a>
                                            <a href="tai-khoan.html" aria-label="Tài khoản" title="Tài khoản" class="nav-action-button text-mecha-white hover:text-mecha-gold transition-colors">
                                                <i class="fa-solid fa-user text-lg"></i>
                                            </a>
                            <a href="gio-hang.html" aria-label="Giỏ hàng" title="Giỏ hàng" class="nav-action-button text-mecha-white hover:text-mecha-gold transition-colors relative">
                                <i class="fa-solid fa-cube text-lg"></i>
                                <span data-cart-badge class="absolute -top-1 -right-1 bg-mecha-gold text-mecha-black text-[10px] font-orbitron font-bold h-4 w-4 flex items-center justify-center cut-corner-sm" hidden>0</span>
                            </a>
                        </div>
                    </div>
                </div>
            </nav>`;

        const navbar = this.querySelector('#navbar');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) {
                navbar.classList.add('bg-mecha-black', 'shadow-[0_4px_20px_rgba(0,0,0,0.8)]');
                navbar.classList.remove('bg-mecha-black/90');
            } else {
                navbar.classList.add('bg-mecha-black/90');
                navbar.classList.remove('bg-mecha-black', 'shadow-[0_4px_20px_rgba(0,0,0,0.8)]');
            }
        });
    }
}

class SiteFooter extends HTMLElement {
    connectedCallback() {
        this.innerHTML = `
            <footer class="bg-mecha-black border-t-2 border-mecha-gold relative overflow-hidden">
                <div class="absolute right-0 bottom-0 opacity-10 pointer-events-none">
                    <svg width="400" height="400" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                        <path d="M0 100 L100 0 L100 100 Z" fill="#FFFFFF"/>
                        <path d="M20 100 L100 20 L100 100 Z" fill="#000000"/>
                    </svg>
                </div>
                <div class="site-container pt-16 pb-8 relative z-10">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-12 mb-12 border-b border-mecha-panel pb-12">
                        <div>
                            <a href="index.html" class="footer-logo-link block mb-4" aria-label="ARSENAL - Trang chủ">
                                <img src="images/logodoan.png" alt="ARSENAL" class="footer-logo">
                            </a>
                            <p class="text-mecha-gray text-sm uppercase tracking-wider mb-6 leading-relaxed">Điểm đến dành cho những mẫu Mecha tinh xảo, dụng cụ lắp ráp và phụ kiện chất lượng.</p>
                            <div class="flex space-x-4 text-mecha-gray">
                                <a href="#" aria-label="X" class="hover:text-mecha-gold"><i class="fa-brands fa-x-twitter"></i></a>
                                <a href="#" aria-label="Instagram" class="hover:text-mecha-gold"><i class="fa-brands fa-instagram"></i></a>
                                <a href="#" aria-label="YouTube" class="hover:text-mecha-gold"><i class="fa-brands fa-youtube"></i></a>
                            </div>
                        </div>
                        <div>
                            <h2 class="font-orbitron font-bold text-mecha-white uppercase tracking-widest mb-6">Hỗ trợ khách hàng</h2>
                            <ul class="space-y-3 font-rajdhani text-mecha-gray uppercase tracking-wider font-semibold">
                                <li><a href="lien-he.html" class="hover:text-mecha-gold">Liên hệ</a></li>
                                <li><a href="tra-cuu-don.html" class="hover:text-mecha-gold">Tra cứu đơn hàng</a></li>
                                <li><a href="ho-tro.html#giao-hang" class="hover:text-mecha-gold">Giao hàng</a></li>
                                <li><a href="ho-tro.html#doi-tra" class="hover:text-mecha-gold">Đổi trả</a></li>
                                <li><a href="ho-tro.html#faq" class="hover:text-mecha-gold">Câu hỏi thường gặp</a></li>
                            </ul>
                        </div>
                        <div>
                            <h2 class="font-orbitron font-bold text-mecha-white uppercase tracking-widest mb-6">Khám phá</h2>
                            <ul class="space-y-3 font-rajdhani text-mecha-gray uppercase tracking-wider font-semibold">
                                <li><a href="dong-mecha.html" class="hover:text-mecha-gold">Các dòng Mecha</a></li>
                                <li><a href="phan-hang.html" class="hover:text-mecha-gold">Phân hạng mô hình</a></li>
                                <li><a href="dung-cu.html" class="hover:text-mecha-gold">Dụng cụ lắp ráp</a></li>
                                <li><a href="dat-truoc.html" class="hover:text-mecha-gold">Sản phẩm đặt trước</a></li>
                            </ul>
                        </div>
                        <div>
                            <h2 class="font-orbitron font-bold text-mecha-white uppercase tracking-widest mb-6">Bản tin ARSENAL</h2>
                            <p class="text-mecha-gray text-xs uppercase tracking-wider mb-4">Cập nhật sản phẩm mới và các đợt mở bán giới hạn.</p>
                            <p class="text-mecha-gray text-xs uppercase tracking-wider mb-4">Đăng ký demo chỉ lưu email trên trình duyệt này; chưa gửi bản tin.</p>
                            <a href="ban-tin.html" class="button button-primary w-full">Đăng ký bản tin</a>
                        </div>
                    </div>
                    <div class="flex flex-col md:flex-row justify-between items-center font-orbitron text-[10px] text-mecha-gray tracking-widest uppercase">
                        <p>&copy; 2026 ARSENAL MECHA SUPPLIES. BẢN QUYỀN ĐƯỢC BẢO LƯU.</p>
                        <div class="flex space-x-6 mt-4 md:mt-0">
                            <a href="chinh-sach.html#bao-mat" class="hover:text-white">Chính sách bảo mật</a>
                            <a href="chinh-sach.html#dieu-khoan" class="hover:text-white">Điều khoản</a>
                            <a href="lien-he.html" class="hover:text-white">Liên hệ</a>
                        </div>
                    </div>
                </div>
            </footer>
        `;
    }
}

function initializeAiChat() {
    if (document.querySelector('[data-ai-chat]')) return;

    const widget = document.createElement('aside');
    widget.className = 'ai-chat-widget';
    widget.dataset.aiChat = '';
    widget.innerHTML = `
        <section class="ai-chat-panel" id="ai-chat-panel" aria-label="Tư vấn ARSENAL" hidden>
            <header class="ai-chat-header">
                <div>
                    <strong>Trợ lý ARSENAL</strong>
                    <span>Tư vấn sản phẩm và giải đáp</span>
                </div>
                <button type="button" class="ai-chat-close" data-ai-chat-close aria-label="Đóng khung tư vấn">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>
            <div class="ai-chat-disclaimer">Tư vấn dựa trên sản phẩm, FAQ và quy tắc cửa hàng đã cấu hình.</div>
            <div class="ai-chat-messages" data-ai-chat-messages role="log" aria-live="polite" aria-relevant="additions"></div>
            <form class="ai-chat-form" data-ai-chat-form>
                <div class="ai-chat-preferences">
                    <label>Trình độ
                        <select name="level">
                            <option value="">Chưa chọn</option>
                            <option value="NGUOI_MOI">Người mới</option>
                            <option value="TRUNG_CAP">Trung cấp</option>
                            <option value="NANG_CAO">Nâng cao</option>
                        </select>
                    </label>
                    <label>Ngân sách tối đa (₫)
                        <input name="budget" type="number" min="0" max="1000000000000" step="10000" placeholder="Không giới hạn">
                    </label>
                    <label class="ai-chat-series">Dòng Mecha
                        <select name="seriesId">
                            <option value="">Tất cả dòng</option>
                        </select>
                    </label>
                </div>
                <label class="sr-only" for="ai-chat-question">Câu hỏi tư vấn</label>
                <textarea id="ai-chat-question" name="question" rows="2" maxlength="500" placeholder="Ví dụ: Mình mới chơi, tìm HG dưới 1 triệu..." required></textarea>
                <button type="submit" aria-label="Gửi câu hỏi tư vấn">
                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                </button>
            </form>
        </section>
        <button type="button" class="ai-chat-toggle" aria-label="Mở tư vấn AI" aria-controls="ai-chat-panel" aria-expanded="false">
            <i class="fa-solid fa-comments" aria-hidden="true"></i>
            <span>Tư vấn</span>
        </button>
    `;
    document.body.append(widget);

    const panel = widget.querySelector('.ai-chat-panel');
    const toggle = widget.querySelector('.ai-chat-toggle');
    const close = widget.querySelector('[data-ai-chat-close]');
    const messages = widget.querySelector('[data-ai-chat-messages]');
    const form = widget.querySelector('[data-ai-chat-form]');
    const questionField = form.elements.namedItem('question');
    let csrfToken = '';
    let isSending = false;

    const appendMessage = (text, sender, products = []) => {
        const message = document.createElement('article');
        message.className = `ai-chat-message ai-chat-message-${sender}`;
        const content = document.createElement('p');
        content.textContent = text;
        message.append(content);

        if (products.length) {
            const recommendations = document.createElement('div');
            recommendations.className = 'ai-chat-recommendations';
            products.forEach(product => {
                const card = document.createElement('a');
                card.className = 'ai-chat-product';
                card.href = `chi-tiet.html?id=${encodeURIComponent(product.id)}`;
                const image = document.createElement('img');
                image.src = catalogImage(product.image);
                image.alt = '';
                image.loading = 'lazy';
                image.onerror = () => {
                    image.onerror = null;
                    image.src = 'images/logodoan.png';
                };
                const details = document.createElement('span');
                const name = document.createElement('strong');
                name.textContent = product.name;
                const price = document.createElement('small');
                price.textContent = `${formatPrice(product.price)} · Còn ${product.stock}`;
                details.append(name, price);
                if (product.reason) {
                    const reason = document.createElement('small');
                    reason.className = 'ai-chat-product-reason';
                    reason.textContent = product.reason;
                    details.append(reason);
                }
                card.append(image, details);
                recommendations.append(card);
            });
            message.append(recommendations);
        }

        messages.append(message);
        messages.scrollTop = messages.scrollHeight;
    };

    appendMessage('Chào bạn! Mình có thể gợi ý mô hình, phân hạng, dụng cụ hoặc trả lời câu hỏi thường gặp. Bạn đang tìm gì?', 'assistant');

    const setOpen = open => {
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        if (open) questionField.focus();
    };
    toggle.addEventListener('click', () => setOpen(panel.hidden));
    close.addEventListener('click', () => setOpen(false));

    fetch('api/tu-van.php', { headers: { Accept: 'application/json' } })
        .then(async response => {
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Không thể khởi tạo khung tư vấn.');
            csrfToken = result.csrfToken || '';
            if (!csrfToken) throw new Error('Thiếu mã bảo mật cho khung tư vấn.');
            const seriesSelect = form.elements.namedItem('seriesId');
            (result.series || []).forEach(series => {
                const option = document.createElement('option');
                option.value = series.id;
                option.textContent = series.name;
                seriesSelect.append(option);
            });
        })
        .catch(error => {
            console.error('ARSENAL AI chat initialization failed:', error);
            questionField.disabled = true;
            form.querySelector('button[type="submit"]').disabled = true;
            appendMessage('Khung tư vấn chưa kết nối được. Vui lòng tải lại trang hoặc thử lại sau.', 'assistant');
        });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        const question = String(questionField.value || '').trim();
        if (!question || isSending || !csrfToken) return;
        isSending = true;
        questionField.disabled = true;
        form.querySelector('button[type="submit"]').disabled = true;
        appendMessage(question, 'user');
        questionField.value = '';
        try {
            const response = await fetch('api/tu-van.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    question,
                    level: form.elements.namedItem('level').value,
                    budget: form.elements.namedItem('budget').value,
                    seriesId: form.elements.namedItem('seriesId').value
                })
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Không thể gửi câu hỏi tư vấn.');
            const sourceNotice = result.source === 'DU_PHONG' ? 'Gợi ý theo quy tắc ARSENAL:\n' : '';
            appendMessage(sourceNotice + result.answer, 'assistant', result.products || []);
        } catch (error) {
            console.error('ARSENAL AI chat request failed:', error);
            appendMessage(error.message || 'Không thể xử lý câu hỏi. Vui lòng thử lại.', 'assistant');
        } finally {
            isSending = false;
            questionField.disabled = false;
            form.querySelector('button[type="submit"]').disabled = false;
            questionField.focus();
        }
    });
}

customElements.define('site-header', SiteHeader);
customElements.define('site-footer', SiteFooter);

function fillCatalogFilters() {
    for (const [id, field, label] of [
        ['product-grade', 'grade', 'Tất cả Grade'],
        ['product-series', 'series', 'Tất cả series']
    ]) {
        const select = document.getElementById(id);
        if (!select) continue;

        const selected = select.value;
        const values = [...new Set(productCatalog.map(product => product[field]).filter(Boolean))];
        values.sort((a, b) => a.localeCompare(b, 'vi'));
        select.innerHTML = `<option value="">${label}</option>` + values.map(value => {
            const text = escapeHtml(value);
            return `<option value="${text}">${text}</option>`;
        }).join('');
        select.value = values.includes(selected) ? selected : '';
    }
}

function renderCatalogViews() {
    renderHomeProducts();
    renderCart();
    updateSearchResults();
    renderFavoriteProducts();
    const params = new URLSearchParams(window.location.search);
    renderProductDetailPage(params.get('id') ?? params.get('maSP'));
    renderBrowseProducts();
}

async function loadPublicGrades() {
    const target = document.getElementById('grade-categories');
    if (!target) return;

    try {
        const response = await fetch('api/phan-hang.php', { headers: { Accept: 'application/json' } });
        const grades = await response.json();
        if (!response.ok) throw new Error(grades.message || `HTTP ${response.status}`);
        if (!Array.isArray(grades)) throw new Error('Dữ liệu phân hạng sai định dạng.');
        if (!grades.length) {
            target.innerHTML = '<p class="col-span-full text-mecha-gray">Chưa có phân hạng nào đang hoạt động.</p>';
            return;
        }

        target.innerHTML = grades.map(grade => `
            <a href="san-pham.html?grade=${encodeURIComponent(grade.code)}" data-browse-filter="grade" data-browse-value="${escapeHtml(grade.code)}" class="browse-category-card bg-mecha-dark border border-mecha-panel p-6 cut-corner gold-glow">
                <span class="font-orbitron text-mecha-gold text-xs">${escapeHtml(grade.code)}</span>
                <h2 class="mt-5 font-orbitron font-black text-5xl">${escapeHtml(grade.code)}</h2>
                <h3 class="mt-2 font-orbitron font-bold text-xl">${escapeHtml(grade.name)}</h3>
                <p class="mt-4 text-mecha-gray uppercase tracking-wide">${escapeHtml(grade.description || 'Xem các sản phẩm thuộc phân hạng này.')}</p>
                <span class="browse-category-link">Xem sản phẩm <i class="fa-solid fa-arrow-right"></i></span>
            </a>
        `).join('');
    } catch (error) {
        console.error('ARSENAL grades', error);
        target.innerHTML = '<p class="col-span-full text-mecha-gray">Không thể tải danh sách phân hạng. Vui lòng tải lại trang.</p>';
    }
}

async function loadPublicMechaSeries() {
    const target = document.getElementById('mecha-categories');
    if (!target) return;

    try {
        const response = await fetch('api/dong-mecha.php', { headers: { Accept: 'application/json' } });
        const series = await response.json();
        if (!response.ok) throw new Error(series.message || `HTTP ${response.status}`);
        if (!Array.isArray(series)) throw new Error('Dữ liệu dòng Mecha sai định dạng.');
        if (!series.length) {
            target.innerHTML = '<p class="col-span-full text-mecha-gray">Chưa có dòng Mecha nào đang hoạt động.</p>';
            return;
        }

        target.innerHTML = series.map(item => `
            <a href="san-pham.html?series=${encodeURIComponent(item.name)}" data-browse-filter="series" data-browse-value="${escapeHtml(item.name)}" class="browse-category-card mecha-series-card ${item.image ? 'has-image' : 'no-image'} relative overflow-hidden bg-mecha-dark border border-mecha-panel p-6 cut-corner gold-glow">
                ${item.image
                    ? `<span class="mecha-series-image" aria-hidden="true"><img src="${escapeHtml(catalogImage(item.image))}" alt="" loading="lazy" onerror="this.hidden=true;this.closest('.mecha-series-card').classList.remove('has-image');this.closest('.mecha-series-card').classList.add('no-image')"></span>`
                    : '<span class="mecha-series-mark" aria-hidden="true"><i class="fa-solid fa-robot"></i></span>'}
                <span class="mecha-series-content">
                    <span class="mecha-series-label font-orbitron text-mecha-gold text-xs tracking-widest">DÒNG MECHA</span>
                    <span class="mecha-series-name font-orbitron font-bold text-2xl">${escapeHtml(item.name)}</span>
                    <span class="mecha-series-description text-mecha-gray">${escapeHtml(item.description || 'Khám phá các sản phẩm thuộc dòng Mecha này.')}</span>
                    <span class="browse-category-link">Xem sản phẩm <i class="fa-solid fa-arrow-right"></i></span>
                </span>
            </a>
        `).join('');
    } catch (error) {
        console.error('ARSENAL Mecha series', error);
        target.innerHTML = '<p class="col-span-full text-mecha-gray">Không thể tải các dòng Mecha. Vui lòng tải lại trang.</p>';
    }
}

function renderBrowseProducts() {
    const target = document.getElementById('browse-products');
    if (!target || catalogState !== 'ready') return;

    const page = window.location.pathname.split('/').pop();
    const params = new URLSearchParams(window.location.search);
    const isProductPage = page === 'san-pham.html';
    const grades = { HG: 'High Grade', RG: 'Real Grade', MG: 'Master Grade', PG: 'Perfect Grade' };
    const toolCategories = TOOL_PRODUCT_CATEGORIES.map(category => category.toLocaleLowerCase('vi'));
    let products = productCatalog;
    let selectedFilter = '';
    let selectedLabel = '';
    const randomProductsPage = ['phan-hang.html', 'dong-mecha.html', 'dung-cu.html'].includes(page);
    if (page === 'phan-hang.html') {
        products = products.filter(product => product.grade || product.gradeCode);
    } else if (page === 'dong-mecha.html') {
        products = products.filter(product =>
            product.series || String(product.category || '').toLocaleLowerCase('vi') === 'mecha'
        );
    } else if (page === 'dung-cu.html') {
        products = products.filter(product => toolCategories.includes(String(product.category || '').toLocaleLowerCase('vi')));
    }
    if (isProductPage && params.has('grade')) {
        const grade = params.get('grade')?.toUpperCase();
        products = products.filter(product => product.grade && (
            product.gradeCode?.toUpperCase() === grade
            || product.grade.toLowerCase() === grades[grade]?.toLowerCase()
        ));
        selectedFilter = 'grade';
        selectedLabel = grade ? `${grades[grade] || grade} (${grade})` : '';
    }
    if (isProductPage && params.has('series')) {
        const series = params.get('series');
        products = products.filter(product => product.series && (!series || product.series.toLowerCase() === series?.toLowerCase()));
        selectedFilter = 'series';
        selectedLabel = series || '';
    }
    if (isProductPage && params.has('tool')) {
        const tool = TOOL_PRODUCT_FILTERS[params.get('tool') || ''];
        if (tool) {
            products = products.filter(product =>
                String(product.category || '').toLocaleLowerCase('vi') === tool.category.toLocaleLowerCase('vi')
            );
        } else {
            products = [];
        }
        selectedFilter = 'tool';
        selectedLabel = tool?.label || 'Dụng cụ';
    }
    if (isProductPage) {
        const title = document.getElementById('browse-title');
        const description = document.getElementById('browse-description');
        const back = document.getElementById('browse-back');
        const backPages = { grade: 'phan-hang.html', series: 'dong-mecha.html', tool: 'dung-cu.html' };
        const categoryLabel = selectedLabel || 'Tất cả sản phẩm';
        if (title) title.textContent = selectedLabel ? `Sản phẩm · ${categoryLabel}` : categoryLabel;
        if (description) description.textContent = selectedLabel
            ? `Các sản phẩm đang kinh doanh thuộc mục ${selectedLabel}.`
            : 'Sản phẩm được cập nhật theo dữ liệu cửa hàng.';
        if (back && backPages[selectedFilter]) {
            back.href = backPages[selectedFilter];
            back.innerHTML = '<i class="fa-solid fa-arrow-left"></i> Quay lại danh mục';
        }
    }
    document.querySelectorAll('[data-browse-filter]').forEach(card => {
        const active = card.dataset.browseFilter === selectedFilter && card.dataset.browseValue === params.get(selectedFilter);
        if (active) card.setAttribute('aria-current', 'page');
        else card.removeAttribute('aria-current');
    });
    const heading = target.closest('section')?.querySelector('h2');
    if (heading) {
        if (randomProductsPage) heading.textContent = 'Sản phẩm gợi ý ngẫu nhiên';
    }
    if (!products.length) {
        target.innerHTML = `
            <div class="col-span-full browse-empty-state">
                <p class="text-mecha-gray">${selectedLabel
                    ? `Hiện chưa có sản phẩm thuộc mục ${escapeHtml(selectedLabel)}.`
                    : 'Chưa có sản phẩm phù hợp trong danh mục này.'}</p>
                ${selectedLabel ? `                <a class="browse-reset-link" href="san-pham.html">Xem tất cả sản phẩm</a>` : ''}
            </div>
        `;
        return;
    }

    if (randomProductsPage) {
        products = [...products];
        for (let index = products.length - 1; index > 0; index--) {
            const randomIndex = Math.floor(Math.random() * (index + 1));
            [products[index], products[randomIndex]] = [products[randomIndex], products[index]];
        }
        products = products.slice(0, 4);
    }

    const savedProducts = getSavedProducts();
    target.innerHTML = products.map(product => renderUnitProductCard(product, savedProducts)).join('');
    document.dispatchEvent(new Event('arsenal:catalog-rendered'));
}

async function loadProductCatalog() {
    if (catalogState === 'loading') return;
    catalogState = 'loading';
    renderCatalogViews();

    try {
        const response = await fetch('api/san-pham.php', { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const rows = await response.json();
        if (!Array.isArray(rows)) throw new Error('Dữ liệu danh sách sản phẩm sai định dạng.');

        productCatalog = rows.map(row => {
            const stock = Number(row.stock);
            return {
                ...row,
                grade: row.grade ?? '',
                series: row.series ?? '',
                image: catalogImage(row.image),
                gallery: productGallery(row.gallery, catalogImage(row.image)),
                stock,
                status: stock > 0 ? `CÒN ${stock} SẢN PHẨM` : 'HẾT HÀNG'
            };
        });
        catalogState = 'ready';
        fillCatalogFilters();
        await initializeCustomerSession();
    } catch (error) {
        catalogState = 'error';
        console.error('ARSENAL catalog', error);
    }

    renderCatalogViews();
}

window.addEventListener('DOMContentLoaded', () => {
    initializeAiChat();
    initializePanels();
    loadPublicGrades();
    loadPublicMechaSeries();
    initializeCheckoutPayment();
    initializeCustomerSession();
    initializePreorderForm();
    if (isDemoCatalog) renderCatalogViews();
    else loadProductCatalog();
});

document.addEventListener('click', event => {
    if (!(event.target instanceof Element)) return;
    if (event.target.closest('[data-close-payment]') || event.target.id === 'payment-modal') {
        const modal = document.getElementById('payment-modal');
        if (modal) modal.hidden = true;
        document.body.classList.remove('payment-modal-open');
        window.clearInterval(window.arsenalPaymentCountdown);
        stopPaymentStatusPolling();
    }
});
