<?php
/**
 * This file is part of Railt package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Railt\Compiler;

use Hoa\Iterator\Buffer;
use Railt\Compiler\Ast\Leaf;
use Railt\Compiler\Ast\LeafInterface;
use Railt\Compiler\Ast\Node;
use Railt\Compiler\Ast\NodeInterface;
use Railt\Compiler\Ast\Rule as AstRule;
use Railt\Compiler\Ast\RuleInterface;
use Railt\Compiler\Exceptions\ParserException;
use Railt\Compiler\Exceptions\UnexpectedTokenException;
use Railt\Compiler\Grammar\Exceptions\InvalidPragmaException;
use Railt\Compiler\Grammar\Lexer;
use Railt\Compiler\Grammar\Reader;
use Railt\Compiler\Grammar\Rule\Choice;
use Railt\Compiler\Grammar\Rule\Concatenation;
use Railt\Compiler\Grammar\Rule\Ekzit;
use Railt\Compiler\Grammar\Rule\Entry;
use Railt\Compiler\Grammar\Rule\Repetition;
use Railt\Compiler\Grammar\Rule\Rule;
use Railt\Compiler\Grammar\Rule\Token;
use Railt\Compiler\Pragma\ParserLookahead;
use Railt\Compiler\Pragma\ParserRootRule;
use Railt\Io\Readable;

/**
 * Class Parser
 */
class Parser
{
    /**
     * List of pragmas.
     *
     * @var Pragma
     */
    protected $pragmas;

    /**
     * List of skipped tokens.
     *
     * @var array
     */
    protected $skip;

    /**
     * Rules, to be defined as associative array, name => Rule object.
     *
     * @var array
     */
    protected $rules;

    /**
     * Lexer iterator.
     *
     * @var Buffer
     */
    protected $buffer;

    /**
     * Possible token causing an error.
     *
     * @var array
     */
    protected $errorToken;

    /**
     * Trace of activated rules.
     *
     * @var array
     */
    protected $trace = [];

    /**
     * @var array
     */
    protected $todo;

    /**
     * Current depth while building the trace.
     *
     * @var int
     */
    protected $depth = -1;

    /**
     * @var Lexer
     */
    private $lexer;

    /**
     * Construct the parser.
     *
     * @param Lexer $lexer
     * @param array $rules Rules.
     * @param Pragma|array $pragmas Pragma Pragmas.
     * @throws \Railt\Compiler\Grammar\Exceptions\InvalidPragmaException
     */
    public function __construct(Lexer $lexer, array $rules, $pragmas)
    {
        $this->lexer   = $lexer;
        $this->rules   = $rules;
        $this->pragmas = \is_array($pragmas) ? new Pragma($pragmas) : $pragmas;
    }

    /**
     * @param Readable $grammar
     * @return Parser
     * @throws \Railt\Compiler\Grammar\Exceptions\InvalidPragmaException
     * @throws \Railt\Compiler\Grammar\Exceptions\GrammarException
     */
    public static function fromGrammar(Readable $grammar): self
    {
        $parser  = new Reader($grammar);
        $pragmas = $parser->getPragmaDefinitions();
        $lexer   = new Lexer($parser->getTokenDefinitions(), $pragmas->lexerConfiguration());

        return new static($lexer, $parser->getRuleDefinitions(), $pragmas);
    }

    /**
     * Parse :-).
     *
     * @param Readable $input Text to parse.
     * @return RuleInterface|LeafInterface|NodeInterface
     * @throws \Railt\Compiler\Exceptions\ParserException
     * @throws \Railt\Compiler\Grammar\Exceptions\InvalidPragmaException
     */
    public function parse(Readable $input): NodeInterface
    {
        $this->buffer = $this->getBuffer($input, $this->pragmas);
        $this->buffer->rewind();

        $this->errorToken = null;
        $this->trace      = [];
        $this->todo       = [];

        $rule = $this->getRootRule();

        $closeRule  = new Ekzit($rule, 0);
        $openRule   = new Entry($rule, 0, [$closeRule]);
        $this->todo = [$closeRule, $openRule];

        do {
            $out = $this->unfold();

            if ($out !== null && $this->buffer->current()[Output::I_TOKEN_NAME] === Eof::T_NAME) {
                break;
            }

            if ($this->backtrack() === false) {
                $token = (function () {
                    return $this->_buffer->top()[1];
                })->call($this->buffer) ?: $this->buffer->current();

                $error = \vsprintf('Unexpected token "%s" (%s)', [
                    $token[Output::I_TOKEN_BODY],
                    $token[Output::I_TOKEN_NAME],
                ]);

                throw UnexpectedTokenException::fromFile(
                    $error,
                    $input,
                    $input->getPosition($token[Output::I_TOKEN_OFFSET] ?? 0)
                );
            }
        } while (true);

        $ast = $this->buildTree();

        if (! ($ast instanceof NodeInterface)) {
            throw new ParserException('Parsing error: Cannot build AST, the trace is corrupted.', 1);
        }

        return $ast;
    }

