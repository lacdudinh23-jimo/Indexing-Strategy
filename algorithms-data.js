// Dữ liệu kiến thức và đặc tính của 4 thuật toán chỉ mục (B-tree, Hash, Full-Text, R-tree)
// File này lưu trữ dữ liệu tĩnh, giúp script.js gọn gàng và chỉ tập trung vào xử lý logic / UI.

const ALGORITHMS = {
  btree: {
    name: 'B-tree Index',
    badge: 'Đang hoạt động',
    ready: true,
    summary: '<strong>B-Tree Index</strong> tổ chức dữ liệu thành cây cân bằng, các khoá được giữ theo thứ tự. Tốc độ tìm kiếm giảm từ <code>O(n)</code> (quét tuần tự) xuống <code>O(log n)</code>.',
    pros: [
      'Tìm kiếm chính xác (<code>=</code>) và tìm khoảng (<code>BETWEEN</code>, <code>&gt;</code>, <code>&lt;</code>).',
      'Tối ưu sắp xếp (<code>ORDER BY</code>) và phân trang (<code>LIMIT</code>).',
      'Hỗ trợ tìm tiền tố chuỗi (<code>LIKE \'abc%\'</code>).'
    ],
    cons: [
      'Làm chậm thao tác ghi (<code>INSERT</code>, <code>UPDATE</code>, <code>DELETE</code> do phải cân bằng lại cây).',
      'Tốn dung lượng ổ đĩa và RAM (Buffer Pool) để duy trì cây chỉ mục.',
      'Không hỗ trợ tìm chuỗi chứa từ bất kỳ (<code>LIKE \'%abc\'</code>).'
    ]
  },
  hash: {
    name: 'Hash Index',
    badge: 'Đang hoạt động',
    ready: true,
    summary: '<strong>Hash Index</strong> sử dụng bảng băm (Hash Table) để ánh xạ khoá tìm kiếm trực tiếp vào địa chỉ bản ghi, đạt độ phức tạp lý tưởng <code>O(1)</code> cho phép so sánh bằng.',
    pros: [
      'Tốc độ cực nhanh đối với phép so sánh bằng chính xác (<code>=</code> hoặc <code>&lt;=&gt;</code>).',
      'Rất phù hợp cho các khoá định danh duy nhất (ISBN, Mã sản phẩm, ID).'
    ],
    cons: [
      'Hoàn toàn không hỗ trợ tìm kiếm khoảng (<code>BETWEEN</code>, <code>&lt;</code>, <code>&gt;</code>).',
      'Không hỗ trợ sắp xếp kết quả (<code>ORDER BY</code>).',
      'Dễ bị suy giảm hiệu năng nếu xảy ra xung đột băm (Hash collision).'
    ]
  },
  fulltext: {
    name: 'Full-Text Index',
    badge: 'Đang hoạt động',
    ready: true,
    summary: '<strong>Full-Text Index</strong> phân tách văn bản thành các từ tố (tokens) và lập danh mục chỉ mục đảo (Inverted Index), cho phép tìm kiếm từ ngữ tự nhiên cực nhanh thay vì quét chậm bằng <code>LIKE \'%...%\'</code>.',
    pros: [
      'Tìm kiếm từ đơn hoặc cụm từ tức thì bằng cú pháp <code>MATCH(...) AGAINST(...)</code>.',
      'Tốc độ vượt trội (nhanh hơn hàng chục đến hàng trăm lần) so với quét tuần tự <code>LIKE \'%từ%\'</code>.',
      'Hỗ trợ tìm kiếm theo toán tử Boolean (<code>+từ_bắt_buộc</code>, <code>-từ_loại_trừ</code>, <code>"cụm từ"</code>).'
    ],
    cons: [
      'Chỉ áp dụng cho các cột kiểu chuỗi văn bản (<code>VARCHAR</code>, <code>TEXT</code>).',
      'Tốn tài nguyên tạo và cập nhật danh mục từ tố đảo; bỏ qua các từ dừng (stopwords).'
    ]
  },
  rtree: {
    name: 'R-tree (Spatial Index)',
    badge: 'Đang hoạt động',
    ready: true,
    summary: '<strong>R-Tree (Spatial Index)</strong> tổ chức dữ liệu không gian nhiều chiều theo các hình chữ nhật bao tối thiểu (MBR - Minimum Bounding Rectangle). Cực kỳ tối ưu khi tìm kiếm vị trí tọa độ địa lý (GIS).',
    pros: [
      'Cực kỳ tối ưu cho các phép tính toán hình học: <code>ST_Contains()</code>, <code>ST_Within()</code>, <code>ST_Intersects()</code>.',
      'Loại bỏ nhanh chóng các khu vực không liên quan nhờ cấu trúc cây phân nhánh MBR.',
      'Chuẩn chỉ mục không gian quốc tế được tích hợp sẵn trong MySQL InnoDB.'
    ],
    cons: [
      'Chỉ áp dụng cho các kiểu dữ liệu không gian (<code>POINT</code>, <code>POLYGON</code>, <code>GEOMETRY</code>) và cột bắt buộc phải có thuộc tính <code>NOT NULL</code>.',
      'Chi phí tính toán tái cấu trúc cây khi chèn/sửa/xoá (INSERT/UPDATE) dữ liệu không gian tương đối cao.'
    ]
  }
};

