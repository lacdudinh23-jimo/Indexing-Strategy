// Frontend Controller for MySQL Indexing Demo
// Toàn bộ việc định dạng, hiển thị, quản lý 3 thuật toán (B-tree, Hash, Full-Text) tại Client-side

const $ = (s) => document.querySelector(s);
const esc = (s) =>
  String(s).replace(
    /[&<>]/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c])
  );

const fmtTime = (ms) =>
  ms >= 1 ? ms.toFixed(1) + ' ms' : (ms * 1000).toFixed(0) + ' µs';

const fmtBytes = (bytes) => {
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
  if (bytes >= 1024) return (bytes / 1024).toFixed(0) + ' KB';
  return bytes + ' B';
};

// Danh mục 3 thuật toán chỉ mục (B-tree, Hash Index, Full-Text Index)
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
  }
};

let ROWS = 0;
let BACKEND_SCENARIOS = {};
let CONFIG = {
  apiEndpoint: 'api.php',
  chunkSize: 50000,
  dataSizes: [
    { value: 100000, label: '100.000 dòng' },
    { value: 500000, label: '500.000 dòng', selected: true },
    { value: 1000000, label: '1.000.000 dòng' }
  ]
};

// Cập nhật trạng thái kết nối Database trên Header
function updateDbBadge(status, text) {
  const badge = $('#dbBadge');
  const txt = $('#dbStatusText');
  badge.className = 'db-badge ' + status;
  txt.textContent = text;
}

// Cập nhật trạng thái từng bảng điều khiển
function setStatus(elementId, text, type = '') {
  const el = $(elementId);
  el.textContent = text;
  el.className = 'status-msg ' + type;
}

// Khóa / Mở nút thao tác khi đang bận
function setBusy(isBusy) {
  $('#genBtn').disabled = isBusy;
  $('#runBtn').disabled = isBusy || ROWS === 0;
  $('#size').disabled = isBusy;
  $('#algoSelect').disabled = isBusy;
  $('#scn').disabled = isBusy;
}

// Wrapper gọi Backend API
async function api(action, data = {}) {
  const endpoint = CONFIG.apiEndpoint || 'api.php';
  const res = await fetch(endpoint, {
    method: 'POST',
    body: new URLSearchParams({ action, ...data })
  });

  let json;
  try {
    json = await res.json();
  } catch (err) {
    throw new Error('API không trả về định dạng JSON hợp lệ.');
  }

  if (json.error) {
    throw new Error(json.error);
  }
  return json;
}

// Hiển thị lý thuyết và nạp kịch bản khi người dùng đổi thuật toán
function updateSelectedAlgorithm() {
  const key = $('#algoSelect').value;
  const algo = ALGORITHMS[key] || ALGORITHMS.btree;

  // Cập nhật thẻ Knowledge
  $('#algoTitle').textContent = `Hiểu nhanh về ${algo.name}`;
  $('#algoBadge').textContent = algo.badge;
  $('#algoBadge').style.background = 'var(--color-accent)';
  $('#algoBadge').style.color = '#0f172a';

  $('#algoBody').innerHTML = `
    <p>${algo.summary}</p>
    <div class="knowledge-grid">
      <div class="feature-box pros">
        <div class="feature-title">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
          Điểm mạnh
        </div>
        <ul>${algo.pros.map((p) => `<li>${p}</li>`).join('')}</ul>
      </div>
      <div class="feature-box cons">
        <div class="feature-title">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          </svg>
          Hạn chế &amp; Chi phí
        </div>
        <ul>${algo.cons.map((c) => `<li>${c}</li>`).join('')}</ul>
      </div>
    </div>
  `;

  // Cập nhật danh sách kịch bản tương ứng với thuật toán
  const scnSelect = $('#scn');
  const scenarios = BACKEND_SCENARIOS[key] || [];

  if (scenarios.length > 0) {
    scnSelect.disabled = false;
    scnSelect.innerHTML = scenarios
      .map((s) => `<option value="${s.key}">${esc(s.name)}</option>`)
      .join('');
    $('#runBtn').disabled = ROWS === 0;
    setStatus('#runStatus', ROWS > 0 ? 'Sẵn sàng chạy kiểm thử so sánh.' : 'Cần tạo dữ liệu trước khi benchmark.');
  } else {
    scnSelect.disabled = true;
    scnSelect.innerHTML = `<option value="" disabled selected>(Không có kịch bản)</option>`;
    $('#runBtn').disabled = true;
    setStatus('#runStatus', 'Chưa có kịch bản cho thuật toán này.', 'err');
  }
}

