<?php
/**
 * This file is part of Railt package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Railt\Tests\SDL\Language;

use Railt\Io\File;
use Railt\Reflection\Contracts\Document;
use Railt\SDL\Schema\CompilerInterface;
use Railt\Tests\AbstractTestCase;
use Railt\Tests\SDL\Helpers\CompilerStubs;

/**
 * Class AbstractReflectionTestCase
 */
abstract class AbstractLanguageTestCase extends AbstractTestCase
{
    use CompilerStubs;

    /**
     * @param string $body
     * @return array|Document[][]
     * @throws \Exception
     */
    public function dataProviderDocuments(string $body): array
    {
        $result = [];

        foreach ($this->getDocuments($body) as $document) {
            $result[] = [$document];
        }

        return $result;
    }

    /**
     * @param string $body
     * @return iterable|Document[]
     * @throws \Exception
     */
    protected function getDocuments(string $body): iterable
    {
        $readable = File::fromSources($body);

        foreach ($this->getCompilers() as $compiler) {
            yield $compiler->compile($readable);
        }
    }

    /**
     * @return array|CompilerInterface[]
     * @throws \LogicException
     * @throws \Exception
     */
    public function dateCompilersProvider(): array
    {
        $result = [];

        foreach ($this->getCompilers() as $compiler) {
            $result[] = [$compiler];
        }

        return $result;
    }
}
