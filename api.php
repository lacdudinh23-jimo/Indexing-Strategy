<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/scenarios.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(0);

// Xuất kết quả JSON và kết thúc request
function out($d, $code = 200)
{
  http_response_code($code);
  echo json_encode($d, JSON_UNESCAPED_UNICODE);
  exit;
}

// Lấy kế hoạch thực thi câu lệnh SQL (EXPLAIN query)
function plan($db, $sql)
{
  $r = $db->query('EXPLAIN ' . $sql);
  if (!$r) return 'EXPLAIN lỗi: ' . $db->error;
  $lines = [];
  while ($row = $r->fetch_assoc()) {
    $lines[] = "type={$row['type']}  key=" . ($row['key'] ?? 'NULL') . "  rows≈{$row['rows']}" . (!empty($row['Extra']) ? "  Extra={$row['Extra']}" : '');
  }
  return implode("\n", $lines);
}

// Chạy lặp tới khi đủ ~300ms để đo chính xác cả truy vấn rất nhanh, bù trừ round-trip
function bench($db, $mk, $min)
{
  $r = $db->query($mk());
  if ($r) {
    $r->fetch_all();
    $r->free(); // Chạy nóng (warm-up) bỏ qua kết quả đầu
  }
  $n = 0;
  $rows = 0;
  $t0 = hrtime(true);
  do {
    $r = $db->query($mk());
    if ($r) {
      $rows += $r->num_rows;
      $r->fetch_all();
      $r->free();
    }
    $n++;
    $el = (hrtime(true) - $t0) / 1e6;
  } while (($el < 300 || $n < $min) && $n < 20000);
  return ['ms' => $el / $n, 'n' => $n, 'rows' => (int)round($rows / $n)];
}

// ================= ENGINE ĐO CHO PHẦN 4 (SARGable) VÀ PHẦN 9 (MRR) =================
// Dùng cho các kịch bản có khóa 'mode' trong scenarios.php; tái sử dụng plan() và bench() ở trên.

function runSpecialMode($db, $algoKey, $algo, $scKey, $sc, $n)
{
  $mode = $sc['mode'];
  if ($mode === 'query_compare')  return runQueryCompare($db, $algoKey, $algo, $scKey, $sc, $n);
  if ($mode === 'switch_compare') return runSwitchCompare($db, $algoKey, $algo, $scKey, $sc, $n);
  out(['error' => 'Mode không hợp lệ'], 400);
}

// Tạo index và trả về thời gian build (ms). Báo lỗi JSON nếu thất bại.
function buildIndexTimed($db, $sc)
{
  $idx = $sc['index'];
  $col = $sc['col'];
  $type = $sc['index_type'] ?? 'USING BTREE';
  $db->query("DROP INDEX $idx ON books"); // đảm bảo sạch (bỏ qua lỗi nếu chưa có)
  $t = hrtime(true);
  if (!$db->query("CREATE INDEX $idx $type ON books($col)")) {
    out(['error' => 'Lỗi tạo index: ' . $db->error], 500);
  }
  return (hrtime(true) - $t) / 1e6;
}

function indexBytes($db)
{
  @$db->query('SET SESSION information_schema_stats_expiry=0');
  $q = $db->query("SELECT INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA='" . DB_NAME . "' AND TABLE_NAME='books'")->fetch_row();
  return (int)($q[0] ?? 0);
}

