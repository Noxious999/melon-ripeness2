<?php
namespace App\Console\Commands;

use App\Services\DatasetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// Tidak perlu import ProgressBar secara spesifik jika menggunakan $this->output->createProgressBar() dari SymfonyStyle
// Namun, jika ada masalah runtime, kita bisa import dan buat instance manual:
// use Symfony\Component\Console\Helper\ProgressBar;

class GenerateDatasetThumbnails extends Command
{
    protected $signature   = 'app:generate-dataset-thumbnails {--force : Overwrite existing thumbnails}';
    protected $description = 'Generate thumbnails for all images in the dataset';

    protected DatasetService $datasetService;

    public function __construct(DatasetService $datasetService)
    {
        parent::__construct();
        $this->datasetService = $datasetService;
    }

    public function handle()
    {
        $this->info('Starting thumbnail generation process...');
        Log::info('Starting batch thumbnail generation via Artisan command...');

        $diskS3         = Storage::disk('s3');
        $forceOverwrite = $this->option('force'); // Mengambil nilai dari opsi --force

        foreach (DatasetService::DATASET_SETS as $set) {
            $this->line("Processing set: <fg=cyan>{$set}</>");
            $originalImageDirectory = DatasetService::S3_DATASET_BASE_DIR . '/' . $set;
            // Path thumbnail menggunakan konstanta dari DatasetService
            $thumbnailImageDirectory = DatasetService::S3_THUMBNAILS_BASE_DIR . '/' . $set;
            $thumbnailImageDirectory = preg_replace('#/+#', '/', $thumbnailImageDirectory);

            $originalFiles = [];
            try {
                $originalFiles = $diskS3->files($originalImageDirectory);
            } catch (\Exception $e) {
                $this->error("Could not list files in {$originalImageDirectory}: " . $e->getMessage());
                Log::error("Could not list files in {$originalImageDirectory} for thumbnail generation: " . $e->getMessage());
                continue; // Lanjutkan ke set berikutnya jika gagal listing
            }

            if (empty($originalFiles)) {
                $this->line("No original images found in: <fg=yellow>{$originalImageDirectory}</>");
                Log::info("No original images found in {$originalImageDirectory} for set {$set}.");
                continue;
            }

            // $this->output di Laravel command biasanya adalah instance dari SymfonyStyle
            /** @var \Symfony\Component\Console\Style\SymfonyStyle $outputStyle */
            $outputStyle = $this->output;
            $progressBar = $outputStyle->createProgressBar(count($originalFiles));

            // Atur format progress bar agar lebih informatif
            $progressBar->setFormatDefinition('custom', ' %current%/%max% [%bar%] %percent:3s%% -- %message%');
            $progressBar->setFormat('custom');
            $progressBar->setMessage('Starting...');
            $progressBar->start();

            $generatedCount = 0;
            $skippedCount   = 0;
            $errorCount     = 0;

            foreach ($originalFiles as $originalS3Path) {
                $filename = basename($originalS3Path);
                $progressBar->setMessage("Processing <fg=magenta>{$filename}</>");

                $expectedThumbnailPath = $thumbnailImageDirectory . '/' . $filename;
                $expectedThumbnailPath = preg_replace('#/+#', '/', $expectedThumbnailPath);

                if (! $forceOverwrite && $diskS3->exists($expectedThumbnailPath)) {
                    $progressBar->advance();
                    $skippedCount++;
                    continue; // Lewati jika thumbnail sudah ada dan tidak ada opsi --force
                }

                $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'])) { // Hanya proses ekstensi gambar umum
                    $progressBar->advance();
                    continue;
                }

                if ($this->datasetService->generateAndStoreThumbnail($originalS3Path, $set, $filename)) {
                    $generatedCount++;
                } else {
                    // Pesan error sudah di-log di dalam generateAndStoreThumbnail
                    $errorCount++;
                }
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->output->newLine(2); // Memberi spasi setelah progress bar

            $this->line("Set <fg=cyan>{$set}</> processed. Generated: <fg=green>{$generatedCount}</>, Skipped: <fg=yellow>{$skippedCount}</>, Errors: <fg=red>{$errorCount}</>");
            Log::info("Batch thumbnail generation for set '{$set}' completed.", ['generated' => $generatedCount, 'skipped' => $skippedCount, 'errors' => $errorCount]);
        }

        $this->info('Thumbnail generation process finished!');
        Log::info('Batch thumbnail generation via Artisan command finished.');

        // Menggunakan nilai integer 0 untuk sukses, yang setara dengan Command::SUCCESS
        // Ini untuk menghindari masalah resolusi konstanta oleh Intelephense.
        return 0;
    }
}
