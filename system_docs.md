# 📋 Hệ Thống Quản Lý Công Việc — Tài Liệu Kỹ Thuật

> Cập nhật: 28/03/2026 | Phiên bản: 1.2.0 | PHP + MySQL (Shared Hosting)

---

## 📁 Cấu Trúc File

| File | Mô tả | Quyền truy cập |
|------|--------|----------------|
| `config.php` | Cấu hình DB, session, helper functions | — (include) |
| `logout.php` | Đăng xuất (xóa session → redirect TC login) | All |
| `index.php` | Dashboard — tổng quan phòng | All |
| `room.php` | Chi tiết phòng — danh sách khách | All |
| `customer.php` | Chi tiết khách hàng (10 actions) | All |
| `customers.php` | Danh sách toàn bộ khách (bảng + filter) | All |
| `worklog_add.php` | Form ghi nhật ký 3 cấp | All |
| `violations.php` | Danh sách vi phạm quy trình | Admin/Manager |
| `users.php` | Quản lý nhân viên (CRUD) | Admin |
| `rooms_manage.php` | Quản lý phòng (CRUD) | Admin |
| `room_config.php` | Cấu hình nhật ký phòng (Action→Result) | Admin |
| `data_manage.php` | Import/Export/Xóa dữ liệu (có mật khẩu) | Admin + Password |
| `profile.php` | Thông tin cá nhân + đổi mật khẩu | All |
| `search_api.php` | API tìm khách (JSON) | All |
| `google_drive.php` | Google Drive helper class (OAuth2) | — (include) |
| `gdrive_auth.php` | Google Drive authorization callback | Admin |
| `layout_top.php` | Header + Sidebar (shared layout) | — (include) |
| `layout_bottom.php` | Footer + Flash message (shared layout) | — (include) |
| `style.css` | CSS toàn bộ ứng dụng | — (static) |
| `sync_from_tc.php` | Trang đồng bộ thủ công từ TC (dashboard + form) | Admin/Manager |
| `cron_sync_tc.php` | Cron job tự động sync mỗi sáng 6h | Cron / URL key |
| `install_v2.php` | Migration script (chạy 1 lần) | — (xóa sau) |
| `import_all.php` | Script import backup (chạy riêng) | — (xóa sau) |

---

## 🗄️ Cấu Trúc Database

### Bảng `users`
| Cột | Kiểu | Mô tả |
|-----|------|-------|
| id | INT PK AUTO | |
| username | VARCHAR | Tên đăng nhập (unique) |
| password | VARCHAR | Mật khẩu (bcrypt hash) |
| full_name | VARCHAR | Họ tên |
| role | ENUM | `admin`, `manager`, `employee` |
| is_active | TINYINT | 1 = hoạt động |
| created_at | DATETIME | |

### Bảng `rooms`
| Cột | Kiểu | Mô tả |
|-----|------|-------|
| id | INT PK AUTO | |
| name | VARCHAR | Tên phòng |
| icon | VARCHAR | Emoji icon |
| color | VARCHAR | Mã màu HEX |
| sort_order | INT | Thứ tự hiển thị |
| sla_days | INT | **Thời hạn** (số ngày) — 0 = không giới hạn |
| is_archive | TINYINT | 1 = phòng lưu trữ |
| action_options | TEXT | JSON — danh sách hành động (cũ) |
| result_options | TEXT | JSON — danh sách kết quả (cũ) |
| worklog_config | MEDIUMTEXT | JSON — cấu hình 3 cấp: Action → Results |

### Bảng `customers`
| Cột | Kiểu | Mô tả |
|-----|------|-------|
| id | INT PK AUTO | |
| name | VARCHAR | Họ tên khách |
| room_id | INT FK → rooms | Phòng hiện tại |
| assigned_to | INT FK → users | Nhân viên phụ trách |
| status | VARCHAR | `active`, `completed`, `overdue` |
| due_date | DATE | Ngày hết hạn (tính từ SLA) |
| transfer_date | DATETIME | Ngày chuyển vào phòng hiện tại |
| notes | TEXT | Ghi chú |
| pinned_note | TEXT | Nội dung lưu ý (ghim) |
| phone | VARCHAR(50) | SĐT |
| cccd | VARCHAR(20) | Số CCCD |
| address | TEXT | Địa chỉ |
| hktt | TEXT | Hộ khẩu thường trú |
| facebook_link | TEXT | Link Facebook |
| company_tag | VARCHAR(100) | Thuộc công ty (VD: "Đức 1") |
| workplace | VARCHAR(255) | Đơn vị công tác |
| relatives_info | TEXT | Thông tin người thân |
| description | TEXT | Mô tả khách hàng |
| planned_next_room_id | INT FK → rooms | Phòng dự kiến chuyển đến |
| drive_folder_id | VARCHAR | ID folder Google Drive của khách |
| created_at | DATETIME | |

### Bảng `work_logs`
| Cột | Kiểu | Mô tả |
|-----|------|-------|
| id | INT PK AUTO | |
| customer_id | INT FK → customers | Khách hàng |
| user_id | INT FK → users | Người ghi |
| room_id | INT FK → rooms | Phòng làm việc |
| work_done | TEXT | Nội dung công việc |
| log_date | DATE | Ngày làm |
| action_type | VARCHAR/TEXT | Loại hành động (VD: "Gọi điện") |
| result_type | VARCHAR/TEXT | Kết quả (VD: "Hẹn trả gốc") |
| promise_date | DATE | Ngày hẹn |
| amount | DECIMAL(15,0) | Số tiền |
| created_at | DATETIME | |

### Bảng `transfer_logs`
| Cột | Kiểu | Mô tả |
|-----|------|-------|
| id | INT PK AUTO | |
| customer_id | INT FK → customers | Khách hàng |
| from_room_id | INT FK → rooms | Phòng cũ |
| to_room_id | INT FK → rooms | Phòng mới |
| transferred_by | INT FK → users | Người chuyển |
| transferred_at | DATETIME | Thời điểm chuyển |
| note | TEXT | Ghi chú |

