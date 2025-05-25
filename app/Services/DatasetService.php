<?php
namespace App\Services;

use App\Services\AnnotationService;
use App\Services\FeatureExtractionService;
use Illuminate\Support\Facades\Cache; // Tambahkan ini
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage; // Tambahkan ini
use Illuminate\Support\Str;             // Untuk pathinfo
use Intervention\Image\ImageManagerStatic as Image;
use RuntimeException;
use Throwable;

class DatasetService
{
    public const S3_DATASET_BASE_DIR    = 'dataset';
    public const S3_THUMBNAILS_BASE_DIR = 'thumbnails';
    public const DATASET_SETS           = ['train', 'valid', 'test'];

    private const S3_SETS_DIRS = [
        'train' => self::S3_DATASET_BASE_DIR . '/train',
        'valid' => self::S3_DATASET_BASE_DIR . '/valid',
        'test'  => self::S3_DATASET_BASE_DIR . '/test',
    ];

    private const TARGET_TRAIN_RATIO             = 0.7;
    private const TARGET_VALID_RATIO             = 0.15;
    private const TARGET_TEST_RATIO              = 0.15;
    private const TARGET_CLASS_BALANCE_THRESHOLD = 0.1;

    /** @var \Illuminate\Contracts\Filesystem\Filesystem */
    private $disk;

    public function __construct()
    {
        $this->disk = Storage::disk('s3');
        assert(
            abs(self::TARGET_TRAIN_RATIO + self::TARGET_VALID_RATIO + self::TARGET_TEST_RATIO - 1.0) < 0.0001,
            'DatasetService: target ratios must sum to 1.0'
        );
    }

