<?php
require_once __DIR__ . '/includes/config.php';


// 1. ລ້າງຂໍ້ມູນ Session ຕົວແປທັງໝົດ
$_SESSION = array();

// 2. ລຶບ Cookie ຂອງ Session ໃນ Browser
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. ທຳລາຍ Session
session_destroy();

// 4. Redirect ກັບໄປໜ້າ Login
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
}
session_destroy();
header('Location: index.php');
exit();
?>