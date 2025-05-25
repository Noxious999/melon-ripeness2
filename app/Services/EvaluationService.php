<?php

// File: app/Services/EvaluationService.php

namespace App\Services;

use Carbon\Carbon; // Tambahkan Log jika ingin melaporkan error di sini
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str; // Gunakan Exception standar
use ReflectionClass;
use Throwable;

class EvaluationService
{
    public const FLASH_RESULT = 'result';
    public const FLASH_ERROR  = 'error';

    protected ModelService $modelService; // Inject ModelService

    public function __construct(ModelService $modelService)
    {
        $this->modelService = $modelService;
    }

    /**
     * Memvalidasi hasil deteksi bounding box dan confidence score.
     * @param array{x: float, y: float, w: float, h: float} $bbox Bounding box (format [x_min, y_min, width, height] dalam piksel absolut).
     * @param float $confidence Skor kepercayaan deteksi (0-1).
     * @param array{width: int, height: int} $imageSize Dimensi gambar asli.
     * @return bool True jika valid, false jika tidak.
     */
    public function validateDetection(array $bbox, float $confidence, array $imageSize): bool
    {
        $minWidth            = 10;
        $minHeight           = 10;
        $confidenceThreshold = 0.7;

        $x_min  = $bbox['x'] ?? -1;
        $y_min  = $bbox['y'] ?? -1;
        $width  = $bbox['w'] ?? -1;
        $height = $bbox['h'] ?? -1;

        $valid = true;
        $valid = $valid && ($x_min >= 0 && $x_min < $imageSize['width']);
        $valid = $valid && ($y_min >= 0 && $y_min < $imageSize['height']);
        $valid = $valid && ($width > $minWidth && ($x_min + $width) <= $imageSize['width']);
        $valid = $valid && ($height > $minHeight && ($y_min + $height) <= $imageSize['height']);
        $valid = $valid && ($confidence >= $confidenceThreshold);
        return $valid;
    }

    /**
     * Menghitung metrik evaluasi biner (akurasi, presisi, recall, F1) dari array label.
     * @param list<string> $actual Label aktual.
     * @param list<string> $predicted Label prediksi.
     * @param string $positive Label yang dianggap positif.
     * @return array{accuracy: float, precision: float, recall: float, f1_score: float}
     */
    public function evaluateDetection(array $actual, array $predicted, string $positive = 'melon'): array
    {
        if (count($actual) !== count($predicted)) {
            Log::error("EvaluationService::evaluateDetection: Actual and predicted label counts do not match.", ['actual_count' => count($actual), 'predicted_count' => count($predicted)]);
            return ['accuracy' => 0.0, 'precision' => 0.0, 'recall' => 0.0, 'f1_score' => 0.0];
        }
        if (empty($actual)) {
            return ['accuracy' => 0.0, 'precision' => 0.0, 'recall' => 0.0, 'f1_score' => 0.0];
        }

        $tp = $fp = $fn = $tn = 0;
        foreach ($actual as $i => $truth) {
            $guess = $predicted[$i] ?? null;

            if ($truth === $positive) {
                if ($guess === $positive) {
                    $tp++;
                } else {
                    $fn++;
                }
            } else {
                if ($guess === $positive) {
                    $fp++;
                } else {
                    $tn++;
                }
            }
        }

        $total     = $tp + $tn + $fp + $fn;
        $accuracy  = $total > 0 ? ($tp + $tn) / $total : 0.0;
        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall    = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $f1        = ($precision + $recall) > 0 ? 2 * ($precision * $recall) / ($precision + $recall) : 0.0;

        return [
            'accuracy'  => round($accuracy, 4),
            'precision' => round($precision, 4),
            'recall'    => round($recall, 4),
            'f1_score'  => round($f1, 4),
        ];
    }

    /**
     * Menggabungkan probabilitas dari dua sumber menggunakan aturan mayoritas/rata-rata (contoh logika ensemble).
     * @param array{ripe?: float, unripe?: float} $proba1 Probabilitas dari model 1.
     * @param array{ripe?: float, unripe?: float} $proba2 Probabilitas dari model 2.
     * @return string Prediksi hasil ('ripe', 'unripe', 'ambiguous', 'invalid').
     */
    public function majorityVote(array $proba1, array $proba2): string
    {
        $proba1_ripe          = (float) ($proba1['ripe'] ?? 0.0);
        $proba1_unripe        = (float) ($proba1['unripe'] ?? 0.0);
        $proba2_ripe          = (float) ($proba2['ripe'] ?? 0.0);
        $proba2_unripe        = (float) ($proba2['unripe'] ?? 0.0);
        $threshold_difference = 0.1;
        $threshold_confidence = 0.6;
        $avg                  = [
            'ripe'   => ($proba1_ripe + $proba2_ripe) / 2,
            'unripe' => ($proba1_unripe + $proba2_unripe) / 2,
        ];
        if (max($avg['ripe'], $avg['unripe']) < $threshold_confidence) {
            return 'invalid';
        }
        if (abs($avg['ripe'] - $avg['unripe']) < $threshold_difference) {
            return 'ambiguous';
        }
        return $avg['ripe'] > $avg['unripe'] ? 'ripe' : 'unripe';
    }

