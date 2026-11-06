# Editing CrazyLinks pages from Claude Desktop and Claude Code (MCP)

CrazyLinks exposes its page tools through the official WordPress **MCP Adapter** plugin. Claude (or another MCP client) then reads your link pages, writes the changes itself and saves them through the same validation and revision path as the page editor. The model runs on your Claude subscription, so this site needs no AI provider key for it. SPEC §10.7.

## What you need

- WordPress 6.9 or newer (CrazyLinks requires it anyway) and CrazyLinks 0.2.0 or newer.
- The **MCP Adapter** plugin by WordPress.org: Plugins → Add New → search "MCP Adapter" → Install → Activate (0.7.1 at the time of writing).
- Claude Desktop, or Claude Code. claude.ai in the browser cannot connect yet: it needs OAuth, and WordPress offers Application Passwords.

## 1. Turn the tools on

CrazyLinks → Settings → AI editing → **External AI agents**. Every ability is off until you tick it. For an assistant that writes its own changes, enable:

| Ability | Tool name | What it does |
|---|---|---|
| List pages | `crazylinks-list-pages` | Lists the link pages the account can edit (id, title, slug, status, URL, last change). |
| Read a page | `crazylinks-get-page` | One page as JSON (profile, design, blocks with ids) plus `modified`. Custom HTML and CSS are summarized, not returned. |
| Editing guide | `crazylinks-get-editing-guide` | Block types and their props, the operations format with examples, the rules and the workflow. With `id`, also the page. The assistant should call it first. |
| Preview changes | `crazylinks-preview-operations` | Checks operations against a page without saving: `valid`, errors to fix, warnings, a plain-English change list and the resulting page. |
| Save changes | `crazylinks-apply-operations` | Validates the operations again and saves them, creating a revision marked "AI (external)" in the page history. |

Optional: **Read click totals** (`crazylinks-get-analytics-summary`, aggregate clicks per block, no visitor data). **Propose changes**, **Apply proposed changes** and **Suggest link titles** use this site's own AI provider (Settings → Connectors); leave them off if you only use Claude through MCP.

In the same section, **External agents (MCP)** shows whether the MCP Adapter is active, both endpoint URLs and copyable connection snippets. Keep **Offer a CrazyLinks MCP server** ticked (the default).

## 2. Create a dedicated account and an Application Password

1. Users → Add New: a user just for the assistant (for example `claude-agent`) with the lowest role that can edit link pages. By default that is **Editor**; an administrator can grant CrazyLinks capabilities to other roles with the `crazylinks_role_caps` filter. The assistant can do only what this user can.
2. Log in as that user (or edit it as an administrator), Users → Profile → **Application Passwords**, name it "Claude", press **Add New Application Password** and copy the password. It is shown once.
3. To cut the assistant off later, revoke that password (or delete the user). Its earlier changes stay in the page history.

Application Passwords need HTTPS, except on sites whose environment type is `local`.

## 3a. Connect Claude Desktop

Claude Desktop talks to remote WordPress sites through the `@automattic/mcp-wordpress-remote` proxy (needs Node.js). Open Settings → Developer → **Edit Config** and add, with your site's endpoint, username and application password:

```json
{
  "mcpServers": {
    "crazylinks": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://example.com/wp-json/crazylinks/mcp",
        "WP_API_USERNAME": "YOUR-WORDPRESS-USERNAME",
        "WP_API_PASSWORD": "YOUR-APPLICATION-PASSWORD"
      }
    }
  }
}
```

Restart Claude Desktop. The CrazyLinks tools appear under the connectors menu. Add `"LOG_FILE": "/path/to/mcp.log"` to `env` to debug the proxy.

For a site on the same computer you can skip HTTP: `wp --path=/path/to/site mcp-adapter serve --server=crazylinks --user=claude-agent` runs the server over stdio (use it as `command` and `args`).

## 3b. Connect Claude Code

```sh
printf '%s' 'claude-agent:xxxx xxxx xxxx xxxx xxxx xxxx' | base64
claude mcp add --transport http crazylinks https://example.com/wp-json/crazylinks/mcp \
  --header "Authorization: Basic <the base64 output>"
```

Run `/mcp` inside Claude Code to check the connection. Do not commit the header to a shared `.mcp.json`.

## Which endpoint

