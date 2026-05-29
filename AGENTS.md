# AGENTS.md

Instructions for AI coding agents (Claude Code and any tool that reads `AGENTS.md`)
working in this repository.

## Project

`code-context` is a standalone PHP CLI (built on Symfony Console) that statically
analyzes a PHP codebase and generates an AI-friendly context: a structured
`context.json` plus Markdown files (`AGENTS.md`, architecture, routes, entities,
services, commands). It also ships an MCP server (`serve`) that exposes query tools
over the generated index.

## Working language

- Write **all pull request titles and descriptions in English**.
- Write **all commit messages in English**.
- This rule applies to artifacts committed to the repository. Conversations with
  maintainers may happen in another language.

## Code style & quality

- Every PHP file declares `strict_types=1`.
- Coding standard: `@PSR12` + `declare_strict_types` (config in `.php-cs-fixer.php`).
- Static analysis: PHPStan level 6 (`phpstan.neon`); test fixtures are excluded.

## Commands

- `composer test` — run the PHPUnit suite
- `composer lint` — run PHPStan
- `composer cs` — check the code style (dry-run, no changes)
- `composer fix` — apply code-style fixes
- `php bin/code-context generate` — generate the context for the target project

## Before opening a PR

Make sure `composer test`, `composer lint` and `composer cs` all pass. CI runs them
on PHP 8.2 / 8.3 / 8.4 against both the lowest and the highest supported dependency
versions.