    /**
     * Menghitung presisi, recall, dan F1-score untuk kelas positif tertentu.
     * @param list<string> $trueLabels Label aktual.
     * @param list<string> $predictedLabels Label prediksi.
     * @param string $positiveLabel Label yang dianggap positif.
     * @return array{0: float, 1: float, 2: float} Array berisi [precision, recall, f1].
     */
    public function calculateMetrics(array $trueLabels, array $predictedLabels, string $positiveLabel): array
    {
        if (count($trueLabels) !== count($predictedLabels)) {
            Log::error("EvaluationService::calculateMetrics: Actual and predicted label counts do not match.", ['actual_count' => count($trueLabels), 'predicted_count' => count($predictedLabels)]);
            return [0.0, 0.0, 0.0];
        }
        if (empty($trueLabels)) {
            return [0.0, 0.0, 0.0];
        }

        $tp = $fp = $fn = 0;
        foreach ($trueLabels as $i => $actual) {
            $predicted = $predictedLabels[$i] ?? null;

            if ($actual === $positiveLabel) {
                if ($predicted === $positiveLabel) {
                    $tp++;
                } else {
                    $fn++;
                }
            } else {
                if ($predicted === $positiveLabel) {
                    $fp++;
                }
            }
        }

        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall    = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $f1        = ($precision + $recall) > 0 ? 2 * ($precision * $recall) / ($precision + $recall) : 0.0;

        return [round($precision, 4), round($recall, 4), round($f1, 4)];
    }

    /**
     * Menghitung confusion matrix untuk klasifikasi biner.
     * Mengembalikan matriks 2x2: [[TP, FN], [FP, TN]].
     * Baris 0: Actual Positive, Baris 1: Actual Negative
     * Kolom 0: Predicted Positive, Kolom 1: Predicted Negative
     *
     * @param list<string> $actual Label aktual.
     * @param list<string> $predicted Label prediksi.
     * @param string|null $positiveLabel Label yang dianggap positif (opsional, akan coba dideteksi).
     * @return array{0: list<int>, 1: list<int>}|array{0:array{0:int,1:int}, 1:array{0:int,1:int}} Matriks [[TP, FN], [FP, TN]] atau [[0,0],[0,0]] jika error.
     */
    public function confusionMatrix(array $actual, array $predicted, ?string $positiveLabel = null): array
    {
        if (count($actual) !== count($predicted)) {
            Log::error("EvaluationService::confusionMatrix: Actual and predicted label counts do not match.", ['actual_count' => count($actual), 'predicted_count' => count($predicted)]);
            return [[0, 0], [0, 0]];
        }
        if (empty($actual)) {
            return [[0, 0], [0, 0]];
        }
        $uniqueActualLabels = array_values(array_unique($actual));
        if ($positiveLabel === null) {
            if (count($uniqueActualLabels) !== 2) {
                Log::error("EvaluationService::confusionMatrix requires exactly 2 unique actual labels for auto-detection or a specified positiveLabel.", ['found_labels' => implode(', ', $uniqueActualLabels)]);
                return [[0, 0], [0, 0]];
            }
            $positiveLabel = $uniqueActualLabels[0];
            Log::debug("Auto-detected positive label for confusion matrix: {$positiveLabel}");
        } elseif (! in_array($positiveLabel, $uniqueActualLabels)) {
            Log::error("EvaluationService::confusionMatrix: Specified positiveLabel '{$positiveLabel}' not found in actual labels.", ['available_labels' => implode(', ', $uniqueActualLabels)]);
            return [[0, 0], [0, 0]];
        }

        $tp = 0;
        $fn = 0;
        $fp = 0;
        $tn = 0;
        foreach ($actual as $i => $actualLabel) {
            $predictedLabel      = $predicted[$i] ?? null;
            $isActualPositive    = ($actualLabel === $positiveLabel);
            $isPredictedPositive = ($predictedLabel === $positiveLabel);
            if ($isActualPositive) {
                if ($isPredictedPositive) {$tp++;} else { $fn++;}
            } else {
                if ($isPredictedPositive) {$fp++;} else { $tn++;}
            }
        }
        return [[$tp, $fn], [$fp, $tn]];
    }

    /**
     * Analisis potensi overfitting berdasarkan performa training dan validasi.
     * @param float $trainScore Skor metrik pada data training (misal: akurasi).
     * @param float $validationScore Skor metrik yang sama pada data validasi.
     * @return array{is_overfitting: bool, difference: float, severity: string, train_score: float, validation_score: float}
     */
    public function analyzeOverfitting(float $trainScore, float $validationScore): array
    {
        if ($trainScore < 0 || $trainScore > 1 || $validationScore < 0 || $validationScore > 1) {
            Log::warning("analyzeOverfitting received scores outside the 0-1 range.", compact('trainScore', 'validationScore'));
        }
        $difference     = abs($trainScore - $validationScore) * 100;
        $threshold_low  = 5.0;
        $threshold_high = 15.0;
        $severity       = 'low';
        $isOverfitting  = false;
        if ($trainScore > $validationScore) {
            if ($difference > $threshold_high) {
                $severity      = 'high';
                $isOverfitting = true;
            } elseif ($difference > $threshold_low) {
                $severity      = 'medium';
                $isOverfitting = true;
            }
        }
        return [
            'is_overfitting'   => $isOverfitting,
            'difference'       => round($difference, 2),
            'severity'         => $severity,
            'train_score'      => round($trainScore, 4),
            'validation_score' => round($validationScore, 4),
        ];
    }

