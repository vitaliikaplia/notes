# Notes

A personal, self-hosted notes app.

Write notes in a block editor (text, checklists, tables, code, images, galleries, videos), keep them in a tree, share a note by link, and download it as Markdown, PDF or Word. Sign in with a password or a passkey (Touch ID / Face ID). AI agents can read and edit notes through the built-in MCP server.

It runs on PHP 8.5 with MySQL/MariaDB and no framework. The live version is at https://notes.kaplia.pro.

To run it locally, copy `config.example.php` to `config.php`, fill in the database connection and point a web server at `index.php`. The database tables create themselves on the first request.

Everything else about the project (architecture, decisions, conventions, tasks) lives in Memo, project #13.
