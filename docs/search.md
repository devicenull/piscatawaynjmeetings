# Site search

Full-text search over meeting minutes, transcripts, AI meeting summaries, bids,
budgets/audits/financial statements, campaign finance reports, newsletters and
tweets, served at `/search.php`. Backed by [Meilisearch](https://www.meilisearch.com/)
running on each host, bound to `127.0.0.1:7700` only.

## How it fits together

```
pre-prod (has web/files + MySQL)
  scripts/build_search_docs.php  →  data/search_docs.ndjson
  scripts/search_cli.php load    →  pre-prod's Meilisearch
  rsync the .ndjson file         →  prod
  ssh prod 'php scripts/search_cli.php load ...'  →  prod's Meilisearch
```

All of this runs from `deploy.sh`. Remote Meilisearch instances are only ever
touched by `search_cli.php` run **on that host over ssh**, never by tunnelling
to the HTTP API. The index is disposable: every load builds `docs_new`, checks
the document count, then atomically swaps it with `docs`. A failed build or load
leaves the previous index live.

| File | Purpose |
|------|---------|
| `scripts/build_search_docs.php` | Extracts text into NDJSON (pre-prod only, needs `web/files`). `--selftest` checks the transcript chunker and PDF page splitter. |
| `scripts/search_cli.php` | `load <file>`, `stats`, `tasks`, `search <query>` against the local instance. Index settings (ranking, synonyms, typo rules) live here. |
| `classes/Search.php` | `Search::query()` used by the web page and `search_cli.php search`. |
| `web/search.php`, `templates/search.html` | Search page. `?format=json` returns the same results as JSON (intended for a future MCP server). |
| `meilisearch.service` | systemd unit for pre-prod/prod (dev runs Meilisearch in Docker). |

## What gets indexed

Only content registered in MySQL (via `import_files.php`) is indexed. Loose files
directly in `web/files/` are not.

| Source | One record per | Links to |
|--------|----------------|----------|
| Meeting minutes (PDF) | page | `/files/...pdf#page=N` |
| Meeting minutes / bids (`.doc`, `.docx`) | file | the file |
| Transcripts | ~2 minutes of speech (`TRANSCRIPT_CHUNK_SECONDS`) | `/{type}/meeting/{date}#t=SECONDS` |
| AI summary sections (`web/files/{type}/{date}.json`) | section | same, at the section start |
| Bids, newsletters, misc files, redevelopment studies, campaign files (PDF) | page | `#page=N` |
| Tweets from non-hidden `twitter_user`s | tweet | archive.org copy |

Not indexed: recordings (the transcripts cover them), CAD calls (Datasette has
them). PDFs without a text layer are logged as `No text:` during the build — run
them through `ocrmypdf` like `import_files.php` does.

Every record has `doc` (the parent file/transcript). It's the index's
`distinctAttribute`, so a search returns each file once, represented by its best
matching page or section. Transcripts and their summaries share a `doc`.

## Ranking

Meilisearch ranks in tiers: `words, typo, proximity, attribute, sort, exactness`,
then the custom `rank:desc` (per-kind priority, `RANK` in `build_search_docs.php`)
and `date:desc`. The last two only break ties between equally good text matches.
Title matches beat speaker-name matches, which beat body matches
(`searchableAttributes` order). Typo tolerance is on, except for numbers
(ordinance, block and lot numbers match exactly). Synonyms (twp/township, rd/road,
...) are in `search_cli.php`.

## Keys and config

`config.php` defines `MEILI_URL`, `MEILI_MASTER_KEY` (used by `search_cli.php`)
and `MEILI_SEARCH_KEY` (used by the web page; search-only on the `docs` index).
Pre-prod and prod share a `config.php`, so they must share the master key and
create the search key with the same uid — Meilisearch derives a key's value from
its uid and the master key.

## Installing on pre-prod/prod

Manual, once per host:

1. `echo "deb [trusted=yes] https://apt.fury.io/meilisearch/ /" > /etc/apt/sources.list.d/fury.list`,
   `apt update && apt install meilisearch && apt-mark hold meilisearch`
2. `install -m 600 /dev/null /etc/meilisearch.env` and put `MEILI_MASTER_KEY=<key>` in it
3. `cp /home/piscataway/meilisearch.service /etc/systemd/system/ && systemctl daemon-reload && systemctl enable --now meilisearch`
4. Create the search key (same uid on both hosts):
   ```bash
   curl -s -X POST http://127.0.0.1:7700/keys \
     -H "Authorization: Bearer <master key>" -H 'Content-Type: application/json' \
     -d '{"uid":"<uid>","name":"web search","actions":["search"],"indexes":["docs"],"expiresAt":null}'
   ```
5. `php scripts/search_cli.php load data/search_docs.ndjson` (or run `deploy.sh`)

**Upgrading:** unhold, upgrade, re-hold; then stop the service, delete
`/var/lib/meilisearch/data.ms` (newer versions may refuse an older database),
start it, repeat step 4 and reload. The index is always rebuilt from the NDJSON,
so nothing is lost.

## Troubleshooting

- `php scripts/search_cli.php tasks` — recent indexing tasks and errors
- `php scripts/search_cli.php stats` — document counts per index
- `php scripts/search_cli.php search sewer bond` — sanity-check results from the CLI
- Page says "Search is temporarily unavailable": Meilisearch is down or the search key
  is wrong; the underlying error is in the PHP error log.