// 1. Logic tạo dữ liệu theo lô (Batch Generation)
async function generateData() {
  const targetRows = +$('#size').value;
  const chunkSize = CONFIG.chunkSize || 50000;
  const progressWrap = $('#progressWrap');
  const progressBar = $('#progressBar');

  setBusy(true);
  progressWrap.classList.add('active');
  progressBar.style.width = '0%';
  setStatus('#genStatus', 'Đang reset bảng dữ liệu...', '');

  try {
    await api('reset');
    ROWS = 0;
    updateDbBadge('ready', '0 dòng sách');

    for (let from = 1; from <= targetRows; from += chunkSize) {
      const to = Math.min(from + chunkSize - 1, targetRows);
      const percent = Math.round((to / targetRows) * 100);

      progressBar.style.width = percent + '%';
      setStatus(
        '#genStatus',
        `Đang chèn: ${to.toLocaleString('vi-VN')} / ${targetRows.toLocaleString('vi-VN')} dòng (${percent}%)`
      );

      await api('gen', { from, to });
    }

    ROWS = targetRows;
    updateDbBadge('ready', `${ROWS.toLocaleString('vi-VN')} dòng trong DB`);
    setStatus('#genStatus', `Đã tạo thành công ${ROWS.toLocaleString('vi-VN')} dòng sách!`, 'success');
    updateSelectedAlgorithm();
  } catch (err) {
    setStatus('#genStatus', 'Lỗi: ' + err.message, 'err');
    updateDbBadge('error', 'Lỗi Database');
  } finally {
    progressWrap.classList.remove('active');
    setBusy(false);
  }
}

// 2. Logic chạy kiểm thử so sánh (Benchmark)
async function runBenchmark() {
  const algoKey = $('#algoSelect').value;
  const scenarioKey = $('#scn').value;
  if (!scenarioKey) return;

  setBusy(true);
  setStatus('#runStatus', 'Đang thực thi: Quét tuần tự → Tạo chỉ mục → Đo lại hiệu năng...', '');

  try {
    const res = await api('run', { algo: algoKey, scenario: scenarioKey });
    renderBenchmarkResult(res);
    setStatus('#runStatus', 'Kiểm thử hoàn tất! Index mẫu đã tự động được dọn dẹp.', 'success');
  } catch (err) {
    setStatus('#runStatus', 'Lỗi: ' + err.message, 'err');
  } finally {
    setBusy(false);
  }
}

// 3. Render kết quả đo lường trực quan lên DOM
let runCount = 0;
function renderBenchmarkResult(r) {
  const emptyState = $('#emptyState');
  if (emptyState) emptyState.remove();

  runCount++;
  $('#resultsCount').textContent = `${runCount} lần chạy kiểm thử`;

  const timeNoIndex = r.A.ms;
  const timeWithIndex = r.B.ms;
  const maxTime = Math.max(timeNoIndex, timeWithIndex);

  const widthNoIndex = Math.max(1, (timeNoIndex / maxTime) * 100).toFixed(1) + '%';
  const widthWithIndex = Math.max(1, (timeWithIndex / maxTime) * 100).toFixed(1) + '%';

  const ratio = timeWithIndex > 0 ? timeNoIndex / timeWithIndex : 1;
  const speedup = ratio.toFixed(ratio >= 10 ? 0 : 1);
  const algoLabel = r.algo_name || 'Chỉ mục';

  // Hiển thị câu lệnh SQL: nếu 2 câu khác nhau (như Full-text: LIKE vs MATCH) thì so sánh rõ ràng
  let sqlDisplayHtml = '';
  if (r.sqlA && r.sqlB && r.sqlA !== r.sqlB) {
    sqlDisplayHtml = `
      <div class="sql-compare-box">
        <div class="sql-sub-box">
          <span class="sql-tag danger">Không Index (LIKE):</span>
          <code>${esc(r.sqlA)}</code>
        </div>
        <div class="sql-sub-box">
          <span class="sql-tag success">Có Index (MATCH):</span>
          <code>${esc(r.sqlB)}</code>
        </div>
      </div>
    `;
  } else {
    sqlDisplayHtml = `<div class="sql-box">${esc(r.sql || r.sqlB)}</div>`;
  }

  const card = document.createElement('article');
  card.className = 'result-card';
  card.innerHTML = `
    <div class="result-header">
      <div class="result-title-group">
        <h3>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
          </svg>
          [${esc(algoLabel)}] ${esc(r.name)}
        </h3>
        <div class="result-meta-badge">
          Dữ liệu: <strong>${r.n.toLocaleString('vi-VN')} dòng</strong> · Kết quả: <strong>~${r.A.rows} dòng</strong>
        </div>
      </div>
      <div class="speedup-badge">
        <span class="speedup-val">${speedup}×</span>
        <span class="speedup-lbl">Nhanh hơn</span>
      </div>
    </div>

    ${sqlDisplayHtml}

    <div class="benchmark-visualizer">
      <div class="bench-row">
        <span class="bench-label">Không Index (Full Scan)</span>
        <div class="bar-track">
          <div class="bar-fill danger" style="width: ${widthNoIndex}"></div>
        </div>
        <span class="bench-time danger">${fmtTime(timeNoIndex)}</span>
      </div>
      <div class="bench-row">
        <span class="bench-label">Có ${esc(algoLabel)}</span>
        <div class="bar-track">
          <div class="bar-fill success" style="width: ${widthWithIndex}"></div>
        </div>
        <span class="bench-time success">${fmtTime(timeWithIndex)}</span>
      </div>
    </div>

    <div class="result-stats-row">
      <div class="stat-item">
        <span>Thời gian build index:</span>
        <strong>${fmtTime(r.build_ms)}</strong>
      </div>
      <div class="stat-item">
        <span>Dung lượng chỉ mục:</span>
        <strong>${fmtBytes(r.index_bytes)}</strong>
      </div>
      <div class="stat-item">
        <span>Độ chính xác:</span>
        <strong>Lặp ${r.A.n} &amp; ${r.B.n} lần (đã bù trừ round-trip)</strong>
      </div>
    </div>

    <div class="explain-grid">
      <div class="explain-col">
        <h4>Kế hoạch thực thi (EXPLAIN khi chưa đánh index)</h4>
        <pre>${esc(r.planA)}</pre>
      </div>
      <div class="explain-col">
        <h4>Kế hoạch thực thi (EXPLAIN với ${esc(algoLabel)})</h4>
        <pre>${esc(r.planB)}</pre>
      </div>
    </div>
  `;

  $('#resultsList').prepend(card);
}

