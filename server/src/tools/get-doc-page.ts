import type { IndexDatabase } from '../db/index.ts';
import { envelope, notFound } from '../envelope.ts';
import { coreModuleVersions, parseVersionNotes } from './search-docs.ts';
import type { ToolDefinition } from '../types.ts';

export function getDocPageTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_get_doc_page',
    description:
      'Прочитать проиндексированную страницу официальной документации по URL или пути. ' +
      'Текст возвращается порциями, а примечания «с версии» — структурированно.',
    inputSchema: {
      type: 'object',
      properties: {
        resource: { type: 'string', description: 'URL или пут вида pages/security/secure-cookies' },
        offset: { type: 'number', description: 'Символьное смещение' },
        length: { type: 'number', description: '200–1200 символов, по умолчанию 900' },
      },
      required: ['resource'],
    },
    handler: (args) => {
      const query = String(args.resource ?? '').trim();
      if (!query) return notFound(db, query, 'invalid_query');
      const normalized = query.replace(/^\/+/, '').replace(/\.md$/, '').replace(/\.html$/, '');
      const page = db.get<{
        url: string;
        section: string;
        title: string | null;
        content: string;
        fetched_at: string;
        version_mentions: string | null;
        source_type: string;
        source_ref: string | null;
        source_revision: string | null;
      }>(
        `SELECT url, section, title, content, fetched_at, version_mentions,
                source_type, source_ref, source_revision
         FROM doc_page
         WHERE url = ? OR url LIKE ? OR url LIKE ?
         ORDER BY CASE WHEN url = ? THEN 0 ELSE 1 END LIMIT 1`,
        [query, `%/${normalized}.html`, `%/${normalized}`, query],
      );
      if (!page) return notFound(db, query);

      const offset = Math.max(Number(args.offset ?? 0), 0);
      const length = Math.min(Math.max(Number(args.length ?? 900), 200), 1200);
      const content = page.content.slice(offset, offset + length);
      const next = offset + content.length;
      const notes = parseVersionNotes(page.version_mentions, coreModuleVersions(db));

      return envelope(db, {
        found: true,
        title: page.title,
        section: page.section,
        url: page.url,
        fetched_at: page.fetched_at,
        source: {
          type: page.source_type,
          ref: page.source_ref,
          revision: page.source_revision,
        },
        content,
        offset,
        next_offset: next < page.content.length ? next : null,
        total_length: page.content.length,
        truncated: next < page.content.length,
        version_notes: notes,
      });
    },
  };
}
