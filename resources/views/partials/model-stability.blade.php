{{-- resources/views/partials/model-stability.blade.php --}}
{{-- Desain Ulang Bagian Analisis Stabilitas & Generalisasi Model --}}

@php
    // Helper functions
    $_formatPercent = fn($value, $digits = 1) => is_numeric($value)
        ? number_format((float) $value * 100, $digits) . '%'
        : 'N/A';
    $_formatScore = fn($value, $digits = 4) => is_numeric($value) ? number_format((float) $value, $digits) : 'N/A';

    // Variabel yang di-pass
    // $evalData (berisi data mentah CV: ['cv_results'] dan LC: ['learning_curve_data'])
    // $advancedAnalysisData (berisi hasil analisis: ['overfitting'], ['cross_validation'], ['stability'])
    // $isDetector (boolean)
    // $chartIdSuffix (string untuk ID canvas LC unik)

    $overfittingInfo = $advancedAnalysisData['overfitting'] ?? null;
    $cvProcessedInfo = $advancedAnalysisData['cross_validation'] ?? null; // Hasil CV yang sudah diproses (mean, std, CI)
    $lcStabilityInfo = $advancedAnalysisData['stability'] ?? null; // Rekomendasi & info konvergensi LC

    $learningCurveRaw = $evalData['learning_curve_data'] ?? null;
    $hasLearningCurveData = !empty($learningCurveRaw['train_sizes']);

    $cvResultsRaw = $evalData['cv_results'] ?? null;
    $kFolds = $cvResultsRaw['k_folds'] ?? 'N/A';
    // Statistik mentah per metrik dari CV (jika ingin menampilkan lebih banyak dari sekadar F1 & Akurasi)
    $cvStatsPerMetricRaw = $cvResultsRaw['stats_per_metric'] ?? [];
    // Skor mentah per fold untuk F1 positif (digunakan untuk detail collapse)
    $posLabelForCV = $isDetector ? 'Melon' : 'Ripe';
    $cvF1PositiveKeyForFoldDetails = $isDetector ? 'f1_melon' : 'f1_ripe';
    $cvF1ScoresPerFold = $cvResultsRaw['metrics_per_fold'][$cvF1PositiveKeyForFoldDetails] ?? [];

@endphp

