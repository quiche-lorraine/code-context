<?php

declare(strict_types=1);

namespace CodeContext\Config;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Processor;

final class ConfigSchema
{
    /**
     * @param array<string, mixed> $tree
     *
     * @return array<string, mixed>
     */
    public function process(array $tree): array
    {
        $processor = new Processor();
        /** @var array<string, mixed> $processed */
        $processed = $processor->process($this->createTreeBuilder()->buildTree(), [$tree]);

        return $processed;
    }

    private function createTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('code_context');
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->arrayNode('paths')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('root')->defaultValue('.')->cannotBeEmpty()->end()
                        ->arrayNode('include')->scalarPrototype()->end()->defaultValue(['src/'])->end()
                        ->arrayNode('exclude')->scalarPrototype()->end()->defaultValue(['vendor/', 'var/', 'node_modules/', 'tests/', '.code-context/'])->end()
                    ->end()
                ->end()
                ->arrayNode('output')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('directory')->defaultValue('.code-context/')->cannotBeEmpty()->end()
                        ->arrayNode('files')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('json')->defaultValue('context.json')->cannotBeEmpty()->end()
                                ->scalarNode('agents_md')->defaultValue('AGENTS.md')->cannotBeEmpty()->end()
                                ->arrayNode('thematic')
                                    ->addDefaultsIfNotSet()
                                    ->children()
                                        ->scalarNode('architecture')->defaultValue('architecture.md')->end()
                                        ->scalarNode('routes')->defaultValue('routes.md')->end()
                                        ->scalarNode('entities')->defaultValue('entities.md')->end()
                                        ->scalarNode('services')->defaultValue('services.md')->end()
                                        ->scalarNode('commands')->defaultValue('commands.md')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('analyzers')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('php')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('include_signatures')->defaultTrue()->end()
                                ->booleanNode('include_properties')->defaultTrue()->end()
                                ->enumNode('include_phpdoc')->values(['none', 'first_line', 'full'])->defaultValue('first_line')->end()
                                ->booleanNode('include_method_bodies')->defaultFalse()->end()
                                ->integerNode('max_body_length')->min(0)->defaultValue(0)->end()
                                ->booleanNode('include_private')->defaultFalse()->end()
                                ->booleanNode('include_protected')->defaultTrue()->end()
                                ->booleanNode('include_attributes')->defaultTrue()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('extractors')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('composer')->defaultTrue()->end()
                        ->arrayNode('docs')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')->defaultTrue()->end()
                                ->arrayNode('patterns')->scalarPrototype()->end()->defaultValue(['README.md', 'docs/**/*.md', 'CONTRIBUTING.md'])->end()
                                ->booleanNode('include_full_content')->defaultFalse()->end()
                            ->end()
                        ->end()
                        ->arrayNode('symfony')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('auto_detect')->defaultTrue()->end()
                                ->booleanNode('routes')->defaultTrue()->end()
                                ->booleanNode('services')->defaultTrue()->end()
                                ->booleanNode('entities')->defaultTrue()->end()
                                ->booleanNode('commands')->defaultTrue()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('rendering')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('json')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('pretty')->defaultTrue()->end()
                                ->booleanNode('strip_nulls')->defaultTrue()->end()
                            ->end()
                        ->end()
                        ->arrayNode('markdown')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('include_toc')->defaultTrue()->end()
                                ->booleanNode('group_by_namespace')->defaultTrue()->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
