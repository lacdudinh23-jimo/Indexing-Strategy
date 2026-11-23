# MySQL Indexing Strategy – Benchmark & Visualizer

Ứng dụng web trực quan hóa và đo lường (benchmark) hiệu năng truy vấn trên MySQL (InnoDB engine) với 4 chiến lược chỉ mục:
1. **B-Tree Index** (Cây cân bằng)
2. **Hash Index** (Bảng băm)
3. **Full-Text Index** (Chỉ mục toàn văn)
4. **R-Tree Index** (Spatial Index - Chỉ mục không gian đa chiều)

---

## 1. Yêu cầu hệ thống

- **PHP**: Phiên bản 7.4 trở lên (khuyến nghị PHP 8.x) đã bật extension `mysqli`.
- **MySQL / MariaDB**: Phiên bản 5.7+ hoặc 8.0+ đang chạy (hỗ trợ InnoDB Spatial & Full-Text).
- **Trình duyệt web**: Chrome, Firefox, Edge,...

---

## 2. Cấu hình cơ sở dữ liệu

Mở file [`db.php`](db.php) và kiểm tra/chỉnh sửa thông số kết nối MySQL nếu cần:

```php
const DB_HOST = '127.0.0.1', DB_USER = 'root', DB_PASS = '', DB_NAME = 'index_demo';
```

> **Lưu ý:** Database `index_demo` và bảng `books` (kèm cột `location POINT NOT NULL`) sẽ được hệ thống tự động khởi tạo khi kết nối thành công, bạn không cần tạo trước thủ công.

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

## 4. Hướng dẫn sử dụng & 4 Chiến lược Index hỗ trợ

1. **Sinh dữ liệu thử nghiệm:**
   - Chọn số lượng bản ghi (100.000, 500.000 hoặc 1.000.000 dòng).
   - Bấm **Tạo lại dữ liệu mẫu** (tự động sinh kèm toạ độ địa lý GIS thực tế cho mỗi cuốn sách).

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
   - **R-tree (Spatial Index):**
     - *ST_Contains()*: Tìm sách nằm trong đa giác bao quanh khu vực.
     - *ST_Within()*: Tìm sách có toạ độ nằm trong ranh giới đa giác.
     - *ST_Intersects()*: Tìm sách có vị trí toạ độ giao cắt / trùng khớp với vùng không gian.

3. **Đọc kết quả so sánh:**
   - **Tốc độ truy vấn:** So sánh thời gian thực thi giữa **Không có Index** (Full Scan) và **Có Index** (B-tree / Hash / Fulltext / Spatial R-Tree).
   - **EXPLAIN Plan:** Xem chi tiết kế hoạch thực thi của MySQL (`ALL` vs `ref`, `range`, `fulltext`).
   - **Chi phí (Trade-offs):** Thời gian build index và dung lượng đĩa chiếm dụng.

---

## 5. Cấu trúc thư mục & Phân bổ trách nhiệm

```text
├── index.html          # Giao diện người dùng (Dashboard & Visualizer)
├── style.css           # Giao diện CSS hiện đại (Dark/Modern theme)
├── algorithms-data.js  # Dữ liệu tĩnh: Định nghĩa lý thuyết, ưu/nhược điểm 4 thuật toán
├── script.js           # Frontend Controller: Xử lý tương tác DOM, progress bar & gọi API
├── scenarios.php       # Cấu hình Backend: Danh mục kịch bản SQL & hàm sinh ISBN
├── api.php             # Backend Controller: Engine đo lường (bench/plan), sinh dữ liệu & routing
├── db.php              # Kết nối cơ sở dữ liệu MySQL (schema InnoDB & GIS POINT)
├── config.json         # Cấu hình chunk size và các mốc dữ liệu
└── README.md           # Tài liệu hướng dẫn sử dụng & kiến trúc luồng
```

---

## 6. Kiến trúc & Luồng hoạt động (Execution Flow)

Hệ thống được thiết kế theo mô hình tách biệt giữa **Dữ liệu tĩnh** và **Tầng thực thi**, giúp code gọn gàng và dễ nắm bắt luồng dữ liệu:

```mermaid
flowchart TD
    subgraph Frontend["Frontend (Client Browser)"]
        A[index.html] --> B[algorithms-data.js]
        A --> C[script.js]
        C -->|1. Khởi tạo| D[Load dropdown & Kiểm tra DB]
        C -->|2. Tạo dữ liệu| E[Batch Progress Bar 50k/lô]
        C -->|3. Chạy thử| F[Gửi Benchmark Request]
        G[Render visualizer & EXPLAIN] <-- C
    end

    subgraph Backend["Backend (PHP Controller)"]
        H[api.php] --> I[scenarios.php]
        H --> J[db.php]
        F -->|POST action=run| H
        E -->|POST action=gen| H
        D -->|POST action=meta| H
    end

    subgraph Database["MySQL (InnoDB)"]
        H -->|Thực thi SQL| K[(Database index_demo)]
    end
```

### Chi tiết các luồng chính:

1. **Luồng Khởi tạo (Init Flow):**
   - Trình duyệt tải `algorithms-data.js` (dữ liệu lý thuyết) rồi tới `script.js`.
   - `script.js` gọi `POST api.php?action=meta` để lấy số lượng bản ghi hiện có và danh sách kịch bản từ `scenarios.php`.

2. **Luồng Sinh dữ liệu (Data Generation Flow):**
   - Client gọi `POST api.php?action=reset` để làm sạch bảng.
   - Client chia nhỏ số dòng thành các chunk (50.000 dòng/lần) và lặp gọi `action=gen`.
   - Backend gom mỗi 1.000 dòng vào một transaction kèm toạ độ địa lý thực tế (`POINT`) rồi `COMMIT`.
   - Client cập nhật thanh tiến trình % trực tiếp trên giao diện.

3. **Luồng Đo lường Benchmark (5-Step Benchmark Flow):**
   - **Bước 1 (Full Scan):** Backend xoá index tương ứng (`DROP INDEX`), chạy hàm `bench()` lặp câu truy vấn mẫu tới khi đủ ~300ms để đo chính xác thời gian khi không có index.
   - **Bước 2 (Build Index):** Thực thi lệnh `CREATE INDEX` (B-tree, Hash, Full-text, Spatial) và bấm giờ vi sai (hrtime) để tính thời gian tạo index.
   - **Bước 3 (Index Scan):** Chạy lại câu truy vấn mẫu khi có index qua hàm `bench()`.
   - **Bước 4 (Dung lượng):** Truy vấn `information_schema.TABLES` để lấy `INDEX_LENGTH`.
   - **Bước 5 (Dọn dẹp):** `DROP INDEX` để trả lại trạng thái ban đầu cho các lần đo tiếp theo và gửi toàn bộ JSON về cho `renderBenchmarkResult()` trên giao diện.
