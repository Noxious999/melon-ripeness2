<?php
namespace App\Services;

use App\Services\FeatureExtractionService; // Pastikan ini di-use
use App\Services\ModelService;             // Pastikan ini di-use
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage; // Ditambahkan untuk File::exists
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

// Tambahkan use statement untuk Rubix ML classes jika Anda menggunakan type hinting spesifik
// misalnya: use Rubix\ML\Probabilistic; use Rubix\ML\Transformers\Transformer;

class PredictionService
{
    // Konstanta untuk folder sementara di S3
    public const S3_UPLOAD_DIR_TEMP         = 'uploads_temp';
    private ?string $bestDetectorKeyCache   = null;
    private ?string $bestClassifierKeyCache = null;

    // Konstanta untuk bobot skor runtime
    private const RUNTIME_SCORE_WEIGHT_CONFIDENCE     = 0.5;
    private const RUNTIME_SCORE_WEIGHT_RELIABILITY    = 0.4;
    private const RUNTIME_SCORE_WEIGHT_MAJORITY_BONUS = 0.1;

    // Bobot untuk skor keandalan umum model
    private const RELIABILITY_WEIGHTS = [
        'f1_positive'        => 0.40,
        'accuracy'           => 0.20,
        'tpr'                => 0.15, // True Positive Rate (Recall Positif)
        'tnr'                => 0.15, // True Negative Rate (Recall Negatif / Specificity)
        'precision_positive' => 0.05,
    ];

    protected ModelService $modelService;

    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    private function findBestModelLogic(string $type): ?string
    {
        $allModelMetrics = $this->modelService->loadModelMetrics(null, $type);

        if (empty($allModelMetrics) || ! is_array($allModelMetrics)) {
            Log::warning("PredictionService: Data metrik kosong atau tidak valid untuk tipe '{$type}' saat mencari model terbaik statis.");
            return null;
        }

        $bestScore          = -1.0;
        $bestKey            = null;
        $weights            = self::RELIABILITY_WEIGHTS; // Menggunakan bobot yang sama untuk konsistensi
        $positiveClassLabel = ($type === 'detector') ? 'melon' : 'ripe';
        $negativeClassLabel = ($type === 'detector') ? 'non_melon' : 'unripe';

        foreach ($allModelMetrics as $key => $data) {
            if (empty($data) || ! is_array($data) || empty($data['metrics']) || ! is_array($data['metrics'])) {
                continue;
            }
            $metricsData     = $data['metrics'];
            $positiveMetrics = $metricsData[$positiveClassLabel] ?? ($metricsData['positive'] ?? null);
            $negativeMetrics = $metricsData[$negativeClassLabel] ?? ($metricsData['negative'] ?? null);

            $currentF1Pos        = (float) ($positiveMetrics['f1_score'] ?? 0.0);
            $currentAccuracy     = (float) ($metricsData['accuracy'] ?? 0.0);
            $currentPrecisionPos = (float) ($positiveMetrics['precision'] ?? 0.0);
            $currentTPR          = (float) ($positiveMetrics['recall'] ?? 0.0);
            $currentTNR          = (float) ($negativeMetrics['recall'] ?? 0.0);

            $combinedScore = ($currentF1Pos * ($weights['f1_positive'] ?? 0)) +
                ($currentAccuracy * ($weights['accuracy'] ?? 0)) +
                ($currentTPR * ($weights['tpr'] ?? 0)) +
                ($currentTNR * ($weights['tnr'] ?? 0)) +
                ($currentPrecisionPos * ($weights['precision_positive'] ?? 0));

            if ($combinedScore > $bestScore) {
                $bestScore = $combinedScore;
                $bestKey   = $key;
            }
        }

        if ($bestKey) {
            Log::info("PredictionService: Model terbaik statis ditemukan untuk tipe '{$type}': {$bestKey} dengan skor gabungan: {$bestScore}");
        } else {
            Log::warning("PredictionService: Tidak ada model terbaik statis yang dapat ditentukan untuk tipe '{$type}'.");
        }
        return $bestKey;
    }

    public function getBestDetectorKey(): ?string
    {
        if ($this->bestDetectorKeyCache === null) {
            $this->bestDetectorKeyCache = $this->findBestModelLogic('detector');
        }
        return $this->bestDetectorKeyCache;
    }

    public function getBestClassifierKey(): ?string
    {
        if ($this->bestClassifierKeyCache === null) {
            $this->bestClassifierKeyCache = $this->findBestModelLogic('classifier');
        }
        return $this->bestClassifierKeyCache;
    }

    public function performSingleDetection(string $modelKeyToUse, ?array $detectionFeatures): array
    {
        $result = [
            'model_key'     => $modelKeyToUse,
            'detected'      => false,
            'probabilities' => ['melon' => 0.0, 'non_melon' => 1.0],
            'success'       => false,
            'error'         => null,
            'metrics'       => null,
        ];

        if (empty($modelKeyToUse)) {
            $result['error'] = "Tidak ada model detektor yang ditentukan untuk prediksi.";
            Log::error($result['error']);
            return $result;
        }
        if (empty($detectionFeatures)) {
            $result['error'] = "Fitur deteksi kosong.";
            Log::warning($result['error'], ['model_key' => $modelKeyToUse]);
            return $result;
        }
        try {
            $detectorModel = $this->modelService->loadModel($modelKeyToUse);
            if (! $detectorModel || ! ($detectorModel instanceof \Rubix\ML\Probabilistic)) {
                throw new RuntimeException("Model detektor '{$modelKeyToUse}' tidak valid, tidak dapat dimuat, atau bukan Probabilistic.");
            }
            $scaler  = $this->getModelSpecificScaler($modelKeyToUse);
            $dataset = new \Rubix\ML\Datasets\Unlabeled([$detectionFeatures]);
            if ($scaler instanceof \Rubix\ML\Transformers\Transformer) {
                $dataset->apply($scaler);
            } else {
                Log::debug("Scaler tidak ditemukan atau bukan Transformer untuk {$modelKeyToUse}. Melanjutkan tanpa penskalaan spesifik.");
            }

            $probaRaw     = $detectorModel->proba($dataset)[0] ?? [];
            $probMelon    = isset($probaRaw['melon']) ? (float) $probaRaw['melon'] : 0.0;
            $probNonMelon = isset($probaRaw['non_melon']) ? (float) $probaRaw['non_melon'] : (1.0 - $probMelon);
            if (count($probaRaw) == 1) {
                if (isset($probaRaw['melon'])) {
                    $probNonMelon = 1.0 - $probMelon;
                } elseif (isset($probaRaw['non_melon'])) {
                    $probMelon = 1.0 - $probNonMelon;
                }

            }
            $probabilities = ['melon' => $probMelon, 'non_melon' => $probNonMelon];

            $loadedMetrics     = $this->modelService->loadModelMetrics($modelKeyToUse);
            $result['metrics'] = $loadedMetrics;

            $threshold = FeatureExtractionService::DETECTOR_THRESHOLD;
            if (is_array($loadedMetrics) && isset($loadedMetrics['metrics']) && is_array($loadedMetrics['metrics'])) {
                if (isset($loadedMetrics['metrics']['optimal_threshold'])) {
                    $threshold = (float) $loadedMetrics['metrics']['optimal_threshold'];
                }
            } elseif (is_array($loadedMetrics) && isset($loadedMetrics['optimal_threshold'])) {
                $threshold = (float) $loadedMetrics['optimal_threshold'];
            }
            Log::debug("Menggunakan threshold deteksi {$threshold} untuk model {$modelKeyToUse} (P(melon) = {$probMelon})");

            $result['detected']      = ($probabilities['melon'] >= $threshold);
            $result['probabilities'] = $probabilities;
            $result['success']       = true;

        } catch (Throwable $e) {
            Log::error("Error deteksi dengan model {$modelKeyToUse}", ['error' => $e->getMessage(), 'trace_snippet' => Str::limit($e->getTraceAsString(), 300)]);
            $result['error'] = "Deteksi gagal ({$modelKeyToUse}): " . Str::limit($e->getMessage(), 100);
        }
        return $result;
    }

