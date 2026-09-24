<?php

declare(strict_types=1);

namespace NeuronAI\Tests;

use DateTimeImmutable;
use NeuronAI\UniqueIdGenerator;
use PHPUnit\Framework\TestCase;
use Spatie\Fork\Fork;

use function array_fill;
use function array_merge;
use function array_unique;
use function class_exists;
use function count;
use function function_exists;
use function hexdec;
use function sort;
use function str_replace;
use function substr;
use function usleep;

use const SORT_STRING;

class UniqueIdGeneratorTest extends TestCase
{
    protected const UUID_V7 = '[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    public function test_uuid_follows_the_rfc_9562_version_7_layout(): void
    {
        $this->assertMatchesRegularExpression('/^' . self::UUID_V7 . '$/', UniqueIdGenerator::generateUUID());
    }

    public function test_id_is_the_prefix_followed_by_a_uuid(): void
    {
        $this->assertMatchesRegularExpression('/^msg_' . self::UUID_V7 . '$/', UniqueIdGenerator::generateId('msg_'));
    }

    public function test_uuid_embeds_its_creation_time_in_milliseconds(): void
    {
        $before = $this->currentMilliseconds();
        $uuid = UniqueIdGenerator::generateUUID();
        $after = $this->currentMilliseconds();

        $timestamp = hexdec(substr(str_replace('-', '', $uuid), 0, 12));

        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    public function test_ids_from_later_milliseconds_sort_after_earlier_ones(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = UniqueIdGenerator::generateId('msg_');
            usleep(1100);
        }

        $sorted = $ids;
        sort($sorted, SORT_STRING);

        $this->assertSame($ids, $sorted);
    }

    public function test_ids_are_unique(): void
    {
        $ids = [];
        for ($i = 0; $i < 10000; $i++) {
            $ids[] = UniqueIdGenerator::generateId();
        }

        $this->assertCount(10000, array_unique($ids));
    }

    public function test_forked_processes_never_repeat_an_id(): void
    {
        if (!function_exists('pcntl_fork') || !class_exists(Fork::class)) {
            $this->markTestSkipped('Forking requires the pcntl extension and spatie/fork.');
        }

        // Children inherit whatever state the parent's earlier IDs left behind.
        UniqueIdGenerator::generateId();
        $start = $this->currentMilliseconds() + 50;

        $batches = Fork::new()->run(...array_fill(0, 4, function () use ($start): array {
            // Start together, so the children generate within the same milliseconds.
            while ($this->currentMilliseconds() < $start) {
                usleep(100);
            }

            $ids = [];
            while ($this->currentMilliseconds() < $start + 5) {
                $ids[] = UniqueIdGenerator::generateId();
            }

            return $ids;
        }));

        $ids = array_merge(...$batches);

        $this->assertCount(count($ids), array_unique($ids));
    }

    protected function currentMilliseconds(): int
    {
        return (int) (new DateTimeImmutable())->format('Uv');
    }
}
