<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Repro;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Evaluation\Cache\FileEvaluationCache;
use NeuronAI\Evaluation\Conversation\Trajectory;
use NeuronAI\Evaluation\Output\JsonOutput;
use NeuronAI\Tests\Evaluation\Stub\EvaluationReportFixture;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function glob;
use function ob_get_clean;
use function ob_start;
use function rmdir;
use function serialize;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function unserialize;

use const JSON_THROW_ON_ERROR;

class EvaluationOutputEncodingReproTest extends TestCase
{
    public function test_the_json_report_survives_an_output_with_invalid_utf8(): void
    {
        ob_start();
        try {
            (new JsonOutput())->output(EvaluationReportFixture::singleResult("caf\xE9"));
        } finally {
            $json = (string) ob_get_clean();
        }

        $this->assertSame("caf\u{FFFD}", json_decode($json, true, 512, JSON_THROW_ON_ERROR)['results'][0]['output']);
    }

    public function test_a_trajectory_with_invalid_utf8_survives_serialization(): void
    {
        $trajectory = Trajectory::fromMessages([new UserMessage('hi'), new AssistantMessage("caf\xE9")]);

        $restored = unserialize(serialize($trajectory));

        $this->assertInstanceOf(Trajectory::class, $restored);
        $this->assertSame(2, $restored->count());
        $this->assertSame("caf\u{FFFD}", $restored->finalAnswer());
    }

    public function test_a_cached_trajectory_with_invalid_utf8_is_read_back(): void
    {
        $directory = sys_get_temp_dir() . '/neuron-eval-cache-' . uniqid();
        $cache = new FileEvaluationCache($directory);

        try {
            $cache->set('key', Trajectory::fromMessages([new UserMessage('hi'), new AssistantMessage("caf\xE9")]));

            $this->assertSame("caf\u{FFFD}", $cache->get('key')->finalAnswer());
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($directory);
        }
    }
}
