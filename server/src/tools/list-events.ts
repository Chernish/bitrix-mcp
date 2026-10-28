import type { IndexDatabase } from '../db/index.ts';
import { envelope, notFound } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

interface RawEvent {
  name: string;
  kind: string;
  call_style: string;
  rel_path: string;
  line: number;
  params_repr: string | null;
  occurrences: number;
}

/**
 * Событие в выдаче.
 *
 * `style` (GetModuleEvents против new Event) и `params` по умолчанию НЕ
 * отдаются: агенту нужно имя события и где оно бросается, а способ вызова
 * и текст параметров интересны лишь при углублении. Оба доступны через
 * `include_details`. Контрактный тест №2 поймал раздутую выдачу
 * на первом прогоне инструмента — 4541 символ при бюджете 2000.
 */
interface EventOut {
  name: string;
  /** Сколько точек вызова у события. Больше одной — норма. */
  occurrences: number;
  file: string;
  line: number;
  style?: string;
  params?: string;
}

export function listEventsTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_list_events',
    description:
      'Список событий модуля Битрикса с точками вызова, файлом и строкой. ' +
      'kind=send — события, которые модуль бросает (на них можно подписаться). ' +
      'kind=register — на что модуль сам подписан. ' +
      'Это разные вопросы, и списки не смешиваются.',
    inputSchema: {
      type: 'object',
      properties: {
        module: { type: 'string', description: 'Код модуля, например sale или main' },
        kind: {
          type: 'string',
          enum: ['send', 'register', 'any'],
          description: 'По умолчанию send — события, которые модуль бросает',
        },
        name_filter: { type: 'string', description: 'Подстрока в имени события' },
        limit: { type: 'number', description: 'Максимум событий, по умолчанию 15' },
        include_details: {
          type: 'boolean',
          description: 'Добавить способ вызова и параметры. По умолчанию выключено ради экономии контекста',
        },
        offset: { type: 'number', description: 'Смещение для следующей порции' },
      },
      required: ['module'],
    },
    handler: (args) => {
      const moduleCode = String(args.module ?? '');
      const kind = String(args.kind ?? 'send');
      const limit = Number(args.limit ?? 15);
      const withDetails = args.include_details === true;
      const offset = Number(args.offset ?? 0);
      const nameFilter = args.name_filter ? String(args.name_filter).toLowerCase() : null;

      const moduleRow = db.get<{ code: string; version: string | null }>(
        'SELECT code, version FROM module WHERE code = ? LIMIT 1',
        [moduleCode],
      );

      if (!moduleRow) {
        return notFound(db, moduleCode, 'not_in_snapshot');
      }

      const where: string[] = ['owner_module = ?'];
      const params: unknown[] = [moduleCode];

      if (kind !== 'any') {
        where.push('kind = ?');
        params.push(kind);
      }

      if (nameFilter) {
        where.push('name_lower LIKE ?');
        params.push('%' + nameFilter + '%');
      }

      const clause = where.join(' AND ');

      // Группировка по имени: одно событие нередко вызывается из нескольких
      // мест, и список из двадцати строк с одинаковым именем бесполезен.
      // Число точек вызова сохраняется отдельным полем.
      const total = db.count(
        `SELECT COUNT(*) AS c FROM (SELECT name FROM event WHERE ${clause} GROUP BY name_lower)`,
        params,
      );

      const raw = db.all<RawEvent>(
        `SELECT name, kind, call_style, line, params_repr,
                COUNT(*) AS occurrences,
                (SELECT f.rel_path FROM file f WHERE f.id = event.file_id) AS rel_path
         FROM event
         WHERE ${clause}
         GROUP BY name_lower
         ORDER BY name
         LIMIT ? OFFSET ?`,
        [...params, limit, offset],
      );

      const events: EventOut[] = raw.map((e) => {
        const out: EventOut = {
          name: e.name,
          occurrences: e.occurrences,
          file: e.rel_path,
          line: e.line,
        };

        if (withDetails) {
          out.style = e.call_style;
          if (e.params_repr) out.params = e.params_repr;
        }

        return out;
      });

      return envelope(
        db,
        {
          found: true,
          module: moduleCode,
          kind,
          events,
          total,
          truncated: offset + events.length < total,
        },
        { module: moduleRow.code, module_version: moduleRow.version },
      );
    },
  };
}
