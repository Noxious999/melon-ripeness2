{{-- resources/views/partials/recent-updates-tab.blade.php --}}
@php
    $imageStatuses = $datasetUpdateStatus['image_statuses'] ?? [];
    $summary = $datasetUpdateStatus['summary'] ?? [];
    $itemsToDisplay = [];

    foreach ($imageStatuses as $s3PathKey => $status) {
        $isConsideredFullyProcessed = true; // Asumsikan selesai, lalu cari kondisi yang membuatnya tidak selesai

        $physicalFileExists = $status['physical_file_exists'] ?? false;
        $hasAnnotationEntry = $status['has_annotation_entry'] ?? false;
        $detectionClassFromAnnotation = $status['detection_class_from_annotation'] ?? null;
        $hasDetectorFeatures = $status['has_detector_features'] ?? false;
        $bboxAnnotationDetails =
            isset($status['bbox_annotations_details']) && is_array($status['bbox_annotations_details'])
                ? $status['bbox_annotations_details']
                : [];

        // Kondisi 1 & 2: File fisik dan entri anotasi harus ada
        if (!$physicalFileExists || !$hasAnnotationEntry) {
            $isConsideredFullyProcessed = false;
        }

        // Kondisi 3: Fitur detektor harus ada jika sudah dianotasi (dan bukan kelas 'unknown')
        if (
            $isConsideredFullyProcessed &&
            !empty($detectionClassFromAnnotation) &&
            $detectionClassFromAnnotation !== 'unknown'
        ) {
            if (!$hasDetectorFeatures) {
                $isConsideredFullyProcessed = false;
            }
        } elseif (
            $isConsideredFullyProcessed &&
            (empty($detectionClassFromAnnotation) || $detectionClassFromAnnotation === 'unknown')
        ) {
            // Jika dianotasi tapi kelas deteksi tidak diketahui, belum bisa dianggap selesai sepenuhnya
            $isConsideredFullyProcessed = false;
        }

        // Kondisi 4: Jika melon, semua BBox harus lengkap dan punya fitur klasifikasi
        if ($isConsideredFullyProcessed && $detectionClassFromAnnotation === 'melon') {
            if (empty($bboxAnnotationDetails)) {
                // Dianotasi sebagai melon tapi tidak ada detail BBox di CSV nya.
                $isConsideredFullyProcessed = false;
            } else {
                foreach ($bboxAnnotationDetails as $bboxKey => $bboxDetail) {
                    if (!is_array($bboxDetail)) {
                        continue;
                    }

                    // Cek kelengkapan data BBox dasar dari CSV
                    $bboxDataPointsExist =
                        !empty($bboxDetail['ripeness_class']) &&
                        isset($bboxDetail['bbox_cx']) &&
                        trim((string) $bboxDetail['bbox_cx']) !== '' &&
                        isset($bboxDetail['bbox_cy']) &&
                        trim((string) $bboxDetail['bbox_cy']) !== '' &&
                        isset($bboxDetail['bbox_w']) &&
                        trim((string) $bboxDetail['bbox_w']) !== '' &&
                        isset($bboxDetail['bbox_h']) &&
                        trim((string) $bboxDetail['bbox_h']) !== '';

                    if (!$bboxDataPointsExist) {
                        $isConsideredFullyProcessed = false;
                        break; // Satu BBox tidak lengkap, gambar belum selesai
                    }

                    if (!($bboxDetail['has_classifier_features'] ?? false)) {
                        $isConsideredFullyProcessed = false;
                        break; // Satu BBox tidak punya fitur classifier, gambar belum selesai
                    }
                }
            }
        }

        // Hanya tampilkan gambar yang BELUM sepenuhnya diproses
        if (!$isConsideredFullyProcessed) {
            $relativePathForDisplay = $s3PathKey;
            if (Str::startsWith($s3PathKey, App\Services\DatasetService::S3_DATASET_BASE_DIR . '/')) {
                $relativePathForDisplay = Str::after(
                    $s3PathKey,
                    App\Services\DatasetService::S3_DATASET_BASE_DIR . '/',
                );
            }
            $relativePathForDisplay = ltrim($relativePathForDisplay, '/');

            $itemsToDisplay[$s3PathKey] = [
                'status_details' => $status,
                'thumbnail_url' => $physicalFileExists
                    ? route('storage.image', ['path' => base64_encode($s3PathKey)])
                    : asset('images/placeholder_image.png'),
                'filename_display' => basename($s3PathKey),
                'set_display' => $status['set'] ?? 'N/A',
                's3_path_for_link' => $relativePathForDisplay,
                // 'is_considered_fully_processed_for_display' bisa ditambahkan jika perlu info ini di card
            ];
        }
    }