### Bảng `comments`
| Cột | Kiểu | Mô tả |
|-----|------|-------|
| id | INT PK AUTO | |
| customer_id | INT FK | Khách hàng |
| user_id | INT FK | Người bình luận |
| content | TEXT | Nội dung |
| created_at | DATETIME | |

### Bảng `violations`
| Cột | Kiểu | Mô tả |
|-----|------|-------|
| id | INT PK AUTO | |
| type | VARCHAR | `TRANSFER_SAME_ROOM`, `DUPLICATE_TRANSFER`, `DATA_MISSING` |
| customer_id | INT FK | Khách hàng liên quan |
| user_id | INT FK | Người thao tác |
| detail | TEXT | JSON chi tiết |
| created_at | DATETIME | |

### Bảng `customer_files`
| Cột | Kiểu | Mô tả |
|-----|------|-------|
| id | INT PK AUTO | |
| customer_id | INT FK | Khách hàng |
| file_name | VARCHAR | Tên file gốc |
| drive_file_id | VARCHAR | ID file trên Google Drive |
| drive_folder_id | VARCHAR | ID folder trên Drive |
| drive_link | TEXT | Link xem file |
| mime_type | VARCHAR | Loại file |
| file_size | INT | Kích thước (bytes) |
| uploaded_by | INT FK → users | Người upload |
| created_at | DATETIME | |

---

## 🔐 Hệ Thống Phân Quyền

**Luồng xác thực (đã gộp TC):**
1. CV **KHÔNG có trang login riêng** — dùng session TC (`/login.php`)
2. Chưa đăng nhập TC → vào CV sẽ redirect về `/login.php` (TC)
3. Đăng nhập TC rồi → vào CV được nếu user có `cv_role` (set trong TC phân quyền)
4. Đăng xuất TC → mất session → CV cũng không vào được
5. `cv_role` được quản lý từ trang **Phân quyền TC** (`user_permissions.php`)

**Vai trò CV (`cv_role` trong bảng `users`):**
```
admin       → Toàn quyền (CRUD users/rooms, data, mark completed)
employee    → Ghi nhật ký, bình luận, xem khách (KHÔNG chuyển phòng, KHÔNG mark completed)
```

---

## 🔄 Luồng Xử Lý Chính

### 1. Dashboard (`index.php`)

```
Vào Dashboard → Query thống kê mỗi phòng (total, overdue, warning, safe)
             → Hiển thị 2 view: Gallery (card) hoặc Table
             → Phòng lưu trữ hiện riêng với opacity thấp
             → Click vào phòng → room.php?id=X
```

**Stats tính toán:**
- 🔴 Quá hạn: `due_date < CURDATE()`
- 🟡 Sắp quá hạn: `due_date >= CURDATE() AND due_date <= CURDATE() + 3 ngày`
- 🟢 Còn hạn: `due_date > CURDATE() + 3 ngày OR due_date IS NULL`

### 2. Chi Tiết Phòng (`room.php`)

```
room.php?id=X → Lấy thông tin phòng (bao gồm SLA)
              → Lấy danh sách khách (filter: all/overdue/warning/safe + search)
              → Hiển thị card grid (tên, trạng thái, công ty, NV phụ trách)
              → Nút "Thêm khách" → modal form
```

**Thêm khách mới:**
- Nếu nhập due_date → dùng giá trị nhập
- Nếu KHÔNG nhập + phòng có SLA > 0 → `due_date = NOW() + sla_days`
- Nếu KHÔNG nhập + SLA = 0 → `due_date = NULL` → hiện "Chưa có hạn"

### 3. Chi Tiết Khách Hàng (`customer.php`)

Trang phức tạp nhất, có **10 actions** xử lý qua POST:

| Action | Mô tả |
|--------|--------|
| `update_info` | Cập nhật toàn bộ thông tin khách (tên, SĐT, CCCD, công ty, địa chỉ...) |
| `save_description` | Lưu mô tả khách |
| `change_room` | **Chuyển phòng** (có transaction, chống duplicate, tính SLA mới) |
| `add_comment` | Thêm bình luận |
| `add_worklog` | Thêm nhật ký từ trang chi tiết khách |
| `update_pinned` | Cập nhật nội dung lưu ý (ghim) |
| `mark_completed` | Đánh dấu hoàn thành (chỉ admin/manager) |
| `upload_file` | Upload file lên Google Drive |
| `delete_file` | Xóa file từ Google Drive |

**Giao diện tab:**
- 💬 Bình luận
- 📋 Nhật ký
- 📜 Chuyển phòng
- 📌 Lưu ý
- 📁 File

#### Luồng Chuyển Phòng (quan trọng nhất)

```
Bấm "Chuyển phòng" → Chọn phòng đích + ghi chú
  ↓
Validate: Không được chuyển cùng phòng → tạo Violation TRANSFER_SAME_ROOM
  ↓
Chống duplicate: Check transfer_logs 60 giây gần nhất → tạo Violation DUPLICATE_TRANSFER
  ↓
BEGIN TRANSACTION
  1. INSERT transfer_logs (from_room_id, to_room_id, transferred_by, transferred_at, note)
  2. Lấy SLA phòng mới
  3. Tính due_date mới = NOW() + sla_days (nếu SLA > 0)
  4. UPDATE customers SET room_id=?, transfer_date=NOW(), due_date=?, planned_next_room_id=NULL
COMMIT
```

### 4. Nhật Ký Làm Việc (`worklog_add.php`)

Form **3 cấp** (progressive disclosure):