    public function performSingleClassification(string $modelKeyToUse, ?array $colorFeatures): array
    {
        $result = [
            'model_key'              => $modelKeyToUse,
            'prediction'             => null,
            'probabilities'          => ['matang' => 0.0, 'belum_matang' => 0.0],
            'success'                => false,
            'error'                  => null,
            'scientific_explanation' => null,
            'metrics'                => null,
        ];
        if (empty($modelKeyToUse)) {
            $result['error'] = "Tidak ada model classifier yang ditentukan untuk prediksi.";
            Log::error($result['error']);
            return $result;
        }
        if (empty($colorFeatures)) {
            $result['error'] = "Fitur klasifikasi (warna) kosong.";
            Log::warning($result['error'], ['model_key' => $modelKeyToUse]);
            return $result;
        }
        try {
            $classifierModel = $this->modelService->loadModel($modelKeyToUse);
            if (! $classifierModel || ! ($classifierModel instanceof \Rubix\ML\Learner  && $classifierModel instanceof \Rubix\ML\Probabilistic)) {
                throw new RuntimeException("Model classifier '{$modelKeyToUse}' tidak valid, tidak dapat dimuat, atau bukan Probabilistic.");
            }
            $scaler  = $this->getModelSpecificScaler($modelKeyToUse);
            $dataset = new \Rubix\ML\Datasets\Unlabeled([$colorFeatures]);
            if ($scaler instanceof \Rubix\ML\Transformers\Transformer) {
                $dataset->apply($scaler);
            } else {
                Log::debug("Scaler tidak ditemukan atau bukan Transformer untuk {$modelKeyToUse}. Melanjutkan tanpa penskalaan spesifik.");
            }

            $predictions          = $classifierModel->predict($dataset);
            $predictedLabelRaw    = $predictions[0] ?? 'unripe';
            $probaRaw             = $classifierModel->proba($dataset)[0] ?? [];
            $predictionMap        = ['ripe' => 'matang', 'unripe' => 'belum_matang'];
            $result['prediction'] = $predictionMap[$predictedLabelRaw] ?? $predictedLabelRaw;

            $probMatang      = isset($probaRaw['ripe']) ? (float) $probaRaw['ripe'] : 0.0;
            $probBelumMatang = isset($probaRaw['unripe']) ? (float) $probaRaw['unripe'] : (1.0 - $probMatang);
            if (count($probaRaw) == 1) {
                if (isset($probaRaw['ripe'])) {
                    $probBelumMatang = 1.0 - $probMatang;
                } elseif (isset($probaRaw['unripe'])) {
                    $probMatang = 1.0 - $probBelumMatang;
                }

            }
            $result['probabilities'] = ['matang' => $probMatang, 'belum_matang' => $probBelumMatang];

            $result['scientific_explanation'] = $this->getScientificExplanation($colorFeatures, $predictedLabelRaw);
            $result['metrics']                = $this->modelService->loadModelMetrics($modelKeyToUse);
            $result['success']                = true;

        } catch (Throwable $e) {
            Log::error("Error klasifikasi dengan model {$modelKeyToUse}", ['error' => $e->getMessage(), 'trace_snippet' => Str::limit($e->getTraceAsString(), 300)]);
            $result['error'] = "Klasifikasi gagal ({$modelKeyToUse}): " . Str::limit($e->getMessage(), 100);
        }
        return $result;
    }

    public function runOtherDetectors(array $detectionFeatures, ?string $targetModelKey = null, array $excludeKeys = []): array
    {
        $otherDetectorResults = [];
        $modelsToRun          = $targetModelKey ? [$targetModelKey] : array_map(fn($k) => $k . '_detector', ModelService::BASE_MODEL_KEYS);
        // $bestDetectorOverall  = $this->getBestDetectorKey(); // Tidak lagi digunakan untuk eksklusi default di sini

        foreach ($modelsToRun as $modelKey) {
            if (empty($modelKey) || in_array($modelKey, $excludeKeys)) {
                continue;
            }
            $otherDetectorResults[$modelKey] = $this->performSingleDetection($modelKey, $detectionFeatures);
        }
        return $otherDetectorResults;
    }

    public function runOtherClassifiers(array $colorFeatures, ?string $targetModelKey = null, array $excludeKeys = []): array
    {
        $otherClassifierResults = [];
        $modelsToRun            = $targetModelKey ? [$targetModelKey] : array_map(fn($k) => $k . '_classifier', ModelService::BASE_MODEL_KEYS);
        // $bestClassifierOverall  = $this->getBestClassifierKey(); // Tidak lagi digunakan untuk eksklusi default

        foreach ($modelsToRun as $modelKey) {
            if (empty($modelKey) || in_array($modelKey, $excludeKeys)) {
                continue;
            }
            $otherClassifierResults[$modelKey] = $this->performSingleClassification($modelKey, $colorFeatures);
        }
        return $otherClassifierResults;
    }

