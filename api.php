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
  if (!$r) out(['error' => 'Lỗi chạy truy vấn benchmark: ' . $db->error], 500);
  $r->fetch_all();
  $r->free(); // Chạy nóng (warm-up) bỏ qua kết quả đầu
  $n = 0;
  $rows = 0;
  $t0 = hrtime(true);
  do {
    $r = $db->query($mk());
    if (!$r) out(['error' => 'Lỗi chạy truy vấn benchmark: ' . $db->error], 500);
    $rows += $r->num_rows;
    $r->fetch_all();
    $r->free();
    $n++;
    $el = (hrtime(true) - $t0) / 1e6;
  } while (($el < 300 || $n < $min) && $n < 20000);
  return ['ms' => $el / $n, 'n' => $n, 'rows' => (int)round($rows / $n)];
}

// ================= ENGINE ĐO CHO CÁC CHẾ ĐỘ SO SÁNH ĐẶC BIỆT =================
// Dùng cho các kịch bản có khóa 'mode' trong scenarios.php; tái sử dụng plan() và bench() ở trên.

function runSpecialMode($db, $algoKey, $algo, $scKey, $sc, $n)
{
  $mode = $sc['mode'];
  if ($mode === 'query_compare')  return runQueryCompare($db, $algoKey, $algo, $scKey, $sc, $n);
  if ($mode === 'switch_compare') return runSwitchCompare($db, $algoKey, $algo, $scKey, $sc, $n);
  if ($mode === 'index_compare') return runIndexCompare($db, $algoKey, $algo, $scKey, $sc, $n);
  if ($mode === 'cbo_join') return runCboJoinBenchmark($db, $algoKey, $algo, $scKey, $sc, $n);
  out(['error' => 'Mode không hợp lệ'], 400);
}

function cboEstimateJoin($left, $right)
{
  $rows = ($left['rows'] * $right['rows']) / max(1, $left['ndv'], $right['ndv']);
  return ['rows' => $rows, 'ndv' => min($left['ndv'], $right['ndv'], $rows)];
}

function cboDynamicPlan($stats)
{
  $count = count($stats);
  $dp = [];
  for ($i = 0; $i < $count; $i++) {
    $dp[1 << $i] = ['cost' => 0.0, 'rows' => $stats[$i]['rows'], 'ndv' => $stats[$i]['ndv'], 'order' => [$i]];
  }

  $fullMask = (1 << $count) - 1;
  for ($mask = 1; $mask <= $fullMask; $mask++) {
    if (!isset($dp[$mask])) continue;
    for ($next = 0; $next < $count; $next++) {
      $nextBit = 1 << $next;
      if (($mask & $nextBit) !== 0) continue;
      $nextMask = $mask | $nextBit;
      $joined = cboEstimateJoin($dp[$mask], $stats[$next]);
      $cost = $dp[$mask]['cost'] + $dp[$mask]['rows'] + $stats[$next]['rows'] + $joined['rows'];
      $candidateOrder = array_merge($dp[$mask]['order'], [$next]);
      $hasExistingPlan = isset($dp[$nextMask]);
      $candidateStartsSmaller = !$hasExistingPlan
        || $stats[$candidateOrder[0]]['rows'] < $stats[$dp[$nextMask]['order'][0]]['rows'];
      $isLowerCost = !$hasExistingPlan || $cost < $dp[$nextMask]['cost'];
      $isCheaperTie = $hasExistingPlan && abs($cost - $dp[$nextMask]['cost']) < 1e-9 && $candidateStartsSmaller;
      if ($isLowerCost || $isCheaperTie) {
        $dp[$nextMask] = [
          'cost' => $cost,
          'rows' => $joined['rows'],
          'ndv' => $joined['ndv'],
          'order' => $candidateOrder,
        ];
      }
    }
  }
  return $dp[$fullMask];
}

function cboFixedOrderCost($stats, $order)
{
  $plan = ['cost' => 0.0, 'rows' => $stats[$order[0]]['rows'], 'ndv' => $stats[$order[0]]['ndv']];
  for ($i = 1; $i < count($order); $i++) {
    $next = $stats[$order[$i]];
    $joined = cboEstimateJoin($plan, $next);
    $plan = [
      'cost' => $plan['cost'] + $plan['rows'] + $next['rows'] + $joined['rows'],
      'rows' => $joined['rows'],
      'ndv' => $joined['ndv'],
    ];
  }
  return $plan['cost'];
}

