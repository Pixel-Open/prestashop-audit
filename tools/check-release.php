<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
if ($composer['license'] !== 'MIT' || $composer['require']['php'] !== '>=8.1') { throw new RuntimeException('Unexpected license or PHP requirement.'); }
if (!is_file($root . '/vendor/autoload.php')) { throw new RuntimeException('Generate the production autoloader before packaging.'); }
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') { continue; }
    $code = file_get_contents($file->getPathname());
    if (preg_match('~Pixel\\\\Module\\\\(?!Audit\\\\)~', $code)) { throw new RuntimeException('Application dependency in core: ' . $file->getPathname()); }
}
foreach (['README.md', 'AGENTS.md', 'LICENSE', 'CHANGELOG.md', 'CONTRIBUTING.md'] as $name) {
    if (!is_file($root . '/' . $name)) { throw new RuntimeException('Missing public document: ' . $name); }
}
foreach (['src', 'controllers', 'views', 'docs', 'translations', 'config', 'upgrade', 'examples', 'tests', '.github', 'pixel_audit.php', 'README.md', 'AGENTS.md', 'CHANGELOG.md', 'CONTRIBUTING.md'] as $path) {
    $files = is_dir($root . '/' . $path)
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $path, FilesystemIterator::SKIP_DOTS))
        : [new SplFileInfo($root . '/' . $path)];
    foreach ($files as $file) {
        if (!$file->isFile()) { continue; }
        $text = file_get_contents($file->getPathname());
        if (preg_match('/\x{2013}|\x{2014}/u', $text)) { throw new RuntimeException('Unicode dash in source/documentation: ' . $file->getPathname()); }
        if (str_contains($text, '/home/') || str_contains($text, '/var/www/')) { throw new RuntimeException('Host-specific path in public files.'); }
    }
}
const PACKAGED_DIRECTORIES = ['src', 'controllers', 'upgrade', 'config', 'views', 'translations', 'vendor'];
foreach (PACKAGED_DIRECTORIES as $directory) {
    $directories = [$root . '/' . $directory];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        if ($file->isDir()) { $directories[] = $file->getPathname(); }
    }
    foreach ($directories as $path) {
        if (!is_file($path . '/index.php')) { throw new RuntimeException('Missing index.php in packaged directory: ' . $path); }
    }
}
echo "Release structure validated\n";
