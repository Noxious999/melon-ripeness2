{{-- resources/views/partials/metric-display.blade.php --}}
{{-- Desain Ulang Tampilan Blok Metrik Performa --}}

@php
    // Helper untuk formatting angka, di-pass atau didefinisikan di parent jika perlu
    $_formatPercentGlobal = fn($value, $digits = 1) => is_numeric($value)
        ? number_format((float) $value * 100, $digits) . '%'
        : 'N/A';
    $_formatScoreGlobal = fn($value, $digits = 4) => is_numeric($value)
        ? number_format((float) $value, $digits)
        : 'N/A';

    // Variabel yang di-pass dari evaluation-card-content.blade.php
    // $metrics (berisi ['accuracy'], ['positive'], ['negative'])
    // $isDetector (boolean)
    // $datasetType (string, misal: 'Validasi' atau 'Test') // Ini yang menyebabkan error
    // $posLabelOverride (string, opsional)
    // $negLabelOverride (string, opsional)

    // Buat variabel $datasetType lebih defensif
    $displayDatasetType = isset($datasetType) ? $datasetType : 'Tidak Diketahui';
    $displayDatasetTypeLower = isset($datasetType) ? strtolower($datasetType) : 'set ini';

    $accuracy = $metrics['accuracy'] ?? null;

    // Data untuk Kelas Positif
    $posData = $metrics['positive'] ?? [];
    $defaultPosLabel = $isDetector ? 'Melon' : 'Matang';
    $posLabel = $posLabelOverride ?? ucfirst(Str::replace('_', ' ', $posData['label'] ?? $defaultPosLabel));
    $precisionPos = $posData['precision'] ?? null;
    $recallPos = $posData['recall'] ?? null;
    $f1Pos = $posData['f1_score'] ?? null;
    $supportPos = $posData['support'] ?? 0;

    // Data untuk Kelas Negatif
    $negData = $metrics['negative'] ?? [];
    $defaultNegLabel = $isDetector ? 'Non-Melon' : 'Belum Matang';
    $negLabel = $negLabelOverride ?? ucfirst(Str::replace('_', ' ', $negData['label'] ?? $defaultNegLabel));
    $precisionNeg = $negData['precision'] ?? null;
    $recallNeg = $negData['recall'] ?? null; // Specificity seringkali adalah recall kelas negatif
    $f1Neg = $negData['f1_score'] ?? null;
    $supportNeg = $negData['support'] ?? 0;

    // Fungsi untuk mendapatkan kelas warna berdasarkan skor (0-1)
    $getScoreColorClass = function ($score) {
        if (!is_numeric($score)) {
            return 'text-muted';
        } // Default jika N/A
        if ($score >= 0.85) {
            return 'text-success-emphasis';
        } // Sangat Baik
        if ($score >= 0.7) {
            return 'text-primary-emphasis';
        } // Baik
        if ($score >= 0.5) {
            return 'text-warning-emphasis';
        } // Cukup
        return 'text-danger-emphasis'; // Kurang
    };

    $idPrefix = Str::slug(
        $metrics['model_key_for_id'] ?? ($isDetector ? 'detector_pred_metrics' : 'classifier_pred_metrics'),
        '_',
    );
@endphp