function cboJoinSql($order, $tables)
{
  $from = '';
  $firstAlias = null;
  foreach ($order as $i => $relation) {
    [$table, $alias] = $tables[$relation];
    if ($i === 0) {
      $from = "`$table` AS $alias";
      $firstAlias = $alias;
    } else {
      $from .= " STRAIGHT_JOIN `$table` AS $alias ON $alias.join_key = $firstAlias.join_key";
    }
  }
  return 'SELECT SQL_NO_CACHE COUNT(*) AS joined_rows FROM ' . $from;
}

function benchCboJoinPair($db, $sqlA, $sqlB)
{
  foreach ([$sqlA, $sqlB] as $sql) {
    $r = $db->query($sql);
    if (!$r) out(['error' => 'Lỗi chạy JOIN benchmark: ' . $db->error], 500);
    $r->fetch_assoc();
    $r->free();
  }

  $times = ['A' => 0.0, 'B' => 0.0];
  $iterations = ['A' => 0, 'B' => 0];
  $rows = ['A' => 0, 'B' => 0];
  $queries = ['A' => $sqlA, 'B' => $sqlB];
  $round = 0;
  do {
    $order = ($round % 2 === 0) ? ['A', 'B'] : ['B', 'A'];
    foreach ($order as $arm) {
      $start = hrtime(true);
      $r = $db->query($queries[$arm]);
      if (!$r) out(['error' => 'Lỗi chạy JOIN benchmark: ' . $db->error], 500);
      $row = $r->fetch_assoc();
      $rows[$arm] += (int)($row['joined_rows'] ?? 0);
      $r->free();
      $times[$arm] += (hrtime(true) - $start) / 1e6;
      $iterations[$arm]++;
    }
    $round++;
  } while (($times['A'] < 300 || $times['B'] < 300 || $round < 2) && $round < 20000);

  return [
    'A' => ['ms' => $times['A'] / $iterations['A'], 'n' => $iterations['A'], 'rows' => (int)round($rows['A'] / $iterations['A'])],
    'B' => ['ms' => $times['B'] / $iterations['B'], 'n' => $iterations['B'], 'rows' => (int)round($rows['B'] / $iterations['B'])],
  ];
}

function runCboJoinBenchmark($db, $algoKey, $algo, $scKey, $sc, $n)
{
  $sampleSize = min($n, 1200);
  $tables = [
    ['cbo_large', 'l'],
    ['cbo_medium', 'm'],
    ['cbo_small', 's'],
  ];
  $divisors = $sc['divisors'] ?? [1, 4, 8];
  if (count($divisors) !== count($tables)) {
    out(['error' => 'Cấu hình kịch bản CBO không hợp lệ'], 500);
  }
  $stats = [];
  foreach ($tables as $i => [$table]) {
    $divisor = max(1, (int)$divisors[$i]);
    $filter = $divisor === 1 ? '' : " AND MOD(id, $divisor) = 0";
    if (!$db->query("CREATE TEMPORARY TABLE `$table` ENGINE=InnoDB AS SELECT id AS join_key FROM books WHERE id <= $sampleSize" . $filter)) {
      out(['error' => 'Không thể tạo bảng JOIN tạm: ' . $db->error], 500);
    }
    $result = $db->query("SELECT COUNT(*) AS row_count, COUNT(DISTINCT join_key) AS ndv FROM `$table`");
    if (!$result) out(['error' => 'Không thể đọc thống kê JOIN: ' . $db->error], 500);
    $row = $result->fetch_assoc();
    $result->free();
    $stats[] = ['rows' => (float)$row['row_count'], 'ndv' => (float)$row['ndv']];
  }

  $dpPlan = cboDynamicPlan($stats);
  $baselineOrder = [0, 1, 2];
  $labels = ['bảng lớn', 'bảng vừa', 'bảng nhỏ'];
  $dpOrderText = implode(' → ', array_map(fn($i) => $labels[$i], $dpPlan['order']));
  $sqlA = cboJoinSql($baselineOrder, $tables);
  $sqlB = cboJoinSql($dpPlan['order'], $tables);
  $planA = plan($db, $sqlA);
  $planB = plan($db, $sqlB);
  $measurements = benchCboJoinPair($db, $sqlA, $sqlB);
  $A = $measurements['A'];
  $B = $measurements['B'];

  foreach ($tables as [$table]) $db->query("DROP TEMPORARY TABLE IF EXISTS `$table`");
  $formatCost = fn($cost) => number_format($cost, 0, ',', '.');
  return [
    'algo' => $algoKey, 'algo_name' => $algo['label'], 'scenario' => $scKey, 'name' => $sc['name'],
    'sql' => $sqlB, 'sqlA' => $sqlA, 'sqlB' => $sqlB, 'n' => $n, 'A' => $A, 'B' => $B,
    'planA' => $planA, 'planB' => $planB, 'labelA' => $sc['labelA'], 'labelB' => $sc['labelB'],
    'planTitleA' => 'EXPLAIN – thứ tự JOIN cố định, bảng lớn trước',
    'planTitleB' => 'EXPLAIN – thứ tự JOIN do quy hoạch động chọn',
    'metricType' => 'join',
    'metrics' => [
      ['label' => 'Thứ tự DP được chọn:', 'value' => $dpOrderText],
      ['label' => 'Số dòng / NDV (lớn · vừa · nhỏ):', 'value' => implode(' · ', array_map(fn($s) => number_format($s['rows'], 0, ',', '.') . ' / ' . number_format($s['ndv'], 0, ',', '.'), $stats))],
      ['label' => 'Cỡ mẫu mỗi bảng:', 'value' => number_format($sampleSize, 0, ',', '.') . ' dòng tối đa'],
      ['label' => 'Chi phí ước lượng (cố định → DP):', 'value' => $formatCost(cboFixedOrderCost($stats, $baselineOrder)) . ' → ' . $formatCost($dpPlan['cost']) . ' đơn vị'],
    ],
    'note' => 'CBO minh họa dùng DP và thống kê COUNT/NDV đo từ dữ liệu; đây là mô hình chi phí giáo dục, không thay thế optimizer nội bộ. Bảng tạm không có index và giới hạn tối đa 1.200 dòng để benchmark an toàn trên MariaDB. EXPLAIN cho biết thuật toán JOIN thực tế; MariaDB 10.4 thường dùng nested-loop/block nested-loop, không phải Hash Join.',
  ];
}

