<?php

// File: app/Console/Commands/TrainMelonClassifierModel.php

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

class TrainMelonClassifierModel extends Command
{

    use Versionable;

    protected $signature   = 'train:melon-classifier {--with-test : Sertakan evaluasi pada set tes} {--with-cv : Sertakan k-fold cross validation}';
    protected $description = 'Melatih SEMUA model klasifikasi kematangan melon dengan scaler spesifik per model.';

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
        $this->line("EVENT_STATUS: START, Memulai Pelatihan Model Klasifikasi...");
        $this->info('🚀 Memulai Pelatihan Model Klasifikasi Kematangan Melon (Semua Algoritma)');

        $this->info('[1/3] Memuat fitur training...');
        $this->line("EVENT_LOG: [1/3] Memuat fitur training...");
        $s3TrainFeaturePath                      = FeatureExtractionService::S3_FEATURE_DIR . '/train_classifier_features.csv';
        [$trainSamples, $trainLabels, $trainIds] = $this->loadFeaturesFromCsv($s3TrainFeaturePath);

        if (empty($trainSamples)) {
            $this->error('❌ File fitur training (train_classifier_features.csv) kosong/tidak valid!');
            $this->line("EVENT_STATUS: ERROR, File fitur training kosong/tidak valid.");
            return self::FAILURE;
        }
        if (count(array_unique($trainLabels)) < 2) {
            $this->error('❌ Dataset training harus memiliki minimal dua kelas (ripe/unripe)!');
            $this->line("EVENT_STATUS: ERROR, Dataset training tidak memiliki dua kelas.");
            return self::FAILURE;
        }
        $this->info("    Memuat " . count($trainSamples) . " sampel training.");
        $this->line("EVENT_LOG:    Memuat " . count($trainSamples) . " sampel training.");
        $originalTrainDataset = Labeled::build($trainSamples, $trainLabels);
        unset($trainSamples, $trainLabels);

        $this->info('[2/3] Menginisialisasi, melatih, dan menyimpan semua model klasifikasi...');
        $this->line("EVENT_LOG: [2/3] Menginisialisasi, melatih, dan menyimpan model...");
        $classifiers             = $this->getAllClassifiers();
        $trainingSuccessfulCount = 0;
        $allValidationMetrics    = [];
        $totalModelsToTrain      = count($classifiers);
        $trainedModelCount       = 0;

