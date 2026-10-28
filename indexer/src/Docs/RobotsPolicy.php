<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Docs;

/**
 * Разбор `robots.txt` и проверка допустимости URL.
 *
 * Уважение к чужому сайту — не формальность, а требование раздела «Рабочий
 * процесс и ограничения» конституции. На целевом домене запрещён только
 * `/_search/`, но полагаться на это знание вместо чтения файла нельзя:
 * правила меняются, а краулер обязан подчиняться текущим.
 *
 * При недоступности `robots.txt` обход НЕ запрещается целиком: отсутствие
 * файла по соглашению означает отсутствие ограничений. А вот ошибка разбора
 * трактуется консервативно — как запрет.
 */
final class RobotsPolicy
{
    /** @param list<string> $disallowed префиксы путей */
    private function __construct(
        private readonly array $disallowed,
        public readonly bool $loaded,
    ) {
    }

    public static function permissive(): self
    {
        return new self([], false);
    }

    public static function parse(string $content): self
    {
        $disallowed = [];
        $appliesToUs = false;

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');

            if ($line === '') {
                continue;
            }

            $parts = explode(':', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $directive = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            if ($directive === 'user-agent') {
                // Учитываем только секцию для всех: собственного имени
                // в robots.txt у нас нет и быть не должно.
                $appliesToUs = $value === '*';
                continue;
            }

            if ($directive === 'disallow' && $appliesToUs && $value !== '') {
                $disallowed[] = $value;
            }
        }

        return new self($disallowed, true);
    }

    public function allows(string $path): bool
    {
        foreach ($this->disallowed as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    public function disallowed(): array
    {
        return $this->disallowed;
    }
}
