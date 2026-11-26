<?php
/**
 * Sinh mã ISBN giả lập ngẫu nhiên nhưng có tính xác định theo id
 */
function isbnOf($i)
{
  return str_pad((string)(($i * 48271) % 2147483647), 10, '0', STR_PAD_LEFT);
}

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

$ALGOS = [
  'btree' => [
    'label' => 'B-tree Index',
    'scenarios' => [
      'isbn' => [
        'name'       => 'Tìm theo ISBN (so sánh bằng =)',
        'index'      => 'idx_books_isbn',
        'col'        => 'isbn',
        'index_type' => 'USING BTREE',
        'query'      => fn($n) => "SELECT SQL_NO_CACHE id,title,author,price FROM books WHERE isbn='" . isbnOf(random_int(1, $n)) . "'"
      ],
      'price' => [
        'name'       => 'Tìm theo khoảng giá (BETWEEN)',
        'index'      => 'idx_books_price',
        'col'        => 'price',
        'index_type' => 'USING BTREE',
        'query'      => function ($n) {
          $a = random_int(10000, 490000);
          return "SELECT SQL_NO_CACHE id,title,price FROM books WHERE price BETWEEN $a AND " . ($a + 500);
        }
      ],
      'title' => [
        'name'       => '20 sách đầu theo tên (ORDER BY + LIMIT)',
        'index'      => 'idx_books_title',
        'col'        => 'title',
        'index_type' => 'USING BTREE',
        'query'      => fn($n) => "SELECT SQL_NO_CACHE id,title,author FROM books ORDER BY title LIMIT 20"
      ],
    ]
  ],
  'hash' => [
    'label' => 'Hash Index',
    'scenarios' => [
      'hash_isbn' => [
        'name'       => 'Tìm theo ISBN (so sánh bằng = với HASH)',
        'index'      => 'idx_books_hash_isbn',
        'col'        => 'isbn',
        'index_type' => 'USING HASH',
        'query'      => fn($n) => "SELECT SQL_NO_CACHE id,title,author,price FROM books WHERE isbn='" . isbnOf(random_int(1, $n)) . "'"
      ],
      'hash_year' => [
        'name'       => 'Tìm theo năm xuất bản (= với HASH)',
        'index'      => 'idx_books_hash_year',
        'col'        => 'year',
        'index_type' => 'USING HASH',
        'query'      => fn($n) => "SELECT SQL_NO_CACHE id,title,author,price FROM books WHERE year=" . random_int(1950, 2025)
      ],
    ]
  ],
  'fulltext' => [
    'label' => 'Full-Text Index',
    'scenarios' => [
      'ft_word' => [
        'name'       => 'Tìm từ đơn trong tên sách (MATCH AGAINST vs LIKE)',
        'index'      => 'idx_books_ft_title',
        'col'        => 'title',
        'index_type' => 'FULLTEXT',
        'query_no_index' => function ($n) {
          $words = ['Biển', 'Núi', 'Thành phố', 'Ký ức', 'Hành trình', 'Giấc mơ'];
          $w = $words[array_rand($words)];
          return "SELECT SQL_NO_CACHE id,title,author FROM books WHERE title LIKE '%$w%'";
        },
        'query_with_index' => function ($n, $sqlNoIndex) {
          preg_match("/LIKE '%(.*)%'/", $sqlNoIndex, $matches);
          $w = $matches[1] ?? 'Biển';
          return "SELECT SQL_NO_CACHE id,title,author FROM books WHERE MATCH(title) AGAINST('+$w' IN BOOLEAN MODE)";
        }
      ],
      'ft_phrase' => [
        'name'       => 'Tìm cụm từ chính xác ("Mùa thu", "Bất tận")',
        'index'      => 'idx_books_ft_title',
        'col'        => 'title',
        'index_type' => 'FULLTEXT',
        'query_no_index' => function ($n) {
          $phrases = ['Mùa thu', 'Ngủ quên', 'Lái đò', 'Bất tận'];
          $p = $phrases[array_rand($phrases)];
          return "SELECT SQL_NO_CACHE id,title,author FROM books WHERE title LIKE '%$p%'";
        },
        'query_with_index' => function ($n, $sqlNoIndex) {
          preg_match("/LIKE '%(.*)%'/", $sqlNoIndex, $matches);
          $p = $matches[1] ?? 'Mùa thu';
          return "SELECT SQL_NO_CACHE id,title,author FROM books WHERE MATCH(title) AGAINST('\"$p\"' IN BOOLEAN MODE)";
        }
      ],
    ]
  ],
  'rtree' => [
    'label' => 'R-tree (Spatial Index)',
    'scenarios' => [
      'rtree_contains' => [
        'name'       => 'ST_Contains() – Đa giác bao chứa toạ độ sách',
        'index'      => 'idx_books_location',
        'col'        => 'location',
        'index_type' => 'SPATIAL',
        'query'      => function ($n) {
          $lat = random_int(10720, 10760) / 1000;
          $lng = random_int(106620, 106660) / 1000;
          $d = 0.035;
          $p1 = "$lat $lng";
          $p2 = ($lat + $d) . " $lng";
          $p3 = ($lat + $d) . " " . ($lng + $d);
          $p4 = "$lat " . ($lng + $d);
          $poly = "POLYGON(($p1, $p2, $p3, $p4, $p1))";
          return "SELECT SQL_NO_CACHE id,title,author,ST_AsText(location) as coords FROM books WHERE ST_Contains(ST_GeomFromText('$poly'), location)";
        }
      ],
      'rtree_within' => [
        'name'       => 'ST_Within() – Toạ độ sách nằm trong ranh giới đa giác',
        'index'      => 'idx_books_location',
        'col'        => 'location',
        'index_type' => 'SPATIAL',
        'query'      => function ($n) {
          $lat = random_int(10720, 10760) / 1000;
          $lng = random_int(106620, 106660) / 1000;
          $d = 0.035;
          $p1 = "$lat $lng";
          $p2 = ($lat + $d) . " $lng";
          $p3 = ($lat + $d) . " " . ($lng + $d);
          $p4 = "$lat " . ($lng + $d);
          $poly = "POLYGON(($p1, $p2, $p3, $p4, $p1))";
          return "SELECT SQL_NO_CACHE id,title,author,ST_AsText(location) as coords FROM books WHERE ST_Within(location, ST_GeomFromText('$poly'))";
        }
      ],
      'rtree_intersects' => [
        'name'       => 'ST_Intersects() – Vùng không gian giao cắt với sách',
        'index'      => 'idx_books_location',
        'col'        => 'location',
        'index_type' => 'SPATIAL',
        'query'      => function ($n) {
          $lat = random_int(10720, 10760) / 1000;
          $lng = random_int(106620, 106660) / 1000;
          $d = 0.035;
          $p1 = "$lat $lng";
          $p2 = ($lat + $d) . " $lng";
          $p3 = ($lat + $d) . " " . ($lng + $d);
          $p4 = "$lat " . ($lng + $d);
          $poly = "POLYGON(($p1, $p2, $p3, $p4, $p1))";
          return "SELECT SQL_NO_CACHE id,title,author,ST_AsText(location) as coords FROM books WHERE ST_Intersects(location, ST_GeomFromText('$poly'))";
        }
      ],
    ]
  ],

  // ===== PHẦN 4 (SARGable Query) + PHẦN 9 (Multi-Range Read) =====
  // Kịch bản có khóa 'mode' được xử lý bằng runSpecialMode() trong api.php
  'sargable' => [
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
  ],
  'mrr' => [
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
  ],
  'composite' => [
    'label' => 'Composite Index',
    'scenarios' => [
      'comp_basic' => [
        'name'       => 'Không index vs composite (genre, year, price)',
        'index'      => 'idx_books_comp_genre_year_price',
        'cols'       => ['genre', 'year', 'price'],
        'col'        => 'genre, year, price',
        'index_type' => 'USING BTREE',
        'labelA'     => 'Không index (Full Scan)',
        'labelB'     => 'Composite (genre, year, price)',
        'query'      => function ($n) {
          return "SELECT SQL_NO_CACHE id,title,author FROM books WHERE genre='Trinh thám' AND year BETWEEN 2010 AND 2020 AND price BETWEEN 100000 AND 150000";
        },
      ],
      'comp_order' => [
        'name'       => 'So sánh thứ tự cột trong composite index',
        'mode'       => 'index_compare',
        'col'        => 'genre, year, price',
        'labelA'     => 'Sai thứ tự (price, genre, year)',
        'labelB'     => 'Đúng thứ tự (genre, year, price)',
        'indexA'     => [
          'index' => 'idx_books_comp_price_genre_year',
          'cols'  => ['price', 'genre', 'year'],
        ],
        'indexB'     => [
          'index' => 'idx_books_comp_genre_year_price_order',
          'cols'  => ['genre', 'year', 'price'],
        ],
        'query'      => function ($n) {
          return "SELECT SQL_NO_CACHE id,title,author FROM books WHERE genre='Trinh thám' AND year BETWEEN 2010 AND 2020 AND price BETWEEN 100000 AND 150000";
        },
      ],
    ],
  ],
  'icp' => [
    'label' => 'Index Condition Pushdown (ICP)',
    'scenarios' => [
      'icp_price_genre' => [
        'name'       => 'Cùng composite index: ICP tắt vs bật',
        'mode'       => 'switch_compare',
        'switch'     => 'icp',
        'index'      => 'idx_books_icp_genre_year_price',
        'cols'       => ['genre', 'year', 'price'],
        'col'        => 'genre, year, price',
        'index_type' => 'USING BTREE',
        'labelA'     => 'ICP tắt (index_condition_pushdown=off)',
        'labelB'     => 'ICP bật (index_condition_pushdown=on)',
        'query'      => function ($n) {
          return "SELECT SQL_NO_CACHE id,title,author FROM books WHERE genre='Trinh thám' AND year BETWEEN 2010 AND 2020 AND price BETWEEN 100000 AND 150000";
        },
      ],
      'icp_like_title' => [
        'name'       => 'LIKE "%Mùa%" trên title trong năm 2020: ICP tắt vs bật',
        'mode'       => 'switch_compare',
        'switch'     => 'icp',
        'index'      => 'idx_books_icp_genre_year_title',
        'cols'       => ['genre', 'year', 'title'],
        'col'        => 'genre, year, title',
        'index_type' => 'USING BTREE',
        'labelA'     => 'ICP tắt (LIKE trên title)',
        'labelB'     => 'ICP bật (LIKE trên title)',
        'query'      => function ($n) {
          return "SELECT SQL_NO_CACHE id,author FROM books FORCE INDEX (idx_books_icp_genre_year_title) WHERE genre='Trinh thám' AND year=2020 AND title LIKE '%Mùa%'";
        },
      ],
    ],
  ]
];
