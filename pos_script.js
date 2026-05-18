// Ensure the DOM is fully loaded before running the script
document.addEventListener('DOMContentLoaded', () => {
    // --- Global Variables ---
    let cart = []; // Stores items currently in the cart
    let products = []; // Stores all products fetched from the backend (for search)
    let invoiceDiscountPercentage = 0; // Global invoice discount percentage
    let invoiceDiscountAmount = 0; // Global invoice discount in LKR
    let selectedProductForModal = null; // Stores product data when productSelectModal is opened
    let currentHeldBill = null; // Stores the currently loaded held bill, if any
    let currentSearchSelectionIndex = -1; // For keyboard navigation in search results
    let searchTimeout = null; // Variable for debounce timer
    let syncInterval = null; // Variable for background sync
    
    // NEW: Variable for Live Cart Sync Debounce (To Tablet)
    let liveCartSyncTimeout = null;

    // For Editing Completed Sales
    let currentEditingSaleId = null; 

    // Get logged-in user ID from localStorage
    const loggedInUserId = localStorage.getItem('user_id');
    const loggedInUsername = localStorage.getItem('username');
    const loggedInUserRole = localStorage.getItem('role'); 

    // --- Security Check: Redirect if not logged in ---
    if (!loggedInUserId) {
        showAlert('You are not logged in. Redirecting to login page...', 'danger');
        setTimeout(() => {
            window.location.href = 'login.html';
        }, 1500);
        return; 
    }

    // --- DOM Elements ---
    const productSearchInput = document.getElementById('productSearchInput');
    const productSearchResults = document.getElementById('productSearchResults');
    const cartItemsTableBody = document.getElementById('cartItemsTableBody');
    const subtotalDisplay = document.getElementById('subtotalDisplay');
    const discountAmountDisplay = document.getElementById('discountAmountDisplay');
    const grandTotalDisplay = document.getElementById('grandTotalDisplay');
    const amountReceivedInput = document.getElementById('amountReceived');
    const changeDueElement = document.getElementById('changeDue'); 

    // Buttons
    const cashPaymentBtn = document.getElementById('cashPaymentBtn');
    const cardPaymentBtn = document.getElementById('cardPaymentBtn');
    const cashPrintBtn = document.getElementById('cashPrintBtn');
    const cardPrintBtn = document.getElementById('cardPrintBtn');
    const holdPaymentBtn = document.getElementById('holdPaymentBtn');
    const otherPaymentDropdownItems = document.querySelectorAll('.dropdown-item[data-method]');
    const clearCartBtn = document.getElementById('clearCartBtn');
    const setDiscountBtn = document.getElementById('setDiscountBtn');
    const generalItemBtn = document.getElementById('generalItemBtn');
    const viewHeldBillsBtn = document.getElementById('viewHeldBillsBtn');
    const logoutBtn = document.getElementById('logoutBtn');
    const returnToDashboardBtn = document.getElementById('returnToDashboardBtn');

    // Modals
    const discountModal = new bootstrap.Modal(document.getElementById('discountModal'));
    const generalItemModal = new bootstrap.Modal(document.getElementById('generalItemModal'));
    const heldBillsModal = new bootstrap.Modal(document.getElementById('heldBillsModal'));
    const productSelectModal = new bootstrap.Modal(document.getElementById('productSelectModal'));
    const replenishStockModal = new bootstrap.Modal(document.getElementById('replenishStockModal')); // Kept for structure but unused logic
    const itemDiscountModal = new bootstrap.Modal(document.getElementById('itemDiscountModal'));
    const editCartItemModal = new bootstrap.Modal(document.getElementById('editCartItemModal'));

    // Alert Container
    const alertMessageContainer = document.getElementById('alertMessageContainer');

    // --- Auto-Focus Search Bar on Load ---
    if (productSearchInput) {
        setTimeout(() => productSearchInput.focus(), 100);
    }

    // --- CART PERSISTENCE (AUTO-SAVE) ---
    function saveCartToStorage() {
        const state = {
            cart,
            invoiceDiscountPercentage,
            invoiceDiscountAmount,
            currentEditingSaleId,
            currentHeldBill
        };
        localStorage.setItem('activePosSession', JSON.stringify(state));
    }

    function loadCartFromStorage() {
        const saved = localStorage.getItem('activePosSession');
        if (saved) {
            try {
                const state = JSON.parse(saved);
                if (state.cart && state.cart.length > 0) {
                    cart = state.cart;
                    invoiceDiscountPercentage = state.invoiceDiscountPercentage || 0;
                    invoiceDiscountAmount = state.invoiceDiscountAmount || 0;
                    currentEditingSaleId = state.currentEditingSaleId || null;
                    currentHeldBill = state.currentHeldBill || null;
                    updateCartUI(false); 
                    showAlert('Session restored from backup.', 'info');
                }
            } catch (e) { console.error('Failed to load cart backup', e); }
        }
    }

    // --- Helper Functions ---

    function showAlert(message, type = 'success') {
        const alertDiv = document.createElement('div');
        alertDiv.classList.add('alert', `alert-${type}`, 'alert-dismissible', 'fade', 'show', 'alert-message');
        alertDiv.setAttribute('role', 'alert');
        alertDiv.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;
        alertMessageContainer.appendChild(alertDiv);

        setTimeout(() => {
            if (alertDiv && document.body.contains(alertDiv)) {
                 const bsAlert = new bootstrap.Alert(alertDiv);
                 bsAlert.close();
            }
        }, 3000);
    }

    async function apiRequest(url, options = {}) {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000); // 30s timeout
        options.signal = controller.signal;
        
        // --- AUTH TOKEN INJECTION ---
        const token = localStorage.getItem('session_token');
        if (token) {
            if (!options.headers) options.headers = {};
            options.headers['Authorization'] = 'Bearer ' + token;
        }

        try {
            const response = await fetch(url, options);
            clearTimeout(timeoutId);

            if (response.status === 401) {
                // Session expired handling
                console.warn("Session expired (401). Redirecting to login...");
                showAlert('Session expired. Redirecting to login...', 'danger');
                setTimeout(() => {
                    window.location.href = 'login.html';
                }, 2000);
                throw new Error('Session Expired');
            }

            if (!response.ok) {
                const text = await response.text();
                throw new Error(`HTTP error! status: ${response.status} - ${text.substring(0, 50)}`);
            }
            const data = await response.json();
            return data;
        } catch (error) {
            clearTimeout(timeoutId);
            console.error(`API request to ${url} failed:`, error);
            if (error.name === 'AbortError') {
                if (!url.includes('getAllProducts')) {
                     throw new Error('Request timed out. Server is busy.');
                }
            }
            throw error; 
        }
    }

    // --- REALTIME SYNC LOGIC ---

    // 1. Initial Load
    async function initializeProducts() {
        try {
            const data = await apiRequest('products.php?action=getAllProducts', { method: 'GET' });
            products = data.products || [];
            // Start background sync only after initial load success
            startBackgroundSync();
        } catch (error) {
            console.error('Failed to load initial products:', error);
            showAlert('Failed to load initial product data.', 'danger');
        }
    }

    // 2. Background Sync (runs every 30 seconds)
    function startBackgroundSync() {
        if (syncInterval) clearInterval(syncInterval);
        
        syncInterval = setInterval(async () => {
            try {
                // Silent fetch - no loading indicators
                const data = await apiRequest('products.php?action=getAllProducts', { method: 'GET' });
                if(data.success) {
                    products = data.products || [];
                }
            } catch (error) {
                console.warn('Background sync failed silently:', error);
            }
        }, 30000); // 30 seconds
    }

    // 3. Just-In-Time Validation Helper
    async function fetchFreshProductDetails(productId) {
        try {
            const data = await apiRequest(`products.php?action=getProductDetails&product_id=${productId}`);
            if(data.success && data.product) {
                return data.product;
            }
            return null;
        } catch(e) {
            console.error("Failed to fetch fresh details", e);
            return null;
        }
    }

    async function loadSaleForEditing(saleId) {
        try {
            const data = await apiRequest(`sales.php?action=getSaleDetails&sale_id=${saleId}`);
            if (data.success) {
                cart = [];
                data.items.forEach(item => {
                    cart.push({
                        product_id: item.product_id,
                        name: item.name,
                        qty: parseInt(item.qty),
                        price: parseFloat(item.price),
                        total: parseFloat(item.total),
                        discountPercent: 0,
                        discountAmount: parseFloat(item.discountTotal || 0),
                        isFree: false
                    });
                });

                invoiceDiscountAmount = parseFloat(data.sale.discount_amount || 0);
                invoiceDiscountPercentage = 0; 
                currentEditingSaleId = saleId;
                
                updateCartUI();
                showAlert(`Loaded Sale #${saleId} for editing.`, 'warning');
            } else {
                showAlert('Failed to load sale for editing: ' + data.message, 'danger');
            }
        } catch (err) {
            console.error(err);
            showAlert('Network error loading sale.', 'danger');
        }
    }

    // --- Product Search & Management ---

    async function performSearch(query) {
        productSearchResults.innerHTML = ''; 
        currentSearchSelectionIndex = -1; 

        if (query.length === 0) {
            productSearchResults.style.display = 'none'; 
            return;
        }

        const exactMatch = products.find(p => 
            p.product_code.toLowerCase() === query || 
            (p.item_code && p.item_code.toLowerCase() === query) ||
            p.name.toLowerCase() === query
        );
        
        if (exactMatch) {
            // NEW: Skip modal, directly add to cart
            await addScannedItemToCart(exactMatch);
            productSearchInput.value = ''; 
            productSearchResults.style.display = 'none';
            return; 
        }

        try {
            productSearchResults.innerHTML = '<li class="p-2 text-muted text-center"><small>Searching...</small></li>';
            productSearchResults.style.display = 'block';

            const data = await apiRequest(`products.php?action=searchProducts&search=${encodeURIComponent(query)}`);
            const serverProducts = data.success ? data.products : [];

            productSearchResults.innerHTML = ''; 

            if (serverProducts.length > 0) {
                serverProducts.forEach((product, index) => {
                    const li = document.createElement('li');
                    li.classList.add('product-search-item');
                    const itemCodeDisplay = product.item_code ? `<span class="badge bg-info text-dark ms-1">${product.item_code}</span>` : '';
                    
                    li.innerHTML = `
                        <div class="d-flex justify-content-between">
                            <div>
                                <strong>${product.name}</strong>
                                ${itemCodeDisplay}
                            </div>
                            <span class="badge bg-secondary">${product.product_code}</span>
                        </div>
                        <div class="d-flex justify-content-between mt-1">
                            <span>Rs. ${parseFloat(product.price).toFixed(2)}</span>
                            <span class="${product.quantity <= 5 ? 'text-danger fw-bold' : 'text-muted'}">Stock: ${product.quantity}</span>
                        </div>
                    `;
                    li.dataset.index = index; 
                    li.addEventListener('click', () => {
                        openProductSelectModal(product);
                        productSearchResults.innerHTML = ''; 
                        productSearchResults.style.display = 'none';
                        productSearchInput.value = ''; 
                    });
                    productSearchResults.appendChild(li);
                });
                productSearchResults.style.display = 'block';
            } else {
                productSearchResults.style.display = 'none';
            }

        } catch (error) {
            console.error("Search failed:", error);
            const filteredProducts = products.filter(product => {
                return product.name.toLowerCase().includes(query) || 
                       product.product_code.toLowerCase().includes(query) ||
                       (product.item_code && product.item_code.toLowerCase().includes(query));
            });
            if (filteredProducts.length > 0) {
                productSearchResults.innerHTML = '';
                filteredProducts.forEach((product, index) => {
                     const li = document.createElement('li');
                     li.classList.add('product-search-item');
                     li.innerHTML = `<div><strong>${product.name}</strong></div>`; 
                     li.addEventListener('click', () => {
                        openProductSelectModal(product);
                        productSearchResults.style.display = 'none';
                        productSearchInput.value = ''; 
                     });
                     productSearchResults.appendChild(li);
                });
                productSearchResults.style.display = 'block';
            } else {
                productSearchResults.style.display = 'none';
            }
        }
    }

    productSearchInput.addEventListener('input', () => {
        clearTimeout(searchTimeout); 
        searchTimeout = setTimeout(() => {
            const query = productSearchInput.value.toLowerCase().trim();
            performSearch(query);
        }, 200); 
    });

    productSearchInput.addEventListener('keydown', (e) => {
        const items = productSearchResults.querySelectorAll('.product-search-item');
        
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(searchTimeout); 
            
            if (currentSearchSelectionIndex >= 0 && currentSearchSelectionIndex < items.length) {
                items[currentSearchSelectionIndex].click(); 
            } else {
                const query = productSearchInput.value.toLowerCase().trim();
                performSearch(query);
            }
            return;
        }

        if (items.length === 0) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            currentSearchSelectionIndex++;
            if (currentSearchSelectionIndex >= items.length) currentSearchSelectionIndex = 0;
            highlightSelection(items);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            currentSearchSelectionIndex--;
            if (currentSearchSelectionIndex < 0) currentSearchSelectionIndex = items.length - 1;
            highlightSelection(items);
        } else if (e.key === 'Escape') {
            productSearchResults.style.display = 'none';
            productSearchInput.blur();
        }
    });

    function highlightSelection(items) {
        items.forEach(item => item.classList.remove('selected'));
        if (currentSearchSelectionIndex >= 0 && currentSearchSelectionIndex < items.length) {
            const selected = items[currentSearchSelectionIndex];
            selected.classList.add('selected');
            selected.scrollIntoView({ block: 'nearest' });
        }
    }

    document.addEventListener('click', (e) => {
        if (!productSearchInput.contains(e.target) && !productSearchResults.contains(e.target)) {
            productSearchResults.style.display = 'none';
        }
    });

    // --- NEW: Handle Quick Scan Add (with Auto-Stock) ---
    async function addScannedItemToCart(localProduct) {
        document.body.style.cursor = 'wait';
        
        // 1. Fetch Fresh Details (Just-in-time)
        let productToAdd = await fetchFreshProductDetails(localProduct.product_id);
        if (!productToAdd) productToAdd = localProduct; // Fallback to local if network fails

        document.body.style.cursor = 'default';

        const qtyToAdd = 1;
        const price = parseFloat(productToAdd.price);

        // 2. Check Stock & Auto-Replenish if needed
        if (productToAdd.quantity < qtyToAdd) {
             const missingQty = qtyToAdd - productToAdd.quantity;
             
             // Show ephemeral alert
             showAlert(`Insufficient stock. Auto-adding ${missingQty} unit(s)...`, 'warning');

             try {
                 const result = await apiRequest('stock.php?action=updateStock', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ product_id: productToAdd.product_id, quantity_to_add: missingQty })
                 });

                 if (result.success) {
                     // Update local cache
                     const prodIndex = products.findIndex(p => p.product_id == productToAdd.product_id);
                     if (prodIndex !== -1) {
                         products[prodIndex].quantity += missingQty;
                     }
                     productToAdd.quantity += missingQty;
                     
                     // Force sync background
                     startBackgroundSync();
                     
                     // Add to cart
                     addToCart(productToAdd, qtyToAdd, price);
                     // Note: Alert suppressed to avoid notification spam during scanning
                 } else {
                     showAlert('Failed to auto-add stock. Please check inventory.', 'danger');
                     openProductSelectModal(localProduct); // Fallback to manual
                 }
             } catch (error) {
                 console.error(error);
                 showAlert('Network error auto-adding stock.', 'danger');
             }
        } else {
            // 3. Normal Add
            addToCart(productToAdd, qtyToAdd, price);
        }
    }

    async function openProductSelectModal(product) {
        document.body.style.cursor = 'wait';
        const freshProduct = await fetchFreshProductDetails(product.product_id);
        selectedProductForModal = freshProduct ? freshProduct : product;
        document.body.style.cursor = 'default';

        document.getElementById('modalProductName').textContent = selectedProductForModal.name;
        document.getElementById('modalProductStockHint').textContent = `Available Stock: ${selectedProductForModal.quantity}`;
        document.getElementById('modalProductPrice').value = parseFloat(selectedProductForModal.price).toFixed(2);
        document.getElementById('modalProductQty').value = 1; 
        
        const stockHint = document.getElementById('modalProductStockHint');
        if (selectedProductForModal.quantity <= 0) {
            stockHint.classList.remove('text-muted');
            stockHint.classList.add('text-danger', 'fw-bold');
            stockHint.textContent = `Out of Stock! (0)`;
        } else {
            stockHint.classList.remove('text-danger', 'fw-bold');
            stockHint.classList.add('text-muted');
        }

        document.getElementById('productSelectFormMessage').classList.add('d-none');
        productSelectModal.show();
        
        setTimeout(() => document.getElementById('modalProductQty').focus(), 500);
    }

    document.getElementById('increaseQtyBtn').addEventListener('click', () => {
        const input = document.getElementById('modalProductQty');
        input.value = parseInt(input.value) + 1;
    });

    document.getElementById('decreaseQtyBtn').addEventListener('click', () => {
        const input = document.getElementById('modalProductQty');
        if (parseInt(input.value) > 1) {
            input.value = parseInt(input.value) - 1;
        }
    });

    document.getElementById('modalProductQty').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('confirmAddToCartBtn').click();
        }
    });

    document.getElementById('modalProductPrice').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('confirmAddToCartBtn').click();
        }
    });

    // --- AUTO-ADD STOCK & ADD TO CART ---
    document.getElementById('confirmAddToCartBtn').addEventListener('click', async () => {
        const qty = parseInt(document.getElementById('modalProductQty').value);
        const price = parseFloat(document.getElementById('modalProductPrice').value);
        const messageDiv = document.getElementById('productSelectFormMessage');
        const btn = document.getElementById('confirmAddToCartBtn');

        if (isNaN(qty) || qty <= 0) {
            messageDiv.textContent = 'Please enter a valid quantity.';
            messageDiv.classList.remove('d-none', 'alert-success');
            messageDiv.classList.add('alert-danger');
            return;
        }

        if (isNaN(price) || price < 0) {
            messageDiv.textContent = 'Please enter a valid price.';
            messageDiv.classList.remove('d-none', 'alert-success');
            messageDiv.classList.add('alert-danger');
            return;
        }

        if (selectedProductForModal.quantity < qty) {
             const missingQty = qty - selectedProductForModal.quantity;
             const originalText = btn.innerText;
             btn.disabled = true;
             btn.innerText = 'Auto-adding Stock...';

             try {
                 const result = await apiRequest('stock.php?action=updateStock', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ product_id: selectedProductForModal.product_id, quantity_to_add: missingQty })
                 });

                 if (result.success) {
                     const prodIndex = products.findIndex(p => p.product_id == selectedProductForModal.product_id);
                     if (prodIndex !== -1) {
                         products[prodIndex].quantity += missingQty;
                     }
                     selectedProductForModal.quantity += missingQty;
                     startBackgroundSync();
                     addToCart(selectedProductForModal, qty, price);
                     productSelectModal.hide();
                     productSearchInput.value = ''; 
                     productSearchInput.focus();
                     showAlert(`Insufficient stock. Auto-added ${missingQty} units.`, 'success');
                 } else {
                     messageDiv.textContent = result.message || 'Failed to auto-add stock.';
                     messageDiv.classList.remove('d-none', 'alert-success');
                     messageDiv.classList.add('alert-danger');
                 }
             } catch (error) {
                 console.error(error);
                 messageDiv.textContent = 'Network error auto-adding stock.';
                 messageDiv.classList.remove('d-none', 'alert-success');
                 messageDiv.classList.add('alert-danger');
             } finally {
                 btn.disabled = false;
                 btn.innerText = originalText;
             }
             return;
        }

        addToCart(selectedProductForModal, qty, price);
        productSelectModal.hide();
        productSearchInput.value = ''; 
        productSearchInput.focus(); 
    });

    function addToCart(product, qty, price) {
        const existingItemIndex = cart.findIndex(item => item.product_id === product.product_id);

        if (existingItemIndex !== -1) {
            const item = cart.splice(existingItemIndex, 1)[0];
            item.qty += qty;
            item.price = price; 
            item.total = item.qty * price;
            cart.unshift(item);
        } else {
            cart.unshift({
                product_id: product.product_id,
                name: product.name,
                qty: qty,
                price: price,
                total: qty * price,
                discountPercent: 0, 
                discountAmount: 0,
                isFree: false
            });
        }
        updateCartUI();
    }

    // --- MODIFIED: Update UI to include +/- Buttons ---
    function updateCartUI(shouldSave = true) {
        cartItemsTableBody.innerHTML = '';
        let subtotal = 0;

        cart.forEach((item, index) => {
            let itemDiscVal = item.discountAmount || (item.total * (item.discountPercent / 100)) || 0;
            const finalItemTotal = item.total - itemDiscVal;
            subtotal += finalItemTotal;

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td style="vertical-align: middle;">
                    <div class="fw-bold text-dark">${item.name}</div>
                    ${itemDiscVal > 0 ? `<small class="text-success">Disc: Rs. ${itemDiscVal.toFixed(2)}</small>` : ''}
                </td>
                <td class="text-center" style="vertical-align: middle; width: 140px;">
                    <div class="input-group input-group-sm flex-nowrap shadow-sm">
                        <button class="btn btn-outline-secondary px-2 border-secondary decrease-qty-btn" data-index="${index}" type="button">
                            <i class="bi bi-dash"></i>
                        </button>
                        <input type="text" class="form-control text-center px-1 border-secondary fw-bold" value="${item.qty}" readonly style="min-width: 40px; background: #fff;">
                        <button class="btn btn-outline-secondary px-2 border-secondary increase-qty-btn" data-index="${index}" type="button">
                            <i class="bi bi-plus"></i>
                        </button>
                    </div>
                </td>
                <td class="text-end" style="vertical-align: middle;">${item.price.toFixed(2)}</td>
                <td class="text-end fw-bold" style="vertical-align: middle;">${finalItemTotal.toFixed(2)}</td>
                <td class="text-center" style="vertical-align: middle;">
                    <button class="btn btn-sm btn-link text-primary p-0 me-2 edit-item-btn" data-index="${index}"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-link text-danger p-0 remove-item-btn" data-index="${index}"><i class="bi bi-x-lg"></i></button>
                </td>
            `;
            cartItemsTableBody.appendChild(tr);
        });

        if (cart.length === 0) {
            cartItemsTableBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-5"><i class="bi bi-cart3 display-4 d-block mb-3 opacity-25"></i>Scan Item to Start</td></tr>';
        }

        let globalDiscountVal = invoiceDiscountPercentage > 0 ? subtotal * (invoiceDiscountPercentage / 100) : invoiceDiscountAmount;
        const grandTotal = subtotal - globalDiscountVal;

        subtotalDisplay.textContent = (subtotal + globalDiscountVal).toFixed(2);
        grandTotalDisplay.textContent = grandTotal.toFixed(2);
        
        const discountRow = document.getElementById('discountRow');
        const discountAmtDisplay = document.getElementById('discountAmountDisplay');
        
        if (discountRow && discountAmtDisplay) {
            if (globalDiscountVal > 0) {
                discountRow.style.display = 'block';
                discountAmtDisplay.textContent = globalDiscountVal.toFixed(2);
            } else {
                discountRow.style.display = 'none';
            }
        }

        if (grandTotal > 0) amountReceivedInput.value = grandTotal.toFixed(2);
        else amountReceivedInput.value = '';

        calculateChange();
        attachCartListeners();
        
        if(shouldSave) {
            saveCartToStorage();
            syncLiveCartToServer();
        }
    }

    function syncLiveCartToServer() {
        clearTimeout(liveCartSyncTimeout);
        liveCartSyncTimeout = setTimeout(async () => {
            let subtotal = 0;
            cart.forEach(item => {
                let itemDiscVal = item.discountAmount || (item.total * (item.discountPercent / 100)) || 0;
                subtotal += (item.total - itemDiscVal);
            });
            
            let globalDiscountVal = invoiceDiscountPercentage > 0 ? subtotal * (invoiceDiscountPercentage / 100) : invoiceDiscountAmount;
            const grandTotal = subtotal - globalDiscountVal;

            try {
                await fetch('live_sync.php?action=update_cart', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        user_id: loggedInUserId,
                        username: loggedInUsername,
                        cart: cart,
                        subtotal: subtotal,
                        discount: globalDiscountVal,
                        grand_total: grandTotal
                    })
                });
            } catch (e) {
                console.warn("Live sync failed", e);
            }
        }, 1000);
    }

    function calculateChange() {
        const received = parseFloat(amountReceivedInput.value) || 0;
        const grandTotal = parseFloat(grandTotalDisplay.textContent) || 0;
        const change = received - grandTotal;
        
        if (received > 0) {
            changeDueElement.textContent = change.toFixed(2);
            if (change < 0) {
                changeDueElement.className = 'fs-4 fw-bold text-danger'; 
            } else {
                changeDueElement.className = 'fs-4 fw-bold text-success'; 
            }
        } else {
             changeDueElement.textContent = '0.00';
             changeDueElement.className = 'fs-4 fw-bold text-success'; 
        }
    }

    amountReceivedInput.addEventListener('input', calculateChange);
    amountReceivedInput.addEventListener('click', function() { this.select(); });

    function attachCartListeners() {
        // Decrease Qty
        document.querySelectorAll('.decrease-qty-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                // Prevent bubbling to row clicks if any
                e.stopPropagation();
                const index = parseInt(e.currentTarget.dataset.index);
                if (cart[index].qty > 1) {
                    cart[index].qty--;
                    cart[index].total = cart[index].qty * cart[index].price;
                    updateCartUI();
                }
            });
        });

        // Increase Qty with Auto-Stock Logic
        document.querySelectorAll('.increase-qty-btn').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const index = parseInt(e.currentTarget.dataset.index);
                const item = cart[index];
                const btnEl = e.currentTarget;
                const originalContent = btnEl.innerHTML;

                // Disable button temporarily to prevent spam clicks
                btnEl.disabled = true;

                // Find current stock info
                // We prefer fresh data if possible, but local 'products' is okay for quick check
                // However, since we are doing auto-stock, we might need to check products array
                const productInCache = products.find(p => p.product_id == item.product_id);
                const currentStock = productInCache ? productInCache.quantity : 9999; // Default high if not tracked

                const newQty = item.qty + 1;

                if (currentStock < newQty) {
                    // Need to add 1 unit stock
                    btnEl.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
                    
                    try {
                        const result = await apiRequest('stock.php?action=updateStock', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ product_id: item.product_id, quantity_to_add: 1 })
                        });

                        if (result.success) {
                            // Update local cache
                            if (productInCache) productInCache.quantity += 1;
                            
                            // Proceed update cart
                            cart[index].qty++;
                            cart[index].total = cart[index].qty * cart[index].price;
                            updateCartUI();
                            
                            // Trigger bg sync
                            startBackgroundSync();
                        } else {
                            showAlert('Failed to auto-add stock: ' + result.message, 'danger');
                        }
                    } catch (err) {
                        console.error(err);
                        showAlert('Network error auto-adding stock.', 'danger');
                    } finally {
                        btnEl.disabled = false;
                        btnEl.innerHTML = originalContent; // UI will be redrawn by updateCartUI anyway, but good practice
                    }
                } else {
                    // Sufficient stock, just increment
                    cart[index].qty++;
                    cart[index].total = cart[index].qty * cart[index].price;
                    updateCartUI();
                    btnEl.disabled = false;
                }
            });
        });

        document.querySelectorAll('.remove-item-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const index = e.currentTarget.dataset.index;
                cart.splice(index, 1);
                updateCartUI();
            });
        });
        document.querySelectorAll('.edit-item-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                 const index = e.currentTarget.dataset.index;
                 const item = cart[index];
                 document.getElementById('editCartItemIndex').value = index;
                 document.getElementById('editCartItemName').value = item.name;
                 document.getElementById('editCartItemPrice').value = item.price;
                 document.getElementById('editCartItemQty').value = item.qty;
                 editCartItemModal.show();
            });
        });
    }

    document.getElementById('editCartItemForm').addEventListener('submit', (e) => {
        e.preventDefault();
        const index = document.getElementById('editCartItemIndex').value;
        const name = document.getElementById('editCartItemName').value;
        const price = parseFloat(document.getElementById('editCartItemPrice').value);
        const qty = parseInt(document.getElementById('editCartItemQty').value);

        if (price < 0 || qty <= 0) return;

        cart[index].name = name;
        cart[index].price = price;
        cart[index].qty = qty;
        cart[index].total = price * qty;
        
        updateCartUI();
        editCartItemModal.hide();
    });

    clearCartBtn.addEventListener('click', () => {
        if(confirm('Are you sure you want to clear the cart?')) {
            cart = [];
            invoiceDiscountPercentage = 0;
            invoiceDiscountAmount = 0;
            currentHeldBill = null;
            currentEditingSaleId = null;
            updateCartUI();
        }
    });

    // Shortcuts for Discount/Hold
    setDiscountBtn.addEventListener('click', () => {
        document.getElementById('modalDiscountPercentageInput').value = invoiceDiscountPercentage > 0 ? invoiceDiscountPercentage : '';
        document.getElementById('modalDiscountLKRInput').value = invoiceDiscountAmount > 0 ? invoiceDiscountAmount : '';
        document.getElementById('discountFormMessage').classList.add('d-none');
        discountModal.show();
    });

    document.getElementById('applyDiscountBtn').addEventListener('click', () => {
        const percent = parseFloat(document.getElementById('modalDiscountPercentageInput').value) || 0;
        const amount = parseFloat(document.getElementById('modalDiscountLKRInput').value) || 0;
        
        invoiceDiscountPercentage = percent;
        invoiceDiscountAmount = amount;
        
        updateCartUI();
        discountModal.hide();
    });

    // General Item Logic
    generalItemBtn.addEventListener('click', () => {
        document.getElementById('generalItemForm').reset();
        document.getElementById('generalItemFormMessage').classList.add('d-none');
        generalItemModal.show();
    });

    document.getElementById('generalItemForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const name = document.getElementById('generalItemName').value.trim();
        const price = parseFloat(document.getElementById('generalItemPrice').value);
        const qty = parseInt(document.getElementById('generalItemQtyToBill').value);
        const stockQty = parseInt(document.getElementById('generalItemStockQty').value); 

        if (!name || isNaN(price) || isNaN(qty) || price < 0 || qty <= 0) return;

        // Add to Cart Logic for General Item
        const tempId = 'GEN-' + Date.now();
        const generalItem = { product_id: tempId, name: name, price: price, qty: qty, quantity: 99999 };

        if (!isNaN(stockQty) && stockQty >= 0) {
             try {
                 const result = await apiRequest('products.php?action=saveGeneralProduct', {
                     method: 'POST',
                     headers: { 'Content-Type': 'application/json' },
                     body: JSON.stringify({ name, price, quantity: stockQty })
                 });
                 if (result.success) generalItem.product_id = result.product_id;
             } catch (err) { console.error('Failed to save general product', err); }
        }

        addToCart(generalItem, qty, price);
        generalItemModal.hide();
        // Force sync after adding new item
        startBackgroundSync();
    });

    async function processPayment(method) {
        if (cart.length === 0) {
            showAlert('Cart is empty!', 'warning');
            return;
        }

        const grandTotal = parseFloat(grandTotalDisplay.textContent);
        const amountReceived = parseFloat(amountReceivedInput.value) || 0;

        if (method === 'Cash' && amountReceived < grandTotal) {
            showAlert('Insufficient cash received!', 'danger');
            return;
        }

        const saleData = {
            user_id: loggedInUserId,
            branch_id: localStorage.getItem('branch_id') || null,
            total_amount: grandTotal,
            sale_date: new Date().toISOString().split('T')[0], 
            sale_time: new Date().toLocaleTimeString('en-GB'), 
            payment_method: method,
            discount_amount: parseFloat(discountAmountDisplay ? discountAmountDisplay.textContent : 0) || 0,
            status: 'Complete'
        };

        const saleItemsData = cart.map(item => ({
            product_id: item.product_id,
            product_name: item.name, 
            quantity: item.qty,
            price_at_sale: item.price,
            item_total: (item.qty * item.price) - (item.discountAmount || (item.total * (item.discountPercent/100)) || 0)
        }));

        try {
            let result;
            if (currentEditingSaleId) {
                 saleData.sale_id = currentEditingSaleId;
                 result = await apiRequest('sales.php?action=updateSale', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ sale: saleData, items: saleItemsData }) 
                 });
            } else if (currentHeldBill) {
                 saleData.sale_id = currentHeldBill.sale_id; 
                 result = await apiRequest('sales.php?action=completeHeldBill', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ sale: saleData, items: saleItemsData }) 
                 });
            } else {
                 result = await apiRequest('sales.php?action=saveSale', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ sale: saleData, items: saleItemsData })
                });
            }

            if (result.success) {
                localStorage.removeItem('activePosSession');

                if(method === 'Cash') {
                    await recordCashTransaction(method, grandTotal, result.sale_id);
                }

                showAlert('Payment Successful!', 'success');
                
                const receiptData = {
                    billNo: result.sale_id || currentEditingSaleId || (currentHeldBill ? currentHeldBill.sale_id : 'N/A'),
                    cashier: loggedInUsername,
                    customer: currentEditingSaleId ? 'Walk-in (Updated)' : 'Walk-in',
                    paymentMethod: method,
                    date: saleData.sale_date,
                    time: saleData.sale_time,
                    items: cart,
                    totalItems: cart.reduce((acc, item) => acc + item.qty, 0),
                    grossSubtotal: parseFloat(subtotalDisplay.textContent),
                    billDiscount: saleData.discount_amount,
                    grandTotal: grandTotal,
                    paidAmount: method === 'Cash' ? amountReceived : grandTotal,
                    balanceAmount: method === 'Cash' ? (amountReceived - grandTotal) : 0
                };
                localStorage.setItem('currentReceiptData', JSON.stringify(receiptData));

                cart = [];
                invoiceDiscountPercentage = 0;
                invoiceDiscountAmount = 0;
                currentHeldBill = null;
                currentEditingSaleId = null;
                updateCartUI();
                
                // Refresh stock after sale
                startBackgroundSync();

                return true; 
            } else {
                showAlert('Transaction Failed: ' + result.message, 'danger');
                return false;
            }
        } catch (error) {
            showAlert('Network error processing transaction.', 'danger');
            return false;
        }
    }
    
    async function recordCashTransaction(method, amount, saleId) {
        if (method !== 'Cash') return;

        try {
            await apiRequest('cash_drawer.php?action=recordTransaction', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    user_id: loggedInUserId,
                    amount: amount,
                    type: 'Sale', 
                    description: `Sale #${saleId}`,
                    transaction_date: new Date().toISOString().split('T')[0],
                    transaction_time: new Date().toLocaleTimeString('en-GB'),
                    sale_id: saleId
                })
            });
        } catch (e) {
            console.error('Failed to log cash drawer transaction', e);
        }
    }

    cashPaymentBtn.addEventListener('click', () => processPayment('Cash'));
    cardPaymentBtn.addEventListener('click', () => processPayment('Card'));
    
    cashPrintBtn.addEventListener('click', async () => {
        if (await processPayment('Cash')) {
            window.open('receipt.html', '_blank', 'width=400,height=600');
        }
    });
    
    cardPrintBtn.addEventListener('click', async () => {
        if (await processPayment('Card')) {
             window.open('receipt.html', '_blank', 'width=400,height=600');
        }
    });

    otherPaymentDropdownItems.forEach(item => {
        item.addEventListener('click', (e) => {
            e.preventDefault();
            const method = e.target.dataset.method;
            processPayment(method);
        });
    });

    holdPaymentBtn.addEventListener('click', async () => {
        if (cart.length === 0) return;
        
        const grandTotal = parseFloat(grandTotalDisplay.textContent);
        
        const saleData = {
            user_id: loggedInUserId,
            branch_id: localStorage.getItem('branch_id') || null,
            total_amount: grandTotal,
            sale_date: new Date().toISOString().split('T')[0],
            sale_time: new Date().toLocaleTimeString('en-GB'),
            payment_method: 'Hold',
            discount_amount: parseFloat(discountAmountDisplay ? discountAmountDisplay.textContent : 0) || 0,
            status: 'Hold'
        };
        const saleItemsData = cart.map(item => ({
            product_id: item.product_id,
            product_name: item.name, 
            quantity: item.qty,
            price_at_sale: item.price,
            item_total: (item.qty * item.price)
        }));

        try {
            const result = await apiRequest('sales.php?action=saveSale', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sale: saleData, items: saleItemsData })
            });

            if (result.success) {
                showAlert('Bill Held Successfully.', 'warning');
                cart = [];
                updateCartUI();
            } else {
                showAlert('Failed to hold bill.', 'danger');
            }
        } catch (e) {
            showAlert('Error holding bill.', 'danger');
        }
    });

    viewHeldBillsBtn.addEventListener('click', loadHeldBills);

    async function loadHeldBills() {
        const tbody = document.getElementById('heldBillsTableBody');
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">Loading...</td></tr>';
        heldBillsModal.show();

        try {
            const data = await apiRequest('sales.php?action=getHeldBills', { method: 'GET' });
            
            tbody.innerHTML = '';
            if (!data.held_bills || data.held_bills.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No held bills found.</td></tr>';
                return;
            }

            data.held_bills.forEach(bill => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${bill.sale_id}</td>
                    <td>${bill.total_amount}</td>
                    <td>${bill.sale_date}</td>
                    <td>${bill.sale_time}</td>
                    <td>
                        <button class="btn btn-sm btn-primary restore-bill-btn me-2" data-id="${bill.sale_id}">Open</button>
                        <button class="btn btn-sm btn-danger delete-bill-btn" data-id="${bill.sale_id}">Delete</button>
                    </td>
                `;
                tbody.appendChild(tr);
            });

            document.querySelectorAll('.restore-bill-btn').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    const saleId = e.target.dataset.id;
                    await restoreHeldBill(saleId);
                });
            });

            document.querySelectorAll('.delete-bill-btn').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    if(confirm('Are you sure you want to delete this held bill?')) {
                        const saleId = e.target.dataset.id;
                        await deleteHeldBill(saleId);
                    }
                });
            });

        } catch (error) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading bills.</td></tr>';
        }
    }

    async function deleteHeldBill(saleId) {
        try {
            const result = await apiRequest('sales.php?action=deleteHeldBill', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ sale_id: saleId })
            });

            if(result.success) {
                showAlert('Held bill deleted successfully.', 'success');
                loadHeldBills(); 
            } else {
                showAlert('Failed to delete bill: ' + (result.message || 'Unknown error'), 'danger');
            }
        } catch (error) {
            showAlert('Network error deleting bill.', 'danger');
        }
    }

    async function restoreHeldBill(saleId) {
        try {
            const data = await apiRequest(`sales.php?action=getHeldBillDetails&sale_id=${saleId}`, { method: 'GET' });
            
            if (data.success) {
                cart = [];
                // Refresh products to ensure we have latest names if needed
                if(products.length === 0) await initializeProducts();

                data.items.forEach(item => {
                    let itemName = item.name;
                    if (!itemName || itemName === 'Unknown Item') {
                        const foundProduct = products.find(p => p.product_id == item.product_id);
                        if (foundProduct) {
                            itemName = foundProduct.name;
                        }
                    }
                    let quantity = item.quantity !== undefined ? parseInt(item.quantity) : (item.qty !== undefined ? parseInt(item.qty) : 1);
                    if(isNaN(quantity) || quantity < 1) quantity = 1;

                    cart.push({
                        product_id: item.product_id,
                        name: itemName || 'Unknown Item',
                        qty: quantity, 
                        price: parseFloat(item.price_at_sale),
                        total: parseFloat(item.item_total),
                        discountPercent: 0, 
                        discountAmount: 0 
                    });
                });
                
                currentHeldBill = data.sale;
                
                updateCartUI();
                heldBillsModal.hide();
                showAlert(`Held Bill #${saleId} opened.`, 'info');
            }
        } catch (error) {
            showAlert('Failed to restore bill.', 'danger');
        }
    }

    function updateDateTime() {
        const now = new Date();
        const formattedDate = now.toLocaleDateString('en-GB', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        const formattedTime = now.toLocaleTimeString('en-GB');
        
        const dateTimeDisplay = document.getElementById('dateTimeDisplay');
        if (dateTimeDisplay) {
            dateTimeDisplay.textContent = `${formattedDate} ${formattedTime}`;
        }
    }
    updateDateTime();
    setInterval(updateDateTime, 1000);

    initializeProducts(); // Starts initial load AND background sync
    
    // --- LOAD CART / EDIT SALE LOGIC ---
    // Check for "Edit Sale" trigger from Sales History page
    const pendingEditId = localStorage.getItem('edit_sale_id');
    
    if (pendingEditId) {
        localStorage.removeItem('edit_sale_id'); // Clear flag immediately
        loadSaleForEditing(pendingEditId);
    } else {
        loadCartFromStorage(); // Only load backup if not starting a specific edit
    }

    logoutBtn.addEventListener('click', () => {
        localStorage.removeItem('user_id');
        localStorage.removeItem('username');
        localStorage.removeItem('role');
        localStorage.removeItem('activePosSession'); 
        if(syncInterval) clearInterval(syncInterval);
        window.location.href = 'login.html'; 
    });

    returnToDashboardBtn.addEventListener('click', () => {
        window.location.href = 'dashboard.html'; 
    });

    // Handle Quick Nav Buttons
    const navSalesBtn = document.getElementById('navSalesBtn');
    const navProductsBtn = document.getElementById('navProductsBtn');
    const navReturnsBtn = document.getElementById('navReturnsBtn');

    if(navSalesBtn) navSalesBtn.addEventListener('click', () => window.open('sales_history.html', '_blank'));
    if(navProductsBtn) navProductsBtn.addEventListener('click', () => window.open('product-management.php', '_blank'));
    if(navReturnsBtn) navReturnsBtn.addEventListener('click', () => window.open('return_panel.html', '_blank'));

    // --- Keyboard Shortcuts ---
    document.addEventListener('keydown', (e) => {
        if (e.altKey) {
            switch (e.key.toLowerCase()) {
                case 'g':
                    e.preventDefault();
                    if(generalItemBtn) generalItemBtn.click();
                    break;
                case 's':
                    e.preventDefault();
                    if(productSearchInput) productSearchInput.focus();
                    break;
                case 'y':
                    e.preventDefault();
                    if(cashPaymentBtn) cashPaymentBtn.click();
                    break;
                case 'i':
                    e.preventDefault();
                    if(cardPaymentBtn) cardPaymentBtn.click();
                    break;
                case 'u':
                    e.preventDefault();
                    if(cashPrintBtn) cashPrintBtn.click();
                    break;
                case 'o':
                    e.preventDefault();
                    if(cardPrintBtn) cardPrintBtn.click();
                    break;
                case 'h':
                    e.preventDefault();
                    if(holdPaymentBtn) holdPaymentBtn.click();
                    break;
            }
        }
    });
});