    /**
     * @param Readable $input
     * @param Pragma $pragmas
     * @return Buffer
     * @throws \Railt\Compiler\Grammar\Exceptions\InvalidPragmaException
     */
    protected function getBuffer(Readable $input, Pragma $pragmas): Buffer
    {
        /** @var \Iterator $stream */
        $stream = $this->lexer->read($input)
            ->exceptChannel(Output::DEFAULT_SKIPPED_CHANNEL_NAME)
            ->get();

        return new Buffer($stream, $pragmas->get(ParserLookahead::getName()));
    }

    /**
     * Get root rule.
     *
     * @return string
     * @throws \Railt\Compiler\Exceptions\ParserException
     * @throws \Railt\Compiler\Grammar\Exceptions\InvalidPragmaException
     */
    public function getRootRule(): string
    {
        $root = $this->pragmas->get(ParserRootRule::getName());

        if ($root === null) {
            foreach ($this->rules as $rule => $i) {
                if (\is_string($rule)) {
                    return $rule;
                }
            }

            throw new ParserException('Can not resolve root rule definition');
        }

        if (\array_key_exists($root, $this->rules)) {
            return $root;
        }

        $error = \sprintf('The production rule "%s" defined by pragma does not exists', $root);
        throw new InvalidPragmaException($error);
    }

    /**
     * Unfold trace.
     *
     * @return  mixed
     */
    protected function unfold()
    {
        while (0 < \count($this->todo)) {
            $rule = \array_pop($this->todo);

            if ($rule instanceof Ekzit) {
                $rule->setDepth($this->depth);
                $this->trace[] = $rule;

                if ($rule->isTransitional() === false) {
                    --$this->depth;
                }
            } else {
                $ruleName = $rule->getRule();
                $next     = $rule->getData();
                $zeRule   = $this->rules[$ruleName];
                $out      = $this->parseCurrentRule($zeRule, $next);

                if ($out === false && $this->backtrack() === false) {
                    return;
                }
            }
        }

        return true;
    }

    /**
     * Parse current rule.
     *
     * @param Rule $zeRule Current rule.
     * @param int $next Next rule index.
     * @return bool
     */
    protected function parseCurrentRule(Rule $zeRule, $next): bool
    {
        if ($zeRule instanceof Token) {
            $name = $this->buffer->current()[Output::I_TOKEN_NAME];

            if ($zeRule->getTokenName() !== $name) {
                return false;
            }

            $value = $this->buffer->current()[Output::I_TOKEN_BODY];

            if (0 <= $unification = $zeRule->getUnificationIndex()) {
                for ($skip = 0, $i = \count($this->trace) - 1; $i >= 0; --$i) {
                    $trace = $this->trace[$i];

                    if ($trace instanceof Entry) {
                        if ($trace->isTransitional() === false) {
                            if ($trace->getDepth() <= $this->depth) {
                                break;
                            }

                            --$skip;
                        }
                    } elseif ($trace instanceof Ekzit &&
                        $trace->isTransitional() === false) {
                        $skip += $trace->getDepth() > $this->depth;
                    }

                    if (0 < $skip) {
                        continue;
                    }

                    if ($trace instanceof Token &&
                        $unification === $trace->getUnificationIndex() &&
                        $value !== $trace->getValue()) {
                        return false;
                    }
                }
            }

            $current = $this->buffer->current();

            $zzeRule = clone $zeRule;
            $zzeRule->setValue($value);
            $zzeRule->setOffset($current[Output::I_TOKEN_OFFSET]);

            \array_pop($this->todo);
            $this->trace[] = $zzeRule;
            $this->buffer->next();
            $this->errorToken = $this->buffer->current();

            return true;
        }

        if ($zeRule instanceof Concatenation) {
            if ($zeRule->isTransitional() === false) {
                ++$this->depth;
            }

            $this->trace[] = new Entry(
                $zeRule->getName(),
                0,
                null,
                $this->depth
            );
            $children      = $zeRule->getChildren();

            for ($i = \count($children) - 1; $i >= 0; --$i) {
                $nextRule     = $children[$i];
                $this->todo[] = new Ekzit($nextRule, 0);
                $this->todo[] = new Entry($nextRule, 0);
            }

            return true;
        }

        if ($zeRule instanceof Choice) {
            $children = $zeRule->getChildren();

            if ($next >= \count($children)) {
                return false;
            }

            if ($zeRule->isTransitional() === false) {
                ++$this->depth;
            }

            $this->trace[] = new Entry(
                $zeRule->getName(),
                $next,
                $this->todo,
                $this->depth
            );
            $nextRule      = $children[$next];
            $this->todo[]  = new Ekzit($nextRule, 0);
            $this->todo[]  = new Entry($nextRule, 0);

            return true;
        }

        if ($zeRule instanceof Repetition) {
            $nextRule = $zeRule->getChildren();

            if ($next === 0) {
                $name = $zeRule->getName();
                $min  = $zeRule->getMin();

                if ($zeRule->isTransitional() === false) {
                    ++$this->depth;
                }

                $this->trace[] = new Entry(
                    $name,
                    $min,
                    null,
                    $this->depth
                );
                \array_pop($this->todo);
                $this->todo[] = new Ekzit(
                    $name,
                    $min,
                    $this->todo
                );

                for ($i = 0; $i < $min; ++$i) {
                    $this->todo[] = new Ekzit($nextRule, 0);
                    $this->todo[] = new Entry($nextRule, 0);
                }

                return true;
            }
            $max = $zeRule->getMax();

            if ($max !== -1 && $next > $max) {
                return false;
            }

            $this->todo[] = new Ekzit(
                $zeRule->getName(),
                $next,
                $this->todo
            );
            $this->todo[] = new Ekzit($nextRule, 0);
            $this->todo[] = new Entry($nextRule, 0);

            return true;
        }

        return false;
    }

