<?php

// File: app/Console/Commands/TrainMelonDetectorModel.php

namespace App\Console\Commands;

use App\Console\Commands\Traits\Versionable;
use App\Persisters\S3ObjectPersister;
use App\Services\EvaluationService;
use App\Services\FeatureExtractionService;
use App\Services\ModelService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str; // Tambahkan ini di atas kelas command
use InvalidArgumentException;
use ReflectionClass;
use Rubix\ML\Classifiers\AdaBoost;
use Rubix\ML\Classifiers\ClassificationTree;
use Rubix\ML\Classifiers\GaussianNB;
use Rubix\ML\Classifiers\KNearestNeighbors;
use Rubix\ML\Classifiers\LogisticRegression;
use Rubix\ML\Classifiers\LogitBoost;
use Rubix\ML\Classifiers\MultilayerPerceptron;
use Rubix\ML\Classifiers\RandomForest;
use Rubix\ML\CrossValidation\Metrics\Accuracy;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Estimator;
use Rubix\ML\Learner;
use Rubix\ML\NeuralNet\ActivationFunctions\ReLU;
use Rubix\ML\NeuralNet\Layers\Activation;
use Rubix\ML\NeuralNet\Layers\Dense;
use Rubix\ML\NeuralNet\Layers\Dropout;
use Rubix\ML\NeuralNet\Optimizers\Adam;
use Rubix\ML\Regressors\RegressionTree;
use Rubix\ML\Transformers\ZScaleStandardizer;
use SplFileObject;
use Throwable;

class TrainMelonDetectorModel extends Command
{
    protected const MAX_IMBALANCE_RATIO = 2.0;

    use Versionable;

    protected $signature   = 'train:melon-detector {--with-test : Sertakan evaluasi pada set tes} {--with-cv : Sertakan k-fold cross validation}';
    protected $description = 'Melatih SEMUA model deteksi melon dengan scaler spesifik per model.';

