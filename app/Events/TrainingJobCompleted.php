<?php
// app/Events/TrainingJobCompleted.php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TrainingJobCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param string $jobId
     * @param string $message
     */
    public function __construct(
        public string $jobId,
        public string $message = 'Training completed successfully.'
    ) {} // Hapus parameter $metrics, $learningCurve, $crossValidationScores

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('training-job.' . $this->jobId),
        ];
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'event' => 'success', // Memberi tahu tipe event ke client
            'jobId' => $this->jobId,
            'message' => $this->message,
            // Hapus metrics, learning_curve, cv_scores dari payload broadcast
        ];
    }
}