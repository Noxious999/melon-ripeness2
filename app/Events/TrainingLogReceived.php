<?php
// app/Events/TrainingLogReceived.php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast; // Penting!
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TrainingLogReceived implements ShouldBroadcast // Implement ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $jobId;
    public string $line;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(string $jobId, string $line)
    {
        $this->jobId = $jobId;
        $this->line = $line;
    }

    /**
     * Get the channels the event should broadcast on.
     * Kita gunakan PrivateChannel agar hanya user yang berhak bisa listen.
     * Anda perlu setup otentikasi channel di routes/channels.php
     * Untuk testing awal, bisa pakai Channel publik biasa: new Channel(...)
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        // Contoh menggunakan Channel publik (mudah untuk testing)
        // Nama channel harus konsisten dengan listener di JS/SSE endpoint
        return new Channel('training-job.' . $this->jobId);

        // Contoh menggunakan PrivateChannel (lebih aman, perlu setup auth)
        // return new PrivateChannel('training-job.' . $this->jobId);
    }

    /**
     * Nama event yang akan di-broadcast.
     * Defaultnya adalah nama class (TrainingLogReceived), tapi bisa di-override.
     * Pastikan listener JS mendengarkan event ini.
     */
    // public function broadcastAs(): string
    // {
    //     return 'log.received';
    // }

    /**
     * Data yang akan di-broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'event' => 'log', // Tambahkan tipe event agar mudah di-handle JS
            'line' => $this->line,
        ];
    }
}