    private function getAllModelReliabilityScores(string $type): array
    {
        $allModelMetrics   = $this->modelService->loadModelMetrics(null, $type);
        $reliabilityScores = [];

        if (empty($allModelMetrics) || ! is_array($allModelMetrics)) {
            Log::warning("[ReliabilityScores] Data metrik kosong atau tidak valid untuk tipe '{$type}'.");
            return [];
        }

        $weights            = self::RELIABILITY_WEIGHTS;
        $positiveClassLabel = ($type === 'detector') ? 'melon' : 'ripe';
        $negativeClassLabel = ($type === 'detector') ? 'non_melon' : 'unripe';

        foreach ($allModelMetrics as $modelKey => $data) {
            if (empty($data) || ! is_array($data) || empty($data['metrics']) || ! is_array($data['metrics'])) {
                Log::debug("[ReliabilityScores] Skipping model {$modelKey} due to missing or invalid metrics structure.");
                continue;
            }
            $metricsData     = $data['metrics'];
            $positiveMetrics = $metricsData[$positiveClassLabel] ?? ($metricsData['positive'] ?? null);
            $negativeMetrics = $metricsData[$negativeClassLabel] ?? ($metricsData['negative'] ?? null);

            $currentF1Pos        = 0.0;
            $currentAccuracy     = 0.0;
            $currentPrecisionPos = 0.0;
            $currentTPR          = 0.0;
            $currentTNR          = 0.0;

            if ($positiveMetrics && isset($positiveMetrics['f1_score'])) {
                $currentF1Pos        = (float) ($positiveMetrics['f1_score'] ?? 0.0);
                $currentAccuracy     = (float) ($metricsData['accuracy'] ?? 0.0);
                $currentPrecisionPos = (float) ($positiveMetrics['precision'] ?? 0.0);
                $currentTPR          = (float) ($positiveMetrics['recall'] ?? 0.0);
            }
            if ($negativeMetrics && isset($negativeMetrics['recall'])) {
                $currentTNR = (float) ($negativeMetrics['recall'] ?? 0.0);
            }

            $combinedScore = ($currentF1Pos * ($weights['f1_positive'] ?? 0)) +
                ($currentAccuracy * ($weights['accuracy'] ?? 0)) +
                ($currentTPR * ($weights['tpr'] ?? 0)) +
                ($currentTNR * ($weights['tnr'] ?? 0)) +
                ($currentPrecisionPos * ($weights['precision_positive'] ?? 0));

            $reliabilityScores[$modelKey] = round($combinedScore, 4);
        }
        Log::debug("[ReliabilityScores] Calculated for type '{$type}'", $reliabilityScores);
        return $reliabilityScores;
    }

    public function performDynamicDetection(?array $detectionFeatures): ?array
    {
        if (empty($detectionFeatures)) {
            Log::warning("[DynamicDetectionRevised] Fitur deteksi kosong.");
            return [
                'model_key'     => 'dynamic_choice_no_features',
                'detected'      => false,
                'probabilities' => ['melon' => 0.0, 'non_melon' => 1.0],
                'success'       => false,
                'error'         => 'Fitur deteksi kosong.',
                'metrics'       => null,
            ];
        }

        $allDetectorKeys            = array_map(fn($k) => $k . '_detector', ModelService::BASE_MODEL_KEYS);
        $detectorReliabilityScores  = $this->getAllModelReliabilityScores('detector');
        $candidateResults           = [];
        $predictionsForMajorityVote = [];

        Log::debug("[DynamicDetectionRevised] Memulai. Keandalan model detektor:", $detectorReliabilityScores);

        foreach ($allDetectorKeys as $modelKey) {
            $currentModelResult = $this->performSingleDetection($modelKey, $detectionFeatures);
            if ($currentModelResult['success']) {
                $modelReliability          = $detectorReliabilityScores[$modelKey] ?? 0.0;
                $predictedClassByThisModel = $currentModelResult['detected'] ? 'melon' : 'non_melon';
                $confidenceInPrediction    = $currentModelResult['probabilities'][$predictedClassByThisModel] ?? 0.0;
                $runtimeScore              = ($confidenceInPrediction * self::RUNTIME_SCORE_WEIGHT_CONFIDENCE) +
                    ($modelReliability * self::RUNTIME_SCORE_WEIGHT_RELIABILITY);
                $candidateResults[] = [
                    'model_result'                  => $currentModelResult,
                    'runtime_score'                 => $runtimeScore,
                    'predicted_class_by_this_model' => $predictedClassByThisModel,
                    'confidence_in_this_prediction' => $confidenceInPrediction,
                    'reliability_of_this_model'     => $modelReliability,
                ];
                $predictionsForMajorityVote[] = $predictedClassByThisModel;
                Log::debug("[DynamicDetectionRevised] Model {$modelKey}: Class={$predictedClassByThisModel}, PConf={$confidenceInPrediction}, Reliability={$modelReliability}, RuntimeScore={$runtimeScore}");
            } else {
                Log::warning("[DynamicDetectionRevised] Model {$modelKey} gagal memberikan prediksi sukses.", ['error' => $currentModelResult['error'] ?? 'N/A']);
            }
        }

        if (empty($candidateResults)) {
            Log::warning("[DynamicDetectionRevised] Tidak ada model yang memberikan prediksi sukses. Mencoba fallback ke model terbaik statis.");
            $staticBestDetectorKey = $this->getBestDetectorKey();
            if ($staticBestDetectorKey) {
                Log::info("[DynamicDetectionRevised Fallback] Menggunakan model terbaik statis: {$staticBestDetectorKey}");
                $fallbackResult = $this->performSingleDetection($staticBestDetectorKey, $detectionFeatures);
                if ($fallbackResult && ($fallbackResult['success'] ?? false)) {
                    $fallbackResult['selection_info_fallback_reason'] = 'Dynamic selection yielded no successful candidates; used static best model.';
                    return $fallbackResult;
                } else {
                    Log::error("[DynamicDetectionRevised Fallback] Model terbaik statis ({$staticBestDetectorKey}) juga gagal.", ['error' => $fallbackResult['error'] ?? 'N/A']);
                }
            } else {
                Log::error("[DynamicDetectionRevised Fallback] Tidak dapat menemukan model terbaik statis.");
            }
            return [
                'model_key'     => 'all_models_failed',
                'detected'      => false,
                'probabilities' => ['melon' => 0.0, 'non_melon' => 1.0],
                'success'       => false,
                'error'         => 'Semua model detektor (dinamis dan statis terbaik) gagal memberikan prediksi valid.',
                'metrics'       => null,
            ];
        }

        $majorityClass = null;
        if (! empty($predictionsForMajorityVote)) {
            $votes = array_count_values($predictionsForMajorityVote);
            arsort($votes);
            $majorityClass = key($votes);
            Log::debug("[DynamicDetectionRevised] Majority vote class: {$majorityClass}", ['votes' => $votes]);
            foreach ($candidateResults as $key => $candidate) {
                if ($candidate['predicted_class_by_this_model'] === $majorityClass) {
                    $candidateResults[$key]['runtime_score'] += self::RUNTIME_SCORE_WEIGHT_MAJORITY_BONUS;
                    Log::debug("[DynamicDetectionRevised] Bonus majority vote untuk {$candidate['model_result']['model_key']}. Skor baru: {$candidateResults[$key]['runtime_score']}");
                }
            }
        }

        usort($candidateResults, function ($a, $b) {
            return $b['runtime_score'] <=> $a['runtime_score'];
        });

        $chosenCandidate                = $candidateResults[0];
        $chosenResult                   = $chosenCandidate['model_result'];
        $chosenResult['selection_info'] = [
            'method'                          => 'dynamic_weighted_score_with_majority',
            'chosen_model_key'                => $chosenResult['model_key'],
            'final_runtime_score_achieved'    => $chosenCandidate['runtime_score'],
            'predicted_class_by_chosen_model' => $chosenCandidate['predicted_class_by_this_model'],
            'confidence_in_prediction'        => $chosenCandidate['confidence_in_this_prediction'],
            'model_reliability_score'         => $chosenCandidate['reliability_of_this_model'],
            'majority_vote_class'             => $majorityClass,
            'all_candidates_count'            => count($candidateResults),
        ];

        Log::info("[DynamicDetectionRevised] Model terpilih secara dinamis: " . $chosenResult['model_key'], $chosenResult['selection_info']);
        return $chosenResult;
    }

