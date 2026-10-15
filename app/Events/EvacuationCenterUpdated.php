<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class EvacuationCenterUpdated implements ShouldBroadcastNow
{
    public function __construct(public int $centerId, public ?int $eventId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('evacuation.admin'), new Channel('evacuation.centers')];
    }

    public function broadcastAs(): string
    {
        return 'center.updated';
    }

    public function broadcastWith(): array
    {
        return ['center_id' => $this->centerId, 'event_id' => $this->eventId, 'changed_at' => now()->toIso8601String()];
    }
}
