(() => {
    const apiUrl = 'api/tai-khoan.php';
    const message = document.getElementById('account-message');
    let csrfToken = '';
    let currentCustomer = null;

    function notify(text, isError = false) {
        message.textContent = text;
        message.classList.toggle('text-red-400', isError);
        message.classList.toggle('text-mecha-gold', !isError);
    }

    async function request(action, data = {}) {
        const response = await fetch(apiUrl, {
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
        if (!response.ok) throw new Error(result.message || 'Yêu cầu không thành công.');
        return result;
    }

    function setFormValues(form, values) {
        for (const [key, value] of Object.entries(values)) {
            const field = form.elements.namedItem(key);
            if (field) field.value = value ?? '';
        }
    }

    function displayList(element, items, render, emptyText) {
        element.replaceChildren();
        if (!items.length) {
            element.textContent = emptyText;
            return;
        }
        for (const item of items) {
            const row = document.createElement('div');
            row.className = 'border-b border-mecha-panel py-2 text-mecha-gray';
            render(row, item);
            element.append(row);
        }
    }

    async function renderCustomer(customer, state) {
        currentCustomer = customer;
        document.getElementById('account-guest').hidden = Boolean(customer);
        document.getElementById('account-profile').hidden = !customer;
        if (!customer) return;

        document.getElementById('account-welcome').textContent = `Xin chào, ${customer.hoTen}`;
        setFormValues(document.getElementById('account-profile-form'), {
            name: customer.hoTen,
            phone: customer.dienThoai,
            email: customer.email,
            address: customer.diaChi
        });
        const favorites = state.favorites || [];
        localStorage.setItem('arsenal-saved-products-vnd', JSON.stringify(favorites));
        localStorage.setItem('arsenal-favorites-owner-vnd', String(customer.maKH));
        displayList(
            document.getElementById('account-favorites'),
            favorites,
            (row, id) => {
                const link = document.createElement('a');
                link.href = `chi-tiet.html?id=${encodeURIComponent(id)}`;
                link.className = 'text-mecha-gold hover:text-white';
                link.textContent = id;
                row.append(link);
            },
            'Chưa có sản phẩm yêu thích.'
        );

        const cart = state.cart || [];
        localStorage.setItem('arsenal-cart-vnd', JSON.stringify(cart));
        localStorage.setItem('arsenal-cart-owner-vnd', String(customer.maKH));
        displayList(
            document.getElementById('account-cart'),
            cart,
            (row, item) => {
                row.textContent = `${item.name} × ${item.quantity}`;
            },
            'Giỏ hàng tài khoản đang trống.'
        );

        const ordersResponse = await request('orders');
        displayList(
            document.getElementById('account-orders'),
            ordersResponse.orders || [],
            (row, order) => {
                const link = document.createElement('a');
                link.href = `tra-cuu-don.html`;
                link.className = 'text-mecha-gold hover:text-white';
                link.textContent = order.maDH;
                row.append(link, document.createTextNode(
                    ` · ${order.trangThaiXuLy} · ${Number(order.tongPhaiTra).toLocaleString('vi-VN')} ₫ · ` +
                    new Date(`${String(order.datLuc).replace(' ', 'T')}Z`).toLocaleString('vi-VN')
                ));
            },
            'Chưa có đơn hàng gắn với tài khoản này.'
        );
    }

    async function initialize() {
        try {
            const response = await fetch(apiUrl, { headers: { Accept: 'application/json' } });
            const state = await response.json();
            if (!response.ok) throw new Error(state.message || 'Không thể tải tài khoản.');
            csrfToken = state.csrfToken;
            await renderCustomer(state.customer, state);
        } catch (error) {
            notify(error.message, true);
        }
    }

    document.getElementById('account-login').addEventListener('submit', async event => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.currentTarget).entries());
        try {
            const result = await request('login', data);
            await renderCustomer(result.customer, result);
            notify('Đăng nhập thành công.');
        } catch (error) {
            notify(error.message, true);
        }
    });

    document.getElementById('account-register').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const data = Object.fromEntries(new FormData(form).entries());
        try {
            const result = await request('register', data);
            await renderCustomer(result.customer, result);
            form.reset();
            notify('Tạo tài khoản thành công.');
        } catch (error) {
            notify(error.message, true);
        }
    });

    document.getElementById('account-profile-form').addEventListener('submit', async event => {
        event.preventDefault();
        try {
            const result = await request('profile', Object.fromEntries(new FormData(event.currentTarget).entries()));
            currentCustomer = result.customer;
            document.getElementById('account-welcome').textContent = `Xin chào, ${currentCustomer.hoTen}`;
            notify('Đã lưu hồ sơ.');
        } catch (error) {
            notify(error.message, true);
        }
    });

    document.getElementById('account-password-form').addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        try {
            const result = await request('password', Object.fromEntries(new FormData(form).entries()));
            form.reset();
            notify(result.message);
        } catch (error) {
            notify(error.message, true);
        }
    });

    document.getElementById('account-save-favorites').addEventListener('click', async () => {
        try {
            const result = await request('favorites', {
                items: JSON.parse(localStorage.getItem('arsenal-saved-products-vnd') || '[]')
            });
            localStorage.setItem('arsenal-saved-products-vnd', JSON.stringify(result.favorites));
            notify('Đã lưu danh sách yêu thích vào tài khoản.');
        } catch (error) {
            notify(error.message, true);
        }
    });

    document.getElementById('account-logout').addEventListener('click', async () => {
        try {
            await request('logout');
            localStorage.removeItem('arsenal-saved-products-vnd');
            localStorage.removeItem('arsenal-cart-vnd');
            localStorage.removeItem('arsenal-cart-owner-vnd');
            localStorage.removeItem('arsenal-favorites-owner-vnd');
            window.location.reload();
        } catch (error) {
            notify(error.message, true);
        }
    });

    initialize();
})();