| Endpoint | What the client sees |
|---|---|
| `/wp-json/crazylinks/mcp` (CrazyLinks server) | One tool per enabled CrazyLinks ability. Recommended. |
| `/wp-json/mcp/mcp-adapter-default-server` (adapter default) | Three meta-tools (`mcp-adapter-discover-abilities`, `-get-ability-info`, `-execute-ability`) through which the client finds and runs every enabled ability on the site, CrazyLinks included. |

Both use the same permission checks. `wp crazylinks mcp status` prints both URLs for your site.

## How an edit works

1. The assistant calls `crazylinks-get-editing-guide` (once per conversation) and `crazylinks-list-pages`.
2. `crazylinks-get-page` gives it the block ids and `modified`.
3. It writes operations (`add_block`, `update_block`, `move_block`, `remove_block`, `set_design`, `set_profile`) and calls `crazylinks-preview-operations` until `valid` is true.
4. `crazylinks-apply-operations` saves them, with a `summary` for the history, `expected_modified` (refused with a conflict if someone changed the page in the meantime) and an `idempotency_key` (a retry with the same key within 30 minutes returns the first result and saves nothing twice).

Every save runs the same checks as a human save in the editor: the operations schema, URL policy, media ownership, schedule sanity and the full page validator with the account's capabilities. Custom HTML blocks and custom CSS cannot be touched. If someone else has the page open in the CrazyLinks editor, the save is refused (409) so it cannot be overwritten by their next save; the assistant should try again later.

Page text, titles and URLs returned to the assistant are data, not instructions; the editing guide tells it so.

## Require confirmation

Tick **Require changes from external agents to be confirmed in the editor** (site default) or the per-page switch at the bottom of the page editor's AI tab. Then apply-operations saves nothing: it returns `applied: false` with a `pending_proposal_id`. Open the page in CrazyLinks → AI tab: the change is listed under "waiting for your confirmation" with a before/after preview; **Apply** saves it (badged "AI (external)"), **Discard** drops it. Pending changes expire after 7 days, and ones that no longer fit the page (someone removed the block they change) are dropped. This works without an AI provider on the site.

## Limits and logging

- Saves count against the same AI request limits as the editor's AI (CrazyLinks → Settings → AI editing → Request limits; default 30 per person per hour, 300 per site per day). Over the limit the call fails with 429. Previews and reads are not counted. The monthly token budget does not apply: the site spends no tokens.
- At most 60 operations per call.
- Each save or pending change writes a row to the AI history (`{prefix}crazylinks_ai_log`, source `mcp`, model `external`, 0 tokens) with the summary and the operations (unless AI history is off), kept for the retention you set (default 90 days). The revision shows "AI (external)" in the page history and can be restored like any other.

## Troubleshooting

`wp crazylinks mcp status` shows the adapter, the server switch, the endpoints and the enabled abilities. `wp crazylinks mcp guide --page=<id>` prints exactly what the assistant reads.

| Symptom | Cause and fix |
|---|---|
| `401` / "not allowed" on every call | Wrong username or application password, or the password was revoked. Use the username (not the email) and paste the password with or without spaces. Logged-out requests to `/wp-json/crazylinks/mcp` are refused by design. |
| `401` although the password is right | The host strips the `Authorization` header before PHP sees it (common with CGI/FastCGI and some proxies). On Apache add to `.htaccess` above the WordPress block: `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` (or `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1`). Ask your host if you are on nginx or a managed platform. |
| `403` | The account has none of the CrazyLinks capabilities. Give it the Editor role or grant the capabilities. |
| `rest_no_route` (404) | The MCP Adapter plugin is not active, the **Offer a CrazyLinks MCP server** setting is off, or the URL is wrong. With plain permalinks the endpoint is `/?rest_route=/crazylinks/mcp`. |
| Connected, but the tool list is empty or misses CrazyLinks tools | No CrazyLinks ability is enabled, or the ones you need are not ticked (each is off by default). Changes take effect on the next request; reconnect the client so it fetches the tool list again. |
| "This CrazyLinks ability is turned off" | The tool is listed by a client that cached an older tool list, or it was reached through the default server. Enable it under CrazyLinks → Settings → AI. |
| Apply returns `crazylinks_conflict` | The page changed after the assistant read it. It should call get-page again and retry with the new `modified`. |
| Apply returns `crazylinks_page_locked` | Someone has the page open in the editor. Close the editor or wait a few minutes. |
| Apply returns `applied: false` | The page requires confirmation; see above. |

## Developers

Filters `crazylinks_ability_enabled` (per-ability switch, also drives MCP exposure) and `crazylinks_mcp_server` (own server on or off); see `docs/hooks.md` for the routes, abilities and error codes.
