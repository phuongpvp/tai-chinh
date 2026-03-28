        </main>
    </div>

    <!-- TOAST CONTAINER -->
    <div class="toast-container" id="toast-container"></div>

    <script>
    // === SIDEBAR TOGGLE (Mobile) ===
    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
    }

    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(e) {
        const sidebar = document.getElementById('sidebar');
        const btn = document.getElementById('mobile-menu-btn');
        if (window.innerWidth <= 768 && sidebar.classList.contains('open') 
            && !sidebar.contains(e.target) && !btn.contains(e.target)) {
            sidebar.classList.remove('open');
        }
    });

    // === DROPDOWN ===
    function toggleDropdown(id) {
        const menu = document.getElementById(id);
        document.querySelectorAll('.dropdown-menu.active').forEach(m => {
            if (m.id !== id) m.classList.remove('active');
        });
        menu.classList.toggle('active');
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.user-info') && !e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-menu.active').forEach(m => m.classList.remove('active'));
        }
    });

    // === TOAST NOTIFICATIONS ===
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const icons = { success: '✅', error: '❌', warning: '⚠️' };
        const toast = document.createElement('div');
        toast.className = 'toast ' + type;
        toast.innerHTML = '<span>' + (icons[type] || '') + '</span><span>' + message + '</span>';
        container.appendChild(toast);
        setTimeout(() => {
            toast.style.animation = 'toast-out 0.3s ease forwards';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // === MODAL ===
    function openModal(id) {
        document.getElementById(id).classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
        document.body.style.overflow = '';
    }

    // Close modal on overlay click
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    });

    // === CONFIRM DELETE ===
    function confirmDelete(message, url) {
        if (confirm(message || 'Bạn có chắc chắn muốn xóa?')) {
            window.location.href = url;
        }
    }

    // Show flash message from PHP session
    <?php if (isset($_SESSION['flash_message'])): ?>
        showToast('<?= addslashes($_SESSION['flash_message']) ?>', '<?= $_SESSION['flash_type'] ?? 'success' ?>');
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>
    </script>
</body>
</html>
