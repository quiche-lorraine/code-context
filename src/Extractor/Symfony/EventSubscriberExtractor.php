<?php

declare(strict_types=1);

namespace CodeContext\Extractor\Symfony;

use CodeContext\Config\Config;
use CodeContext\Kernel\ProjectContext;
use CodeContext\Model\Context;
use PhpParser\Node;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Extracts EventSubscriber metadata: classes implementing EventSubscriberInterface
 * plus the events they subscribe to (parsed from getSubscribedEvents()).
 */
final class EventSubscriberExtractor
{
    private const SUBSCRIBER_INTERFACE = 'Symfony\\Component\\EventDispatcher\\EventSubscriberInterface';

    public function extract(ProjectContext $project, Config $config, Context $context): void
    {
        if (!$config->symfonyEventSubscribersEnabled()) {
            return;
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $printer = new PrettyPrinter();
        $subscribers = [];

        foreach ($context->classes as $class) {
            if (!$this->implementsSubscriberInterface($class->implements)) {
                continue;
            }

            $absoluteFile = $project->rootDir . '/' . $class->file;
            if (!is_file($absoluteFile)) {
                continue;
            }

            $events = [];
            try {
                $source = (string) file_get_contents($absoluteFile);
                $ast = $parser->parse($source) ?? [];
                $events = $this->extractEventsFromAst($ast, $class->fqcn, $printer);
            } catch (\Throwable) {
                // Parser failures shouldn't block indexation
                $events = [];
            }

            $subscribers[] = [
                'class' => $class->fqcn,
                'file' => $class->file,
                'events' => $events,
            ];
        }

        $context->symfony['event_subscribers'] = $subscribers;
    }

    /**
     * @param list<string> $implements
     */
    private function implementsSubscriberInterface(array $implements): bool
    {
        foreach ($implements as $iface) {
            if ($iface === self::SUBSCRIBER_INTERFACE
                || str_ends_with($iface, '\\EventSubscriberInterface')
                || $iface === 'EventSubscriberInterface') {
                return true;
            }
        }

        return false;
    }

    /**
     * Walks the AST to find the target class's getSubscribedEvents() method and parses its return array.
     *
     * @param list<Node> $ast
     *
     * @return list<array{event: string, method: string, priority: ?int}>
     */
    private function extractEventsFromAst(array $ast, string $targetFqcn, PrettyPrinter $printer): array
    {
        $events = [];
        foreach ($this->findClassNode($ast, $targetFqcn) as $classNode) {
            foreach ($classNode->stmts ?? [] as $stmt) {
                if (!$stmt instanceof Node\Stmt\ClassMethod) {
                    continue;
                }
                if ('getSubscribedEvents' !== $stmt->name->toString()) {
                    continue;
                }
                foreach ($stmt->stmts ?? [] as $bodyStmt) {
                    if ($bodyStmt instanceof Node\Stmt\Return_
                        && $bodyStmt->expr instanceof Node\Expr\Array_) {
                        $events = array_merge($events, $this->parseEventsArray($bodyStmt->expr, $printer));
                    }
                }
            }
        }

        return $events;
    }

    /**
     * @param list<Node> $ast
     *
     * @return list<Node\Stmt\Class_>
     */
    private function findClassNode(array $ast, string $targetFqcn): array
    {
        $results = [];
        foreach ($ast as $node) {
            if ($node instanceof Node\Stmt\Namespace_) {
                $ns = null !== $node->name ? $node->name->toString() : '';
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\Class_ && null !== $stmt->name) {
                        $fqcn = '' !== $ns ? $ns . '\\' . $stmt->name->toString() : $stmt->name->toString();
                        if ($fqcn === $targetFqcn) {
                            $results[] = $stmt;
                        }
                    }
                }
            } elseif ($node instanceof Node\Stmt\Class_ && null !== $node->name && $node->name->toString() === $targetFqcn) {
                $results[] = $node;
            }
        }

        return $results;
    }

    /**
     * @return list<array{event: string, method: string, priority: ?int}>
     */
    private function parseEventsArray(Node\Expr\Array_ $array, PrettyPrinter $printer): array
    {
        $events = [];
        foreach ($array->items as $item) {
            if (null === $item->key) {
                continue;
            }
            $eventName = $this->renderKey($item->key, $printer);

            foreach ($this->parseEventValue($item->value, $printer) as $binding) {
                $events[] = [
                    'event' => $eventName,
                    'method' => $binding['method'],
                    'priority' => $binding['priority'],
                ];
            }
        }

        return $events;
    }

    private function renderKey(Node\Expr $key, PrettyPrinter $printer): string
    {
        if ($key instanceof Node\Scalar\String_) {
            return $key->value;
        }

        return trim($printer->prettyPrintExpr($key));
    }

    /**
     * Handles all forms documented in EventSubscriberInterface::getSubscribedEvents():
     *   'method'
     *   ['method', priority]
     *   [['method1', p1], ['method2', p2]]
     *
     * @return list<array{method: string, priority: ?int}>
     */
    private function parseEventValue(Node\Expr $value, PrettyPrinter $printer): array
    {
        // 'method'
        if ($value instanceof Node\Scalar\String_) {
            return [['method' => $value->value, 'priority' => null]];
        }

        if (!$value instanceof Node\Expr\Array_) {
            // Fallback: use the printed source
            return [['method' => trim($printer->prettyPrintExpr($value)), 'priority' => null]];
        }

        // Array: either ['method', priority] or [['method', p], ['method', p]]
        $firstItem = $value->items[0] ?? null;
        if (null === $firstItem) {
            return [];
        }

        // Nested arrays: list of bindings
        if ($firstItem->value instanceof Node\Expr\Array_) {
            $bindings = [];
            foreach ($value->items as $item) {
                if (!$item->value instanceof Node\Expr\Array_) {
                    continue;
                }
                $bindings[] = $this->parseSingleBinding($item->value);
            }

            return $bindings;
        }

        // Flat: ['method', priority]
        return [$this->parseSingleBinding($value)];
    }

    /**
     * @return array{method: string, priority: ?int}
     */
    private function parseSingleBinding(Node\Expr\Array_ $array): array
    {
        $method = '';
        $priority = null;
        $items = $array->items;

        if (isset($items[0]) && $items[0]->value instanceof Node\Scalar\String_) {
            $method = $items[0]->value->value;
        }
        if (isset($items[1])) {
            $priority = $this->parseIntLiteral($items[1]->value);
        }

        return ['method' => $method, 'priority' => $priority];
    }

    private function parseIntLiteral(Node\Expr $node): ?int
    {
        if ($node instanceof Node\Scalar\Int_) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\UnaryMinus && $node->expr instanceof Node\Scalar\Int_) {
            return -$node->expr->value;
        }
        if ($node instanceof Node\Expr\UnaryPlus && $node->expr instanceof Node\Scalar\Int_) {
            return $node->expr->value;
        }

        return null;
    }
}
