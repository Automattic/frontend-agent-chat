# Changelog

## [0.13.1] - 2026-06-18

### Changed
- Use chat package without question freeform input

## [0.13.0] - 2026-06-18

### Added
- emit action-resolved event on pending-action resolve

## [0.12.0] - 2026-06-18

### Added
- pass browser-sent client_context through to the agent

## [0.11.1] - 2026-06-13

### Changed
- Support brain-aware chat metadata

## [0.11.0] - 2026-06-04

### Added
- add retrieval diagnostics panel

### Changed
- Migrate frontend chat onto shared chat primitives
- bump @extrachill/chat to v0.14.0
- Tighten retrieval state metadata parsing
- Align source cards with generic citation identifiers
- Add retrieval state indicators
- Add source card rendering for chat citations

### Fixed
- keep diagnostics panel generic

## [0.10.0] - 2026-05-31

### Added
- add present_question generic question-card renderer key
- forward current page url to chat REST requests

### Fixed
- remove Studio Web renderer coupling

## [0.9.2] - 2026-05-31

### Changed
- bump @extrachill/chat to v0.13.0

### Fixed
- de-nest ternaries in artifact thumbnail and status helpers
- consume chat run events
- render artifact phase status cards

## [0.9.1] - 2026-05-29

### Fixed
- satisfy frontend chat release gates
- remove redundant question freeform input

## [0.9.0] - 2026-05-29

### Added
- emit chat lifecycle events
- wire chat run controls
- add chat run-control REST adapter
- render question tool cards
- pass message suggestions to chat
- dispatch chat response metadata
- support inline chat layout
- support svg fab icons
- allow domain plugins to expose chat agents

### Changed
- use canonical run-control capability probe

### Fixed
- remove Data Machine agent preference coupling
- preserve agent when restoring sessions
- clarify site generator tool card
- bootstrap browser sessions before chat mount
- detect default run-control abilities
- detect run-control handlers per agent
- restore chat bubble radius
- render Studio Web generation tool status
- resolve browser principals for session history
- preserve tool call IDs
- pin chat question card behavior
- hide default persistence banner

## [0.8.7] - 2026-05-25

### Fixed
- preserve multimodal content + attachments on session reload

## [0.8.6] - 2026-05-21

### Fixed
- omit icon slot entirely when fab_icon is empty

## [0.8.5] - 2026-05-21

### Fixed
- satisfy WordPress Yoda condition rule in browser session checks
- remote_path should be relative to wp-root, not workspace
- allow browser chat session listing
- offset expanded chat below admin bar

## [0.8.4] - 2026-05-19

### Fixed
- allow expand icon overrides
- use svg expand icon

## [0.8.3] - 2026-05-19

### Fixed
- update chat UI styles
- use selectable chat theme colors

## [0.8.2] - 2026-05-19

### Fixed
- load default chat session

## [0.8.1] - 2026-05-18

### Fixed
- make frontend chat branding configurable
- improve chat surface legibility

## [0.8.0] - 2026-05-17

### Added
- add expanded frontend chat viewport
- support request default chat agent
- filter frontend chat input
- persist selected frontend agent
- add frontend agent switcher

### Changed
- Add browser principal chat persistence
- Allow principal-based frontend chat access
- Migrate frontend chat to Agents API

### Fixed
- route session listing through frontend filters
- list chat sessions with chat context
- expose generic persistence CTA
- send browser execution principal
- pass browser transcript owner
- hide tool transcript messages
- separate brain chat launcher icon
- update frontend chat fab label

## [0.7.3] - 2026-05-03

### Fixed
- Fix broken Accept/Reject: call /actions/resolve, not /diff/resolve

## [0.7.1] - 2026-04-07

### Changed
- align CSS with DM core tokens (text-primary, border-1, bg-light, blue)

## [0.7.0] - 2026-04-07

### Added
- FAB border defaults to theme --border-color token
- add --datamachine-fab-border-color CSS custom property to FAB

### Changed
- namespace all CSS custom properties to --datamachine-*

## [0.6.0] - 2026-04-07

### Added
- add network-wide config fallback for multisite

### Fixed
- phpstan isset warning on loading_messages, gitignore build meta

## [0.5.0] - 2026-04-02

### Added
- add FAB unread badge, escape key, safe-area inset
- wire mediaUploadFn for WordPress media library uploads
- expose loadingMessages config from PHP to JS

### Changed
- remove all Roadie references from source files

### Fixed
- remove admin bar offset on mobile where the bar scrolls away

## [0.4.5] - 2026-03-29

### Fixed
- correct mount selector to match PHP container attribute

## [0.4.4] - 2026-03-29

### Fixed
- use portable SessionSwitcher from @extrachill/chat, remove custom session header

## [0.4.3] - 2026-03-29

### Fixed
- hide duplicate session list and fix unicode close button

## [0.4.2] - 2026-03-29

### Changed
- update @extrachill/chat to ^0.8.0

## [0.4.1] - 2026-03-29

### Changed
- remove visibility system, defer to DM's can_access_agent

## [0.4.0] - 2026-03-29

### Added
- enable cycling loading messages in Roadie chat

### Changed
- rename to data-machine-frontend-chat (generic DM widget)

### Fixed
- PHPCS alignment warnings in config.php
- remove Network: true — per-site activation only

## [0.3.0] - 2026-03-26

### Added
- render canonical preview diffs in Roadie

### Changed
- use shared chat client context metadata

### Fixed
- keep Roadie non-modal during page collaboration

## [0.2.0] - 2026-03-25

### Added
- wire DiffCard for content-editing tool previews

### Changed
- add README
- initial release: floating agent chat for the Extra Chill network

### Fixed
- offset drawer and backdrop for WordPress admin bar

## [0.1.0] - 2026-03-25

### Added
- Initial release: floating agent chat widget for the Extra Chill network
- Per-site configuration via `data_machine_frontend_chat_config` option and filter
- Agent resolved by slug from Data Machine agents table
- Visibility modes: team, logged_in, public
- Slide-in drawer with persistent chat state across open/close
- CSS variable theming via @extrachill/chat token mapping