    public function performDynamicClassification(?array $colorFeatures): ?array
    {
        if (empty($colorFeatures)) {
            Log::warning("[DynamicClassificationRevised] Fitur warna kosong.");
            return [
                'model_key'     => 'dynamic_choice_no_features',
                'prediction'    => null,
                'probabilities' => ['matang' => 0.0, 'belum_matang' => 0.0],
                'success'       => false,
                'error'         => 'Fitur warna kosong.',
                'metrics'       => null,
            ];
        }

        $allClassifierKeys           = array_map(fn($k) => $k . '_classifier', ModelService::BASE_MODEL_KEYS);
        $classifierReliabilityScores = $this->getAllModelReliabilityScores('classifier');
        $candidateResults            = [];
        $predictionsForMajorityVote  = [];

        Log::debug("[DynamicClassificationRevised] Memulai. Keandalan model classifier:", $classifierReliabilityScores);

        foreach ($allClassifierKeys as $modelKey) {
            $currentModelResult = $this->performSingleClassification($modelKey, $colorFeatures);
            if ($currentModelResult['success'] && isset($currentModelResult['prediction'])) {
                $modelReliability          = $classifierReliabilityScores[$modelKey] ?? 0.0;
                $predictedClassByThisModel = $currentModelResult['prediction'];
                $confidenceInPrediction    = $currentModelResult['probabilities'][$predictedClassByThisModel] ?? 0.0;
                $runtimeScore              = ($confidenceInPrediction * self::RUNTIME_SCORE_WEIGHT_CONFIDENCE) +
                    ($modelReliability * self::RUNTIME_SCORE_WEIGHT_RELIABILITY);
                $candidateResults[] = [
                    'model_result'                  => $currentModelResult,
                    'runtime_score'                 => $runtimeScore,
                    'predicted_class_by_this_model' => $predictedClassByThisModel,
                    'confidence_in_this_prediction' => $confidenceInPrediction,
                    'reliability_of_this_model'     => $modelReliability,
                ];
                $predictionsForMajorityVote[] = $predictedClassByThisModel;
                Log::debug("[DynamicClassificationRevised] Model {$modelKey}: Class={$predictedClassByThisModel}, PConf={$confidenceInPrediction}, Reliability={$modelReliability}, RuntimeScore={$runtimeScore}");
            } else {
                Log::warning("[DynamicClassificationRevised] Model {$modelKey} gagal memberikan prediksi sukses.", ['error' => $currentModelResult['error'] ?? 'N/A']);
            }
        }

        if (empty($candidateResults)) {
            Log::warning("[DynamicClassificationRevised] Tidak ada model yang memberikan prediksi sukses. Mencoba fallback ke model terbaik statis.");
            $staticBestClassifierKey = $this->getBestClassifierKey();
            if ($staticBestClassifierKey) {
                Log::info("[DynamicClassificationRevised Fallback] Menggunakan model terbaik statis: {$staticBestClassifierKey}");
                $fallbackResult = $this->performSingleClassification($staticBestClassifierKey, $colorFeatures);
                if ($fallbackResult && ($fallbackResult['success'] ?? false)) {
                    $fallbackResult['selection_info_fallback_reason'] = 'Dynamic selection yielded no successful candidates; used static best model.';
                    return $fallbackResult;
                } else {
                    Log::error("[DynamicClassificationRevised Fallback] Model terbaik statis ({$staticBestClassifierKey}) juga gagal.", ['error' => $fallbackResult['error'] ?? 'N/A']);
                }
            } else {
                Log::error("[DynamicClassificationRevised Fallback] Tidak dapat menemukan model terbaik statis.");
            }
            return [ // Error jika semua gagal
                'model_key'     => 'all_models_failed',
                'prediction'    => null,
                'probabilities' => ['matang' => 0.0, 'belum_matang' => 0.0],
                'success'       => false,
                'error'         => 'Semua model classifier (dinamis dan statis terbaik) gagal memberikan prediksi valid.',
                'metrics'       => null,
            ];
        }

        $majorityClass = null;
        if (! empty($predictionsForMajorityVote)) {
            $votes = array_count_values($predictionsForMajorityVote);
            arsort($votes);
            $majorityClass = key($votes);
            Log::debug("[DynamicClassificationRevised] Majority vote class: {$majorityClass}", ['votes' => $votes]);
            foreach ($candidateResults as $key => $candidate) {
                if ($candidate['predicted_class_by_this_model'] === $majorityClass) {
                    $candidateResults[$key]['runtime_score'] += self::RUNTIME_SCORE_WEIGHT_MAJORITY_BONUS;
                    Log::debug("[DynamicClassificationRevised] Bonus majority vote untuk {$candidate['model_result']['model_key']}. Skor baru: {$candidateResults[$key]['runtime_score']}");
                }
            }
        }

        usort($candidateResults, function ($a, $b) {
            return $b['runtime_score'] <=> $a['runtime_score'];
        });

        $chosenCandidate                = $candidateResults[0];
        $chosenResult                   = $chosenCandidate['model_result'];
        $chosenResult['selection_info'] = [
            'method'                          => 'dynamic_weighted_score_with_majority',
            'chosen_model_key'                => $chosenResult['model_key'],
            'final_runtime_score_achieved'    => $chosenCandidate['runtime_score'],
            'predicted_class_by_chosen_model' => $chosenCandidate['predicted_class_by_this_model'],
            'confidence_in_prediction'        => $chosenCandidate['confidence_in_this_prediction'],
            'model_reliability_score'         => $chosenCandidate['reliability_of_this_model'],
            'majority_vote_class'             => $majorityClass,
            'all_candidates_count'            => count($candidateResults),
        ];

        Log::info("[DynamicClassificationRevised] Model terpilih secara dinamis: " . $chosenResult['model_key'], $chosenResult['selection_info']);
        return $chosenResult;
    }

