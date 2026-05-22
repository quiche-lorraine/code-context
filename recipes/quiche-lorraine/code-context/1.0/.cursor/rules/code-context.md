---
description: Use code-context MCP tools and AGENTS.md for codebase navigation before grepping.
alwaysApply: true
---

# code-context

This project uses [code-context](https://github.com/quiche-lorraine/code-context) to maintain
a typed index of the codebase. Always prefer the MCP tools below over raw file reads or grep.

## MCP Server

The `code-context` MCP server is configured in `.mcp.json` and exposes:

- **`search_symbol`** — find a class by name fragment
- **`get_class`** — full class details (methods, properties, parent, traits)
- **`find_implementations`** — all implementations of an interface
- **`find_subclasses`** — all subclasses of a class
- **`find_usages`** — all references to a type (useful for refactoring)
- **`get_routes`** — all routes with method/path/name
- **`get_namespace`** — list all classes in a namespace

Use these tools instead of `grep` or reading individual files for symbol lookup.

## Narrative overview

Start every task by reading `code-context-out/AGENTS.md` — it provides a
namespace-by-namespace tour of the codebase with classes and their public API.

Other views in `code-context-out/`:
- `architecture.md` — volume metrics and global structure
- `entities.md` — Doctrine entities and their fields
- `routes.md` — all API/Symfony routes
- `services.md` — registered container services
- `commands.md` — console commands

## Rebuild the index

```bash
vendor/bin/code-context generate
```
