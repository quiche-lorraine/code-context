<?php

declare(strict_types=1);

namespace CodeContext\Analyzer;

use CodeContext\Config\Config;
use CodeContext\Model\AttributeArgumentInfo;
use CodeContext\Model\AttributeInfo;
use CodeContext\Model\ClassInfo;
use CodeContext\Model\FunctionInfo;
use CodeContext\Model\MethodInfo;
use CodeContext\Model\ParameterInfo;
use CodeContext\Model\PropertyInfo;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;

/**
 * Parses PHP files into AST and converts class-like nodes into ClassInfo value objects.
 *
 * Uses nikic/php-parser's NameResolver so that FQCNs are available on every node, regardless of
 * `use` statements in the original source.
 */
final class PhpAstAnalyzer
{
    private readonly Parser $parser;
    private readonly NodeTraverser $traverser;
    private readonly StandardPrinter $printer;
    private readonly NodeFinder $finder;

    public function __construct(private readonly Config $config)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->traverser = new NodeTraverser();
        $this->traverser->addVisitor(new NameResolver());
        $this->printer = new StandardPrinter();
        $this->finder = new NodeFinder();
    }

    /**
     * Parses a single file and returns all class-like declarations found in it.
     *
     * @return list<ClassInfo>
     */
    public function analyze(string $absoluteFile, string $relativeFile): array
    {
        return $this->analyzeAll($absoluteFile, $relativeFile)[0];
    }

    /**
     * Parses a file once and returns both class-like declarations and standalone functions.
     *
     * @return array{0: list<ClassInfo>, 1: list<FunctionInfo>}
     */
    public function analyzeAll(string $absoluteFile, string $relativeFile): array
    {
        $ast = $this->parseFile($absoluteFile);
        if (null === $ast) {
            return [[], []];
        }

        /** @var list<Node\Stmt\ClassLike> $classLikes */
        $classLikes = $this->finder->findInstanceOf($ast, Node\Stmt\ClassLike::class);
        $classes = [];
        foreach ($classLikes as $node) {
            $info = $this->buildClassInfo($node, $relativeFile);
            if (null !== $info) {
                $classes[] = $info;
            }
        }

        /** @var list<Node\Stmt\Function_> $funcNodes */
        $funcNodes = $this->finder->findInstanceOf($ast, Node\Stmt\Function_::class);
        $functions = [];
        foreach ($funcNodes as $node) {
            $functions[] = $this->buildFunctionInfo($node, $relativeFile);
        }

        return [$classes, $functions];
    }

    /** @return array<\PhpParser\Node>|null */
    private function parseFile(string $absoluteFile): ?array
    {
        $source = @file_get_contents($absoluteFile);
        if (false === $source) {
            return null;
        }

        try {
            $ast = $this->parser->parse($source);
        } catch (\Throwable) {
            return null;
        }

        if (null === $ast) {
            return null;
        }

        return $this->traverser->traverse($ast);
    }

    private function buildFunctionInfo(Node\Stmt\Function_ $node, string $relativeFile): FunctionInfo
    {
        $namespace = '';
        if (null !== $node->namespacedName) {
            $fqn = $node->namespacedName->toString();
            $pos = strrpos($fqn, '\\');
            $namespace = false !== $pos ? substr($fqn, 0, $pos) : '';
        }

        $parameters = [];
        foreach ($node->params as $param) {
            $parameters[] = $this->buildParameterInfo($param);
        }

        return new FunctionInfo(
            name: $node->name->toString(),
            namespace: $namespace,
            file: $relativeFile,
            returnType: $this->renderType($node->returnType),
            parameters: $parameters,
            attributes: $this->extractAttributes($node),
            summary: $this->extractDocSummary($node),
        );
    }

    private function buildClassInfo(Node\Stmt\ClassLike $node, string $relativeFile): ?ClassInfo
    {
        if (null === $node->namespacedName) {
            return null;
        }

        $fqcn = $node->namespacedName->toString();
        $shortName = null !== $node->name ? $node->name->toString() : '';
        $namespace = $this->namespaceOf($fqcn);

        [$kind, $isAbstract, $isFinal, $isReadonly, $extends, $implements] = $this->classifyNode($node);
        $traits = $this->extractTraitUses($node);

        $methods = [];
        $properties = [];
        $cases = [];
        foreach ($node->stmts ?? [] as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $method = $this->buildMethodInfo($stmt);
                if (null !== $method) {
                    $methods[] = $method;
                }
                continue;
            }
            if ($stmt instanceof Node\Stmt\EnumCase) {
                $cases[] = $stmt->name->toString();
                continue;
            }
            if ($this->config->phpIncludeProperties() && $stmt instanceof Node\Stmt\Property) {
                foreach ($this->buildPropertyInfos($stmt) as $property) {
                    $properties[] = $property;
                }
            }
        }

        return new ClassInfo(
            fqcn: $fqcn,
            shortName: $shortName,
            namespace: $namespace,
            kind: $kind,
            file: $relativeFile,
            isAbstract: $isAbstract,
            isFinal: $isFinal,
            isReadonly: $isReadonly,
            extends: $extends,
            implements: $implements,
            traits: $traits,
            attributes: $this->extractAttributes($node),
            methods: $methods,
            properties: $properties,
            summary: $this->extractDocSummary($node),
            cases: $cases,
        );
    }

    /**
     * Returns [kind, isAbstract, isFinal, isReadonly, extends, implements].
     *
     * @return array{0: string, 1: bool, 2: bool, 3: bool, 4: ?string, 5: list<string>}
     */
    private function classifyNode(Node\Stmt\ClassLike $node): array
    {
        if ($node instanceof Node\Stmt\Interface_) {
            $extends = [];
            foreach ($node->extends as $parent) {
                $extends[] = $parent->toString();
            }

            return ['interface', false, false, false, [] === $extends ? null : implode(', ', $extends), []];
        }

        if ($node instanceof Node\Stmt\Trait_) {
            return ['trait', false, false, false, null, []];
        }

        if ($node instanceof Node\Stmt\Enum_) {
            $implements = array_map(static fn (Node\Name $n): string => $n->toString(), $node->implements);

            return ['enum', false, true, false, null, $implements];
        }

        if ($node instanceof Node\Stmt\Class_) {
            $extends = null !== $node->extends ? $node->extends->toString() : null;
            $implements = array_map(static fn (Node\Name $n): string => $n->toString(), $node->implements);

            return [
                'class',
                $node->isAbstract(),
                $node->isFinal(),
                $node->isReadonly(),
                $extends,
                $implements,
            ];
        }

        return ['unknown', false, false, false, null, []];
    }

    /**
     * @return list<string>
     */
    private function extractTraitUses(Node\Stmt\ClassLike $node): array
    {
        $traits = [];
        foreach ($node->stmts ?? [] as $stmt) {
            if ($stmt instanceof Node\Stmt\TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $traits[] = $trait->toString();
                }
            }
        }

        return $traits;
    }

    private function buildMethodInfo(Node\Stmt\ClassMethod $node): ?MethodInfo
    {
        $visibility = $this->visibilityFromFlags($node->flags);
        if (!$this->shouldIncludeVisibility($visibility)) {
            return null;
        }

        $parameters = [];
        foreach ($node->params as $param) {
            $parameters[] = $this->buildParameterInfo($param);
        }

        return new MethodInfo(
            name: $node->name->toString(),
            visibility: $visibility,
            isStatic: $node->isStatic(),
            isAbstract: $node->isAbstract(),
            isFinal: $node->isFinal(),
            returnType: $this->renderType($node->returnType),
            parameters: $parameters,
            attributes: $this->extractAttributes($node),
            summary: $this->extractDocSummary($node),
        );
    }

    private function buildParameterInfo(Node\Param $param): ParameterInfo
    {
        $name = $param->var instanceof Node\Expr\Variable && \is_string($param->var->name)
            ? $param->var->name
            : '';

        $default = null;
        $hasDefault = null !== $param->default;
        if ($hasDefault && null !== $param->default) {
            $default = $this->printExpr($param->default);
        }

        return new ParameterInfo(
            name: $name,
            type: $this->renderType($param->type),
            hasDefault: $hasDefault,
            default: $default,
            variadic: $param->variadic,
            byReference: $param->byRef,
            attributes: $this->extractAttributes($param),
        );
    }

    /**
     * A single property statement may declare several names (e.g. `public int $a, $b;`).
     *
     * @return list<PropertyInfo>
     */
    private function buildPropertyInfos(Node\Stmt\Property $node): array
    {
        $visibility = $this->visibilityFromFlags($node->flags);
        if (!$this->shouldIncludeVisibility($visibility)) {
            return [];
        }

        $type = $this->renderType($node->type);
        $isReadonly = (bool) ($node->flags & Modifiers::READONLY);
        $isStatic = (bool) ($node->flags & Modifiers::STATIC);
        $attributes = $this->extractAttributes($node);
        $summary = $this->extractDocSummary($node);

        $infos = [];
        foreach ($node->props as $prop) {
            $default = null !== $prop->default ? $this->printExpr($prop->default) : null;
            $infos[] = new PropertyInfo(
                name: $prop->name->toString(),
                visibility: $visibility,
                isStatic: $isStatic,
                isReadonly: $isReadonly,
                type: $type,
                default: $default,
                attributes: $attributes,
                summary: $summary,
            );
        }

        return $infos;
    }

    /**
     * @return list<AttributeInfo>
     */
    private function extractAttributes(Node $node): array
    {
        if (!$this->config->phpIncludeAttributes()) {
            return [];
        }

        if (!property_exists($node, 'attrGroups')) {
            return [];
        }

        $attributes = [];
        /** @var list<Node\AttributeGroup> $groups */
        $groups = $node->attrGroups ?? [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                $arguments = [];
                foreach ($attr->args as $arg) {
                    $arguments[] = new AttributeArgumentInfo(
                        name: null !== $arg->name ? $arg->name->toString() : null,
                        value: $this->printExpr($arg->value),
                    );
                }
                $attributes[] = new AttributeInfo($attr->name->toString(), $arguments);
            }
        }

        return $attributes;
    }

    private function extractDocSummary(Node $node): ?string
    {
        $level = $this->config->phpDocLevel();
        if ('none' === $level) {
            return null;
        }

        $doc = $node->getDocComment();
        if (null === $doc) {
            return null;
        }

        $text = $this->cleanDocBlock($doc->getText());
        if ('' === $text) {
            return null;
        }

        if ('first_line' === $level) {
            $firstLine = strtok($text, "\n");

            return false === $firstLine ? null : trim($firstLine);
        }

        return $text;
    }

    private function cleanDocBlock(string $raw): string
    {
        $lines = preg_split('/\R/u', $raw) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = preg_replace('#^\s*/\*\*+#', '', $line) ?? $line;
            $line = preg_replace('#\*+/\s*$#', '', $line) ?? $line;
            $line = preg_replace('#^\s*\*\s?#', '', $line) ?? $line;
            $clean[] = rtrim($line);
        }

        return trim(implode("\n", array_filter($clean, static fn (string $l): bool => '' !== trim($l))));
    }

    private function renderType(?Node $typeNode): ?string
    {
        if (null === $typeNode) {
            return null;
        }

        if ($typeNode instanceof Node\Identifier) {
            return $typeNode->toString();
        }
        if ($typeNode instanceof Node\Name) {
            return $typeNode->toString();
        }
        if ($typeNode instanceof Node\NullableType) {
            $inner = $this->renderType($typeNode->type);

            return null === $inner ? null : '?' . $inner;
        }
        if ($typeNode instanceof Node\UnionType) {
            $parts = array_map(fn (Node $t): ?string => $this->renderType($t), $typeNode->types);

            return implode('|', array_filter($parts, static fn (?string $p): bool => null !== $p));
        }
        if ($typeNode instanceof Node\IntersectionType) {
            $parts = array_map(fn (Node $t): ?string => $this->renderType($t), $typeNode->types);

            return implode('&', array_filter($parts, static fn (?string $p): bool => null !== $p));
        }

        return null;
    }

    private function printExpr(Node $node): string
    {
        if ($node instanceof Node\Expr) {
            return $this->printer->prettyPrintExpr($node);
        }

        return '';
    }

    private function visibilityFromFlags(int $flags): string
    {
        if ($flags & Modifiers::PRIVATE) {
            return 'private';
        }
        if ($flags & Modifiers::PROTECTED) {
            return 'protected';
        }

        return 'public';
    }

    private function shouldIncludeVisibility(string $visibility): bool
    {
        return match ($visibility) {
            'private' => $this->config->phpIncludePrivate(),
            'protected' => $this->config->phpIncludeProtected(),
            default => true,
        };
    }

    private function namespaceOf(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return false === $pos ? '' : substr($fqcn, 0, $pos);
    }
}