```
Bước 1: Tìm và chọn khách hàng (search + dropdown)
  ↓
Bước 2: Chọn phòng làm việc (tag buttons)
  ↓
Bước 3: Chọn hành động (load từ worklog_config của phòng)
  ↓
Bước 4: Chọn kết quả (load từ results của action)
  ↓ (tùy kết quả)
Bước 5: Nhập ngày hẹn (show_date = true)
Bước 6: Nhập số tiền (show_amount = true)
Bước 7: Ghi chú
  ↓
Submit → INSERT work_logs
```

**Cấu hình worklog (`room_config.php`):**
```json
[
  {
    "action": "Gọi điện",
    "results": [
      {"label": "Nghe máy - Hẹn trả", "show_date": true, "show_amount": true},
      {"label": "Không nghe máy", "show_date": false, "show_amount": false}
    ]
  },
  {
    "action": "Nhắn tin"  ,
    "results": [...]
  }
]
```

### 5. Quản Lý Phòng (`rooms_manage.php`)

```
CRUD phòng: Thêm / Sửa / Xóa
  - Tên phòng, Icon (emoji), Màu, Thứ tự hiển thị
  - Thời hạn (SLA) — số ngày
  - Đánh dấu phòng lưu trữ
  - Nút ⚙️ → room_config.php (cấu hình nhật ký)
  
Xóa phòng: phải KHÔNG CÒN khách active trong phòng
```

### 6. Quản Lý Nhân Viên (`users.php`)

```
CRUD nhân viên: Thêm / Sửa / Xóa
  - Tên đăng nhập (unique), họ tên, mật khẩu (bcrypt)
  - Vai trò: admin / manager / employee
  - Trạng thái hoạt động (is_active)
  - Không thể xóa chính mình
```

### 7. Vi Phạm Quy Trình (`violations.php`)

Tự động ghi nhận khi:
- **TRANSFER_SAME_ROOM**: Cố chuyển khách sang cùng phòng hiện tại
- **DUPLICATE_TRANSFER**: Chuyển phòng trùng lặp trong 60 giây

Giao diện: Bảng + filter theo loại + phân trang (20/trang)

### 8. Import/Export Dữ Liệu (`data_manage.php`)

**Bảo mật:** Login admin + Mật khẩu riêng (`admin@2026`)

#### Import (3 file):
| File | Bắt buộc | Cột chính |
|------|----------|-----------|
| Khách hàng (.xlsx) | ✅ | A:Tên, B:CCCD, D:HKTT, E:Facebook, J:Phòng, M:SĐT, P:Công ty, W:Đơn vị |
| Nhật ký (.xlsx) | Không | Ngày, Tên khách, Phòng, Hành động, Kết quả, Ngày hẹn, Số tiền |
| Chuyển phòng (.xlsx) | Không | A:Từ phòng, B:Ngày, C:Người chuyển, D:Đến phòng, G:Tên khách, H:Vi phạm |

**Luồng import:**
```
Upload file → Đọc XLSX (ZipArchive + DOMDocument)
  → Tạo phòng mới nếu cần (exact matching, KHÔNG fuzzy)
  → Import khách: fix CCCD/SĐT, tính due_date từ SLA
  → Import nhật ký: map tên khách → customer_id
  → Import chuyển phòng: map phòng + người chuyển
  → Hiện kết quả
```

#### Export:
- Xuất CSV (UTF-8 BOM cho Excel): tên, phòng, SĐT, CCCD, HKTT, Facebook, công ty...

#### Xóa tất cả:
- Phải gõ `XOA TAT CA` + confirm dialog
- Xóa: customers + work_logs + transfer_logs

### 9. Google Drive (`google_drive.php` + `gdrive_auth.php`)

Upload file đính kèm khách hàng lên Google Drive:

```
Lần đầu: Admin vào gdrive_auth.php → Authorize Google → Lưu refresh_token
  ↓
Upload file: customer.php → action=upload_file
  → Tạo subfolder cho khách (nếu chưa có)
  → Upload file vào subfolder
  → Set quyền public (anyone can view)
  → Lưu link vào customer_files
```

### 10. Tìm Kiếm (`search_api.php`)

API JSON trả về danh sách khách hàng match theo tên hoặc SĐT:
```
GET search_api.php?q=Nguyễn → JSON [{id, name, phone, status, room_name}]
```
Giới hạn 10 kết quả, dùng cho auto-suggest.

---

## 🎨 Layout & Sidebar (`layout_top.php`)

Sidebar navigation:

```
📊 Dashboard           → index.php
👥 Khách hàng          → customers.php
📝 Nhật ký             → worklog_add.php
───── Phòng ─────
  🏠 Tín dụng 1        → room.php?id=X
  🏠 Thối 1            → room.php?id=Y
  ...
───── Quản lý ───── (admin/manager)
  ⚠️ Vi phạm           → violations.php
  👥 Nhân viên          → users.php        (admin only)
  🏢 Quản lý phòng     → rooms_manage.php  (admin only)
  💾 Dữ liệu           → data_manage.php   (admin only)
```

> ⚠️ **Bug đã fix:** Biến `$sidebarRoom` trong foreach loop sidebar — trước đây dùng `$room` gây đè biến trên `room.php`.

---

## ⚙️ Helper Functions (`config.php`)

| Function | Mô tả |
|----------|--------|
| `isLoggedIn()` | Kiểm tra session có user_id |
| `requireLogin()` | Redirect về login nếu chưa đăng nhập |
| `requireRole($roles)` | Kiểm tra role, trả 403 nếu không đủ quyền |
| `currentUser()` | Trả về array {id, username, full_name, role} |
| `sanitize($str)` | `htmlspecialchars()` để chống XSS |
| `redirect($path)` | Redirect với BASE_URL prefix |
| `jsonResponse($data)` | Trả JSON response |
| `getDaysRemaining($due_date)` | Tính số ngày còn lại (null nếu không có hạn) |
| `getStatusColor($days)` | Trả màu: đỏ (<0), vàng (≤3), xanh (>3), xám (null) |
| `computeCaseStatus($transferDate, $slaDays)` | Tính due_date + status từ SLA |
| `getStatusLabel($days)` | Trả text: "Quá hạn X ngày", "Còn X ngày"... |

