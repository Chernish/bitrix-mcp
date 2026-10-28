import Database from 'better-sqlite3';
import { existsSync } from 'node:fs';

import type { SnapshotRole } from '../types.ts';

/** Версия схемы, которую понимает этот сервер. Должна совпадать с Schema::VERSION индексатора. */
export const SUPPORTED_SCHEMA_VERSION = 9;

export interface Snapshot {
  sm_version: string | null;
  sm_version_date: string | null;
  indexed_at: string;
  indexer_version: string;
  schema_version: number;
  file_count: number;
  total_bytes: number;
  parse_error_count: number;
  short_open_tag_enabled: number;
  docs_crawled_at: string | null;
  docs_source: string | null;
  docs_source_revision: string | null;
}

export class SchemaMismatchError extends Error {}
export class IndexMissingError extends Error {}

export interface SnapshotSelection {
  snapshotId?: string | null;
  snapshotRole?: SnapshotRole;
}

/**
 * Доступ к индексу.
 *
 * Открывается СТРОГО в режиме только для чтения. Разделение писателя
 * (индексатор) и читателя (сервер) — это Принцип VI, и режим открытия
 * делает его нарушение невозможным, а не просто нежелательным.
 */
export class IndexDatabase {
  private readonly db: Database.Database;
  readonly snapshot: Snapshot;
  readonly snapshotId: string;
  readonly snapshotRole: SnapshotRole;
  readonly path: string;

  // Поле объявлено явно, а не через parameter property: режим strip-types
  // в Node их не поддерживает, а тесты запускаются именно им.
  constructor(path: string, selection: SnapshotSelection = {}) {
    this.path = path;

    if (!existsSync(path)) {
      throw new IndexMissingError(
        `Индекс не найден: ${path}\nПостройте его: make build`,
      );
    }

    this.db = new Database(path, { readonly: true, fileMustExist: true });

    const snapshot = this.db
      .prepare('SELECT * FROM snapshot WHERE id = 1')
      .get() as Snapshot | undefined;

    if (!snapshot) {
      throw new SchemaMismatchError(
        `Индекс ${path} не содержит снимка. Пересоберите: make build`,
      );
    }

    if (snapshot.schema_version !== SUPPORTED_SCHEMA_VERSION) {
      // Отказ, а не попытка прочитать «как получится»: неверно истолкованные
      // данные хуже отсутствующих, потому что выглядят достоверными.
      throw new SchemaMismatchError(
        `Несовместимая версия схемы индекса.\n` +
          `  файл ${path}: версия ${snapshot.schema_version}\n` +
          `  сервер понимает: версию ${SUPPORTED_SCHEMA_VERSION}\n` +
          `Пересоберите индекс: make build`,
      );
    }

    this.snapshot = snapshot;
    this.snapshotId = selection.snapshotId
      ?? `direct:${snapshot.sm_version ?? 'unknown'}:${snapshot.indexed_at}`;
    this.snapshotRole = selection.snapshotRole ?? 'direct';
  }

  /**
   * Индекс построен при выключенном short_open_tag и потому неполон.
   * Не «предупреждение», а признак непригодности: теряется 41% файлов ядра.
   */
  get isUnusable(): boolean {
    return this.snapshot.short_open_tag_enabled !== 1;
  }

  get unusableReason(): string | undefined {
    return this.isUnusable
      ? 'Индекс построен при выключенном short_open_tag: 41% файлов ядра потеряно молча. Пересоберите: make build'
      : undefined;
  }

  /** Путь корня ядра для runtime-проверок версий. */
  coreRoot(): string | null {
    const override = process.env.BITRIX_CORE_ROOT?.trim();
    if (override) return override;

    const row = this.db
      .prepare('SELECT path FROM index_root WHERE is_core = 1 LIMIT 1')
      .get() as { path: string } | undefined;

    return row?.path ?? null;
  }

  all<T = Record<string, unknown>>(sql: string, params: unknown[] = []): T[] {
    return this.db.prepare(sql).all(...params) as T[];
  }

  get<T = Record<string, unknown>>(sql: string, params: unknown[] = []): T | undefined {
    return this.db.prepare(sql).get(...params) as T | undefined;
  }

  count(sql: string, params: unknown[] = []): number {
    const row = this.db.prepare(sql).get(...params) as { c: number } | undefined;

    return row?.c ?? 0;
  }

  close(): void {
    this.db.close();
  }
}