/* ---------- PHẦN 4: cùng index, khác cách viết câu SQL ---------- */
function runQueryCompare($db, $algoKey, $algo, $scKey, $sc, $n)
{
  $sqlA = $sc['query_no_index']($n);
  $sqlB = $sc['query_with_index']($n, $sqlA);
  $mkA = fn() => $sqlA;
  $mkB = fn() => $sqlB;

  $build = buildIndexTimed($db, $sc);
  $db->query('ANALYZE TABLE books');

  $planA = plan($db, $sqlA);
  $A = bench($db, $mkA, 2);
  $planB = plan($db, $sqlB);
  $B = bench($db, $mkB, 50);
  $size = indexBytes($db);

  $db->query('DROP INDEX ' . $sc['index'] . ' ON books');

  return [
    'algo' => $algoKey, 'algo_name' => $algo['label'], 'scenario' => $scKey, 'name' => $sc['name'],
    'sql' => preg_replace('/ SQL_NO_CACHE/', '', $sqlB),
    'sqlA' => preg_replace('/ SQL_NO_CACHE/', '', $sqlA),
    'sqlB' => preg_replace('/ SQL_NO_CACHE/', '', $sqlB),
    'n' => $n, 'A' => $A, 'B' => $B, 'build_ms' => $build, 'index_bytes' => $size,
    'planA' => $planA, 'planB' => $planB,
    'labelA' => $sc['labelA'], 'labelB' => $sc['labelB'],
    'planTitleA' => 'EXPLAIN – câu bọc hàm (index có nhưng KHÔNG dùng được)',
    'planTitleB' => 'EXPLAIN – câu đã viết lại SARGable',
    'note' => 'Index ' . $sc['index'] . ' tồn tại ở cả hai phía; chỉ khác cách viết điều kiện WHERE.',
  ];
}

/* ---------- PHẦN 9: cùng index, cùng kiểu truy vấn, bật/tắt MRR ---------- */

// Đo xen kẽ A/B, mỗi lượt dùng khoảng ngẫu nhiên MỚI -> hai phía công bằng, không phía nào "hưởng cache" của phía kia.
function benchPair($db, $mk, $swA, $swB, $minMs = 400, $minIter = 20, $maxIter = 3000)
{
  $t = ['A' => 0.0, 'B' => 0.0];
  $c = ['A' => 0, 'B' => 0];
  $rows = ['A' => 0, 'B' => 0];
  $sw = ['A' => $swA, 'B' => $swB];

  // warm-up: nạp metadata/plan, không tính vào kết quả
  foreach (['A', 'B'] as $arm) {
    $db->query("SET SESSION optimizer_switch='" . $sw[$arm] . "'");
    $r = $db->query($mk());
    if ($r) { $r->fetch_all(); $r->free(); }
  }

  $i = 0;
  do {
    $order = ($i % 2 === 0) ? ['A', 'B'] : ['B', 'A'];
    foreach ($order as $arm) {
      $db->query("SET SESSION optimizer_switch='" . $sw[$arm] . "'");
      $sql = $mk();
      $t0 = hrtime(true);
      $r = $db->query($sql);
      if ($r) {
        $rows[$arm] += $r->num_rows;
        $r->fetch_all();
        $r->free();
      }
      $t[$arm] += (hrtime(true) - $t0) / 1e6;
      $c[$arm]++;
    }
    $i++;
  } while (($t['A'] < $minMs || $t['B'] < $minMs || $i < $minIter) && $i < $maxIter);

  return [
    'A' => ['ms' => $t['A'] / $c['A'], 'n' => $c['A'], 'rows' => (int)round($rows['A'] / $c['A'])],
    'B' => ['ms' => $t['B'] / $c['B'], 'n' => $c['B'], 'rows' => (int)round($rows['B'] / $c['B'])],
  ];
}

function runSwitchCompare($db, $algoKey, $algo, $scKey, $sc, $n)
{
  // MariaDB (mặc định của XAMPP) khác MySQL 8 ở tên cờ và dòng Extra của EXPLAIN
  $isMaria = stripos($db->server_info, 'MariaDB') !== false;
  $swOff = 'mrr=off';
  $swOn = $isMaria ? 'mrr=on,mrr_sort_keys=on,mrr_cost_based=off' : 'mrr=on,mrr_cost_based=off';
  $swReset = $isMaria ? 'mrr=default,mrr_sort_keys=default,mrr_cost_based=default' : 'mrr=default,mrr_cost_based=default';

  $mk = fn() => $sc['query']($n);
  $sample = $mk();

  $build = buildIndexTimed($db, $sc);
  $db->query('ANALYZE TABLE books');

  $db->query("SET SESSION optimizer_switch='$swOff'");
  $planA = plan($db, $sample);
  $db->query("SET SESSION optimizer_switch='$swOn'");
  $planB = plan($db, $sample);

  $res = benchPair($db, $mk, $swOff, $swOn);
  $size = indexBytes($db);

  $db->query("SET SESSION optimizer_switch='$swReset'");
  $db->query('DROP INDEX ' . $sc['index'] . ' ON books');

  $clean = preg_replace('/ SQL_NO_CACHE/', '', $sample);
  return [
    'algo' => $algoKey, 'algo_name' => $algo['label'], 'scenario' => $scKey, 'name' => $sc['name'],
    'sql' => $clean, 'sqlA' => $clean, 'sqlB' => $clean,
    'n' => $n, 'A' => $res['A'], 'B' => $res['B'], 'build_ms' => $build, 'index_bytes' => $size,
    'planA' => $planA, 'planB' => $planB,
    'labelA' => $sc['labelA'], 'labelB' => $sc['labelB'],
    'planTitleA' => "EXPLAIN – $swOff",
    'planTitleB' => "EXPLAIN – $swOn" . ($isMaria ? ' (tìm \'Rowid-ordered scan\')' : ' (tìm \'Using MRR\')'),
    'note' => ($isMaria ? 'MariaDB' : 'MySQL') . ' ' . $db->server_info . '. Mỗi lượt đo dùng khoảng ngẫu nhiên mới, xen kẽ tắt/bật MRR.',
  ];
}