---

## 🚨 Lưu Ý Bảo Trì

1. **Biến `$room` trong `layout_top.php`**: Dùng `$sidebarRoom` — KHÔNG ĐỔI LẠI thành `$room`
2. **Import dùng exact matching**: KHÔNG dùng `strpos()` hay fuzzy matching cho tên phòng
3. **SLA/Thời hạn**: Khi chuyển phòng, `due_date` tự tính lại từ SLA phòng mới
4. **Mật khẩu data_manage**: Đổi ở `define('DATA_PASSWORD', 'admin@2026')` trong file
5. **Google Drive token**: File `gdrive_token.json` chứa refresh_token, không xóa
6. **Flash messages**: Dùng `$_SESSION['flash_message']` + optional `$_SESSION['flash_type']` (`error`/`warning`)
7. **Violations tự động**: Tạo ở `customer.php` khi chuyển phòng sai quy trình
8. **Worklog config**: Lưu dạng JSON trong `rooms.worklog_config`, cấu hình qua `room_config.php`

---

## 🔮 Roadmap: Đồng Bộ Với Phần Mềm Tài Chính

> Phần mềm Tài Chính nằm ở **hosting riêng**, DB riêng (`toolthien_taichinh`).
> Giải pháp: dùng **API + API Key** để trao đổi dữ liệu qua HTTPS.

### Kiến trúc đồng bộ

```
┌─────────────────────┐       HTTPS + API Key        ┌─────────────────────┐
│   Tài Chính          │  ◄─────────────────────────► │   Quản Lý CV         │
│   (Hosting A)        │                              │   (Hosting B)        │
│                      │                              │                      │
│  api_sync.php        │  → JSON: khách cần thu lãi   │  sync_from_tc.php    │
│  (expose data)       │  ← JSON: kết quả thu hồi     │  (pull + push)       │
└─────────────────────┘                              └─────────────────────┘
```

### Dữ liệu mapping

| Tài Chính | Quản Lý CV | Ghi chú |
|-----------|------------|---------|
| `customers.name` | `customers.name` | Match bằng tên + CCCD |
| `customers.phone` | `customers.phone` | |
| `customers.cmnd` | `customers.cccd` | |
| `loans.next_payment_date` | `customers.due_date` | Ngày đóng lãi → thời hạn |
| `loans.amount` | `work_logs.amount` | Số tiền cần thu |
| `loans.loan_code` | `customers.notes` | Mã hợp đồng vay |

### Tính năng dự kiến

#### ✅ Ưu tiên cao (làm trước)

1. ✅ **Sync ngày đóng lãi → Phòng tín dụng** (Hoàn thành 20/03/2026)
   - **TC**: `api_sync.php` (standalone) — endpoint: `https://taichinh.motmot.vip/api_sync.php`
     - Actions: `due_today`, `due_range`, `all_active`, `summary`
     - Params: `?action=...&key=SYNC_2026_CV_TC_secret`
   - **CV**: `sync_from_tc.php` — giao diện sync thủ công
   - **CV**: `cron_sync_tc.php` — cron job tự động 6:00 sáng
     - Hỗ trợ `?from=...&to=...` cho test thủ công
     - Bảo mật bằng `cron_key=CRON_SECRET_2026`
     - Ghi log vào `sync_log.txt`
   - **Logic sync**:
     - Tìm khách theo SĐT → tên (bất kỳ phòng nào, status=active)
     - Có → chuyển về Tín dụng 1 + ghi `tc_info` + transfer_log
     - Không có → tạo mới vào Tín dụng 1 (description trống, tc_info có data)
     - Chống trùng: check tên trong phòng đích trước khi tạo
   - **Dữ liệu tách biệt**:
     - `tc_info` (cột mới) — read-only, tự cập nhật khi sync
     - `description` — KHÔNG bị động, do user tự ghi
   - **Due date**: tính theo `sla_days` của phòng đích, không dùng ngày TC
   - **Company tag**: lấy `store_name` từ TC

2. ✅ **Auto-transfer khi đóng lãi** (Hoàn thành 26/03/2026)
   - **File**: `cv_helper.php` — hàm `cvAutoTransferOnPayment()`
   - Gọi từ: `contract_process_payment.php`, `contract_view.php` (pay_custom, pay_multischedule, close_contract)
   - **Logic kiểm tra nợ lãi**:
     - Sau khi ghi payment → check `next_payment_date`
     - `next_payment_date > hôm nay` → đã đóng đủ kỳ → auto chuyển "Đã hoàn thành"
     - `next_payment_date ≤ hôm nay` → còn nợ kỳ khác → giữ nguyên phòng hiện tại
     - `status = closed` (tất toán) → luôn chuyển "Đã hoàn thành"
   - **Nút thủ công**: "✅ Hoàn thành" trên `customer.php` (chỉ Admin)
     - Force chuyển sang "Đã hoàn thành" dù còn nợ (công ty đồng ý)
     - Log: "Thủ công: Đánh dấu hoàn thành"

3. ✅ **CRON auto-assign `cv_company_tag`** (Hoàn thành 25/03/2026)
   - `api_auto_assign.php` giờ JOIN `stores` table → ghi `cv_company_tag` = tên cửa hàng
   - Backfill: tự cập nhật tag cho loans đã ở CV nhưng thiếu tag

4. ✅ **Gộp phân quyền TC + CV** (Hoàn thành 25/03/2026)
   - `user_permissions.php` thêm section "Công Việc (CV)" với dropdown: Nhân viên / Admin
   - Lưu `cv_role` cùng lúc với quyền TC
   - Bỏ trang login riêng CV → dùng session TC

