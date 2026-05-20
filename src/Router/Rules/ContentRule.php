<?php

declare(strict_types=1);

namespace NeuronAI\Router\Rules;

use NeuronAI\Chat\Messages\ContentBlocks\AudioContent;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\VideoContent;

class ContentRule implements RoutingRuleInterface
{
    protected ?string $image = null;

    protected ?string $file = null;

    protected ?string $audio = null;

    protected ?string $video = null;

    public function __construct(protected string $default)
    {
    }

    public function image(string $provider): self
    {
        $this->image = $provider;
        return $this;
    }

    public function file(string $provider): self
    {
        $this->file = $provider;
        return $this;
    }

    public function audio(string $provider): self
    {
        $this->audio = $provider;
        return $this;
    }

    public function video(string $provider): self
    {
        $this->video = $provider;
        return $this;
    }

    public function resolveProvider(string $method, array $messages, array $tools): string
    {
        $hasVideo = false;
        $hasAudio = false;
        $hasImage = false;
        $hasFile = false;

        foreach ($messages as $message) {
            foreach ($message->getContentBlocks() as $block) {
                if ($block instanceof VideoContent) {
                    $hasVideo = true;
                } elseif ($block instanceof AudioContent) {
                    $hasAudio = true;
                } elseif ($block instanceof ImageContent) {
                    $hasImage = true;
                } elseif ($block instanceof FileContent) {
                    $hasFile = true;
                }
            }
        }

        if ($hasVideo && $this->video !== null) {
            return $this->video;
        }

        if ($hasAudio && $this->audio !== null) {
            return $this->audio;
        }

        if ($hasImage && $this->image !== null) {
            return $this->image;
        }

        if ($hasFile && $this->file !== null) {
            return $this->file;
        }

        return $this->default;
    }
}