        foreach ($classifiers as $key => $classifier) {
            if (! $classifier instanceof Learner) {
                $this->error("      ❌ Item '{$key}' bukan instance Learner yang valid.");
                $this->line("EVENT_LOG: ERROR - Item '{$key}' bukan instance Learner.");
                continue;
            }
            $name      = ucwords(str_replace('_', ' ', $key));
            $modelKey  = $key . '_classifier';
            $scalerKey = $modelKey . '_scaler';
            $this->line("    - Melatih {$name}...");
            $this->line("EVENT_LOG: Melatih {$name}...");

            $modelStartTime = microtime(true);
            try {
                $this->line("      - Menyiapkan scaler spesifik untuk {$name}...");
                $modelScaler = new ZScaleStandardizer();
                $modelScaler->fit($originalTrainDataset);
                $modelTrainDataset = clone $originalTrainDataset;
                $modelTrainDataset->apply($modelScaler);
                $this->info("      - Penskalaan spesifik diterapkan.");

                $classifier->train($modelTrainDataset);
                $modelDuration = round(microtime(true) - $modelStartTime, 2);
                $this->info("      ✅ Pelatihan {$name} selesai ({$modelDuration} detik).");
                $this->line("EVENT_LOG: ✅ Pelatihan {$name} selesai ({$modelDuration} detik).");

                $trainPredictions = $classifier->predict($modelTrainDataset);
                $trainingAccuracy = (new Accuracy())->score($trainPredictions, $modelTrainDataset->labels());
                $this->line("          > Akurasi Training: " . number_format($trainingAccuracy * 100, 1) . "%");

                $s3ModelPath = ModelService::MODEL_DIR_S3 . "/{$modelKey}.model";
                try {
                    (new S3ObjectPersister($s3ModelPath))->save($classifier, 'private');
                    $this->info("      Model disimpan ke S3: {$s3ModelPath}");
                } catch (Throwable $e) {
                    Log::error("Gagal menyimpan model ke S3: {$s3ModelPath}", ['error' => $e->getMessage()]);
                    $this->error("      ❌ Gagal menyimpan model ke S3.");
                }
                $this->saveModelSpecificScaler($scalerKey, $modelScaler);
                $s3MetaPath  = ModelService::MODEL_DIR_S3 . "/{$modelKey}_meta.json";
                $metadata    = $this->createMetadata($key, $classifier, $modelTrainDataset->numSamples(), $modelScaler, $trainingAccuracy, $s3MetaPath);
                $jsonContent = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($jsonContent === false || ! Storage::disk('s3')->put($s3MetaPath, $jsonContent, 'private')) {
                    Log::error("Gagal menyimpan file metadata classifier ke S3.", ['s3_path' => $s3MetaPath]);
                    $this->error("      ❌ Gagal menyimpan metadata ke S3.");
                } else {
                    $this->info("      Metadata disimpan ke S3: {$s3MetaPath}");
                }

                $this->info("      Memvalidasi {$name} pada set validasi...");
                $valMetrics = $this->validateSingleModel($key, $classifier, $modelScaler);
                if ($valMetrics) {
                    $valMetrics['algorithm_class']   = get_class($classifier);
                    $allValidationMetrics[$modelKey] = $valMetrics;
                    $this->info("      Metrik validasi {$name} berhasil dihitung.");
                    $this->line("          > Akurasi Validasi: " . round(($valMetrics['metrics']['accuracy'] ?? 0.0) * 100, 1) . "%");
                    if (isset($valMetrics['learning_curve_data'])) {
                        $this->modelService->saveLearningCurve($modelKey, $valMetrics['learning_curve_data']);
                        $this->info("      Learning curve data disimpan.");
                    }
                } else {
                    $this->warn("      ⚠️ Gagal menghitung metrik validasi untuk {$name}.");
                }

                if ($this->option('with-cv')) {
                    $this->info("      Melakukan cross validation pada {$name}...");
                    $this->line("EVENT_LOG: CV untuk {$name} dimulai...");
                    $cvResults = $this->performCrossValidation($classifier, $originalTrainDataset, $modelScaler);
                    if (! empty($cvResults['metrics_per_fold'])) {
                        if ($this->modelService->saveCrossValidationScores($modelKey, $cvResults)) {
                            $this->info("      Hasil cross validation disimpan.");
                            // ... (display CV results console) ...
                        } else { $this->error("      ❌ Gagal menyimpan hasil cross validation untuk {$name}.");}
                    } else { $this->warn("      ⚠️ Hasil cross validation tidak valid atau kosong untuk {$name}.");}
                    $this->line("EVENT_LOG: CV untuk {$name} selesai.");
                }
                $trainingSuccessfulCount++;
                $this->clearModelCache($modelKey);

                $trainedModelCount++;
                $progressPercentage = ($totalModelsToTrain > 0) ? round(($trainedModelCount / $totalModelsToTrain) * 100) : 0;
                $this->line("PROGRESS_UPDATE: {$progressPercentage}% (Selesai {$modelKey} - {$trainedModelCount}/{$totalModelsToTrain})");

            } catch (Throwable $e) {
                $this->error("      ❌ Error pada {$name}: " . $e->getMessage());
                $this->line("EVENT_LOG: ERROR training {$modelKey}: " . Str::limit($e->getMessage(), 70));
                Log::error("Training/Saving/Validation Exception for {$modelKey}", [
                    'exception_message' => $e->getMessage(),
                    'trace_snippet'     => Str::limit($e->getTraceAsString(), 500),
                ]);
            }
            unset($classifier, $modelTrainDataset, $modelScaler);
        }
        unset($originalTrainDataset);

        if ($trainingSuccessfulCount === 0) {
            $this->error('❌ Tidak ada model klasifikasi yang berhasil dilatih!');
            $this->line("EVENT_STATUS: ERROR, Tidak ada model yang berhasil dilatih.");
            return self::FAILURE;
        }
        $this->info("    {$trainingSuccessfulCount} model klasifikasi berhasil dilatih dan divalidasi.");
        $this->line("EVENT_LOG: {$trainingSuccessfulCount} model berhasil dilatih.");