    /**
     * Menghitung suara mayoritas untuk detektor berdasarkan akumulasi probabilitas.
     *
     * @param array $detectorResults Hasil dari beberapa model detektor.
     * Setiap hasil harus berupa array dengan ['success' => bool, 'probabilities' => ['melon' => float, 'non_melon' => float]].
     * @return array|null Array berisi ['predicted_class', 'confidence', 'details'] atau null jika tidak ada hasil valid.
     */
    public function calculateDetectorMajorityVote(array $detectorResults): ?array
    {
        if (empty($detectorResults)) {
            return null;
        }

        $accumulatedProbabilities = ['melon' => 0.0, 'non_melon' => 0.0];
        $validModelCount          = 0;

        foreach ($detectorResults as $modelKey => $result) {
            // Pastikan result adalah array dan memiliki struktur yang diharapkan
            if (is_array($result) && ($result['success'] ?? false) && isset($result['probabilities']) && is_array($result['probabilities'])) {
                $probMelon    = (float) ($result['probabilities']['melon'] ?? 0.0);
                $probNonMelon = (float) ($result['probabilities']['non_melon'] ?? 0.0);

                // Normalisasi sederhana jika hanya satu probabilitas diberikan (umumnya tidak terjadi jika model Probabilistic Rubix ML)
                if (isset($result['probabilities']['melon']) && ! isset($result['probabilities']['non_melon']) && $probMelon >= 0 && $probMelon <= 1) {
                    $probNonMelon = 1.0 - $probMelon;
                } elseif (! isset($result['probabilities']['melon']) && isset($result['probabilities']['non_melon']) && $probNonMelon >= 0 && $probNonMelon <= 1) {
                    $probMelon = 1.0 - $probNonMelon;
                }
                // Anda bisa menambahkan validasi lebih lanjut di sini jika probabilitas tidak berjumlah 1

                $accumulatedProbabilities['melon'] += $probMelon;
                $accumulatedProbabilities['non_melon'] += $probNonMelon;
                $validModelCount++;
            } else {
                Log::debug("[MajorityVoteDetector] Skipping invalid result for model {$modelKey}", ['result_preview' => Str::limit(json_encode($result), 100)]);
            }
        }

        if ($validModelCount === 0) {
            return ['predicted_class' => 'N/A', 'confidence' => 0.0, 'details' => ['message' => 'Tidak ada prediksi model yang valid untuk diakumulasikan.']];
        }

        $winningClass = 'seri'; // Default jika probabilitas sama
        if ($accumulatedProbabilities['melon'] > $accumulatedProbabilities['non_melon']) {
            $winningClass = 'melon';
        } elseif ($accumulatedProbabilities['non_melon'] > $accumulatedProbabilities['melon']) {
            $winningClass = 'non_melon';
        }

        $totalAccumulatedProbability = $accumulatedProbabilities['melon'] + $accumulatedProbabilities['non_melon'];
        $ensembleConfidence          = 0.0;

        if ($totalAccumulatedProbability > 0 && $winningClass !== 'seri') {
            // Keyakinan dihitung sebagai proporsi akumulasi probabilitas kelas pemenang terhadap total akumulasi
            $ensembleConfidence = $accumulatedProbabilities[$winningClass] / $totalAccumulatedProbability;
        } elseif ($winningClass === 'seri' && $totalAccumulatedProbability > 0) {
            // Jika seri, keyakinan bisa 0.5 atau berdasarkan salah satu kelas (keduanya sama)
            $ensembleConfidence = 0.5;
        }

        return [
            'predicted_class' => $winningClass,
            'confidence'      => round($ensembleConfidence, 4),
            'details'         => [
                'accumulated_melon_prob'                                    => round($accumulatedProbabilities['melon'], 4),
                'accumulated_non_melon_prob'                                => round($accumulatedProbabilities['non_melon'], 4),
                'valid_model_count'                                         => $validModelCount,
                'total_accumulated_probability_for_normalization_if_needed' => round($totalAccumulatedProbability, 4), // Ini berguna jika Anda ingin menampilkan rata-rata prob, bukan normalisasi
            ],
        ];
    }