#### 🔄 Ưu tiên trung bình (làm sau)

3. **Sync kết quả thu hồi ngược lại TC**
4. **Dashboard tổng hợp tài chính**
5. **Telegram thông báo từ CV**

#### 📊 Ưu tiên thấp

6. **Báo cáo hiệu suất nhân viên**
7. **Webhook 2 chiều (real-time)**

### Yêu cầu UI mới (chờ xác nhận)

> Đang trao đổi với đồng nghiệp, chưa triển khai.

1. **2 nút khi click khách**: "Làm việc" (mở CV) + "Kế toán" (mở TC)
2. **Xuất Excel**: export danh sách khách ra `.xlsx`
3. **View Bảng + Bộ lọc**: hiển thị khách dạng table, filter theo cột
4. **Setup giao diện**: admin cấu hình thời hạn, phòng, nội dung qua UI

### Cập nhật giao diện (20/03/2026)

- **Text sáng hơn**: `--text-secondary: #c4c4c4`, `--text-muted: #999`
- **Room stat text**: `#ddd` (gần trắng)
- **Lưu trữ**: bỏ `opacity:0.7` cho card
- **"Tổng Case"** → **"Tổng Khách"**
- **Cache-busting CSS**: `style.css?v=<?= filemtime() ?>` trong `layout_top.php` + `login.php`

### Cập nhật trang khách hàng (22/03/2026)

- **"MÔ TẢ KHÁCH HÀNG"** → **"THÔNG TIN KHÁCH HÀNG"**
- **"Lưu ý"** chuyển từ tab → **hiện luôn phía trên** THÔNG TIN KHÁCH HÀNG (viền vàng)
- **Tab mặc định**: `comments` → `worklog`
- **Tab "Nhật ký"** → **"Nội dung công việc"**
- **Bảng nhật ký**:
  - "Hành động" → **"Việc đã làm"**
  - "Nội dung" → **"Ghi chú"**
  - "Số tiền" tách thành **"Lãi đã trả"** + **"Gốc đã trả"**
- **DB**: thêm cột `amount_principal DECIMAL(15,2)` vào `work_logs` (auto-migrate trong `config.php`)
- **Thứ tự layout**: 📌 Lưu ý → 📋 Thông tin KH → 🔗 TC Info → Tabs

### Ghi chú kỹ thuật

- **API Key**: `SYNC_2026_CV_TC_secret` — truyền qua `?key=`
- **Cron Key**: `CRON_SECRET_2026` — bảo mật URL cron
- **Cron cPanel**: `0 6 * * * curl -s "https://congviec.motmot.vip/cron_sync_tc.php?cron_key=CRON_SECRET_2026"`
- **Matching khách**: SĐT (ưu tiên) → tên (bất kỳ phòng, status=active) → tên trong phòng đích
- **DB CV cột mới**: `tc_info TEXT` (sau `description`) — auto-migrate trong cron
- **DB TC**: `taichinh_fdsew` — bảng `customers` không có cột `cmnd`

---

## GỘP TC + CV — ĐÃ HOÀN THÀNH (27/03/2026)

### Kiến trúc hiện tại

```
taichinh.motmot.vip/
├── config.php                   ← DB connection (taichinh_fdsew)
├── contract_view.php            ← Chi tiết HĐ, đóng lãi, đính kèm file (Drive)
├── contract_process_payment.php ← API thanh toán
├── cv_helper.php                ← Auto-transfer CV khi đóng lãi
├── customers.php                ← Danh sách khách TC
├── permissions_helper.php       ← Phân quyền TC
└── cong-viec/                   ← Module CV (dark theme)
    ├── config.php               ← GDRIVE constants, auto-migrate tables
    ├── google_drive.php         ← Google Drive API (OAuth2, no Composer)
    ├── gdrive_auth.php          ← Authorize Google Drive
    ├── customer.php             ← Chi tiết khách CV, upload file, ghi chú
    ├── room.php                 ← Phòng (Tín dụng 1, Đã hoàn thành...)
    ├── api_auto_assign.php      ← CRON hàng ngày
    └── gdrive_token.json        ← Token Google Drive (KHÔNG xóa)
```

### Luồng tự động TC ↔ CV

```
Chưa đến hạn (không ở CV)
        │
        ▼  CRON sáng: next_payment_date <= hôm nay
   ┌─────────────┐
   │ Tín dụng 1  │  ← Cần thu lãi
   └─────┬───────┘
         │
         ▼  Kế toán đóng lãi đủ → next_payment_date > hôm nay
   ┌──────────────┐
   │ Đã hoàn thành│  ← Đã đóng đủ kỳ
   └─────┬────────┘
         │
         ▼  CRON sáng: next_payment_date <= hôm nay (kỳ mới)
   ┌─────────────┐
   │ Tín dụng 1  │  ← Lại cần thu lãi
   └─────────────┘
```

| Sự kiện | Điều kiện | Kết quả |
|---|---|---|
| **CRON sáng** | `next_payment_date <= hôm nay` + chưa ở CV | → **Tín dụng 1** |
| **CRON sáng** | Đang ở "Đã hoàn thành" + `next_payment_date <= hôm nay` | → Quay lại **Tín dụng 1** |
| **Đóng lãi đúng/trễ hạn** | Đang ở Tín dụng 1 + `next_payment_date > hôm nay` | → **Đã hoàn thành** |
| **Đóng lãi sớm** | Chưa ở CV + `next_payment_date > hôm nay` | → Thêm vào **Đã hoàn thành** |
| **Đóng thiếu lãi** | `next_payment_date <= hôm nay` | → Giữ nguyên / CRON assign |
| **Nút ✅ thủ công** | Admin bấm | → Force **Đã hoàn thành** |
| **Tất toán HĐ** | `status = closed` | → **Đã hoàn thành** |

