<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Dataset;

use InvalidArgumentException;
use NeuronAI\Evaluation\Dataset\JsonDataset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class JsonDatasetItemsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function notAListOfObjects(): iterable
    {
        yield 'list of scalars' => ['[1, "two", null]'];
        yield 'object keyed by name' => ['{"first": {"question": "a"}, "second": {"question": "b"}}'];
        yield 'single object' => ['{"question": "a", "expected": "b"}'];
    }

    #[DataProvider('notAListOfObjects')]
    public function test_rejects_json_that_is_not_a_list_of_objects(string $content): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'neuron-dataset-');
        file_put_contents($file, $content);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('Dataset must be an array of objects');

            (new JsonDataset($file))->load();
        } finally {
            unlink($file);
        }
    }
}