function indexColumnsSql($sc)
{
  $cols = $sc['cols'] ?? [$sc['col']];
  return implode(', ', array_map(fn($col) => "`$col`", $cols));
}

function createIndexSql($sc)
{
  $idx = $sc['index'];
  $cols = indexColumnsSql($sc);
  $type = $sc['index_type'] ?? 'USING BTREE';
  if ($type === 'FULLTEXT') return "CREATE FULLTEXT INDEX `$idx` ON books($cols)";
  if ($type === 'SPATIAL') return "CREATE SPATIAL INDEX `$idx` ON books($cols)";
  return "CREATE INDEX `$idx` $type ON books($cols)";
}

// Tạo index và trả về thời gian build (ms). Báo lỗi JSON nếu thất bại.
function buildIndexTimed($db, $sc)
{
  $idx = $sc['index'];
  $db->query("DROP INDEX `$idx` ON books"); // đảm bảo sạch (bỏ qua lỗi nếu chưa có)
  $t = hrtime(true);
  if (!$db->query(createIndexSql($sc))) {
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

/* ---------- Composite index: so sánh thứ tự cột trên cùng truy vấn ---------- */
function runIndexCompare($db, $algoKey, $algo, $scKey, $sc, $n)
{
  $sql = $sc['query']($n);
  $measure = function ($definition) use ($db, $sql) {
    $build = buildIndexTimed($db, $definition);
    $db->query('ANALYZE TABLE books');
    $plan = plan($db, $sql);
    $result = bench($db, fn() => $sql, 50);
    $size = indexBytes($db);
    $db->query('DROP INDEX `' . $definition['index'] . '` ON books');
    return ['build_ms' => $build, 'plan' => $plan, 'result' => $result, 'size' => $size];
  };

  $A = $measure($sc['indexA']);
  $B = $measure($sc['indexB']);

  return [
    'algo' => $algoKey, 'algo_name' => $algo['label'], 'scenario' => $scKey, 'name' => $sc['name'],
    'sql' => preg_replace('/ SQL_NO_CACHE/', '', $sql),
    'sqlA' => preg_replace('/ SQL_NO_CACHE/', '', $sql),
    'sqlB' => preg_replace('/ SQL_NO_CACHE/', '', $sql),
    'n' => $n, 'A' => $A['result'], 'B' => $B['result'],
    'build_msA' => $A['build_ms'], 'build_msB' => $B['build_ms'],
    'index_bytesA' => $A['size'], 'index_bytesB' => $B['size'],
    'planA' => $A['plan'], 'planB' => $B['plan'],
    'labelA' => $sc['labelA'], 'labelB' => $sc['labelB'],
    'planTitleA' => 'EXPLAIN – index ' . implode(', ', $sc['indexA']['cols']),
    'planTitleB' => 'EXPLAIN – index ' . implode(', ', $sc['indexB']['cols']),
    'note' => 'Cùng một câu SQL; mỗi lượt chỉ tồn tại một composite index để so sánh thứ tự cột.',
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
    if (!$db->query("SET SESSION optimizer_switch='" . $sw[$arm] . "'")) {
      out(['error' => 'Không thể cấu hình optimizer_switch: ' . $db->error], 500);
    }
    $r = $db->query($mk());
    if (!$r) out(['error' => 'Lỗi chạy truy vấn benchmark: ' . $db->error], 500);
    $r->fetch_all();
    $r->free();
  }

  $i = 0;
  do {
    $order = ($i % 2 === 0) ? ['A', 'B'] : ['B', 'A'];
    foreach ($order as $arm) {
      if (!$db->query("SET SESSION optimizer_switch='" . $sw[$arm] . "'")) {
        out(['error' => 'Không thể cấu hình optimizer_switch: ' . $db->error], 500);
      }
      $sql = $mk();
      $t0 = hrtime(true);
      $r = $db->query($sql);
      if (!$r) out(['error' => 'Lỗi chạy truy vấn benchmark: ' . $db->error], 500);
      $rows[$arm] += $r->num_rows;
      $r->fetch_all();
      $r->free();
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
  $isMaria = stripos($db->server_info, 'MariaDB') !== false;
  $switch = $sc['switch'] ?? 'mrr';
  if ($switch === 'icp') {
    $swOff = 'index_condition_pushdown=off';
    $swOn = 'index_condition_pushdown=on';
    $swReset = 'index_condition_pushdown=default';
  } else {
    // MariaDB (mặc định của XAMPP) khác MySQL 8 ở dòng Extra của EXPLAIN
    $swOff = 'mrr=off';
    $swOn = $isMaria ? 'mrr=on,mrr_sort_keys=on,mrr_cost_based=off' : 'mrr=on,mrr_cost_based=off';
    $swReset = $isMaria ? 'mrr=default,mrr_sort_keys=default,mrr_cost_based=default' : 'mrr=default,mrr_cost_based=default';
  }

  $mk = fn() => $sc['query']($n);
  $sample = $mk();

  $build = buildIndexTimed($db, $sc);
  $db->query('ANALYZE TABLE books');

  if (!$db->query("SET SESSION optimizer_switch='$swOff'")) {
    out(['error' => 'Không thể tắt optimizer option: ' . $db->error], 500);
  }
  $planA = plan($db, $sample);
  if (!$db->query("SET SESSION optimizer_switch='$swOn'")) {
    out(['error' => 'Không thể bật optimizer option: ' . $db->error], 500);
  }
  $planB = plan($db, $sample);

  $res = benchPair($db, $mk, $swOff, $swOn);
  $size = indexBytes($db);

  if (!$db->query("SET SESSION optimizer_switch='$swReset'")) {
    out(['error' => 'Không thể khôi phục optimizer option: ' . $db->error], 500);
  }
  $db->query('DROP INDEX `' . $sc['index'] . '` ON books');

  $clean = preg_replace('/ SQL_NO_CACHE/', '', $sample);
  $planTitleA = "EXPLAIN – $swOff";
  $planTitleB = "EXPLAIN – $swOn";
  if ($switch === 'mrr') {
    $planTitleB .= $isMaria ? ' (tìm \'Rowid-ordered scan\')' : ' (tìm \'Using MRR\')';
  } else {
    $planTitleB .= ' (tìm \'Using index condition\')';
  }
  return [
    'algo' => $algoKey, 'algo_name' => $algo['label'], 'scenario' => $scKey, 'name' => $sc['name'],
    'sql' => $clean, 'sqlA' => $clean, 'sqlB' => $clean,
    'n' => $n, 'A' => $res['A'], 'B' => $res['B'], 'build_ms' => $build, 'index_bytes' => $size,
    'planA' => $planA, 'planB' => $planB,
    'labelA' => $sc['labelA'], 'labelB' => $sc['labelB'],
    'planTitleA' => $planTitleA,
    'planTitleB' => $planTitleB,
    'note' => ($isMaria ? 'MariaDB' : 'MySQL') . ' ' . $db->server_info . ($switch === 'mrr'
      ? '. Mỗi lượt đo dùng khoảng ngẫu nhiên mới, xen kẽ tắt/bật MRR.'
      : '. Cùng truy vấn và composite index; đo xen kẽ ICP tắt/bật.'),
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
  if (!$db->query(createIndexSql($sc))) {
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
  $db->query("DROP INDEX `$idx` ON books");

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
    'planB'       => $planB,
    'labelA'      => $sc['labelA'] ?? null,
    'labelB'      => $sc['labelB'] ?? null
  ]);
}

out(['error' => 'Action không hợp lệ'], 400);
