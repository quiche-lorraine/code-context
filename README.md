# code-context

CLI standalone (basé sur `symfony/console`) qui analyse une base de code PHP et produit un dossier de contexte destiné aux agents IA, composé d'un `context.json` structuré et de plusieurs `.md` orientés agents.

> Statut : **Phase 2 en cours**. Le socle est opérationnel : commandes `init`, `generate`, `serve`, validation stricte de config, architecture extracteurs, détection Symfony, fichiers `.md` thématiques.

## Installation dans un projet Symfony Flex

Déclarez l'endpoint Flex privé dans le `composer.json` du projet hôte :

```json
{
    "extra": {
        "symfony": {
            "endpoint": [
                "https://raw.githubusercontent.com/quiche-lorraine/code-context/main/recipes",
                "flex://defaults"
            ]
        }
    }
}
```

Puis :

```bash
composer require --dev quiche-lorraine/code-context
```

Symfony Flex applique la recette automatiquement :
- crée `.claude/hooks/code-context-rebuild.sh` (hook SessionStart)
- crée `.cursor/rules/code-context.md` (règles Cursor)
- ajoute `/code-context-out/` au `.gitignore`
- si le projet n'a pas encore de `.claude/settings.json`, le crée avec le hook SessionStart pré-câblé

Enregistrez ensuite le serveur MCP (merge non-destructif, préserve les serveurs existants) :

```bash
vendor/bin/code-context init --agent=mcp
```

Puis générez le premier index :

```bash
vendor/bin/code-context generate
```

## Installation sans Symfony Flex

```bash
composer require --dev quiche-lorraine/code-context
vendor/bin/code-context init --agent=all   # configure MCP + Claude Code + Cursor
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

Pour `--agent=claude-code` et `--agent=cursor` : si le fichier cible existe déjà, la commande l'ignore et affiche un message « already exists, skipping ». Pas de clobber.

**Projets avec un `session-start.sh` existant** : la recette crée `.claude/hooks/code-context-rebuild.sh` mais ne modifie pas un `settings.json` existant. Ajoutez simplement l'appel dans votre orchestrateur :

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

Sortie générée dans `<output.directory>` (par défaut `code-context-out/`) :

- `context.json` : représentation structurée consommée par le serveur MCP.
- `AGENTS.md` : vue narrative regroupée par namespace.
- `architecture.md`, `routes.md`, `entities.md`, `services.md`, `commands.md` : vues Symfony spécialisées.

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
    Extractor/
        ExtractorInterface
        ExtractorRegistry
        ComposerExtractor
        DocsExtractor
        PhpStructureExtractor
        SymfonyExtractor
        Symfony/*          Routes, services, entities, commands
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
    index.json             Endpoint Symfony Flex privé
    quiche-lorraine/
        code-context/
            dev-main/
                manifest.json                  Copie hook + cursor rules, gitignore /code-context-out/
                .claude/settings.json          Hook SessionStart pour projets sans config Claude
                .claude/hooks/code-context-rebuild.sh
                .cursor/rules/code-context.md
resources/
    agents/
        claude-code/       Miroir hors-recipe (.claude/settings.json + hook rebuild)
        cursor/            Miroir hors-recipe (.cursor/rules/code-context.md)
```

## Qualité de code

- `composer test` (PHPUnit)
- `composer lint` (PHPStan)
- `composer fix` (php-cs-fixer)
