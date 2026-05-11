# code-context

CLI standalone (basé sur `symfony/console`) qui analyse une base de code PHP et produit un dossier de contexte destiné aux agents IA, composé d'un `context.json` structuré et de plusieurs `.md` orientés agents.

> Statut : **Phase 2 en cours**. Le socle est opérationnel : commande `init`, validation stricte de config, architecture extracteurs, détection Symfony, fichiers `.md` thématiques. Les tests automatisés et la QA statique restent à finaliser.

## Installation

Le package est conçu pour être versionné en local dans `packages/code-context/` et utilisé sans publication.

```bash
cd packages/code-context
composer install
```

Si le projet hôte expose déjà `symfony/console`, `symfony/yaml`, `symfony/finder`, `symfony/filesystem` et `nikic/php-parser` dans son propre `vendor/`, le binaire les détectera automatiquement (utile pour le développement, mais une installation propre via Composer reste recommandée).

## Recette Symfony Flex (auto-install de `.mcp.json`)

Ce package expose une recette Symfony Flex privée qui copie automatiquement un fichier `.mcp.json` à la racine du projet lors du `composer require`.

### Configuration du endpoint privé

Dans le `composer.json` du projet hôte, déclarez l'endpoint Flex privé :

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

### Installation via Flex

```bash
composer require --dev quiche-lorraine/code-context
```

Symfony Flex détecte la recette et copie `.mcp.json` à la racine du projet. Ce fichier configure le serveur MCP `code-context` pour les agents IA.

## Utilisation

À la racine d'un projet PHP :

```bash
php packages/code-context/bin/code-context init
php packages/code-context/bin/code-context generate
```

Options principales :

- `--config=PATH` : chemin vers un fichier `code-context.yaml` (par défaut, le fichier homonyme à la racine si présent ; sinon les valeurs par défaut bundled).
- `--output=DIR` : surcharge `output.directory` du YAML.
- `--cwd=DIR` : analyser un autre répertoire que le cwd courant.

Sortie générée dans `<output.directory>` (par défaut `.code-context/`) :

- `context.json` : représentation structurée et stable, conçue pour être consommée par une IA.
- `AGENTS.md` : vue narrative regroupée par namespace, listant chaque classe et ses méthodes publiques.
- `architecture.md` : vue synthétique des volumes de code extraits.
- `routes.md`, `entities.md`, `services.md`, `commands.md` : vues Symfony spécialisées.

### Données ajoutées dans `context.json`

- `project.characters` : nombre total de caractères scannés (fichiers PHP inclus).
- `project.estimated_tokens` : estimation rapide (`characters / 4`, arrondi supérieur).
- `php.summary` : métriques globales de structure.
- `composer`, `docs`, `symfony` : sections extraites par extracteurs dédiés.

## Configuration

Tous les comportements sont pilotés par un fichier YAML (mergé sur `config/default.yaml` du package). Exemple minimal `code-context.yaml` :

```yaml
code_context:
    paths:
        include: ['src/', 'config/']
        exclude: ['vendor/', 'var/', 'tests/']
    analyzers:
        php:
            include_phpdoc: 'first_line'   # none | first_line | full
            include_private: false
    rendering:
        json:
            pretty: true
            strip_nulls: true
```

Voir [`config/default.yaml`](config/default.yaml) pour l'ensemble des clés supportées.

## Architecture (Phase 2 courante)

```
bin/code-context           Entrypoint Symfony Console
src/
    Application.php        Application Symfony Console
    Command/
        InitCommand        Génère code-context.yaml
        GenerateCommand    Orchestre scan + extracteurs + renderers
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
    Scanner/
        FileScanner        Découverte via symfony/finder
    Analyzer/
        PhpAstAnalyzer     AST via nikic/php-parser
    Model/                 DTO immuables: Context, ClassInfo, MethodInfo, ...
    Renderer/
        JsonRenderer       context.json
        Markdown/
            AgentsMdRenderer       AGENTS.md
            ArchitectureMdRenderer architecture.md
            RoutesMdRenderer       routes.md
            EntitiesMdRenderer     entities.md
            ServicesMdRenderer     services.md
            CommandsMdRenderer     commands.md
    Output/
        OutputWriter       Écriture sur disque
config/
    default.yaml           Configuration par défaut bundled
recipes/
    index.json             Endpoint Symfony Flex privé
    quiche-lorraine/
        code-context/
            dev-main/
                manifest.json   Copie .mcp.json dans le projet hôte
                .mcp.json       Template de configuration MCP
```

## Roadmap (reste à faire)

- Durcir et étendre la suite de tests d'intégration.
- Exécuter systématiquement phpstan/php-cs-fixer en CI.

## Qualité de code

Scripts Composer disponibles :

- `composer test` (PHPUnit)
- `composer lint` (PHPStan)
- `composer fix` (php-cs-fixer)

Fichiers de config inclus :

- `phpunit.xml.dist`
- `phpstan.neon`
- `.php-cs-fixer.php`
