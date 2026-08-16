<?php

declare(strict_types=1);

namespace Hopheartsceo\ReleaseGuard\Analysis\Ast;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class PhpAstParserService
{
    private readonly Parser $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser
            ?? (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @return array<Node\Stmt>
     */
    public function parse(string $source): array
    {
        $statements = $this->parser->parse($source) ?? [];

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        return $traverser->traverse($statements);
    }
}
