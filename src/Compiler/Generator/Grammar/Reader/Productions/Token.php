<?php
/**
 * This file is part of railt package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Railt\Compiler\Generator\Grammar\Reader\Productions;

/**
 * Class Token
 */
class Token extends Rule
{
    /**
     * @var bool
     */
    protected $keep;

    /**
     * Token constructor.
     * @param string $name
     * @param bool $keep
     */
    public function __construct(string $name, bool $keep)
    {
        $this->name = $name;
        $this->keep = $keep;
    }
}
