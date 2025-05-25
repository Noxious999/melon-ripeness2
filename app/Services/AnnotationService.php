<?php
namespace App\Services;

use App\Services\DatasetService;
use Illuminate\Support\Facades\Cache; // Tambahkan ini
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class AnnotationService
{
    public const S3_ANNOTATION_DIR   = 'dataset/annotations';
    public const THUMBNAILS_PER_PAGE = 12;

    public function __construct()
    {
        // Konstruktor bisa kosong
    }

    /**
     * Membaca semua file CSV anotasi dari S3 dan mengembalikan daftar file gambar yang sudah dianotasi.
     * Kunci array adalah path S3 relatif dari root bucket (misal: 'dataset/train/gambar.jpg').
     *
     * @return array<string, bool> Array dengan path S3 gambar sebagai kunci dan true sebagai nilai.
     */
    public function getAnnotatedFilesList(): array
    {
        $cacheKey = 'annotation_service_annotated_files_list_v4'; // Versi baru
        $duration = now()->addMinutes(60);                        // Cache 1 jam

        return Cache::remember($cacheKey, $duration, function () use ($cacheKey) {
            Log::debug("[AnnotationService Cache Miss] Reading annotated files list from S3 for '{$cacheKey}'.");
            $annotatedImageS3Paths = [];

            foreach (DatasetService::DATASET_SETS as $set) {
                $s3CsvPath = self::S3_ANNOTATION_DIR . '/' . $set . '_annotations.csv';
                if (! Storage::disk('s3')->exists($s3CsvPath)) {
                    Log::info("[AnnotationService] Annotation file not found for set '{$set}': {$s3CsvPath}");
                    continue;
                }
                try {
                    $csvContent = Storage::disk('s3')->get($s3CsvPath);
                    if (empty(trim($csvContent ?? ''))) {
                        Log::info("[AnnotationService] Annotation file for set '{$set}' is empty: {$s3CsvPath}");
                        continue;
                    }
                    $lines             = explode("\n", trim($csvContent));
                    $headerFromFileRaw = array_shift($lines); // Ambil header

                    // Validasi header (opsional tapi direkomendasikan)
                    if ($headerFromFileRaw) {
                        $headerFromFile = array_map('trim', str_getcsv($headerFromFileRaw));
                        if (empty($headerFromFile) || count(array_diff(FeatureExtractionService::CSV_HEADER, $headerFromFile)) > 0 || count(array_diff($headerFromFile, FeatureExtractionService::CSV_HEADER)) > 0) {
                            Log::warning("[AnnotationService] Invalid CSV header in {$s3CsvPath}. Expected: " . implode(',', FeatureExtractionService::CSV_HEADER) . ". Got: " . implode(',', $headerFromFile));
                            // continue; // Bisa di-skip atau coba proses dengan asumsi kolom filename tetap di indeks 0
                        }
                    }

                    foreach ($lines as $line) {
                        if (empty(trim($line))) {
                            continue;
                        }

                        $row = str_getcsv($line); // Asumsi kolom filename selalu di indeks 0
                        if (isset($row[0]) && ! empty(trim($row[0]))) {
                            $imagePathInCsv = trim($row[0]);
                            $normalizedPath = str_replace('\\', '/', $imagePathInCsv);
                            // Kunci adalah path S3 relatif dari root bucket, misal "dataset/train/file.jpg"
                            $s3ImagePathKey                         = rtrim(DatasetService::S3_DATASET_BASE_DIR, '/') . '/' . ltrim($normalizedPath, '/');
                            $s3ImagePathKey                         = preg_replace('#/+#', '/', $s3ImagePathKey);
                            $annotatedImageS3Paths[$s3ImagePathKey] = true;
                        }
                    }
                } catch (Throwable $e) {
                    Log::error(
                        "[AnnotationService Cache] Error processing S3 CSV for getAnnotatedFilesList.",
                        ['path' => $s3CsvPath, 'error' => $e->getMessage()]
                    );
                }
            }
            Log::info("[AnnotationService Cache Store] Storing " . count($annotatedImageS3Paths) . " annotated file entries in cache '{$cacheKey}'.");
            return $annotatedImageS3Paths;
        });
    }

    /**
     * Melakukan listing semua file gambar (dengan ekstensi yang diizinkan)
     * dari direktori dataset di S3 (untuk setiap set: train, valid, test).
     * Mengembalikan array dengan path S3 gambar sebagai kunci dan detail sebagai nilai.
     *
     * @return array<string, array{s3Path: string, filename: string, set: string}>
     */
    public function getAllImageFiles(): array
    {
        $cacheKey = 'annotation_service_all_image_files_s3_v4'; // Versi baru untuk key
        $duration = now()->addHours(4);                         // Cache selama 4 jam, atau sesuaikan

        return Cache::remember($cacheKey, $duration, function () use ($cacheKey) {
            Log::debug("[AnnotationService Cache Miss] Memulai pembacaan ulang semua file gambar dari S3 untuk cache '{$cacheKey}'."); // Pesan diubah
            $allImageFiles     = [];
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp']; // Sesuaikan jika perlu

            foreach (DatasetService::DATASET_SETS as $set) { // Menggunakan konstanta dari DatasetService
                $s3SetDirectory = rtrim(DatasetService::S3_DATASET_BASE_DIR, '/') . '/' . $set . '/';
                // !!! TAMBAHKAN LOG INI !!!
                Log::debug("[AnnotationService Cache Miss] Proses set: '{$set}'. Listing direktori S3: '{$s3SetDirectory}'.");
                try {
                    $filesInSetS3 = Storage::disk('s3')->allFiles($s3SetDirectory);
                    // !!! TAMBAHKAN LOG INI !!!
                    Log::debug("[AnnotationService Cache Miss] Set '{$set}': Ditemukan " . count($filesInSetS3) . " file. Sampel (maks 5): " . implode(', ', array_slice($filesInSetS3, 0, 5)));

                    foreach ($filesInSetS3 as $s3FilePath) {
                        $extension = strtolower(pathinfo($s3FilePath, PATHINFO_EXTENSION));
                        if (in_array($extension, $allowedExtensions)) {
                            $allImageFiles[$s3FilePath] = [
                                's3Path'   => $s3FilePath,
                                'filename' => basename($s3FilePath),
                                'set'      => $set,
                            ];
                        }
                    }
                } catch (Throwable $e) {
                    Log::error(
                        "[AnnotationService Cache Miss] Error saat listing file S3 untuk set '{$set}'.", // Log error lebih spesifik
                        ['dir' => $s3SetDirectory, 'error' => $e->getMessage()]
                    );
                }
            }
            ksort($allImageFiles);
            // !!! TAMBAHKAN LOG INI !!!
            Log::info("[AnnotationService Cache Store] Menyimpan " . count($allImageFiles) . " entri file gambar ke cache '{$cacheKey}'. Sampel kunci (maks 10): " . implode(', ', array_slice(array_keys($allImageFiles), 0, 10)));
            return $allImageFiles;
        });
    }

    /**
     * Mendapatkan daftar file yang BELUM dianotasi.
     * @return array<string, array{s3Path: string, filename: string, set: string}>
     */
    public function getAllUnannotatedFiles(): array
    {
        $annotatedImageS3Paths = $this->getAnnotatedFilesList(); // Ini mengembalikan [pathS3 => true]
        $allImageFilesData     = $this->getAllImageFiles();      // Ini mengembalikan [pathS3 => [detail]]

        // Kita ingin file yang ada di $allImageFilesData tapi tidak ada kuncinya di $annotatedImageS3Paths
        $unannotatedFiles = array_diff_key($allImageFilesData, $annotatedImageS3Paths);

        Log::debug("[AnnotationService] Total file yang belum teranotasi dihitung.", ['count' => count($unannotatedFiles)]);
        return $unannotatedFiles;
    }
}
