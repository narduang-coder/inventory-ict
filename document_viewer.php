<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
checkRole(['admin']);

function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $value = $bytes;
    $unitIndex = 0;

    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }

    return round($value, 2) . ' ' . $units[$unitIndex];
}

$uploadRoot = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads';
$documents = [];

if (is_dir($uploadRoot)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $ext = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
            continue;
        }

        $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($uploadRoot) + 1));

        $documents[] = [
            'name' => $file->getFilename(),
            'path' => $relativePath,
            'type' => $ext === 'pdf' ? 'PDF' : 'IMAGE',
            'size' => formatBytes($file->getSize()),
        ];
    }
}

usort($documents, static fn ($a, $b) => strcmp($a['name'], $b['name']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Viewer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/style.css">
    <script>
        function openDocModal(filePath) {
            const iframe = document.getElementById('docFrame');
            const modal = document.getElementById('docModal');
            iframe.src = 'view_document.php?file=' + encodeURIComponent(filePath);
            modal.classList.remove('hidden');
        }

        function closeDocModal() {
            const iframe = document.getElementById('docFrame');
            const modal = document.getElementById('docModal');
            iframe.src = '';
            modal.classList.add('hidden');
        }
    </script>
</head>
<body class="min-h-screen bg-slate-900 text-slate-100">
    <div class="mx-auto max-w-6xl px-4 py-10">
        <div class="mb-6">
            <h1 class="text-3xl font-bold text-emerald-400">Document Library</h1>
            <p class="mt-2 text-slate-300">Preview PDFs and images directly in the browser without downloading them.</p>
        </div>

        <div class="overflow-hidden rounded-2xl border border-slate-700 bg-slate-800 shadow-2xl">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-700 text-left">
                    <thead class="bg-slate-900">
                        <tr>
                            <th class="px-6 py-4 text-xs font-semibold uppercase tracking-wider text-emerald-400">Name</th>
                            <th class="px-6 py-4 text-xs font-semibold uppercase tracking-wider text-emerald-400">Type</th>
                            <th class="px-6 py-4 text-xs font-semibold uppercase tracking-wider text-emerald-400">Size</th>
                            <th class="px-6 py-4 text-xs font-semibold uppercase tracking-wider text-emerald-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        <?php if (empty($documents)): ?>
                            <tr>
                                <td colspan="4" class="px-6 py-10 text-center text-slate-400">
                                    No document files found in the upload folder.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($documents as $doc): ?>
                                <tr class="transition-colors hover:bg-slate-700/60">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-400">
                                                <?php echo strtolower($doc['type']) === 'pdf' ? 'PDF' : 'IMG'; ?>
                                            </span>
                                            <span class="font-medium text-slate-100"><?php echo htmlspecialchars($doc['name']); ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 text-slate-300"><?php echo htmlspecialchars($doc['type']); ?></td>
                                    <td class="px-6 py-4 text-slate-300"><?php echo htmlspecialchars($doc['size']); ?></td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <button
                                                type="button"
                                                onclick="openDocModal('<?php echo htmlspecialchars($doc['path']); ?>')"
                                                class="inline-flex items-center rounded-lg bg-emerald-500 px-3 py-2 text-sm font-medium text-slate-900 transition hover:bg-emerald-400"
                                            >
                                                Open in Modal
                                            </button>

                                            <a
                                                href="view_document.php?file=<?php echo urlencode($doc['path']); ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="inline-flex items-center rounded-lg border border-slate-600 bg-slate-700 px-3 py-2 text-sm font-medium text-slate-100 transition hover:bg-slate-600"
                                            >
                                                Open in New Tab
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="docModal" class="fixed inset-0 z-50 hidden bg-slate-950/80 backdrop-blur-sm">
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative w-full max-w-6xl overflow-hidden rounded-2xl border border-slate-700 bg-slate-900 shadow-2xl">
                <div class="flex items-center justify-between border-b border-slate-700 bg-slate-950 px-4 py-3">
                    <h2 class="text-lg font-semibold text-emerald-400">Document Preview</h2>
                    <button
                        type="button"
                        onclick="closeDocModal()"
                        class="rounded-lg border border-slate-600 bg-slate-800 px-3 py-1.5 text-sm text-slate-200 hover:bg-slate-700"
                    >
                        Close
                    </button>
                </div>

                <div class="p-3">
                    <iframe
                        id="docFrame"
                        class="h-[75vh] w-full rounded-xl border border-slate-700 bg-white"
                        title="Document Preview"
                        src=""
                    ></iframe>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
