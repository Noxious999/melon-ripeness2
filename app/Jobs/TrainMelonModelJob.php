<?php

// File: app/Jobs/TrainMelonModelJob.php

namespace App\Jobs;

use App\Events\TrainingJobCompleted;
use App\Events\TrainingJobFailed;
use App\Events\TrainingLogReceived;
use App\Services\ModelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue; // Diperlukan untuk clear cache
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process; // Event untuk status selesai
use Throwable;

// Event untuk status gagal

class TrainMelonModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $taskType; // 'classifier' atau 'detector'
    protected string $jobId;    // ID unik untuk job ini
    protected string $jobStatusCacheKey;
    public int $timeout = 7200; // Timeout job dalam detik (2 jam)
    public int $tries   = 1;    // Coba job hanya sekali

    /**
     * Create a new job instance.
     *
     * @param string $taskType Tipe tugas ('classifier' atau 'detector')
     * @param string $jobId ID unik untuk job ini
     */
    public function __construct(string $taskType, string $jobId)
    {
        if (! in_array($taskType, ['classifier', 'detector'])) {
            throw new \InvalidArgumentException("Invalid task type provided to TrainMelonModelJob: {$taskType}");
        }
        $this->taskType = $taskType;
        $this->jobId    = $jobId;
        // Key cache yang lebih spesifik untuk status job
        $this->jobStatusCacheKey = 'training_job_status:' . $this->taskType . ':' . $this->jobId;
    }

    /**
     * Jalankan job training model.
     */
    public function handle(ModelService $modelService): void
    {
        ini_set('memory_limit', '2048M'); // Tingkatkan batas memori
        Log::info("🚀 Starting Model Training Job", ['task' => $this->taskType, 'jobId' => $this->jobId]);

        // --- Circuit Breaker & Lock ---
        $failureKey = 'training_failures:' . $this->taskType;
        if (Cache::get($failureKey, 0) >= 5) { // Batasi 5 kegagalan berturut-turut (>= 5)
            Log::alert("Circuit breaker tripped for task '{$this->taskType}'. Skipping job {$this->jobId}.");
            $this->delete(); // Hapus job dari queue
            return;
        }
        // Kunci berdasarkan job ID spesifik
        $lockKey = 'training_lock_' . $this->taskType . '_' . $this->jobId;
        $lock    = Cache::lock($lockKey, $this->timeout + 120); // Lock selama timeout job + buffer
        if (! $lock->get()) {
            Log::warning("Training job for task '{$this->taskType}' with specific ID '{$this->jobId}' is already running or locked. Releasing job back to queue.");
            $this->release(60); // Coba lagi setelah 60 detik
            return;
        }
        Log::info("Lock acquired for task: {$this->taskType}, Job ID: {$this->jobId}");

                                                                                                                               // Set status cache saat job benar-benar dimulai
        Cache::put($this->jobStatusCacheKey, ['status' => 'running', 'time' => now()->toIso8601String()], now()->addHours(3)); // Cache status 'running'

        $success        = false;
        $artisanCommand = 'train:melon-' . $this->taskType;
        // Sertakan opsi yang relevan saat memanggil command
        $commandArgs = [$artisanCommand, '--with-test', '--with-cv'];

        try {
                                                   // **Jalankan Command Artisan menggunakan Symfony Process**
            $phpPath        = PHP_BINARY ?: 'php'; // Path ke PHP executable
            $artisanPath    = base_path('artisan');
            $processCommand = array_merge([$phpPath, $artisanPath], $commandArgs);
            // Gunakan timeout job sebagai timeout proses
            $process = new Process($processCommand, base_path(), null, null, $this->timeout);

            Log::info("==== Starting Process for Job {$this->jobId}: " . implode(' ', $processCommand) . " ====");

            // Broadcast event START
            event(new TrainingLogReceived($this->jobId, "🚀 Memulai {$artisanCommand}..."));

            // Jalankan proses dan tangkap output secara real-time
            $process->run(function ($type, $buffer) {
                $outputLines = explode("\n", trim($buffer));
                foreach ($outputLines as $line) {
                    if (! empty($line)) {
                        // Broadcast setiap baris output log
                        event(new TrainingLogReceived($this->jobId, $line));
                        // Log::debug("[Job {$this->jobId} Output] {$line}"); // Opsional: log ke file
                    }
                }
            });

            // Cek hasil proses setelah selesai
            if ($process->isSuccessful()) {
                $success = true;
                Cache::forget($failureKey); // Reset counter kegagalan jika sukses
                Log::info("✅ Training job for task '{$this->taskType}' (Job ID: {$this->jobId}) completed successfully via Artisan command.");

                                                       // Bersihkan cache evaluasi setelah training sukses
                $modelService->clearEvaluationCache(); // Membersihkan cache file gabungan & metadata

                // Bersihkan cache model dan scaler spesifik untuk tipe ini
                $this->clearModelCacheByType($this->taskType, $modelService);

                // Broadcast event SELESAI
                event(new TrainingJobCompleted(
                    $this->jobId,
                    "✅ Pelatihan {$this->taskType} selesai."
                ));
                Cache::forget($this->jobStatusCacheKey); // Hapus status 'running'

                // **HAPUS:** Pemanggilan logPerformanceFromCache dihapus dari sini
                // // Log performa (opsional, jika masih relevan di sini)
                // $metricsCacheFile = ($this->taskType === 'classifier') ? 'all_classifier_metrics.json' : 'all_detector_metrics.json';
                // $keySuffix = '_' . $this->taskType;
                // // $this->logPerformanceFromCache($modelService, $metricsCacheFile, $keySuffix);

            } else {
                                               // Jika proses Artisan gagal
                Cache::increment($failureKey); // Increment counter kegagalan
                $errorOutput  = $process->getErrorOutput();
                $errorMessage = "❌ Training job for task '{$this->taskType}' (Job ID: {$this->jobId}) failed via Artisan command.";
                Log::error($errorMessage, [
                    'exit_code' => $process->getExitCode(),
                    'stderr'    => Str::limit($errorOutput, 1000), // Batasi output error di log
                ]);
                // Broadcast event ERROR
                $displayError = $errorMessage . " (Exit Code: " . $process->getExitCode() . ")";
                if (! empty($errorOutput)) {
                    $displayError .= " Error: " . Str::limit(trim(preg_replace('/\s+/', ' ', $errorOutput)), 200); // Tampilkan error ringkas
                }
                event(new TrainingJobFailed($this->jobId, $displayError));
                // Throw exception agar job ditandai gagal oleh queue worker
                throw new RuntimeException($errorMessage . " Exit Code: " . $process->getExitCode());
            }
        } catch (Throwable $e) {
            // Tangani exception dari Process atau lainnya
            // Hanya increment failure jika bukan dari exception RuntimeException yang sudah dilempar di atas
            if (! ($e instanceof RuntimeException && str_contains($e->getMessage(), 'failed via Artisan command'))) {
                Cache::increment($failureKey);
            }
            Log::critical("💥 Critical Error during Training Job '{$this->taskType}' (Job ID: {$this->jobId})", [
                'exception_message' => $e->getMessage(),
                'exception_trace'   => Str::limit($e->getTraceAsString(), 2000), // Batasi trace di log
            ]);
            // Broadcast event ERROR jika ada exception tak terduga
            event(new TrainingJobFailed($this->jobId, "💥 Error kritis saat training: " . Str::limit($e->getMessage(), 200)));
            // Hapus status cache 'running' jika job gagal karena exception
            Cache::forget($this->jobStatusCacheKey);
            $this->fail($e); // Tandai job gagal secara eksplisit
        } finally {
            // Selalu lepaskan lock
            $lock->release();
            Log::info("Lock released for task: {$this->taskType}, Job ID: {$this->jobId}. Peak memory: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB");
            // Jangan hapus cache status di sini jika job gagal, biarkan method failed() atau blok catch yang menanganinya.
        }
    }

    /**
     * Bersihkan cache model dan scaler yang relevan berdasarkan tipe tugas.
     * @param string $type 'classifier' atau 'detector'
     * @param ModelService $modelService Instance ModelService
     */
    private function clearModelCacheByType(string $type, ModelService $modelService): void
    {
        $prefix        = ModelService::CACHE_PREFIX;
        $modelsToClear = [];

        // **PERBAIKAN:** Gunakan konstanta BASE_MODEL_KEYS yang sudah didefinisikan di kelas ini
        foreach (ModelService::BASE_MODEL_KEYS as $baseKey) {
            $modelKey  = $baseKey . '_' . $type;
            $scalerKey = $modelKey . '_scaler';
            // Tambahkan kunci cache model dan scaler spesifik ke daftar
            $modelsToClear[] = $prefix . $modelKey;
            $modelsToClear[] = $prefix . $scalerKey;
        }

        // Hapus setiap kunci cache dalam daftar
        foreach ($modelsToClear as $cacheKey) {
            Cache::forget($cacheKey);
            // Log::debug("Cache cleared by job: {$cacheKey}"); // Aktifkan jika perlu debug
        }

        Log::info("Model and specific scaler caches cleared by job for type: {$type}");
    }

    // **HAPUS:** Metode logPerformanceFromCache() dihapus dari Job
    /*
    private function logPerformanceFromCache(ModelService $modelService, string $cacheFileName, string $keySuffix): void
    {
        // ... (Implementasi dihapus) ...
    }
    */

    /**
     * Handle saat job gagal setelah semua percobaan (tries).
     */
    public function failed(Throwable $exception): void
    {
        Log::critical("Training job for task '{$this->taskType}' (Job ID: {$this->jobId}) has ultimately failed.", [
            'exception_message' => $exception->getMessage(),
            'exception_trace'   => Str::limit($exception->getTraceAsString(), 2000),
        ]);
        // Pastikan status 'running' dihapus jika job gagal permanen
        Cache::forget($this->jobStatusCacheKey);
        // Coba lepaskan lock jika masih ada (meskipun finally harusnya sudah)
        Cache::lock('training_lock_' . $this->taskType . '_' . $this->jobId)->forceRelease();
        Log::warning("Lock force released due to job failure for task: {$this->taskType}, Job ID: {$this->jobId}");

        // Opsional: Beri tahu admin atau lakukan aksi lain saat job gagal total
        // Misal: Notifikasi admin
    }
}
