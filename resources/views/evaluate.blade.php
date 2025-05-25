{{-- resources/views/evaluate.blade.php --}}
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dasbor Evaluasi Model & Kualitas Dataset</title>
    {{-- Font Awesome dan Google Fonts (Inter) --}}
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    {{-- Vite untuk CSS dan JS aplikasi --}}
    @vite(['resources/css/app.css', 'resources/css/evaluate.css', 'resources/js/app.js', 'resources/js/evaluate.js'])
</head>

<body class="evaluate-page">
    @php
        // Helper PHP untuk formatting (tetap sama)
        $formatScore = fn($value, $digits = 4) => is_numeric($value) ? number_format((float) $value, $digits) : 'N/A';
        $formatPercent = fn($value, $digits = 1) => is_numeric($value)
            ? number_format((float) $value * 100, $digits) . '%'
            : 'N/A';

        // Variabel yang di-pass dari EvaluationController
        $evaluation = $evaluation ?? [];
        $modelKeysForView = $modelKeysForView ?? [];
        $dynamicAnalysis =
            $dynamicAnalysis ?? '<p class="text-muted small fst-italic">Analisis dinamis tidak tersedia saat ini.</p>';
        $lastClassifierTimeFormatted = $lastClassifierTimeFormatted ?? 'Belum ada';
        $lastDetectorTimeFormatted = $lastDetectorTimeFormatted ?? 'Belum ada';
        $bestClassifierKey = $bestClassifierKey ?? null;
        $bestDetectorKey = $bestDetectorKey ?? null;
        $advancedAnalysis = $advancedAnalysis ?? [];
        // **BARU**: Data untuk tab Pembaruan Terkini
        $datasetUpdateStatus = $datasetUpdateStatus ?? [
            'images_missing_annotation' => [],
            'images_missing_detector_features' => [],
            'annotations_missing_classifier_features' => [],
            'images_without_files' => [],
        ];
        $pendingAnnotationCountForView = $pendingAnnotationCount ?? 0; // Variabel baru agar jelas
    @endphp

    <div class="dashboard-container container-fluid my-lg-4 my-3 px-lg-4">
        {{-- Area Notifikasi Global --}}
        <div id="notification-area-main" class="mb-4">
            @if (session('success'))
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                    <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif
            @if (session('warning'))
                <div class="alert alert-warning alert-dismissible fade show shadow-sm" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>{{ session('warning') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif
            @if (session('error'))
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <i
                        class="fas fa-times-circle me-2"></i>{{ is_string(session('error')) ? session('error') : 'Terjadi kesalahan tidak terduga.' }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif
            @if ($errors->any())
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                    <strong class="d-block mb-2"><i class="fas fa-times-circle me-2"></i>Error Validasi
                        Terdeteksi:</strong>
                    <ul class="mb-0 ps-4" style="list-style-type: disc;">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif
            {{-- Placeholder untuk notifikasi AJAX jika diperlukan --}}
            <div id="ajax-notification-placeholder"></div>
        </div>

        {{-- Notifikasi Anotasi Pending (di atas semua tab) --}}
        @if ($pendingAnnotationCountForView)
            <div class="alert alert-info alert-dismissible fade show small py-2 px-3 mb-3" role="alert">
                <i class="fas fa-info-circle me-2"></i>
                Terdapat <strong>{{ $pendingAnnotationCountForView }}</strong> gambar menunggu anotasi BBox manual di
                halaman
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

        {{-- Header Halaman --}}
        <header class="page-header mb-4">
            <h1 class="page-title"><i class="fas fa-analytics"></i>Dasbor Evaluasi & Kualitas</h1>
            <div class="page-actions">
                <a href="{{ route('melon.index') }}" class="btn btn-outline-secondary btn-sm" data-bs-toggle="tooltip"
                    data-bs-placement="bottom" title="Kembali ke Dashboard Utama">
                    <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
                </a>
                <button id="clear-app-cache-btn" class="btn btn-sm btn-outline-warning ms-2">
                    <i class="fas fa-broom me-1"></i> Bersihkan Cache Aplikasi
                </button>
                <a href="{{ route('annotate.index') }}" class="btn btn-outline-secondary btn-sm"
                    data-bs-toggle="tooltip" data-bs-placement="bottom" title="Melakukan Anotasi Manual">
                    <i class="fas fa-arrow-right"></i> Anotasi Sekarang
                </a>
            </div>
        </header>

        {{-- Kontainer Utama untuk Tab --}}
        <div class="main-tabs-container">
            {{-- Navigasi Tab Utama (HANYA DUA TAB UTAMA SEKARANG) --}}
            <ul class="nav nav-tabs main-tabs" id="mainEvaluationTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="quality-main-tab" data-bs-toggle="tab"
                        data-bs-target="#quality-main-tab-pane" type="button" role="tab"
                        aria-controls="quality-main-tab-pane" aria-selected="true">
                        <i class="fas fa-award"></i> Kualitas Dataset
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="model-eval-main-tab" data-bs-toggle="tab"
                        data-bs-target="#model-eval-main-tab-pane" type="button" role="tab"
                        aria-controls="model-eval-main-tab-pane" aria-selected="false">
                        <i class="fas fa-brain"></i> Evaluasi Performa Model
                    </button>
                </li>
            </ul>

            {{-- Konten Tab Utama --}}
            <div class="tab-content" id="mainEvaluationTabContent">

                {{-- Panel Tab 1: Kualitas Dataset (berisi sub-tab) --}}
                <div class="tab-pane fade show active" id="quality-main-tab-pane" role="tabpanel"
                    aria-labelledby="quality-main-tab" tabindex="0">

                    <ul class="nav nav-tabs sub-tabs" id="datasetQualitySubTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="stats-quality-subtab" data-bs-toggle="tab"
                                data-bs-target="#stats-quality-subtab-pane" type="button" role="tab"
                                aria-controls="stats-quality-subtab-pane" aria-selected="true">
                                <i class="fas fa-chart-pie"></i> Statistik Kualitas
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="control-quality-subtab" data-bs-toggle="tab"
                                data-bs-target="#control-quality-subtab-pane" type="button" role="tab"
                                aria-controls="control-quality-subtab-pane" aria-selected="false">
                                <i class="fas fa-cogs"></i> Kontrol Kualitas
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="updates-quality-subtab" data-bs-toggle="tab"
                                data-bs-target="#updates-quality-subtab-pane" type="button" role="tab"
                                aria-controls="updates-quality-subtab-pane" aria-selected="false">
                                <i class="fas fa-sync-alt"></i> Pembaruan Kualitas
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content pt-3" id="datasetQualitySubTabsContent">
                        <div class="tab-pane fade show active" id="stats-quality-subtab-pane" role="tabpanel"
                            aria-labelledby="stats-quality-subtab" tabindex="0">
                            {{-- Konten statistik dipindahkan ke partialnya sendiri --}}
                            @include('partials.dataset-stats-content')
                        </div>
                        <div class="tab-pane fade" id="control-quality-subtab-pane" role="tabpanel"
                            aria-labelledby="control-quality-subtab" tabindex="0">
                            {{-- Konten kontrol dataset dipindahkan ke partialnya sendiri --}}
                            @include('partials.dataset-control-content')
                        </div>
                        <div class="tab-pane fade" id="updates-quality-subtab-pane" role="tabpanel"
                            aria-labelledby="updates-quality-subtab" tabindex="0">
                            @include('partials.recent-updates-tab', compact('datasetUpdateStatus'))
                        </div>
                    </div>
                </div>

                {{-- Panel Tab 2: Evaluasi Performa Model (sekarang juga berisi sub-tab Kesimpulan) --}}
                <div class="tab-pane fade" id="model-eval-main-tab-pane" role="tabpanel"
                    aria-labelledby="model-eval-main-tab" tabindex="0">

                    <ul class="nav nav-tabs sub-tabs" id="modelEvalSubTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="classifier-eval-subtab" data-bs-toggle="tab"
                                data-bs-target="#classifier-eval-subtab-pane" type="button" role="tab"
                                aria-controls="classifier-eval-subtab-pane" aria-selected="true">
                                <i class="fas fa-tasks-alt"></i> Model Klasifikasi
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="detector-eval-subtab" data-bs-toggle="tab"
                                data-bs-target="#detector-eval-subtab-pane" type="button" role="tab"
                                aria-controls="detector-eval-subtab-pane" aria-selected="false">
                                <i class="fas fa-object-group"></i> Model Deteksi
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="summary-analysis-subtab" data-bs-toggle="tab"
                                data-bs-target="#summary-analysis-subtab-pane" type="button" role="tab"
                                aria-controls="summary-analysis-subtab-pane" aria-selected="false">
                                <i class="fas fa-list-check"></i> Kesimpulan & Analisis
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="modelEvalSubTabsContent">
                        {{-- Sub-Panel 2.1: Klasifikasi --}}
                        <div class="tab-pane fade show active" id="classifier-eval-subtab-pane" role="tabpanel"
                            aria-labelledby="classifier-eval-subtab" tabindex="0">
                            @php
                                $classifierModels = array_filter(
                                    $evaluation,
                                    fn($modelData, $modelKey) => Str::endsWith($modelKey, '_classifier') &&
                                        !empty($modelData),
                                    ARRAY_FILTER_USE_BOTH,
                                );
                                $firstClassifierKey = !empty($classifierModels)
                                    ? array_key_first($classifierModels)
                                    : null;
                            @endphp
                            @if (!empty($classifierModels))
                                {{-- Nav Pills dan konten --}}
                                <div class="model-selection-pills-container py-3">
                                    <ul class="nav nav-pills model-sub-sub-tabs" id="classifierModelNavTabsEvaluate"
                                        role="tablist">
                                        @foreach ($classifierModels as $modelKey => $evalData)
                                            @php
                                                $modelDisplayName =
                                                    $modelKeysForView[$modelKey] ??
                                                    Str::title(str_replace(['_classifier', '_'], ['', ' '], $modelKey));
                                                $tabId = Str::slug($modelKey) . '-eval-tab';
                                                $paneId = Str::slug($modelKey) . '-eval-pane';
                                            @endphp
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link {{ $loop->first ? 'active' : '' }}"
                                                    id="{{ $tabId }}" data-bs-toggle="tab"
                                                    data-bs-target="#{{ $paneId }}" type="button"
                                                    role="tab" aria-controls="{{ $paneId }}"
                                                    aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                                                    {{ $modelDisplayName }}
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                                <div class="tab-content" id="classifierModelNavTabsContentEvaluate">
                                    @foreach ($classifierModels as $modelKey => $evalData)
                                        @php
                                            $isDetector = false;
                                            $chartIdSuffix = Str::slug($modelKey, '-');
                                            $isBest = ($bestClassifierKey ?? null) === $modelKey;
                                            $paneId = Str::slug($modelKey) . '-eval-pane';
                                        @endphp
                                        <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}"
                                            id="{{ $paneId }}" role="tabpanel"
                                            aria-labelledby="{{ Str::slug($modelKey) . '-eval-tab' }}"
                                            tabindex="0">
                                            <div
                                                class="card individual-model-card {{ $isBest ? 'border-primary shadow-lg' : '' }}">
                                                <div class="card-header">
                                                    <h5 class="card-title-model mb-0">
                                                        @if ($isBest)
                                                            <i class="fas fa-star text-warning me-2"
                                                                title="Model Klasifikasi Terbaik Saat Ini"></i>
                                                        @endif
                                                        {{ $modelKeysForView[$modelKey] ?? Str::title(str_replace(['_classifier', '_'], ['', ' '], $modelKey)) }}
                                                    </h5>
                                                </div>
                                                <div class="card-body">
                                                    @include(
                                                        'partials.evaluation-card-content',
                                                        compact(
                                                            'evalData',
                                                            'isDetector',
                                                            'chartIdSuffix',
                                                            'modelKey',
                                                            'bestClassifierKey',
                                                            'bestDetectorKey',
                                                            'modelKeysForView',
                                                            'advancedAnalysis'))
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="text-center py-5 my-5">
                                    <i class="fas fa-empty-set fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">Belum ada data evaluasi untuk model klasifikasi.</p>
                                </div>
                            @endif
                        </div>

                        {{-- Panel Sub-Tab Deteksi (logika tetap sama) --}}
                        <div class="tab-pane fade" id="detector-eval-subtab-pane" role="tabpanel"
                            aria-labelledby="detector-eval-subtab" tabindex="0">
                            @php
                                $detectorModels = array_filter(
                                    $evaluation,
                                    fn($modelData, $modelKey) => Str::endsWith($modelKey, '_detector') &&
                                        !empty($modelData),
                                    ARRAY_FILTER_USE_BOTH,
                                );
                                $firstDetectorKey = !empty($detectorModels) ? array_key_first($detectorModels) : null;
                            @endphp
                            @if (!empty($detectorModels))
                                <div class="model-selection-pills-container py-3">
                                    <ul class="nav nav-pills model-sub-sub-tabs" id="detectorModelNavTabsEvaluate"
                                        role="tablist">
                                        @foreach ($detectorModels as $modelKey => $evalData)
                                            @php
                                                $modelDisplayName =
                                                    $modelKeysForView[$modelKey] ??
                                                    Str::title(str_replace(['_detector', '_'], ['', ' '], $modelKey));
                                                $tabId = Str::slug($modelKey) . '-eval-tab';
                                                $paneId = Str::slug($modelKey) . '-eval-pane';
                                            @endphp
                                            <li class="nav-item" role="presentation">
                                                <button class="nav-link {{ $loop->first ? 'active' : '' }}"
                                                    id="{{ $tabId }}" data-bs-toggle="tab"
                                                    data-bs-target="#{{ $paneId }}" type="button"
                                                    role="tab" aria-controls="{{ $paneId }}"
                                                    aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                                                    {{ $modelDisplayName }}
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                                <div class="tab-content" id="detectorModelNavTabsContentEvaluate">
                                    @foreach ($detectorModels as $modelKey => $evalData)
                                        @php
                                            $isDetector = true;
                                            $chartIdSuffix = Str::slug($modelKey, '-');
                                            $isBest = ($bestDetectorKey ?? null) === $modelKey;
                                            $paneId = Str::slug($modelKey) . '-eval-pane';
                                        @endphp
                                        <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}"
                                            id="{{ $paneId }}" role="tabpanel"
                                            aria-labelledby="{{ Str::slug($modelKey) . '-eval-tab' }}"
                                            tabindex="0">
                                            <div
                                                class="card individual-model-card {{ $isBest ? 'border-primary shadow-lg' : '' }}">
                                                <div class="card-header">
                                                    <h5 class="card-title-model mb-0">
                                                        @if ($isBest)
                                                            <i class="fas fa-star text-warning me-2"
                                                                title="Model Deteksi Terbaik Saat Ini"></i>
                                                        @endif
                                                        {{ $modelKeysForView[$modelKey] ?? Str::title(str_replace(['_detector', '_'], ['', ' '], $modelKey)) }}
                                                    </h5>
                                                </div>
                                                <div class="card-body">
                                                    @include(
                                                        'partials.evaluation-card-content',
                                                        compact(
                                                            'evalData',
                                                            'isDetector',
                                                            'chartIdSuffix',
                                                            'modelKey',
                                                            'bestClassifierKey',
                                                            'bestDetectorKey',
                                                            'modelKeysForView',
                                                            'advancedAnalysis'))
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="text-center py-5 my-5">
                                    <i class="fas fa-empty-set fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">Belum ada data evaluasi untuk model deteksi.</p>
                                </div>
                            @endif
                        </div>

                        {{-- Sub-Panel 2.3: Kesimpulan & Analisis --}}
                        <div class="tab-pane fade" id="summary-analysis-subtab-pane" role="tabpanel"
                            aria-labelledby="summary-analysis-subtab" tabindex="0">
                            @include(
                                'partials.analysis-summary',
                                compact(
                                    'dynamicAnalysis',
                                    'lastClassifierTimeFormatted',
                                    'lastDetectorTimeFormatted'))
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Indikator Progress SSE yang Mengikuti --}}
    <div id="sse-progress-indicator"
        class="position-fixed bottom-0 end-0 p-3 me-3 mb-3 shadow-lg rounded bg-white border"
        style="z-index: 1060; display: none; width: 380px;">
        {{-- Bagian yang Selalu Terlihat (Judul, Tombol Minimize, Persentase Global) --}}
        <div class="sse-header-condensed d-flex justify-content-between align-items-center mb-2">
            <strong class="me-auto text-primary" id="sse-progress-title"
                style="font-size: 0.9rem;">Processing...</strong>
            <div id="sse-global-percentage-text" class="fw-semibold small me-2" style="font-size:0.85rem;">0%</div>
            <button type="button" class="btn btn-sm btn-outline-secondary p-0" id="sse-minimize-btn"
                aria-label="Minimize/Maximize Log"
                style="width: 22px; height: 22px; line-height: 1; font-size: 0.8rem;" data-bs-toggle="tooltip"
                data-bs-placement="top" title="">
                <i class="fas fa-minus"></i>
            </button>
        </div>

        {{-- Bagian Detail yang Bisa Di-toggle --}}
        <div class="sse-details-collapsible">
            <div class="d-flex align-items-center mb-1">
                <small id="sse-progress-status-text" class="text-muted me-auto"
                    style="font-size: 0.8rem;">Menghubungkan...</small>
                {{-- Persentase dipindah ke atas agar tetap terlihat saat minimize --}}
            </div>
            <div class="progress mb-2" style="height: 10px;">
                <div id="sse-global-progress-bar"
                    class="progress-bar progress-bar-striped progress-bar-animated bg-info" role="progressbar"
                    style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                </div>
            </div>
            <div id="sse-progress-log-summary" class="small text-muted bg-light p-2 rounded"
                style="max-height: 80px; overflow-y: auto; font-size: 0.75rem; line-height: 1.4; white-space: pre-wrap; word-break: break-all;">
                Menunggu output log...
            </div>
        </div>
    </div>

    {{-- Tombol Scroll ke Atas --}}
    <button id="evaluateScrollToTopBtn" title="Kembali ke atas" class="btn btn-primary rounded-circle shadow"
        style="display: none; position: fixed; bottom: 20px; right: 20px; z-index: 1030; width: 45px; height: 45px; padding: 0; line-height: 45px; text-align: center;">
        <i class="fas fa-arrow-up"></i>
    </button>

    <script>
        // Variabel global untuk JavaScript (tambahkan $datasetUpdateStatus)
        window.evaluationData = @json($evaluation ?? null);
        window.datasetActionsEndpoint = "{{ route('evaluate.dataset.action') }}";
        window.csrfToken = "{{ csrf_token() }}";
        window.streamExtractFeaturesUrl = "{{ route('evaluate.stream.extract_features_incremental') }}";
        window.streamExtractFeaturesOverwriteUrl = "{{ route('evaluate.stream.extract_features_overwrite') }}";
        window.streamTrainClassifierUrl = "{{ route('evaluate.stream.train_classifier') }}";
        window.streamTrainDetectorUrl = "{{ route('evaluate.stream.train_detector') }}";
        window.recentUpdatesTabContentUrl = "{{ route('evaluate.dataset.recent_updates_content') }}";
        window.bestClassifierKey = @json($bestClassifierKey ?? null);
        window.bestDetectorKey = @json($bestDetectorKey ?? null);
        window.initialPendingAnnotationCount = {{ $pendingAnnotationCountForView }}; // Kirim ke JS
        window.datasetUpdateStatus = @json($datasetUpdateStatus);
    </script>
</body>

</html>
