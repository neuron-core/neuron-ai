<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;

use function serialize;

class PhpSerializerTrailingBytesTest extends TestCase
{
    #[RequiresPhp('>= 8.3')]
    public function test_trailing_bytes_are_rejected_as_corruption(): void
    {
        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('Unable to unserialize persisted Workflow value.');

        (new PhpSerializer())->unserialize(serialize('abc') . 'garbage');
    }

    public function test_a_valid_record_still_round_trips_without_warnings_leaking(): void
    {
        $serializer = new PhpSerializer();

        $this->assertSame(['a' => 1], $serializer->unserialize($serializer->serialize(['a' => 1])));
        $this->assertFalse($serializer->unserialize(serialize(false)));
    }
}