    /**
     * Menghitung statistik deskriptif dari skor cross-validation.
     * @param array<float|int> $scores Array skor dari setiap fold.
     * @return array{mean: float, std: float, min: float, max: float}
     */
    public function calculateCrossValidationStats(array $scores): array
    {
        $n             = count($scores);
        $numericScores = array_filter($scores, 'is_numeric');
        $n_numeric     = count($numericScores);

        if ($n_numeric === 0) {
            Log::warning("calculateCrossValidationStats called with empty or non-numeric scores array.");
            return ['mean' => 0.0, 'std' => 0.0, 'min' => 0.0, 'max' => 0.0];
        }
        $mean = array_sum($numericScores) / $n_numeric;
        $min  = min($numericScores);
        $max  = max($numericScores);
        $std  = 0.0;
        if ($n_numeric > 1) {
            $sumSquaredDiffs = array_reduce($numericScores, function ($carry, $item) use ($mean) {
                return $carry + pow((float) $item - $mean, 2);
            }, 0.0);
            $std = sqrt($sumSquaredDiffs / ($n_numeric - 1));
        }
        return [
            'mean' => round($mean, 4),
            'std'  => round($std, 4),
            'min'  => round((float) $min, 4),
            'max'  => round((float) $max, 4),
        ];
    }

    /**
     * Analisis learning curve untuk deteksi potensi overfitting/underfitting.
     * @param array $learningCurve Data output dari generateLearningCurve, harus berisi 'train_sizes', 'train_scores', 'test_scores'.
     * @return array{has_convergence: bool, convergence_point_index: int|null, final_gap: float|null, recommendation: string}
     */
    public function analyzeLearningCurve(array $learningCurve): array
    {
        if (
            empty($learningCurve['train_sizes']) || empty($learningCurve['train_scores']) || empty($learningCurve['test_scores']) ||
            count($learningCurve['train_sizes']) !== count($learningCurve['train_scores']) ||
            count($learningCurve['train_sizes']) !== count($learningCurve['test_scores'])
        ) {
            Log::warning("analyzeLearningCurve received invalid or incomplete data structure.");
            return [
                'has_convergence'         => false,
                'convergence_point_index' => null,
                'final_gap'               => null,
                'recommendation'          => 'Data learning curve tidak lengkap atau tidak valid.',
            ];
        }

        $trainScores    = $learningCurve['train_scores'];
        $testScores     = $learningCurve['test_scores'];
        $n              = count($trainScores);
        $lastTrainScore = end($trainScores);
        $lastTestScore  = end($testScores);
        $finalGap       = (is_numeric($lastTrainScore) && is_numeric($lastTestScore))
        ? abs($lastTrainScore - $lastTestScore)
        : null;
        $convergenceThreshold  = 0.01;
        $convergencePointIndex = null;
        $hasConvergence        = false;
        $stablePoints          = 0;
        $pointsToCheck         = min(3, floor($n / 2));

        if ($n > 1 && $pointsToCheck > 0) {
            for ($i = $n - $pointsToCheck; $i < $n; $i++) {
                if (
                    ! is_numeric($trainScores[$i]) || ! is_numeric($trainScores[$i - 1]) ||
                    ! is_numeric($testScores[$i]) || ! is_numeric($testScores[$i - 1])
                ) {
                    continue;
                }
                $trainDiff = abs($trainScores[$i] - $trainScores[$i - 1]);
                $testDiff  = abs($testScores[$i] - $testScores[$i - 1]);
                if ($trainDiff < $convergenceThreshold && $testDiff < $convergenceThreshold) {
                    $stablePoints++;
                    if ($convergencePointIndex === null) {
                        $convergencePointIndex = $i;
                    }
                } else {
                    $stablePoints          = 0;
                    $convergencePointIndex = null;
                }
            }
            if ($stablePoints >= $pointsToCheck || ($n <= 4 && $stablePoints > 0)) {
                $hasConvergence = true;
            }
        } elseif ($n == 1) {
            $hasConvergence = false;
        }
        $recommendation = $this->generateLearningCurveRecommendation(
            $finalGap,
            $hasConvergence,
            $lastTrainScore,
            $lastTestScore
        );
        return [
            'has_convergence'         => $hasConvergence,
            'convergence_point_index' => $convergencePointIndex,
            'final_gap'               => $finalGap !== null ? round($finalGap, 4) : null,
            'recommendation'          => $recommendation,
        ];
    }

