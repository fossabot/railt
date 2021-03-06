<?php
/**
 * This file is part of Railt package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Railt\Compiler;

use Railt\Compiler\Exception\LexerException;
use Railt\Compiler\Grammar\Parsers\Pragmas;
use Railt\Compiler\Grammar\Pragmas\Unicode;
use Railt\Compiler\Grammar\Pragmas\UnrecognizedToken;
use Railt\Compiler\Lexer\LexerInterface;
use Railt\Compiler\Lexer\Token;

/**
 * Class ReLexer
 */
class Lexer implements LexerInterface
{
    /**#@+
     * Token input definition indexes
     */
    public const INPUT_TOKEN_PATTERN            = 0;
    public const INPUT_TOKEN_KEPT               = 1;
    /**#@-*/

    /**
     * @var string
     */
    protected $input;

    /**
     * @var array
     */
    protected $tokens;

    /**
     * @var bool
     */
    protected $isUnicode;

    /**
     * @var string
     */
    protected $errorUnrecognized;

    /**
     * @var bool
     */
    protected $keepAll = false;

    /**
     * Lexer constructor.
     * @param string $input
     * @param array $tokens
     * @param array $pragmas
     * @throws \Railt\Compiler\Exception\InvalidPragmaException
     */
    public function __construct(string $input, array $tokens = [], array $pragmas = [])
    {
        $this->input  = $input;
        $this->tokens = $tokens;

        $this->isUnicode         = Pragmas::get($pragmas, Unicode::class);
        $this->errorUnrecognized = Pragmas::get($pragmas, UnrecognizedToken::class);
    }

    /**
     * @param bool $keep
     * @return Lexer
     */
    public function keepAll(bool $keep = true): LexerInterface
    {
        $this->keepAll = $keep;

        return $this;
    }

    /**
     * @return \Generator
     */
    public function getIterator(): \Traversable
    {
        $pattern = $this->regex($this->tokens);

        $offset  = 0;
        $result  = [];

        \preg_replace_callback($pattern, function (array $matches) use (&$result, &$offset): void {
            [$name, $body] = $this->getTokenInfo($matches);

            $length = \strlen($body);
            $kept   = $this->tokens[$name][self::INPUT_TOKEN_KEPT] ?? true;

            $this->verifyOffset($offset, $body);

            if ($this->keepAll || $kept) {
                $result[] = [
                    Token::T_TOKEN     => $name,
                    Token::T_VALUE     => $body,
                    Token::T_LENGTH    => $length,
                    Token::T_NAMESPACE => Token::T_DEFAULT_NAMESPACE,
                    Token::T_KEEP      => $kept,
                    Token::T_OFFSET    => $offset,
                ];
            }

            $offset += $length;
        }, $this->input);

        yield from $result;

        yield Token::eof($offset);
    }

    /**
     * @param int $offset
     * @param string $body
     * @return void
     */
    private function verifyOffset(int $offset, string $body): void
    {
        $isValid = \substr($this->input, $offset, \strlen($body)) === $body;

        if (! $isValid) {
            $this->throwUnrecognizedToken($offset);
        }
    }

    /**
     * @param int $offset
     * @return void
     */
    protected function throwUnrecognizedToken(int $offset): void
    {
        $error = \sprintf('Unrecognized token "%s"', $this->input[$offset]);

        throw new $this->errorUnrecognized($error, 0, null, [
            'input'  => $this->input,
            'offset' => $offset,
        ]);
    }

    /**
     * @param array $data
     * @return array
     */
    private function getTokenInfo(array $data): array
    {
        $last = '';

        foreach (\array_reverse($data) as $index => $body) {
            if (! \is_string($index)) {
                continue;
            }

            $last = $index;

            if ($body !== '') {
                return [$index, $body];
            }
        }

        $error = \sprintf('A lexeme must not match an empty value, which is the case of "%s"', $last);
        throw new LexerException($error);
    }

    /**
     * @param array $tokens
     * @return string
     */
    private function regex(array $tokens): string
    {
        $result = $this->collectRegexGroups($tokens);

        return \sprintf('#(%s)#%s', \implode('|', $result), $this->getRegexFlags());
    }

    /**
     * @param array $tokens
     * @return array
     */
    private function collectRegexGroups(array $tokens): array
    {
        $result = [];

        foreach ($tokens as $name => $info) {
            $result[] = \vsprintf('(?<%s>%s)', [
                \preg_quote($name, '#'),
                \str_replace('#', '\#', $info[self::INPUT_TOKEN_PATTERN]),
            ]);
        }

        return $result;
    }

    /**
     * @return string
     */
    private function getRegexFlags(): string
    {
        $flags = '';

        if ($this->isUnicode) {
            $flags .= 'u';
        }

        return $flags;
    }
}