    public function getStatistics(): array
    {
        $cacheKey = 'dataset_service_statistics_summary_v4'; // Versi baru
        $duration = now()->addMinutes(60);                   // Cache 1 jam

        return Cache::remember($cacheKey, $duration, function () use ($cacheKey) {
            Log::debug("[DatasetService Cache Miss] Calculating dataset statistics from S3 for '{$cacheKey}'.");
            // ----- MULAI LOGIKA ASLI getStatistics() ANDA DI SINI -----
            Log::info("Calculating dataset and feature statistics from S3...");
            $stats                    = [];
            $processedAnnotationFiles = [];

            foreach (self::DATASET_SETS as $set) {
                $stats[$set] = [
                    'annotations' => [
                        'total_images_in_csv'   => 0,
                        'melon_annotations'     => 0,
                        'ripe_annotations'      => 0,
                        'unripe_annotations'    => 0,
                        'non_melon_annotations' => 0,
                    ],
                    'features'    => [
                        'detector'   => ['melon' => 0, 'non_melon' => 0, 'total' => 0],
                        'classifier' => ['ripe' => 0, 'unripe' => 0, 'total' => 0],
                    ],
                ];
                $processedAnnotationFiles[$set] = [];

                $s3AnnotationCsvPath = AnnotationService::S3_ANNOTATION_DIR . '/' . $set . '_annotations.csv';
                Log::debug("[DatasetService::getStatistics] Reading S3 annotation CSV: {$s3AnnotationCsvPath}");

                if (! Storage::disk('s3')->exists($s3AnnotationCsvPath)) {
                    Log::warning("[DatasetService::getStatistics] Annotation CSV not found for set '{$set}'.", ['s3_path' => $s3AnnotationCsvPath]);
                    continue;
                }
                try {
                    $csvContent = Storage::disk('s3')->get($s3AnnotationCsvPath);
                    if ($csvContent === null || empty(trim($csvContent))) {
                        Log::info("[DatasetService::getStatistics] S3 Annotation CSV empty or unreadable for '{$set}'.", ['s3_path' => $s3AnnotationCsvPath]);
                        continue;
                    }
                    $lines             = explode("\n", trim($csvContent));
                    $headerFromFileRaw = array_shift($lines);
                    if (empty($headerFromFileRaw)) {
                        continue;
                    }

                    $headerFromFile = array_map('trim', str_getcsv($headerFromFileRaw));
                    if (empty($headerFromFile) || count(array_diff(FeatureExtractionService::CSV_HEADER, $headerFromFile)) > 0 || count(array_diff($headerFromFile, FeatureExtractionService::CSV_HEADER)) > 0) {
                        Log::warning("[DatasetService::getStatistics] Invalid CSV header on S3.", ['s3_path' => $s3AnnotationCsvPath, 'expected' => FeatureExtractionService::CSV_HEADER, 'actual' => $headerFromFile]);
                        continue;
                    }
                    foreach ($lines as $line) {
                        if (empty(trim($line))) {
                            continue;
                        }
                        $row = str_getcsv($line);
                        if (count($row) !== count($headerFromFile)) {
                            continue;
                        }
                        $rowData = @array_combine($headerFromFile, $row);
                        if ($rowData === false) {
                            continue;
                        }
                        $filePathInCsv = trim($rowData['filename'] ?? '');
                        if (empty($filePathInCsv)) {
                            continue;
                        }
                        if (! isset($processedAnnotationFiles[$set][$filePathInCsv])) {
                            $stats[$set]['annotations']['total_images_in_csv']++;
                            $processedAnnotationFiles[$set][$filePathInCsv] = true;
                        }
                        $detectionClass = strtolower(trim($rowData['detection_class'] ?? ''));
                        $ripenessClass  = strtolower(trim($rowData['ripeness_class'] ?? ''));
                        if ($detectionClass === 'melon') {
                            $stats[$set]['annotations']['melon_annotations']++;
                            if ($ripenessClass === 'ripe') {
                                $stats[$set]['annotations']['ripe_annotations']++;
                            } elseif ($ripenessClass === 'unripe') {
                                $stats[$set]['annotations']['unripe_annotations']++;
                            }
                        } elseif ($detectionClass === 'non_melon') {
                            $stats[$set]['annotations']['non_melon_annotations']++;
                        }
                    }
                } catch (Throwable $e) {
                    Log::error("Error reading S3 annotation CSV for stats", ['s3_path' => $s3AnnotationCsvPath, 'error' => $e->getMessage()]);
                }
            }
            foreach (self::DATASET_SETS as $set) {
                $s3DetFeatPath = FeatureExtractionService::S3_FEATURE_DIR . '/' . $set . '_detector_features.csv';
                if (Storage::disk('s3')->exists($s3DetFeatPath)) {
                    try {
                        $csvContent = Storage::disk('s3')->get($s3DetFeatPath);
                        if ($csvContent !== null && ! empty(trim($csvContent))) {
                            $lines        = explode("\n", trim($csvContent));
                            $headerDetRaw = array_shift($lines);
                            if (! empty($headerDetRaw)) {
                                $headerDet = array_map('trim', str_getcsv($headerDetRaw));
                                if ($headerDet && isset($headerDet[1]) && strtolower(trim($headerDet[1])) === 'label') {
                                    foreach ($lines as $line) {
                                        if (empty(trim($line))) {
                                            continue;
                                        }

                                        $row = str_getcsv($line);
                                        if (is_array($row) && isset($row[1])) {
                                            $label = strtolower(trim($row[1]));
                                            if (in_array($label, ['melon', 'non_melon'])) {
                                                $stats[$set]['features']['detector'][$label]++;
                                                $stats[$set]['features']['detector']['total']++;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    } catch (Throwable $e) {Log::error("Error reading S3 detector features for stats", ['s3_path' => $s3DetFeatPath, 'error' => $e->getMessage()]);}
                }
                $s3ClsFeatPath = FeatureExtractionService::S3_FEATURE_DIR . '/' . $set . '_classifier_features.csv';
                if (Storage::disk('s3')->exists($s3ClsFeatPath)) {
                    try {
                        $csvContent = Storage::disk('s3')->get($s3ClsFeatPath);
                        if ($csvContent !== null && ! empty(trim($csvContent))) {
                            $lines        = explode("\n", trim($csvContent));
                            $headerClsRaw = array_shift($lines);
                            if (! empty($headerClsRaw)) {
                                $headerCls = array_map('trim', str_getcsv($headerClsRaw));
                                if ($headerCls && isset($headerCls[1]) && strtolower(trim($headerCls[1])) === 'label') {
                                    foreach ($lines as $line) {
                                        if (empty(trim($line))) {
                                            continue;
                                        }

                                        $row = str_getcsv($line);
                                        if (is_array($row) && isset($row[1])) {
                                            $label = strtolower(trim($row[1]));
                                            if (in_array($label, ['ripe', 'unripe'])) {
                                                $stats[$set]['features']['classifier'][$label]++;
                                                $stats[$set]['features']['classifier']['total']++;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    } catch (Throwable $e) {Log::error("Error reading S3 classifier features for stats", ['s3_path' => $s3ClsFeatPath, 'error' => $e->getMessage()]);}
                }
            }
            Log::info("Dataset and feature statistics calculation from S3 finished.", ['stats_final_count' => count($stats)]); // Log countnya saja
                                                                                                                               // ----- AKHIR LOGIKA ASLI getStatistics() ANDA -----
            return $stats;
        });
    }

    /**
     * Menganalisis kualitas dataset berdasarkan statistik.
     * @param array $stats Statistik dari getStatistics().
     * @return array{issues: list<string>, recommendations: list<string>} Hasil analisis.
     */
    public function analyzeQuality(array $stats): array// $stats adalah hasil dari getStatistics() yang sudah di-cache
    {
        $statsHash = md5(json_encode($stats)); // Kunci berdasarkan konten $stats
        $cacheKey  = 'dataset_service_quality_analysis_' . $statsHash;
        $duration  = now()->addHours(2);

        return Cache::remember($cacheKey, $duration, function () use ($stats, $statsHash) { // $stats perlu di-pass ke use
            Log::debug("[DatasetService Cache Miss] Analyzing dataset quality for hash '{$statsHash}'.");

            $issues               = [];
            $recommendations      = [];
            $totalOverall         = 0;
            $totalMelonOverall    = 0;
            $totalNonMelonOverall = 0;
            $totalRipeOverall     = 0;
            $totalUnripeOverall   = 0;

            foreach (self::DATASET_SETS as $set) {
                // **PERBAIKAN DI SINI:**
                $totalOverall += $stats[$set]['annotations']['total_images_in_csv'] ?? 0;
                $totalMelonOverall += $stats[$set]['annotations']['melon_annotations'] ?? 0;
                $totalNonMelonOverall += $stats[$set]['annotations']['non_melon_annotations'] ?? 0;
                $totalRipeOverall += $stats[$set]['annotations']['ripe_annotations'] ?? 0;
                $totalUnripeOverall += $stats[$set]['annotations']['unripe_annotations'] ?? 0;
            }

            if ($totalOverall === 0) {
                $issues[] = "Dataset kosong atau tidak ada anotasi yang valid ditemukan di file CSV anotasi."; // Pesan lebih spesifik
                return compact('issues', 'recommendations');
            }

            // 1. Analisis Rasio Train/Valid/Test
            // **PERBAIKAN DI SINI JUGA:**
            $trainRatio  = $totalOverall > 0 ? ($stats['train']['annotations']['total_images_in_csv'] ?? 0) / $totalOverall : 0;
            $validRatio  = $totalOverall > 0 ? ($stats['valid']['annotations']['total_images_in_csv'] ?? 0) / $totalOverall : 0;
            $testRatio   = $totalOverall > 0 ? ($stats['test']['annotations']['total_images_in_csv'] ?? 0) / $totalOverall : 0;
            $ratioIssues = false;
            if (abs($trainRatio - self::TARGET_TRAIN_RATIO) > self::TARGET_CLASS_BALANCE_THRESHOLD) {$issues[] = sprintf("Rasio data latih (%.1f%%) tidak sesuai target (%.1f%%).", $trainRatio * 100, self::TARGET_TRAIN_RATIO * 100);
                $ratioIssues                         = true;}
            if (abs($validRatio - self::TARGET_VALID_RATIO) > self::TARGET_CLASS_BALANCE_THRESHOLD) {$issues[] = sprintf("Rasio data validasi (%.1f%%) tidak sesuai target (%.1f%%).", $validRatio * 100, self::TARGET_VALID_RATIO * 100);
                $ratioIssues                         = true;}
            if (abs($testRatio - self::TARGET_TEST_RATIO) > self::TARGET_CLASS_BALANCE_THRESHOLD) {$issues[] = sprintf("Rasio data uji (%.1f%%) tidak sesuai target (%.1f%%).", $testRatio * 100, self::TARGET_TEST_RATIO * 100);
                $ratioIssues                         = true;}
            if (abs($trainRatio + $validRatio + $testRatio - 1.0) > 0.01) {$issues[] = "Total rasio pembagian set tidak 100%.";
                $ratioIssues                         = true;}
            if ($ratioIssues) {$recommendations[] = "Pertimbangkan menggunakan fitur 'Sesuaikan Keseimbangan Otomatis' untuk memperbaiki rasio pembagian data latih/validasi/uji.";}

            // 2. Analisis Keseimbangan Kelas Detektor
            foreach (self::DATASET_SETS as $set) {
                // **PERBAIKAN AKSES KEY:**
                $melonCount    = $stats[$set]['annotations']['melon_annotations'] ?? 0;
                $nonMelonCount = $stats[$set]['annotations']['non_melon_annotations'] ?? 0;
                $detectorTotal = $melonCount + $nonMelonCount;if ($detectorTotal === 0) {
                    continue;
                }

                $melonRatio    = $melonCount / $detectorTotal;
                $nonMelonRatio = $nonMelonCount / $detectorTotal;
                // Toleransi lebih besar untuk deteksi (misal 0.2 atau 20%)
                if (abs($melonRatio - 0.5) > (self::TARGET_CLASS_BALANCE_THRESHOLD + 0.1)) {
                    $issues[]          = "Ketidakseimbangan kelas detektor pada set '{$set}': Melon (" . round($melonRatio * 100) . "%) vs Non-Melon (" . round($nonMelonRatio * 100) . "%).";
                    $recommendations[] = "Tambahkan lebih banyak data untuk kelas minoritas pada set '{$set}' (detektor) atau lakukan augmentasi/oversampling/undersampling saat training.";
                }
            }

            // 3. Analisis Keseimbangan Kelas Klasifikasi
            foreach (self::DATASET_SETS as $set) {
                // **PERBAIKAN AKSES KEY:**
                $ripeCount       = $stats[$set]['annotations']['ripe_annotations'] ?? 0;
                $unripeCount     = $stats[$set]['annotations']['unripe_annotations'] ?? 0;
                $classifierTotal = $ripeCount + $unripeCount;if ($classifierTotal === 0) {
                    continue;
                }

                $ripeRatio   = $ripeCount / $classifierTotal;
                $unripeRatio = $unripeCount / $classifierTotal;
                if (abs($ripeRatio - 0.5) > self::TARGET_CLASS_BALANCE_THRESHOLD) {
                    $issues[]          = "Ketidakseimbangan kelas klasifikasi pada set '{$set}': Matang (" . round($ripeRatio * 100) . "%) vs Belum Matang (" . round($unripeRatio * 100) . "%).";
                    $recommendations[] = "Tambahkan lebih banyak data anotasi untuk kelas kematangan minoritas pada set '{$set}' atau pertimbangkan teknik penyeimbangan data saat training classifier.";
                }
            }

            if (empty($issues)) {
                $issues[] = "Tidak ada masalah keseimbangan signifikan yang terdeteksi berdasarkan ambang batas saat ini.";
            }

            return compact('issues', 'recommendations');
        });
    }

    /**
     * Menyesuaikan keseimbangan dataset antar set (train/valid/test) secara fisik.
     * Memindahkan file gambar dan memperbarui file CSV anotasi.
     *
     * @return array{success: bool, message: string, details: array} Hasil operasi.
     */
    public function adjustBalance(): array
    {
        Log::info("Starting dataset balance adjustment on S3.");
        $startTime          = microtime(true);
        $details            = ['files_moved_s3' => [], 'files_failed_move_s3' => [], 'csv_updates_s3' => [], 'errors' => []];
        $filesMovedCountS3  = 0;
        $filesFailedCountS3 = 0;

        // Validasi Pra-Kondisi File Anotasi (membaca dari S3)
        $missingOrInvalidAnnotationFiles = [];
        foreach (self::DATASET_SETS as $set) {
            $s3AnnotationCsvPath = AnnotationService::S3_ANNOTATION_DIR . '/' . $set . '_annotations.csv';
            if (! Storage::disk('s3')->exists($s3AnnotationCsvPath)) {
                $missingOrInvalidAnnotationFiles[] = "File anotasi S3 untuk set '{$set}' tidak ditemukan: {$s3AnnotationCsvPath}";
                continue;
            }
            try {
                $csvContent = Storage::disk('s3')->get($s3AnnotationCsvPath);
                if ($csvContent !== null && ! empty(trim($csvContent))) {
                    $lines  = explode("\n", trim($csvContent));
                    $header = str_getcsv(array_shift($lines) ?: '');
                    $header = array_map('trim', $header);
                    if (empty($header) || count(array_diff(FeatureExtractionService::CSV_HEADER, $header)) > 0 || count(array_diff($header, FeatureExtractionService::CSV_HEADER)) > 0) {
                        $missingOrInvalidAnnotationFiles[] = "File anotasi S3 untuk set '{$set}' memiliki header tidak valid: {$s3AnnotationCsvPath}";
                    }
                } else {
                    $missingOrInvalidAnnotationFiles[] = "File anotasi S3 untuk set '{$set}' kosong: {$s3AnnotationCsvPath}";
                }
            } catch (Throwable $e) {
                $missingOrInvalidAnnotationFiles[] = "Gagal membaca file anotasi S3 untuk set '{$set}': {$s3AnnotationCsvPath} (Error: {$e->getMessage()})";
            }
        }

        if (! empty($missingOrInvalidAnnotationFiles)) {
            $errorMessage = "Penyesuaian keseimbangan tidak dapat dilakukan karena file anotasi berikut hilang atau tidak valid: " . implode('; ', $missingOrInvalidAnnotationFiles);
            Log::error($errorMessage);
            $details['errors'][] = $errorMessage;
            return ['success' => false, 'message' => $errorMessage, 'details' => $details];
        }
        // **AKHIR LANGKAH BARU**

                                                                 // 1. Load Semua Anotasi & Data File Unik (lanjutkan jika validasi di atas lolos)
        $allAnnotationsData = $this->getAllAnnotationsGrouped(); // Ini sekarang S3-aware
        if (empty($allAnnotationsData)) {
            // Pesan ini sekarang lebih mengarah ke konten CSV yang mungkin kosong setelah header valid
            $details['errors'][] = 'Tidak ada data anotasi yang valid ditemukan di dalam file CSV (mungkin hanya header atau baris kosong).';
            return ['success' => false, 'message' => 'Gagal memulai penyesuaian: Tidak ada data anotasi yang valid di dalam file CSV.', 'details' => $details];
        }
        $uniqueFiles = array_keys($allAnnotationsData);
        $totalFiles  = count($uniqueFiles);
        if ($totalFiles === 0) {
            $details['errors'][] = 'Tidak ada file unik yang ditemukan dalam anotasi untuk disesuaikan.';
            return ['success' => false, 'message' => 'Tidak ada file unik dalam anotasi untuk disesuaikan.', 'details' => $details];
        }
        Log::info("Total unique files found in annotations: {$totalFiles}");

        // 2. Hitung Target Jumlah File per Set
        $targetCounts = [
            'train' => (int) floor($totalFiles * self::TARGET_TRAIN_RATIO),
            'valid' => (int) floor($totalFiles * self::TARGET_VALID_RATIO),
            'test'  => 0,
        ];
        $targetCounts['test'] = $totalFiles - $targetCounts['train'] - $targetCounts['valid']; // Sisa untuk test
                                                                                               // Pastikan tidak ada target negatif jika totalFiles sangat kecil
        foreach ($targetCounts as $set => $count) {if ($count < 0) {
            $targetCounts[$set] = 0;
        }}
        Log::info("Target counts per set:", $targetCounts);

        // 3. Kelompokkan File Berdasarkan Set Saat Ini
        $filesBySet = [];
        foreach (self::DATASET_SETS as $s) {$filesBySet[$s] = [];}
        foreach ($uniqueFiles as $filePath) {
            $firstAnnotationRow = reset($allAnnotationsData[$filePath]); // Ambil baris pertama
                                                                         // Pastikan kolom 'set' ada dan valid
            $currentSet = strtolower(trim($firstAnnotationRow['set'] ?? ''));
            if (! empty($currentSet) && in_array($currentSet, self::DATASET_SETS)) {
                $filesBySet[$currentSet][] = $filePath;
            } else {
                Log::warning("Cannot determine current set for file, skipping in balancing.", ['file' => $filePath, 'set_value' => $firstAnnotationRow['set'] ?? 'N/A']);
                $details['errors'][] = "Set tidak dapat ditentukan atau tidak valid untuk file: {$filePath}";
            }
        }

        // Hitung jumlah file saat ini per set
        $currentCounts = array_map('count', $filesBySet);
        foreach (self::DATASET_SETS as $s) {$currentCounts[$s] = $currentCounts[$s] ?? 0;}
        Log::info("Current counts per set:", $currentCounts);

        // 4. Rencanakan Pemindahan File
        $plannedMoves = [];
        $setsOrder    = self::DATASET_SETS; // ['train', 'valid', 'test'] - Urutan prioritas pemindahan (jika perlu)

        // Pindahkan DARI set yang kelebihan KE set yang kekurangan
        foreach ($setsOrder as $fromSet) {
            if (($currentCounts[$fromSet] ?? 0) <= $targetCounts[$fromSet]) {
                continue;
            }
            // Tidak kelebihan

            if (isset($filesBySet[$fromSet])) {
                shuffle($filesBySet[$fromSet]);
            }
            // Acak file di set sumber

            foreach ($setsOrder as $toSet) {
                if ($fromSet === $toSet) {
                    continue;
                }
                // Jangan pindah ke set yang sama
                if (($currentCounts[$toSet] ?? 0) >= $targetCounts[$toSet]) {
                    continue;
                }
                // Set tujuan sudah penuh

                $needed          = $targetCounts[$toSet] - ($currentCounts[$toSet] ?? 0);
                $availableToMove = ($currentCounts[$fromSet] ?? 0) - $targetCounts[$fromSet];
                $numToMove       = min($needed, $availableToMove, count($filesBySet[$fromSet] ?? []));

                if ($numToMove > 0) {
                    $filesToMove = array_slice($filesBySet[$fromSet], 0, $numToMove);
                    foreach ($filesToMove as $fileToMove) {
                        $plannedMoves[] = ['from' => $fromSet, 'to' => $toSet, 'filename' => $fileToMove];
                    }
                    // Update data sementara
                    $filesBySet[$fromSet] = array_slice($filesBySet[$fromSet], $numToMove);
                    $filesBySet[$toSet]   = array_merge($filesBySet[$toSet] ?? [], $filesToMove);
                    $currentCounts[$fromSet] -= $numToMove;
                    $currentCounts[$toSet] += $numToMove;
                    Log::debug("Planned to move {$numToMove} files from '{$fromSet}' to '{$toSet}'.");
                }
                // Jika set sumber sudah mencapai target, hentikan pemindahan dari set ini
                if (($currentCounts[$fromSet] ?? 0) <= $targetCounts[$fromSet]) {
                    break;
                }

            }
        }
        Log::info("Total planned moves: " . count($plannedMoves));
        $details['planned_moves_summary'] = array_count_values(array_map(fn($m) => "{$m['from']} -> {$m['to']}", $plannedMoves));

        // 5. Lakukan Operasi (Pindah File & Update CSV)
        $newAnnotationsBySet = [];
        foreach (self::DATASET_SETS as $s) {$newAnnotationsBySet[$s] = [];}

        // Proses pemindahan file dan siapkan data anotasi baru
        foreach ($allAnnotationsData as $originalS3FilePath => $annotationRows) {
            $newSetForFile         = null;
            $finalS3FilePathForCsv = $originalS3FilePath; // Default ke path asli
            $firstAnnotationRow    = reset($annotationRows);

            $movePlan = null;
            foreach ($plannedMoves as $move) {
                // $move['filename'] adalah $originalS3FilePath
                if ($move['filename'] === $originalS3FilePath) {
                    $movePlan = $move;
                    break;
                }
            }

            if ($movePlan) {
                $fromSet       = $movePlan['from'];
                $newSetForFile = $movePlan['to'];
                $baseFilename  = basename($originalS3FilePath);

                // Path S3 baru: "dataset/{new_set}/{filename}"
                // Pastikan self::S3_SETS_DIRS[$newSetForFile] sudah benar
                $newS3ImagePathTarget = self::S3_SETS_DIRS[$newSetForFile] . '/' . $baseFilename;

                try {
                    if (Storage::disk('s3')->exists($originalS3FilePath)) {
                        // Pastikan target "direktori" tidak perlu dibuat secara eksplisit untuk S3
                        if (Storage::disk('s3')->move($originalS3FilePath, $newS3ImagePathTarget)) {
                            $filesMovedCountS3++;
                            $details['files_moved_s3'][] = "{$originalS3FilePath} -> {$newS3ImagePathTarget}";
                            $finalS3FilePathForCsv       = $newS3ImagePathTarget; // Path untuk CSV adalah path baru
                            Log::debug("Moved S3 object", ['from' => $originalS3FilePath, 'to' => $newS3ImagePathTarget]);
                        } else {
                            throw new RuntimeException("Storage::move S3 failed.");
                        }
                    } else {
                        Log::warning("Source S3 file not found during move", ['s3_path' => $originalS3FilePath]);
                        $details['files_failed_move_s3'][] = "{$originalS3FilePath} (Source S3 not found)";
                        $filesFailedCountS3++;
                        $newSetForFile = $firstAnnotationRow['set'] ?? null; // Revert to original set if move fails
                                                                             // $finalS3FilePathForCsv tetap $originalS3FilePath
                    }
                } catch (Throwable $e) {
                    Log::error("Failed to move S3 object", ['from' => $originalS3FilePath, 'to' => $newS3ImagePathTarget, 'error' => $e->getMessage()]);
                    $details['files_failed_move_s3'][] = "{$originalS3FilePath} (Error: {$e->getMessage()})";
                    $filesFailedCountS3++;
                    $newSetForFile = $firstAnnotationRow['set'] ?? null; // Revert
                }
            } else {
                $newSetForFile = strtolower(trim($firstAnnotationRow['set'] ?? ''));
            }

            if (! empty($newSetForFile) && in_array($newSetForFile, self::DATASET_SETS)) {
                foreach ($annotationRows as $row) {
                    $row['set'] = $newSetForFile;

                    // PERBAIKAN: Buat path filename menjadi relatif terhadap S3_DATASET_BASE_DIR
                    // $finalS3FilePathForCsv adalah full S3 path (misal: dataset/train/gambar.jpg)
                    $relativePathForCsv = $finalS3FilePathForCsv;
                    if (Str::startsWith($finalS3FilePathForCsv, self::S3_DATASET_BASE_DIR . '/')) {
                        // Menghapus prefix 'dataset/' dari path
                        $relativePathForCsv = Str::after($finalS3FilePathForCsv, self::S3_DATASET_BASE_DIR . '/');
                    }
                                                            // Sekarang $relativePathForCsv akan menjadi "set/gambar.jpg"
                    $row['filename'] = $relativePathForCsv; // <<< INI YANG SUDAH DIPERBAIKI

                    $newAnnotationsBySet[$newSetForFile][] = $row;
                }
            } else {
                Log::warning("Could not determine final set for file, annotations might be lost.", ['file' => $originalS3FilePath, 'determined_set' => $newSetForFile]);
                $details['errors'][] = "Gagal menentukan set akhir yang valid untuk file: {$originalS3FilePath}";
            }
        } // End foreach allAnnotationsData

        // 6. Tulis Ulang File CSV Anotasi ke S3
        $csvWriteErrors = 0;
        foreach (self::DATASET_SETS as $set) {
            $s3AnnotationCsvPath   = AnnotationService::S3_ANNOTATION_DIR . '/' . $set . '_annotations.csv';
            $tempCsvContentLines   = [];
            $tempCsvContentLines[] = implode(',', FeatureExtractionService::CSV_HEADER); // Header
            $finalRowCount         = 0;

            if (isset($newAnnotationsBySet[$set])) {
                foreach ($newAnnotationsBySet[$set] as $rowDataArray) {
                    $orderedRow = [];
                    foreach (FeatureExtractionService::CSV_HEADER as $headerKey) {
                        $value = $rowDataArray[$headerKey] ?? '';
                        if (in_array($headerKey, ['bbox_cx', 'bbox_cy', 'bbox_w', 'bbox_h']) && is_numeric($value)) {
                            $orderedRow[] = number_format((float) $value, 6, '.', '');
                        } else {
                            $orderedRow[] = trim($value);
                        }
                    }
                    // Helper sederhana untuk fputcsv ke string
                    $f = fopen('php://memory', 'r+');
                    fputcsv($f, $orderedRow);
                    rewind($f);
                    $csvLine = rtrim(stream_get_contents($f), "\n");
                    fclose($f);
                    $tempCsvContentLines[] = $csvLine;
                    $finalRowCount++;
                }
            }
            $finalCsvContent = implode("\n", $tempCsvContentLines);
            if (! empty(trim($finalCsvContent)) && ! Str::endsWith($finalCsvContent, "\n")) {
                $finalCsvContent .= "\n";
            }

            try {
                // Langsung tulis ke S3
                if (! Storage::disk('s3')->put($s3AnnotationCsvPath, $finalCsvContent, 'private')) {
                    throw new RuntimeException("Failed to write updated CSV to S3: {$s3AnnotationCsvPath}");
                }
                $details['csv_updates_s3'][] = "Set '{$set}': {$finalRowCount} rows written to S3.";
                Log::info("Annotation CSV updated successfully on S3 for set '{$set}'.");
            } catch (Throwable $e) {
                Log::error("Error updating S3 annotation CSV for set '{$set}'", ['error' => $e->getMessage()]);
                $details['errors'][] = "Gagal memperbarui CSV anotasi S3 set '{$set}': {$e->getMessage()}";
                $csvWriteErrors++;
            }
        }
        // Coba hapus file temp jika masih ada
        if (isset($tempHandle) && is_resource($tempHandle)) {
            @fclose($tempHandle);
        }

        $duration      = round(microtime(true) - $startTime, 2);
        $finalMessage  = "Penyesuaian keseimbangan dataset di S3 selesai dalam {$duration} detik. ";
        $successStatus = true;

        if ($filesMovedCountS3 > 0) {
            $finalMessage .= "{$filesMovedCountS3} file gambar S3 berhasil dipindahkan. ";
        }

        if ($filesFailedCountS3 > 0) {$finalMessage .= "{$filesFailedCountS3} file gambar S3 gagal dipindahkan. ";
            $successStatus = false;}
        if ($csvWriteErrors > 0) {$finalMessage .= "Terjadi {$csvWriteErrors} error saat memperbarui file CSV anotasi di S3. ";
            $successStatus = false;}
        if (! empty($details['errors'])) {$finalMessage .= "Terdapat error lain (lihat detail). ";
            $successStatus = false;}

        if ($successStatus && $filesMovedCountS3 == 0 && count($plannedMoves) == 0 && $csvWriteErrors == 0 && empty($details['errors'])) {
            $finalMessage = "Dataset di S3 sudah seimbang atau tidak ada cukup data untuk dipindahkan. Tidak ada perubahan dilakukan.";
        } elseif ($successStatus && $filesMovedCountS3 == 0 && count($plannedMoves) > 0) {

            $finalMessage .= "Ada rencana pemindahan tetapi tidak ada file yang benar-benar dipindahkan (mungkin file sumber tidak ditemukan). Periksa detail.";
        } elseif (! $successStatus && $filesMovedCountS3 == 0 && count($plannedMoves) > 0) {
            $finalMessage .= "Gagal memindahkan file meskipun ada rencana pemindahan. ";
        }

        Log::info($finalMessage, ['details' => $details]);
        return ['success' => $successStatus, 'message' => $finalMessage, 'details' => $details];
    }

    public function getDatasetUpdateStatus(): array
    {
        $cacheKey = 'dataset_service_update_status_v4'; // Versi baru
        $duration = now()->addMinutes(30);              // Durasi cache

        return Cache::remember($cacheKey, $duration, function () use ($cacheKey) {
            Log::debug("[DatasetService Cache Miss] Performing S3 dataset integrity check for '{$cacheKey}'.");
            // ----- MULAI LOGIKA ASLI getDatasetUpdateStatus() ANDA DI SINI -----
            Log::info("[DatasetService] Starting S3 dataset integrity check (getDatasetUpdateStatus)...");

            $status = [
                'summary'        => [
                    'physical_images_s3_total'           => 0,
                    'physical_images_by_set'             => array_fill_keys(self::DATASET_SETS, 0),
                    'unique_images_in_annotation_csv'    => 0,
                    'total_bbox_annotations_in_csv'      => 0,
                    'annotations_missing_physical_file'  => 0,
                    'images_missing_any_annotation'      => 0,
                    'images_needing_detector_features'   => 0,
                    'bboxes_needing_classifier_features' => 0,
                ],
                'details'        => [
                    'annotations_without_physical_files' => [],
                    'images_needing_any_annotation'      => [],
                    'images_needing_detector_features'   => [],
                    'bboxes_needing_classifier_features' => [],
                ],
                'last_checked'   => now()->toIso8601String(),
                'image_statuses' => [],
            ];

            $allowedImageExtensions = ['jpg', 'jpeg', 'png', 'webp'];
            $physicalImageFiles     = [];

            // 1. Kumpulkan semua file gambar fisik dari S3
            foreach (self::DATASET_SETS as $set) {
                $s3SetImageDirectory = rtrim(self::S3_DATASET_BASE_DIR, '/') . '/' . $set;
                try {
                    $files = $this->disk->allFiles($s3SetImageDirectory);
                    foreach ($files as $s3FullFilePath) {
                        if (in_array(strtolower(pathinfo($s3FullFilePath, PATHINFO_EXTENSION)), $allowedImageExtensions)) {
                            $physicalImageFiles[$s3FullFilePath] = $set;
                            $status['summary']['physical_images_by_set'][$set]++;
                            $status['summary']['physical_images_s3_total']++;
                            if (! isset($status['image_statuses'][$s3FullFilePath])) {
                                $status['image_statuses'][$s3FullFilePath] = [
                                    'set'                             => $set,
                                    's3_path'                         => $s3FullFilePath,
                                    'physical_file_exists'            => true,
                                    'has_annotation_entry'            => false,
                                    'detection_class_from_annotation' => null,
                                    'has_detector_features'           => false,
                                    'bbox_annotations_details'        => [], // SELALU inisialisasi sebagai array
                                ];
                            }
                        }
                    }
                } catch (Throwable $e) {
                    Log::error("[DatasetService] Error listing S3 images in {$s3SetImageDirectory} for status: " . $e->getMessage());
                }
            }

            // 2. Proses file anotasi CSV dari S3
            $uniqueImagesInCsv = [];
            foreach (self::DATASET_SETS as $setFromFile) {
                $s3AnnotationCsvPath = rtrim(AnnotationService::S3_ANNOTATION_DIR, '/') . '/' . $setFromFile . '_annotations.csv';
                if (! $this->disk->exists($s3AnnotationCsvPath)) {
                    continue;
                }

                try {
                    $csvContent = $this->disk->get($s3AnnotationCsvPath);
                    if (empty(trim($csvContent ?? ''))) {
                        continue;
                    }

                    $lines     = explode("\n", trim($csvContent));
                    $headerRaw = array_shift($lines);
                    if (empty($headerRaw)) {
                        continue;
                    }

                    $header = array_map('trim', str_getcsv($headerRaw));

                    $filenameColIdx       = array_search('filename', $header);
                    $setCsvColIdx         = array_search('set', $header);
                    $detectionClassColIdx = array_search('detection_class', $header);
                    $ripenessClassColIdx  = array_search('ripeness_class', $header);
                    $bboxCxColIdx         = array_search('bbox_cx', $header);
                    $bboxCyColIdx         = array_search('bbox_cy', $header); // Tambahkan ini
                    $bboxWColIdx          = array_search('bbox_w', $header);  // Tambahkan ini
                    $bboxHColIdx          = array_search('bbox_h', $header);  // Tambahkan ini

                    if ($filenameColIdx === false || $detectionClassColIdx === false) {
                        Log::warning("[DatasetService] CSV {$s3AnnotationCsvPath} missing required headers 'filename' or 'detection_class'.");
                        continue;
                    }
                    $bboxIndexCounter = [];

                    foreach ($lines as $line) {
                        if (empty(trim($line))) {
                            continue;
                        }

                        $row = str_getcsv($line);
                        if (count($row) !== count($header)) {
                            continue;
                        }

                        $imagePathInCsvCell = trim($row[$filenameColIdx]);
                        if (empty($imagePathInCsvCell)) {
                            continue;
                        }

                        $s3ImagePathKey                     = rtrim(self::S3_DATASET_BASE_DIR, '/') . '/' . $imagePathInCsvCell;
                        $s3ImagePathKey                     = preg_replace('#/+#', '/', $s3ImagePathKey);
                        $uniqueImagesInCsv[$s3ImagePathKey] = true;

                        if (! isset($status['image_statuses'][$s3ImagePathKey])) {
                            $status['image_statuses'][$s3ImagePathKey] = [
                                'set'                             => strtolower(trim(($setCsvColIdx !== false && isset($row[$setCsvColIdx])) ? $row[$setCsvColIdx] : $setFromFile)),
                                's3_path'                         => $s3ImagePathKey,
                                'physical_file_exists'            => isset($physicalImageFiles[$s3ImagePathKey]),
                                'has_annotation_entry'            => false,
                                'detection_class_from_annotation' => null,
                                'has_detector_features'           => false,
                                'bbox_annotations_details'        => [], // SELALU inisialisasi sebagai array
                            ];
                        }
                        $status['image_statuses'][$s3ImagePathKey]['has_annotation_entry'] = true;
                        $currentDetectionClass                                             = strtolower(trim($row[$detectionClassColIdx]));

                        if (empty($status['image_statuses'][$s3ImagePathKey]['detection_class_from_annotation']) ||
                            ($status['image_statuses'][$s3ImagePathKey]['detection_class_from_annotation'] !== 'melon' && $currentDetectionClass === 'melon')) {
                            $status['image_statuses'][$s3ImagePathKey]['detection_class_from_annotation'] = $currentDetectionClass;
                        }

                        if ($currentDetectionClass === 'melon') {
                            $bboxIndexCounter[$s3ImagePathKey] = ($bboxIndexCounter[$s3ImagePathKey] ?? 0) + 1;
                            $annotationId                      = pathinfo(basename($imagePathInCsvCell), PATHINFO_FILENAME) . "_bbox" . $bboxIndexCounter[$s3ImagePathKey];

                            if (! is_array($status['image_statuses'][$s3ImagePathKey]['bbox_annotations_details'])) {
                                $status['image_statuses'][$s3ImagePathKey]['bbox_annotations_details'] = [];
                            }

                            $status['image_statuses'][$s3ImagePathKey]['bbox_annotations_details'][$annotationId] = [
                                'ripeness_class'          => ($ripenessClassColIdx !== false && isset($row[$ripenessClassColIdx])) ? strtolower(trim($row[$ripenessClassColIdx])) : '',
                                'bbox_cx'                 => ($bboxCxColIdx !== false && isset($row[$bboxCxColIdx])) ? trim($row[$bboxCxColIdx]) : null,
                                'bbox_cy'                 => ($bboxCyColIdx !== false && isset($row[$bboxCyColIdx])) ? trim($row[$bboxCyColIdx]) : null,
                                'bbox_w'                  => ($bboxWColIdx !== false && isset($row[$bboxWColIdx])) ? trim($row[$bboxWColIdx]) : null,
                                'bbox_h'                  => ($bboxHColIdx !== false && isset($row[$bboxHColIdx])) ? trim($row[$bboxHColIdx]) : null,
                                'has_bbox_data_in_csv'    => ($bboxCxColIdx !== false && ! empty(trim($row[$bboxCxColIdx] ?? ''))), // Cek jika cx ada
                                'has_classifier_features' => false,                                                                // Default
                            ];
                            $status['summary']['total_bbox_annotations_in_csv']++;
                        }
                    }
                } catch (Throwable $e) {
                    Log::error("[DatasetService] Error reading S3 annotation CSV {$s3AnnotationCsvPath} for status: " . $e->getMessage());
                }
            }
            $status['summary']['unique_images_in_annotation_csv'] = count($uniqueImagesInCsv);

            // 3. Bandingkan & identifikasi masalah anotasi
            foreach ($status['image_statuses'] as $s3Path => $imageData) {
                if (($imageData['has_annotation_entry'] ?? false) && ! ($imageData['physical_file_exists'] ?? false)) {
                    $status['summary']['annotations_missing_physical_file']++;
                    $status['details']['annotations_without_physical_files'][$s3Path] = $imageData['set'] ?? 'unknown';
                } elseif (($imageData['physical_file_exists'] ?? false) && ! ($imageData['has_annotation_entry'] ?? false)) {
                    $status['summary']['images_missing_any_annotation']++;
                    $status['details']['images_needing_any_annotation'][$s3Path] = $imageData['set'] ?? 'unknown';
                }
            }

            // 4. Cek file fitur dari S3
            $existingDetectorFeatures   = [];
            $existingClassifierFeatures = [];
            foreach (self::DATASET_SETS as $set) {
                $s3DetFeatPath = rtrim(FeatureExtractionService::S3_FEATURE_DIR, '/') . '/' . $set . '_detector_features.csv';
                if ($this->disk->exists($s3DetFeatPath)) {
                    try {
                        $csvContent = $this->disk->get($s3DetFeatPath);
                        if ($csvContent) {
                            $lines        = explode("\n", trim($csvContent));
                            $headerDetRaw = array_shift($lines);
                            if ($headerDetRaw) {
                                $headerDet             = array_map('trim', str_getcsv($headerDetRaw));
                                $filenameColFeatDetIdx = array_search('filename', $headerDet);
                                if ($filenameColFeatDetIdx !== false) {
                                    foreach ($lines as $line) {
                                        if (empty(trim($line))) {
                                            continue;
                                        }

                                        $row = str_getcsv($line);
                                        if (count($row) > $filenameColFeatDetIdx && ! empty(trim($row[$filenameColFeatDetIdx]))) {
                                            $featureImagePathKey                            = rtrim(self::S3_DATASET_BASE_DIR, '/') . '/' . trim($row[$filenameColFeatDetIdx]);
                                            $featureImagePathKey                            = preg_replace('#/+#', '/', $featureImagePathKey);
                                            $existingDetectorFeatures[$featureImagePathKey] = true;
                                        }
                                    }
                                }
                            }
                        }
                    } catch (Throwable $e) {Log::error("[DatasetService] Error reading S3 detector features {$s3DetFeatPath} for status: " . $e->getMessage());}
                }

                $s3ClsFeatPath = rtrim(FeatureExtractionService::S3_FEATURE_DIR, '/') . '/' . $set . '_classifier_features.csv';
                if ($this->disk->exists($s3ClsFeatPath)) {
                    try {
                        $csvContent = $this->disk->get($s3ClsFeatPath);
                        if ($csvContent) {
                            $lines        = explode("\n", trim($csvContent));
                            $headerClsRaw = array_shift($lines);
                            if ($headerClsRaw) {
                                $headerCls          = array_map('trim', str_getcsv($headerClsRaw));
                                $annIdColFeatClsIdx = array_search('annotation_id', $headerCls);
                                if ($annIdColFeatClsIdx !== false) {
                                    foreach ($lines as $line) {
                                        if (empty(trim($line))) {
                                            continue;
                                        }

                                        $row = str_getcsv($line);
                                        if (count($row) > $annIdColFeatClsIdx && ! empty(trim($row[$annIdColFeatClsIdx]))) {
                                            $existingClassifierFeatures[trim($row[$annIdColFeatClsIdx])] = true;
                                        }
                                    }
                                }
                            }
                        }
                    } catch (Throwable $e) {Log::error("[DatasetService] Error reading S3 classifier features {$s3ClsFeatPath} for status: " . $e->getMessage());}
                }
            }

                                                                                      // 5. Cek fitur yang hilang dan UPDATE STATUS GAMBAR (dengan referensi dan pengecekan)
            foreach ($status['image_statuses'] as $s3ImagePathKey => &$imageStatus) { // <<<--- GUNAKAN REFERENSI (&)
                if (! ($imageStatus['physical_file_exists'] ?? false) || ! ($imageStatus['has_annotation_entry'] ?? false)) {
                    continue;
                }

                if (! isset($existingDetectorFeatures[$s3ImagePathKey])) {
                    $status['summary']['images_needing_detector_features']++;
                    if (! isset($status['details']['images_needing_detector_features'])) {
                        $status['details']['images_needing_detector_features'] = [];
                    }

                    $status['details']['images_needing_detector_features'][$s3ImagePathKey] = $imageStatus['detection_class_from_annotation'] ?? 'unknown';
                    $imageStatus['has_detector_features']                                   = false;
                } else {
                    $imageStatus['has_detector_features'] = true;
                }

                if (($imageStatus['detection_class_from_annotation'] ?? null) === 'melon') {
                    if (! is_array($imageStatus['bbox_annotations_details'])) {
                        Log::error("[DatasetService] KRITIS SAAT CEK FITUR: 'bbox_annotations_details' BUKAN ARRAY untuk '{$s3ImagePathKey}'. Tipe: " . gettype($imageStatus['bbox_annotations_details']));
                        $imageStatus['bbox_annotations_details'] = [];
                    }

                    /** @var array<string, array<string, mixed>> $imageStatus['bbox_annotations_details'] */
                    if (! empty($imageStatus['bbox_annotations_details'])) {
                        foreach ($imageStatus['bbox_annotations_details'] as $annotationId => &$bboxDetail) { // <<<--- GUNAKAN REFERENSI (&)
                            if (is_array($bboxDetail)) {
                                if (isset($existingClassifierFeatures[$annotationId])) {
                                    $bboxDetail['has_classifier_features'] = true;
                                } else {
                                    $bboxDetail['has_classifier_features'] = false;
                                    $status['summary']['bboxes_needing_classifier_features']++;
                                    if (! isset($status['details']['bboxes_needing_classifier_features'])) {
                                        $status['details']['bboxes_needing_classifier_features'] = [];
                                    }

                                    $status['details']['bboxes_needing_classifier_features'][$annotationId] = $s3ImagePathKey;
                                }
                            } else {
                                Log::warning("[DatasetService] Entri \$bboxDetail BUKAN ARRAY untuk annId '{$annotationId}' di '{$s3ImagePathKey}'.");
                            }
                        }
                        unset($bboxDetail);
                    }
                }
            }
            unset($imageStatus);

            Log::info("[DatasetService] S3 dataset integrity check completed.", ['summary' => $status['summary']]);
            return $status;
        });
    }

    private function getAllAnnotationsGrouped(): array
    {
        $allAnnotations = [];
        Log::debug("Reading all annotation files from S3 to group data...");
        foreach (self::DATASET_SETS as $set) {
            $s3AnnotationCsvPath = AnnotationService::S3_ANNOTATION_DIR . '/' . $set . '_annotations.csv';
            if (! Storage::disk('s3')->exists($s3AnnotationCsvPath)) {
                continue;
            }

            try {
                $csvContent = Storage::disk('s3')->get($s3AnnotationCsvPath);
                if (empty(trim($csvContent ?? ''))) {
                    continue;
                }

                $lines             = explode("\n", trim($csvContent));
                $headerFromFileRaw = array_shift($lines);
                if (empty($headerFromFileRaw)) {
                    continue;
                }

                $headerFromFile = array_map('trim', str_getcsv($headerFromFileRaw));
                if (empty($headerFromFile) || count(array_diff(FeatureExtractionService::CSV_HEADER, $headerFromFile)) > 0 || count(array_diff($headerFromFile, FeatureExtractionService::CSV_HEADER)) > 0) {
                    Log::warning("[DatasetService::getAllAnnotationsGrouped] Invalid CSV header on S3.", ['s3_path' => $s3AnnotationCsvPath, 'header' => $headerFromFile]);
                    continue;
                }
                foreach ($lines as $line) {
                    if (empty(trim($line))) {
                        continue;
                    }

                    $row = str_getcsv($line);
                    if (count($row) !== count($headerFromFile)) {
                        continue;
                    }

                    $rowData = @array_combine($headerFromFile, $row);
                    if ($rowData === false) {
                        continue;
                    }

                    $filePathInCsv = trim($rowData['filename'] ?? '');
                    if (! empty($filePathInCsv)) {
                        $normalizedRelativePath = str_replace('\\', '/', $filePathInCsv);
                        $rowData['set']         = strtolower(trim($rowData['set'] ?? $set));
                        if (! in_array($rowData['set'], self::DATASET_SETS)) {
                            $rowData['set'] = $set;
                        }
                        $fullS3Path                    = rtrim(self::S3_DATASET_BASE_DIR, '/') . '/' . $normalizedRelativePath;
                        $fullS3Path                    = preg_replace('#/+#', '/', $fullS3Path);
                        $allAnnotations[$fullS3Path][] = $rowData;
                    }
                }
            } catch (Throwable $e) {
                Log::error("Error reading S3 annotation CSV for grouping", ['s3_path' => $s3AnnotationCsvPath, 'error' => $e->getMessage()]);
            }
        }
        return $allAnnotations;
    }

    /**
     * Membuat dan menyimpan thumbnail untuk gambar.
     *
     * @param string $originalS3Path Path lengkap S3 ke gambar asli (misal: "dataset/train/namafile.jpg")
     * @param string $targetSet Set tujuan ('train', 'valid', atau 'test')
     * @param string $filename Nama file (misal: "namafile.jpg")
     * @param int $width Lebar thumbnail (default 150)
     * @param int|null $height Tinggi thumbnail (null untuk menjaga rasio aspek berdasarkan lebar)
     * @return bool Berhasil atau tidak
     */
    public function generateAndStoreThumbnail(string $originalS3Path, string $targetSet, string $filename, int $width = 150, ?int $height = null): bool
    {
        try {
            $diskS3 = Storage::disk('s3');
            if (! $diskS3->exists($originalS3Path)) {
                Log::error("Gambar asli tidak ditemukan untuk pembuatan thumbnail: {$originalS3Path}");
                return false;
            }

            $imageContent = $diskS3->get($originalS3Path);
            $img          = Image::make($imageContent); // 1. Gambar asli dimuat

            $img->resize($width, $height, function ($constraint) { // 2. Gambar diubah ukurannya (thumbnail dibuat dalam memori)
                $constraint->aspectRatio();
                $constraint->upsize();
            });

            $thumbnailS3Path = self::S3_THUMBNAILS_BASE_DIR . '/' . $targetSet . '/' . $filename;
            $thumbnailS3Path = preg_replace('#/+#', '/', $thumbnailS3Path);

            $imageExtension = strtolower(File::extension($filename));
            $outputFormat   = in_array($imageExtension, ['jpeg', 'jpg', 'png', 'webp', 'gif']) ? $imageExtension : 'jpg';

            // --- INILAH LANGKAH PEMBUATAN OBJEK THUMBNAIL DI S3 ---
            if ($diskS3->put($thumbnailS3Path, (string) $img->encode($outputFormat, 75))) {
                //    ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
                //    Baris ini mengambil data gambar thumbnail yang sudah di-encode
                //    dan MENGUNGGAHNYA/MENYIMPANNYA ke S3 pada path $thumbnailS3Path.
                //    Proses inilah yang "menciptakan" objek thumbnail di bucket S3 Anda.
                Log::info("Thumbnail berhasil dibuat dan disimpan ke: {$thumbnailS3Path}");
                return true;
            } else {
                Log::error("Gagal menyimpan thumbnail ke S3: {$thumbnailS3Path}");
                return false;
            }
            // --- AKHIR LANGKAH PEMBUATAN OBJEK ---

        } catch (Throwable $e) {
            Log::error("Error saat membuat thumbnail untuk {$originalS3Path}: " . $e->getMessage(), ['trace_simple' => Str::limit($e->getTraceAsString(), 500)]);
            return false;
        }
    }
}
