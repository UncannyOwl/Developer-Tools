# Hook Documentation — Implementation Plan

## Overview

Automated pipeline that scans ~850 hooks from Uncanny Automator free+pro, enriches via AI, and syncs to a WordPress site at `docs.automatorplugin.com`. The WordPress side is set-and-forget — no cleanup between runs, only UPSERT.

```
Phase 2              Phase 3              Phase 4
scan-hooks.php   →   enrich-hooks.py  →   sync-hooks.php
(ripgrep+PHP)        (AI fill)            (WP-CLI upsert)
     ↓                    ↓                    ↓
 .md files           .md files (filled)    WordPress CPT
 + placeholders                            docs.automatorplugin.com
```

---

## Phases

### Phase 1: WordPress Site (CPT + Theme) -- COMPLETE

Set-and-forget WordPress installation with Astra child theme.

**Deliverables:**

| File | Location | Status |
|------|----------|--------|
| `automator-hook-docs.php` | `automator-docs/wp-content/plugins/automator-hook-docs/` | Done |
| `front-page.php` | `automator-docs/wp-content/themes/astra-child/` | Done |
| `single-automator_hook.php` | `automator-docs/wp-content/themes/astra-child/` | Done |
| `archive-automator_hook.php` | `automator-docs/wp-content/themes/astra-child/` | Done |
| `template-parts/hook-sidebar.php` | `automator-docs/wp-content/themes/astra-child/` | Done |
| `hook-docs.css` | `automator-docs/wp-content/themes/astra-child/` | Done |
| `hook-docs.js` | `automator-docs/wp-content/themes/astra-child/` | Done |
| `functions.php` | `automator-docs/wp-content/themes/astra-child/` | Done |

**What was built:**

- `automator_hook` CPT with `/hooks/` permalink
- 3 taxonomies: `hook_type` (action/filter), `hook_integration`, `hook_plugin`
- 16 registered post meta fields (all REST-exposed)
- 3 admin meta boxes: Hook Details, Source Code, Descriptions
- Home page: hero with search, stat cards, integration grid, recently added table
- Archive page: sidebar + search/filter bar + paginated table
- Single hook page (Gravity Forms style): breadcrumbs, badges, TOC, Description, Usage, Parameters (with sub-key tables), Return Value, Examples, Placement, Source Code, Related Hooks, Internal Usage
- Sidebar: search, stats, integration tree with counts
- Prism.js syntax highlighting (Tomorrow dark theme)
- Click-to-copy on all code blocks (appears on hover, green "Copied!" feedback)
- Automator brand colors (`#0790E8` blue, `#6BC45A` green, `#222222` dark)
- Full-width layout, responsive

---

### Phase 2: Scanner (`bin/scan-hooks.php`)

**Location:** `automator-dev-tools/bin/scan-hooks.php`

**Input:** `--plugin-path`, `--pro-path`, `--addon-path`
**Output:** One `.md` file per hook in `hook-docs/docs/{segment}/`, plus `hook-docs/index.php`

```bash
php bin/scan-hooks.php \
  --plugin-path /path/to/uncanny-automator \
  --pro-path /path/to/uncanny-automator-pro \
  [--addon-path /path/to/addon] \
  [--output hook-docs] \
  [--context-lines 7] \
  [--dry-run] \
  [--fail-on-undocumented]
```

**What it does:**

1. Ripgrep scan for all `do_action(` and `apply_filters(` calls
2. Type detection via `str_contains` on line text (fixes filter bug)
3. Skip `_deprecated`, `_ref_array`, commented-out calls
4. For each match, extract:
   - Hook name, type, plugin, integration
   - Parameters from `@param` + call args (including `@type` sub-keys)
   - `@since`, `@return`, `@example` from docblocks
   - Source context (N lines around the call)
   - Dynamic hook detection + value enumeration
5. Post-processing:
   - Deduplicate by hook name, group locations
   - Detect related hooks (proximity + name patterns)
   - Find internal usage (`add_action`/`add_filter` on automator hooks)
6. Output:
   - One `.md` per hook with YAML frontmatter + `{{PLACEHOLDERS}}`
   - `index.php` manifest with totals and file list

**Folder structure:**