    public function __construct(
        protected EvaluationService $evaluator,
        protected ModelService $modelService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        ini_set('memory_limit', '2048M');
        mt_srand(12345);
        $this->line("EVENT_STATUS: START, Memulai Pelatihan Model Detektor...");
        $this->info('🚀 Memulai Pelatihan Model Deteksi Melon (Semua Algoritma)');

        $this->info('[1/4] Memuat fitur training...');
        $this->line("EVENT_LOG: [1/4] Memuat fitur training...");
        $s3TrainFeaturePath                      = FeatureExtractionService::S3_FEATURE_DIR . '/train_detector_features.csv';
        [$trainSamples, $trainLabels, $trainIds] = $this->loadFeaturesFromCsv($s3TrainFeaturePath);

        if (empty($trainSamples)) {
            $this->error('❌ File fitur training (train_detector_features.csv) kosong/tidak valid!');
            $this->line("EVENT_STATUS: ERROR, File fitur training detektor kosong/tidak valid.");
            return self::FAILURE;
        }
        if (count(array_unique($trainLabels)) < 2) {
            $this->error('❌ Dataset training harus memiliki minimal dua kelas (melon/non_melon)!');
            $this->line("EVENT_STATUS: ERROR, Dataset training detektor tidak memiliki dua kelas.");
            return self::FAILURE;
        }

        $initialCounts = array_count_values($trainLabels);
        $melonCount    = $initialCounts['melon'] ?? 0;
        $nonMelonCount = $initialCounts['non_melon'] ?? 0;
        $this->info("    Memuat " . count($trainSamples) . " sampel training (Melon: {$melonCount}, Non-Melon: {$nonMelonCount}).");
        $this->line("EVENT_LOG:    Memuat " . count($trainSamples) . " sampel training (Melon: {$melonCount}, Non-Melon: {$nonMelonCount}).");
        $originalTrainDataset = Labeled::build($trainSamples, $trainLabels);

        $this->info('[2/4] Menerapkan undersampling jika diperlukan...');
        $this->line("EVENT_LOG: [2/4] Menerapkan undersampling...");
        $trainDatasetForTraining = clone $originalTrainDataset;
        $finalCounts             = $initialCounts;

        if ($melonCount > 0 && $nonMelonCount > ($melonCount * self::MAX_IMBALANCE_RATIO)) {
            $targetNonMelonCount = max(1, (int) floor($melonCount * self::MAX_IMBALANCE_RATIO));
            $this->info("    Undersampling sampel non-melon dari {$nonMelonCount} menjadi {$targetNonMelonCount}.");
            $melonIndices    = array_keys($trainLabels, 'melon');
            $nonMelonIndices = array_keys($trainLabels, 'non_melon');
            shuffle($nonMelonIndices);
            $nonMelonIndicesToKeep = array_slice($nonMelonIndices, 0, $targetNonMelonCount);
            $finalIndices          = array_merge($melonIndices, $nonMelonIndicesToKeep);
            shuffle($finalIndices);
            $finalSamples            = array_map(fn($i) => $trainSamples[$i], $finalIndices);
            $finalLabels             = array_map(fn($i) => $trainLabels[$i], $finalIndices);
            $trainDatasetForTraining = Labeled::build($finalSamples, $finalLabels);
            $finalCounts             = array_count_values($finalLabels);
            $this->info("    Jumlah setelah undersampling: Melon=" . ($finalCounts['melon'] ?? 0) . ", Non-Melon=" . ($finalCounts['non_melon'] ?? 0));
            $this->line("EVENT_LOG:    Jumlah setelah undersampling: Melon=" . ($finalCounts['melon'] ?? 0) . ", Non-Melon=" . ($finalCounts['non_melon'] ?? 0));
        } else {
            $this->info("    Keseimbangan data diterima atau tidak ada sampel melon. Undersampling dilewati.");
            $this->line("EVENT_LOG:    Undersampling dilewati.");
        }
        unset($trainSamples, $trainLabels, $trainIds);

        if ($trainDatasetForTraining->numSamples() === 0) {
            $this->error('❌ Tidak ada sampel tersisa setelah undersampling!');
            return self::FAILURE;
        }
        if (count(array_unique($trainDatasetForTraining->labels())) < 2) {
            $this->error("❌ Dataset harus mengandung kedua kelas ('melon', 'non_melon') setelah undersampling!");
            return self::FAILURE;
        }

        $this->info('[3/4] Menginisialisasi, melatih, dan menyimpan semua model deteksi...');
        $this->line("EVENT_LOG: [3/4] Menginisialisasi, melatih, dan menyimpan model detektor...");
        $detectors               = $this->getAllDetectors();
        $trainingSuccessfulCount = 0;
        $allValidationMetrics    = [];
        $totalModelsToTrain      = count($detectors);
        $trainedModelCount       = 0;

        foreach ($detectors as $key => $detector) {
            if (! $detector instanceof Learner) {
                $this->error("      ❌ Item '{$key}' bukan instance Learner.");
                continue;
            }
            $name      = ucwords(str_replace('_', ' ', $key));
            $modelKey  = $key . '_detector';
            $scalerKey = $modelKey . '_scaler';
            $this->line("    - Melatih {$name}...");
            $this->line("EVENT_LOG: Melatih {$name} (Detektor)...");

            $modelStartTime = microtime(true);
            try {
                $this->line("      - Menyiapkan scaler spesifik untuk {$name} (fit on original data)...");
                $modelScaler = new ZScaleStandardizer();
                $modelScaler->fit($originalTrainDataset);

                $modelTrainDataset = clone $trainDatasetForTraining;
                $modelTrainDataset->apply($modelScaler);
                $this->info("      - Penskalaan spesifik diterapkan pada data training (undersampled).");

                $detector->train($modelTrainDataset);
                $modelDuration = round(microtime(true) - $modelStartTime, 2);
                $this->info("      ✅ Pelatihan {$name} selesai ({$modelDuration} detik).");
                $this->line("EVENT_LOG: ✅ Pelatihan {$name} (Detektor) selesai.");

                $trainPredictions = $detector->predict($modelTrainDataset);
                $trainingAccuracy = (new Accuracy())->score($trainPredictions, $modelTrainDataset->labels());
                $this->line("          > Akurasi Training (on processed data): " . number_format($trainingAccuracy * 100, 1) . "%");

                $s3ModelPath = ModelService::MODEL_DIR_S3 . "/{$modelKey}.model"; // Path S3, misal 'models/nama_model.model'
                try {
                    /** @var Estimator $detectorToSave */
                    $detectorToSave = $detector;
                    (new S3ObjectPersister($s3ModelPath))->save($detectorToSave, 'private'); // Simpan private
                    $this->info("      Model disimpan ke S3: {$s3ModelPath}");
                } catch (Throwable $e) {
                    Log::error("Gagal menyimpan model ke S3: {$s3ModelPath}", ['error' => $e->getMessage()]);
                    $this->error("      ❌ Gagal menyimpan model ke S3.");
                    // Lanjutkan atau hentikan? Tergantung kebutuhan Anda.
                }

                $this->saveModelSpecificScaler($scalerKey, $modelScaler);

                $s3MetaPath = ModelService::MODEL_DIR_S3 . "/{$modelKey}_meta.json"; // Path S3 untuk menyimpan metadata

                // Pemanggilan ini sekarang seharusnya sudah benar (8 argumen)
                $metadata = $this->createMetadata(
                    $key,
                    $detector,
                    $modelTrainDataset->numSamples(),
                    $modelScaler,
                    $initialCounts,
                    $finalCounts,
                    $trainingAccuracy,
                    $s3MetaPath // Path S3 untuk versioning
                );

                                                                                                                         // Menyimpan metadata ke S3 (yang ini sudah Anda koreksi menjadi Storage::disk('s3')->put)
                $jsonContent = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); // Tambah JSON_THROW_ON_ERROR
                if (! Storage::disk('s3')->put($s3MetaPath, $jsonContent, 'private')) {                                   // Periksa return value dari put()
                    Log::error("Gagal menyimpan file metadata detector ke S3.", ['s3_path' => $s3MetaPath]);
                    $this->error("      ❌ Gagal menyimpan metadata ke S3.");
                } else {
                    $this->info("      Metadata disimpan ke S3: {$s3MetaPath}");
                }

                $this->info("      Memvalidasi {$name} pada set validasi...");
                $valMetrics = $this->validateSingleModel($key, $detector, $modelScaler);
                if ($valMetrics) {
                    $valMetrics['algorithm_class']   = get_class($detector);
                    $allValidationMetrics[$modelKey] = $valMetrics;
                    $this->info("      Metrik validasi {$name} berhasil dihitung."); // Tambahkan info
                    $this->line("          > Akurasi Validasi: " . round(($valMetrics['metrics']['accuracy'] ?? 0.0) * 100, 1) . "%");

                    // TAMBAHKAN BAGIAN INI UNTUK MENYIMPAN LEARNING CURVE
                    if (isset($valMetrics['learning_curve_data']) && ! empty($valMetrics['learning_curve_data']['train_sizes'])) {
                        if ($this->modelService->saveLearningCurve($modelKey, $valMetrics['learning_curve_data'])) {
                            $this->info("      Learning curve data untuk {$name} disimpan.");
                            $this->line("EVENT_LOG: Learning curve untuk {$name} (Detektor) disimpan.");
                        } else {
                            $this->error("      ❌ Gagal menyimpan learning curve data untuk {$name}.");
                        }
                    } else {
                        $this->warn("      ⚠️ Data learning curve tidak valid atau kosong untuk {$name}, tidak disimpan.");
                    }
                } else {
                    $allValidationMetrics[$modelKey] = null;
                    $this->warn("      ⚠️ Gagal menghitung metrik validasi untuk {$key}.");
                }

                if ($this->option('with-cv')) {
                    $this->info("      Melakukan cross validation pada {$name} (Detektor)...");
                    $this->line("EVENT_LOG: CV untuk {$name} (Detektor) dimulai...");

                                                              // PENTING: Putuskan dataset mana yang akan digunakan untuk CV.
                                                              // Jika model utama dilatih pada data yang sudah di-undersampling ($trainDatasetForTraining),
                                                              // maka CV sebaiknya juga dilakukan pada data tersebut untuk evaluasi yang paling relevan.
                                                              // Method performCrossValidation akan melakukan scaling per-fold sendiri.
                    $datasetForCV = $trainDatasetForTraining; // Gunakan dataset yang sudah di-undersampling (jika ada)

                    $cvResults = $this->performCrossValidation($detector, $datasetForCV); // Hapus $modelScaler jika tidak digunakan lagi di sana

                    if (! empty($cvResults['metrics_per_fold']) && ! empty($cvResults['metrics_per_fold']['accuracy'])) { // Pastikan ada data akurasi
                        if ($this->modelService->saveCrossValidationScores($modelKey, $cvResults)) {
                            $this->info("      Hasil cross validation detektor disimpan.");
                            // Anda bisa menambahkan kembali displayCrossValidationResultsConsole jika diperlukan
                        } else {
                            $this->error("      ❌ Gagal menyimpan hasil cross validation untuk detektor {$name}.");
                        }
                    } else {
                        $this->warn("      ⚠️ Hasil cross validation detektor tidak valid atau kosong untuk {$name}. Tidak disimpan.");
                    }
                    $this->line("EVENT_LOG: CV untuk {$name} (Detektor) selesai.");
                }

                $trainingSuccessfulCount++;        // Ini mungkin sudah ada, pastikan tidak duplikat
                $this->clearModelCache($modelKey); // Ini juga mungkin sudah ada

                $trainedModelCount++;
                $progressPercentage = ($totalModelsToTrain > 0) ? round(($trainedModelCount / $totalModelsToTrain) * 100) : 0;
                $this->line("PROGRESS_UPDATE: {$progressPercentage}% (Selesai Detektor {$modelKey} - {$trainedModelCount}/{$totalModelsToTrain})");

            } catch (Throwable $e) {
                $this->error("      ❌ Error pada {$name}: " . $e->getMessage());
                $this->line("EVENT_LOG: ERROR training Detektor {$modelKey}: " . Str::limit($e->getMessage(), 70));
                Log::error("Training/Saving/Validation Exception for {$modelKey}", ['exception_message' => $e->getMessage(), 'trace_snippet' => Str::limit($e->getTraceAsString(), 500)]);
            }
            unset($detector, $modelTrainDataset, $modelScaler);
        }

