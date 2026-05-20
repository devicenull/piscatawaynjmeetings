# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**piscatawaynjmeetings.com** is a public information system tracking government meetings, bids, campaign finance, tweets, and other municipal data for Piscataway, New Jersey. The project combines a PHP web application with automated data collection scripts and a Datasette-based data explorer.

**Repository**: https://github.com/devicenull/piscatawaynjmeetings

## Tech Stack

- **Backend**: PHP 7+ with Twig templating
- **Frontend**: Bootstrap 5, jQuery, DataTables, Select2
- **Database**: MySQL via ADOdb abstraction layer
- **Dependencies**: Composer (Twig, ADOdb, PHPMailer, iCalendar, Twitter Stream API)
- **Data Processing**: Python scripts, shell scripts, yt-dlp, Tesseract OCR, Graphviz
- **Data Publishing**: Datasette (SQLite-based data explorer)
- **External Integrations**: Archive.org, Rev.ai, YouTube, Twitter/BlueSky, VoIP.ms, Cloudflare R2

## Directory Structure

- **`/classes`** - PHP data model classes extending `BaseDBObject`
  - Core models: `Meeting`, `Tweet`, `Bid`, `Newsletter`, `CampaignFile`, `CADCall`, `Address`
  - Service classes: `ArchiveOrg`, `BlueSky`, `VoIPms`, `YouTube`
- **`/web`** - PHP entry points and public-facing scripts (thin controllers)
- **`/templates`** - Twig HTML templates (including Datasette customizations)
- **`/scripts`** - Automated maintenance scripts (meetings, tweets, archive, transcription)
- **`/web/files`** - Uploaded/served content (minutes, recordings, bids, newsletters, etc.)
- **`/data`** - Local SQLite databases for Datasette (CAD calls, etc.)
- **`/cad_extract`** - CAD log parsing utilities (Python/shell)
- **`/vendor`** - Composer dependencies

## Architecture & Data Models

### PHP Architecture Pattern

The project uses a simple MVC-like pattern:
1. **Controllers** (`/web/*.php`) - Thin entry points that instantiate objects and call `displayPage()`
2. **Models** (`/classes/*.php`) - Domain objects extending `BaseDBObject`
3. **Templates** (`/templates/*.html`) - Twig templates receiving model data

### BaseDBObject

Core ORM-like base class implementing ArrayAccess. Key methods:
- `construct_by_column()` - Load by any column
- `add()` - INSERT with parameterized queries
- `set()` - UPDATE with parameterized queries
- `delete()` - DELETE by primary key
- Virtual fields support for computed properties

All database interactions use prepared statements via ADOdb.

### Key Data Models

1. **Meeting** - Government meetings (council, zoning, planning, etc.)
   - Fields: type, date, minutes/recording files, Zoom details, Rev.ai transcript job ID
   - Methods: file link generation, transcript formatting with clickable timestamps
   - Virtual field: `board_type` (maps type to display name)

2. **Tweet** - Archived tweets with Archive.org integration
   - Integrates with Twitter/X oEmbed API
   - Tracks archive.org job IDs for preservation

3. **Bid**, **Newsletter**, **CampaignFile** - Simple document trackers
   - Minimal metadata (filename, date, year)
   - EXIF metadata injection for accessibility

4. **CADCall** - Police CAD (Computer-Aided Dispatch) logs
   - Links to geocoded addresses
   - Filters to Oct 2025+ data only

5. **Address** - Geocoded locations for CAD mapping

### External Service Integration

- **ArchiveOrg**: Submit URLs to archive.org Save Page Now, poll job status
- **BlueSky**: Post meeting summaries to Bluesky social network
- **VoIPms**: SOAP API for phone system integration
- **Rev.ai**: Transcription job submission and status polling
- **YouTube**: Feed parsing and download via yt-dlp

## Build, Test, and Development Commands

### Prerequisites

```bash
# Install Composer dependencies
composer install

# Database setup
# Create MySQL database and import schema
# Config file at /home/piscataway/config.php (NOT in git) with:
# - DB_HOST, DB_USER, DB_PASSWORD, DB_NAME
# - API credentials (ARCHIVE_ACCESS, ARCHIVE_SECRET, etc.)
```

### Local Development