    /**
     * Menghitung suara mayoritas untuk classifier berdasarkan akumulasi probabilitas.
     *
     * @param array $classifierResults Hasil dari beberapa model classifier.
     * Setiap hasil ['success' => bool, 'probabilities' => ['matang' => float, 'belum_matang' => float]].
     * @return array|null Array berisi ['predicted_class', 'confidence', 'details'] atau null.
     */
    public function calculateMajorityVote(array $classifierResults): ?array
    {
        if (empty($classifierResults)) {
            return null;
        }

        $accumulatedProbabilities = ['matang' => 0.0, 'belum_matang' => 0.0];
        $validModelCount          = 0;

        foreach ($classifierResults as $modelKey => $result) {
            if (is_array($result) && ($result['success'] ?? false) && isset($result['probabilities']) && is_array($result['probabilities'])) {
                $probMatang      = (float) ($result['probabilities']['matang'] ?? 0.0);
                $probBelumMatang = (float) ($result['probabilities']['belum_matang'] ?? 0.0);

                if (isset($result['probabilities']['matang']) && ! isset($result['probabilities']['belum_matang']) && $probMatang >= 0 && $probMatang <= 1) {
                    $probBelumMatang = 1.0 - $probMatang;
                } elseif (! isset($result['probabilities']['matang']) && isset($result['probabilities']['belum_matang']) && $probBelumMatang >= 0 && $probBelumMatang <= 1) {
                    $probMatang = 1.0 - $probBelumMatang;
                }

                $accumulatedProbabilities['matang'] += $probMatang;
                $accumulatedProbabilities['belum_matang'] += $probBelumMatang;
                $validModelCount++;
            } else {
                Log::debug("[MajorityVoteClassifier] Skipping invalid result for model {$modelKey}", ['result_preview' => Str::limit(json_encode($result), 100)]);
            }
        }

        if ($validModelCount === 0) {
            return ['predicted_class' => 'N/A', 'confidence' => 0.0, 'details' => ['message' => 'Tidak ada prediksi model yang valid untuk diakumulasikan.']];
        }

        $winningClass = 'seri';
        if ($accumulatedProbabilities['matang'] > $accumulatedProbabilities['belum_matang']) {
            $winningClass = 'matang';
        } elseif ($accumulatedProbabilities['belum_matang'] > $accumulatedProbabilities['matang']) {
            $winningClass = 'belum_matang';
        }

        $totalAccumulatedProbability = $accumulatedProbabilities['matang'] + $accumulatedProbabilities['belum_matang'];
        $ensembleConfidence          = 0.0;

        if ($totalAccumulatedProbability > 0 && $winningClass !== 'seri') {
            $ensembleConfidence = $accumulatedProbabilities[$winningClass] / $totalAccumulatedProbability;
        } elseif ($winningClass === 'seri' && $totalAccumulatedProbability > 0) {
            $ensembleConfidence = 0.5;
        }

        return [
            'predicted_class' => $winningClass,
            'confidence'      => round($ensembleConfidence, 4),
            'details'         => [
                'accumulated_matang_prob'                                   => round($accumulatedProbabilities['matang'], 4),
                'accumulated_belum_matang_prob'                             => round($accumulatedProbabilities['belum_matang'], 4),
                'valid_model_count'                                         => $validModelCount,
                'total_accumulated_probability_for_normalization_if_needed' => round($totalAccumulatedProbability, 4),

            ],
        ];
    }

    private function getModelSpecificScaler(string $modelKey): ?\Rubix\ML\Transformers\Transformer
    {
        $scalerKey = $modelKey . '_scaler';
        $scaler    = $this->modelService->loadModel($scalerKey);
        if ($scaler instanceof \Rubix\ML\Transformers\Transformer) {
            return $scaler;
        }
        Log::debug("Scaler spesifik tidak ditemukan atau bukan Transformer untuk {$scalerKey}.", ['model_key' => $modelKey]);
        return null;
    }

