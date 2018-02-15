<?php
/**
 * This file is part of Lexer package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Railt\Compiler;

use Railt\Compiler\Grammar\GrammarDefinition;
use Railt\Compiler\Grammar\Reader;
use Railt\Io\Readable;

/**
 * Class Grammar
 */
class Grammar implements GrammarDefinition
{
    /**
     * @var iterable
     */
    private $tokens;

    /**
     * @var iterable
     */
    private $rules;

    /**
     * @var iterable
     */
    private $pragmas;

    /**
     * Grammar constructor.
     * @param iterable $tokens
     * @param iterable $rules
     * @param iterable $pragmas
     */
    public function __construct(iterable $tokens = [], iterable $rules = [], iterable $pragmas = [])
    {
        $this->tokens  = $tokens;
        $this->rules   = $rules;
        $this->pragmas = $pragmas;
    }

    /**
     * @param Readable $schema
     * @return static
     * @throws \Railt\Compiler\Lexer\Exceptions\LexerException
     * @throws \Railt\Compiler\Grammar\Exceptions\GrammarException
     */
    public static function read(Readable $schema)
    {
        $reader = new Reader($schema);

        return new static(
            $reader->getTokenDefinitions(),
            $reader->getRuleDefinitions(),
            $reader->getPragmaDefinitions()
        );
    }

    /**
     * @return iterable
     */
    public function getTokenDefinitions(): iterable
    {
        return $this->tokens;
    }

    /**
     * @return iterable
     */
    public function getRuleDefinitions(): iterable
    {
        return $this->rules;
    }

    /**
     * @return iterable
     */
    public function getPragmaDefinitions(): iterable
    {
        return $this->pragmas;
    }
}