// ================= ROUTER ĐIỀU HƯỚNG REQUEST =================
$action = $_POST['action'] ?? 'meta';

// 1. Lấy thông tin số dòng và danh sách kịch bản truy vấn phân theo thuật toán
if ($action === 'meta') {
  $scenarios = [];
  foreach ($ALGOS as $algoKey => $a) {
    $scenarios[$algoKey] = [];
    foreach ($a['scenarios'] as $sKey => $s) {
      $scenarios[$algoKey][] = [
        'key'  => $sKey,
        'name' => $s['name'],
        'col'  => $s['col']
      ];
    }
  }
  out([
    'rows'      => dbRows($db),
    'scenarios' => $scenarios
  ]);
}

// 2. Xóa và tạo lại bảng dữ liệu
if ($action === 'reset') {
  if (!resetBooksTable($db)) out(['error' => $db->error], 500);
  out(['ok' => true]);
}

// 3. Sinh dữ liệu mẫu theo block (kèm toạ độ địa lý thực tế)
if ($action === 'gen') {
  $from = max(1, (int)($_POST['from'] ?? 1));
  $to = (int)($_POST['to'] ?? 0);
  if ($to < $from) out(['error' => 'Dải dữ liệu không hợp lệ'], 400);

  $W1 = ['Dế', 'Tôi', 'Cô', 'Người', 'Con', 'Mùa', 'Đường', 'Biển', 'Núi', 'Thành phố', 'Ký ức', 'Hành trình', 'Bóng', 'Ngôi nhà', 'Giấc mơ'];
  $W2 = ['Mèn', 'Thấy', 'Gái', 'Lái đò', 'Chim', 'Hoa', 'Xa', 'Xanh', 'Cao', 'Ngủ quên', 'Mùa thu', 'Cuối', 'Đêm', 'Nhỏ', 'Bất tận'];
  $AU = ['Nguyễn Nhật Ánh', 'Tô Hoài', 'Nam Cao', 'Ngô Tất Tố', 'Xuân Diệu', 'Nguyễn Du', 'Vũ Trọng Phụng', 'Bảo Ninh', 'Dương Thu Hương', 'Nguyễn Huy Thiệp'];
  $GE = ['Tiểu thuyết', 'Truyện ngắn', 'Thiếu nhi', 'Trinh thám', 'Lịch sử', 'Khoa học', 'Kinh tế'];

  $db->begin_transaction();
  for ($s = $from; $s <= $to; $s += 1000) {
    $vals = [];
    for ($i = $s; $i <= min($s + 999, $to); $i++) {
      $title = $db->real_escape_string($W1[array_rand($W1)] . ' ' . $W2[array_rand($W2)] . ' ' . random_int(0, 99999));
      $lat = random_int(107000, 108500) / 10000;
      $lng = random_int(1066000, 1067500) / 10000;
      $vals[] = "($i,'" . isbnOf($i) . "','$title','" . $db->real_escape_string($AU[array_rand($AU)]) . "','" . $db->real_escape_string($GE[array_rand($GE)]) . "'," . random_int(1950, 2025) . ',' . random_int(10000, 500000) . ",ST_GeomFromText('POINT($lat $lng)'))";
    }
    if (!$db->query('INSERT INTO books (id,isbn,title,author,genre,year,price,location) VALUES ' . implode(',', $vals))) {
      $db->rollback();
      out(['error' => $db->error], 500);
    }
  }
  $db->commit();
  out(['ok' => true]);
}