        unset($originalTrainDataset, $trainDatasetForTraining);

        if ($trainingSuccessfulCount === 0) {
            $this->error('❌ Tidak ada model deteksi yang berhasil dilatih!');
            $this->line("EVENT_STATUS: ERROR, Tidak ada model detektor yang berhasil dilatih.");
            return self::FAILURE;
        }
        $this->info("    {$trainingSuccessfulCount} model deteksi berhasil dilatih dan divalidasi.");
        $this->line("EVENT_LOG: {$trainingSuccessfulCount} model detektor berhasil dilatih.");

        $s3CachePath = ModelService::MODEL_DIR_S3 . "/all_detector_metrics.json"; // Path S3
        try {
            $jsonContent = json_encode($allValidationMetrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            // Simpan private ke S3
            if (Storage::disk('s3')->put($s3CachePath, $jsonContent, 'private')) {
                $this->info("    Cache metrik validasi gabungan disimpan ke S3: {$s3CachePath}");
                Cache::forget(ModelService::CACHE_PREFIX . 'all_detector_metrics'); // Pembersihan cache Laravel tetap relevan
            } else {
                Log::error("Gagal menyimpan cache metrik validasi detector gabungan.", ['path' => $s3CachePath]);
                $this->error("    ❌ Gagal menyimpan cache metrik validasi gabungan.");
            }
        } catch (\JsonException $e) {
            Log::critical("Gagal encode JSON metrik validasi detector.", ['error' => $e->getMessage()]);
            $this->error("    ❌ Error saat memformat data metrik validasi (JSON Encode).");
        } catch (Throwable $e) {
            Log::error("Gagal menyimpan cache metrik validasi detector gabungan.", ['path' => $s3CachePath, 'error' => $e->getMessage()]);
            $this->error("    ❌ Gagal menyimpan cache metrik validasi gabungan: " . $e->getMessage());
        }

        if ($this->option('with-test')) {
            $this->info('[4/4] Mengevaluasi semua model pada set tes...');
            $testResults = $this->evaluateOnTestSet();
            if (! empty($testResults)) {
                $this->saveTestResults($testResults);
            } else {
                $this->warn("    Tidak ada hasil tes untuk disimpan.");
            }
        } else {
            $this->info('[4/4] Melewati evaluasi set tes (gunakan flag --with-test untuk mengaktifkan).');
        }

        $this->info('✅ Pelatihan dan Evaluasi Model Deteksi Selesai!');
        $this->line("EVENT_STATUS: DONE, Pelatihan Detektor Selesai!");
        return self::SUCCESS;
    }

    private function getAllDetectors(): array
    {
        $this->line("    Menginisialisasi algoritma deteksi (klasifikasi biner)...");
        return [
            'gaussian_nb'           => new GaussianNB(),
            'classification_tree'   => new ClassificationTree(8, 3, 0.002, FeatureExtractionService::DETECTOR_FEATURE_COUNT),
            'logistic_regression'   => new LogisticRegression(64, new Adam(0.001), 1e-3, 150, 1e-5),
            'ada_boost'             => new AdaBoost(new ClassificationTree(2), 0.05, 0.8, 150),
            'k_nearest_neighbors'   => new KNearestNeighbors(5, true),
            'logit_boost'           => new LogitBoost(new RegressionTree(2), 0.05, 0.5, 150),
            'multilayer_perceptron' => new MultilayerPerceptron(
                [
                    new Dense(32),
                    new Activation(new ReLU()),
                    new Dropout(0.2),
                    new Dense(16),
                    new Activation(new ReLU()),
                    new Dropout(0.2),
                    new Dense(8),
                    new Activation(new ReLU()),
                ],
                64,
                new Adam(0.001),
                1e-3,
                150,
                1e-5
            ),
            'random_forest'         => new RandomForest(
                new ClassificationTree(8, 3, 0.002, 4),
                200,
                0.05,
                true
            ),
        ];
    }