```
hook-docs/
  index.php
  docs/
    core/
      automator-action-completion-status-changed.md
      ...
    api/
      ...
    integrations/
      gravity-forms/
        ...
      buddyboss/
        ...
    pro-core/
      ...
    pro-integrations/
      ...
```

**YAML frontmatter per hook:**

```yaml
---
hook_id: automator_action_completion_status_changed
hook_type: action
plugin: uncanny-automator
integration: core
since: "6.7.0"
dynamic: false
undocumented: false
---
```

**Placeholders in body:**

| Placeholder | When present |
|-------------|-------------|
| `{{READABLE_DESCRIPTION}}` | Always |
| `{{TECHNICAL_DESCRIPTION}}` | Always |
| `{{USAGE_EXAMPLE}}` | Always |
| `{{PARAM_DESCRIPTION_N}}` | Undocumented hooks |
| `{{RETURN_DESCRIPTION}}` | Undocumented filters |

**Verification:**

- [ ] Finds ~850 unique hooks (actions AND filters)
- [ ] Every `.md` has valid YAML frontmatter with `hook_id`
- [ ] `index.php` totals match file count
- [ ] `--dry-run` prints stats without writing
- [ ] `--fail-on-undocumented` exits 1

---

### Phase 3: AI Enricher (`bin/enrich-hooks.py`)

**Location:** `automator-dev-tools/bin/enrich-hooks.py`

**Input:** `hook-docs/docs/` directory with `.md` files containing `{{PLACEHOLDERS}}`
**Output:** Same `.md` files with placeholders filled in-place

```bash
python3 bin/enrich-hooks.py \
  --input hook-docs/docs \
  [--force] \
  [--workers 5] \
  [--rate-limit 2.0] \
  [--description-types readable technical example]
```

**Architecture:** `HookEnricher` class — mirrors `generate_ai_content.py` from `automator-item-exporter`:

- Multi-provider: Gemini / Claude / OpenAI / OpenRouter (via `.env`)
- Provider-specific rate limits and worker counts
- `ThreadPoolExecutor` parallel processing
- 503 retry with exponential backoff
- Progress reporting
- Incremental: files without placeholders are skipped

**How it works:**

1. Walk `docs/` recursively, find all `.md` files
2. For each file, scan for `{{PLACEHOLDER}}` patterns
3. No placeholders → skip
4. Parse frontmatter + body for context (hook name, type, params, source code)
5. Build prompt, call AI, replace placeholder, write back

**AI generates:**

| Placeholder | AI prompt focus |
|-------------|----------------|
| `{{READABLE_DESCRIPTION}}` | 1-2 sentence human-friendly description |
| `{{TECHNICAL_DESCRIPTION}}` | 30-80 word developer description |
| `{{USAGE_EXAMPLE}}` | Realistic PHP code example |
| `{{PARAM_DESCRIPTION_N}}` | Parameter description from context |
| `{{RETURN_DESCRIPTION}}` | Filter return value description |

**Provider defaults:**

| Provider | Rate Limit | Workers | Model |
|----------|-----------|---------|-------|
| Gemini | 20 req/s | 10 | `gemini-2.5-flash-lite` |
| Claude | 1 req/s | 3 | `claude-sonnet-4-6-20250514` |
| OpenAI | 10 req/s | 8 | `gpt-4o-mini` |
| OpenRouter | 5 req/s | 5 | `anthropic/claude-3.5-sonnet` |

**Verification:**

- [ ] All placeholders filled after first run
- [ ] Re-run → 0 files processed
- [ ] `--force` regenerates all
- [ ] Manual edits preserved (no `{{` pattern to match)

---

### Phase 4: WP Sync (`bin/sync-hooks.php`)

**Location:** `automator-dev-tools/bin/sync-hooks.php`

**Input:** `hook-docs/docs/` directory with enriched `.md` files
**Output:** WordPress CPT posts at docs site

```bash
# Via REST API from CI
php bin/sync-hooks.php \
  --input hook-docs/docs \
  --site https://docs.automatorplugin.com \
  --auth-token $DOCS_API_TOKEN

# Or via WP-CLI on the docs server
wp automator-hooks sync --path=/path/to/hook-docs/docs/
```

**UPSERT logic:**