**Files liên quan:**
- `cv_helper.php` → `cvAutoTransferOnPayment()` — gọi SAU payment lưu DB
- `api_auto_assign.php` → CRON:
  ```bash
  0 7 * * * curl -s "https://taichinh.motmot.vip/cong-viec/api_auto_assign.php?key=cv_auto_assign_2024_secret"
  ```

### Google Drive Integration

**Config (cong-viec/config.php):**
```php
define('GDRIVE_CLIENT_ID', '*** xem trong config.php ***');
define('GDRIVE_CLIENT_SECRET', '*** xem trong config.php ***');
define('GDRIVE_FOLDER_ID', '*** xem trong config.php ***');
define('GDRIVE_TOKEN_FILE', __DIR__ . '/gdrive_token.json');
```

**Cách đổi tài khoản Google Drive:**
1. Xóa `cong-viec/gdrive_token.json`
2. Google Console → thêm email mới làm Test User (nếu Testing mode)
3. Vào `https://taichinh.motmot.vip/cong-viec/gdrive_auth.php` → Đăng nhập mới

**Upload đồng bộ TC + CV:**
- Cả 2 đều upload vào subfolder `"Tên khách #ID"` trên Drive
- Folder ID lưu trong `loans.cv_drive_folder_id`
- TC: `contract_view.php` tab Đính kèm → AJAX upload với progress bar
- CV: `customer.php` tab Files
- Database: `contract_attachments` (TC) + `cv_customer_files` (CV) — đều có `drive_file_id`, `drive_link`

### Date Calculation Fix (Off-by-one)

Sửa trong `contract_view.php` JS:
- `calculateFromDays()`: `start + days - 1`
- `calculateCustomInterest()`: `diff + 1`

### CV columns trên bảng loans

```sql
ALTER TABLE loans ADD cv_room_id INT DEFAULT NULL;
ALTER TABLE loans ADD cv_status VARCHAR(20) DEFAULT NULL;
ALTER TABLE loans ADD cv_due_date DATE DEFAULT NULL;
ALTER TABLE loans ADD cv_transfer_date DATE DEFAULT NULL;
ALTER TABLE loans ADD cv_assigned_to INT DEFAULT NULL;
ALTER TABLE loans ADD cv_notes TEXT DEFAULT NULL;
ALTER TABLE loans ADD cv_drive_folder_id VARCHAR(255) DEFAULT NULL;
ALTER TABLE loans ADD cv_company_tag VARCHAR(100) DEFAULT NULL;
ALTER TABLE users ADD cv_role VARCHAR(20) DEFAULT NULL;
```

### Cập nhật 28/03/2026

#### ✅ Thư viện & Hướng dẫn — Giao diện Folder (`library.php`)

- Danh mục hiển thị dạng **folder icon** giống Explorer (grid ngang)
- 4 folder có **màu riêng** + icon SVG gradient:
  - 🟡 Hướng dẫn (vàng) | 🔵 Tài liệu (xanh dương) | 🟢 Quy trình (xanh lá) | 🟣 Khác (tím)
- **Badge đỏ** góc phải hiện số bài viết
- Click folder → lọc bài viết theo danh mục, hiện dạng **accordion** (chỉ tiêu đề, click mở nội dung)
- Hover folder: nhấc lên + phóng to icon + đổ bóng
- Thêm bài viết tự chọn sẵn danh mục đang xem

#### ✅ Thêm tên khách vào nhật ký & chuyển phòng

- Tab **Nội dung công việc** (`customer.php`): thêm cột **🧑 Họ tên khách**
- Tab **Chuyển phòng** (`customer.php`): thêm cột **🧑 Họ tên khách**
- Phục vụ xuất file Excel có đầy đủ thông tin

#### ✅ Xuất Excel nhật ký & chuyển phòng

**5 điểm xuất Excel (CSV UTF-8 BOM):**

| Vị trí | File | Tên file CSV |
|--------|------|-------------|
| Tab Nhật ký (`customer.php`) | `customer.php` | `nhatky_TenKhach_YYYYMMDD.csv` |
| Tab Chuyển phòng (`customer.php`) | `customer.php` | `chuyenphong_TenKhach_YYYYMMDD.csv` |
| Tab Tổng hợp (`customer.php`) | `customer.php` | `tonghop_TenKhach_YYYYMMDD.csv` |
| Nhật ký LV tổng (`logs_all.php`) | `logs_all.php` | `nhatky_tong_YYYY-MM-DD.csv` |
| Chuyển phòng tổng (`logs_all.php`) | `logs_all.php` | `chuyenphong_tong_YYYY-MM-DD.csv` |

- Export qua `?export=csv` (customer) hoặc `?export=csv&export_type=worklog|transfer` (logs_all)
- Tất cả CSV có cột **"Họ tên khách"** + filter hiện tại được giữ nguyên

#### ✅ Phần tổng hợp — Thêm tiêu đề trước giá trị

Cột "Nội dung tổng hợp" (`customer.php` tab Timeline) giờ hiện format có label:
```
Phòng làm việc: Tín dụng 1; Việc đã làm: Nhắn tin; Kết quả: Đã trả lại; Lãi đã trả: 10.000đ
```

Áp dụng cho 3 loại:
- **Nhật ký làm việc**: Phòng làm việc / Việc đã làm / Kết quả / Lãi đã trả / Gốc đã trả / Ngày hẹn / Ghi chú
- **Chuyển phòng**: Từ phòng / Đến phòng / Người chuyển / Ghi chú
- **Kế toán nhập tiền**: Loại / Số tiền / Ghi chú

#### ✅ Modal nhật ký — Load cấu hình theo phòng

