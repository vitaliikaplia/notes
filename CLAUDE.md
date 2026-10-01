# CLAUDE.md

All project knowledge lives in **Memo** (MCP server `memo`), **project #13**: architecture, decisions, conventions, workflow rules, tasks, known issues and the current status. This file only points there.

Notes is a self-hosted notes app (PHP 8.5, Twig 3, Editor.js, MySQL/MariaDB). Live: https://notes.kaplia.pro, local: https://notes.test.

## How to work

1. Start with `memo_structure` for project 13: the card, the map of halls and rooms, and the protocol.
2. Before deciding or answering about what was decided, search with `memo_find` (hall + room + a short query).
3. After every finished task, update the records in Memo: new knowledge, `superseded` / `outdated` for what changed, `verified` for what you re-checked. Do not keep project knowledge in Markdown files; there is no STATUS.md.
4. Before a context compaction, write the current status as a task in the room `compaction`. After the compaction, read it first, continue, and close it when done.
5. If the Memo tools are not available, say so instead of guessing.

Two rules hold even without Memo: commit and push only when Vitalii asks for it in the current message, and never print secrets such as the MCP token.
