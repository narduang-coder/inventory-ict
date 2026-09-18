<?php
require_once __DIR__ . '/includes/config.php';
checkRole(['user']);

$page = isset($_GET['user']) ? $_GET['user'] : 'dashboard';
$user_id = $_SESSION['user_id'];

// ດຶງຂໍ້ມູນ ພະແນກ/ພາກສ່ວນ ຂອງຜູ້ໃຊ້ງານ
if (!isset($_SESSION['department'])) {
    $stmtUser = $pdo->prepare("SELECT department, fullname FROM users WHERE id = ?");
    $stmtUser->execute([$user_id]);
    $currUser = $stmtUser->fetch();
    
    if ($currUser) {
        $_SESSION['department'] = !empty($currUser['department']) ? $currUser['department'] : 'ບໍ່ລະບຸພະແນກ';
        if (!empty($currUser['fullname'])) {
            $_SESSION['fullname'] = $currUser['fullname'];
        }
    } else {
        $_SESSION['department'] = 'ບໍ່ລະບຸພະແນກ';
    }
}

$user_department = $_SESSION['department'];

// ດຶງຂໍ້ມູນຝ່າຍ / ໂຮງຮຽນ
$stmt = $pdo->query("SELECT * FROM settings LIMIT 1");
$school = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລະບົບຄຸ້ມຄອງສາງ ICT - ຜູ້ໃຊ້</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@100..900&display=swap" rel="stylesheet">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Noto Sans Lao', sans-serif; background: #f8fafc; color: #334155; }
        
        /* ປ່ຽນ Sidebar ເປັນ gradient ສີຟ້າ EDL Sky */
        .sidebar {
            background: linear-gradient(180deg, #002B66 0%, #004B99 60%, #0284c7 100%);
            min-height: 100vh;
            width: 260px;
            position: fixed;
            top: 0; left: 0;
            overflow-y: auto;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            box-shadow: 4px 0 20px rgba(0, 43, 102, 0.15);
        }
        .sidebar-item {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: #bae6fd;
            text-decoration: none;
            transition: all 0.2s ease;
            border-left: 4px solid transparent;
            font-weight: 500;
        }
        .sidebar-item:hover { background: rgba(255, 255, 255, 0.12); color: #ffffff; }
        .sidebar-item.active { background: rgba(255, 255, 255, 0.18); color: #ffffff; border-left-color: #f59e0b; font-weight: 600; }
        .sidebar-item.active i { color: #fbbf24; }
        .sidebar-item i { width: 24px; margin-right: 12px; font-size: 1.1rem; color: #7dd3fc; }
        .main-content { margin-left: 260px; padding: 28px; min-height: 100vh; transition: margin 0.3s; }
        
        .card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05);
            border: 1px solid #f1f5f9;
            padding: 24px;
            transition: all 0.3s ease;
        }
        .card:hover { box-shadow: 0 10px 35px -5px rgba(0,0,0,0.08); }

        .form-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            outline: none;
            transition: all 0.2s;
            background-color: #f8fafc;
            font-size: 0.95rem;
        }
        .form-input:focus {
            border-color: #0284c7;
            background-color: #ffffff;
            box-shadow: 0 0 0 4px rgba(2, 132, 199, 0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, #002B66 0%, #0284c7 100%);
            color: white;
            padding: 11px 24px;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.25);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(2, 132, 199, 0.35);
            background: linear-gradient(135deg, #001d45 0%, #0369a1 100%);
        }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-left: 0; padding: 16px; }
        }
    </style>
</head>
<body>
    <div class="sidebar" id="sidebar">
        <div class="p-6 text-center border-b border-white/10">
            <?php if ($school && !empty($school['logo_path']) && file_exists(assetPath($school['logo_path']))): ?>
                <img src="<?php echo htmlspecialchars(assetUrl($school['logo_path'])); ?>" alt="Logo" class="h-16 mx-auto mb-3 object-contain shadow-md">
            <?php endif; ?>
            <h1 class="text-lg font-bold text-white tracking-wide"><?php echo $school ? htmlspecialchars($school['school_name'], ENT_QUOTES, 'UTF-8') : 'ຝ່າຍ ICT'; ?></h1>
            <p class="text-xs text-sky-200 mt-1">ລະບົບຄຸ້ມຄອງສາງອຸປະກອນ</p>
        </div>
        
        <div class="p-4 border-b border-white/10">
            <div class="flex items-center text-white bg-white/10 p-3 rounded-xl border border-white/10">
                <div class="w-10 h-10 rounded-lg bg-gradient-to-r from-[#002B66] to-[#0284c7] flex items-center justify-center font-bold text-lg text-white shadow-sm">
                    <?php echo strtoupper(substr($_SESSION['fullname'] ?? 'U', 0, 1)); ?>
                </div>
                <div class="ml-3 overflow-hidden">
                    <p class="font-semibold text-sm truncate"><?php echo $_SESSION['fullname'] ?? 'ຜູ້ໃຊ້'; ?></p>
                    <span class="inline-block bg-sky-400/20 text-sky-200 text-[10px] px-2 py-0.5 rounded-full font-medium">
                        <?php echo htmlspecialchars($user_department); ?>
                    </span>
                </div>
            </div>
        </div>
        
        <nav class="p-4 space-y-1">
            <a href="?user=dashboard" class="sidebar-item <?php echo $page == 'dashboard' ? 'active' : ''; ?>">
                <i class="fas fa-chart-pie"></i> ໜ້າຫຼັກ
            </a>
            <a href="?user=request" class="sidebar-item <?php echo $page == 'request' ? 'active' : ''; ?>">
                <i class="fas fa-paper-plane"></i> ຂໍເບີກອຸປະກອນ
            </a>
            <a href="?user=myrequests" class="sidebar-item <?php echo $page == 'myrequests' ? 'active' : ''; ?>">
                <i class="fas fa-clock-rotate-left"></i> ປະຫວັດການເບີກ
            </a>
            <a href="user.php?user=items" class="sidebar-item <?php echo $page == 'items' ? 'active' : ''; ?>">
                <i class="fas fa-boxes-stacked"></i> ຈັດການອຸປະກອນ
            </a>
            <a href="?user=tsg_management" class="sidebar-item <?php echo $page == 'tsg_management' ? 'active' : ''; ?>">
                <i class="fas fa-boxes-stacked"></i> ຄຸ້ມຄອງ ທສກ ພະແນກ
            </a>
            <a href="?user=return" class="sidebar-item <?php echo $page == 'return' ? 'active' : ''; ?>">
                <i class="fas fa-rotate-left"></i> ສົ່ງອຸປະກອນຄືນ
            </a>
            
            <div class="pt-6 border-t border-white/10 mt-6">
                <a href="logout.php" class="sidebar-item text-rose-300 hover:text-white hover:bg-rose-500/20 rounded-xl">
                    <i class="fas fa-right-from-bracket text-rose-300"></i> ອອກຈາກລະບົບ
                </a>
            </div>
        </nav>
    </div>
    
    <button onclick="toggleSidebar()" class="fixed top-4 left-4 z-50 lg:hidden bg-white text-slate-700 p-3 rounded-2xl shadow-lg border border-slate-100 hover:bg-slate-50">
        <i class="fas fa-bars text-xl"></i>
    </button>
    
    <div class="main-content">
    <?php
    switch($page) {
        case 'dashboard': 
            include USER_PATH . 'dashboard.php'; 
            break;
        case 'request': 
            include USER_PATH . 'request.php'; 
            break;
        case 'myrequests': 
            include USER_PATH . 'my_requests.php'; 
            break;
        case 'items': 
            include USER_PATH . 'items.php'; 
            break;
        case 'tsg_management': 
            include USER_PATH . 'tsg_management.php'; 
            break;
        case 'return': 
            include USER_PATH . 'returns.php'; 
            break;
        default: 
            include USER_PATH . 'dashboard.php';
    }
    ?>
    </div>
    
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('[onclick="toggleSidebar()"]');
            if (window.innerWidth <= 768 && sidebar.classList.contains('open') && 
                !sidebar.contains(e.target) && !toggle.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    </script>
</body>
</html>