// 4. Chạy benchmark so sánh có index và không có index
if ($action === 'run') {
  $algoKey = $_POST['algo'] ?? 'btree';
  $scenarioKey = $_POST['scenario'] ?? '';

  $algo = $ALGOS[$algoKey] ?? null;
  if (!$algo) out(['error' => 'Thuật toán không hợp lệ'], 400);

  $sc = $algo['scenarios'][$scenarioKey] ?? null;
  if (!$sc) out(['error' => 'Kịch bản truy vấn không hợp lệ'], 400);

  $n = dbRows($db);
  if ($n < 1) out(['error' => 'Chưa có dữ liệu. Bấm "Tạo lại dữ liệu mẫu" trước.'], 400);

  // Các kịch bản có 'mode' (SARGable, MRR) dùng engine đo riêng (runSpecialMode) ở phần ENGINE ĐO phía trên
  if (isset($sc['mode'])) {
    out(runSpecialMode($db, $algoKey, $algo, $scenarioKey, $sc, $n));
  }

  $idx = $sc['index'];
  $col = $sc['col'];
  $idxType = $sc['index_type'] ?? 'USING BTREE';

  // Chuẩn bị câu lệnh SQL
  if (isset($sc['query_no_index'])) {
    $sqlA = $sc['query_no_index']($n);
    $sqlB = $sc['query_with_index']($n, $sqlA);
  } else {
    $sqlA = $sc['query']($n);
    $sqlB = $sqlA;
  }

  $mkA = fn() => $sqlA;
  $mkB = fn() => $sqlB;

  // Bước 1: Đo khi KHÔNG có index (Full Scan)
  $db->query("DROP INDEX $idx ON books");
  $db->query('ANALYZE TABLE books');
  $planA = plan($db, $mkA());
  $A = bench($db, $mkA, 2);

  // Bước 2: Tạo index và đo thời gian build
  $t = hrtime(true);
  if ($idxType === 'FULLTEXT') {
    $createSql = "CREATE FULLTEXT INDEX $idx ON books($col)";
  } elseif ($idxType === 'SPATIAL') {
    $createSql = "CREATE SPATIAL INDEX $idx ON books($col)";
  } else {
    $createSql = "CREATE INDEX $idx $idxType ON books($col)";
  }

  if (!$db->query($createSql)) {
    out(['error' => 'Lỗi tạo index: ' . $db->error], 500);
  }
  $build = (hrtime(true) - $t) / 1e6;

  // Bước 3: Đo khi CÓ index
  $db->query('ANALYZE TABLE books');
  $planB = plan($db, $mkB());
  $B = bench($db, $mkB, 50);

  // Bước 4: Lấy dung lượng index từ thông số hệ thống
  @$db->query('SET SESSION information_schema_stats_expiry=0');
  $q = $db->query("SELECT INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA='" . DB_NAME . "' AND TABLE_NAME='books'")->fetch_row();
  $size = (int)($q[0] ?? 0);

  // Bước 5: Dọn dẹp index sau khi đo xong
  $db->query("DROP INDEX $idx ON books");

  out([
    'algo'        => $algoKey,
    'algo_name'   => $algo['label'],
    'scenario'    => $scenarioKey,
    'name'        => $sc['name'],
    'sql'         => preg_replace('/ SQL_NO_CACHE/', '', $sqlB),
    'sqlA'        => preg_replace('/ SQL_NO_CACHE/', '', $sqlA),
    'sqlB'        => preg_replace('/ SQL_NO_CACHE/', '', $sqlB),
    'n'           => $n,
    'A'           => $A,
    'B'           => $B,
    'build_ms'    => $build,
    'index_bytes' => $size,
    'planA'       => $planA,
    'planB'       => $planB
  ]);
}

out(['error' => 'Action không hợp lệ'], 400);
