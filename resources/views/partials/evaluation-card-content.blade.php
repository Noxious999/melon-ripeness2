{{-- resources/views/partials/evaluation-card-content.blade.php --}}
{{-- Konten Kartu Evaluasi Model Individual (Setelah semua sub-partials dirombak) --}}

@php
    // Helper PHP inline
    $_formatPercent = fn($value, $digits = 1) => is_numeric($value)
        ? number_format((float) $value * 100, $digits) . '%'
        : 'N/A';
    $_formatScore = fn($value, $digits = 4) => is_numeric($value) ? number_format((float) $value, $digits) : 'N/A';

    // Variabel utama dari parent view (evaluate.blade.php -> loop model)
    // $evalData, $isDetector, $chartIdSuffix, $modelKey,
    // $bestClassifierKey, $bestDetectorKey, $modelKeysForView, $advancedAnalysis
    // semua diasumsikan sudah di-pass dengan benar.

    // Ekstrak data yang sering digunakan
    $metadata = $evalData['metadata'] ?? [];
    $validationMetricsData = $evalData['validation_metrics'] ?? null; // Ini berisi ['metrics_per_class'] dan ['confusion_matrix']
    $testResultsData = $evalData['test_results'] ?? null; // Ini berisi ['metrics'] dan ['confusion_matrix']
    $learningCurveRawData = $evalData['learning_curve_data'] ?? null;
    $cvResultsRawData = $evalData['cv_results'] ?? null;

    // Data analisis lanjutan untuk model ini
    $advancedAnalysisDataForThisModel = $advancedAnalysis[$modelKey] ?? null;

    // Informasi dasar model
    $trainedAt = isset($metadata['trained_at'])
        ? \Carbon\Carbon::parse($metadata['trained_at'])->isoFormat('D MMMM YYYY, HH:mm')
        : 'N/A';
    $algorithmClass = $metadata['algorithm_class'] ?? ($metadata['algorithm'] ?? 'N/A');
    $algorithmName = $algorithmClass !== 'N/A' ? class_basename($algorithmClass) : 'N/A';
    $trainingAccuracy = $metadata['training_accuracy_on_processed_data'] ?? ($metadata['training_accuracy'] ?? null);
    $numFeatures = $metadata['num_features_expected'] ?? ($metadata['num_features'] ?? 'N/A');
    $scalerUsedClass = $metadata['scaler_used_class'] ?? ($metadata['scaler_used'] ?? 'N/A');
    $scalerName = $scalerUsedClass !== 'N/A' ? class_basename($scalerUsedClass) : 'Tidak Digunakan';
    $trainingSamplesCount =
        $metadata['training_samples_count_after_processing'] ??
        ($metadata['training_samples_count'] ?? ($metadata['training_samples'] ?? 'N/A'));

    // Tentukan apakah model ini dibandingkan dengan model terbaik
    $isComparedToBest = $modelKey !== ($isDetector ? $bestDetectorKey : $bestClassifierKey);
    $currentBestModelKey = $isDetector ? $bestDetectorKey : $bestClassifierKey;
    $bestModelNameForComparison = '';
    if ($currentBestModelKey && isset($modelKeysForView[$currentBestModelKey])) {
        $bestModelNameForComparison = $modelKeysForView[$currentBestModelKey];
    } elseif ($currentBestModelKey) {
        $bestModelNameForComparison = Str::title(
            str_replace(['_classifier', '_detector', '_'], ['', '', ' '], $currentBestModelKey),
        );
    }

    // Default labels (digunakan jika data dari metrik tidak ada)
    $defaultPosLabel = $isDetector ? 'Melon' : 'Matang';
    $defaultNegLabel = $isDetector ? 'Non-Melon' : 'Belum Matang';

    // Persiapan label dan data CM untuk validasi
    $valMetricsPerClass = $validationMetricsData['metrics_per_class'] ?? null;
    $valPosLabel = $valMetricsPerClass
        ? ucfirst(Str::replace('_', ' ', $valMetricsPerClass['positive']['label'] ?? $defaultPosLabel))
        : $defaultPosLabel;
    $valNegLabel = $valMetricsPerClass
        ? ucfirst(Str::replace('_', ' ', $valMetricsPerClass['negative']['label'] ?? $defaultNegLabel))
        : $defaultNegLabel;
    $valMatrixRaw = $validationMetricsData['confusion_matrix'] ?? null; // Ini adalah array [[TP,FN],[FP,TN]] atau yang sudah diformat oleh controller
    $tp_val = $valMatrixRaw[$valPosLabel]['TP'] ?? ($valMatrixRaw[0][0] ?? 0);
    $fn_val = $valMatrixRaw[$valPosLabel]['FN'] ?? ($valMatrixRaw[0][1] ?? 0);
    $fp_val = $valMatrixRaw[$valNegLabel]['FP'] ?? ($valMatrixRaw[1][0] ?? 0);
    $tn_val = $valMatrixRaw[$valNegLabel]['TN'] ?? ($valMatrixRaw[1][1] ?? 0);

    // Persiapan label dan data CM untuk test
    $testMetricsPerClass = $testResultsData['metrics'] ?? null; // Ini adalah $testResultsData['metrics'] yang berisi ['accuracy'], ['positive'], ['negative']
    $testPosLabel = $testMetricsPerClass
        ? ucfirst(Str::replace('_', ' ', $testMetricsPerClass['positive']['label'] ?? $defaultPosLabel))
        : $defaultPosLabel;
    $testNegLabel = $testMetricsPerClass
        ? ucfirst(Str::replace('_', ' ', $testMetricsPerClass['negative']['label'] ?? $defaultNegLabel))
        : $defaultNegLabel;
    $testMatrixRaw = $testResultsData['confusion_matrix'] ?? null;
    $tp_test = $testMatrixRaw[$testPosLabel]['TP'] ?? ($testMatrixRaw[0][0] ?? 0);
    $fn_test = $testMatrixRaw[$testPosLabel]['FN'] ?? ($testMatrixRaw[0][1] ?? 0);
    $fp_test = $testMatrixRaw[$testNegLabel]['FP'] ?? ($testMatrixRaw[1][0] ?? 0);
    $tn_test = $testMatrixRaw[$testNegLabel]['TN'] ?? ($testMatrixRaw[1][1] ?? 0);

    // Tentukan apakah model ini dibandingkan dengan model terbaik
    $currentBestModelOverallKey = $isDetector ? $bestDetectorKey : $bestClassifierKey; // Ini adalah model terbaik untuk TIPEnya
    $isComparedToBest = $modelKey !== $currentBestModelOverallKey; // Benar, ini menentukan apakah bagian signifikansi harus muncul

    $bestModelNameForComparison = '';
    if ($currentBestModelOverallKey && isset($modelKeysForView[$currentBestModelOverallKey])) {
        $bestModelNameForComparison = $modelKeysForView[$currentBestModelOverallKey];
    } elseif ($currentBestModelOverallKey) {
        $bestModelNameForComparison = Str::title(
            str_replace(['_classifier', '_detector', '_'], ['', '', ' '], $currentBestModelOverallKey),
        );
    }