    /**
     * Generate rekomendasi teks berdasarkan hasil analisis learning curve.
     * @param float|null $gap Selisih absolut skor akhir train vs test (0-1). Null jika tidak bisa dihitung.
     * @param bool $hasConvergence Apakah kurva dianggap sudah konvergen.
     * @param float|null $finalTrainScore Skor training terakhir (0-1). Null jika tidak valid.
     * @param float|null $finalTestScore Skor test/validasi terakhir (0-1). Null jika tidak valid.
     * @return string Rekomendasi teks.
     */
    private function generateLearningCurveRecommendation(
        ?float $gap,
        bool $hasConvergence,
        ?float $finalTrainScore,
        ?float $finalTestScore
    ): string {
        if (! is_numeric($finalTrainScore) || ! is_numeric($finalTestScore)) {
            return 'Skor akhir tidak valid untuk memberikan rekomendasi.';
        }
        if ($gap === null) {
            return 'Selisih skor akhir tidak dapat dihitung.';
        }
        if ($gap > 0.15) {
            if (! $hasConvergence) {
                return 'Overfitting: Gap performa besar dan belum konvergen. Coba: tambah data training, simplifikasi model, tambah regularisasi, atau gunakan validasi silang saat tuning.';
            } else {
                return 'Overfitting: Gap performa besar meskipun sudah konvergen. Coba: simplifikasi model, tambah regularisasi, atau kumpulkan lebih banyak data fitur yang relevan.';
            }
        }
        if ($hasConvergence && $gap < 0.05 && $finalTestScore < 0.70) {
            return 'Underfitting: Model terlalu sederhana atau fitur kurang. Coba: tambah kompleksitas model (misal: layer/neuron di MLP, kedalaman pohon), rekayasa fitur (feature engineering), atau gunakan algoritma yang lebih kompleks.';
        }
        if (! $hasConvergence && $finalTestScore < 0.70) {
            return 'Underfitting/Belum Optimal: Model mungkin perlu training lebih lama (epoch) atau data lebih banyak. Jika skor training juga rendah, pertimbangkan kompleksitas model/fitur.';
        }
        if ($hasConvergence && $gap < 0.05 && $finalTestScore >= 0.70) {
            return 'Keseimbangan Baik: Model menunjukkan performa generalisasi yang baik dengan gap train-test kecil dan skor yang memadai.';
        }
        if ($hasConvergence && $gap < 0.10 && $finalTestScore >= 0.70) {
            return 'Cukup Baik: Model cukup stabil. Peningkatan mungkin bisa dicapai dengan sedikit lebih banyak data atau tuning hyperparameter.';
        }
        if (! $hasConvergence && $gap < 0.10 && $finalTestScore >= 0.70) {
            return 'Potensi Peningkatan: Model tampaknya masih belajar. Coba tambah data training atau epoch training.';
        }
        return 'Analisis learning curve menunjukkan kondisi yang perlu diperiksa lebih lanjut. Pertimbangkan gap antara skor training dan validasi serta titik konvergensi.';
    }

    /**
     * Menghitung confidence interval (CI) 95% untuk rata-rata skor menggunakan distribusi-t.
     * @param array<float|int> $scores Array skor numerik.
     * @param float $confidenceLevel Tingkat kepercayaan (default 0.95).
     * @return array{lower: float|null, upper: float|null, mean: float|null, std: float|null, n: int} CI, mean, std, n. Null jika tidak bisa dihitung.
     */
    public function calculateConfidenceInterval(array $scores, float $confidenceLevel = 0.95): array
    {
        $numericScores = array_filter($scores, 'is_numeric');
        $n             = count($numericScores);

        if ($n < 2) {
            Log::warning("calculateConfidenceInterval requires at least 2 numeric scores.", ['n' => $n]);
            return ['lower' => null, 'upper' => null, 'mean' => ($n == 1 ? round((float) $numericScores[0], 4) : null), 'std' => null, 'n' => $n];
        }

        $mean            = array_sum($numericScores) / $n;
        $sumSquaredDiffs = array_reduce($numericScores, fn($carry, $item) => $carry + pow((float) $item - $mean, 2), 0.0);
        $std             = sqrt($sumSquaredDiffs / ($n - 1));
        $alpha           = 1.0 - $confidenceLevel;
        $tValue          = 1.96;
        if ($n < 30) {
            if ($n <= 5) {
                $tValue = 2.776;
            } elseif ($n <= 10) {
                $tValue = 2.262;
            } elseif ($n <= 20) {
                $tValue = 2.086;
            } else {
                $tValue = 2.042;
            }
        }
        $marginOfError = $tValue * ($std / sqrt($n));
        $lowerBound    = $mean - $marginOfError;
        $upperBound    = $mean + $marginOfError;
        return [
            'lower' => round($lowerBound, 4),
            'upper' => round($upperBound, 4),
            'mean'  => round($mean, 4),
            'std'   => round($std, 4),
            'n'     => $n,
        ];
    }

