<?php
require_once __DIR__ . '/includes/config.php';

// ກວດສອບວ່າເຂົ້າສູ່ລະບົບແລ້ວຫຼືບໍ່
if (isLoggedIn()) {
    if ($_SESSION['role'] == 'admin') {
        header('Location: admin.php?admin=dashboard');
    } else {
        header('Location: user.php?user=dashboard');
    }
    exit();
}

// ດຶງຂໍ້ມູນຝ່າຍ
$stmt = $pdo->prepare("SELECT * FROM settings LIMIT 1");
$stmt->execute();
$school = $stmt->fetch();

// ປະມວນຜົນຟອມເຂົ້າສູ່ລະບົບ
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'ຂໍ້ມູນບໍ່ປອດໄພ';
    } else {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '' || !enforceLoginRateLimit($username)) {
        $error = 'ຊື່ຜູ້ໃຊ້ ຫຼື ລະຫັດຜ່ານບໍ່ຖືກຕ້ອງ';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            clearFailedLogins($username);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['department'] = $user['department'] ?? '';
            $_SESSION['dept_id'] = $user['dept_id'] ?? null;
            logActivity("ຜູ້ໃຊ້ {$user['username']} ເຂົ້າສູ່ລະບົບ");
            header($user['role'] === 'admin' ? 'Location: admin.php?admin=dashboard' : 'Location: user.php?user=dashboard');
            exit();
        }
        recordFailedLogin($username);
        $error = 'ຊື່ຜູ້ໃຊ້ ຫຼື ລະຫັດຜ່ານບໍ່ຖືກຕ້ອງ';
    }
    }
}
?>
<!DOCTYPE html>
<html lang="lo">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ລະບົບຄຸ້ມຄອງສາງ ICT</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Lao:wght@100..900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Noto Sans Lao', sans-serif;
            background: linear-gradient(135deg, #cedfe8 0%, #cedfe8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            max-width: 480px;
            width: 100%;
        }
        .login-header {
            background: linear-gradient(180deg, #002B66 0%, #004B99 60%, #0284c7 100%);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }
        .login-body {
            padding: 30px;
        }
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            transition: all 0.3s;
            font-family: 'Noto Sans Lao', sans-serif;
            font-size: 16px;
        }
        .form-input:focus {
            border-color: #0284c7;
            outline: none;
            box-shadow: 0 0 0 4px rgba(2, 132, 199, 0.1);
        }
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #002B66 0%, #0284c7 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Noto Sans Lao', sans-serif;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(2, 132, 199, 0.35);
        }
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <?php $logoAsset = resolveLogoAssetPath($school['logo_path'] ?? ''); ?>
            <?php if ($logoAsset !== ''): ?>
                <img src="<?php echo htmlspecialchars(assetUrl($logoAsset)); ?>" alt="Logo" class="h-20 mx-auto mb-4 object-contain">
            <?php else: ?>
                <div class="text-6xl mb-4"></div>
            <?php endif; ?>
            <h1 class="text-2xl font-bold"><?php echo $school ? htmlspecialchars($school['school_name']) : 'ຝ່າຍ ICT'; ?></h1>
            <p class="text-white/80 text-sm mt-1">ລະບົບຄຸ້ມຄອງສາງ ICT</p>
        </div>
        
        <div class="login-body">
            <?php if ($error !== null): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">ຊື່ຜູ້ໃຊ້</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                            <i class="fas fa-user"></i>
                        </span>
                        <input type="text" name="username" class="form-input pl-12" placeholder="ປ້ອນຊື່ຜູ້ໃຊ້..." required>
                    </div>
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">ລະຫັດຜ່ານ</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" name="password" id="password" class="form-input pl-12" placeholder="ປ້ອນລະຫັດຜ່ານ..." required>
                        <button type="button" onclick="togglePassword()" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt mr-2"></i>
                    ເຂົ້າສູ່ລະບົບ
                </button>
            </form>
            
            <div class="mt-6 text-center text-sm text-gray-500">
                <p>ກະລຸນາປ້ອນຊື່ຜູ້ໃຊ້</p>
            </div>
        </div>
    </div>
    
    <script>
        function togglePassword() {
            const password = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            if (password.type === 'password') {
                password.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                password.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }
    </script>
</body>
</html>
