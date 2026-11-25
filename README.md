# Claude Site Agent (v0.1)

A Claude-powered agent inside wp-admin. You chat with it like Claude Code, and it works on the site through WordPress itself: building pages (including ACF flexible content), bulk content edits, media details, small CSS/JS snippets and page-speed rules. Every write is previewed as a diff, approved by you, and undoable.

## Install

1. Copy the `claude-site-agent` folder into `wp-content/plugins/` (or upload the zip) and activate it.
2. Go to **Claude Agent → Settings**, paste an Anthropic API key (stored encrypted with your site salts), or define `CSA_ANTHROPIC_API_KEY` in `wp-config.php` yourself. The plugin never edits that file.
3. Set which capabilities are on globally and for each role, then open **Claude Agent**.

Requires WordPress 6.4+ and PHP 8.0+. No Composer dependencies and no build step.

## What it can do

| Capability | Tools | Default |
|---|---|---|
| Read site content | `get_site_info`, `search_content`, `get_post`, `list_theme_files`, `read_theme_file`, `get_acf_field_groups`, `get_acf_options` | Admin, Editor |
| Create and edit content | `create_post`, `update_post` (fields, template, featured image, plain meta, ACF) | Admin, Editor |
| Bulk edits and trashing | `bulk_replace` (title, content, excerpt, plain/ACF text meta; max 200 items), `trash_posts` | Admin |
| ACF fields and options pages | ACF values in create/update, `update_acf_options` | Admin, Editor |
| CSS and JS snippets | `list_snippets`, `save_snippet`, `remove_snippet` | Admin |
| Media library | `search_media` (e.g. missing alt, large files), `update_media`, `import_media` | Admin, Editor |
| Page speed | `analyze_page`, `run_pagespeed`, `list_performance_rules`, `set_performance_rule`, `remove_performance_rule` | Admin |
| Basic site settings | `update_site_options` (short allowlist only) | Off |

A user gets a capability only if it is on globally **and** on for one of their roles. On top of that, every tool checks the user's own WordPress capabilities (`edit_post`, `publish_posts`, `upload_files`, `unfiltered_html`, `manage_options`…), so the agent can never do more than the user could by hand.

## Safety model

**Hard limits (not configurable).** No tool exists for: wp-config.php, .htaccess, server/PHP/nginx config, WP Engine or any host settings, caching layers, plugins or themes (install/activate/edit), theme file writes, users and roles, site URLs or permalinks, shell commands or PHP execution. `CSA_Guard` also blocks option names such as `siteurl`, `home`, `active_plugins`, `*user_roles`, `rewrite_rules`, anything matching `wpe`/`wpengine`, secrets, and the plugin's own `csa_*` settings, so the agent can't change its own permissions. Theme files are readable only inside the active (child) theme, excluding `node_modules`, `vendor`, `.git`.

**Approval.** Write tools produce a preview first: a diff per field/item with a risk badge (single, live content, bulk, trash, front-end code, settings). Nothing is written until you approve. Rejecting with a note sends the note back to Claude. Typing a new message while changes are pending rejects them. An optional setting auto-applies *single draft* changes only; bulk, live content, publishing, trash, snippets and settings always ask.

**Undo.** Each applied change is a change set with a full snapshot (post fields + all post meta, or option values) taken before writing, plus a normal WordPress revision. **History & undo** restores it. If the item was edited after the agent's change, undo warns first and needs "Undo anyway". Undoing a created page moves it to the trash.

**Staleness.** Approving re-runs the preview; if the content changed since the preview was shown, nothing is applied.

**Defaults.** New content is created as a draft. The agent is told to treat text inside posts and files as data, not instructions.

## How it works

```
Browser (wp-admin chat)  ──REST──▶  CSA_Rest  ──▶  CSA_Agent.step()
                                                    │  one Claude API call per request
                                                    ▼
                                    Claude Messages API (claude-opus-5-5, tool use)
                                                    │
               read tools run now ◀─────────────────┤
               write tools → preview → you approve ─┘ → apply inside a change set
```

* Each HTTP request makes **one** Claude API call plus the tool calls it asks for, then returns. The browser calls `/continue` until the turn ends. This keeps each PHP request short for hosts that kill long requests (WP Engine: 60 seconds).
* API: `POST /v1/messages` via `wp_remote_post`, `anthropic-version: 2026-10-21`, adaptive thinking, configurable effort (default medium), automatic prompt caching, and server-side refusal fallbacks (`fallbacks: "default"`). The conversation history is replayed exactly as returned, including thinking blocks.
* Sessions and change sets live in two tables: `{prefix}csa_sessions`, `{prefix}csa_changesets`. Snippets and performance rules live in the `csa_snippets` and `csa_rules` options and are printed by `CSA_Frontend` on the front end only. Logged-in editors can add `?csa_no_rules=1` to a URL to compare without them.

## ACF notes

The agent calls `get_acf_field_groups` before building a page, so it uses your real flexible content layouts and sub field names. It can also read the theme's layout partials to see how each layout renders. Values are written with `update_field()` using field keys resolved from the groups that match the post (so new posts work). Validation rejects unknown fields, layouts and sub fields before you see a preview. A flexible content or repeater field is replaced as a whole when it's passed. Gallery/image fields take attachment IDs; link fields take `{url,title,target}`.

## Extending

* `csa_tools` filter: add/remove tool definitions (format documented in `includes/tools/class-tool.php`).
* `csa_option_allowlist`: extend the allowlisted options (the denylist still wins).
* `csa_user_caps`: adjust a user's capability groups.
* `csa_api_request_body`, `csa_api_url`: adjust API requests.

## Known limits in v0.1

* Tested against WordPress 6.6 with a small ACF stand-in and a mocked Claude API (38 automated checks). Not yet tested against real ACF Pro or the live API.
* No streaming; a long single reply can still hit a 60s host limit at high effort.
* No menu editing, taxonomy term editing, Gutenberg block-level tools, image compression or ACF Blocks support yet.
* No token budget per user/month yet (usage is shown per conversation).
* Long conversations are not compacted; start a new conversation for a new task.