@endphp

<div class="model-evaluation-details p-md-2">

    {{-- Bagian 1: Informasi Umum & Metadata Model --}}
    <section class="model-section model-meta-section card shadow-sm mb-4">
        <div class="card-header bg-light-subtle py-3">
            <h6 class="mb-0 fw-semibold text-dark-emphasis d-flex align-items-center section-title-like">
                <i class="fas fa-file-invoice me-2 text-info "></i>Informasi & Metadata Model
            </h6>
        </div>
        <div class="card-body p-3 p-lg-4">
            <div class="row g-3">
                <div class="col-lg-6 col-xl-4">
                    <div class="meta-item p-2 border rounded bg-white d-flex flex-column align-items-center text-center">
                        <i class="fas fa-cogs fa-fw text-muted mb-1"></i>
                        <div>
                            <span class="meta-label d-block text-muted small">Algoritma</span>
                            <strong class="meta-value text-dark">{{ $algorithmName }}</strong>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 col-xl-4">
                    <div
                        class="meta-item p-2 border rounded bg-white d-flex flex-column align-items-center text-center">
                        <i class="fas fa-calendar-alt fa-fw text-muted"></i>
                        <div>
                            <span class="meta-label d-block text-muted small">Dilatih Pada</span>
                            <strong class="meta-value text-dark">{{ $trainedAt }}</strong>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 col-xl-4">
                    <div
                        class="meta-item p-2 border rounded bg-white d-flex flex-column align-items-center text-center">
                        <i class="fas fa-graduation-cap fa-fw text-muted"></i> {{-- Diubah dari fa-vial-circle-check --}}
                        <div>
                            <span class="meta-label d-block text-muted small">Akurasi Training</span>
                            <strong
                                class="meta-value {{ ($trainingAccuracy ?? 0) >= 0.8 ? 'text-success' : (($trainingAccuracy ?? 0) >= 0.6 ? 'text-warning' : 'text-danger') }}">
                                {{ $_formatPercent($trainingAccuracy) }}
                            </strong>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 col-xl-4">
                    <div
                        class="meta-item p-2 border rounded bg-white d-flex flex-column align-items-center text-center">
                        <i class="fas fa-database fa-fw text-muted"></i> {{-- Diubah dari fa-list-ol --}}
                        <div>
                            <span class="meta-label d-block text-muted small">Sampel Training Digunakan</span>
                            <strong class="meta-value text-dark">{{ number_format($trainingSamplesCount) }}</strong>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 col-xl-4">
                    <div
                        class="meta-item p-2 border rounded bg-white d-flex flex-column align-items-center text-center">
                        <i class="fas fa-puzzle-piece fa-fw text-muted"></i>
                        <div>
                            <span class="meta-label d-block text-muted small">Jumlah Fitur</span>
                            <strong class="meta-value text-dark">{{ $numFeatures }}</strong>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6 col-xl-4">
                    <div
                        class="meta-item p-2 border rounded bg-white d-flex flex-column align-items-center text-center">
                        <i class="fas fa-sliders-h fa-fw text-muted"></i>
                        <div>
                            <span class="meta-label d-block text-muted small">Scaler Digunakan</span>
                            <strong class="meta-value text-dark">{{ $scalerName }}</strong>
                        </div>
                    </div>
                </div>
                @if (!empty($metadata['feature_names']))
                    <div class="col-12 mt-2">
                        <div
                            class="meta-item p-2 border rounded bg-white d-flex flex-column align-items-center text-center">
                            <i class="fas fa-tags fa-fw text-muted"></i>
                            <div>
                                <span class="meta-label d-block text-muted small">Nama Fitur Digunakan</span>
                                <div class="feature-list p-2 border rounded bg-light-subtle mt-1">
                                    {{ is_array($metadata['feature_names']) ? implode(', ', $metadata['feature_names']) : $metadata['feature_names'] }}
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </section>

    {{-- Bagian 2 & 3: Performa Validasi dan Test dalam satu baris jika memungkinkan --}}
    <div class="row g-lg-4 g-3">
        {{-- Kolom Performa Validasi --}}
        <div class="col-xl-6 d-flex flex-column"> {{-- d-flex flex-column untuk h-100 pada card --}}
            <section class="model-section validation-performance-section card shadow-sm flex-grow-1">
                {{-- flex-grow-1 --}}
                <div class="card-header bg-light-subtle py-3">
                    <h6 class="mb-0 fw-semibold text-dark-emphasis d-flex align-items-center section-title-like">
                        <i class="fas fa-clipboard-check me-2 text-success"></i>Performa Set Valid
                    </h6>
                </div>
                <div class="card-body p-3 p-lg-4">
                    @if ($valMetricsPerClass)
                        <div class="row g-3"> {{-- align-items-stretch agar kolom chart sama tinggi --}}
                            <div class="col-12">
                                @include('partials.metric-display', [
                                    'metrics' => $valMetricsPerClass, // Ini sudah benar $validationMetricsData['metrics_per_class']
                                    'isDetector' => $isDetector,
                                    'datasetType' => 'Validasi',
                                    'posLabelOverride' => $valPosLabel,
                                    'negLabelOverride' => $valNegLabel,
                                ])
                            </div>
                            <div class="col-12">
                                <div class="metric-chart-container w-100 mt-3" data-chart-height="210px">
                                    <canvas id="metricsChart_{{ $chartIdSuffix }}_validation"></canvas>
                                    {{-- ID Benar --}}
                                </div>
                            </div>
                        </div>
                        @if ($valMatrixRaw)
                            <div class="mt-3"> {{-- Wrapper untuk CM agar bisa diberi margin atas --}}
                                @include('partials.confusion-matrix-table', [
                                    'tp' => $tp_val,
                                    'fn' => $fn_val,
                                    'fp' => $fp_val,
                                    'tn' => $tn_val,
                                    'posLabel' => $valPosLabel,
                                    'negLabel' => $valNegLabel,
                                ])
                            </div>
                        @else
                            <p class="text-muted small text-center mt-3 mb-0"><em>Matriks konfusi validasi tidak
                                    tersedia.</em></p>
                        @endif
                    @else
                        <div class="text-center py-4 text-muted">
                            <i class="far fa-folder-open fa-2x mb-2"></i>
                            <p class="mb-0">Metrik validasi tidak tersedia.</p>
                        </div>
                    @endif
                </div>
            </section>
        </div>

        {{-- Kolom Performa Test Set --}}
        <div class="col-xl-6 d-flex flex-column">
            <section class="model-section test-performance-section card shadow-sm flex-grow-1">
                <div class="card-header bg-light-subtle py-3">
                    <h6 class="mb-0 fw-semibold text-dark-emphasis d-flex align-items-center section-title-like">
                        <i class="fas fa-vial-circle-check me-2 text-warning"></i>Performa Set Test
                    </h6>
                </div>
                <div class="card-body p-3 p-lg-4">
                    @if ($testMetricsPerClass)
                        <div class="row g-3">
                            <div class="col-12">
                                @include('partials.metric-display', [
                                    'metrics' => $testMetricsPerClass, // Ini dari $testResultsData['metrics']
                                    'isDetector' => $isDetector,
                                    'datasetType' => 'Test',
                                    'posLabelOverride' => $testPosLabel,
                                    'negLabelOverride' => $testNegLabel,
                                ])
                            </div>
                            <div class="col-12">
                                <div class="metric-chart-container w-100 mt-3" data-chart-height="210px">
                                    <canvas id="metricsChart_{{ $chartIdSuffix }}_test"></canvas> {{-- ID Benar --}}
                                </div>
                            </div>
                        </div>
                        @if ($testMatrixRaw)
                            <div class="mt-3">
                                @include('partials.confusion-matrix-table', [
                                    'tp' => $tp_test,
                                    'fn' => $fn_test,
                                    'fp' => $fp_test,
                                    'tn' => $tn_test,
                                    'posLabel' => $testPosLabel,
                                    'negLabel' => $testNegLabel,
                                ])
                            </div>
                        @else
                            <p class="text-muted small text-center mt-3 mb-0"><em>Matriks konfusi test tidak
                                    tersedia.</em></p>
                        @endif
                    @else
                        <div class="text-center py-4 text-muted">
                            <i class="far fa-folder-open fa-2x mb-2"></i>
                            <p class="mb-0">Hasil evaluasi test set tidak tersedia.</p>
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>

    <hr class="my-4 custom-hr"> {{-- Pemisah yang lebih menonjol --}}

    {{-- Bagian 4: Analisis Stabilitas & Generalisasi Model (Menggunakan partial yang sudah dirombak) --}}
    @if ($advancedAnalysisDataForThisModel || $learningCurveRawData || $cvResultsRawData)
        <section class="model-section stability-analysis-section card shadow-sm mb-4">
            <div class="card-body p-3 p-lg-4">
                @include('partials.model-stability', [
                    'evalData' => $evalData,
                    'advancedAnalysisData' => $advancedAnalysisDataForThisModel,
                    'isDetector' => $isDetector,
                    'chartIdSuffix' => $chartIdSuffix,
                ])
            </div>
        </section>
    @else
        <section class="model-section stability-analysis-section card shadow-sm mb-4">
            <div class="card-header bg-light-subtle py-3">
                <h6 class="mb-0 fw-semibold text-dark-emphasis d-flex align-items-center section-title-like">
                    <i class="fas fa-analytics me-2 text-primary"></i>Analisis Stabilitas & Generalisasi
                </h6>
            </div>
            <div class="card-body p-3 p-lg-4 text-center py-5 text-muted">
                <i class="far fa-folder-open fa-2x mb-2"></i>
                <p class="mb-0">Data untuk analisis stabilitas (learning curve, CV, overfitting) tidak tersedia.</p>
            </div>
        </section>
    @endif
</div>