```bash
# Run PHP built-in server on localhost:8000
php -S localhost:8000 -t web/

# Access at http://localhost:8000
```

### Code Quality & Linting

```bash
# SonarQube analysis (CI integration)
# Config: sonar-project.properties
# Excludes: vendor/**, CSS/JS minified files
# Metric: Lines of code tracked at https://sonarcloud.io/
```

No built-in test suite. Code quality enforced via SonarCloud CI integration.

### Deployment

```bash
# Full production sync script (see deploy.sh for details)
./deploy.sh
# Generates CAD CSV → SQLite, rsyncs code/data, uploads to S3, restarts services
```

Key deployment tasks:
- CSV-to-SQLite conversion for Datasette
- File sync to Cloudflare R2 (excluding large YouTube files)
- MySQL dump and restore
- Datasette service restart via systemd

## Important Configuration & Constants

**`init.php`** - Central initialization file. Defines:
- Timezone: `America/New_York`
- Twig environment (strict variables, HTML autoescape)
- Autoloader for `/classes` directory
- Global `$db` connection (ADOdb MySQL)
- Authentication: `hasEditAuth()` checks if request IP starts with `192.168.5.`
- Message helpers: `displaySuccess()`, `displayError()` (session-based with redirect support)

**Constants**:
- `PISCATAWAY_UID` - Unique identifier for EXIF metadata
- `DICTIONARY_FILE` - Path to vocabulary.txt (for OCR/text processing)
- `BASE_FILE_PATH` - Web-accessible file storage root
- `ONE_HOUR`, `ONE_DAY` - Timestamp helpers

**Twig Variables** (passed to all templates):
- `has_edit_auth` - Boolean, set in `displayPage()`
- `success_message` / `error_message` - Session flash messages

## Automated Scripts

Located in `/scripts`, typically run via cron:

- **monitor_youtube.php** - Fetch YouTube feed, download videos with yt-dlp
- **monitor_tweets.php** - Monitor Twitter/X account, submit tweets to Archive.org
- **transcribe_meetings.php** - Submit recordings to Rev.ai, poll for completion
- **import_files.php** - Scan `/web/files` directories, create DB records, OCR PDFs, inject EXIF metadata
- **post_to_bluesky.php** - Auto-post meeting summaries to Bluesky
- **cad_calls.php** - Parse CAD logs, generate CSV
- **parse_campaign_expenses.php** - Generate Graphviz visualization of campaign contributions
- **extract_budget_stats.php** - Extract financial figures from budget/debt statement/financial statement PDFs into `budget_stats` table (run manually when new PDFs added)

## Datasette Integration

SQLite database published at `/datasette/cad_calls/`:
- Config: `datasette_metadata.json` and `datasette.service` (systemd unit)
- Custom templates: `/templates/datasette/` (Jinja2, NOT Twig)
- Pre-defined queries for monthly date ranges (Oct 2025 - Mar 2026)

## File Organization & Storage

Meeting files organized by type and date:
```
/files/
  ├── council/YYYY-MM-DD.{doc,pdf,docx,mp3,m4a,txt}
  ├── planning/YYYY-MM-DD.*
  ├── zoning/YYYY-MM-DD.*
  ├── bids/*.pdf
  ├── newsletter/*.pdf
  ├── campaign/YEAR/
  ├── youtube/YYYY-MM-DD/
  ├── budget/YYYY-MM-DD.pdf          (annual adopted budgets)
  ├── audits/YYYY-MM-DD.pdf          (annual audits)
  ├── financial_statements/YYYY-MM-DD.pdf
  ├── debt_statements/YYYY-MM-DD.pdf
  └── misc_files/
```

Large files (recordings, minutes) stored on Cloudflare R2; transcripts stored locally. File links in `Meeting.getLink()` and `Meeting.getPublicLink()` handle both cases.

## Authentication & Security

- **Edit access**: IP-based (192.168.5.* range only)
- **Session management**: PHP `$_SESSION` with flash messages
- **Database**: All queries parameterized via ADOdb (SQL injection protected)
- **CSRF**: No explicit CSRF tokens (minimal POST actions; IP auth sufficient)
- **Config secrets**: External `config.php` (API keys, DB credentials) — NOT in git