@endphp

{{-- Notifikasi Perubahan Dataset & Anotasi Pending --}}
@if (isset($showDatasetChangeNotification) && $showDatasetChangeNotification && isset($datasetChangeSummary))
    <div class="alert alert-warning alert-dismissible fade show shadow-sm mb-3" role="alert">
        <h6 class="alert-heading"><i class="fas fa-sync-alt me-2"></i>Dataset Telah Diperbarui!</h6>
        @if ($datasetChangeSummary['type_display'] ?? null)
            <p class="mb-1 small">
                Aksi terakhir: <strong>{{ $datasetChangeSummary['type_display'] }}</strong>
                @if ($datasetChangeSummary['identifier_display'] ?? null)
                    pada <em>{{ $datasetChangeSummary['identifier_display'] }}</em>
                @endif
                @if ($datasetChangeSummary['time_ago'] ?? null)
                    (sekitar {{ $datasetChangeSummary['time_ago'] }}).
                @endif
            </p>
        @endif
        <p class="mb-0 small">Disarankan untuk melakukan <strong>Ekstraksi Fitur Ulang</strong> & <strong>Training Ulang
                Model</strong> melalui tab "Kontrol Kualitas".</p>
        <button type="button" class="btn-close btn-sm mt-1" data-bs-dismiss="alert" aria-label="Close"
            onclick="markDatasetChangesAsSeenTab(this)" data-url="{{ route('dataset.changes.mark_seen') }}"></button>
    </div>
@endif
@if (isset($pendingAnnotationCountForView) && $pendingAnnotationCountForView > 0)
    <div class="alert alert-info small p-2 mb-3">
        <i class="fas fa-info-circle me-2"></i>
        Terdapat <strong>{{ $pendingAnnotationCountForView }}</strong> gambar menunggu anotasi BBox manual.
        <a href="{{ route('annotate.index') }}" class="alert-link fw-bold">Anotasi Sekarang <i
                class="fas fa-arrow-right fa-xs"></i></a>.
    </div>
@endif

