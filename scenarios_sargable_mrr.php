<?php
/**
 * PHẦN 4 (SARGable Query) và PHẦN 9 (Multi-Range Read) – khai báo kịch bản.
 * File riêng, được nạp từ api.php sau scenarios.php để không đụng code của các bạn khác.
 *
 * Hai kiểu 'mode' mới (xử lý trong bench_special.php):
 *  - query_compare : index tồn tại ở CẢ HAI phía, so sánh câu SQL "bọc cột" vs câu SQL viết lại SARGable.
 *  - switch_compare: index tồn tại ở CẢ HAI phía, cùng một câu SQL, so sánh MRR tắt vs MRR bật.
 */

$ALGOS['sargable'] = [
  'label' => 'SARGable Query',
  'scenarios' => [
    'sarg_left_isbn' => [
      'name'       => 'Hàm LEFT() bọc cột ISBN  vs  LIKE tiền tố',
      'mode'       => 'query_compare',
      'index'      => 'idx_books_sarg_isbn',
      'col'        => 'isbn',
      'index_type' => 'USING BTREE',
      'labelA'     => 'Không SARGable: LEFT(isbn,4)',
      'labelB'     => 'SARGable: isbn LIKE \'xxxx%\'',
      'query_no_index' => function ($n) {
        $p = substr(isbnOf(random_int(1, $n)), 0, 4);
        return "SELECT SQL_NO_CACHE id,title,author,price FROM books WHERE LEFT(isbn,4)='$p'";
      },
      'query_with_index' => function ($n, $sqlA) {
        preg_match("/LEFT\(isbn,4\)='(\d{4})'/", $sqlA, $m);
        $p = $m[1] ?? '0000';
        return "SELECT SQL_NO_CACHE id,title,author,price FROM books WHERE isbn LIKE '$p%'";
      },
    ],
    'sarg_abs_price' => [
      'name'       => 'Hàm ABS() bọc cột giá  vs  BETWEEN',
      'mode'       => 'query_compare',
      'index'      => 'idx_books_sarg_price',
      'col'        => 'price',
      'index_type' => 'USING BTREE',
      'labelA'     => 'Không SARGable: ABS(price - c)',
      'labelB'     => 'SARGable: price BETWEEN',
      'query_no_index' => function ($n) {
        $c = random_int(20000, 480000);
        return "SELECT SQL_NO_CACHE id,title,price FROM books WHERE ABS(price - $c) <= 250";
      },
      'query_with_index' => function ($n, $sqlA) {
        preg_match('/ABS\(price - (\d+)\)/', $sqlA, $m);
        $c = (int)($m[1] ?? 100000);
        return 'SELECT SQL_NO_CACHE id,title,price FROM books WHERE price BETWEEN ' . ($c - 250) . ' AND ' . ($c + 250);
      },
    ],
    'sarg_cast_year' => [
      'name'       => 'CAST(year AS CHAR) bọc cột năm  vs  so sánh trực tiếp',
      'mode'       => 'query_compare',
      'index'      => 'idx_books_sarg_year',
      'col'        => 'year',
      'index_type' => 'USING BTREE',
      'labelA'     => 'Không SARGable: CAST(year AS CHAR)',
      'labelB'     => 'SARGable: year = 1999',
      'query_no_index' => function ($n) {
        $y = random_int(1950, 2025);
        return "SELECT SQL_NO_CACHE id,title,author FROM books WHERE CAST(year AS CHAR) = '$y'";
      },
      'query_with_index' => function ($n, $sqlA) {
        preg_match("/= '(\d{4})'/", $sqlA, $m);
        $y = (int)($m[1] ?? 2000);
        return "SELECT SQL_NO_CACHE id,title,author FROM books WHERE year = $y";
      },
    ],
  ],
];

/**
 * Tạo câu truy vấn khoảng ngẫu nhiên, độ rộng = $frac của miền giá trị.
 * Mỗi lần gọi ra một khoảng MỚI để hai phía đo đều phải đọc trang chưa nằm trong cache.
 */
function mrrPriceRange($frac)
{
  return function ($n) use ($frac) {
    $min = 10000; $max = 500000;
    $w = (int)round(($max - $min) * $frac);
    $a = random_int($min, $max - $w);
    return "SELECT SQL_NO_CACHE id,title,author,price FROM books WHERE price BETWEEN $a AND " . ($a + $w);
  };
}

$ALGOS['mrr'] = [
  'label' => 'Multi-Range Read (MRR)',
  'scenarios' => [
    'mrr_price_1' => [
      'name'       => 'Quét khoảng giá ~1% bảng (secondary index → tra bảng gốc)',
      'mode'       => 'switch_compare',
      'index'      => 'idx_books_mrr_price',
      'col'        => 'price',
      'index_type' => 'USING BTREE',
      'labelA'     => 'MRR tắt (mrr=off)',
      'labelB'     => 'MRR bật (mrr=on)',
      'query'      => mrrPriceRange(0.01),
    ],
    'mrr_price_5' => [
      'name'       => 'Quét khoảng giá ~5% bảng (nhiều dòng cần tra hơn)',
      'mode'       => 'switch_compare',
      'index'      => 'idx_books_mrr_price',
      'col'        => 'price',
      'index_type' => 'USING BTREE',
      'labelA'     => 'MRR tắt (mrr=off)',
      'labelB'     => 'MRR bật (mrr=on)',
      'query'      => mrrPriceRange(0.05),
    ],
    'mrr_year' => [
      'name'       => 'Quét khoảng năm xuất bản (2 năm liên tiếp)',
      'mode'       => 'switch_compare',
      'index'      => 'idx_books_mrr_year',
      'col'        => 'year',
      'index_type' => 'USING BTREE',
      'labelA'     => 'MRR tắt (mrr=off)',
      'labelB'     => 'MRR bật (mrr=on)',
      'query'      => function ($n) {
        $y = random_int(1950, 2024);
        return "SELECT SQL_NO_CACHE id,title,author,year FROM books WHERE year BETWEEN $y AND " . ($y + 1);
      },
    ],
  ],
];
