{{-- resources/views/partials/analysis-summary.blade.php --}}
{{-- Desain Ulang Kartu Analisis & Ringkasan --}}

@php
    // Variabel dari parent view (evaluate.blade.php)
    // $dynamicAnalysis (HTML)
    // $lastClassifierTimeFormatted, $lastDetectorTimeFormatted
    // $rankedClassifiers, $rankedDetectors (BARU: array hasil ranking)
    // $bestClassifierKey, $bestDetectorKey (BARU: kunci model terbaik)

    $_formatScoreForSummary = fn($value, $digits = 3) => is_numeric($value)
        ? number_format((float) $value, $digits)
        : 'N/A';
@endphp

<div class="analysis-summary-container">
    {{-- Kartu untuk Analisis Dinamis (Rekomendasi & Isu) --}}
    <div class="card shadow-sm mb-4 analysis-card">
        <div class="card-header bg-primary-gradient text-white py-3">
            <h5 class="mb-0 d-flex align-items-center">
                <i class="fas fa-lightbulb fa-lg me-2"></i> Rekomendasi & Analisis Isu Umum
            </h5>
        </div>
        <div class="card-body analysis-content p-lg-4">
            @isset($dynamicAnalysis)
                @if (!empty(trim(strip_tags($dynamicAnalysis))))
                    {!! $dynamicAnalysis !!}
                @else
                    <div class="text-center py-4">
                        <i class="fas fa-info-circle fa-2x text-muted mb-3"></i>
                        <p class="text-muted">Tidak ada analisis atau rekomendasi spesifik yang dapat dihasilkan saat ini.
                        </p>
                        <small class="text-muted">Pastikan model telah dilatih dan dievaluasi.</small>
                    </div>
                @endif
            @else
                <div class="text-center py-4">
                    <i class="fas fa-hourglass-half fa-2x text-muted mb-3"></i>
                    <p class="text-muted">Hasil evaluasi model belum tersedia untuk dianalisis.</p>
                </div>
            @endisset
        </div>
    </div>

    {{-- BARU: Kartu untuk Ranking Model --}}
    <div class="row g-4 mb-4">
        {{-- Ranking Model Klasifikasi --}}
        <div class="col-lg-6">
            <div class="card shadow-sm model-ranking-card h-100">
                <div class="card-header bg-success-subtle py-3">
                    <h6 class="mb-0 text-success-emphasis fw-semibold">
                        <i class="fas fa-trophy me-2"></i>Peringkat Model Klasifikasi
                    </h6>
                </div>
                <div class="card-body p-3">
                    @if (!empty($rankedClassifiers))
                        <p class="small text-muted mb-2">Berdasarkan skor gabungan (F1 Positif, Akurasi, Presisi,
                            Recall) pada set validasi.</p>
                        <div class="table-responsive" style="max-height: 300px;">
                            <table class="table table-sm table-hover small align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col" class="ps-2">#</th>
                                        <th scope="col">Model</th>
                                        <th scope="col" class="text-center" data-bs-toggle="tooltip"
                                            title="Skor Gabungan Terbobot">Skor</th>
                                        <th scope="col" class="text-center" data-bs-toggle="tooltip"
                                            title="F1-Score Kelas Positif">F1 (+)</th>
                                        <th scope="col" class="text-center" data-bs-toggle="tooltip" title="Akurasi">
                                            Akurasi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rankedClassifiers as $index => $model)
                                        <tr class="{{ $model['key'] === $bestClassifierKey ? 'table-primary' : '' }}">
                                            <td class="ps-2 fw-bold">{{ $index + 1 }}</td>
                                            <td>
                                                {{ $model['name'] }}
                                                @if ($model['key'] === $bestClassifierKey)
                                                    <i class="fas fa-star text-warning fa-xs ms-1"
                                                        title="Model Klasifikasi Terbaik"></i>
                                                @endif
                                            </td>
                                            <td class="text-center fw-medium">
                                                {{ $_formatScoreForSummary($model['combined_score']) }}</td>
                                            <td class="text-center">{{ $_formatScoreForSummary($model['f1_positive']) }}
                                            </td>
                                            <td class="text-center">{{ $_formatScoreForSummary($model['accuracy']) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted text-center py-3"><em>Data peringkat model klasifikasi tidak tersedia.</em>
                        </p>
                    @endif
                </div>
            </div>
        </div>

        {{-- Ranking Model Deteksi --}}
        <div class="col-lg-6">
            <div class="card shadow-sm model-ranking-card h-100">
                <div class="card-header bg-info-subtle py-3">
                    <h6 class="mb-0 text-info-emphasis fw-semibold">
                        <i class="fas fa-award me-2"></i>Peringkat Model Deteksi
                    </h6>
                </div>
                <div class="card-body p-3">
                    @if (!empty($rankedDetectors))
                        <p class="small text-muted mb-2">Berdasarkan skor gabungan (F1 Positif, Akurasi, Presisi,
                            Recall) pada set validasi.</p>
                        <div class="table-responsive" style="max-height: 300px;">
                            <table class="table table-sm table-hover small align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col" class="ps-2">#</th>
                                        <th scope="col">Model</th>
                                        <th scope="col" class="text-center" data-bs-toggle="tooltip"
                                            title="Skor Gabungan Terbobot">Skor</th>
                                        <th scope="col" class="text-center" data-bs-toggle="tooltip"
                                            title="F1-Score Kelas Positif">F1 (+)</th>
                                        <th scope="col" class="text-center" data-bs-toggle="tooltip" title="Akurasi">
                                            Akurasi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rankedDetectors as $index => $model)
                                        <tr class="{{ $model['key'] === $bestDetectorKey ? 'table-primary' : '' }}">
                                            <td class="ps-2 fw-bold">{{ $index + 1 }}</td>
                                            <td>
                                                {{ $model['name'] }}
                                                @if ($model['key'] === $bestDetectorKey)
                                                    <i class="fas fa-star text-warning fa-xs ms-1"
                                                        title="Model Deteksi Terbaik"></i>
                                                @endif
                                            </td>
                                            <td class="text-center fw-medium">
                                                {{ $_formatScoreForSummary($model['combined_score']) }}</td>
                                            <td class="text-center">
                                                {{ $_formatScoreForSummary($model['f1_positive']) }}</td>
                                            <td class="text-center">{{ $_formatScoreForSummary($model['accuracy']) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted text-center py-3"><em>Data peringkat model deteksi tidak tersedia.</em></p>
                    @endif
                </div>
            </div>
        </div>
    </div>


    {{-- Kartu untuk Informasi Training Terakhir (Tetap Sama) --}}
    <div class="card shadow-sm training-info-card">
        <div class="card-header bg-light py-3">
            <h6 class="mb-0 text-primary-emphasis">
                <i class="fas fa-history me-2 text-primary"></i>Informasi Pelatihan Model Terakhir
            </h6>
        </div>
        <div class="card-body p-lg-4">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="info-box p-3 border rounded bg-light-subtle text-center h-100">
                        <i class="fas fa-tasks-alt fa-2x text-primary mb-2"></i>
                        <h6 class="small text-muted text-uppercase fw-semibold">Model Klasifikasi</h6>
                        <p class="mb-0 fw-bold text-dark fs-5">{{ $lastClassifierTimeFormatted ?? 'Belum Ada Data' }}
                        </p>
                        @if (($lastClassifierTimeFormatted ?? 'Belum Ada Data') === 'Belum Ada Data')
                            <small class="text-danger d-block mt-1">Model belum pernah dilatih.</small>
                        @endif
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-box p-3 border rounded bg-light-subtle text-center h-100">
                        <i class="fas fa-object-group fa-2x text-info mb-2"></i>
                        <h6 class="small text-muted text-uppercase fw-semibold">Model Deteksi</h6>
                        <p class="mb-0 fw-bold text-dark fs-5">{{ $lastDetectorTimeFormatted ?? 'Belum Ada Data' }}</p>
                        @if (($lastDetectorTimeFormatted ?? 'Belum Ada Data') === 'Belum Ada Data')
                            <small class="text-danger d-block mt-1">Model belum pernah dilatih.</small>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
