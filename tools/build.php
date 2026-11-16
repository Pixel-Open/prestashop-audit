<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require __DIR__ . '/check-release.php';
if (!class_exists(ZipArchive::class)) { throw new RuntimeException('The zip extension is required to build a release.'); }
$destination = $root . '/dist';
if (!is_dir($destination) && !mkdir($destination, 0775, true)) { throw new RuntimeException('Cannot create dist directory.'); }
$zip = new ZipArchive();
if ($zip->open($destination . '/pixel_audit.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Cannot create release archive.'); }
// Runtime files only: guides, contributor notes and the example module stay in the repository.
$files = ['pixel_audit.php', 'composer.json', 'composer.lock', 'README.md', 'LICENSE', 'CHANGELOG.md', 'logo.png', 'index.php'];
foreach (PACKAGED_DIRECTORIES as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) { $files[] = substr($file->getPathname(), strlen($root) + 1); }
    }
}
sort($files, SORT_STRING);
// Reproducible archive: with SOURCE_DATE_EPOCH, every entry gets that date and the same mode instead of the local ones.
$epoch = getenv('SOURCE_DATE_EPOCH');
if ($epoch !== false && !ctype_digit($epoch)) { throw new RuntimeException('SOURCE_DATE_EPOCH must be a Unix timestamp.'); }
if ($epoch !== false) { putenv('TZ=UTC'); }
foreach ($files as $file) {
    if (!is_file($root . '/' . $file)) { continue; }
    $name = 'pixel_audit/' . $file;
    if (!$zip->addFile($root . '/' . $file, $name)) { throw new RuntimeException('Cannot add release file: ' . $file); }
    if ($epoch !== false && (!$zip->setMtimeName($name, (int) $epoch) || !$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0100644 << 16))) {
        throw new RuntimeException('Cannot normalize release file: ' . $file);
    }
}
if (!$zip->close()) { throw new RuntimeException('Cannot finish release archive.'); }
echo "Built dist/pixel_audit.zip\n";
