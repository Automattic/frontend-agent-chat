# Frontend Agent Chat

Floating agent chat widget for WordPress. Connects to canonical abilities from [Agents API](https://github.com/Automattic/agents-api) and stays independent from any concrete runtime or storage plugin.

This plugin is the frontend companion for Agents API-powered WordPress agents. It was originally incubated as Data Machine Frontend Chat, then renamed and decoupled as the Agents API contract became the shared runtime boundary.

## How it works

A small React app mounts a floating action button (FAB) in the bottom-right corner of every page. Click it and a slide-in drawer opens with a full chat interface powered by the [`@extrachill/chat`](https://www.npmjs.com/package/@extrachill/chat) package.

The widget is a frontend shell. Agent runtime, tools, prompt policy, pending-action resolution, access control, and conversation sessions are provided by Agents API abilities and host-registered stores.

`@extrachill/chat` remains the current React UI dependency because it speaks the REST contract used by this plugin's Agents API adapter. Other Automattic chat UI packages can converge here once they support the Agents API session, run-control, pending-action, and message contracts directly.

## Configuration

Each site configures the chat widget via the `frontend_agent_chat_config` option.

```php
update_option( 'frontend_agent_chat_config', [
    'agent_slug'  => 'my-agent',
    'description' => 'Your AI assistant.',
    'enabled'     => true,
    'fab_label'   => 'Agent Chat',
    'fab_icon'    => 'AI',
] );
```

| Key | Type | Description |
|-----|------|-------------|
| `agent_slug` | `string` | Slug of the registered WordPress agent to connect to |
| `description` | `string` | Shown in the empty state before the first message |
| `enabled` | `bool` | Toggle the chat on/off for this site |
| `fab_label` | `string` | Floating action button label |
| `fab_icon` | `string` | Floating action button icon or short text |

The config can also be overridden via the `frontend_agent_chat_config` filter.

Visibility is resolved through `agents/can-access-agent` and can be refined with `frontend_agent_chat_user_can_see`.

## Requirements

- WordPress 6.9+
- [Agents API](https://github.com/Automattic/agents-api)
- A registered WordPress agent
- Host stores/resolvers for:
- `wp_agent_access_store`
- `wp_agent_conversation_store`
- `wp_agent_pending_action_store`
- `wp_agent_pending_action_resolver`

## Architecture

```
Browser                          Server
-------                          ------
FAB -> Drawer -> <Chat>    -->   /frontend-agent-chat/v1/chat
       (React)                   REST adapter
       @extrachill/chat          -> agents/chat
                                 -> agents/*conversation-session*
                                 -> agents/resolve-pending-action
```

- **Frontend**: `@extrachill/chat` package, mounted via `wp_footer` hook
- **Backend**: Local REST adapter that dispatches to canonical Agents API abilities
- **Auth**: WordPress nonce authentication via `wp-api-fetch`
- **Agent resolution**: `agents/list-accessible-agents`
- **Access checks**: `agents/can-access-agent`
- **Sessions**: `agents/list-conversation-sessions`, `agents/get-conversation-session`, `agents/create-conversation-session`, `agents/delete-conversation-session`
- **Approvals**: `agents/resolve-pending-action`

## Features

- Slide-in drawer with CSS transition
- Chat stays mounted when drawer closes (preserves session state)
- Admin bar aware (offsets below the WP toolbar)
- Per-site agent configuration
- Visibility controls from Agents API access grants
- CSS variable theming with standalone fallback values
- DiffCard rendering for inline code diffs
- Mobile responsive (full-width drawer on small screens)
- Network-activated (one plugin, all sites)

## CSS

Class prefix: `frontend-agent-chat`. Theme tokens use `--frontend-agent-chat-*` variables.

## Development

```bash
npm install
npm run build    # wp-scripts, outputs to build/
```

No custom webpack config — standard `wp-scripts` auto-discovery from `src/index.ts`.

## License

GPL v2 or later
