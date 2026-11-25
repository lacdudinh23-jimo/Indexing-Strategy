<?php
/**
 * Engine đo cho PHẦN 4 (SARGable) và PHẦN 9 (MRR).
 * Dùng lại plan() và bench() trong api.php (được gọi lúc chạy nên không cần require ngược).
 */

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