    private function loadFeaturesFromCsv(string $s3FeatureCsvPath): array
    {
        $samples          = [];
        $labels           = [];
        $ids              = [];
        $localTempCsvPath = null;
        if (! Storage::disk('s3')->exists($s3FeatureCsvPath)) {
            $this->warn("      File fitur tidak ditemukan di S3: {$s3FeatureCsvPath}");
            return [[], [], []];
        }

        try {
            $csvS3Content = Storage::disk('s3')->get($s3FeatureCsvPath);
            if ($csvS3Content === null) {
                $this->error("      Gagal membaca konten file fitur dari S3: {$s3FeatureCsvPath}");
                return [[], [], []];
            }
            if (empty(trim($csvS3Content))) {
                $this->warn("      File fitur S3 kosong: {$s3FeatureCsvPath}");
                return [[], [], []];
            }

            $localTempCsvPath = tempnam(sys_get_temp_dir(), "s3_cmd_feat_csv_");
            if ($localTempCsvPath === false || file_put_contents($localTempCsvPath, $csvS3Content) === false) {
                $this->error("      Gagal membuat/menulis file fitur temporary lokal dari S3: {$s3FeatureCsvPath}");
                if ($localTempCsvPath && file_exists($localTempCsvPath)) {
                    @unlink($localTempCsvPath);
                }

                return [[], [], []];
            }

            $fileHandle = new SplFileObject($localTempCsvPath, 'r'); // Baca dari temp lokal

            $fileHandle->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD);
            $fileHandle->setCsvControl(',');
            $header = $fileHandle->fgetcsv();

            if (! $header || count($header) < 3 || strtolower(trim($header[0])) !== 'filename' || strtolower(trim($header[1])) !== 'label') {
                throw new InvalidArgumentException("Header CSV fitur detektor tidak valid. Ditemukan: " . implode(',', $header) . " di {$s3FeatureCsvPath}");
            }
            $expectedFeatureCountInHeader = count($header) - 2;
            if ($expectedFeatureCountInHeader !== FeatureExtractionService::DETECTOR_FEATURE_COUNT) { // <--- **BENAR JIKA SEPERTI INI**
                $this->warn("      ⚠️ Peringatan: Jumlah fitur di header detektor ({$expectedFeatureCountInHeader}) tidak sesuai harapan (" . FeatureExtractionService::DETECTOR_FEATURE_COUNT . "). File: {$s3FeatureCsvPath}");
                return [[], [], []];
            }
            if ($fileHandle->valid()) {
                $fileHandle->next();
            }
            // Lewati header jika valid

            while (! $fileHandle->eof() && ($row = $fileHandle->fgetcsv())) {
                if (! is_array($row) || count($row) !== count($header)) {
                    Log::warning("Baris CSV detektor tidak valid.", ['row_count' => is_array($row) ? count($row) : 'not_array', 'header_count' => count($header), 'path' => $s3FeatureCsvPath]);
                    continue;
                }
                $id            = trim($row[0]);
                $label         = strtolower(trim($row[1]));
                $features      = array_slice($row, 2, FeatureExtractionService::DETECTOR_FEATURE_COUNT);
                $floatFeatures = array_map('floatval', $features);

                if (count($floatFeatures) === FeatureExtractionService::DETECTOR_FEATURE_COUNT && // <--- **BENAR JIKA SEPERTI INI**
                    in_array($label, FeatureExtractionService::DETECTOR_VALID_LABELS) &&              // <--- Pastikan DETECTOR_VALID_LABELS ada di FeatureExtractionService
                    ! empty($id)) {$samples[] = $floatFeatures;
                    $labels[]                             = $label;
                    $ids[]                                = $id;} else {
                    Log::warning("Data sampel detektor tidak valid dilewati.", ['id' => $id, 'label' => $label, 'num_features_read' => count($features), 'path' => $s3FeatureCsvPath]);
                }
            }
            $fileHandle = null;
        } catch (Throwable $e) {
            $this->error("      Error membaca CSV fitur dari S3 {$s3FeatureCsvPath}: " . $e->getMessage());
            Log::error("Error reading feature CSV from S3", ['s3_path' => $s3FeatureCsvPath, 'error' => $e->getMessage()]);
            if (isset($fileHandle)) {
                $fileHandle = null;
            }

            return [[], [], []];
        } finally {
            if ($localTempCsvPath && file_exists($localTempCsvPath)) {
                unlink($localTempCsvPath);
            }
        }

        if (empty($samples)) {
            $this->warn("      ⚠️ Tidak ada sampel valid yang dimuat dari {$s3FeatureCsvPath}.");
        }

