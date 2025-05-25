<?php

// File: app/Services/ModelService.php

namespace App\Services;

use App\Persisters\S3ObjectPersister;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Rubix\ML\Estimator;
use Rubix\ML\Transformers\Transformer;
use RuntimeException;
use Throwable;

class ModelService
{
    public const MODEL_DIR_S3 = 'models'; // Path prefix di S3
    public const MODEL_TYPES  = ['classifier', 'detector'];

    public const CACHE_PREFIX = 'ml_model_';

    public const BASE_MODEL_KEYS = [
        'gaussian_nb',
        'classification_tree',
        'logistic_regression',
        'ada_boost',
        'k_nearest_neighbors',
        'logit_boost',
        'multilayer_perceptron',
        'random_forest',
    ];

    protected const CACHE_DURATION = 3600; // Cache 1 jam

    public function __construct()
    {
    }

    /**
     * Memuat model (.model) atau scaler (.phpdata) dengan nama kunci yang tepat.
     *
     * @param string $key Kunci lengkap (misal: 'gaussian_nb_detector' atau 'gaussian_nb_detector_scaler')
     * @return Estimator|Transformer|object|null
     */
    public function loadModel(string $key): ?object
    {
        $cacheKey = self::CACHE_PREFIX . $key;
        // Coba ambil dari cache dulu
        // Gunakan closure untuk menghindari eksekusi Cache::get jika $key kosong (meski tidak seharusnya)
        $cached = Cache::get($cacheKey);
        if ($cached) {
            Log::debug("Object '{$key}' loaded from cache.");
            // Pastikan yang di cache adalah objek (mencegah data rusak di cache)
            return is_object($cached) ? $cached : null;
        }

        Log::debug("Object '{$key}' not in cache, loading from file.");
        $s3ObjectPath = null;      // Ganti nama variabel agar jelas ini path S3
        $fileType     = 'unknown'; // Untuk logging

        // Tentukan path file berdasarkan akhiran kunci
        if (str_ends_with($key, '_scaler')) {
            $s3ObjectPath = rtrim(self::MODEL_DIR_S3, '/') . '/' . $key . '.phpdata';
        } else {
            $s3ObjectPath = rtrim(self::MODEL_DIR_S3, '/') . '/' . $key . '.model';
        }

                                                           // Jika path tidak ditemukan setelah dicek
        if (! Storage::disk('s3')->exists($s3ObjectPath)) { // Cek di S3
            Log::warning("Expected {$fileType} file not found for key '{$key}'.", context: ['path' => $s3ObjectPath]);
            return null;
        }

        // Coba load dari file menggunakan persister
        try {
            $persister    = new S3ObjectPersister($s3ObjectPath); // Gunakan S3 persister
            $loadedObject = $persister->load();

            // Validasi bahwa yang di-load adalah objek
            if (! is_object($loadedObject)) {
                // Hapus file yang rusak jika memungkinkan? Atau cukup log error.
                Log::error("Loaded content is not an object from file.", ['key' => $key, 'path' => $s3ObjectPath]);
                             // File::delete($path); // Hati-hati dengan penghapusan otomatis
                return null; // Jangan cache data yang rusak
            }

            // Simpan ke cache jika berhasil load
            Cache::put($cacheKey, $loadedObject, self::CACHE_DURATION);
            Log::info("Object '{$key}' loaded from file and cached.", ['path' => $s3ObjectPath, 'class' => get_class($loadedObject)]);
            return $loadedObject;
        } catch (Throwable $e) {
            // Tangani error saat load (misal: unserialize error, file read error)
            Log::error("Failed to load object from file", [
                'key'   => $key,
                'path'  => $s3ObjectPath,
                'error' => $e->getMessage(),
                // 'trace' => Str::limit($e->getTraceAsString(), 500) // Mungkin terlalu verbose untuk log reguler
            ]);
            return null;
        }
    }

    /** Memuat metadata model (dengan caching) */
    public function loadModelMetadata(string $modelKey): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $modelKey . '_meta';
        // Durasi cache bisa disesuaikan, misal 6 jam
        $cacheDuration = now()->addHours(6);

