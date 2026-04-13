# Hook Documentation Toolchain

Automated pipeline that extracts all `do_action()` and `apply_filters()` hooks from WordPress plugin codebases, generates AI-enriched documentation, and syncs it to a WordPress site.

Works with **any WordPress plugin** that has a `src/` directory and `uncanny-owl/developer-tools` as a composer dev dependency.

## Quick Start

```bash
# From your plugin root (where composer.json lives):

# 1. Scan — extracts hooks, generates .md files with {{PLACEHOLDERS}}
php vendor/uncanny-owl/developer-tools/bin/scan-hooks.php --plugin-path .

# 2. Enrich — AI fills placeholders with descriptions and examples
python3 vendor/uncanny-owl/developer-tools/bin/enrich-hooks.py --input hook-docs/docs

# 3. Sync — UPSERTs to WordPress docs site
php vendor/uncanny-owl/developer-tools/bin/sync-hooks.php \
  --input hook-docs/docs \
  --wp-path /path/to/docs-wordpress-install
```

## Prerequisites

- **PHP 8.0+**
- **Python 3.7+** (for AI enrichment only)
- **ripgrep** (`brew install ripgrep` / `apt install ripgrep`)
- **AI API key** in `.env` (for enrichment only — see `.env.example`)
- **Python packages** (for enrichment): `pip install google-genai anthropic openai requests python-dotenv`

## Architecture

```
scan-hooks.php  →  enrich-hooks.py  →  sync-hooks.php
(PHP + ripgrep)    (Python + AI)       (PHP + WordPress)
     ↓                  ↓                    ↓
 hook-docs/docs/    Same .md files       WordPress CPT
 *.md files         placeholders filled  (automator_hook)
```

Each stage is independent. You can run the scanner without AI enrichment, or enrich without syncing.

---

## Stage 1: Scanner (`bin/scan-hooks.php`)

Scans PHP source files for `do_action()` and `apply_filters()` calls using ripgrep for speed. Extracts structural data from docblocks, call signatures, and surrounding code. Outputs one Markdown file per hook.

### CLI

```bash
php bin/scan-hooks.php \
  --plugin-path /path/to/plugin \        # Required. Plugin root (must have src/).
  [--pro-path /path/to/pro-plugin] \     # Optional. Pro/companion plugin.
  [--addon-path /path/to/addon] \        # Optional. Repeatable for multiple addons.
  [--context-lines 7] \                  # Lines of source context (default: 7).
  [--dry-run] \                          # Print stats, don't write files.
  [--fail-on-undocumented]               # Exit code 1 if any hooks lack docblocks.
```

### What It Extracts Per Hook

| Field | Source | Description |
|-------|--------|-------------|
| `hook_id` | Hook name (first arg) | Stable unique identifier |
| `hook_type` | `str_contains` on line | `action` or `filter` |
| `plugin` | Directory name | Plugin slug derived from path |
| `integration` | Path pattern | `src/integrations/{slug}/` → slug, else `core` or `api` |
| `since` | `@since` tag | Version number |
| `description` | Docblock body | Raw description |
| `params` | `@param` tags + call args | Name, type, description, sub_keys |
| `params[].sub_keys` | `@type` inside `@param array` | WordPress-style array key docs |
| `return_type` | `@return` tag | For filters |
| `examples` | `@example` blocks | Verbatim code from docblock |
| `source_context` | File read | N lines around the hook call |
| `related_hooks` | Post-processing | Proximity (30 lines) + name patterns (before/after) |
| `usage` | Second ripgrep pass | Internal `add_action`/`add_filter` calls |
| `locations` | All occurrences | File:line pairs (deduplicated by name) |
| `dynamic` | Name analysis | `true` if name uses concatenation/variables |

### Output Structure

Each plugin gets its own `hook-docs/` directory:

