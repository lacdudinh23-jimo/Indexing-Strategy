// Frontend Controller for MySQL Indexing Demo
// Xử lý toàn bộ tương tác người dùng, gọi API, theo dõi tiến trình và hiển thị trực quan kết quả đo lường.

/**
 * ============================================================================
 * FRONTEND EXECUTION FLOW:
 * ============================================================================
 * 1. Khởi tạo (init):
 *    - Gán sự kiện click cho các nút: Sinh dữ liệu (#genBtn), Chạy thử (#runBtn), Xoá kết quả (#clearBtn).
 *    - Nạp danh mục thuật toán và kỹ thuật từ `algorithms-data.js` vào dropdown #algoSelect.
 *    - Đọc cấu hình từ `config.json` (nếu có).
 *    - Gọi `api('meta')` để kiểm tra kết nối DB, lấy số dòng hiện có và nạp các kịch bản tương ứng.
 * 
 * 2. Thay đổi thuật toán (updateSelectedAlgorithm):
 *    - Đọc dữ liệu từ `ALGORITHMS` (trong algorithms-data.js) và render lý thuyết (Điểm mạnh / Hạn chế) vào #algoBody.
 *    - Nạp danh sách kịch bản (Scenarios) tương ứng vào dropdown #scn.
 * 
 * 3. Sinh dữ liệu mẫu (generateData):
 *    - Reset bảng books qua API action `reset`.
 *    - Lặp chèn dữ liệu theo khối (chunkSize = 50.000 dòng/lần) qua API action `gen`.
 *    - Cập nhật thanh tiến trình #progressBar theo từng lô cho tới khi đạt 100%.
 * 
 * 4. Chạy kiểm thử so sánh (runBenchmark):
 *    - Gửi yêu cầu đo lường (algo, scenario) qua API action `run`.
 *    - Nhận thời gian thực thi (A: Không index vs B: Có index), dung lượng index và EXPLAIN.
 *    - Gọi `renderBenchmarkResult()` để chèn card kết quả và biểu đồ thanh (bar visualizer) lên đầu danh sách.
 * ============================================================================
 */

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

