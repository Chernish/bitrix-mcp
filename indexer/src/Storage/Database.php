<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Storage;

use PDO;

/**
 * Открытие файла индекса.
 *
 * Индексатор — единственный писатель. Настройки ниже выбраны под профиль
 * «массовая вставка один раз, затем только чтение».
 */
final class Database
{
    public static function open(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');
        // Массовая вставка: полная синхронизация на каждый коммит не нужна,
        // потому что индекс всегда можно пересобрать одной командой.
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA temp_store = MEMORY');
        $pdo->exec('PRAGMA cache_size = -64000');

        return $pdo;
    }

    /**
     * Создать индекс с нуля, удалив прежний файл.
     */
    public static function recreate(string $path): PDO
    {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }

        $pdo = self::open($path);
        Schema::create($pdo);

        return $pdo;
    }
}