// Xóa lịch sử kết quả
function clearResults() {
  runCount = 0;
  $('#resultsCount').textContent = 'Chưa có lượt chạy nào';
  $('#resultsList').innerHTML = `
    <div class="empty-state" id="emptyState">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M3 3v18h18"></path>
        <path d="m19 9-5 5-4-4-3 3"></path>
      </svg>
      <p>Chưa có dữ liệu đo lường.</p>
      <span>Hãy chọn thuật toán và bấm nút <strong>"Chạy kiểm thử so sánh"</strong> để đối chiếu hiệu năng.</span>
    </div>
  `;
}

// Khởi chạy ứng dụng
async function init() {
  $('#genBtn').onclick = generateData;
  $('#runBtn').onclick = runBenchmark;
  $('#clearBtn').onclick = clearResults;
  $('#algoSelect').onchange = updateSelectedAlgorithm;

  // Render 3 thuật toán vào dropdown
  $('#algoSelect').innerHTML = Object.entries(ALGORITHMS)
    .map(
      ([key, algo]) =>
        `<option value="${key}">${esc(algo.name)}</option>`
    )
    .join('');

  // Đọc config.json nếu có
  try {
    const configRes = await fetch('config.json');
    if (configRes.ok) {
      const extCfg = await configRes.json();
      CONFIG = { ...CONFIG, ...extCfg };
    }
  } catch (_) {}

  // Lấy dữ liệu meta từ backend
  try {
    const meta = await api('meta');
    ROWS = meta.rows || 0;
    BACKEND_SCENARIOS = meta.scenarios || {};

    // Cập nhật trạng thái DB và giao diện theo thuật toán đang chọn
    if (ROWS > 0) {
      updateDbBadge('ready', `${ROWS.toLocaleString('vi-VN')} dòng sẵn sàng`);
      setStatus('#genStatus', `Bảng hiện có ${ROWS.toLocaleString('vi-VN')} dòng sách.`);
    } else {
      updateDbBadge('ready', '0 dòng sách');
      setStatus('#genStatus', 'Chưa có dữ liệu. Hãy bấm "Tạo lại dữ liệu mẫu".');
    }

    updateSelectedAlgorithm();
    $('#genBtn').disabled = false;
  } catch (err) {
    updateDbBadge('error', 'Mất kết nối API');
    setStatus('#genStatus', 'Không kết nối được: ' + err.message, 'err');
    setStatus('#runStatus', 'Không thể chạy khi chưa kết nối API.', 'err');
  }
}

init();
