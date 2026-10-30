import type { IndexDatabase } from './db/index.ts';
import { checkStaleness } from './staleness.ts';
import type { StalenessResult } from './staleness.ts';
import type { Envelope, NotFound, NotFoundReason, Provenance } from './types.ts';

/**
 * Сборка конверта ответа.
 *
 * Единственная точка, где создаётся `provenance`. Централизация не про
 * удобство: она делает невозможным ответ без версионной атрибуции, чего
 * требует NON-NEGOTIABLE Принцип II.
 */

export interface ModuleAttribution {
  module?: string;
  module_version?: string | null;
}

export function buildProvenance(
  db: IndexDatabase,
  attribution: ModuleAttribution = {},
  staleness?: StalenessResult,
): Provenance {
  const { stale, reason } = staleness ?? checkStaleness(db);

  const provenance: Provenance = {
    sm_version: db.snapshot.sm_version,
    snapshot_id: db.snapshotId,
    snapshot_role: db.snapshotRole,
    indexed_at: db.snapshot.indexed_at,
    stale,
    stale_reason: reason,
  };

  if (attribution.module !== undefined) {
    provenance.module = attribution.module;
    provenance.module_version = attribution.module_version ?? null;
    // Неизвестность обязана быть отличима от знания. Подставлять сюда
    // sm_version запрещено (Принцип II).
    provenance.version_unknown = attribution.module_version == null;
  }

  if (db.isUnusable) {
    provenance.index_unusable = true;
    provenance.index_unusable_reason = db.unusableReason;
  }

  return provenance;
}

export function envelope<T>(
  db: IndexDatabase,
  result: T,
  attribution: ModuleAttribution = {},
  staleness?: StalenessResult,
): Envelope<T> {
  return { provenance: buildProvenance(db, attribution, staleness), result };
}

/**
 * Штатный отказ.
 *
 * Это нормальный ответ, а не исключение (Принцип I). Похожие имена, если
 * они есть, уходят в отдельное поле с явной пометкой «догадка» — подсовывать
 * их как результат запрещено.
 */
export function notFound(
  db: IndexDatabase,
  query: string,
  reason: NotFoundReason = 'not_in_snapshot',
  didYouMean: string[] = [],
): Envelope<NotFound> {
  const result: NotFound = { found: false, reason, query };

  if (didYouMean.length > 0) {
    result.did_you_mean = { guess: true, names: didYouMean };
  }

  return envelope(db, result);
}
