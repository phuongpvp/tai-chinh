function openContractModal(url) {
    // 1. Show Loading
    const modalBody = document.querySelector('#contractModal .modal-body');
    // Clear previous content
    modalBody.innerHTML = '<div class="text-center p-5"><div class="spinner-border text-primary" role="status"></div><br>Đang tải dữ liệu...</div>';

    const modal = new bootstrap.Modal(document.getElementById('contractModal'), {
        keyboard: false
    });
    modal.show();

    // 2. Add view_mode=modal param
    const fetchUrl = url + (url.includes('?') ? '&' : '?') + 'view_mode=modal';

    // 3. Create Iframe
    const iframe = document.createElement('iframe');
    iframe.src = fetchUrl;
    iframe.style.width = '100%';
    iframe.style.height = '80vh'; // Fixed height
    iframe.style.border = 'none';

    // Hide spinner when loaded
    iframe.onload = function () {
        // Optional: Remove spinner if we kept it separate
    };

    modalBody.innerHTML = '';
    modalBody.appendChild(iframe);
}
