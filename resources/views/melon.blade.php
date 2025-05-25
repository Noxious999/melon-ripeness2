<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Prediksi Kematangan Melon</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        // Variabel global untuk URL dan data awal, diakses oleh app.js
        window.uploadImageForPredictUrl = "{{ route('predict.upload_image_temp') }}";
        window.predictDefaultUrl = "{{ route('predict.default') }}";
        window.getAllResultsUrl = "{{ route('predict.all_results') }}";
        window.feedbackDetectionUrl = "{{ route('feedback.detection') }}";
        window.feedbackClassificationUrl = "{{ route('feedback.classification') }}";
        window.annotateUrlGlobal = "{{ route('annotate.index') }}"; // Diubah dari annotateUrl
        window.clearCacheUrl = "{{ route('app.clear_cache') }}";
        window.csrfToken = "{{ csrf_token() }}";
        window.resultData = @json($result ?? ['filename' => null, 'context' => null]);
        window.modelKeysForView = @json($modelKeysForView ?? []);
        window.initialPendingAnnotationCount = {{ $pendingAnnotationCount ?? 0 }};
        window.triggerPiCameraUrl = "{{ route('api.trigger_pi_camera') }}"; // URL untuk trigger Pi
        window.runBboxClassifyOnDemandUrl = "{{ route('predict.run_bbox_classify_on_demand') }}"; // TAMBAHKAN INI
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-light">
    @php
        $lastClassifierTimeFormatted = $lastClassifierTimeFormatted ?? 'Belum tersedia';
        $lastDetectorTimeFormatted = $lastDetectorTimeFormatted ?? 'Belum tersedia';
    @endphp

    <div class="dashboard-container container-xl my-4">
        {{-- Area Notifikasi (Selalu Tampil) --}}
        <div id="notification-area-main" class="mb-4 position-sticky top-0" style="z-index: 1050;">
            {{-- Notifikasi akan ditambahkan oleh JavaScript di sini --}}
            @if (session('success'))
                <div class="alert alert-success alert-dismissible fade show"><i
                        class="fas fa-check-circle me-2"></i>{{ session('success') }} <button type="button"
                        class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
            @endif
            @if (session('warning'))
                <div class="alert alert-warning alert-dismissible fade show"><i
                        class="fas fa-exclamation-triangle me-2"></i>{{ session('warning') }} <button type="button"
                        class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
            @endif
            @if (session('error'))
                <div class="alert alert-danger alert-dismissible fade show"><i
                        class="fas fa-times-circle me-2"></i>{{ is_string(session('error')) ? session('error') : 'Terjadi kesalahan tidak terduga.' }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif
            @if ($errors->any())
                <div class="alert alert-danger alert-dismissible fade show"><i
                        class="fas fa-times-circle me-2"></i><strong>Error Validasi:</strong>
                    <ul class="mb-0 ps-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif
        </div>

        {{-- Notifikasi Anotasi Pending --}}
        @if (isset($pendingAnnotationCount) && $pendingAnnotationCount > 0)
            <div id="pending-annotation-reminder-container"
                class="alert alert-info alert-dismissible fade show small py-2 px-3 mb-3" role="alert">
                <i class="fas fa-info-circle me-2"></i>
                Terdapat <strong>{{ $pendingAnnotationCount }}</strong> gambar menunggu anotasi BBox manual di halaman
                <a href="{{ route('annotate.index') }}" class="alert-link fw-bold">Anotasi Gambar</a>.
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        {{-- Notifikasi Perubahan Dataset (Anjuran Training Ulang) --}}
        @if (isset($showDatasetChangeNotification) && $showDatasetChangeNotification && isset($datasetChangeSummary))
            <div id="dataset-change-notification" class="alert alert-warning alert-dismissible fade show shadow-sm mb-4"
                role="alert">
                <h5 class="alert-heading"><i class="fas fa-sync-alt me-2"></i>Dataset Telah Diperbarui!</h5>
                <p class="mb-1 small">
                    Perubahan terakhir terdeteksi:
                    @if ($datasetChangeSummary['type_display'] ?? null)
                        <strong>{{ $datasetChangeSummary['type_display'] }}</strong>
                    @endif
                    @if ($datasetChangeSummary['identifier_display'] ?? null)
                        pada file/entitas <em>{{ $datasetChangeSummary['identifier_display'] }}</em>
                    @endif
                    @if ($datasetChangeSummary['time_ago'] ?? null)
                        (sekitar {{ $datasetChangeSummary['time_ago'] }}).
                    @endif
                </p>
                <p class="mb-2">
                    Sangat disarankan untuk melakukan <strong>Ekstraksi Fitur Ulang</strong> dan <strong>Training Ulang
                        Model</strong>
                    melalui <a href="{{ route('evaluate.index') }}#control-quality-subtab"
                        class="alert-link fw-bold">halaman Evaluasi (tab Kontrol Kualitas)</a>
                    untuk memastikan model Anda menggunakan data terbaru.
                </p>
                <hr class="my-2">
                <div class="d-flex justify-content-end">
                    <button type="button" class="btn btn-sm btn-outline-secondary mark-changes-seen-btn"
                        data-url="{{ route('dataset.changes.mark_seen') }}">
                        <i class="fas fa-check me-1"></i> Oke, Saya Mengerti
                    </button>
                </div>
            </div>
        @endif

        <h1 class="mb-4"><i class="fas fa-seedling me-2"></i>PREDIKSI KEMATANGAN MELON</h1>

        <div class="main-actions text-center mb-5">
            <a href="{{ route('annotate.index') }}" class="btn btn-outline-secondary me-2"><i
                    class="fas fa-pencil-alt me-1"></i> Anotasi Manual</a>
            <a href="{{ route('evaluate.index') }}" class="btn btn-outline-primary me-2"><i
                    class="fas fa-chart-line me-1"></i> Evaluasi & Dataset</a>
            <button id="clear-app-cache-btn" class="btn btn-outline-warning btn-sm"><i class="fas fa-broom me-1"></i>
                Bersihkan Cache</button>
        </div>

        {{-- Card untuk Input Prediksi dengan Toggle --}}
        <div class="card shadow-sm mb-5 prediction-input-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>MODE INPUT PREDIKSI</h5>
                {{-- Toggle Switch --}}
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="prediction-mode-toggle" checked>
                    <label class="form-check-label small" for="prediction-mode-toggle" id="prediction-mode-label">Mode:
                        Unggah Manual</label>
                </div>
            </div>
            <div class="card-body">
                {{-- Area untuk Upload Manual (Default Aktif) --}}
                <div id="upload-manual-section">
                    <form id="upload-form" action="{{ route('predict.default') }}" method="POST"
                        enctype="multipart/form-data">
                        @csrf
                        <div class="input-group mb-2">
                            <input type="file" name="image" class="form-control form-control-lg" required
                                accept="image/jpeg, image/png, image/jpg, image/webp" aria-label="Pilih gambar melon">
                            <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-search me-1"></i>
                                Analisis Gambar</button>
                        </div>
                        <small class="form-text text-muted">Maksimal 5MB (Format: JPEG, PNG, JPG, WEBP)</small>
                    </form>
                </div>

                {{-- Area untuk Trigger Raspberry Pi (Default Tersembunyi) --}}
                <div id="receive-pi-section" class="text-center" style="display: none;">
                    <p class="text-muted small mb-3">Gunakan Raspberry Pi untuk mengambil gambar dan melakukan prediksi
                        otomatis.</p>
                    <button id="trigger-pi-camera-btn" class="btn btn-lg btn-primary">
                        <i class="fas fa-camera-retro me-2"></i> Potret & Prediksi via Raspberry Pi
                    </button>
                    <div id="pi-status-display" class="mt-3 small">
                        {{-- Status dari Raspberry Pi akan ditampilkan di sini oleh JavaScript --}}
                    </div>
                </div>
            </div>
        </div>


        {{-- Bagian Hasil Analisis (Awalnya Disembunyikan, JS akan menampilkan) --}}
        <div id="result-section" class="d-none">

            <div class="text-center mb-4">
                <h4 class="fw-semibold"><i class="fas fa-file-image me-2"></i>Hasil Analisis: <span class="fw-normal"
                        id="result-filename-display">N/A</span></h4>
            </div>

            {{-- Pesan Error Pipeline (jika ada error global saat prediksi) --}}
            <div id="pipeline-error-display" class="alert alert-danger mb-4" style="display: none;"></div>

            {{-- BARIS UNTUK GAMBAR ORIGINAL & HASIL DETEKSI (HANYA GAMBAR) --}}
            <div class="row g-lg-4 g-md-3 g-2 mb-4">
                <div class="col-lg-6">
                    <div class="card image-only-card h-100">
                        <div class="card-header"><i class="fas fa-image me-2"></i>Gambar Original</div>
                        <div class="card-body p-2">
                            <div class="image-display-area">
                                <img id="uploaded-image" src="#" alt="Gambar Original"
                                    style="display: none;">
                                <p class="text-center text-muted p-3" id="uploaded-image-placeholder">Pilih gambar</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="card image-only-card h-100">
                        <div class="card-header"><i class="fas fa-magic-wand-sparkles me-2"></i>Gambar Hasil Deteksi
                        </div>
                        <div class="card-body p-2">
                            <div class="image-display-area">
                                <div style="position: relative; width:100%; height:100%;">
                                    <img id="detection-image-display" src="#" alt="Hasil Deteksi"
                                        style="display: none;">
                                    <p class="text-center text-muted p-3" id="detection-image-placeholder">Menunggu
                                        hasil</p>
                                    <div id="bbox-overlay" class="melon-bbox" style="display: none;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- CARD: RINGKASAN UTAMA DETEKSI & KLASIFIKASI --}}
            <div id="main-summary-card" class="card mb-4" style="display: none;">
                <div class="card-header"><i class="fas fa-poll me-2"></i>Ringkasan Prediksi</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3 mb-md-0">
                            <h6 class="fw-semibold mb-2 text-center border-bottom pb-2">Deteksi</h6>
                            <div class="mb-2">
                                <small class="text-muted">Model:</small>
                                <span id="summary-detector-model-name" class="fw-medium">N/A</span>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Status:</small>
                                <span id="summary-detector-badge" class="badge">N/A</span>
                            </div>
                            <div>
                                <small class="text-muted">Keyakinan (Melon):</small>
                                <div class="confidence-visualization d-flex align-items-center w-100">
                                    <div class="confidence-bar-container flex-grow-1">
                                        <div class="confidence-bar" id="summary-detector-confidence-bar"
                                            style="width: 0%;"></div>
                                    </div>
                                    <span class="confidence-value-text ms-2"
                                        id="summary-detector-confidence-text">0%</span>
                                </div>
                            </div>
                            <div id="summary-bbox-status-message" class="small text-warning mt-2"
                                style="display: none;">
                                <i class="fas fa-exclamation-triangle me-1"></i>Estimasi BBox Gagal
                            </div>
                        </div>
                        <div class="col-md-6" id="summary-classification-column" style="display: none;">
                            <h6 class="fw-semibold mb-2 text-center border-bottom pb-2">Klasifikasi</h6>
                            <div class="mb-2">
                                <small class="text-muted">Model:</small>
                                <span id="summary-classifier-model-name" class="fw-medium">N/A</span>
                            </div>
                            <div class="mb-2">
                                <small class="text-muted">Status:</small>
                                <span id="summary-classifier-badge" class="badge">N/A</span>
                            </div>
                            <div>
                                <small class="text-muted">Keyakinan:</small>
                                <div class="confidence-visualization d-flex align-items-center w-100">
                                    <div class="confidence-bar-container flex-grow-1">
                                        <div class="confidence-bar" id="summary-classifier-confidence-bar"
                                            style="width: 0%;"></div>
                                    </div>
                                    <span class="confidence-value-text ms-2"
                                        id="summary-classifier-confidence-text">0%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- CARD: ANALISIS FITUR WARNA (Penjelasan Ilmiah) --}}
            <div id="scientific-explanation-card" class="card mb-4" style="display: none;">
                <div class="card-header"><i class="fas fa-flask me-2"></i>Penjelasan Ilmiah
                </div>
                <div class="card-body" id="scientific-explanation-content">
                    <p class="text-muted small">Penjelasan ilmiah akan muncul di sini.</p>
                </div>
            </div>

            {{-- CARD: BERIKAN UMPAN BALIK --}}
            <div id="feedback-card" class="card mb-4" style="display: none;">
                <div class="card-header"><i class="fas fa-comments me-2"></i>Berikan Umpan Balik</div>
                <div class="card-body">
                    <div class="row">
                        {{-- Kolom Feedback Deteksi --}}
                        <div class="col-md-6 mb-3 mb-md-0" id="detector-feedback-wrapper">
                            <div class="feedback-section-combined p-3 border rounded h-100">
                                <form id="feedback-detection-form" action="{{ route('feedback.detection') }}"
                                    method="POST" class="mb-0 feedback-form">
                                    @csrf
                                    <h6 class="mb-2 fw-bold"><i class="fas fa-search-location me-1"></i>Umpan Balik
                                        Deteksi</h6>
                                    <p class="text-muted mb-2 small" id="feedback-detection-prompt-text">Apakah ini?
                                    </p>
                                    <div class="d-flex justify-content-center gap-2">
                                        <button type="submit" name="is_melon" value="yes"
                                            class="btn btn-sm btn-success"><i class="fas fa-check me-1"></i>
                                            Melon</button>
                                        <button type="submit" name="is_melon" value="no"
                                            class="btn btn-sm btn-danger"><i class="fas fa-times me-1"></i> Bukan
                                            Melon</button>
                                    </div>
                                    <button type="button" id="confirm-detection-feedback-btn"
                                        class="btn btn-primary btn-sm mt-2 w-100 disabled" disabled>
                                        <i class="fas fa-check"></i> Konfirmasi Pilihan Deteksi
                                    </button>
                                </form>
                                <div id="feedback-detection-result" class="mt-2"></div>
                            </div>
                        </div>
                        {{-- Kolom Feedback Klasifikasi (JS akan menampilkan jika relevan) --}}
                        <div class="col-md-6" id="classifier-feedback-wrapper" style="display: none;">
                            <div class="feedback-section-combined p-3 border rounded h-100">
                                <form id="feedback-classification-form"
                                    action="{{ route('feedback.classification') }}" method="POST"
                                    class="mb-0 feedback-form">
                                    @csrf
                                    <h6 class="mb-2 fw-bold"><i class="fas fa-tags me-1"></i>Umpan Balik Klasifikasi
                                    </h6>
                                    <p class="text-muted mb-2 small">Model memberi klasifikasi <strong
                                            id="feedback-classification-prediction-text"
                                            class="text-secondary">N/A</strong>. Konfirmasi jenis kematangannya.</p>
                                    <div class="d-flex justify-content-center gap-2">
                                        <button type="submit" name="actual_label" value="matang"
                                            class="btn btn-sm btn-success"><i
                                                class="fas fa-thumbs-up me-1"></i>Matang</button>
                                        <button type="submit" name="actual_label" value="belum_matang"
                                            class="btn btn-sm btn-warning"><i class="fas fa-thumbs-down me-1"></i>
                                            Belum Matang</button>
                                    </div>
                                    <button type="button" id="confirm-classification-feedback-btn"
                                        class="btn btn-primary btn-sm mt-2 w-100 disabled" disabled>
                                        <i class="fas fa-check"></i> Konfirmasi Pilihan Klasifikasi
                                    </button>
                                </form>
                                <div id="feedback-classification-result" class="mt-2"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- CARD: OPSI LANJUTAN & MODEL LAIN --}}
            <div id="advanced-options-card" class="card mb-4" style="display: none;">
                <div class="card-header"><i class="fas fa-cogs me-2"></i>Model Prediksi Lain</div>
                <div class="card-body">
                    <div id="retry-detection-options-wrapper" class="mb-4 pb-3 border-bottom" style="display: none;">
                        <h6 class="sub-section-title">Deteksi Ulang dengan Model Lain?</h6>
                        <p class="small text-muted mb-2" id="retry-detection-prompt">Jika hasil deteksi kurang
                            memuaskan, klik model dari daftar "Detektor Lain" di bawah.</p>
                        <div id="single-detector-rerun-spinner" class="text-center mt-2" style="display:none;">
                            <div class="spinner-border spinner-border-sm text-primary" role="status"><span
                                    class="visually-hidden">Memproses...</span></div>
                        </div>
                    </div>
                    <div id="other-models-group-content">
                        <h6 class="sub-section-title mb-3">Gunakan "Jalankan Semua Model" untuk memperlihatkan hasil
                            prediksi seluruh model deteksi dan klasifikasi.</h6>
                        <div class="row g-4">
                            <div class="col-md-6" id="other-detectors-column">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <p class="fw-semibold mb-0"><i class="fas fa-search opacity-75 me-1"></i> Model
                                        Deteksi</p>
                                    <button type="button" class="btn btn-outline-info btn-sm run-other-model"
                                        data-task="detector" data-run="all">
                                        <i class="fas fa-play-circle me-1"></i> Jalankan Semua Model
                                    </button>
                                </div>
                                <div id="detectors-loading"
                                    class="spinner-border spinner-border-sm text-info ms-2 d-none" role="status">
                                    <span class="visually-hidden">Memuat…</span>
                                </div>
                                <ul id="detectors-results" class="list-group list-group-flush result-list mt-2"></ul>
                                <div id="detector-majority-vote-container" class="text-center mt-3 p-2 border rounded"
                                    style="display: none;">
                                    <h6 class="small text-muted mb-1">Mayoritas Deteksi:</h6>
                                    <span id="detector-majority-vote-text-badge" class="badge fs-6">N/A</span>
                                </div>
                            </div>
                            <div class="col-md-6" id="other-classifiers-column" style="display: none;">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <p class="fw-semibold mb-0"><i class="fas fa-tags opacity-75 me-1"></i> Model
                                        Klasifikasi</p>
                                    <button type="button" class="btn btn-outline-info btn-sm run-other-model"
                                        data-task="classifier" data-run="all">
                                        <i class="fas fa-play-circle me-1"></i> Jalankan Semua Model
                                    </button>
                                </div>
                                <div id="classifiers-loading"
                                    class="spinner-border spinner-border-sm text-info ms-2 d-none" role="status">
                                    <span class="visually-hidden">Memuat…</span>
                                </div>
                                <ul id="classifiers-results" class="list-group list-group-flush result-list mt-2">
                                </ul>
                                <div id="majority-vote-result" class="text-center mt-3 p-2 border rounded"
                                    style="display: none;">
                                    <h6 class="small text-muted mb-1">Mayoritas Klasifikasi:</h6>
                                    <span id="majority-vote-badge" class="badge fs-6"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- CARD: LAPORAN PERFORMA MODEL (Hanya Metrik Tabel) --}}
            <div id="model-performance-card" class="card mb-4" style="display: none;">
                <div class="card-header"><i class="fas fa-table me-2"></i>Informasi Performa Model</div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-lg-6 mb-4 mb-lg-0" id="detector-metrics-table-column">
                            <p class="fw-semibold text-center mb-2">Detektor (<span
                                    id="metrics-detector-model-name">N/A</span>)</p>
                            <div id="detector-metrics-table-container" class="table-responsive">
                                <p class="text-muted small fst-italic text-center">Tabel metrik detektor.</p>
                            </div>
                        </div>
                        <div class="col-lg-6" id="classifier-metrics-table-column" style="display: none;">
                            <p class="fw-semibold text-center mb-2">Classifier (<span
                                    id="metrics-classifier-model-name">N/A</span>)</p>
                            <div id="classifier-metrics-table-container" class="table-responsive">
                                <p class="text-muted small fst-italic text-center">Tabel metrik classifier.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="classification-skipped-message" class="alert alert-secondary text-center mt-4"
                style="display: none;"></div>
            <div id="classification-error-display" class="alert alert-warning mb-4" style="display: none;"></div>

        </div> {{-- Akhir #result-section --}}

        <div class="training-info text-center mx-auto d-block mt-5">
            <p class="mb-0"> <i class="fas fa-history me-1"></i> <strong>Waktu Training Terakhir:</strong><br> <span
                    class="training-time"> Klasifikasi: {{ $lastClassifierTimeFormatted }}</span> | <span
                    class="training-time"> Deteksi: {{ $lastDetectorTimeFormatted }}</span> </p>
        </div>
    </div>

    <button id="scrollToTopBtn" title="Kembali ke atas" class="btn btn-primary rounded-circle shadow"
        style="display: none; position: fixed; bottom: 20px; right: 20px; z-index: 1030;">
        <i class="fas fa-arrow-up"></i>
    </button>

    <div id="overlay" class="overlay-container">
        <div
            style="display: flex; flex-direction: column; justify-content: center; align-items: center; width: 100%; height: 100%; text-align: center;">
            <div class="d-flex justify-content-center align-items-center h-100">
                <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status"><span
                        class="visually-hidden">Memproses...</span></div>
                <span class="ms-3 fs-5 text-primary" id="overlay-text">Memproses...</span>
            </div>
        </div>
    </div>

    <template id="other-model-result-template">
        <li class="list-group-item px-0 py-2 other-model-list-item" data-model-key="" data-task-type="">
            <div class="d-flex w-100 justify-content-between align-items-center mb-1">
                <h6 class="mb-0 model-name small fw-bold">Nama Model</h6>
                <span class="model-prediction d-flex align-items-center">
                    <span class="badge small me-2">Hasil</span>
                    <div class="confidence-visualization d-flex align-items-center flex-grow-1"
                        style="min-width: 80px;">
                        <div class="confidence-bar-container flex-grow-1" style="height: 8px;">
                            <div class="confidence-bar model-confidence-bar"
                                style="width: 0%; height: 8px; line-height:8px; font-size:7px; border-radius:var(--border-radius-sm);">
                            </div>
                        </div>
                        <span class="confidence-value-text ms-1 model-confidence-text"
                            style="font-size: 0.75rem;">0%</span>
                    </div>
                </span>
            </div>
            <div class="model-metrics small text-muted mt-1" style="display: none;">
                Acc: <span class="accuracy">N/A</span> | F1(+): <span class="f1-pos">N/A</span>
            </div>
            <div class="model-error text-danger small mt-1" style="display: none;">Error: Pesan Error</div>
        </li>
    </template>
</body>

</html>
