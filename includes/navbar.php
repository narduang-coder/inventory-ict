<?php
// Optional shared navigation for standalone admin SCT pages.
$home = function_exists('isAdmin') && isAdmin() ? '../admin.php?admin=dashboard' : '../index.php';
?>
<nav class="bg-[#002B66] text-white shadow-sm">
    <div class="container mx-auto px-4 py-3 flex items-center justify-between">
        <a href="<?php echo htmlspecialchars($home); ?>" class="font-semibold">ICT Inventory</a>
        <a href="../logout.php" class="text-sm text-slate-200 hover:text-white">Logout</a>
    </div>
</nav>
