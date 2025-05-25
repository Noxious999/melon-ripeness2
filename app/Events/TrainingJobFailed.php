<?php
// app/Events/TrainingJobFailed.php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TrainingJobFailed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $jobId;
    public string $message;

    public function __construct(string $jobId, string $message = 'Job gagal.')
    {
        $this->jobId = $jobId;
        $this->message = $message;
    }

    public function broadcastOn()
    {
        return new Channel('training-job.' . $this->jobId);
        // return new PrivateChannel('training-job.' . $this->jobId);
    }

    public function broadcastWith(): array
    {
        return [
            'event' => 'error',
            'message' => $this->message,
        ];
    }
}
