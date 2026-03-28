-- =====================================================
-- MIGRATION: Thêm bảng CV vào Database TC
-- Chạy trên database: taichinh_fdsew
-- Ngày: 23/03/2026
-- KHÔNG ảnh hưởng dữ liệu TC hiện có
-- =====================================================

-- 1. Bảng phòng làm việc
CREATE TABLE IF NOT EXISTS cv_rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(10) DEFAULT '📁',
    color VARCHAR(7) DEFAULT '#3b82f6',
    sort_order INT DEFAULT 0,
    sla_days INT DEFAULT 0 COMMENT 'Thời hạn (số ngày), 0 = không giới hạn',
    is_archive TINYINT DEFAULT 0,
    action_options TEXT DEFAULT NULL,
    result_options TEXT DEFAULT NULL,
    worklog_config MEDIUMTEXT DEFAULT NULL COMMENT 'JSON cấu hình 3 cấp: Action → Results'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Thêm cột room_id vào loans (phòng CV hiện tại)
ALTER TABLE loans ADD COLUMN cv_room_id INT DEFAULT NULL AFTER store_id;
ALTER TABLE loans ADD COLUMN cv_assigned_to INT DEFAULT NULL AFTER cv_room_id;
ALTER TABLE loans ADD COLUMN cv_status VARCHAR(20) DEFAULT 'active' AFTER cv_assigned_to;
ALTER TABLE loans ADD COLUMN cv_due_date DATE DEFAULT NULL AFTER cv_status;
ALTER TABLE loans ADD COLUMN cv_transfer_date DATETIME DEFAULT NULL AFTER cv_due_date;
ALTER TABLE loans ADD COLUMN cv_pinned_note TEXT DEFAULT NULL AFTER cv_transfer_date;
ALTER TABLE loans ADD COLUMN cv_description TEXT DEFAULT NULL AFTER cv_pinned_note;
ALTER TABLE loans ADD COLUMN cv_notes TEXT DEFAULT NULL AFTER cv_description;
ALTER TABLE loans ADD COLUMN cv_planned_next_room_id INT DEFAULT NULL AFTER cv_notes;
ALTER TABLE loans ADD COLUMN cv_drive_folder_id VARCHAR(255) DEFAULT NULL AFTER cv_planned_next_room_id;

-- 3. Thêm cv_role vào users
ALTER TABLE users ADD COLUMN cv_role VARCHAR(20) DEFAULT NULL COMMENT 'admin, manager, employee - NULL = không có quyền CV';

-- 4. Bảng nhật ký công việc
CREATE TABLE IF NOT EXISTS cv_work_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL COMMENT 'FK → loans.id',
    user_id INT NOT NULL COMMENT 'FK → users.id',
    room_id INT DEFAULT NULL COMMENT 'FK → cv_rooms.id',
    work_done TEXT,
    log_date DATE NOT NULL,
    action_type VARCHAR(255) DEFAULT NULL,
    result_type VARCHAR(255) DEFAULT NULL,
    promise_date DATE DEFAULT NULL,
    amount DECIMAL(15,2) DEFAULT NULL COMMENT 'Lãi đã trả',
    amount_principal DECIMAL(15,2) DEFAULT NULL COMMENT 'Gốc đã trả',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Bảng lịch sử chuyển phòng
CREATE TABLE IF NOT EXISTS cv_transfer_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL COMMENT 'FK → loans.id',
    from_room_id INT DEFAULT NULL COMMENT 'FK → cv_rooms.id',
    to_room_id INT DEFAULT NULL COMMENT 'FK → cv_rooms.id',
    transferred_by INT DEFAULT NULL COMMENT 'FK → users.id',
    transferred_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    note TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Bảng bình luận
CREATE TABLE IF NOT EXISTS cv_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL COMMENT 'FK → loans.id',
    user_id INT NOT NULL COMMENT 'FK → users.id',
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Bảng vi phạm
CREATE TABLE IF NOT EXISTS cv_violations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    loan_id INT DEFAULT NULL COMMENT 'FK → loans.id',
    user_id INT DEFAULT NULL COMMENT 'FK → users.id',
    detail TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Bảng file đính kèm
CREATE TABLE IF NOT EXISTS cv_customer_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL COMMENT 'FK → loans.id',
    file_name VARCHAR(255) NOT NULL,
    drive_file_id VARCHAR(255) DEFAULT NULL,
    drive_folder_id VARCHAR(255) DEFAULT NULL,
    drive_link TEXT DEFAULT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    file_size INT DEFAULT 0,
    uploaded_by INT DEFAULT NULL COMMENT 'FK → users.id',
    uploader VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Seed phòng mặc định
INSERT INTO cv_rooms (name, icon, color, sort_order, sla_days) VALUES
('Tín dụng 1', '💳', '#ef4444', 1, 10),
('Tín dụng 2', '💳', '#f97316', 2, 10),
('Tín dụng 3', '💳', '#eab308', 3, 10),
('Hợp bàn 1',  '🤝', '#22c55e', 4, 10),
('Hợp bàn 2',  '🤝', '#14b8a6', 5, 10),
('CNC',        '🏠', '#3b82f6', 6, 0),
('Lưu trữ',   '📦', '#6b7280', 99, 0);

-- =====================================================
-- XONG! Chạy script này 1 lần trên phpMyAdmin hoặc CLI
-- TC vẫn hoạt động bình thường, không mất dữ liệu
-- =====================================================
