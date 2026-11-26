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
  },
  // ===== PHẦN 4 (SARGable Query) + PHẦN 9 (Multi-Range Read) =====
  sargable: {
    name: 'SARGable Query',
    badge: 'Đang hoạt động',
    ready: true,
    summary: '<strong>SARGable</strong> (Search ARGument ABLE) là câu điều kiện <code>WHERE</code> mà bộ tối ưu có thể dùng trực tiếp B-Tree để tìm. Khi bọc cột bằng hàm hoặc phép tính (<code>YEAR(col)</code>, <code>LEFT(col,4)</code>, <code>col*2</code>), giá trị trong index không còn khớp với biểu thức cần so sánh nên MySQL phải đọc mọi dòng (<code>O(n)</code>). Viết lại để cột đứng một mình ở một vế sẽ đưa về <code>O(log n)</code>.',
    pros: [
      'Không tốn thêm index hay dung lượng, chỉ cần viết lại câu truy vấn.',
      'Tận dụng được index sẵn có cho <code>=</code>, <code>BETWEEN</code>, <code>&gt;</code>, <code>&lt;</code>, <code>LIKE \'abc%\'</code>.',
      'Chênh lệch có thể từ hàng chục đến hàng nghìn lần trên bảng lớn.'
    ],
    cons: [
      'Có index mà <code>EXPLAIN</code> vẫn báo <code>type=ALL</code>, rất dễ bỏ sót nếu không đọc kế hoạch thực thi.',
      'Một số trường hợp phải đổi cách viết điều kiện (ví dụ <code>LIKE \'%abc\'</code> không thể SARGable).',
      'Nếu bắt buộc phải bọc hàm, cần dùng functional index (MySQL 8.0.13+) hoặc generated column.'
    ]
  },
  mrr: {
    name: 'Multi-Range Read (MRR)',
    badge: 'Đang hoạt động',
    ready: true,
    summary: '<strong>Multi-Range Read</strong> là kỹ thuật tối ưu của MySQL khi quét khoảng trên <em>secondary index</em>: thay vì tra bảng gốc theo thứ tự khóa phụ (ngẫu nhiên theo khóa chính, gây <em>random I/O</em>), MySQL gom các khóa chính, <strong>sắp xếp theo thứ tự</strong> rồi mới đọc, biến truy cập ngẫu nhiên thành tuần tự. Bật bằng <code>optimizer_switch=\'mrr=on,mrr_cost_based=off\'</code>, <code>EXPLAIN</code> hiện <code>Using MRR</code>.',
    pros: [
      'Giảm số lần đọc trang đĩa lặp lại và biến random I/O thành sequential I/O.',
      'Hiệu quả rõ khi bảng lớn hơn bộ nhớ đệm (Buffer Pool) và khoảng quét trả về nhiều dòng.',
      'Không cần đổi câu truy vấn hay cấu trúc bảng, chỉ là cờ của bộ tối ưu.'
    ],
    cons: [
      'Tốn bộ nhớ và CPU để gom và sắp xếp khóa chính (<code>read_rnd_buffer_size</code>).',
      'Khi dữ liệu đã nằm trong RAM hoặc khoảng quét nhỏ, MRR có thể không nhanh hơn, thậm chí chậm hơn.',
      'Mặc định bộ tối ưu hiếm khi chọn MRR (<code>mrr_cost_based=on</code>); chỉ áp dụng cho truy vấn cần tra lại bảng gốc (không phải covering index).'
    ]
  },
  composite: {
    name: 'Composite Index',
    badge: 'Đang hoạt động',
    ready: true,
    summary: '<strong>Composite index</strong> lưu nhiều cột theo thứ tự; bộ tối ưu tận dụng tốt nhất tiền tố trái, thường đặt cột so sánh bằng trước cột khoảng.',
    pros: [
      'Một composite index có thể phục vụ nhiều điều kiện WHERE theo thứ tự tiền tố trái.',
      'Thứ tự cột phù hợp giúp giảm phạm vi cần quét và có thể phục vụ nhiều truy vấn liên quan.'
    ],
    cons: [
      'Thứ tự cột quan trọng; sau cột điều kiện khoảng đầu tiên, các cột sau thường không thu hẹp tiếp phạm vi quét.',
      'Index rộng làm tăng dung lượng và chi phí ghi; kết quả còn phụ thuộc dữ liệu và optimizer.'
    ]
  },
  icp: {
    name: 'Index Condition Pushdown (ICP)',
    badge: 'Đang hoạt động',
    ready: true,
    summary: '<strong>Index Condition Pushdown</strong> cho phép storage engine kiểm tra điều kiện trên các cột index trước khi đọc đầy đủ dòng trong bảng. Đây là tối ưu truy vấn, không phải một loại index riêng; demo dùng composite B-tree để so sánh.',
    pros: [
      'Có thể giảm số lần đọc dòng đầy đủ khi điều kiện trên index loại bỏ nhiều kết quả.',
      'Bật/tắt qua <code>optimizer_switch</code> và đối chiếu trong <code>EXPLAIN</code>.',
      'So sánh dùng cùng truy vấn và cùng index, chỉ thay đổi trạng thái ICP.',
      'Kịch bản LIKE dùng `(genre, title)`: điều kiện bằng trên genre tìm prefix, còn LIKE trên title được kiểm tra trong index.'
    ],
    cons: [
      'Chỉ hữu ích khi truy vấn cần đọc các cột ngoài index; covering index không cần tra lại dòng.',
      'LIKE với wildcard đầu (`%từ%`) không thể tự tìm kiếm bằng B-tree; ICP chỉ được minh họa ở đây nhờ điều kiện bằng trên genre và index `(genre, title)`.',
      'Optimizer và engine phải hỗ trợ, và EXPLAIN mới xác nhận ICP thực sự được áp dụng.',
      'Thời gian thực tế phụ thuộc dữ liệu, cache và phiên bản MySQL/MariaDB.'
    ]
  },
  cbo: {
    name: 'Cost-Based Optimizer & JOIN không Index',
    badge: 'Đang hoạt động',
    ready: true,
    summary: '<strong>Cost-Based Optimizer (CBO)</strong> dùng số dòng và số giá trị phân biệt (NDV) để ước lượng kết quả JOIN. Demo áp dụng quy hoạch động (DP) để chọn thứ tự nối ba bảng tạm không có index, rồi so sánh với thứ tự cố định. Cỡ mẫu được giới hạn để chạy ổn định trên MariaDB/XAMPP.',
    pros: [
      'DP xét các cách nhóm và nối bảng dựa trên số liệu thực tế thay vì chỉ theo thứ tự ban đầu.',
      'Hiển thị thống kê, chi phí ước lượng, thời gian chạy và EXPLAIN của hai thứ tự JOIN.',
      'Dùng STRAIGHT_JOIN để đảm bảo DB thực thi đúng thứ tự đang được so sánh.'
    ],
    cons: [
      'Ước lượng dựa trên NDV và giả định phân bố dữ liệu; kết quả có thể lệch khi dữ liệu thực tế bị skew.',
      'MariaDB 10.4 thường dùng nested-loop/block nested-loop khi JOIN không có index; EXPLAIN cho biết thuật toán thực tế.',
      'Đây là mô hình CBO minh họa, không thay thế optimizer nội bộ; Hash Join phụ thuộc phiên bản và engine DB.'
    ]
  }
};
