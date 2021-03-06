<?php
/**
 * This file is part of Railt package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Railt\Reflection\Base\Definitions;

use Railt\Reflection\Base\Dependent\Argument\BaseArgumentsContainer;
use Railt\Reflection\Base\Invocations\Directive\BaseDirectivesContainer;
use Railt\Reflection\Contracts\Definitions\InputDefinition;
use Railt\Reflection\Contracts\Invocations\InputInvocation;
use Railt\Reflection\Contracts\Type;

/**
 * Class BaseInput
 */
abstract class BaseInput extends BaseTypeDefinition implements InputDefinition
{
    use BaseArgumentsContainer;
    use BaseDirectivesContainer;

    /**
     * Input type name
     */
    protected const TYPE_NAME = Type::INPUT;

    /**
     * @param mixed $value
     * @return bool
     */
    public function isCompatible($value): bool
    {
        return $value instanceof InputInvocation;
    }

    /**
     * @return array
     */
    public function __sleep(): array
    {
        return \array_merge(parent::__sleep(), [
            // trait HasArguments
            'arguments',

            // trait HasDirectives
            'directives',
        ]);
    }
}
