# BP Events Sync

WordPress plugin that syncs **Speakers**, **Judges**, and **Sponsors** from the central BP Events CMS API into local WordPress Custom Post Types.

## Architecture

```
bp-events-sync/
├── bp-events-sync.php              # Bootstrap: constants, autoloader, activation hooks
├── includes/
│   ├── class-plugin.php            # Singleton orchestrator, AJAX handlers
│   ├── class-settings.php          # Centralised option read/write
│   ├── class-api-client.php        # HTTP client (CF Access auth, pagination)
│   ├── class-sync-engine.php       # Two-phase sync logic (name → ID)
│   ├── class-admin-page.php        # Settings UI, asset enqueuing
│   ├── class-activator.php         # Activation hook
│   └── class-deactivator.php       # Deactivation hook (cron cleanup)
├── admin/
│   ├── views/
│   │   └── settings-page.php       # Admin settings HTML template
│   ├── css/
│   │   └── admin.css               # Admin styles
│   └── js/
│       └── admin.js                # AJAX sync, taxonomy detection
└── README.md
```

## How It Works

### Two-Phase Sync Strategy

**Phase 1 — Initial Sync (by Name/Slug)**
1. Pull data from the central CMS API
2. For each record, try to match an existing WordPress post:
   - Normalise the API name → lowercase → compare against lowercase post titles
   - If no match → try matching by slug
   - If still no match → create a new post
3. Store the external CMS unique ID (`_bpes_external_id`) as post meta

**Phase 2 — Ongoing Sync (by ID)**
1. Look up posts by the stored `_bpes_external_id`
2. If found → update
3. If not found → create

### Taxonomy Handling

- **Speakers/Judges**: `event_year` is assigned as a category term (e.g. "2026")
- **Sponsors**: `event_year` as parent term, `tier` as child term (e.g. 2026 → Gold)
- **Additive only** — the plugin never removes existing term assignments
- **Year filter** — only records with `event_year >= 2026` are synced

## Settings Page

1. **API Connection** — Base URL, CF Access Client ID & Secret, Event Name
2. **Content Type Mapping** — Enable speakers/judges/sponsors per site, select which registered CPT maps to each, auto-detect and confirm taxonomies
3. **Automated Sync** — Enable WP Cron with configurable interval
4. **Manual Sync** — Per-type buttons for "Sync by Name/Slug" (initial) and "Sync by ID" (ongoing), with live results & log

## Installation

1. Upload `bp-events-sync/` to `/wp-content/plugins/`
2. Activate via WordPress admin
3. Go to **BP Events Sync** in the admin menu
4. Enter your API credentials and event name
5. Select which CPTs this site uses and confirm taxonomy mappings
6. Run initial sync via "Sync by Name/Slug"

## API Endpoints Used

| Type     | Endpoint                                                     | ID Field    |
|----------|--------------------------------------------------------------|-------------|
| Sponsors | `GET /admin/live?event={name}&pageSize=200`                  | `upload_id` |
| Speakers | `GET /admin/speakers/live?event={name}&type=speaker&pageSize=200` | `id`   |
| Judges   | `GET /admin/speakers/live?event={name}&type=judge&pageSize=200`   | `id`   |

Images are served publicly from `https://assets.events.businesspost.ie/r2/{key}`.

## Post Meta Stored

All meta keys are prefixed with `_bpes_`:

| Meta Key              | Description                        |
|-----------------------|------------------------------------|
| `_bpes_external_id`   | Central CMS unique ID              |
| `_bpes_event_name`    | Event name                         |
| `_bpes_event_year`    | Event year                         |
| `_bpes_image_url`     | Public R2 image URL                |
| `_bpes_job_title`     | Job title (speakers/judges)        |
| `_bpes_organisation`  | Organisation (speakers/judges)     |
| `_bpes_bio`           | Bio text (speakers/judges)         |
| `_bpes_tier`          | Sponsor tier                       |
| `_bpes_website`       | Sponsor website                    |
| `_bpes_contact_email` | Contact email                      |
