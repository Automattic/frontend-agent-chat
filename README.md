# Frontend Agent Chat

Floating agent chat widget for WordPress. Connects to the canonical `agents/chat` ability from [Agents API](https://github.com/Automattic/agents-api) and keeps compatibility with Data Machine when Data Machine registers itself as the chat runtime.

## How it works

A small React app mounts a floating action button (FAB) in the bottom-right corner of every page. Click it and a slide-in drawer opens with a full chat interface powered by the [`@extrachill/chat`](https://www.npmjs.com/package/@extrachill/chat) package.

The chat connects to a registered WordPress agent. The widget stays a frontend shell: agent runtime, tools, prompt policy, and model execution belong to whichever plugin handles the canonical `agents/chat` ability. Data Machine remains compatible by registering itself as that handler.

## Configuration

Each site configures the chat widget via the `frontend_agent_chat_config` option. Existing installs can keep using `data_machine_frontend_chat_config`.

```php
update_option( 'frontend_agent_chat_config', [
    'agent_slug'  => 'my-agent',
    'description' => 'Your AI assistant.',
    'enabled'     => true,
] );
```

| Key | Type | Description |
|-----|------|-------------|
| `agent_slug` | `string` | Slug of the registered WordPress agent to connect to |
| `description` | `string` | Shown in the empty state before the first message |
| `enabled` | `bool` | Toggle the chat on/off for this site |

The config can also be overridden entirely via the `frontend_agent_chat_config` filter. The legacy `data_machine_frontend_chat_config` filter still runs afterward for compatibility.

Visibility defaults to `manage_options`, with Data Machine's access helper used when available. Override with `frontend_agent_chat_user_can_see`.

## Requirements

- WordPress 6.9+
- [Agents API](https://github.com/Automattic/agents-api) or another plugin that provides the `agents/chat` ability
- A registered WordPress agent

## Architecture

```
Browser                          Server
-------                          ------
FAB -> Drawer -> <Chat>    -->   /frontend-agent-chat/v1/chat
       (React)                   REST adapter
       @extrachill/chat          -> agents/chat ability
                                 -> runtime handler
```

- **Frontend**: `@extrachill/chat` package, mounted via `wp_footer` hook
- **Backend**: Local REST adapter that dispatches to the canonical `agents/chat` ability
- **Auth**: WordPress nonce authentication via `wp-api-fetch`
- **Agent resolution**: Looked up by registered agent slug, with a Data Machine row fallback for existing installs

## Features

- Slide-in drawer with CSS transition
- Chat stays mounted when drawer closes (preserves session state)
- Admin bar aware (offsets below the WP toolbar)
- Per-site agent configuration
- Visibility controls (team only, logged in, or public)
- CSS variable theming with fallback values for standalone use
- DiffCard rendering for inline code diffs
- Mobile responsive (full-width drawer on small screens)
- Network-activated (one plugin, all sites)

## CSS

Class prefix: `datamachine-chat`. Theme tokens use `--datamachine-*` variables with fallback values so the widget works on any Data Machine site without requiring the Extra Chill theme.

## Development

```bash
npm install
npm run build    # wp-scripts, outputs to build/
```

No custom webpack config — standard `wp-scripts` auto-discovery from `src/index.ts`.

## License

GPL v2 or later