## Common Development Tasks

### Adding a New Page

1. Create Twig template in `/templates/{name}.html` (extends `layout.html`)
2. Create PHP controller in `/web/{name}.php` that calls `displayPage('template.html', $data)`
3. Create or reuse data model class in `/classes/`
4. Add navigation link in `templates/layout.html`

### Adding a New Data Model

1. Create class in `/classes/ClassName.php` extending `BaseDBObject`
2. Define: `const DB_KEY`, `const DB_TABLE`, `var $fields`, `var $virtual_fields`
3. Implement static fetch methods (e.g., `getAll()`, `getByType()`)
4. Override `get($offset)` for virtual field logic
5. Implement custom validation in `add()` / `set()` if needed

### Processing New Files

Add file type handling in `scripts/import_files.php`:
- Check parent directory name
- Create appropriate model instance
- Call `->add()` and optionally `ocrPDF()` or `setExifMetadata()`

### Integrating External Data Sources

Use service classes as templates:
- **ArchiveOrg.php**: Async job submission + polling pattern
- **BlueSky.php**: API authentication, JSON payloads
- **VoIPms.php**: SOAP client template
- Config credentials in external `config.php`, never hardcoded

## Budget & Financial Data

**Pages**: `web/budget.php` + `templates/budget.html`, `web/audits.php` + `templates/audits.html`

The budget page shows Highcharts trend charts (taxable valuation, total debt, stacked tax levy) plus a document table with links to both the budget PDF and the corresponding debt statement PDF for each year.

**Extraction script**: `scripts/extract_budget_stats.php` — run manually to re-extract when new PDFs are added. Writes to `budget_stats` table. Three data sources in priority order:

1. **Budget PDFs** (`web/files/budget/`): UFB format (2017+) extracts everything; legacy NJ format (pre-2017, 2022, 2026) extracts taxable valuation and municipal-only tax. Never extract debt from legacy budgets — "Outstanding Balance" is a partial figure (general capital only).

2. **Debt statements** (`web/files/debt_statements/`): Gross debt total. Backfills years missing debt from budget PDFs. Three OCR formats: `Total $X`, `2 Total $X`, and space-embedded numbers (2016: `$   114,       173,057.00`). The budget loop overwrites debt unconditionally; the debt statement loop uses `COALESCE` so it only fills nulls.

3. **Financial statements** (`web/files/financial_statements/`): Net Valuation Taxable. Each statement is certified as of October 1 of year N and feeds the year N+1 budget — so FS file for year N → `budget_stats` row for year N+1. Scanned PDFs produce 20M+ chars of junk; skip files > 5MB of text or with values < $1B. Regex: `NET\s+VALUATION\s+TAXABLE\s+\d{4}\s+([\d,]+)`.

**Budget format notes**:
- 2022 is legacy format despite the year (post-UFB era). Its debt extraction is unreliable; 2022 total_debt is intentionally NULL.
- 2015 and 2016 have no budget PDFs; their rows exist in `budget_stats` via debt statement and financial statement backfill only.
- The `misc_files` page excludes budget/audit/debt_statement types (they have dedicated pages). `MiscFile::getByTypes(['debt_statements', 'other'])` is used there.

## Known Quirks & TODOs

- **CAD data partial**: Only complete logs from Oct 2025 onward (older data from 2 addresses only)
- **Campaign finance visualization**: Uses Graphviz with shell subprocess; colors hardcoded
- **Zoom/transcript fields**: Meeting model has both; some meetings have neither
- **File type detection**: Uses extension-based matching, not MIME types
- **Copy logging**: Tracking user text selection for textcopy table (privacy consideration)
- **Twitter oEmbed**: Relies on Twitter's public API; may break if X/Twitter changes access
- **Date timezone handling**: Strftime used inconsistently; prefer DateTime class for new code
- **Budget debt 2022**: No reliable source; debt statement is scanned, budget extraction is partial. Stays NULL.
- **Budget taxable 2013, 2015**: No usable financial statement for those years; taxable valuation stays NULL.

## Testing & QA

- Manual QA: Access local instance via http://localhost:8000
- SonarCloud CI: Checks code complexity and security issues on every push
- Datasette queries: Test pre-defined queries in `datasette_metadata.json`
