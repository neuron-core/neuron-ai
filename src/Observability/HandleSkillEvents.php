<?php

declare(strict_types=1);

namespace NeuronAI\Observability;

use Inspector\Models\Segment;
use NeuronAI\Agent\Skills\SkillInterface;
use NeuronAI\Observability\Events\SkillActivated;
use NeuronAI\Observability\Events\SkillsBootstrapped;

use function array_map;

trait HandleSkillEvents
{
    protected Segment $skillBootstrap;

    public function skillsBootstrapping(object $source, string $event, mixed $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $this->skillBootstrap = $this->inspector
            ->startSegment(
                self::SEGMENT_TYPE.'.skill',
                'skills_bootstrap()'
            )
            ->setColor(self::STANDARD_COLOR);
    }

    public function skillsBootstrapped(object $source, string $event, SkillsBootstrapped $data): void
    {
        if (!isset($this->skillBootstrap)) {
            return;
        }

        $this->skillBootstrap->end();

        $this->skillBootstrap->addContext('Skills', array_map(
            fn (SkillInterface $skill): array => [
                'name' => $skill->name(),
                'priority' => $skill->priority(),
            ],
            $data->skills
        ));

        $this->skillBootstrap->addContext('Instructions', $data->instructions);
    }

    public function skillActivated(object $source, string $event, SkillActivated $data): void
    {
        if (!$this->inspector->canAddSegments()) {
            return;
        }

        $segment = $this->inspector
            ->startSegment(
                self::SEGMENT_TYPE.'.skill',
                "skill_activated: {$data->skillName}"
            )
            ->setColor('#9b59b6');

        $segment->addContext('Skill Activated', [
            'skill' => $data->skillName,
            'reason' => $data->reason,
        ]);

        $segment->end();
    }
}
