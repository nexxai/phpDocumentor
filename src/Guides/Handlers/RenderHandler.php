<?php

declare(strict_types=1);

/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link https://phpdoc.org
 */

namespace phpDocumentor\Guides\Handlers;

use IteratorAggregate;
use League\Flysystem\FilesystemInterface;
use phpDocumentor\Descriptor\DocumentDescriptor;
use phpDocumentor\Descriptor\GuideSetDescriptor;
use phpDocumentor\Guides\Environment;
use phpDocumentor\Guides\Metas;
use phpDocumentor\Guides\NodeRenderers\FullDocumentNodeRenderer;
use phpDocumentor\Guides\References\Doc;
use phpDocumentor\Guides\References\Reference;
use phpDocumentor\Guides\RenderCommand;
use phpDocumentor\Guides\Renderer;
use phpDocumentor\Transformer\Router\Router;
use Psr\Log\LoggerInterface;

use function array_merge;
use function dirname;
use function get_class;
use function iterator_to_array;
use function str_replace;

final class RenderHandler
{
    /** @var Metas */
    private $metas;

    /** @var Renderer */
    private $renderer;

    /** @var LoggerInterface */
    private $logger;

    /** @var Reference[] */
    private $references;

    /** @var Router */
    private $router;

    /** @param IteratorAggregate<Reference> $references */
    public function __construct(
        Metas $metas,
        Renderer $renderer,
        LoggerInterface $logger,
        IteratorAggregate $references,
        Router $router
    ) {
        $this->metas = $metas;
        $this->renderer = $renderer;
        $this->logger = $logger;
        $this->references = iterator_to_array($references);
        $this->router = $router;
    }

    public function handle(RenderCommand $command): void
    {
        $environment = new Environment(
            $command->getConfiguration(),
            $this->renderer,
            $this->logger,
            $command->getOrigin(),
            $this->metas
        );

        $nodeRendererFactory = $command->getConfiguration()->getFormat()->getNodeRendererFactory($environment);
        $environment->setNodeRendererFactory($nodeRendererFactory);
        $this->render($command->getDocumentationSet(), $environment, $command->getDestination());
    }

    private function render(
        GuideSetDescriptor $documentationSet,
        Environment $environment,
        FilesystemInterface $destination
    ): void {
        $this->initReferences($environment, $this->references);

        /** @var DocumentDescriptor $descriptor */
        foreach ($documentationSet->getDocuments() as $descriptor) {
            $document = $descriptor->getDocumentNode();

            // TODO: This is a hack; I want to rework path handling for guides as the Environment, for example,
            //       has a plethora of 'em.
            $target = str_replace(
                '//',
                '/',
                $documentationSet->getOutput() . '/' . $this->router->generate($descriptor)
            );

            $directory = dirname($target);

            $environment->setCurrentFileName($descriptor->getFile());
            // TODO: We assume there is one, but there may be multiple. Handling this correctly required rework on how
            // source locations are propagated.
            $sourcePath = $documentationSet->getSource()->paths()[0];

            $environment->setCurrentAbsolutePath($sourcePath . '/' . dirname($descriptor->getFile()));
            $environment->setCurrentDirectory($directory);

            foreach ($descriptor->getLinks() as $link => $url) {
                $environment->setLink($link, $url);
            }

            foreach ($descriptor->getVariables() as $key => $value) {
                $environment->setVariable($key, $value);
            }

            /** @var FullDocumentNodeRenderer $renderer */
            $renderer = $environment->getNodeRendererFactory()->get(get_class($document));
            $this->renderer->setGuidesEnvironment($environment);
            $this->renderer->setDestination($environment->getUrl());

            $destination->put($target, $renderer->renderDocument($document, $environment));
        }
    }

    /**
     * @param array<Reference> $references
     */
    private function initReferences(Environment $environment, array $references): void
    {
        $references = array_merge(
            [
                new Doc(),
                new Doc('ref', true),
            ],
            $references
        );

        foreach ($references as $reference) {
            $environment->registerReference($reference);
        }
    }
}