    /**
     * Backtrack the trace.
     *
     * @return bool
     */
    protected function backtrack(): bool
    {
        $found = false;

        do {
            $last = \array_pop($this->trace);

            if ($last instanceof Entry) {
                $zeRule = $this->rules[$last->getRule()];
                $found  = $zeRule instanceof Choice;
            } elseif ($last instanceof Ekzit) {
                $zeRule = $this->rules[$last->getRule()];
                $found  = $zeRule instanceof Repetition;
            } elseif ($last instanceof Token) {
                $this->buffer->previous();

                if ($this->buffer->valid() === false) {
                    return false;
                }
            }
        } while (0 < \count($this->trace) && $found === false);

        if ($found === false) {
            return false;
        }

        $rule         = $last->getRule();
        $next         = $last->getData() + 1;
        $this->depth  = $last->getDepth();
        $this->todo   = $last->getTodo();
        $this->todo[] = new Entry($rule, $next);

        return true;
    }

    /**
     * Build AST from trace.
     * Walk through the trace iteratively and recursively.
     *
     * @param int $i Current trace index.
     * @param array &$children Collected children.
     * @return Node|int
     */
    protected function buildTree($i = 0, array &$children = [])
    {
        $max = \count($this->trace);

        while ($i < $max) {
            $trace = $this->trace[$i];

            if ($trace instanceof Entry) {
                $ruleName  = $trace->getRule();
                $rule      = $this->rules[$ruleName];
                $isRule    = $trace->isTransitional() === false;
                $nextTrace = $this->trace[$i + 1];
                $id        = $rule->getNodeId();

                // Optimization: Skip empty trace sequence.
                if ($nextTrace instanceof Ekzit && $ruleName === $nextTrace->getRule()) {
                    $i += 2;

                    continue;
                }

                if ($isRule === true) {
                    $children[] = $ruleName;
                }

                if ($id !== null) {
                    $children[] = [
                        'id'      => $id,
                        'options' => $rule->getNodeOptions(),
                    ];
                }

                $i = $this->buildTree($i + 1, $children);

                if ($isRule === false) {
                    continue;
                }

                $handle = [];
                $cId    = null;

                do {
                    $pop = \array_pop($children);

                    if (\is_object($pop) === true) {
                        $handle[] = $pop;
                    } elseif (\is_array($pop) === true && $cId === null) {
                        $cId = $pop['id'];
                    } elseif ($ruleName === $pop) {
                        break;
                    }
                } while ($pop !== null);

                if ($cId === null) {
                    $cId = $rule->getDefaultId();
                }

                if ($cId === null) {
                    for ($j = \count($handle) - 1; $j >= 0; --$j) {
                        $children[] = $handle[$j];
                    }

                    continue;
                }

                $cTree      = new AstRule((string)($id ?: $cId), \array_reverse($handle));
                $children[] = $cTree;
            } elseif ($trace instanceof Ekzit) {
                return $i + 1;
            } else {
                if ($trace->isKept() === false) {
                    ++$i;

                    continue;
                }

                $child = new Leaf(
                    $trace->getTokenName(),
                    $trace->getValue(),
                    $trace->getOffset()
                );

                $children[] = $child;
                ++$i;
            }
        }

        return $children[0];
    }

    /**
     * @return iterable|array[]
     */
    public function getTokens(): iterable
    {
        return $this->tokens;
    }

    /**
     * @return array|Rule[]
     */
    public function getRules(): iterable
    {
        return $this->rules;
    }

    /**
     * @return iterable
     */
    public function getPragmas(): iterable
    {
        return \iterator_to_array($this->pragmas);
    }

    /**
     * Get trace.
     *
     * @return array
     */
    public function getTrace(): array
    {
        return $this->trace;
    }

    /**
     * Try to merge directly children into an existing node.
     *
     * @param array &$children Current children being gathering.
     * @param array &$handle Children of the new node.
     * @param string $cId Node ID.
     * @return bool
     */
    protected function mergeTree(&$children, &$handle, $cId): bool
    {
        \end($children);
        $last = \current($children);

        if (! \is_object($last)) {
            return false;
        }

        if ($cId !== $last->getId()) {
            return false;
        }

        foreach ($handle as $child) {
            $last->appendChild($child);
            $child->setParent($last);
        }

        return true;
    }
}
