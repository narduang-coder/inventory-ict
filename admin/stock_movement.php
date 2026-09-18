<?php
// stock_movement.php - ລະບົບຕິດຕາມປະຫວັດການເຄື່ອນໄຫວອຸປະກອນ
require_once __DIR__ . '/../includes/config.php';

// ກວດສອບ Session

// ກວດສອບສິດການເຂົ້າເຖິງ
if (function_exists('checkRole')) {
    checkRole(['admin']);
} else if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit;
}

// ດຶງຂໍ້ມູນປະຫວັດທັງໝົດ (ໃຊ້ sm.serial_number ຈາກ Table stock_movements ເປັນຄ່າຫຼັກ)
$all_history_stmt = $pdo->prepare("
    SELECT sm.*, 
           COALESCE(sm.serial_number, i.serial_number) as display_serial_number,
           i.name as item_name, 
           i.item_code, 
           i.unit,
           u.fullname as created_by_name,
           l1.location_name as from_location_name,
           l2.location_name as to_location_name
    FROM stock_movements sm
    LEFT JOIN items i ON sm.item_id = i.id
    LEFT JOIN users u ON sm.created_by = u.id
    LEFT JOIN locations l1 ON sm.from_location_id = l1.id
    LEFT JOIN locations l2 ON sm.to_location_id = l2.id
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT 1000
");
$all_history_stmt->execute();
$all_history = $all_history_stmt->fetchAll(PDO::FETCH_ASSOC);

// ແຍກຂໍ້ມູນປະຫວັດຕາມປະເພດ (ສຳລັບ Quick Stats Card)
$receive_history  = array_values(array_filter($all_history, fn($h) => $h['movement_type'] === 'receive'));
$issue_history    = array_values(array_filter($all_history, fn($h) => $h['movement_type'] === 'issue'));
$transfer_history = array_values(array_filter($all_history, fn($h) => $h['movement_type'] === 'transfer'));
$return_history   = array_values(array_filter($all_history, fn($h) => $h['movement_type'] === 'return'));
$count_history    = array_values(array_filter($all_history, fn($h) => $h['movement_type'] === 'count'));
$dispose_history  = array_values(array_filter($all_history, fn($h) => $h['movement_type'] === 'dispose'));
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ປະຫວັດການເຄື່ອນໄຫວອຸປະກອນ - Stock Movement</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Noto Sans Lao', sans-serif; background-color: #f8fafc; color: #334155; }
        .badge { padding: 4px 12px; border-radius: 9999px; font-size: 11px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px; }
        .badge-green { background: #dcfce7; color: #15803d; }
        .badge-orange { background: #ffedd5; color: #c2410c; }
        .badge-purple { background: #f3e8ff; color: #6b21a8; }
        .badge-blue { background: #dbeafe; color: #1e40af; }
        .badge-teal { background: #ccfbf1; color: #0f766e; }
        .badge-red { background: #fee2e2; color: #b91c1c; }
        .badge-gray { background: #f1f5f9; color: #475569; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body class="min-h-screen py-6 px-4 sm:px-6 lg:px-8">

<div class="max-w-7xl mx-auto space-y-6">
    
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center text-xl shadow-inner">
                <i class="fas fa-history"></i>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-slate-800 tracking-tight">ປະຫວັດການເຄື່ອນໄຫວອຸປະກອນ</h1>
                <p class="text-xs sm:text-sm text-slate-500">ຕິດຕາມການ ຮັບເຂົ້າ, ເບີກຈ່າຍ, ຍ້າຍ, ຮັບຄືນ, ນັບ ແລະ ຊຳລະ/ສະສາງອຸປະກອນ</p>
            </div>
        </div>
    </div>

    <!-- Quick Stats Card -->
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3 no-print">
        <div class="bg-white p-4 rounded-xl border border-slate-100 shadow-sm flex items-center justify-between cursor-pointer hover:border-slate-300 transition" onclick="quickFilterType('all')">
            <div>
                <p class="text-xs font-medium text-slate-400">ທັງໝົດ</p>
                <p class="text-xl font-bold text-slate-700"><?php echo count($all_history); ?></p>
            </div>
            <div class="w-9 h-9 rounded-lg bg-slate-50 text-slate-500 flex items-center justify-center text-sm">
                <i class="fas fa-list"></i>
            </div>
        </div>
        <div class="bg-white p-4 rounded-xl border border-slate-100 shadow-sm flex items-center justify-between cursor-pointer hover:border-emerald-200 transition" onclick="quickFilterType('receive')">
            <div>
                <p class="text-xs font-medium text-slate-400">ຮັບເຂົ້າ/ເພີ່ມ</p>
                <p class="text-xl font-bold text-emerald-600"><?php echo count($receive_history); ?></p>
            </div>
            <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-500 flex items-center justify-center text-sm">
                <i class="fas fa-arrow-down"></i>
            </div>
        </div>
        <div class="bg-white p-4 rounded-xl border border-slate-100 shadow-sm flex items-center justify-between cursor-pointer hover:border-orange-200 transition" onclick="quickFilterType('issue')">
            <div>
                <p class="text-xs font-medium text-slate-400">ເບີກຈ່າຍ</p>
                <p class="text-xl font-bold text-orange-600"><?php echo count($issue_history); ?></p>
            </div>
            <div class="w-9 h-9 rounded-lg bg-orange-50 text-orange-500 flex items-center justify-center text-sm">
                <i class="fas fa-arrow-up"></i>
            </div>
        </div>
        <div class="bg-white p-4 rounded-xl border border-slate-100 shadow-sm flex items-center justify-between cursor-pointer hover:border-purple-200 transition" onclick="quickFilterType('transfer')">
            <div>
                <p class="text-xs font-medium text-slate-400">ຍົກຍ້າຍ</p>
                <p class="text-xl font-bold text-purple-600"><?php echo count($transfer_history); ?></p>
            </div>
            <div class="w-9 h-9 rounded-lg bg-purple-50 text-purple-500 flex items-center justify-center text-sm">
                <i class="fas fa-right-left"></i>
            </div>
        </div>
        <div class="bg-white p-4 rounded-xl border border-slate-100 shadow-sm flex items-center justify-between cursor-pointer hover:border-blue-200 transition" onclick="quickFilterType('return')">
            <div>
                <p class="text-xs font-medium text-slate-400">ຮັບຄືນ</p>
                <p class="text-xl font-bold text-blue-600"><?php echo count($return_history); ?></p>
            </div>
            <div class="w-9 h-9 rounded-lg bg-blue-50 text-blue-500 flex items-center justify-center text-sm">
                <i class="fas fa-rotate-left"></i>
            </div>
        </div>
        <div class="bg-white p-4 rounded-xl border border-slate-100 shadow-sm flex items-center justify-between cursor-pointer hover:border-rose-200 transition" onclick="quickFilterType('dispose')">
            <div>
                <p class="text-xs font-medium text-slate-400">ຊຳລະ/ສະສາງ</p>
                <p class="text-xl font-bold text-rose-600"><?php echo count($dispose_history); ?></p>
            </div>
            <div class="w-9 h-9 rounded-lg bg-rose-50 text-rose-500 flex items-center justify-center text-sm">
                <i class="fas fa-trash"></i>
            </div>
        </div>
    </div>

    <!-- Search Form -->
    <form id="searchForm" onsubmit="event.preventDefault(); applyFilter();" class="bg-white p-5 rounded-2xl border border-slate-100 shadow-sm space-y-4 no-print">
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">🔍 ຄົ້ນຫາ</label>
                <input type="text" id="searchInput" oninput="applyFilter()" placeholder="ຊື່, ລະຫັດ, Serial Number, ຜູ້ດຳເນີນ..." class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3.5 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">🏷️ ປະເພດ</label>
                <select id="historyFilter" onchange="applyFilter()" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3.5 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition cursor-pointer">
                    <option value="all">📋 ທັງໝົດ</option>
                    <option value="receive">📥 ຮັບເຂົ້າ / ເພີ່ມໃໝ່</option>
                    <option value="issue">📤 ເບີກຈ່າຍ</option>
                    <option value="transfer">🔄 ຍົກຍ້າຍອຸປະກອນ</option>
                    <option value="return">🔙 ຮັບຄືນ</option>
                    <option value="count">🧮 ນັບອຸປະກອນ / ແກ້ໄຂ</option>
                    <option value="dispose">🗑️ ຊຳລະ / ສະສາງ</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">📅 ແຕ່ວັນທີ</label>
                <input type="date" id="startDateInput" onchange="applyFilter()" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">📅 ຮອດວັນທີ</label>
                <input type="date" id="endDateInput" onchange="applyFilter()" class="w-full bg-slate-50 border border-slate-200 text-slate-700 text-sm rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition">
            </div>
        </div>
        <div class="flex items-center justify-between pt-2 border-t border-slate-100">
            <div class="flex items-center gap-2">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-xl text-xs font-semibold transition flex items-center gap-1.5 shadow-sm">
                    <i class="fas fa-search"></i> ຄົ້ນຫາ
                </button>
                <button type="button" onclick="resetFilters()" class="bg-slate-100 hover:bg-slate-200 text-slate-600 px-4 py-2 rounded-xl text-xs font-semibold transition flex items-center gap-1">
                    <i class="fas fa-rotate-right"></i> ລ້າງຄ່າ
                </button>
            </div>
            <div>
            <button type="button" onclick="printFilteredHistory()" class="bg-slate-800 hover:bg-slate-900 text-white px-4 py-2 rounded-xl text-xs font-medium transition shadow-sm hover:shadow flex items-center gap-1.5">
                <i class="fas fa-print"></i> ພິມລາຍງານທັງໝົດ
            </button> <br>
            <button onclick="printSelectedHistory()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-xs font-semibold transition flex items-center gap-1.5 shadow-sm">
                    <i class="fas fa-print"></i> ພິມສະເພາະລາຍການທີ່ເລືອກ
                </button>
                </div>
        </div>
    </form>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 space-y-4">
        
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <div class="flex items-center gap-2">
                <i class="fas fa-layer-group text-slate-400"></i>
                <h3 class="text-base font-bold text-slate-700">ຕາຕະລາງປະຫວັດ</h3>
            </div>
            <span class="text-xs font-semibold text-slate-500">
                ຈຳນວນ <strong id="totalFound" class="text-blue-600">0</strong> ລາຍການ
            </span>
        </div>

        <div id="historyContainer" class="overflow-x-auto"></div>

    </div>
</div>

<script>
const rawHistoryData = <?php echo json_encode($all_history, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let currentDisplayedData = [];

function resolveMovementType(item) {
    return item.movement_type || 'receive';
}

function applyFilter() {
    const searchVal = document.getElementById('searchInput').value.trim().toLowerCase();
    const typeVal = document.getElementById('historyFilter').value;
    const startDate = document.getElementById('startDateInput').value;
    const endDate = document.getElementById('endDateInput').value;

    currentDisplayedData = rawHistoryData.filter(item => {
        const itemName = (item.item_name || '').toLowerCase();
        const itemCode = (item.item_code || '').toLowerCase();
        const serialVal = (item.display_serial_number || '').toLowerCase();
        const createdBy = (item.created_by_name || '').toLowerCase();
        const note = (item.note || '').toLowerCase();
        const refNo = (item.reference_no || '').toLowerCase();

        const matchesSearch = !searchVal || 
            itemName.includes(searchVal) || 
            itemCode.includes(searchVal) || 
            serialVal.includes(searchVal) || 
            createdBy.includes(searchVal) || 
            note.includes(searchVal) ||
            refNo.includes(searchVal);

        const mType = resolveMovementType(item);
        const matchesType = (typeVal === 'all') || (mType === typeVal);

        let matchesDate = true;
        if (item.created_at) {
            const itemDate = item.created_at.substring(0, 10);
            if (startDate && itemDate < startDate) matchesDate = false;
            if (endDate && itemDate > endDate) matchesDate = false;
        }

        return matchesSearch && matchesType && matchesDate;
    });

    renderHistoryTable(currentDisplayedData);
}

function quickFilterType(type) {
    document.getElementById('historyFilter').value = type;
    applyFilter();
}

function resetFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('historyFilter').value = 'all';
    document.getElementById('startDateInput').value = '';
    document.getElementById('endDateInput').value = '';
    applyFilter();
}

function renderHistoryTable(data) {
    const container = document.getElementById('historyContainer');
    document.getElementById('totalFound').textContent = data.length;
    
    let html = `
        <table class="w-full text-sm text-left border-collapse" id="historyTable">
            <thead>
                <tr class="bg-slate-50 border-y border-slate-100 text-slate-500 font-semibold text-xs uppercase tracking-wider">
                    <th class="py-3 px-3 w-10 text-center">
                        <input type="checkbox" id="selectAll" onchange="toggleAllHistory()" class="rounded border-slate-300 text-blue-600 focus:ring-blue-500">
                    </th>
                    <th class="py-3 px-3">ວັນທີ-ເວລາ</th>
                    <th class="py-3 px-3">ປະເພດ</th>
                    <th class="py-3 px-3">ອຸປະກອນ</th>
                    <th class="py-3 px-3">Serial Number (S/N)</th>
                    <th class="py-3 px-3 text-right">ກ່ອນໜ້າ</th>
                    <th class="py-3 px-3 text-right">ຈຳນວນ</th>
                    <th class="py-3 px-3 text-right">ຍອດໃໝ່</th>
                    <th class="py-3 px-3">ສະຖານທີ່ / ພະແນກ</th>
                    <th class="py-3 px-3">ຜູ້ດຳເນີນ</th>
                    <th class="py-3 px-3">ໝາຍເຫດ</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 text-slate-600">
    `;
    
    if (data.length === 0) {
        html += `
            <tr>
                <td colspan="11" class="text-center py-12 text-slate-400">
                    <i class="fas fa-folder-open text-4xl mb-3 block text-slate-300"></i>
                    ບໍ່ມີຂໍ້ມູນປະຫວັດການເຄື່ອນໄຫວຕາມເງື່ອນໄຂທີ່ຄົ້ນຫາ
                </td>
            </tr>
        `;
    } else {
        data.forEach((item) => {
            const mType = resolveMovementType(item);

            const typeLabels = {
                'receive': { label: 'ຮັບເຂົ້າ / ເພີ່ມໃໝ່', class: 'badge-green', icon: 'fa-arrow-down' },
                'issue': { label: 'ເບີກຈ່າຍ', class: 'badge-orange', icon: 'fa-arrow-up' },
                'transfer': { label: 'ຍົກຍ້າຍອຸປະກອນ', class: 'badge-purple', icon: 'fa-right-left' },
                'return': { label: 'ຮັບຄືນ', class: 'badge-blue', icon: 'fa-rotate-left' },
                'count': { label: 'ນັບອຸປະກອນ / ແກ້ໄຂ', class: 'badge-teal', icon: 'fa-calculator' },
                'dispose': { label: 'ຊຳລະ / ສະສາງ', class: 'badge-red', icon: 'fa-trash' }
            };
            
            const type = typeLabels[mType] || { label: mType || 'ບັນທຶກທົ່ວໄປ', class: 'badge-gray', icon: 'fa-circle' };
            
            let locationInfo = '';
            const fromLoc = item.from_location_name || item.from_department;
            const toLoc = item.to_location_name || item.to_department;

            if (fromLoc && toLoc) {
                locationInfo = `${escapeHtml(fromLoc)} <i class="fas fa-arrow-right text-[10px] text-slate-300 mx-1"></i> ${escapeHtml(toLoc)}`;
            } else if (toLoc) {
                locationInfo = escapeHtml(toLoc);
            } else if (fromLoc) {
                locationInfo = escapeHtml(fromLoc);
            }
            
            const isAddition = mType === 'receive' || mType === 'return';
            const isDeduction = mType === 'issue' || mType === 'dispose';
            
            const qtyClass = isAddition ? 'text-emerald-600 font-semibold' : 
                             isDeduction ? 'text-rose-600 font-semibold' : 'text-purple-600 font-semibold';
            const qtyPrefix = isAddition ? '+' : isDeduction ? '-' : '';

            const serialNo = item.display_serial_number || '-';
            
            html += `
                <tr class="hover:bg-slate-50/80 transition cursor-pointer group" onclick="toggleRow(this, event)">
                    <td class="py-3 px-3 text-center" onclick="event.stopPropagation()">
                        <input type="checkbox" class="history-checkbox rounded border-slate-300 text-blue-600 focus:ring-blue-500" data-id="${item.id}" onchange="updateSelectAll()">
                    </td>
                    <td class="py-3 px-3 text-xs whitespace-nowrap text-slate-500 font-medium">${formatDate(item.created_at)}</td>
                    <td class="py-3 px-3">
                        <span class="badge ${type.class}">
                            <i class="fas ${type.icon} text-[10px]"></i> ${type.label}
                        </span>
                    </td>
                    <td class="py-3 px-3 font-medium text-slate-800">
                        ${escapeHtml(item.item_name || 'ບໍ່ລະບຸອຸປະກອນ')}
                        ${item.item_code ? `<span class="text-xs text-slate-400 block font-normal">#${escapeHtml(item.item_code)}</span>` : ''}
                    </td>
                    <td class="py-3 px-3 text-xs font-mono text-blue-600 font-medium">${escapeHtml(serialNo)}</td>
                    <td class="py-3 px-3 text-right text-xs text-slate-400 font-mono">${formatNumber(item.old_quantity ?? 0)}</td>
                    
                    <td class="py-3 px-3 text-right font-mono ${qtyClass}">${qtyPrefix}${formatNumber(item.quantity)}</td>
                    
                    <td class="py-3 px-3 text-right font-mono text-blue-600 font-semibold">${formatNumber(item.new_quantity ?? 0)}</td>
                    <td class="py-3 px-3 text-xs text-slate-500">${locationInfo || '-'}</td>
                    <td class="py-3 px-3 text-xs text-slate-500">${escapeHtml(item.created_by_name || 'Admin')}</td>
                    <td class="py-3 px-3 text-xs text-slate-400 max-w-[160px] truncate" title="${escapeHtml(item.note)}">${escapeHtml(item.note || '-')}</td>
                </tr>
            `;
        });
    }
    
    html += `
            </tbody>
        </table>
        <div class="mt-4 pt-3 flex flex-wrap justify-between items-center gap-3 no-print border-t border-slate-100">
            <div class="flex flex-wrap items-center gap-2">
                <button onclick="printSelectedHistory()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-xs font-semibold transition flex items-center gap-1.5 shadow-sm">
                    <i class="fas fa-print"></i> ພິມທີ່ເລືອກ
                </button>
                <button onclick="selectAllHistory()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-2 rounded-xl text-xs font-semibold transition">
                    ເລືອກທັງໝົດ
                </button>
                <button onclick="deselectAllHistory()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-2 rounded-xl text-xs font-semibold transition">
                    ຍົກເລີກ
                </button>
            </div>
            <span id="selectedCount" class="text-xs font-medium text-slate-400">ເລືອກ 0 ລາຍການ</span>
        </div>
    `;
    
    container.innerHTML = html;
    updateSelectedCount();
}

function formatDate(datetime) {
    if (!datetime) return '-';
    const d = new Date(datetime);
    if (isNaN(d.getTime())) return '-';
    return d.toLocaleDateString('lo-LA') + ' ' + d.toLocaleTimeString('lo-LA', {hour: '2-digit', minute: '2-digit'});
}

function formatNumber(num) {
    const val = Number(num);
    return isNaN(val) ? '0' : new Intl.NumberFormat('lo-LA').format(val);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function toggleAllHistory() {
    const checked = document.getElementById('selectAll').checked;
    document.querySelectorAll('.history-checkbox').forEach(cb => cb.checked = checked);
    updateSelectedCount();
}

function updateSelectAll() {
    const checkboxes = document.querySelectorAll('.history-checkbox');
    const checked = document.querySelectorAll('.history-checkbox:checked');
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.checked = checkboxes.length === checked.length && checkboxes.length > 0;
    }
    updateSelectedCount();
}

function updateSelectedCount() {
    const count = document.querySelectorAll('.history-checkbox:checked').length;
    const el = document.getElementById('selectedCount');
    if (el) el.textContent = `ເລືອກ ${count} ລາຍການ`;
}

function selectAllHistory() {
    document.querySelectorAll('.history-checkbox').forEach(cb => cb.checked = true);
    const selectAll = document.getElementById('selectAll');
    if (selectAll) selectAll.checked = true;
    updateSelectedCount();
}

function deselectAllHistory() {
    document.querySelectorAll('.history-checkbox').forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('selectAll');
    if (selectAll) selectAll.checked = false;
    updateSelectedCount();
}

function toggleRow(row, event) {
    if (event.target.tagName !== 'INPUT') {
        const checkbox = row.querySelector('.history-checkbox');
        if (checkbox) {
            checkbox.checked = !checkbox.checked;
            updateSelectAll();
        }
    }
}

function printFilteredHistory() {
    if (currentDisplayedData.length === 0) {
        Swal.fire({
            icon: 'info',
            title: 'ບໍ່ມີຂໍ້ມູນ',
            text: 'ບໍ່ມີຂໍ້ມູນປະຫວັດສຳລັບການພິມ',
            confirmColor: '#3b82f6'
        });
        return;
    }
    printHistoryData(currentDisplayedData, 'ລາຍງານປະຫວັດການເຄື່ອນໄຫວອຸປະກອນ');
}

function printSelectedHistory() {
    const selected = document.querySelectorAll('.history-checkbox:checked');
    if (selected.length === 0) {
        Swal.fire({
            icon: 'warning',
            title: 'ກະລຸນາເລືອກລາຍການ',
            text: 'ທ່ານຕ້ອງເລືອກຢ່າງໜ້ອຍ 1 ລາຍການກ່ອນພິມ',
            confirmColor: '#3b82f6'
        });
        return;
    }
    
    const selectedIds = Array.from(selected).map(cb => String(cb.dataset.id));
    const selectedData = currentDisplayedData.filter(item => selectedIds.includes(String(item.id)));
    
    printHistoryData(selectedData, 'ປະຫວັດການເຄື່ອນໄຫວອຸປະກອນ (ຕາມທີ່ເລືອກ)');
}

function printHistoryData(data, title) {
    const printWindow = window.open('', '_blank', 'width=1200,height=800');
    let tableRows = '';
    
    data.forEach((item, index) => {
        const mType = resolveMovementType(item);

        const typeLabels = {
            'receive': { label: 'ຮັບເຂົ້າ', class: 'badge-green' },
            'issue': { label: 'ເບີກຈ່າຍ', class: 'badge-orange' },
            'transfer': { label: 'ຍ້າຍ', class: 'badge-purple' },
            'return': { label: 'ຮັບຄືນ', class: 'badge-blue' },
            'count': { label: 'ນັບສິນຄ້າ', class: 'badge-teal' },
            'dispose': { label: 'ຊຳລະ/ສະສາງ', class: 'badge-red' }
        };
        const type = typeLabels[mType] || { label: mType || 'ທົ່ວໄປ', class: 'badge-gray' };
        
        let locationInfo = '';
        const fromLoc = item.from_location_name || item.from_department;
        const toLoc = item.to_location_name || item.to_department;

        if (fromLoc && toLoc) {
            locationInfo = fromLoc + ' → ' + toLoc;
        } else if (toLoc) {
            locationInfo = toLoc;
        } else if (fromLoc) {
            locationInfo = fromLoc;
        }
        
        const isAddition = mType === 'receive' || mType === 'return';
        const isDeduction = mType === 'issue' || mType === 'dispose';
        const qtyPrefix = isAddition ? '+' : isDeduction ? '-' : '';

        const serialNo = item.display_serial_number || '-';
        
        tableRows += `
            <tr>
                <td style="text-align:center;">${index + 1}</td>
                <td>${formatDate(item.created_at)}</td>
                <td><span class="badge ${type.class}">${type.label}</span></td>
                <td>${escapeHtml(item.item_name || '-')}</td>
                <td style="font-family:monospace;color:#2563eb;">${escapeHtml(serialNo)}</td>
                <td style="text-align:right;color:#64748b;">${formatNumber(item.old_quantity ?? 0)}</td>
                <td style="text-align:right;font-weight:bold;">${qtyPrefix}${formatNumber(item.quantity)}</td>
                <td style="text-align:right;font-weight:bold;color:#2563eb;">${formatNumber(item.new_quantity ?? 0)}</td>
                <td>${escapeHtml(locationInfo || '-')}</td>
                <td>${escapeHtml(item.created_by_name || 'Admin')}</td>
                <td>${escapeHtml(item.note || '-')}</td>
            </tr>
        `;
    });
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>${escapeHtml(title)}</title>
            <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@400;700&display=swap" rel="stylesheet">
            <style>
                body { font-family: 'Noto Sans Lao', sans-serif; padding: 20px; color: #1e293b; }
                .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; }
                .header h2 { margin: 0; font-size: 20px; color: #0f172a; }
                .header p { margin: 4px 0 0; font-size: 12px; color: #64748b; }
                table { width: 100%; border-collapse: collapse; font-size: 11px; }
                th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
                th { background: #f8fafc; font-weight: 700; color: #334155; }
                .badge { padding: 2px 6px; border-radius: 9999px; font-size: 10px; font-weight: 600; display: inline-block; }
                .badge-green { background: #dcfce7; color: #15803d; }
                .badge-orange { background: #ffedd5; color: #c2410c; }
                .badge-purple { background: #f3e8ff; color: #6b21a8; }
                .badge-blue { background: #dbeafe; color: #1e40af; }
                .badge-teal { background: #ccfbf1; color: #0f766e; }
                .badge-gray { background: #f1f5f9; color: #475569; }
                .badge-red { background: #fee2e2; color: #b91c1c; }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>${escapeHtml(title)}</h2>
                <p>ວັນທີພິມ: ${new Date().toLocaleDateString('lo-LA')} ${new Date().toLocaleTimeString('lo-LA')}</p>
                <p>ຈຳນວນລາຍການ: ${data.length}</p>
            </div>
            <table>
                <thead>
                    <tr>
                        <th style="text-align:center;width:35px;">ລຳ</th>
                        <th>ວັນທີ</th>
                        <th>ປະເພດ</th>
                        <th>ອຸປະກອນ</th>
                        <th>Serial Number</th>
                        <th style="text-align:right;">ກ່ອນໜ້າ</th>
                        <th style="text-align:right;">ຈຳນວນ</th>
                        <th style="text-align:right;">ຍອດໃໝ່</th>
                        <th>ສະຖານທີ່</th>
                        <th>ຜູ້ດຳເນີນ</th>
                        <th>ໝາຍເຫດ</th>
                    </tr>
                </thead>
                <tbody>${tableRows}</tbody>
            </table>
            <script>
                window.onload = function() {
                    setTimeout(function() {
                        window.print();
                        window.close();
                    }, 500);
                }
            <\/script>
        </body>
        </html>
    `);
    printWindow.document.close();
}

document.addEventListener('DOMContentLoaded', function() {
    applyFilter();
});
</script>

</body>
</html>

