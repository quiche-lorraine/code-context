# code-context

Standalone CLI (built on `symfony/console`) that analyses a PHP codebase and produces a context folder for AI agents, consisting of a structured `context.json` and several agent-oriented `.md` files.

> Status: **Phase 2 in progress**. The foundation is operational: `init`, `generate`, `serve` commands, strict config validation, extractor architecture, Symfony detection, thematic `.md` files.

## Installation

```bash
composer require --dev quiche-lorraine/code-context
```

Then initialise the agent configuration:

```bash
vendor/bin/code-context init --agent=mcp         # merge .mcp.json (preserves other servers)
vendor/bin/code-context init --agent=claude-code  # writes .claude/settings.json + rebuild hook
vendor/bin/code-context init --agent=cursor       # writes .cursor/rules/code-context.md
vendor/bin/code-context init --agent=all          # all three agents at once
```

Then generate the first index:

```bash
vendor/bin/code-context generate
```

## `init --agent` command

```bash
vendor/bin/code-context init --agent=mcp         # merge into .mcp.json (preserves other servers)
vendor/bin/code-context init --agent=claude-code  # writes .claude/settings.json + hook (skipped if already present)
vendor/bin/code-context init --agent=cursor       # writes .cursor/rules/code-context.md (skipped if already present)
vendor/bin/code-context init --agent=all          # all agents
vendor/bin/code-context init --agent=mcp --dry-run  # prints the result without writing
```

MCP merge is idempotent: a second run is a no-op if the config is already up to date.

For `--agent=claude-code` and `--agent=cursor`: if the target file already exists, the command leaves it untouched (no clobber).

**Projects with an existing `session-start.sh`**: add the hook call from your orchestrator:

```bash
if [ -x .claude/hooks/code-context-rebuild.sh ]; then
    .claude/hooks/code-context-rebuild.sh
fi
```

## Usage

Main options:

- `generate --config=PATH`: path to a `code-context.yaml` file.
- `generate --output=DIR`: overrides `output.directory` from the YAML.
- `generate --cwd=DIR`: analyse a directory other than the current working directory.

Output generated in `<output.directory>` (default `.code-context/`):

- `context.json`: structured representation consumed by the MCP server.
- `AGENTS.md`: narrative view grouped by namespace.
- `architecture.md`, `routes.md`, `entities.md`, `services.md`, `commands.md`: specialised Symfony views.

## SessionStart Hook (Claude Code)

The `.claude/hooks/code-context-rebuild.sh` file (installed by `init --agent=claude-code`) rebuilds the index at every Claude Code session start.

## Architecture

```
bin/code-context           Symfony Console entrypoint
src/
    Application.php
    Command/
        InitCommand        init --agent=mcp|claude-code|cursor|all + legacy yaml init
        GenerateCommand    Orchestrates scan + extractors + renderers
        ServeCommand       MCP stdio server
    Config/
        Config             Immutable value object
        ConfigLoader       default.yaml + user override + validation
        ConfigSchema       Strict schema via symfony/config
    Detector/
        SymfonyDetector    Symfony framework detection
    Extractor/             ComposerExtractor, PhpStructureExtractor, SymfonyExtractor, ...
    Kernel/
        ProjectContext     Target project path resolution
    Mcp/
        McpManifestMerger  Non-destructive merge of .mcp.json (idempotent)
        MergeResult        Merge result DTO
    Scanner/
        FileScanner        Discovery via symfony/finder
    Analyzer/
        PhpAstAnalyzer     AST via nikic/php-parser
    Model/                 Immutable DTOs: Context, ClassInfo, MethodInfo, ...
    Renderer/
        JsonRenderer       context.json
        Markdown/          AGENTS.md, architecture.md, routes.md, etc.
    Output/
        OutputWriter       Writes to disk
config/
    default.yaml           Bundled default configuration
recipes/
    quiche-lorraine/code-context/1.0/
        manifest.json      gitignore /.code-context/, copies hook + cursor rules
        .claude/           settings.json + hooks/code-context-rebuild.sh
        .cursor/           rules/code-context.md
    quiche-lorraine/code-context/dev-main/
        manifest.json      (same, for installation from the main branch)
resources/agents/
    claude-code/           Source for init --agent=claude-code
    cursor/                Source for init --agent=cursor
```

## Code quality

- `composer test` (PHPUnit)
- `composer lint` (PHPStan)
- `composer fix` (php-cs-fixer)