        return [$samples, $labels, $ids];
    }

    private function createMetadata(
        string $key,
        Learner $model,
        int $trainingSamplesCount,
        ZScaleStandardizer $scaler,
        array $initialCounts,            // Argumen ke-5
        array $finalCounts,              // Argumen ke-6
        ?float $trainingAccuracy = null, // Argumen ke-7
        string $s3MetaPathForVersion     // Argumen ke-8 (path S3 untuk versioning)
    ): array {
        $modelIdentifier = $key . '_detector';
        $metadata        = [
            'model_key'                               => $modelIdentifier,
            'task_type'                               => 'detector',
            // Gunakan $s3MetaPathForVersion yang di-pass
            'version'                                 => $this->getNextModelVersion($s3MetaPathForVersion),
            'trained_at'                              => now()->toIso8601String(),
            'initial_training_samples_distribution'   => $initialCounts,
            'final_training_samples_distribution'     => $finalCounts,
            'training_samples_count_after_processing' => $trainingSamplesCount,
            'num_features_expected'                   => FeatureExtractionService::DETECTOR_FEATURE_COUNT,
            'feature_names'                           => FeatureExtractionService::DETECTOR_FEATURE_NAMES,
            'scaler_used_class'                       => get_class($scaler),
            'algorithm_class'                         => get_class($model),
            'hyperparameters'                         => $this->hyperParametersOf($model),
            'undersampling_details'                   => [
                'applied'             => ($initialCounts['non_melon'] ?? 0) > (($initialCounts['melon'] ?? 0) * self::MAX_IMBALANCE_RATIO) && ($initialCounts['melon'] ?? 0) > 0,
                'max_imbalance_ratio' => self::MAX_IMBALANCE_RATIO,
            ],
            'rubix_ml_version'                        => \Rubix\ML\VERSION,
        ];
        if ($trainingAccuracy !== null) {
            $metadata['training_accuracy_on_processed_data'] = round($trainingAccuracy, 4);
        }
        return $metadata;
    }

    private function hyperParametersOf(Learner | Estimator $estimator): array
    {
        $rc     = new ReflectionClass($estimator);
        $ctor   = $rc->getConstructor();
        $params = [];
        if ($ctor) {
            foreach ($ctor->getParameters() as $arg) {
                $name = $arg->getName();
                if ($rc->hasProperty($name)) {
                    $prop = $rc->getProperty($name);
                    if (! $prop->isPublic()) {
                        $prop->setAccessible(true);
                    }

                    $value         = $prop->getValue($estimator);
                    $params[$name] = is_object($value) ? get_class($value) : $value;
                }
            }
        }
        return $params;
    }

    private function saveModelSpecificScaler(string $scalerKey, ZScaleStandardizer $scaler): bool
    {
        try {
            $s3ScalerPath                                  = ModelService::MODEL_DIR_S3 . "/{$scalerKey}.phpdata";
            /** @var Estimator $modelScaler */$modelScaler = $scaler;
            (new S3ObjectPersister($s3ScalerPath))->save($modelScaler, 'private');
            $this->info("      Scaler spesifik model disimpan ke: {$s3ScalerPath}");
            return true;
        } catch (Throwable $e) {
            $this->error("      Error saat menyimpan scaler spesifik model {$scalerKey}: " . $e->getMessage());
            Log::error("Model-specific Scaler Save Exception", ['scaler_key' => $scalerKey, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function validateSingleModel(string $key, Learner $model, ZScaleStandardizer $modelScaler): ?array
    {
        $modelKey                                = $key . '_detector';
        $featureFile                             = 'valid_detector_features.csv';
        $s3ValidFeaturePath                      = FeatureExtractionService::S3_FEATURE_DIR . '/valid_detector_features.csv'; // Path S3
        [$validSamples, $validLabels, $validIds] = $this->loadFeaturesFromCsv($s3ValidFeaturePath);

// Log yang sudah ada dari saya (atau yang Anda tambahkan) SANGAT PENTING di sini untuk melihat count
        Log::debug("[TrainMelonDetectorModel] validateSingleModel - Data validasi diterima SETELAH loadFeaturesFromCsv:", [
            'model_key'                    => $key, // $key adalah argumen pertama fungsi ini
            's3_path_valid_features'       => $s3ValidFeaturePath,
            'valid_samples_count_CHECK'    => count($validSamples), // Ini akan 110 jika loadFeaturesFromCsv benar
            'valid_labels_count_CHECK'     => count($validLabels),  // Ini akan 110
            'is_empty_valid_samples_check' => empty($validSamples), // Ini seharusnya false
            'is_empty_valid_labels_check'  => empty($validLabels),  // Ini seharusnya false
        ]);

// GANTI $valSamples menjadi $validSamples
        if (empty($validSamples) || empty($validLabels)) { // <<< PERBAIKI TYPO INI
            $this->warn("      File fitur validasi ({$featureFile}) kosong atau gagal parse. Validasi dilewati.");
            return null;
        }
        if (count(array_unique($validLabels)) < 2) {
            $this->warn("      Dataset validasi {$modelKey} tidak punya 2 kelas.");
        }

        try {
            $originalValDataset   = Labeled::build($validSamples, $validLabels);
            $valDatasetForPredict = clone $originalValDataset;
            $valDatasetForPredict->apply($modelScaler);
            $predictions   = $model->predict($valDatasetForPredict);
            $learningCurve = $this->generateLearningCurve($model, $originalValDataset, $modelScaler);

            $posKey                             = 'melon';
            $negKey                             = 'non_melon';
            [$precisionPos, $recallPos, $f1Pos] = $this->evaluator->calculateMetrics($validLabels, $predictions, $posKey);
            [$precisionNeg, $recallNeg, $f1Neg] = $this->evaluator->calculateMetrics($validLabels, $predictions, $negKey);
            $accuracy                           = (new Accuracy())->score($predictions, $validLabels);
            $matrix                             = $this->evaluator->confusionMatrix($validLabels, $predictions, $posKey);
            $supportPos                         = ($matrix[0][0] ?? 0) + ($matrix[0][1] ?? 0);
            $supportNeg                         = ($matrix[1][0] ?? 0) + ($matrix[1][1] ?? 0);

            return [
                'model_key'                => $modelKey, // Ini sudah ada
                'validation_samples_count' => count($validSamples),
                'metrics'                  => [
                    'accuracy' => round($accuracy, 4),
                    $posKey    => ['precision' => round($precisionPos, 4), 'recall' => round($recallPos, 4), 'f1_score' => round($f1Pos, 4), 'support' => $supportPos, 'label' => $posKey],
                    $negKey    => ['precision' => round($precisionNeg, 4), 'recall' => round($recallNeg, 4), 'f1_score' => round($f1Neg, 4), 'support' => $supportNeg, 'label' => $negKey],
                ],
                'confusion_matrix'         => $matrix,
                'learning_curve_data'      => $learningCurve,
            ];
        } catch (Throwable $e) {
            $this->error("      ❌ Error validasi {$modelKey}: " . $e->getMessage());
            Log::error("Validation Exception {$modelKey}", ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function generateLearningCurve(Learner $model, Labeled $originalUnscaledDataset, ZScaleStandardizer $modelScaler): array
    {
        $numSamples = $originalUnscaledDataset->numSamples();
        if ($numSamples < 10) {
            Log::info("LC: Not enough samples ({$numSamples}) for {$model->type()}. Skipping LC.");
            return ['train_sizes' => [], 'train_scores' => [], 'test_scores' => []];
        }
        $trainSizesRatios = [0.2, 0.4, 0.6, 0.8, 1.0];
        $trainScores      = [];
        $testScores       = [];
        $actualTrainSizes = [];
        $allSamples       = $originalUnscaledDataset->samples();
        $allLabels        = $originalUnscaledDataset->labels();
        $indices          = range(0, $numSamples - 1);

        foreach ($trainSizesRatios as $ratio) {
            shuffle($indices);
            $currentTrainSize = max(5, (int) floor($numSamples * $ratio));
            if ($ratio == 1.0) {
                $currentTrainSize = $numSamples > 1 ? $numSamples - max(1, (int) floor($numSamples * 0.1)) : 1;
                if ($currentTrainSize < 5 && $numSamples > 5) {
                    $currentTrainSize = $numSamples - 1;
                } else if ($currentTrainSize < 5) {
                    $currentTrainSize = $numSamples;
                }

            }
            if ($currentTrainSize >= $numSamples && $numSamples > 1) {
                $currentTrainSize = $numSamples - 1;
            }

            if ($currentTrainSize <= 0) {
                continue;
            }

            $currentTestSize = $numSamples - $currentTrainSize;
            if ($currentTestSize < 1 && $ratio < 1.0) {
                $currentTrainSize = $numSamples > 1 ? $numSamples - 1 : 1;
                $currentTestSize  = $numSamples - $currentTrainSize;
            }
            if ($currentTrainSize < 1 || ($currentTestSize < 1 && $ratio < 1.0)) {
                Log::warning("LC: Skipping ratio {$ratio} for {$model->type()}.");
                continue;
            }
            $actualTrainSizes[] = $currentTrainSize;
            $trainIndices       = array_slice($indices, 0, $currentTrainSize);
            $testIndices        = array_slice($indices, $currentTrainSize, $currentTestSize);
            if (empty($trainIndices) || (empty($testIndices) && $ratio < 1.0)) {
                Log::warning("LC: Empty indices ratio {$ratio} for {$model->type()}.");
                continue;
            }
            $trainSamplesSubset = array_map(fn($i) => $allSamples[$i], $trainIndices);
            $trainLabelsSubset  = array_map(fn($i) => $allLabels[$i], $trainIndices);
            $testSamplesSubset  = ! empty($testIndices) ? array_map(fn($i) => $allSamples[$i], $testIndices) : [];
            $testLabelsSubset   = ! empty($testIndices) ? array_map(fn($i) => $allLabels[$i], $testIndices) : [];

            try {
                $trainDatasetSubset = Labeled::build($trainSamplesSubset, $trainLabelsSubset);
                if (count(array_unique($trainDatasetSubset->labels())) < 2 && $trainDatasetSubset->numSamples() > 0) {
                    Log::info("LC: Train subset ratio {$ratio} one class for {$model->type()}. Skip.");
                    $trainScores[] = null;
                    $testScores[]  = null;
                    continue;
                }
                $tempModel    = clone $model;
                $subsetScaler = new ZScaleStandardizer();
                $subsetScaler->fit($trainDatasetSubset);
                $scaledTrainSubset = clone $trainDatasetSubset;
                $scaledTrainSubset->apply($subsetScaler);
                $tempModel->train($scaledTrainSubset);
                $trainPreds    = $tempModel->predict($scaledTrainSubset);
                $trainScores[] = (new Accuracy())->score($trainPreds, $scaledTrainSubset->labels());

                if (! empty($testSamplesSubset)) {
                    $testDatasetSubset = Labeled::build($testSamplesSubset, $testLabelsSubset);
                    if (count(array_unique($testDatasetSubset->labels())) < 1 && $testDatasetSubset->numSamples() > 0) {
                        Log::info("LC: Test subset ratio {$ratio} no/one class for {$model->type()}.");
                    }
                    $scaledTestSubset = clone $testDatasetSubset;
                    $scaledTestSubset->apply($subsetScaler);
                    $testPreds    = $tempModel->predict($scaledTestSubset);
                    $testScores[] = (new Accuracy())->score($testPreds, $scaledTestSubset->labels());
                } else {
                    $testScores[] = null;
                }
            } catch (Throwable $e) {
                Log::error("LC: Error ratio {$ratio} with {$model->type()}", ['error' => $e->getMessage()]);
                $trainScores[] = null;
                $testScores[]  = null;
            }
            unset($tempModel, $subsetScaler, $trainDatasetSubset, $scaledTrainSubset, $testDatasetSubset, $scaledTestSubset);
        }
        $validTrainScores = [];
        $validTestScores  = [];
        $validTrainSizes  = [];
        for ($i = 0; $i < count($trainScores); $i++) {
            if ($trainScores[$i] !== null && ($testScores[$i] !== null || $trainSizesRatios[$i] == 1.0)) {
                $validTrainScores[] = $trainScores[$i];
                $validTestScores[]  = $testScores[$i];
                $validTrainSizes[]  = $actualTrainSizes[$i];
            }
        }
        return ['train_sizes' => $validTrainSizes, 'train_scores' => $validTrainScores, 'test_scores' => $validTestScores];
    }

    private function evaluateOnTestSet(): array
    {
        $s3TestFeaturePath          = FeatureExtractionService::S3_FEATURE_DIR . '/test_detector_features.csv'; // After
        [$testSamples, $testLabels] = $this->loadFeaturesFromCsv($s3TestFeaturePath);
        $allTestResults             = [];
        if (empty($testSamples)) {
            $this->warn("    File fitur tes detector kosong.");
            return $allTestResults;
        }
        $this->info("    Memuat " . count($testSamples) . " sampel tes.");
        $originalTestDataset = Labeled::build($testSamples, $testLabels);
        unset($testSamples);
        $detectorKeys = array_keys($this->getAllDetectors());

        foreach ($detectorKeys as $key) {
            $name      = ucwords(str_replace('_', ' ', $key));
            $modelKey  = $key . '_detector';
            $scalerKey = $modelKey . '_scaler';
            $this->line("\n      📊 Hasil Tes untuk {$name}:");
            try {
                /** @var Learner|null $model */$model = $this->modelService->loadModel($modelKey);
                if (! ($model instanceof Learner)) {
                    $this->error("      ❌ Gagal load model {$modelKey}.");
                    continue;
                }
                /** @var ZScaleStandardizer|null $modelScaler */$modelScaler = $this->modelService->loadModel($scalerKey);
                if (! ($modelScaler instanceof ZScaleStandardizer)) {
                    $this->error("      ❌ Gagal load scaler {$scalerKey}.");
                    continue;
                }

                $testDatasetForPredict = clone $originalTestDataset;
                $testDatasetForPredict->apply($modelScaler);
                $predictions                        = $model->predict($testDatasetForPredict);
                $posKey                             = 'melon';
                $negKey                             = 'non_melon';
                [$precisionPos, $recallPos, $f1Pos] = $this->evaluator->calculateMetrics($testLabels, $predictions, $posKey);
                [$precisionNeg, $recallNeg, $f1Neg] = $this->evaluator->calculateMetrics($testLabels, $predictions, $negKey);
                $accuracy                           = (new Accuracy())->score($predictions, $testLabels);
                $matrix                             = $this->evaluator->confusionMatrix($testLabels, $predictions, $posKey);
                $supportPosTest                     = ($matrix[0][0] ?? 0) + ($matrix[0][1] ?? 0);
                $supportNegTest                     = ($matrix[1][0] ?? 0) + ($matrix[1][1] ?? 0);
                $testMetricsData                    = [
                    'accuracy' => round($accuracy, 4),
                    $posKey    => ['precision' => round($precisionPos, 4), 'recall' => round($recallPos, 4), 'f1_score' => round($f1Pos, 4), 'support' => $supportPosTest, 'label' => $posKey],
                    $negKey    => ['precision' => round($precisionNeg, 4), 'recall' => round($recallNeg, 4), 'f1_score' => round($f1Neg, 4), 'support' => $supportNegTest, 'label' => $negKey],
                ];
                $allTestResults[$modelKey] = ['metrics' => $testMetricsData, 'confusion_matrix' => $matrix];
                $this->table(['Metric', 'Value'], [
                    ['Accuracy', round($accuracy, 4)],
                    ["Precision ({$posKey})", round($precisionPos, 4)],
                    ["Recall ({$posKey})", round($recallPos, 4)],
                    ["F1 ({$posKey})", round($f1Pos, 4)],
                    ["Precision ({$negKey})", round($precisionNeg, 4)],
                    ["Recall ({$negKey})", round($recallNeg, 4)],
                    ["F1 ({$negKey})", round($f1Neg, 4)],
                ]);
                $this->info('      Confusion Matrix Tes (Actual \\ Predicted):');
                $this->table(['Actual', "Pred {$posKey}", "Pred {$negKey}"], [
                    [ucfirst($posKey), $matrix[0][0] ?? 0, $matrix[0][1] ?? 0],
                    [ucfirst($negKey), $matrix[1][0] ?? 0, $matrix[1][1] ?? 0],
                ]);
            } catch (Throwable $e) {
                $this->error("      ❌ Error evaluasi tes {$modelKey}: " . $e->getMessage());
                Log::error("Test Eval Exception {$modelKey}", ['error' => $e->getMessage()]);
            }
            unset($model, $modelScaler, $testDatasetForPredict);
        }
        unset($originalTestDataset, $testLabels);
        return $allTestResults;
    }

    private function saveTestResults(array $testResults): void
    {
        if (empty($testResults)) {
            return;
        }
        // Path S3 langsung
        $s3TestFilePath = ModelService::MODEL_DIR_S3 . "/all_detector_test_results.json";
        try {
            $jsonContent = json_encode($testResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            // Langsung put ke S3 dengan path S3
            if (Storage::disk('s3')->put($s3TestFilePath, $jsonContent, 'private')) {
                $this->info("    Hasil tes gabungan disimpan ke S3: {$s3TestFilePath}");
                Cache::forget(ModelService::CACHE_PREFIX . 'all_detector_test_results');
            } else {
                Log::error("Gagal menyimpan hasil evaluasi test set detector ke S3.", ['s3_path' => $s3TestFilePath]);
                $this->error("    ❌ Gagal menyimpan hasil tes gabungan ke S3.");
            }
        } catch (Throwable $e) {
            Log::error("Error menyimpan hasil tes detector ke S3", ['error' => $e->getMessage(), 's3_path' => $s3TestFilePath]);
            $this->error("    ❌ Error saat menyimpan hasil tes gabungan ke S3.");
        }
    }

    private function performCrossValidation(Learner $model, Labeled $originalDataset): array
    {
        $numSamples = $originalDataset->numSamples();
        if ($numSamples < FeatureExtractionService::DEFAULT_K_FOLDS) {
            $this->warn("      Jumlah sampel ({$numSamples}) kurang dari K_FOLDS (" . FeatureExtractionService::DEFAULT_K_FOLDS . ") untuk CV. Melewati CV.");
            return ['k_folds' => FeatureExtractionService::DEFAULT_K_FOLDS, 'metrics_per_fold' => []];
        }

        $foldSize       = (int) floor($numSamples / FeatureExtractionService::DEFAULT_K_FOLDS);
        $posKey         = 'melon';     // Label positif untuk detektor
        $negKey         = 'non_melon'; // Label negatif untuk detektor
        $metricsPerFold = [
            'accuracy'            => [],
            "precision_{$posKey}" => [], "recall_{$posKey}" => [], "f1_{$posKey}" => [],
            "precision_{$negKey}" => [], "recall_{$negKey}" => [], "f1_{$negKey}" => [],
        ];

        $indices = range(0, $numSamples - 1);
        shuffle($indices);
        $allSamples = $originalDataset->samples();
        $allLabels  = $originalDataset->labels();

        $this->line("      Memulai " . FeatureExtractionService::DEFAULT_K_FOLDS . "-Fold Cross Validation untuk Detektor:");
        $progressBar = $this->output->createProgressBar(FeatureExtractionService::DEFAULT_K_FOLDS);
        $progressBar->setFormat(" CV Fold %current%/%max% [%bar%] %percent:3s%%");
        $progressBar->start();

        for ($fold = 0; $fold < FeatureExtractionService::DEFAULT_K_FOLDS; $fold++) {
            $startIdx        = $fold * $foldSize;
            $currentFoldSize = ($fold === FeatureExtractionService::DEFAULT_K_FOLDS - 1) ? ($numSamples - $startIdx) : $foldSize;
            if ($currentFoldSize <= 0) {continue;}

            $valIndices   = array_slice($indices, $startIdx, $currentFoldSize);
            $trainIndices = array_diff($indices, $valIndices);
            if (empty($trainIndices) || empty($valIndices)) {
                Log::warning("CV Fold {$fold} (Detector): Skipping due to empty train/validation indices.");
                $this->addNullMetrics($metricsPerFold); // Tambahkan null jika fold dilewati
                $progressBar->advance();
                continue;
            }

            $valSamples   = array_map(fn($i) => $allSamples[$i], $valIndices);
            $valLabels    = array_map(fn($i) => $allLabels[$i], $valIndices);
            $trainSamples = array_map(fn($i) => $allSamples[$i], $trainIndices);
            $trainLabels  = array_map(fn($i) => $allLabels[$i], $trainIndices);

            try {
                $trainDatasetFold = Labeled::build($trainSamples, $trainLabels);
                $valDatasetFold   = Labeled::build($valSamples, $valLabels);

                if (count(array_unique($trainDatasetFold->labels())) < 2) {
                    Log::warning("CV Fold {$fold} (Detector): Training data only has one class. Skipping fold.");
                    $this->addNullMetrics($metricsPerFold);
                    $progressBar->advance();
                    continue;
                }

                $foldScaler = new ZScaleStandardizer(); // Scaler baru untuk fold ini
                $foldScaler->fit($trainDatasetFold);    // Fit HANYA pada data training fold ini

                $trainDatasetFold->apply($foldScaler);
                $valDatasetFold->apply($foldScaler); // Terapkan scaler yang sama ke data validasi fold

                $foldModel = clone $model; // Clone model asli untuk dilatih per fold
                $foldModel->train($trainDatasetFold);

                $predictions = $foldModel->predict($valDatasetFold);
                $labels      = $valDatasetFold->labels();

                $metricsPerFold['accuracy'][]            = (new Accuracy())->score($predictions, $labels);
                [$precisionPos, $recallPos, $f1Pos]      = $this->evaluator->calculateMetrics($labels, $predictions, $posKey);
                [$precisionNeg, $recallNeg, $f1Neg]      = $this->evaluator->calculateMetrics($labels, $predictions, $negKey);
                $metricsPerFold["precision_{$posKey}"][] = $precisionPos;
                $metricsPerFold["recall_{$posKey}"][]    = $recallPos;
                $metricsPerFold["f1_{$posKey}"][]        = $f1Pos;
                $metricsPerFold["precision_{$negKey}"][] = $precisionNeg;
                $metricsPerFold["recall_{$negKey}"][]    = $recallNeg;
                $metricsPerFold["f1_{$negKey}"][]        = $f1Neg;

            } catch (Throwable $e) {
                Log::error("CV Fold {$fold} Error (Detector Model: " . get_class($model) . "): " . $e->getMessage());
                $this->addNullMetrics($metricsPerFold); // Tambahkan null jika error pada fold ini
            } finally {
                $progressBar->advance();
                unset($trainDatasetFold, $valDatasetFold, $foldScaler, $foldModel);
            }
        }
        $progressBar->finish();
        $this->line(""); // Baris baru setelah progress bar

        // Filter skor null sebelum mengembalikan, untuk memastikan array metrik konsisten
        $validMetricsPerFold = [];
        foreach ($metricsPerFold as $metricKey => $scores) {
            $validMetricsPerFold[$metricKey] = array_values(array_filter($scores, fn($s) => $s !== null && is_numeric($s)));
        }

        // Jika setelah filter, metrik akurasi kosong, berarti tidak ada fold yang berhasil
        if (empty($validMetricsPerFold['accuracy'])) {
            $this->warn("      CV untuk Detektor tidak menghasilkan fold yang valid sama sekali.");
            // Kosongkan semua array metrik jika akurasi kosong
            foreach (array_keys($metricsPerFold) as $keyToClear) {
                $metricsPerFold[$keyToClear] = [];
            }
            return ['k_folds' => FeatureExtractionService::DEFAULT_K_FOLDS, 'metrics_per_fold' => $metricsPerFold];
        }

        return ['k_folds' => FeatureExtractionService::DEFAULT_K_FOLDS, 'metrics_per_fold' => $validMetricsPerFold];
    }

    private function addNullMetrics(array &$metricsPerFold): void
    {
        foreach (array_keys($metricsPerFold) as $metricKey) {
            $metricsPerFold[$metricKey][] = null; // Tambahkan null untuk menjaga konsistensi jumlah iterasi
        }
    }

    private function displayCrossValidationResultsConsole(string $modelName, array $cvStats): void
    {
        if (empty($cvStats)) {
            return;
        }

        $this->info("\n      📊 Hasil {$modelName} Cross Validation ({FeatureExtractionService::DEFAULT_K_FOLDS}-fold) [Console Summary]:");
        $tableData    = [];
        $metricOrder  = ['accuracy', 'precision_melon', 'recall_melon', 'f1_melon', 'precision_non_melon', 'recall_non_melon', 'f1_non_melon'];
        $metricLabels = [
            'accuracy'            => 'Accuracy',
            'precision_melon'     => 'Precision (Melon)',
            'recall_melon'        => 'Recall (Melon)',
            'f1_melon'            => 'F1 Score (Melon)',
            'precision_non_melon' => 'Precision (Non-Melon)',
            'recall_non_melon'    => 'Recall (Non-Melon)',
            'f1_non_melon'        => 'F1 Score (Non-Melon)',
        ];
        foreach ($metricOrder as $metricKey) {
            if (isset($cvStats[$metricKey])) {
                $label       = $metricLabels[$metricKey] ?? ucfirst(str_replace('_', ' ', $metricKey));
                $tableData[] = [$label, sprintf('%.4f ± %.4f', $cvStats[$metricKey]['mean'] ?? 0, $cvStats[$metricKey]['std'] ?? 0)];
            }
        }
        $this->table(['Metric', 'Mean ± Std'], $tableData);
    }

    private function clearModelCache(string $baseKey): void
    {
        Cache::forget(ModelService::CACHE_PREFIX . $baseKey);
        Cache::forget(ModelService::CACHE_PREFIX . $baseKey . '_scaler');
    }
}
