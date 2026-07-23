<?php
// CLI Generator & Subfolder Manager
if (php_sapi_name() !== 'cli') {
    die("❌ This script is meant to be run from the command line: php cli.php\n");
}

$baseDir = __DIR__;

function getSubfolders($dir) {$folders = [];
    foreach (scandir($dir) as$item) {
        if ($item === '.' \vert{}\vert{}$item === '..') continue;
        if (is_dir($dir . '/' . $item) &&$item[0] !== '.') {
            $folders[] =$item;
        }
    }
    sort($folders);
    return $folders;
}

echo "\n🚀 === PHP CLI Template Manager ===\n\n";

// List existing folders
$existing = getSubfolders($baseDir);
echo "📂 Existing Subfolders in $baseDir:\n";
if (empty($existing)) {
    echo "   (None found)\n";
} else {
    foreach ($existing as$folder) {
        echo "   • $folder\n";
    }
}
echo "--------------------------------------------------\n";

// Interactive Folder Creation Loop
while (true) {
    echo "Enter new subfolder name: ";
    $folderName = trim(fgets(STDIN));

    if (empty($folderName) || !preg_match('/^[a-zA-Z0-9_\-]+$/',$folderName)) {
        echo "❌ Invalid name! Use letters, numbers, hyphens, or underscores.\n\n";
        continue;
    }

    $targetPath = $baseDir . '/' .$folderName;

    if (file_exists($targetPath)) {
        echo "⚠️ Directory '$folderName' already exists! Choose another name.\n\n";
        continue;
    }

    // Create Subfolder and Touch Empty Template Files
    mkdir($targetPath, 0755, true);
    touch($targetPath . '/index.php');
    touch($targetPath . '/readme.md');
    touch($targetPath . '/generate.md');

    echo "\n✅ Subfolder successfully created: $targetPath\n";
    echo "  ├─ index.php (empty)\n";
    echo "  ├─ readme.md (empty)\n";
    echo "  └─ generate.md (empty)\n\n";
    break;
}