    public function getAggregatedEvaluationData(): array
    {
        $evaluation = [];
        $modelKeys  = [];
        foreach (ModelService::BASE_MODEL_KEYS as $baseKey) {
            foreach (ModelService::MODEL_TYPES as $type) {
                $modelKeys[] = "{$baseKey}_{$type}";
            }
        }

        foreach ($modelKeys as $modelKey) {
            try {
                $metadata          = $this->modelService->loadModelMetadata($modelKey);
                $validationDataRaw = $this->modelService->loadModelMetrics($modelKey);
                $learningCurveData = $this->modelService->loadLearningCurveData($modelKey);
                $cvResultsRaw      = $this->modelService->loadCrossValidationScores($modelKey);
                $testResultsRaw    = $this->modelService->loadTestResults($modelKey);

                $validationMetrics = null;
                $isDetector        = Str::endsWith($modelKey, '_detector');
                $posLabelDefault   = $isDetector ? 'melon' : 'ripe';
                $negLabelDefault   = $isDetector ? 'non_melon' : 'unripe';

                if ($validationDataRaw && is_array($validationDataRaw)) {
                    $metricsSource = $validationDataRaw['metrics'] ?? ($validationDataRaw['metrics_per_class'] ?? $validationDataRaw);

                    if (is_array($metricsSource)) { // Pastikan metricsSource adalah array
                        $actualPosLabel = $metricsSource[$posLabelDefault]['label'] ?? ($metricsSource['positive']['label'] ?? $posLabelDefault);
                        $actualNegLabel = $metricsSource[$negLabelDefault]['label'] ?? ($metricsSource['negative']['label'] ?? $negLabelDefault);

                        $metricsPerClass = [
                            'accuracy' => $metricsSource['accuracy'] ?? 0.0,
                            'positive' => $metricsSource[$actualPosLabel] ?? ($metricsSource[$posLabelDefault] ?? ($metricsSource['positive'] ?? ['precision' => 0, 'recall' => 0, 'f1_score' => 0, 'support' => 0])),
                            'negative' => $metricsSource[$actualNegLabel] ?? ($metricsSource[$negLabelDefault] ?? ($metricsSource['negative'] ?? ['precision' => 0, 'recall' => 0, 'f1_score' => 0, 'support' => 0])),
                        ];
                        if (is_array($metricsPerClass['positive'])) {
                            $metricsPerClass['positive']['label'] = $actualPosLabel;
                        }

                        if (is_array($metricsPerClass['negative'])) {
                            $metricsPerClass['negative']['label'] = $actualNegLabel;
                        }

                        $confusionMatrixRaw        = $validationDataRaw['confusion_matrix'] ?? [[0, 0], [0, 0]];
                        $structuredConfusionMatrix = $this->formatConfusionMatrixForService($confusionMatrixRaw, $actualPosLabel, $actualNegLabel);
                        $samplesCount              = $validationDataRaw['validation_samples_count'] ?? ($validationDataRaw['validation_samples'] ?? ($validationDataRaw['samples'] ?? array_sum(array_map('array_sum', $confusionMatrixRaw))));

                        $validationMetrics = [
                            'metrics_per_class' => $metricsPerClass,
                            'confusion_matrix'  => $structuredConfusionMatrix,
                            'samples'           => $samplesCount,
                        ];
                    } else {
                        Log::warning("EvaluationService: metricsSource bukan array untuk model {$modelKey}", ['metricsSource_type' => gettype($metricsSource)]);
                    }
                }

                $cvResults = null;
                if ($cvResultsRaw && isset($cvResultsRaw['metrics_per_fold']) && is_array($cvResultsRaw['metrics_per_fold'])) {
                    $calculatedStats = [];
                    foreach ($cvResultsRaw['metrics_per_fold'] as $metricName => $scores) {
                        if (! is_array($scores)) {
                            continue;
                        }
                        $stats = empty($scores)
                        ? ['mean' => 0, 'std' => 0, 'min' => 0, 'max' => 0]
                        : $this->calculateCrossValidationStats($scores);
                        $calculatedStats[$metricName] = $stats;
                    }
                    $cvResults = [
                        'k_folds'          => $cvResultsRaw['k_folds'] ?? FeatureExtractionService::DEFAULT_K_FOLDS,
                        'stats_per_metric' => $calculatedStats,
                        'metrics_per_fold' => $cvResultsRaw['metrics_per_fold'],
                    ];
                }

                $testSetResults = null;
                if ($testResultsRaw && isset($testResultsRaw['metrics']) && is_array($testResultsRaw['metrics'])) {
                    $testMetricsSource  = $testResultsRaw['metrics'];
                    $actualPosLabelTest = $testMetricsSource[$posLabelDefault]['label'] ?? ($testMetricsSource['positive']['label'] ?? $posLabelDefault);
                    $actualNegLabelTest = $testMetricsSource[$negLabelDefault]['label'] ?? ($testMetricsSource['negative']['label'] ?? $negLabelDefault);

                    $testMetricsFormatted = [
                        'accuracy' => $testMetricsSource['accuracy'] ?? 0.0,
                        'positive' => $testMetricsSource[$actualPosLabelTest] ?? ($testMetricsSource[$posLabelDefault] ?? ($testMetricsSource['positive'] ?? ['precision' => 0, 'recall' => 0, 'f1_score' => 0, 'support' => 0])),
                        'negative' => $testMetricsSource[$actualNegLabelTest] ?? ($testMetricsSource[$negLabelDefault] ?? ($testMetricsSource['negative'] ?? ['precision' => 0, 'recall' => 0, 'f1_score' => 0, 'support' => 0])),
                    ];
                    if (is_array($testMetricsFormatted['positive'])) {
                        $testMetricsFormatted['positive']['label'] = $actualPosLabelTest;
                    }

                    if (is_array($testMetricsFormatted['negative'])) {
                        $testMetricsFormatted['negative']['label'] = $actualNegLabelTest;
                    }

                    $testConfusionMatrixRaw        = $testResultsRaw['confusion_matrix'] ?? [[0, 0], [0, 0]];
                    $structuredTestConfusionMatrix = $this->formatConfusionMatrixForService($testConfusionMatrixRaw, $actualPosLabelTest, $actualNegLabelTest);
                    $testSetResults                = ['metrics' => $testMetricsFormatted, 'confusion_matrix' => $structuredTestConfusionMatrix];
                }

                if ($metadata) {
                    $evaluation[$modelKey] = [
                        'metadata'            => $metadata,
                        'validation_metrics'  => $validationMetrics,
                        'cv_results'          => $cvResults,
                        'test_results'        => $testSetResults,
                        'learning_curve_data' => $learningCurveData,
                    ];
                } else {
                    $evaluation[$modelKey] = null;
                }
            } catch (Throwable $e) {
                Log::error("EvaluationService: Error processing evaluation data for model {$modelKey}", ['error' => $e->getMessage(), 'trace' => Str::limit($e->getTraceAsString(), 500)]);
                $evaluation[$modelKey] = null;
            }
        }
        uksort($evaluation, function ($a, $b) {
            $order = ModelService::BASE_MODEL_KEYS;
            $aBase = explode('_', $a)[0] . '_' . explode('_', $a)[1]; // e.g., gaussian_nb
            $bBase = explode('_', $b)[0] . '_' . explode('_', $b)[1];
            $aType = Str::contains($a, '_detector') ? 1 : 0; // Classifier 0, Detector 1
            $bType = Str::contains($b, '_detector') ? 1 : 0;

            $aIndex = array_search($aBase, array_map(fn($k) => explode('_', $k)[0] . '_' . explode('_', $k)[1], $order));
            $bIndex = array_search($bBase, array_map(fn($k) => explode('_', $k)[0] . '_' . explode('_', $k)[1], $order));

            // Jika base key sama, urutkan berdasarkan tipe (Classifier dulu baru Detector)
            if ($aIndex === $bIndex) {
                return $aType <=> $bType;
            }
            return ($aIndex === false ? 999 : $aIndex) <=> ($bIndex === false ? 999 : $bIndex);
        });
        return $evaluation;
    }

