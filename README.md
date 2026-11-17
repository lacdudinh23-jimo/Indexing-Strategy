# MySQL Indexing Strategy – Benchmark & Visualizer

Ứng dụng web trực quan hóa và đo lường (benchmark) hiệu năng truy vấn trên MySQL (InnoDB engine) với 3 chiến lược chỉ mục: **B-Tree Index**, **Hash Index**, và **Full-Text Index**.

---

## 1. Yêu cầu hệ thống

- **PHP**: Phiên bản 7.4 trở lên (khuyến nghị PHP 8.x) đã bật extension `mysqli`.
- **MySQL / MariaDB**: Phiên bản 5.7+ hoặc 8.0+ đang chạy (mặc định InnoDB).
- **Trình duyệt web**: Chrome, Firefox, Edge,...

---

## 2. Cấu hình cơ sở dữ liệu

Mở file [`db.php`](db.php) và kiểm tra/chỉnh sửa thông số kết nối MySQL nếu cần:

```php
const DB_HOST = '127.0.0.1', DB_USER = 'root', DB_PASS = '', DB_NAME = 'index_demo';
```

> **Lưu ý:** Database `index_demo` và bảng `books` sẽ được hệ thống tự động khởi tạo khi kết nối thành công, bạn không cần tạo trước thủ công.

---

## 3. Cách chạy dự án

### Cách 1: Sử dụng PHP Built-in Server (Nhanh nhất)

1. Đảm bảo dịch vụ MySQL đang chạy.
2. Mở terminal tại thư mục dự án và chạy:
   ```bash
   php -S localhost:8000
   ```
3. Mở trình duyệt và truy cập: [http://localhost:8000](http://localhost:8000)

---

### Cách 2: Sử dụng XAMPP / Laragon

1. Khởi động **Apache** và **MySQL** trong XAMPP Control Panel.
2. Đặt toàn bộ thư mục `Indexing-Strategy` vào thư mục web root:
   - **XAMPP Linux**: `/opt/lampp/htdocs/Indexing-Strategy`
   - **XAMPP Windows**: `C:\xampp\htdocs\Indexing-Strategy`
3. Mở trình duyệt và truy cập: [http://localhost/Indexing-Strategy](http://localhost/Indexing-Strategy)

---

## 4. Hướng dẫn sử dụng & 3 Chiến lược Index hỗ trợ

1. **Sinh dữ liệu thử nghiệm:**
   - Chọn số lượng bản ghi (100.000, 500.000 hoặc 1.000.000 dòng).
   - Bấm **Tạo lại dữ liệu mẫu**.

2. **Chọn thuật toán & Kịch bản thử nghiệm:**
   - **B-tree Index:**
     - *Tìm theo ISBN* (`=` chính xác).
     - *Tìm theo khoảng giá* (`BETWEEN`).
     - *20 sách đầu theo tên* (`ORDER BY + LIMIT`).
   - **Hash Index:**
     - *Tìm theo ISBN* (`=` so sánh bằng với HASH).
     - *Tìm theo năm xuất bản* (`=` với HASH).
   - **Full-Text Index:**
     - *Tìm từ đơn trong tên sách* (`MATCH(...) AGAINST(...)` so sánh trực tiếp với quét `LIKE '%từ%'`).
     - *Tìm cụm từ chính xác* (Tìm cụm từ bằng `MATCH` vs `LIKE '%cụm từ%'`).

3. **Đọc kết quả so sánh:**
   - **Tốc độ truy vấn:** So sánh thời gian thực thi giữa **Không có Index** và **Có Index**.
   - **EXPLAIN Plan:** Xem chi tiết kế hoạch thực thi của MySQL (`ALL` vs `ref`, `range`, `fulltext`).
   - **Chi phí (Trade-offs):** Thời gian build index và dung lượng đĩa chiếm dụng.

---

## 5. Cấu trúc thư mục

```text
├── index.html     # Giao diện người dùng (Dashboard & Visualizer)
├── style.css      # Giao diện CSS hiện đại (Dark/Modern theme)
├── script.js      # Xử lý tương tác frontend, gọi API và biểu đồ
├── api.php        # Backend API xử lý benchmark, EXPLAIN và sinh dữ liệu
├── db.php         # Quản lý kết nối cơ sở dữ liệu MySQL
├── config.json    # Cấu hình chunk size và các mốc dữ liệu
└── README.md      # Tài liệu hướng dẫn sử dụng
```