```
your-plugin/
  hook-docs/
    index.php          # PHP array manifest with totals
    docs/
      core/            # Hooks from src/core/
        hook-name.md
        ...
      api/             # Hooks from src/api/
        ...
      integrations/    # Hooks from src/integrations/{name}/
        woocommerce/
          hook-name.md
        gravity-forms/
          hook-name.md
```

### Multi-Plugin Support

When scanning free + pro + addons, each plugin's hooks go to its own repo:

```bash
php bin/scan-hooks.php \
  --plugin-path /path/to/free \
  --pro-path /path/to/pro \
  --addon-path /path/to/addon-one \
  --addon-path /path/to/addon-two
```

Result:
- `/path/to/free/hook-docs/docs/` — free plugin hooks
- `/path/to/pro/hook-docs/docs/` — pro plugin hooks
- `/path/to/addon-one/hook-docs/docs/` — addon hooks
- `/path/to/addon-two/hook-docs/docs/` — addon hooks

Each repo commits its own hook docs independently.

### Generated Markdown Format

Each `.md` file has YAML frontmatter + Gravity Forms-style sections + `{{PLACEHOLDERS}}` for AI:

```markdown
---
hook_id: automator_action_completion_status_changed
hook_type: action
plugin: uncanny-automator
integration: core
since: "6.7.0"
dynamic: false
undocumented: false
---

# `automator_action_completion_status_changed`

**Type:** Action
**Plugin:** Uncanny Automator
**Integration:** Core
**Since:** 6.7.0

{{READABLE_DESCRIPTION}}

---

## Description

{{TECHNICAL_DESCRIPTION}}

---

## Usage

```​php
add_action( 'automator_action_completion_status_changed', 'your_function_name', 10, 5 );
```​

---

## Parameters

- **$action_id** `int`
  The action ID that was marked complete.

- **$recipe_details** `array`
  The recipe details.

  | Key | Type | Description |
  |-----|------|-------------|
  | `recipe_id` | `int` | The recipe post ID. |

---

## Examples

{{USAGE_EXAMPLE}}

---

## Placement

This code should be placed in the `functions.php` file...

---

## Source Code

`src/core/lib/utilities/db/class-automator-db-handler-actions.php:376`

```​php
do_action( 'automator_action_completion_status_changed', ... );
```​

---

## Related Hooks

- [`automator_action_marked_{dynamic}`](automator-action-marked-dynamic.md) — fires_nearby

---

## Internal Usage

Found in `src/core/services/properties.php:42`:

```​php
add_action( 'automator_action_completion_status_changed', array( $this, 'record_properties' ), 20, 1 );
```​
```

### Placeholders

| Placeholder | When present | What AI fills |
|-------------|-------------|---------------|
| `{{READABLE_DESCRIPTION}}` | Always | 1-2 sentence human-friendly description |
| `{{TECHNICAL_DESCRIPTION}}` | Always | 30-80 word developer description |
| `{{USAGE_EXAMPLE}}` | Always | Realistic PHP code example |
| `{{PARAM_DESCRIPTION_N}}` | Undocumented hooks | Parameter description |
| `{{RETURN_DESCRIPTION}}` | Undocumented filters | Return value description |

---

## Stage 2: AI Enricher (`bin/enrich-hooks.py`)

Reads Markdown files, finds `{{PLACEHOLDER}}` patterns, calls an AI provider to generate content, and writes back in-place. Follows the same architecture as `generate_ai_content.py` from `automator-item-exporter`.

### CLI

```bash
python3 bin/enrich-hooks.py \
  --input hook-docs/docs \                     # One or more paths (space-separated).
  [--force] \                                  # Re-generate even if placeholders are filled.
  [--workers 5] \                              # Concurrent threads (auto-adjusted per provider).
  [--rate-limit 2.0]                           # Requests/second (auto-adjusted per provider).
```

### Multi-path support

```bash
python3 bin/enrich-hooks.py \
  --input hook-docs/docs ../pro-plugin/hook-docs/docs ../addon/hook-docs/docs
```

### Environment (`.env`)