// Dữ liệu thuật toán được nạp từ algorithms-data.js (fallback an toàn nếu chưa load)
const ALGOS_REF = typeof ALGORITHMS !== 'undefined' ? ALGORITHMS : {};

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
  const algo = ALGOS_REF[key] || ALGOS_REF.btree || {};

  // Cập nhật thẻ Knowledge
  $('#algoTitle').textContent = `Hiểu nhanh về ${algo.name || key}`;
  $('#algoBadge').textContent = algo.badge || 'Đang hoạt động';
  $('#algoBadge').style.background = 'var(--color-accent)';
  $('#algoBadge').style.color = '#0f172a';

  const prosList = (algo.pros || []).map((p) => `<li>${p}</li>`).join('');
  const consList = (algo.cons || []).map((c) => `<li>${c}</li>`).join('');

  $('#algoBody').innerHTML = `
    <p>${algo.summary || ''}</p>
    <div class="knowledge-grid">
      <div class="feature-box pros">
        <div class="feature-title">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
          Điểm mạnh
        </div>
        <ul>${prosList}</ul>
      </div>
      <div class="feature-box cons">
        <div class="feature-title">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          </svg>
          Hạn chế &amp; Chi phí
        </div>
        <ul>${consList}</ul>
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
  setStatus('#runStatus', 'Đang thực thi benchmark...', '');

  try {
    const res = await api('run', { algo: algoKey, scenario: scenarioKey });
    renderBenchmarkResult(res);
    setStatus(
      '#runStatus',
      res.metricType === 'join'
        ? 'Kiểm thử JOIN hoàn tất! Các bảng tạm đã được dọn dẹp.'
        : 'Kiểm thử hoàn tất! Index mẫu đã tự động được dọn dẹp.',
      'success'
    );
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
  const slower = ratio < 1; // phía B chậm hơn phía A (có thể xảy ra với MRR khi dữ liệu nằm hết trong RAM)
  const shown = slower ? 1 / ratio : ratio;
  const speedup = shown.toFixed(shown >= 10 ? 0 : 1);
  const labelA = r.labelA || 'Không Index (Full Scan)';
  const labelB = r.labelB || 'Có ' + (r.algo_name || 'Chỉ mục');
  const algoLabel = r.algo_name || 'Chỉ mục';
  const indexMetricsHtml = r.metricType === 'join'
    ? ''
    : Number.isFinite(r.build_msA)
      ? `
        <div class="stat-item"><span>Build index A:</span><strong>${fmtTime(r.build_msA)}</strong></div>
        <div class="stat-item"><span>Build index B:</span><strong>${fmtTime(r.build_msB)}</strong></div>
        <div class="stat-item"><span>INDEX_LENGTH A:</span><strong>${fmtBytes(r.index_bytesA)}</strong></div>
        <div class="stat-item"><span>INDEX_LENGTH B:</span><strong>${fmtBytes(r.index_bytesB)}</strong></div>
      `
      : `
        <div class="stat-item"><span>Thời gian build index:</span><strong>${fmtTime(r.build_ms)}</strong></div>
        <div class="stat-item"><span>Dung lượng chỉ mục:</span><strong>${fmtBytes(r.index_bytes)}</strong></div>
      `;
  const extraMetricsHtml = (r.metrics || []).map((metric) => `
    <div class="stat-item"><span>${esc(metric.label)}</span><strong>${esc(metric.value)}</strong></div>
  `).join('');

  // Hiển thị câu lệnh SQL: nếu 2 câu khác nhau (như Full-text: LIKE vs MATCH) thì so sánh rõ ràng
  let sqlDisplayHtml = '';
  if (r.sqlA && r.sqlB && r.sqlA !== r.sqlB) {
    sqlDisplayHtml = `
      <div class="sql-compare-box">
        <div class="sql-sub-box">
          <span class="sql-tag danger">${esc(r.labelA || 'Không Index (LIKE)')}:</span>
          <code>${esc(r.sqlA)}</code>
        </div>
        <div class="sql-sub-box">
          <span class="sql-tag success">${esc(r.labelB || 'Có Index (MATCH)')}:</span>
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
        <span class="speedup-lbl">${slower ? 'Chậm hơn' : 'Nhanh hơn'}</span>
      </div>
    </div>

    ${sqlDisplayHtml}

    <div class="benchmark-visualizer">
      <div class="bench-row">
        <span class="bench-label">${esc(labelA)}</span>
        <div class="bar-track">
          <div class="bar-fill danger" style="width: ${widthNoIndex}"></div>
        </div>
        <span class="bench-time danger">${fmtTime(timeNoIndex)}</span>
      </div>
      <div class="bench-row">
        <span class="bench-label">${esc(labelB)}</span>
        <div class="bar-track">
          <div class="bar-fill success" style="width: ${widthWithIndex}"></div>
        </div>
        <span class="bench-time success">${fmtTime(timeWithIndex)}</span>
      </div>
    </div>

    <div class="result-stats-row">
      ${indexMetricsHtml}
      ${extraMetricsHtml}
      <div class="stat-item">
        <span>Độ chính xác:</span>
        <strong>Lặp ${r.A.n} &amp; ${r.B.n} lần (đã bù trừ round-trip)</strong>
      </div>
      ${r.note ? `<div class="stat-item"><span>Ghi chú:</span><strong>${esc(r.note)}</strong></div>` : ''}
    </div>

    <div class="explain-grid">
      <div class="explain-col">
        <h4>${esc(r.planTitleA || 'Kế hoạch thực thi (EXPLAIN khi chưa đánh index)')}</h4>
        <pre>${esc(r.planA)}</pre>
      </div>
      <div class="explain-col">
        <h4>${esc(r.planTitleB || 'Kế hoạch thực thi (EXPLAIN với ' + algoLabel + ')')}</h4>
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

  // Render các thuật toán và kỹ thuật vào dropdown
  $('#algoSelect').innerHTML = Object.entries(ALGOS_REF)
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
