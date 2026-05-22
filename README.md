# code-context

CLI standalone (basé sur `symfony/console`) qui analyse une base de code PHP et produit un dossier de contexte destiné aux agents IA, composé d'un `context.json` structuré et de plusieurs `.md` orientés agents.

> Statut : **Phase 2 en cours**. Le socle est opérationnel : commandes `init`, `generate`, `serve`, validation stricte de config, architecture extracteurs, détection Symfony, fichiers `.md` thématiques.

## Installation

```bash
composer require --dev quiche-lorraine/code-context
```

Puis initialisez la configuration des agents :

```bash
vendor/bin/code-context init --agent=mcp         # merge .mcp.json (préserve les autres serveurs)
vendor/bin/code-context init --agent=claude-code  # écrit .claude/settings.json + hook rebuild
vendor/bin/code-context init --agent=cursor       # écrit .cursor/rules/code-context.md
vendor/bin/code-context init --agent=all          # les trois agents d'un coup
```

Puis générez le premier index :

```bash
vendor/bin/code-context generate
```

## Commande `init --agent`

```bash
vendor/bin/code-context init --agent=mcp         # merge dans .mcp.json (préserve les autres serveurs)
vendor/bin/code-context init --agent=claude-code  # écrit .claude/settings.json + hook (skip si déjà présent)
vendor/bin/code-context init --agent=cursor       # écrit .cursor/rules/code-context.md (skip si déjà présent)
vendor/bin/code-context init --agent=all          # tous les agents
vendor/bin/code-context init --agent=mcp --dry-run  # affiche le résultat sans écrire
```

Le merge MCP est idempotent : une seconde exécution est no-op si la config est déjà à jour.

Pour `--agent=claude-code` et `--agent=cursor` : si le fichier cible existe déjà, la commande l'ignore (pas de clobber).

**Projets avec un `session-start.sh` existant** : ajoutez l'appel au hook depuis votre orchestrateur :

```bash
if [ -x .claude/hooks/code-context-rebuild.sh ]; then
    .claude/hooks/code-context-rebuild.sh
fi
```

## Utilisation

Options principales :

- `generate --config=PATH` : chemin vers un fichier `code-context.yaml`.
- `generate --output=DIR` : surcharge `output.directory` du YAML.
- `generate --cwd=DIR` : analyser un autre répertoire que le cwd courant.

Sortie générée dans `<output.directory>` (par défaut `.code-context/`) :

- `context.json` : représentation structurée consommée par le serveur MCP.
- `AGENTS.md` : vue narrative regroupée par namespace.
- `architecture.md`, `routes.md`, `entities.md`, `services.md`, `commands.md` : vues Symfony spécialisées.

## Hook SessionStart (Claude Code)

Le fichier `.claude/hooks/code-context-rebuild.sh` (installé par `init --agent=claude-code`) reconstruit l'index à chaque démarrage de session Claude Code.

## Architecture

```
bin/code-context           Entrypoint Symfony Console
src/
    Application.php
    Command/
        InitCommand        init --agent=mcp|claude-code|cursor|all + legacy yaml init
        GenerateCommand    Orchestre scan + extracteurs + renderers
        ServeCommand       Serveur MCP stdio
    Config/
        Config             Value-object immuable
        ConfigLoader       default.yaml + override utilisateur + validation
        ConfigSchema       Schéma strict via symfony/config
    Detector/
        SymfonyDetector    Détection du framework Symfony
    Extractor/             ComposerExtractor, PhpStructureExtractor, SymfonyExtractor, ...
    Kernel/
        ProjectContext     Résolution des chemins du projet cible
    Mcp/
        McpManifestMerger  Merge non-destructif de .mcp.json (idémpotent)
        MergeResult        DTO résultat du merge
    Scanner/
        FileScanner        Découverte via symfony/finder
    Analyzer/
        PhpAstAnalyzer     AST via nikic/php-parser
    Model/                 DTO immuables: Context, ClassInfo, MethodInfo, ...
    Renderer/
        JsonRenderer       context.json
        Markdown/          AGENTS.md, architecture.md, routes.md, etc.
    Output/
        OutputWriter       Écriture sur disque
config/
    default.yaml           Configuration par défaut bundled
recipes/
    quiche-lorraine/code-context/1.0/
        manifest.json      gitignore /.code-context/, copie hook + cursor rules
        .claude/           settings.json + hooks/code-context-rebuild.sh
        .cursor/           rules/code-context.md
    quiche-lorraine/code-context/dev-main/
        manifest.json      (idem, pour installation depuis la branche main)
resources/agents/
    claude-code/           Source pour init --agent=claude-code
    cursor/                Source pour init --agent=cursor
```

## Qualité de code

- `composer test` (PHPUnit)
- `composer lint` (PHPStan)
- `composer fix` (php-cs-fixer)