    public function getAggregatedEvaluationDataWithBestModels(): array
    {
        $evaluationData = $this->getAggregatedEvaluationData();

        $bestClassifierKey = null;
        $bestDetectorKey   = null;
        $rankedClassifiers = [];
        $rankedDetectors   = [];
        $tempModelScores   = [];
        $weights           = ['f1_score' => 0.7, 'accuracy' => 0.2, 'precision' => 0.05, 'recall' => 0.05];

        if (is_array($evaluationData)) { // Pastikan $evaluationData adalah array
            foreach ($evaluationData as $modelKey => $data) {
                // Tambahkan pengecekan $data dan struktur dalamnya
                if (empty($data) || ! is_array($data) || empty($data['validation_metrics']['metrics_per_class']) || ! is_array($data['validation_metrics']['metrics_per_class'])) {
                    Log::debug("Skipping model '{$modelKey}' in getAggregatedEvaluationDataWithBestModels due to missing/invalid validation_metrics['metrics_per_class'].", ['data_preview' => Str::limit(json_encode($data), 100)]);
                    continue;
                }

                $metricsData       = $data['validation_metrics']['metrics_per_class'];
                $isDetectorCurrent = Str::endsWith($modelKey, '_detector');

                // Penentuan positiveClassLabel yang lebih aman
                $positiveClassLabel = '';
                if ($isDetectorCurrent) {
                    $positiveClassLabel = $metricsData['melon']['label'] ?? ($metricsData['positive']['label'] ?? 'melon');
                } else {
                    $positiveClassLabel = $metricsData['ripe']['label'] ?? ($metricsData['positive']['label'] ?? 'ripe');
                }

                $positiveMetrics = $metricsData[$positiveClassLabel] ?? ($metricsData['positive'] ?? null);

                if ($positiveMetrics && isset($positiveMetrics['f1_score'])) {
                    $currentF1        = (float) ($positiveMetrics['f1_score'] ?? 0.0);
                    $currentAccuracy  = (float) ($metricsData['accuracy'] ?? 0.0);
                    $currentPrecision = (float) ($positiveMetrics['precision'] ?? 0.0);
                    $currentRecall    = (float) ($positiveMetrics['recall'] ?? 0.0);

                    $combinedScore = ($currentF1 * $weights['f1_score']) +
                        ($currentAccuracy * $weights['accuracy']) +
                        ($currentPrecision * $weights['precision']) +
                        ($currentRecall * $weights['recall']);

                    $tempModelScores[] = [
                        'key'                => $modelKey,
                        'name'               => class_basename($data['metadata']['algorithm_class'] ?? $modelKey),
                        'type'               => $isDetectorCurrent ? 'detector' : 'classifier',
                        'f1_positive'        => $currentF1,
                        'accuracy'           => $currentAccuracy,
                        'precision_positive' => $currentPrecision,
                        'recall_positive'    => $currentRecall,
                        'combined_score'     => round($combinedScore, 4),
                        'trained_at'         => $data['metadata']['trained_at'] ?? null,
                    ];
                }
            }
        } else {
            Log::error("EvaluationService: getAggregatedEvaluationData() did not return an array in getAggregatedEvaluationDataWithBestModels.");
            // $evaluationData akan menjadi nilai non-array, yang akan ditangani di return.
        }

        $allClassifiers = array_filter($tempModelScores, fn($m) => $m['type'] === 'classifier');
        $allDetectors   = array_filter($tempModelScores, fn($m) => $m['type'] === 'detector');

        usort($allClassifiers, fn($a, $b) => $b['combined_score'] <=> $a['combined_score']);
        usort($allDetectors, fn($a, $b) => $b['combined_score'] <=> $a['combined_score']);

        if (! empty($allClassifiers)) {
            $bestClassifierKey = $allClassifiers[0]['key'];
            $rankedClassifiers = $allClassifiers;
        }
        if (! empty($allDetectors)) {
            $bestDetectorKey = $allDetectors[0]['key'];
            $rankedDetectors = $allDetectors;
        }

        Log::debug("Best models determined for evaluation page.", compact('bestClassifierKey', 'bestDetectorKey'));

        return [
            'evaluation'          => $evaluationData, // $evaluationData bisa jadi bukan array jika getAggregatedEvaluationData gagal parah
            'best_classifier_key' => $bestClassifierKey,
            'best_detector_key'   => $bestDetectorKey,
            'ranked_classifiers'  => $rankedClassifiers,
            'ranked_detectors'    => $rankedDetectors,
        ];
    }