<div class="card shadow-sm">
    <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
        <div>
            <h6 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Status Integritas & Kelengkapan Dataset</h6>
            <small class="text-muted">Terakhir diperbarui:
                {{ isset($datasetUpdateStatus['last_checked']) && $datasetUpdateStatus['last_checked'] ? \Carbon\Carbon::parse($datasetUpdateStatus['last_checked'])->isoFormat('D MMM, HH:mm:ss') : 'Belum pernah' }}
            </small>
        </div>
        <button id="refresh-dataset-status-btn-tab" class="btn btn-sm btn-outline-primary px-2 py-1">
            <i class="fas fa-sync-alt me-1"></i> Perbarui
        </button>
    </div>
    <div class="card-body p-3">
        @if (empty($summary['physical_images_s3_total']) && empty($summary['unique_images_in_annotation_csv']))
            <div class="alert alert-info text-center small py-3 my-3">
                <i class="fas fa-info-circle fa-lg me-2"></i>
                Tidak ada data untuk dianalisis.<br>
                <span class="fw-normal">Pastikan ada gambar di direktori dataset S3 (train, valid, test) dan file
                    anotasi yang sesuai.</span>
            </div>
        @else
            <hr class="my-3">
            <h6 class="text-primary small-caps"><i class="fas fa-tasks me-2"></i>Progres Gambar & Item Butuh Aksi:</h6>

            @if (empty($itemsToDisplay))
                <div class="alert alert-success small py-2 px-3 border-0 mt-2">
                    <i class="fas fa-check-circle me-2"></i>Semua gambar tampak sudah lengkap dan sinkron! Tidak ada
                    item yang memerlukan perhatian khusus saat ini.
                </div>
            @else
                <p class="text-muted small mb-3">
                    Menampilkan {{ count($itemsToDisplay) }} gambar yang memerlukan perhatian atau belum lengkap
                    prosesnya:
                </p>
                <div class="row g-3">
                    {{-- Loop untuk menampilkan card item $itemsToDisplay --}}
                    @foreach (array_slice($itemsToDisplay, 0, 12) as $s3PathKey => $item)
                        @php
                            $statusData = $item['status_details'];
                            $physicalFileExists = $statusData['physical_file_exists'] ?? false;
                            $hasAnnotationEntry = $statusData['has_annotation_entry'] ?? false;
                            $hasDetectorFeatures = $statusData['has_detector_features'] ?? false;
                            $detectionClassFromAnnotation = $statusData['detection_class_from_annotation'] ?? null;
                            $bboxAnnotationDetails =
                                isset($statusData['bbox_annotations_details']) &&
                                is_array($statusData['bbox_annotations_details'])
                                    ? $statusData['bbox_annotations_details']
                                    : [];
                            $isCardFullyProcessed = false; // Karena kita hanya menampilkan yang BELUM selesai
                        @endphp
                        <div class="col-md-6 col-lg-4 col-xl-3">
                            <div class="card dataset-issue-item h-100 shadow-sm border border-warning-subtle">
                                {{-- Karena item ini ditampilkan, berarti ia "butuh aksi" / belum selesai --}}
                                @if ($statusData['physical_file_exists'] ?? false)
                                    <a href="{{ route('annotate.index', ['image' => base64_encode($item['s3_path_for_link'])]) }}"
                                        target="_blank" title="Proses/Anotasi: {{ $item['filename_display'] }}">
                                        <img src="{{ $item['thumbnail_url'] }}" class="card-img-top"
                                            alt="{{ $item['filename_display'] }}"
                                            style="height: 130px; object-fit: cover;">
                                    </a>
                                @else
                                    <img src="{{ $item['thumbnail_url'] }}" class="card-img-top"
                                        alt="{{ $item['filename_display'] }}"
                                        style="height: 130px; object-fit: cover; filter: grayscale(100%) opacity(0.3);">
                                @endif
                                <div class="card-body p-2 d-flex flex-column" style="font-size: 0.8rem;">
                                    <h6 class="card-title small fw-semibold mb-1 text-truncate"
                                        title="{{ $item['filename_display'] }}">
                                        <i
                                            class="fas fa-image text-muted me-1 fa-xs"></i>{{ Str::limit($item['filename_display'], 25) }}
                                    </h6>
                                    <p class="text-muted small mb-1">Set: <span
                                            class="badge bg-light text-dark border fw-normal">{{ $item['set_display'] }}</span>
                                    </p>

                                    <ul class="list-unstyled mb-2 issue-details-list flex-grow-1"
                                        style="font-size: 0.75rem; padding-left: 0;">
                                        <li class="mb-1">
                                            <i
                                                class="fas {{ $statusData['physical_file_exists'] ?? false ? 'fa-file-alt text-success' : 'fa-unlink text-danger' }} fa-fw"></i>
                                            File Fisik: <span
                                                class="{{ $statusData['physical_file_exists'] ?? false ? 'text-success' : 'text-danger fw-bold' }}">{{ $statusData['physical_file_exists'] ?? false ? 'Ada' : 'Hilang!' }}</span>
                                        </li>

                                        @if (($statusData['physical_file_exists'] ?? false) || ($statusData['has_annotation_entry'] ?? false))
                                            <li class="mb-1">
                                                <i
                                                    class="fas {{ $statusData['has_annotation_entry'] ?? false ? 'fa-check-square text-success' : 'fa-pen-nib text-warning' }} fa-fw"></i>
                                                Anotasi CSV:
                                                @if ($statusData['has_annotation_entry'] ?? false)
                                                    <span class="text-success">Ada</span>
                                                    @if ($statusData['detection_class_from_annotation'] ?? null)
                                                        <span
                                                            class="badge bg-primary-subtle text-primary-emphasis ms-1 px-1 py-0 rounded-pill"
                                                            style="font-size:0.9em;">{{ Str::title(Str::replace('_', ' ', $statusData['detection_class_from_annotation'])) }}</span>
                                                    @else
                                                        <span
                                                            class="badge bg-secondary-subtle text-secondary-emphasis ms-1 px-1 py-0 rounded-pill"
                                                            style="font-size:0.9em;">Kelas Deteksi N/A</span>
                                                    @endif
                                                @else
                                                    <span class="text-warning fw-bold">Perlu Anotasi</span>
                                                @endif
                                            </li>
                                        @endif

                                        @if ($hasAnnotationEntry && $physicalFileExists)
                                            <li class="mb-1">
                                                <i
                                                    class="fas {{ $hasDetectorFeatures ? 'fa-cogs text-success' : 'fa-cog text-warning' }} fa-fw"></i>
                                                Fitur Detektor: <span
                                                    class="{{ $hasDetectorFeatures ? 'text-success' : 'text-warning fw-bold' }}">{{ $hasDetectorFeatures ? 'Ada' : 'Belum Ada' }}</span>
                                            </li>
                                        @endif

                                        @if ($hasAnnotationEntry && $physicalFileExists && $detectionClassFromAnnotation === 'melon')
                                            <li class="mb-1">
                                                <i
                                                    class="fas fa-microscope fa-fw {{ empty($bboxAnnotationDetails) ? 'text-muted' : '' }}"></i>
                                                <span>BBox & Klasifikasi:</span>
                                                @if (empty($bboxAnnotationDetails))
                                                    <span class="text-warning ms-1 fw-bold">Data BBox di CSV anotasi
                                                        tidak ditemukan/kosong.</span>
                                                @else
                                                    <ul class="list-unstyled ps-3 mt-1" style="font-size: 0.95em;">
                                                        @php $bboxCounterDisplay = 0; @endphp
                                                        @foreach ($bboxAnnotationDetails as $annIdForCard => $bboxDetailCard)
                                                            @php
                                                                $bboxCounterDisplay++;
                                                                $ripeClass = $bboxDetailCard['ripeness_class'] ?? 'N/A';
                                                                $cx = $bboxDetailCard['bbox_cx'] ?? 'N/A';
                                                                $cy = $bboxDetailCard['bbox_cy'] ?? 'N/A';
                                                                $w = $bboxDetailCard['bbox_w'] ?? 'N/A';
                                                                $h = $bboxDetailCard['bbox_h'] ?? 'N/A';
                                                                $hasClsFeat =
                                                                    $bboxDetailCard['has_classifier_features'] ?? false;
                                                            @endphp
                                                            <li>
                                                                <small>BBox #{{ $bboxCounterDisplay }}
                                                                    (<code>{{ Str::limit(Str::after($annIdForCard, '_bbox'), 4) }}</code>)
                                                                    :
                                                                    <span
                                                                        class="badge bg-info-subtle text-info-emphasis px-1 py-0 rounded-pill">{{ Str::title($ripeClass) }}</span>
                                                                    <br>
                                                                    <i
                                                                        class="fas {{ $hasClsFeat ? 'fa-check-circle text-success' : 'fa-circle-notch text-warning' }} fa-fw"></i>
                                                                    <span
                                                                        class="{{ $hasClsFeat ? 'text-success' : 'text-warning fw-bold' }}">Fitur
                                                                        Cls:
                                                                        {{ $hasClsFeat ? 'Ada' : 'Belum Ada' }}</span>
                                                                    <br>
                                                                    <span class="text-muted" style="font-size:0.85em;"
                                                                        title="cx, cy, w, h">
                                                                        ({{ $cx }}, {{ $cy }},
                                                                        {{ $w }}, {{ $h }})
                                                                    </span>
                                                                </small>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                @endif
                                            </li>
                                        @endif
                                    </ul>

                                    <div class="mt-auto pt-1 border-top border-light text-center">
                                        @if ($physicalFileExists && !$hasAnnotationEntry)
                                            <a href="{{ route('annotate.index', ['image' => base64_encode($item['s3_path_for_link'])]) }}"
                                                class="btn btn-primary btn-sm py-0 px-1" target="_blank"
                                                style="font-size:0.7rem;">
                                                <i class="fas fa-pencil-alt fa-xs"></i> Anotasi Sekarang
                                            </a>
                                        @elseif (!$isCardFullyProcessed && $physicalFileExists)
                                            {{-- Tombol Lanjutkan Proses sekarang ke Kontrol Kualitas --}}
                                            <a href="{{ route('evaluate.index') }}#control-quality-subtab"
                                                class="btn btn-outline-info btn-sm py-0 px-1" style="font-size:0.7rem;"
                                                title="Buka Tab Kontrol Kualitas untuk Ekstraksi Fitur/Training">
                                                <i class="fas fa-cogs fa-xs"></i> Kontrol Kualitas
                                            </a>
                                        @elseif ($physicalFileExists)
                                            <span class="text-success small"><i class="fas fa-check-circle"></i>
                                                Lengkap & Sinkron</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                @if (count($itemsToDisplay) > 12)
                    <p class="text-center text-muted small mt-3">
                        Dan {{ count($itemsToDisplay) - 12 }} item lainnya...
                    </p>
                @endif
            @endif
        @endif
    </div>
</div>