        $s3CachePath = ModelService::MODEL_DIR_S3 . "/all_classifier_metrics.json";
        try {
            $jsonContent = json_encode($allValidationMetrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (Storage::disk('s3')->put($s3CachePath, $jsonContent, 'private')) {
                $this->info("    Cache metrik validasi gabungan disimpan ke S3: {$s3CachePath}");
                Cache::forget(ModelService::CACHE_PREFIX . 'all_classifier_metrics');
            } else {
                Log::error("Gagal menyimpan cache metrik validasi classifier gabungan menggunakan Storage::put.", ['path' => $s3CachePath]);
                $this->error("    ❌ Gagal menyimpan cache metrik validasi gabungan.");
            }
        } catch (Throwable $e) {
            Log::error("Gagal menyimpan cache metrik validasi classifier gabungan.", ['path' => $s3CachePath, 'error' => $e->getMessage()]);
            $this->error("    ❌ Gagal menyimpan cache metrik validasi gabungan: " . $e->getMessage());
        }

        if ($this->option('with-test')) {
            $this->info('[3/3] Mengevaluasi semua model pada set tes...');
            $this->line("EVENT_LOG: [3/3] Mengevaluasi pada set tes...");
            $testResults = $this->evaluateOnTestSet();
            if (! empty($testResults)) {
                $this->saveTestResults($testResults);
            } else { $this->warn("    Tidak ada hasil tes untuk disimpan.");}
        } else {
            $this->info('[3/3] Melewati evaluasi set tes (gunakan flag --with-test untuk mengaktifkan).');
        }

        $this->info('✅ Pelatihan dan Evaluasi Model Klasifikasi Selesai!');
        $this->line("EVENT_STATUS: DONE, Pelatihan Klasifikasi Selesai!");
        return self::SUCCESS;
    }