    /**
     * Helper internal untuk memformat confusion matrix.
     * Berbeda dari yang di EvaluationController karena ini untuk konsumsi internal Service/Controller lain.
     */
    private function formatConfusionMatrixForService(array $matrix, string $posLabelKey, string $negLabelKey): array
    {
        $posLabelFormatted = ucfirst(Str::replace('_', ' ', $posLabelKey));
        $negLabelFormatted = ucfirst(Str::replace('_', ' ', $negLabelKey));

        if (isset($matrix[0][0], $matrix[0][1], $matrix[1][0], $matrix[1][1])) {
            return [
                $posLabelFormatted => ['TP' => (int) $matrix[0][0], 'FN' => (int) $matrix[0][1], 'label' => $posLabelKey],
                $negLabelFormatted => ['FP' => (int) $matrix[1][0], 'TN' => (int) $matrix[1][1], 'label' => $negLabelKey],
            ];
        }
        Log::warning('EvaluationService: Format confusion matrix tidak dikenali atau tidak lengkap untuk formatting internal.', ['matrix' => $matrix]);
        return [
            $posLabelFormatted => ['TP' => 0, 'FN' => 0, 'label' => $posLabelKey],
            $negLabelFormatted => ['FP' => 0, 'TN' => 0, 'label' => $negLabelKey],
        ];
    }

    /**
     * Membuat array nama model yang mudah dibaca untuk view.
     */
    public function generateModelKeysForView(array $evaluationData): array
    {
        $modelKeysForView = [];
        $orderedKeys      = [];
        foreach (ModelService::BASE_MODEL_KEYS as $baseKey) {
            $orderedKeys[] = $baseKey . '_classifier';
            $orderedKeys[] = $baseKey . '_detector';
        }

        foreach ($orderedKeys as $key) {
            if (isset($evaluationData[$key]) && $evaluationData[$key] !== null && is_array($evaluationData[$key])) { // Tambah cek is_array
                $data      = $evaluationData[$key];
                $baseName  = '';
                $algoClass = $data['metadata']['algorithm_class'] ?? ($data['metadata']['algorithm'] ?? null);
                if ($algoClass && is_string($algoClass)) {
                    try {
                        $reflect  = new ReflectionClass($algoClass);
                        $baseName = $reflect->getShortName();
                    } catch (Throwable $th) {
                        $baseName = Str::title(str_replace(['_classifier', '_detector', '_'], ['', '', ' '], $key));
                    }
                } else {
                    $baseName = Str::title(str_replace(['_classifier', '_detector', '_'], ['', '', ' '], $key));
                }
                $taskLabel              = Str::endsWith($key, '_classifier') ? 'Klasifikasi' : 'Deteksi';
                $modelKeysForView[$key] = "{$baseName} ({$taskLabel})";
            }
        }
        return $modelKeysForView;
    }

