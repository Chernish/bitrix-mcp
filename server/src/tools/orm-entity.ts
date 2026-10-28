import type { IndexDatabase } from '../db/index.ts';
import { lookupSymbol } from '../db/symbols.ts';
import { envelope, notFound } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

interface RawEntity {
  id: number;
  table_name: string | null;
  table_name_source: string;
  extraction_style: string;
  is_complete: number;
  incomplete_reason: string | null;
  fqn: string;
  module: string;
  module_version: string | null;
  rel_path: string;
  line: number;
}

interface RawField {
  name: string;
  data_type: string | null;
  is_primary: number;
  is_required: number;
  is_autocomplete: number;
  default_repr: string | null;
  is_default_resolved: number;
  reference_target: string | null;
  join_condition: string | null;
  values_repr: string | null;
}

/**
 * Поле в выдаче.
 *
 * Признаки упакованы в одну строку `flags` вместо трёх булевых ключей.
 * У сущности бывает под сотню полей, и `"primary":true,"autocomplete":true`
 * на каждом съедает бюджет ответа из SC-007 быстрее, чем сами данные.
 * Контрактный тест №2 поймал это на первом же прогоне нового инструмента.
 *
 * Значения flags: `pk` — первичный ключ, `req` — обязательное,
 * `auto` — автоинкремент, `?default` — значение задано замыканием
 * и статически не вычислимо.
 */
interface FieldOut {
  name: string;
  type?: string;
  flags?: string;
  default?: string;
  reference?: { target: string | null; join: string | null };
  values?: string;
}

const SELECT_ENTITY = `
  SELECT o.id, o.table_name, o.table_name_source, o.extraction_style,
         o.is_complete, o.incomplete_reason,
         s.fqn, s.line, m.code AS module, m.version AS module_version, f.rel_path
  FROM orm_entity o
  JOIN symbol s ON s.id = o.symbol_id
  JOIN module m ON m.id = s.module_id
  JOIN file   f ON f.id = s.file_id
`;

export function ormEntityTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_get_orm_entity',
    description:
      'Получить ORM-схему сущности Битрикса: имя таблицы, поля, типы, обязательность, связи. ' +
      'Принимает имя класса или имя таблицы. ' +
      'Если карта полей извлечена не полностью, ответ содержит is_complete: false и причину — ' +
      'частичная схема НИКОГДА не выдаётся за полную.',
    inputSchema: {
      type: 'object',
      properties: {
        entity: {
          type: 'string',
          description: 'Имя класса (IblockTable, \\Bitrix\\Iblock\\IblockTable) или имя таблицы (b_iblock)',
        },
        include_references: {
          type: 'boolean',
          description: 'Включить описание связей, по умолчанию true',
        },
        limit: { type: 'number', description: 'Максимум полей, по умолчанию 20; выдача страничная через offset' },
        offset: { type: 'number', description: 'Смещение для следующей порции' },
      },
      required: ['entity'],
    },
    handler: (args) => {
      const query = String(args.entity ?? '');
      const entity = resolveEntity(db, query);

      if (!entity) {
        return notFound(db, query, 'not_in_snapshot');
      }

      const limit = Number(args.limit ?? 20);
      const offset = Number(args.offset ?? 0);
      const withRefs = args.include_references !== false;

      const total = db.count('SELECT COUNT(*) AS c FROM orm_field WHERE orm_entity_id = ?', [
        entity.id,
      ]);

      const raw = db.all<RawField>(
        `SELECT name, data_type, is_primary, is_required, is_autocomplete,
                default_repr, is_default_resolved, reference_target, join_condition, values_repr
         FROM orm_field WHERE orm_entity_id = ? ORDER BY position LIMIT ? OFFSET ?`,
        [entity.id, limit, offset],
      );

      const fields: FieldOut[] = raw.map((f) => {
        const out: FieldOut = { name: f.name };

        if (f.data_type !== null) out.type = f.data_type;

        const flags: string[] = [];
        if (f.is_primary === 1) flags.push('pk');
        if (f.is_required === 1) flags.push('req');
        if (f.is_autocomplete === 1) flags.push('auto');

        // Ключевое различие: «значения нет» и «значение вычисляется замыканием»
        // — разные состояния, и агент обязан их видеть (Принцип III).
        if (f.is_default_resolved === 0) flags.push('?default');

        if (flags.length > 0) out.flags = flags.join(',');
        if (f.default_repr !== null) out.default = f.default_repr;

        if (withRefs && (f.reference_target || f.join_condition)) {
          out.reference = { target: f.reference_target, join: f.join_condition };
        }

        if (f.values_repr !== null) out.values = f.values_repr;

        return out;
      });

      return envelope(
        db,
        {
          found: true,
          fqn: entity.fqn,
          table_name: entity.table_name,
          table_name_source: entity.table_name_source,
          extraction_style: entity.extraction_style,
          is_complete: entity.is_complete === 1,
          incomplete_reason: entity.incomplete_reason,
          file: entity.rel_path,
          line: entity.line,
          fields,
          total,
          truncated: offset + fields.length < total,
        },
        { module: entity.module, module_version: entity.module_version },
      );
    },
  };
}

/**
 * Поиск сущности по имени класса или по имени таблицы.
 *
 * Имя таблицы — полноценный путь входа: агент нередко видит `b_iblock`
 * в запросе или в логе и не знает, какому классу оно принадлежит.
 */
function resolveEntity(db: IndexDatabase, query: string): RawEntity | undefined {
  const byTable = db.get<RawEntity>(`${SELECT_ENTITY} WHERE o.table_name = ? LIMIT 1`, [query]);

  if (byTable) return byTable;

  const { symbols } = lookupSymbol(db, query, { limit: 1 });
  const symbol = symbols[0];

  if (!symbol) return undefined;

  return db.get<RawEntity>(`${SELECT_ENTITY} WHERE s.fqn = ? LIMIT 1`, [symbol.canonical_fqn]);
}
