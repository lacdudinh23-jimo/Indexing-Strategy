<?php
/**
 * Sinh mã ISBN giả lập ngẫu nhiên nhưng có tính xác định theo id
 */
function isbnOf($i)
{
  return str_pad((string)(($i * 48271) % 2147483647), 10, '0', STR_PAD_LEFT);
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
  ]
];

