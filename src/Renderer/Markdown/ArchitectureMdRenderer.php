<?php

declare(strict_types=1);

namespace CodeContext\Renderer\Markdown;

use CodeContext\Model\Context;

final class ArchitectureMdRenderer
{
    public function render(Context $context): string
    {
        $lines = ['# architecture.md', ''];
        $lines[] = '## Overview';
        $lines[] = '';
        $lines[] = sprintf('- Generated at: `%s`', $context->generatedAt);
        $lines[] = sprintf('- Project root: `%s`', $context->projectRoot);
        $lines[] = sprintf('- Estimated tokens: `%d`', $context->estimatedTokens);
        $lines[] = '';
        $summary = $context->phpSummary;
        if ([] !== $summary) {
            $lines[] = '## PHP Structure';
            $lines[] = '';
            $lines[] = sprintf('- Namespaces: `%d`', (int) ($summary['namespaces_total'] ?? 0));
            $lines[] = sprintf('- Class-like declarations: `%d`', (int) ($summary['classes_total'] ?? 0));
            $lines[] = sprintf('- Methods: `%d`', (int) ($summary['methods_total'] ?? 0));
            $lines[] = sprintf('- Properties: `%d`', (int) ($summary['properties_total'] ?? 0));
            $lines[] = '';
        }

        return implode("\n", $lines) . "\n";
    }
}