```
For each .md file:
  1. Parse YAML frontmatter → get hook_id
  2. Parse markdown body → extract sections
  3. Query WP: get_posts( post_name = hook_id )
  4. Found → update pipeline-owned fields only
  5. Not found → insert new post with all fields
  6. Assign taxonomies: hook_type, integration, plugin
  7. Compute pipeline_hash → skip if unchanged
```

**Unique ID:** `hook_id` = hook name → `post_name` slug. Deterministic, stable across releases.

**Pipeline-owned vs human-owned:**

| Field | Owner | On update |
|-------|-------|-----------|
| `post_title` | Pipeline | Always overwrite |
| `post_name` (slug) | Pipeline | Never change after creation |
| Taxonomies | Pipeline | Always set |
| `_hook_since`, `_hook_dynamic`, `_hook_undocumented` | Pipeline | Always overwrite |
| `_hook_parameters`, `_hook_source_*`, `_hook_related`, `_hook_internal_usage` | Pipeline | Always overwrite |
| `_hook_description_readable` | **Human after first set** | Only set on INSERT or if still `{{PLACEHOLDER}}` |
| `_hook_description_technical` | **Human after first set** | Only set on INSERT or if still `{{PLACEHOLDER}}` |
| `_hook_usage_example` | **Human after first set** | Only set on INSERT or if still `{{PLACEHOLDER}}` |
| `_hook_pipeline_hash` | Pipeline | MD5 of pipeline-owned fields — skip update if unchanged |

**Verification:**

- [ ] Creates all posts with correct taxonomies
- [ ] Re-run → 0 posts changed (hash match)
- [ ] Edit a post in wp-admin → re-run → edit preserved
- [ ] New hook in source → re-run → new post appears

---

### Phase 5: Integration & Cleanup

**Composer scripts** in `uncanny-automator/composer.json`:

```json
"scan-hooks": "php vendor/uncanny-owl/developer-tools/bin/scan-hooks.php --plugin-path . --pro-path ../uncanny-automator-pro",
"enrich-hooks": "python3 vendor/uncanny-owl/developer-tools/bin/enrich-hooks.py --input hook-docs/docs",
"sync-hooks": "php vendor/uncanny-owl/developer-tools/bin/sync-hooks.php --input hook-docs/docs --site https://docs.automatorplugin.com",
"generate-hook-docs": [
    "@scan-hooks",
    "@enrich-hooks",
    "@sync-hooks"
]
```

**`automator-dev-tools/composer.json`** — add to `bin` array:

```json
"bin": [
    "bin/pr-code-check",
    "bin/pr-code-check.bat",
    "bin/pr-code-check.php",
    "bin/scan-hooks.php",
    "bin/enrich-hooks.py",
    "bin/sync-hooks.php"
]
```

**Buddy.works pipeline step:**

```yaml
- action: "Generate and sync hook documentation"
  type: "BUILD"
  working_directory: "/wp-content/plugins/uncanny-automator"
  docker_image_name: "library/php"
  docker_image_tag: "8.2-cli"
  execute_commands:
    - "apt-get update -qq && apt-get install -y -qq ripgrep python3 python3-pip > /dev/null 2>&1"
    - "pip3 install google-genai anthropic openai requests python-dotenv > /dev/null 2>&1"
    - "composer generate-hook-docs"
  trigger_condition: "on_change"
  trigger_condition_paths:
    - "src/**/*.php"
```

**Delete prototype files:**

| File | Action |
|------|--------|
| `uncanny-automator/tools/scan-hooks.php` | Delete |
| `uncanny-automator/tools/generate-docs.php` | Delete |
| `uncanny-automator/buddy-hook-docs.yml` | Delete |
| `uncanny-automator/hook-docs/` | Delete |

**Verification:**

- [ ] `composer generate-hook-docs` runs all 3 stages end-to-end
- [ ] Site search works, sidebar navigation works, code highlighting works
- [ ] `--fail-on-undocumented` exits 1 for CI

---

## Phase Summary

| Phase | What | Status |
|-------|------|--------|
| **Phase 1** | WordPress site (CPT + theme + templates) | **COMPLETE** |
| **Phase 2** | Scanner (`bin/scan-hooks.php`) | Not started |
| **Phase 3** | AI Enricher (`bin/enrich-hooks.py`) | Not started |
| **Phase 4** | WP Sync (`bin/sync-hooks.php`) | Not started |
| **Phase 5** | Composer scripts + Buddy pipeline + cleanup | Not started |