```env
AI_PROVIDER=gemini                    # gemini | claude | openai | openrouter
GEMINI_API_KEY=your-key
GEMINI_MODEL=gemini-2.5-flash-lite
```

See `.env.example` for all supported providers.

### Provider Defaults

| Provider | Rate Limit | Workers | Model |
|----------|-----------|---------|-------|
| Gemini | 20 req/s | 10 | `gemini-2.5-flash-lite` |
| Claude | 1 req/s | 3 | `claude-sonnet-4-6-20250514` |
| OpenAI | 10 req/s | 8 | `gpt-4o-mini` |
| OpenRouter | 5 req/s | 5 | `anthropic/claude-3.5-sonnet` |

### Incremental Behavior

- **First run**: All files have placeholders → all get AI-generated content.
- **Subsequent runs**: Files without `{{` patterns are skipped → only new hooks processed.
- **`--force`**: Re-generates all content.
- **Manual edits**: If someone replaces a placeholder with real text, the enricher never touches it again (no `{{` to match).

### Performance

~1,300 hooks at Gemini 5 req/s = ~25 minutes. At 20 req/s = ~6 minutes (may hit 502s).

---

## Stage 3: WP Sync (`bin/sync-hooks.php`)

Reads enriched Markdown files and UPSERTs them into a WordPress site as `automator_hook` custom post type entries. Requires WordPress loaded (via `--wp-path` or WP-CLI).

### CLI

```bash
# Standalone (loads WordPress)
php bin/sync-hooks.php \
  --input hook-docs/docs,../pro/hook-docs/docs \   # Comma-separated paths.
  --wp-path /path/to/wordpress \                    # WordPress installation root.
  [--dry-run]                                       # Show what would happen.
```

### Multi-path support

Accepts comma-separated `--input` paths. Reads `plugin` from YAML frontmatter to assign the correct `hook_plugin` taxonomy — doesn't matter which directory the file comes from.

### UPSERT Logic

```
For each .md file:
  1. Parse YAML frontmatter → get hook_id
  2. Parse markdown body → extract all sections
  3. Query WordPress: get_posts( post_name = sanitize_title(hook_id) )
  4. If found → update pipeline-owned fields only
  5. If not found → insert new post
  6. Assign taxonomies: hook_type, hook_integration, hook_plugin
  7. Compute pipeline_hash → skip if unchanged on next run
```

### Pipeline-Owned vs Human-Owned Fields

| Field | Owner | On update |
|-------|-------|-----------|
| `post_title`, taxonomies, `_hook_since`, `_hook_parameters`, `_hook_source_*`, `_hook_related`, `_hook_internal_usage` | **Pipeline** | Always overwrite |
| `post_name` (slug) | **Pipeline** | Never change after creation |
| `_hook_description_readable`, `_hook_description_technical`, `_hook_usage_example` | **Human after first set** | Only overwrite if empty or contains `{{` |

**Key rule**: If support team edits a description in wp-admin, the pipeline never overwrites it.

### Unique ID Strategy

`hook_id` = hook name (e.g., `automator_action_completion_status_changed`). This becomes the `post_name` slug in WordPress. It's:
- **Deterministic** — same hook always produces the same ID
- **Stable across releases** — hook names are public API, they don't change
- **Unique** — hook names are globally unique in WordPress

---

## WordPress Site Setup

The docs site needs two components:

### 1. Plugin: `automator-hook-docs`

Registers:
- `automator_hook` CPT (`/hooks/` URL)
- `hook_type` taxonomy (action, filter)
- `hook_integration` taxonomy (core, woocommerce, gravity-forms, ...)
- `hook_plugin` taxonomy (uncanny-automator, uncanny-automator-pro, ...)
- 16 post meta fields (all REST-exposed)
- Admin meta boxes for editing

### 2. Theme: Astra Child with templates