    private function getAllClassifiers(): array
    {
        $this->line("    Menginisialisasi algoritma klasifikasi...");
        return [
            'classification_tree'   => new ClassificationTree(12, 3, 0.001, FeatureExtractionService::CLASSIFIER_FEATURE_COUNT),
            'logistic_regression'   => new LogisticRegression(64, new Adam(0.001), 5e-4, 150, 1e-5),
            'gaussian_nb'           => new GaussianNB(),
            'ada_boost'             => new AdaBoost(new ClassificationTree(2), 0.05, 0.8, 150),
            'k_nearest_neighbors'   => new KNearestNeighbors(5, true),
            'logit_boost'           => new LogitBoost(new RegressionTree(2), 0.05, 0.5, 150),
            'multilayer_perceptron' => new MultilayerPerceptron(
                [
                    new Dense(32),
                    new Activation(new ReLU()),
                    new Dropout(0.1),
                    new Dense(16),
                    new Activation(new ReLU()),
                    new Dropout(0.1),
                    new Dense(8),
                    new Activation(new ReLU()),
                ],
                64,
                new Adam(0.001),
                5e-4,
                200,
                1e-5
            ),
            'random_forest'         => new RandomForest(
                new ClassificationTree(12, 3, 0.001, 4),
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

            if (! $header || count($header) < 3 || strtolower(trim($header[0])) !== 'annotation_id' || strtolower(trim($header[1])) !== 'label') {
                throw new InvalidArgumentException("Header CSV fitur classifier tidak valid. Ditemukan: " . implode(',', $header) . " di {$s3FeatureCsvPath}");
            }
            $expectedFeatureCountInHeader = count($header) - 2;
            if ($expectedFeatureCountInHeader !== FeatureExtractionService::CLASSIFIER_FEATURE_COUNT) { // <--- **BENAR JIKA SEPERTI INI**
                $this->error("      ❌ Jumlah fitur di header ({$expectedFeatureCountInHeader}) tidak sesuai harapan (" . FeatureExtractionService::CLASSIFIER_FEATURE_COUNT . "). File: {$s3FeatureCsvPath}");

                return [[], [], []];
            }
            if ($fileHandle->valid()) {
                $fileHandle->next();
            }
            // Lewati header jika valid

            while (! $fileHandle->eof() && ($row = $fileHandle->fgetcsv())) {
                if (! is_array($row) || count($row) !== count($header)) {
                    Log::warning("Baris CSV classifier tidak valid.", ['row_count' => is_array($row) ? count($row) : 'not_array', 'header_count' => count($header), 'path' => $s3FeatureCsvPath]);
                    continue;
                }
                $id            = trim($row[0]);
                $label         = strtolower(trim($row[1]));
                $features      = array_slice($row, 2, FeatureExtractionService::CLASSIFIER_FEATURE_COUNT);
                $floatFeatures = array_map('floatval', $features);

                if (count($floatFeatures) === FeatureExtractionService::CLASSIFIER_FEATURE_COUNT && // <--- **BENAR JIKA SEPERTI INI**
                    in_array($label, FeatureExtractionService::CLASSIFIER_VALID_LABELS) &&              // <--- Pastikan CLASSIFIER_VALID_LABELS ada di FeatureExtractionService
                    ! empty($id)) {
                    $samples[] = $floatFeatures;
                    $labels[]  = $label;
                    $ids[]     = $id;
                } else {
                    Log::warning("Data sampel classifier tidak valid dilewati.", ['id' => $id, 'label' => $label, 'num_features_read' => count($features), 'path' => $s3FeatureCsvPath]);
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
            Log::error('Dataset classifier kosong setelah validasi', ['path' => $s3FeatureCsvPath]);
        }

        return [$samples, $labels, $ids];
    }

    private function createMetadata(
        string $key,
        Learner $model,
        int $trainingSamples,
        ZScaleStandardizer $scaler,
        ?float $trainingAccuracy = null, // Argumen ke-5
        string $s3MetaPathForVersion     // Argumen ke-6 (path S3 untuk versioning)
    ): array {
        $modelIdentifier = $key . '_classifier';
        $metadata        = [
            'model_key'              => $modelIdentifier,
            'task_type'              => 'classifier',
            // Gunakan $s3MetaPathForVersion yang di-pass
            'version'                => $this->getNextModelVersion($s3MetaPathForVersion),
            'trained_at'             => now()->toIso8601String(),
            'training_samples_count' => $trainingSamples,
            'num_features_expected'  => FeatureExtractionService::CLASSIFIER_FEATURE_COUNT,
            'feature_names'          => FeatureExtractionService::CLASSIFIER_FEATURE_NAMES,
            'scaler_used_class'      => get_class($scaler),
            'algorithm_class'        => get_class($model),
            'hyperparameters'        => $this->hyperParametersOf($model),
            'rubix_ml_version'       => \Rubix\ML\VERSION,
        ];
        if ($trainingAccuracy !== null) {
            $metadata['training_accuracy'] = round($trainingAccuracy, 4);
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
                    // Untuk properti private/protected
                    $value = $prop->getValue($estimator);
                    // Jika hyperparameter adalah objek (misal, base estimator), simpan nama kelasnya
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
            $this->info("     Model-specific scaler disimpan ke: {$s3ScalerPath}");
            return true;
        } catch (Throwable $e) {
            $this->error("     Error saat menyimpan model-specific scaler {$scalerKey}: " . $e->getMessage());
            Log::error("Model-specific Scaler Save Exception", ['scaler_key' => $scalerKey, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private function validateSingleModel(string $key, Learner $model, ZScaleStandardizer $modelScaler): ?array
    {
        $modelKey                                = $key . '_classifier';
        $featureFile                             = 'valid_classifier_features.csv';
        $s3ValidFeaturePath                      = FeatureExtractionService::S3_FEATURE_DIR . '/valid_classifier_features.csv'; // Path S3
        [$validSamples, $validLabels, $validIds] = $this->loadFeaturesFromCsv($s3ValidFeaturePath);

        if (empty($validSamples)) {
            $this->warn("     File fitur validasi ({$featureFile}) tidak ditemukan/kosong. Validasi dilewati untuk {$modelKey}.");
            return null;
        }
        if (count(array_unique($validLabels)) < 2) {
            $this->warn("     Dataset validasi {$modelKey} tidak punya 2 kelas.");
        }

        try {
            $originalValDataset   = new Labeled($validSamples, $validLabels);
            $valDatasetForPredict = clone $originalValDataset;
            $valDatasetForPredict->apply($modelScaler);

            $predictions   = $model->predict($valDatasetForPredict);
            $learningCurve = $this->generateLearningCurve($model, $originalValDataset);

            $posKey                             = 'ripe';
            $negKey                             = 'unripe';
            [$precisionPos, $recallPos, $f1Pos] = $this->evaluator->calculateMetrics($validLabels, $predictions, $posKey);
            [$precisionNeg, $recallNeg, $f1Neg] = $this->evaluator->calculateMetrics($validLabels, $predictions, $negKey);
            $accuracy                           = (new Accuracy())->score($predictions, $validLabels);
            $matrix                             = $this->evaluator->confusionMatrix($validLabels, $predictions, $posKey);
            $supportPos                         = ($matrix[0][0] ?? 0) + ($matrix[0][1] ?? 0);
            $supportNeg                         = ($matrix[1][0] ?? 0) + ($matrix[1][1] ?? 0);

            return [
                'model_key'           => $modelKey,
                'validation_samples'  => count($validSamples),
                'metrics'             => [
                    'accuracy' => round($accuracy, 4),
                    $posKey    => ['precision' => round($precisionPos, 4), 'recall' => round($recallPos, 4), 'f1_score' => round($f1Pos, 4), 'support' => $supportPos, 'label' => $posKey],
                    $negKey    => ['precision' => round($precisionNeg, 4), 'recall' => round($recallNeg, 4), 'f1_score' => round($f1Neg, 4), 'support' => $supportNeg, 'label' => $negKey],
                ],
                'confusion_matrix'    => $matrix,
                'learning_curve_data' => $learningCurve,
            ];
        } catch (Throwable $e) {
            $this->error("     ❌ Error saat validasi {$modelKey}: " . $e->getMessage());
            Log::error("Validation Exception for {$modelKey}", ['exception_message' => $e->getMessage()]);
            return null;
        }
    }

    private function generateLearningCurve(Learner $model, Labeled $originalUnscaledDataset): array
    {
        $numSamples = $originalUnscaledDataset->numSamples();
        if ($numSamples < 10) { // Butuh minimal beberapa sampel untuk LC yang berarti
            Log::info("Learning Curve: Not enough samples ({$numSamples}) for {$model->type()}. Skipping LC generation.");
            return ['train_sizes' => [], 'train_scores' => [], 'test_scores' => []];
        }

        $trainSizesRatios = [0.2, 0.4, 0.6, 0.8, 1.0]; // Rasio ukuran training
        $trainScores      = [];
        $testScores       = [];
        $actualTrainSizes = [];
        $allSamples       = $originalUnscaledDataset->samples();
        $allLabels        = $originalUnscaledDataset->labels();
        $indices          = range(0, $numSamples - 1);

        foreach ($trainSizesRatios as $ratio) {
            shuffle($indices);                                             // Acak indeks untuk setiap rasio
            $currentTrainSize = max(5, (int) floor($numSamples * $ratio)); // Min 5 sampel train
                                                                           // Untuk rasio 1.0, gunakan hampir semua data untuk train, sisakan sedikit untuk test
            if ($ratio == 1.0) {
                $currentTrainSize = $numSamples > 1 ? $numSamples - max(1, (int) floor($numSamples * 0.1)) : 1; // Sisakan ~10% untuk test
                if ($currentTrainSize < 5 && $numSamples > 5) {
                    $currentTrainSize = $numSamples - 1;
                }
                // Minimal 1 test jika sampel sedikit
                else if ($currentTrainSize < 5) {
                    $currentTrainSize = $numSamples;
                }
                // Gunakan semua jika < 5
            }
            if ($currentTrainSize >= $numSamples && $numSamples > 1) {
                $currentTrainSize = $numSamples - 1;
            }
            // Pastikan ada data test
            if ($currentTrainSize <= 0) {
                continue;
            }

            $currentTestSize = $numSamples - $currentTrainSize;
            // Jika rasio < 1.0 dan tidak ada data test, sesuaikan agar ada data test
            if ($currentTestSize < 1 && $ratio < 1.0) {
                $currentTrainSize = $numSamples > 1 ? $numSamples - 1 : 1; // Minimal 1 data test
                $currentTestSize  = $numSamples - $currentTrainSize;
            }
            if ($currentTrainSize < 1 || ($currentTestSize < 1 && $ratio < 1.0)) {
                Log::warning("Learning Curve: Skipping ratio {$ratio} due to insufficient train/test split size for {$model->type()}.");
                continue; // Lewati jika tidak bisa split
            }
            $actualTrainSizes[] = $currentTrainSize;

            $trainIndices = array_slice($indices, 0, $currentTrainSize);
            $testIndices  = array_slice($indices, $currentTrainSize, $currentTestSize);
            if (empty($trainIndices) || (empty($testIndices) && $ratio < 1.0)) {
                Log::warning("Learning Curve: Empty train or test indices for ratio {$ratio} for {$model->type()}.");
                continue;
            }

            $trainSamplesSubset = array_map(fn($i) => $allSamples[$i], $trainIndices);
            $trainLabelsSubset  = array_map(fn($i) => $allLabels[$i], $trainIndices);
            $testSamplesSubset  = ! empty($testIndices) ? array_map(fn($i) => $allSamples[$i], $testIndices) : [];
            $testLabelsSubset   = ! empty($testIndices) ? array_map(fn($i) => $allLabels[$i], $testIndices) : [];

            try {
                $trainDatasetSubset = Labeled::build($trainSamplesSubset, $trainLabelsSubset);
                if (count(array_unique($trainDatasetSubset->labels())) < 2 && $trainDatasetSubset->numSamples() > 0) {
                    Log::info("Learning Curve: Training subset for ratio {$ratio} has only one class for {$model->type()}. Skipping this point.");
                    $trainScores[] = null;
                    $testScores[]  = null;
                    continue;
                }

                $tempModel    = clone $model;             // Clone model asli
                $subsetScaler = new ZScaleStandardizer(); // Scaler baru untuk subset ini
                $subsetScaler->fit($trainDatasetSubset);  // Fit scaler HANYA pada subset training ini
                $scaledTrainSubset = clone $trainDatasetSubset;
                $scaledTrainSubset->apply($subsetScaler);
                $tempModel->train($scaledTrainSubset);

                $trainPreds    = $tempModel->predict($scaledTrainSubset);
                $trainScore    = (new Accuracy())->score($trainPreds, $scaledTrainSubset->labels());
                $trainScores[] = $trainScore;

                if (! empty($testSamplesSubset)) {
                    $testDatasetSubset = Labeled::build($testSamplesSubset, $testLabelsSubset);
                    if (count(array_unique($testDatasetSubset->labels())) < 1 && $testDatasetSubset->numSamples() > 0) {
                        Log::info("Learning Curve: Test subset for ratio {$ratio} has no/one class for {$model->type()}.");
                    }
                    $scaledTestSubset = clone $testDatasetSubset;
                    $scaledTestSubset->apply($subsetScaler); // Terapkan scaler yang sama
                    $testPreds    = $tempModel->predict($scaledTestSubset);
                    $testScore    = (new Accuracy())->score($testPreds, $scaledTestSubset->labels());
                    $testScores[] = $testScore;
                } else {
                    $testScores[] = null; // Tidak ada data tes untuk rasio 1.0 (atau jika split gagal)
                }
            } catch (Throwable $e) {
                Log::error("Learning Curve: Error processing subset for ratio {$ratio} with {$model->type()}", ['error' => $e->getMessage()]);
                $trainScores[] = null;
                $testScores[]  = null;
            }
            unset($tempModel, $subsetScaler, $trainDatasetSubset, $scaledTrainSubset, $testDatasetSubset, $scaledTestSubset);
        }

        // Filter null dan pastikan ukuran array konsisten
        $validTrainScores = [];
        $validTestScores  = [];
        $validTrainSizes  = [];
        for ($i = 0; $i < count($trainScores); $i++) {
            // Hanya sertakan jika trainScore valid. testScore bisa null jika itu titik terakhir (ratio 1.0)
            if ($trainScores[$i] !== null && ($testScores[$i] !== null || $trainSizesRatios[$i] == 1.0)) {
                $validTrainScores[] = $trainScores[$i];
                $validTestScores[]  = $testScores[$i]; // Bisa jadi null
                $validTrainSizes[]  = $actualTrainSizes[$i];
            }
        }
        return ['train_sizes' => $validTrainSizes, 'train_scores' => $validTrainScores, 'test_scores' => $validTestScores];
    }

    private function evaluateOnTestSet(): array
    {
        $s3TestFeaturePath          = FeatureExtractionService::S3_FEATURE_DIR . '/test_classifier_features.csv'; // After
        [$testSamples, $testLabels] = $this->loadFeaturesFromCsv($s3TestFeaturePath);
        $allTestResults             = [];

        if (empty($testSamples)) {
            $this->warn("   File fitur tes classifier tidak ditemukan/kosong. Evaluasi tes dilewati.");
            return $allTestResults;
        }
        $this->info("   Memuat " . count($testSamples) . " sampel tes.");
        $originalTestDataset = new Labeled($testSamples, $testLabels);
        unset($testSamples);

        $classifiers = $this->getAllClassifiers();

        foreach (array_keys($classifiers) as $key) {
            $name      = ucwords(str_replace('_', ' ', $key));
            $modelKey  = $key . '_classifier';
            $scalerKey = $modelKey . '_scaler';
            $this->line("\n     📊 Hasil Tes untuk {$name}:");

            try {
                $model = $this->modelService->loadModel($modelKey);
                if (! ($model instanceof Learner)) {
                    $this->error("     ❌ Gagal memuat model {$modelKey} atau bukan Learner yang valid.");
                    continue;
                }
                $modelScaler = $this->modelService->loadModel($scalerKey);
                if (! ($modelScaler instanceof ZScaleStandardizer)) {
                    $this->error("     ❌ Gagal memuat scaler spesifik ({$scalerKey}) untuk model {$name}. Evaluasi tes dilewati.");
                    continue;
                }

                $testDatasetForPredict = clone $originalTestDataset;
                $testDatasetForPredict->apply($modelScaler);
                $predictions = $model->predict($testDatasetForPredict);

                $posKey                             = 'ripe';
                $negKey                             = 'unripe';
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

                $metricsTableData = [
                    ['Accuracy', round($accuracy, 4)],
                    ["Precision ({$posKey})", round($precisionPos, 4)],
                    ["Recall ({$posKey})", round($recallPos, 4)],
                    ["F1 ({$posKey})", round($f1Pos, 4)],
                    ["Precision ({$negKey})", round($precisionNeg, 4)],
                    ["Recall ({$negKey})", round($recallNeg, 4)],
                    ["F1 ({$negKey})", round($f1Neg, 4)],
                ];
                $this->table(['Metric', 'Value'], $metricsTableData);
                $this->info('     Confusion Matrix Tes (Actual \ Predicted):');
                $this->table(['Actual', "Pred {$posKey}", "Pred {$negKey}"], [
                    [ucfirst($posKey), $matrix[0][0] ?? 0, $matrix[0][1] ?? 0],
                    [ucfirst($negKey), $matrix[1][0] ?? 0, $matrix[1][1] ?? 0],
                ]);
            } catch (Throwable $e) {
                $this->error("     ❌ Error saat evaluasi tes {$modelKey}: " . $e->getMessage());
                Log::error("Test Evaluation Exception for {$modelKey}", ['exception_message' => $e->getMessage()]);
            }
            unset($model, $modelScaler, $testDatasetForPredict);
        }
        unset($originalTestDataset);
        return $allTestResults;
    }

    private function saveTestResults(array $testResults): void
    {
        if (empty($testResults)) {
            return;
        }
        // Path S3 langsung
        $s3TestFilePath = ModelService::MODEL_DIR_S3 . "/all_classifier_test_results.json";
        try {
            $jsonContent = json_encode($testResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            // Langsung put ke S3 dengan path S3
            if (Storage::disk('s3')->put($s3TestFilePath, $jsonContent, 'private')) {
                $this->info("    Hasil tes gabungan disimpan ke S3: {$s3TestFilePath}");
                Cache::forget(ModelService::CACHE_PREFIX . 'all_classifier_test_results');
            } else {
                Log::error("Gagal menyimpan hasil evaluasi test set classifier ke S3.", ['s3_path' => $s3TestFilePath]);
                $this->error("    ❌ Gagal menyimpan hasil tes gabungan ke S3.");
            }
        } catch (Throwable $e) {
            Log::error("Error menyimpan hasil tes classifier ke S3", ['error' => $e->getMessage(), 's3_path' => $s3TestFilePath]);
            $this->error("    ❌ Error saat menyimpan hasil tes gabungan ke S3.");
        }
    }

    private function performCrossValidation(Learner $model, Labeled $originalDataset, ZScaleStandardizer $baseScalerForCV): array
    {
        $numSamples = $originalDataset->numSamples();
        if ($numSamples < FeatureExtractionService::DEFAULT_K_FOLDS) {
            $this->warn("      Jumlah sampel ({$numSamples}) kurang dari K_FOLDS (" . FeatureExtractionService::DEFAULT_K_FOLDS . ") untuk CV. Melewati CV.");
            return ['k_folds' => FeatureExtractionService::DEFAULT_K_FOLDS, 'metrics_per_fold' => []];
        }
        $foldSize       = (int) floor($numSamples / FeatureExtractionService::DEFAULT_K_FOLDS);
        $posKey         = 'ripe';
        $negKey         = 'unripe';
        $metricsPerFold = [
            'accuracy'            => [],
            "precision_{$posKey}" => [],
            "recall_{$posKey}"    => [],
            "f1_{$posKey}"        => [],
            "precision_{$negKey}" => [],
            "recall_{$negKey}"    => [],
            "f1_{$negKey}"        => [],
        ];
        $indices = range(0, $numSamples - 1);
        shuffle($indices);
        $allSamples = $originalDataset->samples();
        $allLabels  = $originalDataset->labels();

        $this->line("      Memulai " . FeatureExtractionService::DEFAULT_K_FOLDS . "-Fold Cross Validation:");
        $progressBar = $this->output->createProgressBar(FeatureExtractionService::DEFAULT_K_FOLDS);
        $progressBar->start();

        for ($fold = 0; $fold < FeatureExtractionService::DEFAULT_K_FOLDS; $fold++) {
            $startIdx        = $fold * $foldSize;
            $currentFoldSize = ($fold === FeatureExtractionService::DEFAULT_K_FOLDS - 1) ? ($numSamples - $startIdx) : $foldSize;
            if ($currentFoldSize <= 0) {
                continue;
            }

            $valIndices   = array_slice($indices, $startIdx, $currentFoldSize);
            $trainIndices = array_diff($indices, $valIndices);
            if (empty($trainIndices) || empty($valIndices)) {
                Log::warning("CV Fold {$fold}: Skipping due to empty indices.");
                $this->addNullMetrics($metricsPerFold);
                $progressBar->advance();
                continue;
            }
            $validSamples = array_map(fn($i) => $allSamples[$i], $valIndices);
            $validLabels  = array_map(fn($i) => $allLabels[$i], $valIndices);
            $trainSamples = array_map(fn($i) => $allSamples[$i], $trainIndices);
            $trainLabels  = array_map(fn($i) => $allLabels[$i], $trainIndices);

            try {
                $trainDatasetFold = Labeled::build($trainSamples, $trainLabels);
                $valDatasetFold   = Labeled::build($validSamples, $validLabels);
                if (count(array_unique($trainDatasetFold->labels())) < 2) {
                    Log::warning("CV Fold {$fold}: Training data only has one class. Skipping fold.");
                    $this->addNullMetrics($metricsPerFold);
                    $progressBar->advance();
                    continue;
                }
                $foldScaler = new ZScaleStandardizer();
                $foldScaler->fit($trainDatasetFold);
                $trainDatasetFold->apply($foldScaler);
                $valDatasetFold->apply($foldScaler);
                $foldModel = clone $model;
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
                Log::error("CV Fold {$fold} Error (" . get_class($model) . "): " . $e->getMessage());
                $this->addNullMetrics($metricsPerFold);
            } finally {
                $progressBar->advance();
                unset($trainDatasetFold, $valDatasetFold, $foldScaler, $foldModel);
            }
        }
        $progressBar->finish();
        $this->line("");
        $validMetricsPerFold = [];
        foreach ($metricsPerFold as $metricKey => $scores) {
            $validMetricsPerFold[$metricKey] = array_values(array_filter($scores, fn($s) => $s !== null && is_numeric($s)));
        }
        if (empty($validMetricsPerFold['accuracy'])) {
            $this->warn("      CV tidak menghasilkan fold yang valid sama sekali.");
            foreach (array_keys($metricsPerFold) as $key) {
                $metricsPerFold[$key] = [];
            }

            return ['k_folds' => FeatureExtractionService::DEFAULT_K_FOLDS, 'metrics_per_fold' => $metricsPerFold];
        }
        return ['k_folds' => FeatureExtractionService::DEFAULT_K_FOLDS, 'metrics_per_fold' => $validMetricsPerFold];
    }

    private function addNullMetrics(array &$metricsPerFold): void
    {
        foreach (array_keys($metricsPerFold) as $metricKey) {
            $metricsPerFold[$metricKey][] = null;
        }

    }

    private function displayCrossValidationResultsConsole(string $modelName, array $cvStats): void
    {
        if (empty($cvStats)) {
            return;
        }

        $this->info("\n     📊 Hasil {$modelName} Cross Validation (" . FeatureExtractionService::DEFAULT_K_FOLDS . "-fold) [Console Summary]:");
        $tableData    = [];
        $metricOrder  = ['accuracy', 'precision_ripe', 'recall_ripe', 'f1_ripe', 'precision_unripe', 'recall_unripe', 'f1_unripe'];
        $metricLabels = [
            'accuracy'         => 'Accuracy',
            'precision_ripe'   => 'Precision (Ripe)',
            'recall_ripe'      => 'Recall (Ripe)',
            'f1_ripe'          => 'F1 Score (Ripe)',
            'precision_unripe' => 'Precision (Unripe)',
            'recall_unripe'    => 'Recall (Unripe)',
            'f1_unripe'        => 'F1 Score (Unripe)',
        ];
        foreach ($metricOrder as $metricKey) {
            if (isset($cvStats[$metricKey])) {
                $label       = $metricLabels[$metricKey] ?? ucfirst(str_replace('_', ' ', $metricKey));
                $tableData[] = [$label, sprintf('%.4f ± %.4f', $cvStats[$metricKey]['mean'] ?? 0, $cvStats[$metricKey]['std'] ?? 0)];
            }
        }
        $this->table(['Metric', 'Mean ± Std'], $tableData);
    }

    private function clearModelCache(string $modelKey): void
    {
        Cache::forget(ModelService::CACHE_PREFIX . $modelKey);
        Cache::forget(ModelService::CACHE_PREFIX . $modelKey . '_scaler');
    }
}