<div class="metrics-display-wrapper bg-white rounded shadow-sm p-3 p-lg-4" id="metrics-wrapper-{{ $idPrefix }}">
    @if ($metrics && isset($posData) && isset($negData))
        <div class="metric-overall text-center mb-3 pb-3 border-bottom">
            <h6 class="metric-title text-muted small text-uppercase fw-semibold" data-bs-toggle="tooltip"
                data-bs-placement="top" title="Proporsi prediksi yang benar secara keseluruhan.">
                <i class="fas fa-bullseye me-1"></i>Akurasi Keseluruhan
            </h6>
            <p class="metric-value display-5 fw-bold {{ $getScoreColorClass($accuracy) }} mb-0"
                id="accuracy-{{ $idPrefix }}">
                {{ $_formatPercentGlobal($accuracy) }}
            </p>
        </div>

        <p class="metric-set-info text-center text-muted small mb-3">
            <i class="fas fa-info-circle me-1"></i>
            Menampilkan metrik performa untuk dataset <strong>{{ $displayDatasetType }}</strong>.
        </p>

        <div class="row gx-lg-4 gy-3 text-center">
            {{-- Kolom Kelas Positif --}}
            <div class="col-md-6 metric-group border-end-md">
                <h6 class="metric-group-title fw-semibold text-primary mb-2 pb-1 border-bottom border-primary d-inline-block"
                    id="pos-label-{{ $idPrefix }}">
                    <i class="fas fa-plus-circle me-1"></i>{{ $posLabel }} (Positif)
                </h6>
                <div class="metric-row">
                    <span class="metric-label" data-bs-toggle="tooltip" data-bs-placement="top"
                        title="Dari semua yang diprediksi '{{ $posLabel }}', berapa persen yang benar '{{ $posLabel }}'.">
                        <i class="fas fa-crosshairs fa-fw me-1 text-muted"></i>Presisi
                    </span>
                    <span class="metric-value fw-bold {{ $getScoreColorClass($precisionPos) }}"
                        id="precision-pos-{{ $idPrefix }}">
                        {{ $_formatPercentGlobal($precisionPos) }}
                    </span>
                </div>
                <div class="metric-row">
                    <span class="metric-label" data-bs-toggle="tooltip" data-bs-placement="top"
                        title="Dari semua yang aktual '{{ $posLabel }}', berapa persen yang berhasil diprediksi '{{ $posLabel }}'. Juga dikenal sebagai Sensitivity atau True Positive Rate.">
                        <i class="fas fa-bullhorn fa-fw me-1 text-muted"></i>Recall
                    </span>
                    <span class="metric-value fw-bold {{ $getScoreColorClass($recallPos) }}"
                        id="recall-pos-{{ $idPrefix }}">
                        {{ $_formatPercentGlobal($recallPos) }}
                    </span>
                </div>
                <div class="metric-row">
                    <span class="metric-label" data-bs-toggle="tooltip" data-bs-placement="top"
                        title="F1-Score: Rata-rata harmonik dari Presisi dan Recall.">
                        <i class="fas fa-medal fa-fw me-1 text-muted"></i>F1-Score
                    </span>
                    <span class="metric-value fw-bold {{ $getScoreColorClass($f1Pos) }}"
                        id="f1-pos-{{ $idPrefix }}">
                        {{ $_formatPercentGlobal($f1Pos) }}
                    </span>
                </div>
                <div class="metric-row mt-2 pt-2 border-top">
                    <span class="metric-label small text-muted">
                        <i class="fas fa-users fa-fw me-1 text-muted"></i>Jumlah Dataset Aktual
                    </span>
                    <span class="metric-value fw-bold"
                        id="support-pos-{{ $idPrefix }}">{{ number_format($supportPos) }}</span>
                </div>
            </div>

            {{-- Kolom Kelas Negatif --}}
            <div class="col-md-6 metric-group">
                <h6 class="metric-group-title fw-semibold text-secondary mb-2 pb-1 border-bottom border-secondary d-inline-block"
                    id="neg-label-{{ $idPrefix }}">
                    <i class="fas fa-minus-circle me-1"></i>{{ $negLabel }} (Negatif)
                </h6>
                <div class="metric-row">
                    <span class="metric-label" data-bs-toggle="tooltip" data-bs-placement="top"
                        title="Dari semua yang diprediksi '{{ $negLabel }}', berapa persen yang benar '{{ $negLabel }}'.">
                        <i class="fas fa-crosshairs fa-fw me-1 text-muted"></i>Presisi
                    </span>
                    <span class="metric-value fw-bold {{ $getScoreColorClass($precisionNeg) }}"
                        id="precision-neg-{{ $idPrefix }}">
                        {{ $_formatPercentGlobal($precisionNeg) }}
                    </span>
                </div>
                <div class="metric-row">
                    <span class="metric-label" data-bs-toggle="tooltip" data-bs-placement="top"
                        title="Dari semua yang aktual '{{ $negLabel }}', berapa persen yang berhasil diprediksi '{{ $negLabel }}'. Juga dikenal sebagai Specificity atau True Negative Rate.">
                        <i class="fas fa-shield-alt fa-fw me-1 text-muted"></i>Recall/Specificity
                    </span>
                    <span class="metric-value fw-bold {{ $getScoreColorClass($recallNeg) }}"
                        id="recall-neg-{{ $idPrefix }}">
                        {{ $_formatPercentGlobal($recallNeg) }}
                    </span>
                </div>
                <div class="metric-row">
                    <span class="metric-label" data-bs-toggle="tooltip" data-bs-placement="top"
                        title="F1-Score: Rata-rata harmonik dari Presisi dan Recall.">
                        <i class="fas fa-medal fa-fw me-1 text-muted"></i>F1-Score
                    </span>
                    <span class="metric-value fw-bold {{ $getScoreColorClass($f1Neg) }}"
                        id="f1-neg-{{ $idPrefix }}">
                        {{ $_formatPercentGlobal($f1Neg) }}
                    </span>
                </div>
                <div class="metric-row mt-2 pt-2 border-top">
                    <span class="metric-label small text-muted">
                        <i class="fas fa-users fa-fw me-1 text-muted"></i>Jumlah Dataset Aktual
                    </span>
                    <span class="metric-value fw-bold"
                        id="support-neg-{{ $idPrefix }}">{{ number_format($supportNeg) }}</span>
                </div>
            </div>
        </div>
    @else
        <div class="text-center py-4 text-muted" id="no-metrics-message-{{ $idPrefix }}"> {{-- Tambahkan ID juga di sini --}}
            <i class="fas fa-chart-bar fa-2x mb-3"></i>
            <p class="mb-0"><em>Data metrik performa tidak tersedia untuk {{ $displayDatasetTypeLower }}.</em></p>
        </div>
    @endif
</div>
