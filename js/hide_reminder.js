// Toggle hide from reminder checkbox
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.hide-reminder-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            const loanId = this.dataset.loanId;
            const isHidden = this.checked ? 1 : 0;

            fetch('contract_toggle_hidden.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `loan_id=${loanId}&is_hidden=${isHidden}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const action = isHidden ? 'ẩn' : 'hiện';
                        alert(`✅ Đã ${action} khách hàng khỏi danh sách nhắc nhở!`);
                        location.reload();
                    } else {
                        alert('❌ Lỗi: ' + (data.error || 'Unknown error'));
                        this.checked = !this.checked;
                    }
                })
                .catch(error => {
                    alert('❌ Lỗi kết nối: ' + error);
                    this.checked = !this.checked;
                });
        });
    });
});