    public function getScientificExplanation(array $colorFeatures, string $predictionRawLabel) : string
    {
        if (empty(FeatureExtractionService::CLASSIFIER_FEATURE_NAMES) || count($colorFeatures) !== count(FeatureExtractionService::CLASSIFIER_FEATURE_NAMES)) {
            return "<p class='text-muted small'>Penjelasan ilmiah tidak dapat dibuat (data fitur warna tidak lengkap).</p>";
        }
        try {
            $features = @array_combine(FeatureExtractionService::CLASSIFIER_FEATURE_NAMES, $colorFeatures);
            if ($features === false) {
                return "<p class='text-muted small'>Gagal memproses fitur untuk penjelasan.</p>";
            }

            $rMean = round($features['R_mean'] ?? 0, 1);
            $gMean = round($features['G_mean'] ?? 0, 1);
            $bMean = round($features['B_mean'] ?? 0, 1);
            $rStd  = round($features['R_std'] ?? 0, 1);
            $gStd  = round($features['G_std'] ?? 0, 1);
            $bStd  = round($features['B_std'] ?? 0, 1);

            $textureSummary  = [];
            $glcmFeatureKeys = array_filter(array_keys($features), function ($key) {
                return Str::startsWith($key, ['contrast', 'correlation', 'energy', 'homogeneity']);
            });

            if (! empty($glcmFeatureKeys)) {
                $avgContrast      = 0;
                $countContrast    = 0;
                $avgCorrelation   = 0;
                $countCorrelation = 0;
                $avgEnergy        = 0;
                $countEnergy      = 0;
                $avgHomogeneity   = 0;
                $countHomogeneity = 0;

                foreach ($glcmFeatureKeys as $key) {
                    if (Str::startsWith($key, 'contrast')) {$avgContrast += ($features[$key] ?? 0);
                        $countContrast++;}
                    if (Str::startsWith($key, 'correlation')) {$avgCorrelation += ($features[$key] ?? 0);
                        $countCorrelation++;}
                    if (Str::startsWith($key, 'energy')) {$avgEnergy += ($features[$key] ?? 0);
                        $countEnergy++;}
                    if (Str::startsWith($key, 'homogeneity')) {$avgHomogeneity += ($features[$key] ?? 0);
                        $countHomogeneity++;}
                }
                if ($countContrast > 0) {$textureSummary['contrast'] = round($avgContrast / $countContrast, 2);}
                if ($countCorrelation > 0) {$textureSummary['correlation'] = round($avgCorrelation / $countCorrelation, 2);}
                if ($countEnergy > 0) {$textureSummary['energy'] = round($avgEnergy / $countEnergy, 2);}
                if ($countHomogeneity > 0) {$textureSummary['homogeneity'] = round($avgHomogeneity / $countHomogeneity, 2);}
            }

            $explanationPoints  = [];
            $predictedClassText = ($predictionRawLabel === 'ripe') ? 'Matang' : 'Belum Matang';

            if ($predictionRawLabel === 'ripe') {
                $explanationPoints[] = "Warna dominan cenderung ke arah kuning/oranye (R:{$rMean}, G:{$gMean}, B:{$bMean}), mengindikasikan degradasi klorofil dan munculnya pigmen karotenoid.";
                if ($rMean > $gMean && $rMean > ($bMean * 1.1)) {$explanationPoints[] = "Intensitas merah yang relatif tinggi dibandingkan hijau menunjukkan proses pematangan yang lanjut.";}
                if ($gMean < 100 && $rMean > 120) {$explanationPoints[] = "Rendahnya intensitas hijau memperkuat dugaan kematangan.";}
            } else { // unripe
                $explanationPoints[] = "Warna dominan masih hijau (G:{$gMean} tinggi, R:{$rMean} & B:{$bMean} lebih rendah), menandakan kandungan klorofil yang masih signifikan.";
                if ($gMean > $rMean && $gMean > $bMean) {$explanationPoints[] = "Tingginya intensitas hijau dibandingkan warna lain adalah ciri khas buah yang belum matang.";}
                if ($rStd > 15 && $gStd > 15) {$explanationPoints[] = "Variasi warna pada permukaan ({R_std: $rStd, G_std: $gStd}) mungkin menunjukkan tekstur jaring yang belum sepenuhnya terbentuk atau warna dasar yang belum merata.";}
            }

            if (! empty($textureSummary)) {
                if ($predictionRawLabel === 'ripe') {
                    if (isset($textureSummary['contrast']) && $textureSummary['contrast'] > 100) {$explanationPoints[] = "Tekstur permukaan menunjukkan kontras yang cukup tinggi (rata-rata Kontras GLCM: {$textureSummary['contrast']}), yang bisa jadi karena jaring yang sudah jelas dan menonjol.";}
                    if (isset($textureSummary['homogeneity']) && $textureSummary['homogeneity'] < 0.2) {$explanationPoints[] = "Homogenitas tekstur yang relatif rendah (rata-rata Homogenitas GLCM: {$textureSummary['homogeneity']}) dapat disebabkan oleh pola jaring yang matang dan tidak seragam sempurna.";}
                } else { // unripe
                    if (isset($textureSummary['energy']) && $textureSummary['energy'] > 0.02) {$explanationPoints[] = "Energi tekstur yang lebih tinggi (rata-rata Energi GLCM: {$textureSummary['energy']}) mungkin mengindikasikan permukaan yang lebih seragam atau jaring yang belum begitu berkembang.";}
                    if (isset($textureSummary['correlation']) && $textureSummary['correlation'] > 0.8) {$explanationPoints[] = "Korelasi piksel yang tinggi (rata-rata Korelasi GLCM: {$textureSummary['correlation']}) bisa menunjukkan pola jaring yang mulai terbentuk namun masih teratur.";}
                }
            }

            $kesimpulan = "Secara keseluruhan, berdasarkan analisis fitur warna ";
            if (! empty($textureSummary)) {$kesimpulan .= "dan tekstur permukaan, ";}
            $kesimpulan .= "model menyimpulkan bahwa melon ini kemungkinan besar <strong>{$predictedClassText}</strong>.";
            $explanationPoints[] = $kesimpulan;

            if (empty($explanationPoints)) {return "<p class='text-muted small'>Tidak ada poin penjelasan spesifik untuk {$predictedClassText} berdasarkan fitur yang ada.</p>";}

            $html = "<h6 class='small fw-bold'>Analisis Fitur (Prediksi: {$predictedClassText}):</h6><ul class='list-unstyled small mb-0 ms-2'>";
            foreach ($explanationPoints as $point) {$html .= "<li class='mb-1'><i class='fas fa-palette fa-fw me-1 text-primary opacity-75'></i> {$point}</li>";}
            $html .= "</ul>";
            return $html;

        } catch (Throwable $e) {
            Log::error("Error membuat penjelasan ilmiah.", ['error' => $e->getMessage()]);
            return "<p class='text-muted small'>Terjadi kesalahan saat membuat penjelasan ilmiah.</p>";
        }
    }

