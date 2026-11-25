// PHẦN 4 (SARGable Query) và PHẦN 9 (Multi-Range Read) – dữ liệu lý thuyết hiển thị trên giao diện.
// Nạp SAU algorithms-data.js (xem index.html) để thêm 2 mục vào ALGORITHMS mà không sửa file chung.

ALGORITHMS.sargable = {
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
};

ALGORITHMS.mrr = {
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
};