- `single-automator_hook.php` — Gravity Forms-style hook detail page
- `archive-automator_hook.php` — Filterable hook list with sidebar
- `front-page.php` — Landing page with search, stats, integration grid
- `template-parts/hook-sidebar.php` — Integration tree with counts
- `hook-docs.css` — Full styling with brand colors
- `hook-docs.js` — Click-to-copy for code blocks
- Prism.js for syntax highlighting

---

## Composer Scripts (for consuming plugins)

Add to your plugin's `composer.json`:

```json
{
  "require-dev": {
    "uncanny-owl/developer-tools": "dev-main"
  },
  "scripts": {
    "scan-hooks": "php ./vendor/uncanny-owl/developer-tools/bin/scan-hooks.php --plugin-path .",
    "enrich-hooks": "python3 ./vendor/uncanny-owl/developer-tools/bin/enrich-hooks.py --input hook-docs/docs",
    "generate-hook-docs": [
      "@scan-hooks",
      "@enrich-hooks"
    ]
  }
}
```

For multi-plugin setups (free + pro):

```json
{
  "scripts": {
    "scan-hooks": "php ./vendor/uncanny-owl/developer-tools/bin/scan-hooks.php --plugin-path . --pro-path ../my-plugin-pro",
    "enrich-hooks": "python3 ./vendor/uncanny-owl/developer-tools/bin/enrich-hooks.py --input hook-docs/docs ../my-plugin-pro/hook-docs/docs",
    "sync-hooks": "php ./vendor/uncanny-owl/developer-tools/bin/sync-hooks.php --input hook-docs/docs,../my-plugin-pro/hook-docs/docs --wp-path /path/to/docs-site",
    "generate-hook-docs": [
      "@scan-hooks",
      "@enrich-hooks",
      "@sync-hooks"
    ]
  }
}
```

---

## Adding to a New Plugin

1. **Add dev dependency**:
   ```bash
   composer require --dev uncanny-owl/developer-tools:dev-main
   ```

2. **Ensure your plugin has `src/`** — the scanner looks for `{plugin-path}/src/`.

3. **Copy `.env.example`** to `.env` and add your AI API key:
   ```bash
   cp vendor/uncanny-owl/developer-tools/.env.example .env
   # Edit .env with your GEMINI_API_KEY
   ```

4. **Add composer scripts** (see section above).

5. **Run**:
   ```bash
   composer scan-hooks          # Generates hook-docs/docs/*.md
   composer enrich-hooks        # AI fills placeholders
   ```

6. **Commit** `hook-docs/` to your repo — each plugin owns its hook docs.

7. **Sync to docs site** (optional):
   ```bash
   composer sync-hooks
   ```

---

## CI/CD (Buddy.works)

```yaml
- action: "Generate hook documentation"
  type: "BUILD"
  working_directory: "/wp-content/plugins/your-plugin"
  docker_image_name: "library/php"
  docker_image_tag: "8.2-cli"
  execute_commands:
    - "apt-get update -qq && apt-get install -y -qq ripgrep python3 python3-pip > /dev/null 2>&1"
    - "pip3 install google-genai requests python-dotenv > /dev/null 2>&1"
    - "composer scan-hooks"
    - "composer enrich-hooks"
    - "composer sync-hooks"
  trigger_condition: "on_change"
  trigger_condition_paths:
    - "src/**/*.php"
```

---

## Troubleshooting

| Problem | Cause | Fix |
|---------|-------|-----|
| `ripgrep (rg) not found` | Not installed | `brew install ripgrep` or `apt install ripgrep` |
| `GEMINI_API_KEY not found` | Missing `.env` | Copy `.env.example` to `.env`, add key |
| 502 errors during enrichment | Gemini rate limit | Lower rate: `--rate-limit 5 --workers 5` |
| Sync says "0 created" for existing hooks | Slug mismatch | Check `post_name` vs `sanitize_title(hook_id)` |
| Files disappearing during enrichment | Git or file watcher | Add `hook-docs/` to `.gitignore` during dev, or output to `/tmp/` |
| `--fail-on-undocumented` exits 1 | Hooks without docblocks | Add docblocks or accept as CI warning |
