# Frontend Agent Chat

Floating agent chat widget for WordPress. Connects to canonical abilities from [Agents API](https://github.com/Automattic/agents-api) and stays independent from any concrete runtime or storage plugin.

This plugin is the frontend companion for Agents API-powered WordPress agents. It was originally incubated as Data Machine Frontend Chat, then renamed and decoupled as the Agents API contract became the shared runtime boundary.

## How it works

A small React app mounts a floating action button (FAB) in the bottom-right corner of every page. Click it and a slide-in drawer opens with a full chat interface powered by the [`@automattic/agenttic-ui`](https://www.npmjs.com/package/@automattic/agenttic-ui) embedded agent UI, talking to Agents API through the [`@automattic/agenttic-client`](https://www.npmjs.com/package/@automattic/agenttic-client) package.

The widget is a frontend shell. Agent runtime, tools, prompt policy, pending-action resolution, access control, and conversation sessions are provided by Agents API abilities and host-registered stores.

`@automattic/agenttic-client` speaks the REST contract used by this plugin's Agents API adapter, and `@automattic/agenttic-ui` renders the chat UI on top of that client. Together they support the Agents API session, run-control, pending-action, and message contracts directly.

## Configuration

Each site configures the chat widget via the `frontend_agent_chat_config` option.

```php
update_option( 'frontend_agent_chat_config', [
    'agent_slug'  => 'my-agent',
    'description' => 'Your AI assistant.',
    'enabled'     => true,
    'fab_label'   => 'Agent Chat',
    'fab_icon'    => 'AI',
    'header_controls' => [
        'agent_selector'    => true,
        'session_controls' => true,
        'expand_button'    => true,
        'close_button'     => true,
    ],
] );
```

| Key | Type | Description |
|-----|------|-------------|
| `agent_slug` | `string` | Slug of the registered WordPress agent to connect to |
| `description` | `string` | Shown in the empty state before the first message |
| `enabled` | `bool` | Toggle the chat on/off for this site |
| `fab_label` | `string` | Floating action button label |
| `fab_icon` | `string` | Floating action button icon or short text |
| `header_controls.agent_selector` | `bool` | Show the agent selector/title in the drawer header |
| `header_controls.session_controls` | `bool` | Show new/session picker controls in the drawer header |
| `header_controls.expand_button` | `bool` | Show the viewport expand/collapse button |
| `header_controls.close_button` | `bool` | Show the drawer close button |

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
       @automattic/agenttic-ui   -> agents/chat
       @automattic/agenttic-client  -> agents/*conversation-session*
                                 -> agents/resolve-pending-action
```

- **Frontend**: `@automattic/agenttic-ui` + `@automattic/agenttic-client` packages, mounted via `wp_footer` hook
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

### Theming

The chat conversation surface is rendered by [`@automattic/agenttic-ui`](https://github.com/Automattic/agenttic), which ships its own `.agenttic` design-system tokens (`--color-*`, `--font-*`, `--text-*`, `--spacing`, `--radius-*`, `--shadow-*`). Frontend Agent Chat bridges its own `--frontend-agent-chat-*` tokens onto that agenttic surface inside the `.frontend-agent-chat .agenttic` scope, so a downstream brand themes the entire widget — chrome and conversation — by setting only `--frontend-agent-chat-*` variables.

Every token below defaults to agenttic's current value, so a site that sets nothing renders identically to a bare agenttic surface.

**Surface colors**

| Token | Purpose |
|-------|---------|
| `--frontend-agent-chat-accent` | Primary action color (FAB, composer send button, links, focus ring) |
| `--frontend-agent-chat-on-accent` | Foreground on accent fills (including the composer send button) |
| `--frontend-agent-chat-drawer-bg` | Drawer / conversation background |
| `--frontend-agent-chat-text-primary` | Primary text |
| `--frontend-agent-chat-text-muted` | Muted / secondary text |
| `--frontend-agent-chat-border-color` | Borders and dividers |
| `--frontend-agent-chat-bg-muted` | Muted surfaces |
| `--frontend-agent-chat-message-bg` | Message bubble background |
| `--frontend-agent-chat-input-bg` | Input field background |

**Status colors**

These semantic status tokens drive both the agenttic conversation surface and Frontend Agent Chat's own chrome (retrieval-state banners, tool-cards, artifact-cards, citation list, persistence notices) — a single token family covers the whole widget.

| Token | Purpose |
|-------|---------|
| `--frontend-agent-chat-link` | Link color (defaults to accent) |
| `--frontend-agent-chat-error` / `--frontend-agent-chat-error-background` | Error text / fill |
| `--frontend-agent-chat-success` / `--frontend-agent-chat-success-background` | Success text / fill |
| `--frontend-agent-chat-warning` / `--frontend-agent-chat-warning-background` | Warning text / fill |

**Typography**

| Token | Purpose |
|-------|---------|
| `--frontend-agent-chat-font-family` / `--frontend-agent-chat-header-font-family` | Body / header font stack |
| `--frontend-agent-chat-font-weight-medium` / `-semibold` / `-bold` | Font weights |
| `--frontend-agent-chat-text-xs` / `-sm` / `-base` | Type scale |

**Radii**

| Token | Purpose |
|-------|---------|
| `--frontend-agent-chat-radius` | Base radius; `-xs` / `-sm` / `-md` / `-lg` / `-xl` derive from it |
| `--frontend-agent-chat-radius-full` | Pill / circular radius |

**Shadows**

| Token | Purpose |
|-------|---------|
| `--frontend-agent-chat-shadow-sm` / `-lg` | Card / drawer elevation |
| `--frontend-agent-chat-shadow-outline` / `-outline-strong` | Focus / outline rings |

**Spacing**

| Token | Purpose |
|-------|---------|
| `--frontend-agent-chat-spacing-base` | Base spacing unit; agenttic's `--spacing-1`…`--spacing-20` scale derives from it |
| `--frontend-agent-chat-spacing-xs` / `-sm` / `-md` / `-lg` | Chrome spacing steps |

**Dark mode** is the brand's responsibility: toggle `.dark` on the agenttic root (to use agenttic's dark defaults) or override the `--frontend-agent-chat-*` tokens inside an `@media (prefers-color-scheme: dark)` block.

## Development

```bash
npm install
npm run build    # wp-scripts, outputs to build/
```

No custom webpack config — standard `wp-scripts` auto-discovery from `src/index.ts`.

## License

GPL v2 or later