    /** Menghasilkan analisis dinamis (perlu key model terbaik dari showEvaluationPage) */
    public function generateDynamicAnalysis(array $evaluationData, array $modelNames, ?string $bestClassifierKey, ?string $bestDetectorKey): string
    {
        if (empty($evaluationData) || count(array_filter($evaluationData)) === 0) {
            return '<p class="text-muted small fst-italic">Data evaluasi tidak tersedia untuk dianalisis.</p>';
        }

        $analysisHtml = '<div class="alert alert-light border-start border-4 border-primary mb-4">';
        $analysisHtml .= '<h6 class="alert-heading text-primary"><i class="fas fa-star me-1"></i> Evaluasi Hasil Terkini</h6>';
        $analysisHtml .= '<ul>';

        $metricLabel = 'F1 Positif';
        $issues      = [];

        if ($bestClassifierKey && isset($evaluationData[$bestClassifierKey]['validation_metrics']['metrics_per_class'])) {
            $bestClsMetrics = $evaluationData[$bestClassifierKey]['validation_metrics']['metrics_per_class'];
            $name           = $modelNames[$bestClassifierKey] ?? $bestClassifierKey;

            $posLabelClsKey = $bestClsMetrics['ripe']['label'] ?? ($bestClsMetrics['positive']['label'] ?? 'ripe'); // Default ke 'ripe' jika tidak ada
            $posLabelCls    = ucfirst(Str::replace('_', ' ', $posLabelClsKey));

            $score          = $bestClsMetrics[$posLabelClsKey]['f1_score'] ?? ($bestClsMetrics['positive']['f1_score'] ?? 0.0);
            $scoreFormatted = number_format((float) $score * 100, 1) . "%";
            $analysisHtml .= "<li>Untuk <strong>Klasifikasi Kematangan</strong>, model <strong>{$name}</strong> menunjukkan performa terbaik saat ini ({$metricLabel} {$posLabelCls}: {$scoreFormatted}).</li>";
        } else {
            $analysisHtml .= "<li>Tidak ada data valid untuk menentukan model klasifikasi terbaik secara otomatis. Pastikan model telah dilatih dan file metrik validasi tersedia.</li>";
        }

        if ($bestDetectorKey && isset($evaluationData[$bestDetectorKey]['validation_metrics']['metrics_per_class'])) {
            $bestDetMetrics = $evaluationData[$bestDetectorKey]['validation_metrics']['metrics_per_class'];
            $name           = $modelNames[$bestDetectorKey] ?? $bestDetectorKey;

            $posLabelDetKey = $bestDetMetrics['melon']['label'] ?? ($bestDetMetrics['positive']['label'] ?? 'melon'); // Default ke 'melon'
            $posLabelDet    = ucfirst(Str::replace('_', ' ', $posLabelDetKey));

            $score          = $bestDetMetrics[$posLabelDetKey]['f1_score'] ?? ($bestDetMetrics['positive']['f1_score'] ?? 0.0);
            $scoreFormatted = number_format((float) $score * 100, 1) . "%";
            $analysisHtml .= "<li>Untuk <strong>Deteksi Melon</strong>, model <strong>{$name}</strong> menunjukkan performa terbaik saat ini ({$metricLabel} {$posLabelDet}: {$scoreFormatted}).</li>";
        } else {
            $analysisHtml .= "<li>Tidak ada data valid untuk menentukan model deteksi terbaik secara otomatis. Pastikan model telah dilatih dan file metrik validasi tersedia.</li>";
        }

        if (is_array($evaluationData)) { // Tambahan pengecekan
            foreach ($evaluationData as $key => $data) {
                if (empty($data) || ! is_array($data) || empty($data['validation_metrics']['metrics_per_class']) || ! is_array($data['validation_metrics']['metrics_per_class'])) {
                    continue;
                }

                $metrics           = $data['validation_metrics']['metrics_per_class'];
                $modelName         = $modelNames[$key] ?? $key;
                $isDetectorCurrent = str_ends_with($key, '_detector');

                $currentPosLabelKey = '';
                if ($isDetectorCurrent) {
                    $currentPosLabelKey = $metrics['melon']['label'] ?? ($metrics['positive']['label'] ?? 'melon');
                } else {
                    $currentPosLabelKey = $metrics['ripe']['label'] ?? ($metrics['positive']['label'] ?? 'ripe');
                }
                $currentPositiveMetrics = $metrics[$currentPosLabelKey] ?? ($metrics['positive'] ?? null);

                $f1   = $currentPositiveMetrics['f1_score'] ?? null;
                $prec = $currentPositiveMetrics['precision'] ?? null;
                $rec  = $currentPositiveMetrics['recall'] ?? null;

                if (is_numeric($f1) && $f1 < 0.6) {
                    $issues[] = "<li>Model <strong>{$modelName}</strong> memiliki F1-Score {$currentPosLabelKey} rendah (" . number_format($f1 * 100, 1) . "%).</li>";
                }

                if (is_numeric($prec) && is_numeric($rec) && abs($prec - $rec) > 0.3) {
                    $posLabelDyn = ucfirst(Str::replace('_', ' ', $currentPosLabelKey));
                    $issues[]    = "<li>Model <strong>{$modelName}</strong> memiliki selisih besar antara Presisi ({$posLabelDyn}: " . number_format($prec * 100, 1) . "%) dan Recall ({$posLabelDyn}: " . number_format($rec * 100, 1) . "%).</li>";
                }
            }
        }

        if (! empty($issues)) {
            $analysisHtml .= '<li class="mt-2"><strong>Potensi Isu Lain Teridentifikasi:</strong><ul>';
            foreach ($issues as $issue) {
                $analysisHtml .= $issue;
            }
            $analysisHtml .= '</ul></li>';
        } elseif ($bestClassifierKey || $bestDetectorKey) {
            $analysisHtml .= '<li class="mt-2">Tidak ada isu performa signifikan lain yang terdeteksi berdasarkan kriteria saat ini.</li>';
        }

        $analysisHtml .= '</ul></div>';
        return $analysisHtml;
    }

    /** Mendapatkan timestamp training terakhir per tipe model */
    public function getLatestTrainingTimes(array $evaluationData): array
    {
        $latestClassifierTime = null;
        $latestDetectorTime   = null;
        if (is_array($evaluationData)) { // Tambah cek is_array
            foreach ($evaluationData as $key => $data) {
                if (empty($data) || ! is_array($data) || ! isset($data['metadata']['trained_at'])) { // Tambah cek !is_array($data)
                    continue;
                }

                try {
                    $currentTime = Carbon::parse($data['metadata']['trained_at']);
                    if (str_ends_with($key, '_classifier')) {
                        if ($latestClassifierTime === null || $currentTime->isAfter($latestClassifierTime)) {
                            $latestClassifierTime = $currentTime;
                        }
                    } elseif (str_ends_with($key, '_detector')) {
                        if ($latestDetectorTime === null || $currentTime->isAfter($latestDetectorTime)) {
                            $latestDetectorTime = $currentTime;
                        }
                    }
                } catch (Throwable $e) {
                    Log::warning("Invalid date format in metadata for {$key}", ['date_string' => $data['metadata']['trained_at'] ?? 'N/A', 'error' => $e->getMessage()]);
                }
            }
        }
        $lastClassifierTimeFormatted = $latestClassifierTime ? $latestClassifierTime->isoFormat('D MMM, HH:mm') : 'N/A';
        $lastDetectorTimeFormatted   = $latestDetectorTime ? $latestDetectorTime->isoFormat('D MMMM YYYY, HH:mm') : 'N/A';
        return [$lastClassifierTimeFormatted, $lastDetectorTimeFormatted];
    }
}
// Akhir Class EvaluationService