    public function runPythonBboxEstimator(string $s3ImagePath): ?array
    {
        Log::info("[PredictionService] Memulai estimasi BBox Python untuk S3 path.", ['s3_image_path' => $s3ImagePath]);
        $pythonScriptPath        = base_path('scripts/estimate_bbox.py');
        $pythonExecutable        = env('PYTHON_EXECUTABLE_PATH', 'python');
        $localTempImageForPython = null;

        try {
            if (! Storage::disk('s3')->exists($s3ImagePath)) {
                Log::error("[PredictionService] File gambar S3 tidak ditemukan untuk estimasi BBox.", ['s3_path' => $s3ImagePath]);
                return ['success' => false, 'message' => 'File gambar sumber tidak ditemukan di S3.', 'bboxes' => []];
            }

            $imageContent = Storage::disk('s3')->get($s3ImagePath);
            if ($imageContent === null) {
                Log::error("[PredictionService] Gagal mengambil konten gambar dari S3 untuk estimasi BBox.", ['s3_path' => $s3ImagePath]);
                return ['success' => false, 'message' => 'Gagal membaca gambar sumber dari S3.', 'bboxes' => []];
            }

            $extension               = pathinfo($s3ImagePath, PATHINFO_EXTENSION) ?: 'jpg';
            $localTempImageForPython = tempnam(sys_get_temp_dir(), "s3_bbox_py_") . '.' . $extension;
            if (file_put_contents($localTempImageForPython, $imageContent) === false) {
                Log::error("[PredictionService] Gagal menulis gambar S3 ke file temporary untuk Python.", ['s3_path' => $s3ImagePath, 'temp_path' => $localTempImageForPython]);
                $localTempImageForPython = null; // Set ke null jika gagal tulis
            } else {
                Log::info("[PredictionService] Gambar S3 disimpan ke temporary untuk Python.", ['s3_path' => $s3ImagePath, 'temp_path' => $localTempImageForPython]);
            }

            if ($localTempImageForPython === null) { // Cek setelah blok if-else
                return ['success' => false, 'message' => 'Gagal menyiapkan file gambar untuk skrip Python.', 'bboxes' => []];
            }

            if (! File::exists($pythonScriptPath)) { // Gunakan File::exists
                Log::error("[PredictionService] Skrip Python Bbox tidak ditemukan.", ['path' => $pythonScriptPath]);
                return ['success' => false, 'message' => 'Skrip estimasi BBox tidak ditemukan di server.', 'bboxes' => []];
            }

            $command = [$pythonExecutable, $pythonScriptPath, $localTempImageForPython];
            $process = new \Symfony\Component\Process\Process($command, base_path(), null, null, 60.0);

            try {
                // Modifikasi untuk menangkap output secara manual
                $process->run();

                $stdout = $process->getOutput();
                $stderr = $process->getErrorOutput();

                Log::debug("[PredictionService] Python STDOUT for " . basename($s3ImagePath) . ": " . Str::limit($stdout, 500));
                Log::debug("[PredictionService] Python STDERR for " . basename($s3ImagePath) . ": " . Str::limit($stderr, 500));

                if (! $process->isSuccessful()) {
                    Log::error("[PredictionService] Eksekusi skrip Python Bbox TIDAK SUKSES.", [
                        's3_path'        => $s3ImagePath,
                        'command'        => $process->getCommandLine(),
                        'exit_code'      => $process->getExitCode(),
                        'stdout_on_fail' => Str::limit($stdout, 500),
                        'stderr_on_fail' => Str::limit($stderr, 500),
                    ]);
                    return ['success' => false, 'message' => 'Eksekusi skrip Python gagal (kode: ' . $process->getExitCode() . '). Cek log server.', 'bboxes' => [], 'debug_stderr' => $stderr];
                }

                $outputJson = trim($stdout);
                // Log::debug("[PredictionService] Output Python Bbox:", ['stdout_length' => strlen($outputJson), 'stdout_preview' => Str::limit($outputJson, 200)]); // Duplikat, bisa dihapus
                $result = json_decode($outputJson, true);

                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($result) || ! ($result['success'] ?? false) || ! isset($result['bboxes']) || ! is_array($result['bboxes'])) {
                    Log::warning("[PredictionService] Output Python Bbox tidak valid/sukses dari skrip.", [
                        'result_raw' => Str::limit($outputJson, 200),
                        'json_error' => json_last_error_msg(),
                    ]);
                    return ['success' => false, 'message' => 'Output skrip Python tidak valid atau gagal.', 'bboxes' => []];
                }

                if (empty($result['bboxes'])) {
                    Log::info("[PredictionService] Tidak ada Bbox dari Python.", ['image' => basename($s3ImagePath)]);
                    return ['success' => true, 'bboxes' => []];
                }

                $firstBboxAbs = $result['bboxes'][0];
                if (! isset($firstBboxAbs['x'], $firstBboxAbs['y'], $firstBboxAbs['w'], $firstBboxAbs['h'])) {
                    Log::warning("[PredictionService] Format Bbox Absolut Python tidak sesuai dari skrip.", ['bbox_abs' => $firstBboxAbs]);
                    return ['success' => false, 'message' => 'Format Bbox dari skrip Python tidak sesuai.', 'bboxes' => []];
                }

                $imageSize = @getimagesize($localTempImageForPython);
                if ($imageSize === false || ($imageSize[0] ?? 0) <= 0 || ($imageSize[1] ?? 0) <= 0) {
                    Log::error("[PredictionService] Gagal dapat dimensi gambar dari FILE TEMPORARY LOKAL untuk konversi Bbox.", ['path' => $localTempImageForPython, 's3_original_path' => $s3ImagePath]);
                    return ['success' => false, 'message' => 'Gagal mendapatkan dimensi gambar lokal untuk konversi BBox.', 'bboxes' => []];
                }

                $imgW    = (float) $imageSize[0];
                $imgH    = (float) $imageSize[1];
                $absX    = (float) ($firstBboxAbs['x']);
                $absY    = (float) ($firstBboxAbs['y']);
                $absW    = (float) ($firstBboxAbs['w']);
                $absH    = (float) ($firstBboxAbs['h']);
                $bboxRel = [
                    'cx' => round(($absX + $absW / 2.0) / $imgW, 6),
                    'cy' => round(($absY + $absH / 2.0) / $imgH, 6),
                    'w'  => round($absW / $imgW, 6),
                    'h'  => round($absH / $imgH, 6),
                ];

                if ($bboxRel['cx'] >= 0 && $bboxRel['cx'] <= 1 && $bboxRel['cy'] >= 0 && $bboxRel['cy'] <= 1 && $bboxRel['w'] > 0 && $bboxRel['w'] <= 1 && $bboxRel['h'] > 0 && $bboxRel['h'] <= 1) {
                    Log::info("[PredictionService] Bbox berhasil dikonversi ke relatif.", ['bbox_rel' => $bboxRel]);
                    return ['success' => true, 'bboxes' => [$bboxRel]]; // Hanya Bbox pertama
                }

                Log::warning("[PredictionService] Hasil konversi Bbox relatif Python tidak valid.", ['bbox_rel' => $bboxRel]);
                return ['success' => false, 'message' => 'Konversi Bbox ke format relatif gagal.', 'bboxes' => []];

            } catch (\Symfony\Component\Process\Exception\ProcessFailedException $e) { // Seharusnya tidak terpicu jika pakai run() dan cek isSuccessful()
                Log::error("[PredictionService] Eksekusi skrip Python Bbox gagal (ProcessFailedException).", ['command' => $e->getProcess()->getCommandLine(), 'error' => $e->getMessage(), 'stderr' => Str::limit($e->getProcess()->getErrorOutput(), 500)]);
                return ['success' => false, 'message' => 'Eksekusi skrip Python estimasi BBox gagal.', 'bboxes' => []];
            }
        } catch (Throwable $e) {
            Log::error("[PredictionService] Exception selama eksekusi atau pemrosesan Python.", [
                's3_path' => $s3ImagePath,
                'error'   => $e->getMessage(),
                'trace'   => Str::limit($e->getTraceAsString(), 300),
            ]);
            return ['success' => false, 'message' => 'Error internal server saat estimasi Bbox (exception).', 'bboxes' => []];
        } finally {
            if ($localTempImageForPython && file_exists($localTempImageForPython)) {
                @unlink($localTempImageForPython);
                Log::info("[PredictionService] File gambar temporary dari S3 untuk Python dihapus.", ['temp_path' => $localTempImageForPython]);
            }
        }
    }
} // <--- INI ADALAH AKHIR DARI CLASS PREDICTIONSERVICE