        // Gunakan Cache::remember untuk load dari file jika cache miss
        return Cache::remember($cacheKey, $cacheDuration, function () use ($modelKey) {
            $s3MetaPath = rtrim(self::MODEL_DIR_S3, '/') . '/' . $modelKey . '_meta.json';
            Log::debug("Memuat metadata dari S3 untuk {$modelKey} dari {$s3MetaPath}");

            if (! Storage::disk('s3')->exists($s3MetaPath)) {
                Log::warning("File metadata tidak ditemukan di S3", ['s3_path' => $s3MetaPath]);
                return null;
            }
            try {
                $content = Storage::disk('s3')->get($s3MetaPath);
                if ($content === null) { // Penanganan jika File::get gagal
                    Log::error("Failed to read metadata file content for {$modelKey}", ['path' => $s3MetaPath]);
                    return null;
                }
                $metadata = json_decode($content, true);

                // Cek error JSON decode
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error("Failed to decode metadata JSON for {$modelKey}", [
                        'path'          => $s3MetaPath,
                        'error_code'    => json_last_error(),
                        'error_message' => json_last_error_msg(),
                        // 'content_sample' => Str::limit($content, 200) // Log sampel jika perlu debug
                    ]);
                    return null; // Kembalikan null jika JSON tidak valid
                }

                // Pastikan hasil decode adalah array
                return is_array($metadata) ? $metadata : null;
            } catch (Throwable $e) {
                Log::error("Error reading metadata file for {$modelKey}", ['path' => $s3MetaPath, 'error' => $e->getMessage()]);
                return null; // Kembalikan null jika ada exception lain
            }
        });
    }

    /** Memuat metrik evaluasi validasi (dari cache gabungan, dengan caching) */
    public function loadModelMetrics(?string $modelKey, ?string $type = null): ?array// (A) Tambahkan parameter $type seperti diskusi sebelumnya
    {
        $metricsFile = '';
        // (B) Logika menentukan $metricsFile berdasarkan $modelKey atau $type
        if ($type === 'detector' || ($modelKey && str_ends_with($modelKey, '_detector'))) {
            $metricsFile = 'all_detector_metrics.json';
        } elseif ($type === 'classifier' || ($modelKey && str_ends_with($modelKey, '_classifier'))) {
            $metricsFile = 'all_classifier_metrics.json';
        } else {
            // Jika $modelKey null dan $type juga null (atau tidak valid),
            // kita tidak bisa menentukan file metrik mana yang harus dimuat.
            // Ini bisa jadi error atau Anda bisa memiliki default (misal, load keduanya dan gabungkan,
            // tapi itu akan mengubah cara fungsi ini bekerja secara signifikan).
            // Untuk sekarang, asumsikan ini adalah kondisi error jika tidak ada yang cocok.
            Log::warning("Tidak dapat menentukan file metrik gabungan untuk dimuat.", compact('modelKey', 'type'));
            return null;
        }

        $cacheKey      = self::CACHE_PREFIX . str_replace('.json', '', $metricsFile); // Buat cacheKey dari $metricsFile
        $cacheDuration = now()->addHours(6);

        // (C) Definisikan $metricsPath DI LUAR closure
        $s3MetricsPath = rtrim(self::MODEL_DIR_S3, '/') . '/' . $metricsFile;
        Log::debug("[Cache Miss] Memuat metrik validasi gabungan dari S3: {$s3MetricsPath}");

        $allMetrics = Cache::remember($cacheKey, $cacheDuration, function () use ($metricsFile, $s3MetricsPath) { // Sekarang $metricsPath sudah terdefinisi
            Log::debug("[Cache Miss] Loading combined validation metrics from file: {$metricsFile}");
            Log::debug("Attempting to read file: {$s3MetricsPath}"); // $metricsPath sudah bisa diakses

            try {
                $s3Config = config('filesystems.disks.s3');
                Log::debug("[ModelService - S3 Config Check] Region being used", ['region' => $s3Config['region'] ?? 'NOT_SET']);
                Log::debug("[ModelService - S3 Config Check] Bucket being used", ['bucket' => $s3Config['bucket'] ?? 'NOT_SET']);
                Log::debug("[ModelService - S3 Config Check] Endpoint being used", ['endpoint' => $s3Config['endpoint'] ?? 'NOT_SET_OR_DEFAULT']);
                Log::debug("[ModelService - S3 Config Check] Path style being used", ['use_path_style_endpoint' => $s3Config['use_path_style_endpoint'] ?? 'NOT_SET_OR_DEFAULT']);
            } catch (Throwable $configEx) {
                Log::error("[ModelService - S3 Config Check] Error getting S3 config details", ['error' => $configEx->getMessage()]);
            }

            if (! Storage::disk('s3')->exists($s3MetricsPath)) {
                Log::warning("Combined validation metrics file not found", ['path' => $s3MetricsPath]);
                return null;
            }

            try {
                $content = Storage::disk('s3')->get($s3MetricsPath);
                if ($content === false) {
                    Log::error("Failed to read combined validation metrics file content", ['path' => $s3MetricsPath]);
                    return null;
                }
                if (empty(trim($content))) {
                    Log::warning("Combined validation metrics file is empty.", ['path' => $s3MetricsPath]);
                    return null;
                }

                $decoded   = json_decode($content, true);
                $jsonError = json_last_error();

                if ($jsonError !== JSON_ERROR_NONE || ! is_array($decoded)) {
                    Log::error("Invalid JSON format or decode error in combined validation metrics file", [
                        'path'          => $s3MetricsPath,
                        'error_code'    => $jsonError,
                        'error_message' => json_last_error_msg(),
                    ]);
                    return null;
                }

                Log::info("Successfully loaded and decoded combined validation metrics file: {$metricsFile}");
                return $decoded;
            } catch (Throwable $e) {
                Log::error("Exception reading/decoding combined validation metrics file", ['path' => $s3MetricsPath, 'error' => $e->getMessage()]);
                return null;
            }
        });

        if ($allMetrics === null) {
            Log::warning("Gagal memuat atau decode cache/file metrik gabungan: {$metricsFile}");
            return null;
        }

        // Jika modelKey null DAN tipe diberikan, kembalikan semua metrik untuk tipe itu
        if ($modelKey === null && $type !== null) {
            Log::debug("Mengembalikan semua metrik untuk tipe '{$type}' dari file: {$metricsFile}");
            return $allMetrics; // KEMBALIKAN SELURUH ARRAY $allMetrics
        }
        // Jika modelKey spesifik diminta dan ada di data yang dimuat
        else if ($modelKey && isset($allMetrics[$modelKey])) {
            Log::debug("Metrik validasi diambil dari cache gabungan untuk model spesifik {$modelKey}");
            return $allMetrics[$modelKey];
        }
        // Jika modelKey spesifik diminta tapi tidak ada di data yang dimuat
        else if ($modelKey) {
            Log::warning("Data metrik validasi tidak ditemukan untuk {$modelKey} dalam cache gabungan.", ['file' => $metricsFile]);
            return null;
        }

        return null; // Fallback
    }

    /** Memuat data learning curve dari file JSON spesifik model */
    public function loadLearningCurveData(string $modelKey): ?array
    {
        // Tambahkan Caching untuk konsistensi dengan metode load lainnya
        $cacheKey = self::CACHE_PREFIX . $modelKey . '_learning_curve';
                                             // Durasi cache bisa sama dengan metadata atau disesuaikan
        $cacheDuration = now()->addHours(6); // Misal 6 jam

        return Cache::remember($cacheKey, $cacheDuration, function () use ($modelKey) {
            $s3LcPath = rtrim(self::MODEL_DIR_S3, '/') . '/' . $modelKey . '_learning_curve.json';
            Log::debug("[Cache Miss] Memuat learning curve dari S3 untuk {$modelKey} dari {$s3LcPath}");

            if (! Storage::disk('s3')->exists($s3LcPath)) {
                Log::warning("File learning curve tidak ditemukan di S3 untuk {$modelKey}", ['s3_path' => $s3LcPath]);
                return null;
            }

            try {
                $content = Storage::disk('s3')->get($s3LcPath);
                if ($content === null) {
                    Log::error("Gagal membaca konten file learning curve dari S3 (null returned)", ['s3_path' => $s3LcPath]);
                    return null;
                }
                if (empty(trim($content))) {
                    Log::warning("File learning curve S3 kosong untuk {$modelKey}.", ['s3_path' => $s3LcPath]);
                    return null;
                }

                $data = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
                    Log::error("Gagal decode JSON learning curve dari S3 untuk {$modelKey}", [
                        's3_path'    => $s3LcPath,
                        'json_error' => json_last_error_msg(),
                    ]);
                    return null;
                }

                // Validasi dasar struktur data LC
                if (
                    ! isset($data['train_sizes']) || ! isset($data['train_scores']) || ! isset($data['test_scores']) ||
                    ! is_array($data['train_sizes']) || ! is_array($data['train_scores']) || ! is_array($data['test_scores'])
                ) {
                    Log::warning("Struktur data learning curve dari S3 tidak valid untuk {$modelKey}", ['s3_path' => $s3LcPath]);
                    return null; // Kembalikan null jika struktur tidak sesuai harapan
                }

                Log::info("Data learning curve berhasil dimuat dari S3 dan di-cache untuk {$modelKey}", ['s3_path' => $s3LcPath]);
                return $data;
            } catch (Throwable $e) {
                Log::error("Error membaca file learning curve dari S3 untuk {$modelKey}", ['s3_path' => $s3LcPath, 'error' => $e->getMessage()]);
                return null;
            }
        });
    }

    /**
     * Memuat data hasil cross-validation lengkap dari file JSON spesifik model.
     * @param string $modelKey Kunci model.
     * @return array|null Array berisi 'k_folds' dan 'metrics_per_fold', atau null jika gagal.
     */
    public function loadCrossValidationScores(string $modelKey): ?array
    {
        $cacheKey      = self::CACHE_PREFIX . $modelKey . '_cv_scores';
        $cacheDuration = now()->addHours(6);

        return Cache::remember($cacheKey, $cacheDuration, function () use ($modelKey) {
            $s3CvPath = rtrim(self::MODEL_DIR_S3, '/') . '/' . $modelKey . '_cv_scores.json';
            Log::debug("[Cache Miss] Memuat skor CV dari S3 untuk {$modelKey} dari {$s3CvPath}");

            if (! Storage::disk('s3')->exists($s3CvPath)) {
                Log::warning("File skor CV tidak ditemukan di S3 untuk {$modelKey}", ['s3_path' => $s3CvPath]);
                return null;
            }

            try {
                $content = Storage::disk('s3')->get($s3CvPath);
                if ($content === null) {
                    Log::error("Gagal membaca konten file skor CV dari S3 (null returned)", ['s3_path' => $s3CvPath]);
                    return null;
                }
                if (empty(trim($content))) {
                    Log::warning("File skor CV S3 kosong untuk {$modelKey}.", ['s3_path' => $s3CvPath]);
                    return null;
                }

                $data = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
                    Log::error("Gagal decode JSON skor CV dari S3 untuk {$modelKey}", [
                        's3_path'    => $s3CvPath,
                        'json_error' => json_last_error_msg(),
                    ]);
                    return null;
                }

                // Validasi struktur dasar
                if (! isset($data['k_folds']) || ! isset($data['metrics_per_fold']) || ! is_array($data['metrics_per_fold'])) {
                    Log::warning("Struktur data CV dari S3 tidak valid untuk {$modelKey}", ['s3_path' => $s3CvPath]);
                    return null;
                }

                Log::info("Skor CV berhasil dimuat dari S3 dan di-cache untuk {$modelKey}", ['s3_path' => $s3CvPath]);
                return $data;
            } catch (Throwable $e) {
                Log::error("Error membaca file skor CV dari S3 untuk {$modelKey}", ['s3_path' => $s3CvPath, 'error' => $e->getMessage()]);
                return null;
            }
        });
    }

    /**
     * Memuat hasil evaluasi test set dari file gabungan.
     * @return array<mixed>|null Data test set untuk model spesifik ['metrics'=>..., 'confusion_matrix'=>...]
     */
    public function loadTestResults(string $modelKey): ?array
    {
        $testResultFile = $this->getCombinedTestResultsFilename($modelKey);
        // $testResultPathS3 sekarang adalah path relatif di S3
        $testResultPathS3 = rtrim(self::MODEL_DIR_S3, '/') . '/' . $testResultFile;
        $cacheKey         = self::CACHE_PREFIX . str_replace('.json', '', $testResultFile);
        $cacheDuration    = now()->addHours(6);

        $allResults = Cache::remember($cacheKey, $cacheDuration, function () use ($testResultFile, $testResultPathS3) {
            Log::debug("[Cache Miss] Memuat hasil tes gabungan dari S3: {$testResultPathS3}");

            if (! Storage::disk('s3')->exists($testResultPathS3)) {
                Log::warning("File hasil tes gabungan tidak ditemukan di S3", ['s3_path' => $testResultPathS3]);
                return null;
            }
            try {
                $content = Storage::disk('s3')->get($testResultPathS3);
                if ($content === null) {
                    Log::error("Failed to read combined test results file content", ['path' => $testResultPathS3]);
                    return null;
                }
                if (empty(trim($content))) {
                    Log::warning("Combined test results file is empty.", ['path' => $testResultPathS3]);
                    return null;
                }
                $decoded = json_decode($content, true);
                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                    Log::error("Invalid JSON format or decode error in combined test results file", [
                        'path'  => $testResultPathS3,
                        'error' => json_last_error_msg(),
                    ]);
                    return null;
                }
                Log::info("Berhasil memuat dan decode file hasil tes gabungan dari S3: {$testResultFile}");
                return $decoded;
            } catch (Throwable $e) {
                Log::error("Exception reading/decoding combined test results file", ['path' => $testResultPathS3, 'error' => $e->getMessage()]);
                return null;
            }
        }); // Akhir Cache::remember

        if ($allResults === null) {
            Log::warning("Failed to load or decode combined test results cache/file: {$testResultFile}");
            return null;
        }

        // Ambil data untuk model spesifik
        if (isset($allResults[$modelKey]) && is_array($allResults[$modelKey])) {
            Log::debug("Hasil tes diambil dari cache gabungan S3 untuk {$modelKey}");
            return $allResults[$modelKey];
        } else {
            Log::warning("Test results not found for {$modelKey} within combined file.", ['file' => $testResultFile]);
            return null;
        }
    }

    /** Mendapatkan nama file cache metrik validasi gabungan */
    public function getCombinedMetricsFilename(string $modelKey): string
    {
        return str_ends_with($modelKey, '_classifier') ? 'all_classifier_metrics.json' : 'all_detector_metrics.json';
    }

    /** Mendapatkan nama file cache hasil tes gabungan */
    public function getCombinedTestResultsFilename(string $modelKey): string
    {
        return str_ends_with($modelKey, '_classifier') ? 'all_classifier_test_results.json' : 'all_detector_test_results.json';
    }

    /** Menyimpan data learning curve ke file JSON */
    public function saveLearningCurve(string $modelKey, array $learningCurve): bool
    {
        try {
            // Path di S3, menggunakan $this->self::MODEL_DIR_S3 yang sudah 'models'
            $s3Path      = rtrim(self::MODEL_DIR_S3, '/') . '/' . $modelKey . '_learning_curve.json';
            $jsonContent = json_encode($learningCurve, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($jsonContent === false) {
                Log::error("JSON encode gagal untuk learning curve {$modelKey}", ['json_error' => json_last_error_msg()]);
                return false;
            }
            // Simpan ke S3 dengan visibility 'private'
            if (! Storage::disk('s3')->put($s3Path, $jsonContent, 'private')) {
                Log::error("Gagal menulis learning curve ke S3 untuk {$modelKey}", ['s3_path' => $s3Path]);
                return false;
            }
            Log::info("Data learning curve disimpan ke S3 untuk {$modelKey}", ['s3_path' => $s3Path]);
            // Hapus cache jika ada setelah berhasil menyimpan ke S3
            Cache::forget(self::CACHE_PREFIX . $modelKey . '_learning_curve');
            return true;
        } catch (Throwable $e) {
            Log::error("Error menyimpan learning curve ke S3 untuk {$modelKey}", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Menyimpan hasil cross-validation (struktur lengkap).
     * @param string $modelKey Kunci model (e.g., 'gaussian_nb_detector')
     * @param array $cvResults Array berisi 'k_folds' dan 'metrics_per_fold'
     * @return bool Berhasil atau tidak.
     */
    public function saveCrossValidationScores(string $modelKey, array $cvResults): bool
    {
        // Validasi dasar struktur input sebelum menyimpan
        if (! isset($cvResults['k_folds']) || ! isset($cvResults['metrics_per_fold']) || ! is_array($cvResults['metrics_per_fold'])) {
            Log::error("Invalid CV results structure provided for saving.", ['model_key' => $modelKey]);
            return false;
        }

        try {
            // Path di S3
            $s3Path      = rtrim(self::MODEL_DIR_S3, '/') . '/' . $modelKey . '_cv_scores.json';
            $jsonContent = json_encode($cvResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($jsonContent === false) {
                Log::error("JSON encode failed for CV scores for {$modelKey}", ['json_error' => json_last_error_msg()]);
                return false;
            }

            // Simpan ke S3 dengan visibility 'private'
            if (! Storage::disk('s3')->put($s3Path, $jsonContent, 'private')) {
                Log::error("Gagal menulis skor CV ke S3 untuk {$modelKey}", ['s3_path' => $s3Path]);
                return false;
            }
            Log::info("Skor cross-validation disimpan ke S3 untuk {$modelKey}", ['s3_path' => $s3Path]);
            // Hapus cache jika ada
            Cache::forget(self::CACHE_PREFIX . $modelKey . '_cv_scores');
            return true;
        } catch (Throwable $e) {
            Log::error("Error saving CV scores for {$modelKey}", ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** Menyimpan log history performa model */
    public function logModelPerformance(string $modelName, array $metrics): void
    {
        if (empty($metrics)) {
            Log::warning("Attempted to log empty metrics for model: {$modelName}");
            return;
        }
        $s3HistoryPath = rtrim(self::MODEL_DIR_S3, '/') . '/performance_history.json';
        try {
            $history = [];
            if (Storage::disk('s3')->exists($s3HistoryPath)) {
                $content = Storage::disk('s3')->get($s3HistoryPath);
                if ($content !== null && ! empty(trim($content))) {
                    $decoded = json_decode($content, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        $history = $decoded;
                    } else {
                        Log::warning("Performance history file was corrupted or invalid JSON. Starting fresh.", ['path' => $s3HistoryPath]);
                    }
                }
            }

            // Tambahkan entri baru
            $history[] = [
                'model_name' => $modelName,
                'metrics'    => $metrics,
                'timestamp'  => now()->toDateTimeString(),
            ];
            if (count($history) > 100) {$history = array_slice($history, -100);}

            $jsonContent = json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            // Simpan sebagai private
            if ($jsonContent === false || Storage::disk('s3')->put($s3HistoryPath, $jsonContent, 'private') === false) {
                Log::error("Gagal menulis histori performa ke S3.", ['s3_path' => $s3HistoryPath]);
            } else {
                Log::info("Histori performa berhasil dicatat ke S3 untuk: {$modelName}");
            }
        } catch (Throwable $e) {
            Log::error("Error logging model performance", ['message' => $e->getMessage(), 'model' => $modelName]);
        }
    }

    /** Membersihkan cache terkait evaluasi (metrik gabungan & metadata) */
    public function clearEvaluationCache(): void
    {
        // Hapus cache metrik gabungan
        Cache::forget(self::CACHE_PREFIX . 'all_detector_metrics');
        Cache::forget(self::CACHE_PREFIX . 'all_classifier_metrics');
        // Hapus cache hasil tes gabungan
        Cache::forget(self::CACHE_PREFIX . 'all_detector_test_results');
        Cache::forget(self::CACHE_PREFIX . 'all_classifier_test_results');

        // Hapus cache metadata individual menggunakan BASE_MODEL_KEYS
        foreach (self::BASE_MODEL_KEYS as $baseKey) {
            Cache::forget(self::CACHE_PREFIX . $baseKey . '_classifier_meta');
            Cache::forget(self::CACHE_PREFIX . $baseKey . '_detector_meta');
        }
        Log::info("Evaluation related caches (combined metrics, combined tests, metadata) cleared.");

        // PERHATIAN: Ini TIDAK membersihkan cache model/scaler individual.
        // Itu dibersihkan oleh clearModelCacheByType() di Job atau clearModelCache() di Command.
    }

    /** Export data performa lengkap model untuk analisis eksternal */
    public function exportModelPerformance(string $modelKey): string
    {
        try {
            // Muat semua data terkait model
            $metadata          = $this->loadModelMetadata($modelKey);
            $validationMetrics = $this->loadModelMetrics($modelKey); // Ini sudah mengambil dari file gabungan
            $learningCurve     = $this->loadLearningCurveData($modelKey);
            $cvScores          = $this->loadCrossValidationScores($modelKey); // Mengambil data lengkap CV
            $testResults       = $this->loadTestResults($modelKey);           // Mengambil data lengkap tes

            // Susun data untuk ekspor
            $performance = [
                'model_key'             => $modelKey,
                'metadata'              => $metadata,
                'validation_data'       => $validationMetrics, // Ganti nama key agar jelas ini validasi
                'learning_curve_data'   => $learningCurve,
                'cross_validation_data' => $cvScores,    // Sertakan data CV lengkap
                'test_set_data'         => $testResults, // Sertakan data tes lengkap
                'exported_at'           => now()->toIso8601String(),
            ];

            // Encode ke JSON
            $jsonOutput = json_encode($performance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($jsonOutput === false) {
                throw new RuntimeException("JSON encode failed during performance export for {$modelKey}. Error: " . json_last_error_msg());
            }
            return $jsonOutput;
        } catch (Throwable $e) {
            Log::error("Error exporting performance for {$modelKey}", ['error' => $e->getMessage()]);
            // Kembalikan JSON error jika gagal
            return json_encode(['error' => "Failed to export performance data for {$modelKey}.", 'message' => $e->getMessage()]);
        }
    }
} // Akhir Class ModelService
