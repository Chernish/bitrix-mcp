<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Core;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Читает версии модулей статическим разбором, без выполнения кода.
 *
 * Файлы версий выглядят как данные, и соблазн подключить их через include велик.
 * Принцип VI это запрещает: подключение привнесло бы в индексатор состояние и
 * побочные эффекты рабочего приложения и разрушило бы детерминированность
 * из Принципа V. Регулярные выражения тоже исключены — Принцип III требует
 * разбора для структурных фактов.
 *
 * Поддерживаются две формы, обе встречаются в целевом ядре:
 *   modules/<code>/install/version.php:            $arModuleVersion = ['VERSION' => '...'];
 *   modules/main/classes/general/version.php:      define("SM_VERSION", "...");
 *
 * Главный модуль — исключение по расположению: у него НЕТ install/version.php,
 * версия сборки объявлена константами в classes/general/version.php.
 * Проверено на целевом ядре: попытка читать main как обычный модуль давала
 * «версия неизвестна» для главного модуля и пустую версию сборки.
 */
final class ModuleVersionReader
{
    private readonly Parser $parser;
    private readonly NodeFinder $finder;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();
    }

    public function readBuildVersion(IndexRoot $root): ?BuildVersion
    {
        foreach ($this->buildVersionCandidates($root) as $path) {
            $stmts = $this->parseFile($path);

            if ($stmts === null) {
                continue;
            }

            $version = $this->findDefine($stmts, 'SM_VERSION');

            if ($version !== null) {
                return new BuildVersion($version, $this->findDefine($stmts, 'SM_VERSION_DATE'));
            }
        }

        return null;
    }

    public function readModule(IndexRoot $root, string $code): ModuleVersion
    {
        $relPath = 'modules/' . $code;

        // Главный модуль версионируется константами и в другом файле.
        if ($code === 'main') {
            $build = $this->readBuildVersion($root);

            return $build === null
                ? ModuleVersion::unknown($code, $relPath)
                : new ModuleVersion(
                    $code,
                    $build->smVersion,
                    $build->smVersionDate,
                    VersionSource::SmVersion,
                    $relPath,
                );
        }

        $stmts = $this->parseFile($root->path . '/' . $relPath . '/install/version.php');

        if ($stmts === null) {
            return ModuleVersion::unknown($code, $relPath);
        }

        $map = $this->findModuleVersionArray($stmts);

        if ($map === null || !isset($map['VERSION'])) {
            return ModuleVersion::unknown($code, $relPath);
        }

        return new ModuleVersion(
            $code,
            $map['VERSION'],
            $map['VERSION_DATE'] ?? null,
            VersionSource::ModuleFile,
            $relPath,
        );
    }

    /**
     * Карта версий всех модулей корня.
     *
     * Читается целиком при каждом прогоне, включая инкрементальный: она дёшева
     * (порядка сотни файлов) и критична для Принципа II. Оптимизировать её
     * через инкрементальность нельзя.
     *
     * @return array<string, ModuleVersion>
     */
    public function readAll(IndexRoot $root): array
    {
        $modulesDir = $root->path . '/modules';
        $entries = @scandir($modulesDir);

        if ($entries === false) {
            return [];
        }

        $result = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!is_dir($modulesDir . '/' . $entry)) {
                continue;
            }

            $result[$entry] = $this->readModule($root, $entry);
        }

        ksort($result);

        return $result;
    }

    /**
     * Известные расположения констант версии сборки, в порядке проверки.
     *
     * Первое — фактическое для целевого ядра. Второе оставлено как запасное
     * на случай иной раскладки поставки; это перечисление известных мест,
     * а не угадывание — при отсутствии обоих возвращается честный null.
     *
     * @return list<string>
     */
    private function buildVersionCandidates(IndexRoot $root): array
    {
        return [
            $root->path . '/modules/main/classes/general/version.php',
            $root->path . '/modules/main/install/version.php',
        ];
    }

    /** @return Node\Stmt[]|null */
    private function parseFile(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $code = @file_get_contents($path);

        if ($code === false) {
            return null;
        }

        try {
            return $this->parser->parse($code);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param Node\Stmt[] $stmts
     */
    private function findDefine(array $stmts, string $constant): ?string
    {
        /** @var Node\Expr\FuncCall[] $calls */
        $calls = $this->finder->find($stmts, static function (Node $node) use ($constant): bool {
            if (!$node instanceof Node\Expr\FuncCall) {
                return false;
            }

            if (!$node->name instanceof Node\Name || $node->name->toLowerString() !== 'define') {
                return false;
            }

            $first = $node->args[0] ?? null;

            return $first instanceof Node\Arg
                && $first->value instanceof Node\Scalar\String_
                && $first->value->value === $constant;
        });

        foreach ($calls as $call) {
            $second = $call->args[1] ?? null;

            if ($second instanceof Node\Arg && $second->value instanceof Node\Scalar\String_) {
                return $second->value->value;
            }
        }

        return null;
    }

    /**
     * @param Node\Stmt[] $stmts
     * @return array<string, string>|null
     */
    private function findModuleVersionArray(array $stmts): ?array
    {
        /** @var Node\Expr\Assign|null $assign */
        $assign = $this->finder->findFirst($stmts, static function (Node $node): bool {
            return $node instanceof Node\Expr\Assign
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'arModuleVersion'
                && $node->expr instanceof Node\Expr\Array_;
        });

        if ($assign === null || !$assign->expr instanceof Node\Expr\Array_) {
            return null;
        }

        $result = [];

        foreach ($assign->expr->items as $item) {
            if ($item->key instanceof Node\Scalar\String_ && $item->value instanceof Node\Scalar\String_) {
                $result[$item->key->value] = $item->value->value;
            }
        }

        return $result;
    }
}