<div class="model-stability-analysis-container">
    <h6 class="section-title">
        <i class="fas fa-square-binary text-primary"></i>Stabilitas Model
    </h6>

    <div class="row g-lg-4 g-3">
        {{-- Kolom Kiri: Overfitting & Detail Cross-Validation --}}
        <div class="col-lg-6 d-flex flex-column">

            {{-- 1. Analisis Overfitting --}}
            <div class="sub-section-card card shadow-sm mb-3 flex-grow-1">
                <div class="card-header bg-light-subtle py-2">
                    <h6 class="mb-0 fw-semibold text-dark-emphasis small d-flex align-items-center">
                        <i class="fas fa-search-plus me-2 text-info"></i>Deteksi Overfitting
                    </h6>
                </div>
                <div class="card-body p-3">
                    @if ($overfittingInfo)
                        <div
                            class="alert alert-{{ $overfittingInfo['severity'] === 'high' ? 'danger' : ($overfittingInfo['severity'] === 'medium' ? 'warning' : 'info') }} p-2 mb-0 small">
                            <strong class="d-block mb-1">
                                @if ($overfittingInfo['is_overfitting'])
                                    <i class="fas fa-exclamation-triangle me-1"></i>Potensi Overfitting (Level:
                                    {{ Str::title($overfittingInfo['severity']) }})
                                @else
                                    <i class="fas fa-check-circle me-1"></i>Tidak Ada Overfitting Signifikan
                                @endif
                            </strong>
                            <div>Selisih Performa: <span
                                    class="fw-bold">{{ number_format($overfittingInfo['difference'], 1) }}%</span></div>
                            <div class="mt-1 pt-1 border-top border-secondary-subtle">
                                <small>Akurasi Training: <span
                                        class="fw-semibold">{{ $_formatPercent($overfittingInfo['train_score']) }}</span></small><br>
                                <small>Akurasi Validasi: <span
                                        class="fw-semibold">{{ $_formatPercent($overfittingInfo['validation_score']) }}</span></small>
                            </div>
                        </div>
                    @else
                        <p class="text-muted small fst-italic mb-0">Analisis overfitting tidak tersedia.</p>
                    @endif
                </div>
            </div>

            {{-- 2. Ringkasan Cross-Validation --}}
            <div class="sub-section-card card shadow-sm flex-grow-1">
                <div class="card-header bg-light-subtle py-2">
                    <h6 class="mb-0 fw-semibold text-dark-emphasis small d-flex align-items-center">
                        <i class="fas fa-sync-alt me-2 text-info"></i>Ringkasan Validasi Silang
                        ({{ $kFolds }}-Fold)
                    </h6>
                </div>
                <div class="card-body p-3">
                    @if ($cvProcessedInfo && isset($cvProcessedInfo['stats']))
                        @php
                            $cvAccuracyMean =
                                $cvProcessedInfo['stats']['mean'] ??
                                ($cvStatsPerMetricRaw[$cvAccuracyKey]['mean'] ?? null);
                            $cvAccuracyStd =
                                $cvProcessedInfo['stats']['std'] ??
                                ($cvStatsPerMetricRaw[$cvAccuracyKey]['std'] ?? null);
                            $cvF1PosMean = $cvStatsPerMetricRaw[$cvF1PositiveKeyForFoldDetails]['mean'] ?? null;
                            $cvF1PosStd = $cvStatsPerMetricRaw[$cvF1PositiveKeyForFoldDetails]['std'] ?? null;
                            $cvConfidenceInterval = $cvProcessedInfo['confidence_interval'] ?? null;
                        @endphp

                        <ul class="list-unstyled mb-0 small cv-summary-list">
                            @if ($cvAccuracyMean !== null)
                                <li class="cv-stat-item">
                                    <span class="stat-label"><i
                                            class="fas fa-tachometer-alt-average me-1 text-muted"></i>Rata-rata
                                        Akurasi</span>
                                    <span class="stat-value fw-bold">{{ $_formatScore($cvAccuracyMean) }} ±
                                        {{ $_formatScore($cvAccuracyStd) }}</span>
                                </li>
                            @endif
                            @if ($cvF1PosMean !== null)
                                <li class="cv-stat-item">
                                    <span class="stat-label"><i class="fas fa-medal me-1 text-muted"></i>Rata-rata F1
                                        ({{ $posLabelForCV }})</span>
                                    <span class="stat-value fw-bold">{{ $_formatScore($cvF1PosMean) }} ±
                                        {{ $_formatScore($cvF1PosStd) }}</span>
                                </li>
                            @endif
                            @if ($cvConfidenceInterval && $cvConfidenceInterval['lower'] !== null)
                                <li class="cv-stat-item">
                                    <span class="stat-label" data-bs-toggle="tooltip"
                                        title="95% Confidence Interval untuk rata-rata akurasi">
                                        <i class="fas fa-arrows-h me-1 text-muted"></i>95% CI Akurasi
                                    </span>
                                    <span
                                        class="stat-value fw-bold">[{{ $_formatScore($cvConfidenceInterval['lower']) }}
                                        – {{ $_formatScore($cvConfidenceInterval['upper']) }}]</span>
                                </li>
                            @endif
                        </ul>

                        @if (!empty($cvF1ScoresPerFold))
                            <div class="cv-details-table mt-3"> {{-- Ganti dari cv-details-collapse --}}
                                <h6 class="small text-muted mb-2"><i class="fas fa-list-ol me-1"></i>Detail F1-Score
                                    ({{ $posLabelForCV }}) per Fold:</h6>
                                <div class="table-responsive" style="max-height: 180px; overflow-y: auto;">
                                    {{-- Tambahkan scroll jika banyak fold --}}
                                    <table class="table table-sm table-striped table-hover small border rounded-2">
                                        <thead class="table-light sticky-top" style="font-size: 0.75rem;">
                                            {{-- Buat header tabel sticky --}}
                                            <tr>
                                                <th scope="col" class="ps-2">Fold</th>
                                                <th scope="col" class="text-end pe-2">F1-Score
                                                    ({{ $posLabelForCV }})</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($cvF1ScoresPerFold as $fold => $score)
                                                <tr>
                                                    <td class="ps-2">Fold {{ $fold + 1 }}</td>
                                                    <td class="text-end pe-2 fw-medium">{{ $_formatScore($score) }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    @else
                        <p class="text-muted small fst-italic mb-0">Statistik cross-validation tidak tersedia.</p>
                    @endif
                </div>
            </div>
        </div>

        {{-- Kolom Kanan: Learning Curve --}}
        <div class="col-lg-6 d-flex flex-column">
            <div class="sub-section-card card shadow-sm learning-curve-section flex-grow-1">
                <div class="card-header bg-light-subtle py-2">
                    <h6 class="mb-0 fw-semibold text-dark-emphasis small d-flex align-items-center">
                        <i class="fas fa-chart-line me-2 text-info"></i>Kurva Pembelajaran (Learning Curve)
                    </h6>
                </div>
                <div class="card-body p-3 d-flex flex-column">
                    @if ($hasLearningCurveData)
                        @if ($lcStabilityInfo && isset($lcStabilityInfo['recommendation']))
                            <div class="alert alert-info bg-info-subtle text-info-emphasis small p-3 mb-3 shadow-sm">
                                <strong class="d-block mb-1">
                                    <i class="fas fa-comment-alt-medical me-1"></i> Interpretasi Kurva:
                                </strong>
                                {{ $lcStabilityInfo['recommendation'] }}
                                <div class="mt-2 pt-2 border-top border-info-subtle text-muted-dark">
                                    <small>
                                        Gap Skor Akhir:
                                        <span
                                            class="fw-semibold">{{ $lcStabilityInfo['final_gap'] !== null ? $_formatPercent($lcStabilityInfo['final_gap']) : 'N/A' }}</span>
                                        <span class="mx-1">|</span>
                                        Konvergen:
                                        <span
                                            class="fw-semibold">{{ $lcStabilityInfo['has_convergence'] ? 'Ya' : 'Belum' }}</span>
                                        @if (
                                            $lcStabilityInfo['has_convergence'] &&
                                                $lcStabilityInfo['convergence_point_index'] !== null &&
                                                isset($learningCurveRaw['train_sizes'][$lcStabilityInfo['convergence_point_index']]))
                                            (±
                                            {{ $learningCurveRaw['train_sizes'][$lcStabilityInfo['convergence_point_index']] }}
                                            sampel)
                                        @endif
                                    </small>
                                </div>
                            </div>
                        @endif
                        <div class="learning-curve-graphic-container mt-auto flex-grow-1" data-chart-height="230px">
                            <canvas id="learningCurve_{{ $chartIdSuffix }}"></canvas> {{-- ID Benar --}}
                        </div>
                    @else
                        <div
                            class="text-center py-5 h-100 d-flex flex-column justify-content-center align-items-center">
                            <i class="far fa-chart-line fa-3x text-muted mb-3"></i>
                            <p class="text-muted mb-0"><em>Data learning curve tidak tersedia untuk model ini.</em></p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