- **Trước**: Modal "Lưu nhật ký" chỉ load `worklog_config` của phòng hiện tại
- **Sau**: Load config tất cả phòng → pass JS map `allRoomConfigs[roomId]`
- Khi đổi phòng trong dropdown → JS `wlSwitchRoom(roomId)` rebuild lại:
  - Danh sách "Đã làm việc gì" theo config phòng được chọn
  - Reset kết quả, ngày hẹn, số tiền, ghi chú
  - Phòng chưa cấu hình → hiện "⚠️ Phòng chưa cấu hình nhật ký"

#### ✅ Ô nhập chi tiết cho hành động nhật ký (show_custom_input)

Mỗi hành động trong `worklog_config` giờ có thể bật **"Có ô nhập chi tiết"** — khi nhân viên chọn hành động đó, hiện thêm 1 ô text input với label tùy chỉnh (VD: "Công việc sáng tạo là gì").

**Cấu trúc worklog_config mới:**
```json
{
  "action": "Công việc sáng tạo chưa có trong phương án",
  "results": [],
  "show_custom_input": true,
  "custom_input_label": "Công việc sáng tạo là gì"
}
```

**Files đã sửa:**
- `room_config.php`: Thêm checkbox + ô nhập label trong UI cấu hình (nền cam nổi bật)
- `worklog_add.php`: Thêm section `#custom-input-section` giữa action và result, JS xử lý show/hide
- `customer.php`: Tương tự cho modal nhật ký, thêm `#wl-custom-input-group`

**Backend:** Giá trị custom input gửi qua `custom_detail`, merge vào `work_done`:
- Có custom_detail + có ghi chú → `"custom_detail; ghi chú"`
- Có custom_detail, không ghi chú → `"custom_detail"` (không lặp action name)
- Không custom_detail, có ghi chú → `"ghi chú"`
- Không có gì → `""` (rỗng, action_type/result_type lưu riêng)

#### ✅ Bỏ qua bước kết quả khi action không có results

Khi action **không có kết quả** nào (VD: "Nội dung khác", "Thực hiện phương án mềm"):
- **Trước**: Hiện section kết quả rỗng → bí, không bấm tiếp được
- **Sau**: Bỏ qua bước chọn kết quả → hiện thẳng **Ghi chú + Submit**

Áp dụng cho cả `worklog_add.php` và modal trong `customer.php`.

---

### 📊 Xuất Dữ Liệu XLSX (28/03/2026)

Chuyển toàn bộ export từ CSV sang **Excel (.xlsx)** bằng thư viện `SimpleXLSXGen.php`.

**Files đã sửa:**
- `customer.php`: 3 export (Timeline, Worklog, Transfers) → XLSX
- `logs_all.php`: 2 export (Worklog, Transfer) → XLSX
- `data_manage.php`: 1 export (Customer list) → XLSX

**Thư viện:** `SimpleXLSXGen.php` (nằm ở thư mục gốc TC)

---

### 🔒 An Toàn Dữ Liệu (28/03/2026)

**Bỏ xóa bảng `customers` khỏi "Xóa tất cả":**
- `data_manage.php`: Chức năng "Xóa tất cả" chỉ xóa `cv_work_logs` + `cv_transfer_logs`
- **KHÔNG xóa** bảng `customers` — bảng này dùng chung với hệ thống TC
- Đã ẩn mục "Dữ liệu" khỏi menu sidebar (không cần import nữa sau khi đồng bộ TC)

---

### 🔗 URL Thân Thiện (28/03/2026)

Cấu hình trong `.htaccess` (thư mục gốc), sử dụng `RewriteCond` vì `cong-viec` là thư mục thật.

| URL thân thiện | File PHP |
|---|---|
| `/cong-viec/tong-quan` | `cong-viec/index.php` |
| `/cong-viec/khach-hang` | `cong-viec/customers.php` |
| `/cong-viec/khach-hang/{id}` | `cong-viec/customer.php?id={id}` |
| `/cong-viec/nhat-ky` | `cong-viec/worklog_add.php` |
| `/cong-viec/nhat-ky-tong` | `cong-viec/logs_all.php` |
| `/cong-viec/thu-vien` | `cong-viec/library.php` |
| `/cong-viec/phong/{id}` | `cong-viec/room.php?id={id}` |
| `/cong-viec/vi-pham` | `cong-viec/violations.php` |
| `/cong-viec/nhan-vien` | `cong-viec/users.php` |
| `/cong-viec/quan-ly-phong` | `cong-viec/rooms_manage.php` |
| `/cong-viec/cau-hinh-phong/{id}` | `cong-viec/room_config.php?id={id}` |

**Lưu ý:**
- CSS, JS, fetch API trong `layout_top.php` dùng đường dẫn tuyệt đối (`/cong-viec/...`)
- LiteSpeed cần restart sau khi sửa `.htaccess`
- Phòng dùng ID (chưa có slug): `/cong-viec/phong/2`

---

### 💾 Backup & Version Control (28/03/2026)

**Git backup:** `backup_git.bat` — chạy 1 click để commit + push lên GitHub
- Repo: `phuongpvp/tai-chinh`
- File `.bat` chỉ dùng ký tự ASCII (tránh lỗi CMD encoding)

### Tính năng chưa làm

- **Cổ đông / Chia cổ tức** (`/co-dong/chia-co-tuc-nhieu`) — chờ bàn bạc
### 🤖 Module AI Phân Tích Khách Hàng (29/03/2026)

Tính năng tự động tổng hợp dữ liệu (nhật ký, giao dịch) và đưa ra đánh giá rủi ro/đề xuất hành động.

