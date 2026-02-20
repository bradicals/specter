# 👻 Specter

A floating kanban board that lives on the edge of your Windows desktop. It hides as an invisible tab until you hover over it, pops open when you need it, and gets out of the way when you don't.

Cards come from Axosoft via a one-click browser extension, Claude can manage your board through MCP, and everything is stored locally — no accounts, no sync, no cloud.

---

## What it does

- Floating kanban board with customizable columns (To Do, In Progress, Blocked, Done by default)
- Stashes to the right edge of your screen — invisible until you hover, click to bring it back
- `Ctrl + Shift + S` to toggle it from anywhere
- One-click Axosoft import via browser extension (grabs ticket ID, title, description, images, links, testing doc)
- Claude (Code or Desktop) can add, move, and update cards via MCP
- Drag cards between columns, set priority, add notes and links
- All data is just a local JSON file

---

## Requirements

- **PHP 8+** on PATH — check with `php -v`
- **.NET 8 SDK** — check with `dotnet --version`
- **WebView2 Runtime** — already on Windows 11, otherwise grab it from Microsoft

---

## Getting started

Build the launcher once:

```bat
cd launcher
dotnet build -c Release
```

Then just run:

```bat
start.bat
```

PHP server starts in the background, the window appears, and everything shuts down cleanly when you close it.

---

## Browser extension

The `extension/` folder is a Chrome extension that lets you send any Axosoft ticket to Specter with one click. It pulls the ticket ID, title, description, attachments, and links — no copy-pasting.

To install it:

1. Go to `chrome://extensions`
2. Enable **Developer mode**
3. Click **Load unpacked** and select the `extension/` folder

When you're on an Axosoft ticket, click the Specter icon in the toolbar and it'll show up on your board.

---

## MCP — letting Claude manage your board

Specter has a built-in MCP server (`mcp.php`) so Claude can read and write cards directly.

**Claude Code:**

```bat
claude mcp add --scope user specter-kanban -- php "C:\path\to\Specter\mcp.php"
```

**Claude Desktop** — add to `%APPDATA%\Claude\claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "specter-kanban": {
      "command": "php",
      "args": ["C:\\path\\to\\Specter\\mcp.php"]
    }
  }
}
```

Then you can just say things like:
- *"Add a high priority card for the login bug to To Do"*
- *"Move QA-101 to In Progress"*
- *"What's sitting in Blocked right now?"*

Available tools: `list_cards`, `add_card`, `move_card`, `update_card`, `delete_card`, `list_columns`, `add_column`, `rename_column`, `delete_column`

---

## File structure

```
Specter/
├── extension/
│   ├── background.js       Axosoft scraper + Specter import logic
│   ├── manifest.json
│   └── icon.png
├── launcher/
│   ├── Program.cs          C# WinForms + WebView2 host
│   └── launcher.csproj
├── data/
│   └── kanban.json         Your board data (auto-created on first run)
├── server.php              PHP server, REST API, and the whole UI
├── mcp.php                 MCP server for Claude integration
├── start.bat               Run this
└── README.md
```

Board data lives in `data/kanban.json`. The UI polls every couple seconds, so you can edit it manually and changes will show up automatically.
