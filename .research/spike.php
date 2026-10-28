<?php
/**
 * Исследовательский прогон для plan.md — НЕ часть поставки.
 * Проверяет: (1) поведение php-parser на коротких тегах, (2) реальную
 * пропускную способность и долю ошибок разбора на ядре Битрикса.
 */
require __DIR__ . '/vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

$core = '/Users/sergejcernomordov/stroka/bp/www/bitrix/modules';
$mode = $argv[1] ?? 'bench';
$limit = (int)($argv[2] ?? 2000);

$parser = (new ParserFactory())->createForNewestSupportedVersion();

if ($mode === 'shorttag') {
    echo 'short_open_tag = ', ini_get('short_open_tag') ? 'On' : 'Off', PHP_EOL;
    $code = "<?\nclass Foo { public function bar(int \$x): string { return 'a'; } }\n";
    try {
        $stmts = $parser->parse($code);
        $classes = 0;
        $traverser = new NodeTraverser();
        $finder = new class extends PhpParser\NodeVisitorAbstract {
            public int $count = 0;
            public function enterNode(PhpParser\Node $n) {
                if ($n instanceof PhpParser\Node\Stmt\Class_) { $this->count++; }
                return null;
            }
        };
        $traverser->addVisitor($finder);
        $traverser->traverse($stmts);
        echo 'top-level stmts: ', count($stmts), PHP_EOL;
        echo 'classes found:   ', $finder->count, PHP_EOL;
        echo 'first node type: ', $stmts ? get_class($stmts[0]) : '(none)', PHP_EOL;
    } catch (\Throwable $e) {
        echo 'PARSE ERROR: ', $e->getMessage(), PHP_EOL;
    }
    exit(0);
}

// --- benchmark ---
$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($core, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
        $files[] = $f->getPathname();
        if (count($files) >= $limit) { break; }
    }
}

$ok = 0; $fail = 0; $emptyShort = 0; $bytes = 0; $classes = 0;
$errors = [];
$t0 = microtime(true);

foreach ($files as $path) {
    $code = @file_get_contents($path);
    if ($code === false) { $fail++; continue; }
    $bytes += strlen($code);
    // признак короткого открывающего тега в начале файла
    $isShort = preg_match('/^<\?(?!php|=)/', ltrim($code)) === 1;
    try {
        $stmts = $parser->parse($code);
        if ($stmts === null) { $fail++; continue; }
        $trav = new NodeTraverser();
        $trav->addVisitor(new NameResolver());
        $finder = new class extends PhpParser\NodeVisitorAbstract {
            public int $count = 0;
            public function enterNode(PhpParser\Node $n) {
                if ($n instanceof PhpParser\Node\Stmt\ClassLike) { $this->count++; }
                return null;
            }
        };
        $trav->addVisitor($finder);
        $trav->traverse($stmts);
        $classes += $finder->count;
        // короткий тег, распознанный как inline HTML => всё содержимое потеряно
        if ($isShort && count($stmts) === 1 && $stmts[0] instanceof PhpParser\Node\Stmt\InlineHTML) {
            $emptyShort++;
        }
        $ok++;
    } catch (\Throwable $e) {
        $fail++;
        if (count($errors) < 8) {
            $errors[] = basename($path) . ': ' . substr($e->getMessage(), 0, 90);
        }
    }
}

$dt = microtime(true) - $t0;
$n = count($files);
printf("short_open_tag:      %s\n", ini_get('short_open_tag') ? 'On' : 'Off');
printf("файлов разобрано:    %d\n", $n);
printf("успешно:             %d (%.2f%%)\n", $ok, $n ? $ok / $n * 100 : 0);
printf("ошибок разбора:      %d (%.2f%%)\n", $fail, $n ? $fail / $n * 100 : 0);
printf("коротких тегов съед: %d  <-- молчаливая потеря данных\n", $emptyShort);
printf("классов найдено:     %d\n", $classes);
printf("объём:               %.1f МБ\n", $bytes / 1048576);
printf("время:               %.2f с\n", $dt);
printf("скорость:            %.0f файл/с, %.1f МБ/с\n", $n / $dt, $bytes / 1048576 / $dt);
printf("экстраполяция 67836: %.0f с в один поток\n", 67836 / ($n / $dt));
if ($errors) {
    echo "примеры ошибок:\n";
    foreach ($errors as $e) { echo "  - $e\n"; }
}