#### 🛠️ Kiến trúc Integration:
- **Provider**: [OpenRouter.ai](https://openrouter.ai/) (Dùng Proxy để lách luật giới hạn vùng của Google Gemini).
- **Model**: `openai/gpt-oss-120b:free` hoặc `openrouter/free`.
- **Lưu trữ**: Bảng `system_settings` trong Database.
  - `gemini_api_key`: Lưu API Key của OpenRouter (sk-or-...).
  - `gemini_prompt`: Lưu mẫu câu lệnh (prompt) đánh giá.

#### 🔧 Cách cấu hình:
1. **Lấy API Key**: Truy cập [OpenRouter Keys](https://openrouter.ai/keys) → Tạo key mới.
2. **Cài đặt trong hệ thống**: Vào giao diện **Cài đặt AI** → Dán Key → Bấm **Lưu**.
3. **Tùy chỉnh Prompt**: Có thể sửa trực tiếp trong UI trang Cài đặt (đã có nút Reset mặc định).

#### ⚠️ Lưu ý quan trọng (Tránh lỗi API):
Nếu gặp lỗi "No endpoints matching guardrail restrictions", cần cấu hình tài khoản OpenRouter:
- Vào [OpenRouter Privacy Settings](https://openrouter.ai/settings/privacy).
- **Tắt (OFF)** nút: **"Always Enforce Allowed"**.
- **Bật (ON)** nút: **"Enable free endpoints that may train on inputs"**.
- **Tắt (OFF)** nút: **"ZDR Endpoints Only"**.

#### 📝 Logic xử lý (ai_analyze.php):
- Tổng hợp 3 nguồn data: Nhật ký làm việc, Lịch sử chuyển phòng, Lịch sử giao dịch TC.
- Gửi Prompt + Data sang OpenRouter theo định dạng OpenAI.
- Hiển thị kết quả dạng Markdown/Emoji trong trang chi tiết khách hàng.
- **Tự động nhận diện khách Tốt**: Ưu tiên đề xuất "Tri ân/Duy trì" thay vì "Nhắc nợ".

---

### 🔔 Sửa lỗi Nhắc nhở Telegram & Cấu hình GDrive Mới (31/03/2026)

#### 1. Xử lý Ghi nhận Cấu hình Nhắc nhở Telegram
- **Lỗi 1 (Cập nhật ngày hẹn/ẩn báo thành công ảo)**: Do truy vấn sai lệnh `WHERE` kẹt điều kiện `store_id` không khớp với Session. Đã lược bỏ `store_id` trong vòng kiểm tra và bổ sung bắt lỗi theo `rowCount()` thực tế tại file `contract_update_appointment.php`.
- **Lỗi 2 (Tích "Ẩn" không phản hồi)**: Xung đột cấu hình URL Friendly ảo (`/cai-dat/telegram`). Trình duyệt tải sai folder. Sửa lại tất cả path JS tại `telegram_manager.php` thành `/js/hide_reminder.js` và hàm fetch bên trong thành `/contract_toggle_hidden.php` (Đường dẫn tuyệt đối từ root).

#### 2. Cài đặt Dữ liệu Upload sang Google Drive Mới
- Do nhu cầu đổi account Google lưu trữ file dùng chung giữa **Phần mềm TC (Tab Đính kèm)** và **Phần mềm CV (Tiện ích Upload file)**. Hệ thống đã thay API mới trong `cong-viec/config.php`:
  - Mã định danh: `GDRIVE_CLIENT_ID`, `GDRIVE_CLIENT_SECRET`, `GDRIVE_FOLDER_ID` mới.
- Xóa sạch file cache `cong-viec/gdrive_token.json` để tiến hành đăng nhập lại qua endpoint `gdrive_auth.php`.
- Tài khoản xác thực trên Google Cloud đã được đẩy sang trạng thái **In Production**. Việc này giúp giữ kết nối mãi mãi cho Website, vượt qua mức giới hạn hết hạn Token 7 ngày của app Test.

---

### 🗄️ Hệ Thống Tự Động Sao Lưu Database (01/04/2026)

Tính năng tự động sao lưu toàn bộ Cơ sở dữ liệu (Database) của dự án định kỳ mỗi ngày 1 lần. 

#### 1. Kịch bản sao lưu (`cron_backup.php`)
- **Nền tảng**: Sử dụng thuần PHP kết hợp thư viện PDO.
- **Độ tương thích**: Tương thích 100% trên mọi nền tảng Shared Hosting, Cpanel, DirectAdmin (ngay cả khi máy chủ đã khóa hàm bảo mật `exec()` hoặc không cho dùng lệnh `mysqldump`). Tự động trích xuất toàn bộ cấu trúc bảng (CREATE TABLE) và bulk-insert dữ liệu an toàn.
- **Cơ chế hoạt động**:
  - Đọc trực tiếp cấu hình cơ sở dữ liệu từ file `config.php`.
  - Xuất ra file `.sql` sạch, đặt tên tự động theo cấu trúc `taichinh_fdsew_YYYY-MM-DD_HH-mm-ss.sql`.
  - Toàn bộ được cất giữ vào thư mục riêng biệt `/backups` nằm trên server.
  - Tự động sinh file `.htaccess` chặn mọi truy cập tải xuống qua HTTP từ trình duyệt của tin tặc.
  - **Dọn rác tự động**: Tự động rà soát, nếu phát hiện file backup có tuổi thọ quá 7 ngày, nó sẽ tự xóa bỏ, giúp máy chủ Hosting không bao giờ bị đầy ổ cứng.

#### 2. Cấu hình tự động (Cron Job)
Hệ thống sử dụng tiến trình nền (Cron) cấu hình trên bảng điều khiển Hosting.
- **Khung giờ chạy**: `03:00` sáng mỗi ngày (`0 3 * * *`). Lựa chọn khung giờ thấp điểm (đêm khuya) để tránh gây ảnh hưởng tới quá trình nhân viên thao tác làm việc trên phần mềm ban ngày.
- **Lệnh thực thi âm thầm (Silent)**:
  ```bash
  curl -s "https://taichinh.motmot.vip/cron_backup.php" > /dev/null
  ```
