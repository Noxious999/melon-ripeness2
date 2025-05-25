<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Alat Anotasi Gambar Melon</title>
    {{-- Fonts & Icons --}}
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    {{-- Load CSS via Vite --}}
    @vite(['resources/css/app.css', 'resources/css/annotation.css', 'resources/js/app.js', 'resources/js/annotation.js'])
</head>

<body class="bg-light">
    {{-- **PERUBAHAN:** Pindahkan container utama ke dalam @else --}}
    <div class="container-fluid" id="annotation-page-container"
        @if (isset($annotationComplete) && !$annotationComplete && isset($imagePathForCsv) && isset($s3Path)) {{-- Tambah isset($s3Path) --}}
        data-initial-image-path="{{ $imagePathForCsv }}" {{-- Path singkat: train/file.jpg --}}
        data-initial-image-url="{{ $imageUrl }}"
        data-initial-filename="{{ $filename }}"
        data-initial-set="{{ $datasetSet }}"
        data-initial-s3-path="{{ $s3Path }}" {{-- !!! TAMBAHKAN INI: Path S3 lengkap: dataset/train/file.jpg !!! --}}
        data-initial-is-pending-bbox="{{ $isPendingBbox ?? false ? 'true' : 'false' }}"
        data-image-data-endpoint="{{ route('annotate.index') }}"
        data-gallery-endpoint="{{ route('annotate.index') }}"
        data-estimate-bbox-endpoint="{{ route('annotate.estimate_bbox') }}"
        data-clear-cache-url="{{ $clearCacheUrl ?? route('app.clear_cache') }}" {{-- Tambahkan URL Clear Cache --}}
    @else
        data-initial-image-path=""
        data-initial-image-url=""
        data-initial-filename="N/A"
        data-initial-set="N/A"
        data-initial-s3-path="" {{-- !!! TAMBAHKAN INI !!! --}}
        data-initial-is-pending-bbox="false"
        data-image-data-endpoint="{{ route('annotate.index') }}"
        data-gallery-endpoint="{{ route('annotate.index') }}"
        data-estimate-bbox-endpoint="{{ route('annotate.estimate_bbox') }}"
        data-clear-cache-url="{{ $clearCacheUrl ?? route('app.clear_cache') }}" @endif>
        <h1>ANOTASI MANUAL</h1>

        {{-- Container untuk notifikasi --}}
        <div id="notification-area" class="mb-3">
            @if (session('success'))
                <div class="alert alert-success alert-dismissible fade show">{{ session('success') }} <button
                        type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            @endif
            @if (session('error'))
                <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }} <button
                        type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            @endif
            @if (session('info'))
                <div class="alert alert-info alert-dismissible fade show">{{ session('info') }} <button type="button"
                        class="btn-close" data-bs-dismiss="alert"></button></div>
            @endif
            {{-- Area notifikasi khusus prefill --}}
            <div id="prefill-notification-area"></div>
        </div>

        {{-- **PERUBAHAN:** Cek flag $annotationComplete --}}
        @if (isset($annotationComplete) && $annotationComplete)
            {{-- Tampilkan pesan jika anotasi selesai --}}
            <div class="card shadow">
                <div class="card-body text-center">
                    <div class="alert alert-success mb-4">
                        <h4 class="alert-heading"><i class="fas fa-check-circle me-2"></i>Anotasi Selesai!</h4>
                        <p class="mb-0">Semua gambar dalam dataset yang terdeteksi saat ini sudah memiliki entri
                            anotasi.</p>
                    </div>
                    <a href="{{ route('melon.index') }}" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-1"></i> Kembali ke Dashboard
                    </a>
                    <a href="{{ route('evaluate.index') }}" class="btn btn-outline-secondary ms-2">
                        Lihat Evaluasi <i class="fas fa-arrow-right me-1"></i>
                    </a>
                </div>
            </div>
        @elseif (isset($annotationError))
            {{-- Tampilkan pesan jika ada error saat load awal --}}
            <div class="card shadow">
                <div class="card-body text-center">
                    <div class="alert alert-danger mb-4">
                        <h4 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Error</h4>
                        <p class="mb-0">{{ $annotationError }}</p>
                    </div>
                    <a href="{{ route('melon.index') }}" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-1"></i> Kembali ke Dashboard
                    </a>
                </div>
            </div>
        @elseif (isset($imagePathForCsv))
            {{-- Tampilkan UI anotasi normal jika ada gambar --}}
            <div class="card shadow mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-pencil-alt me-2"></i>Area Anotasi</h5>
                    <div>
                        <a href="{{ route('melon.index') }}" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Dashboard
                        </a>
                        <button id="clear-app-cache-btn" class="btn btn-sm btn-outline-warning ms-2">
                            <i class="fas fa-broom me-1"></i> Bersihkan Cache Aplikasi
                        </button>
                        <a href="{{ route('evaluate.index') }}" class="btn btn-sm btn-outline-info ms-2">
                            Evaluasi Model <i class="fas fa-arrow-right me-1"></i>
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    {{-- Galeri Thumbnail --}}
                    <div class="mb-4">
                        {{-- ... (Konten Galeri sama seperti sebelumnya) ... --}}
                        <div class="text-center fw-bold mb-2">Antrian Anotasi</div>
                        <div id="gallery-controls" class="mb-2">
                            <div id="gallery-info" class="mb-2">
                                Hal <span id="current-page-display">{{ $currentPage }}</span> / <span
                                    id="total-pages-display">{{ $totalPages }}</span> (<span
                                    id="total-images-display">{{ $totalImages }}</span> gambar)
                            </div>
                            <div id="gallery-pagination" class="d-flex gap-2">
                                <button id="prev-page-btn" class="btn btn-outline-secondary btn-sm"
                                    {{ $currentPage <= 1 ? 'disabled' : '' }}>
                                    <i class="fas fa-chevron-left"></i> Sebelumnya
                                </button>
                                <button id="next-page-btn" class="btn btn-outline-secondary btn-sm"
                                    {{ $currentPage >= $totalPages ? 'disabled' : '' }}>
                                    Berikutnya <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                        <div id="thumbnail-container">
                            @forelse ($galleryImages as $galleryImage)
                                <img src="{{ $galleryImage['thumbnailUrl'] }}"
                                    alt="Thumbnail {{ $galleryImage['filename'] }}"
                                    title="{{ $galleryImage['filename'] }} ({{ $galleryImage['set'] }})"
                                    class="gallery-thumbnail clickable @if ($galleryImage['s3Path'] === $activeThumbS3Path) active-thumb @endif"
                                    data-image-path="{{ $galleryImage['mainImageS3PathForDataAttr'] }}"
                                    {{-- Pastikan ini adalah path S3 LENGKAP ke gambar utama --}} loading="lazy">
                            @empty
                                @if ($totalImages === 0)
                                    <p class="text-muted small w-100 text-center my-auto">Tidak ada gambar dalam
                                        antrian.</p>
                                @else
                                    <p class="text-muted small w-100 text-center my-auto">Tidak ada gambar di halaman
                                        ini.</p>
                                @endif
                            @endforelse
                        </div>
                    </div>

                    <div class="row g-4">
                        {{-- Kolom Gambar & Anotasi --}}
                        <div class="col-lg-8">
                            <div id="annotation-wrapper">
                                <div id="image-title-overlay">
                                    <span id="active-image-path">{{ $datasetSet }}/{{ $filename }}</span>
                                </div>
                                <div id="annotation-container">
                                    <img id="annotation-image" src="{{ $imageUrl }}" alt="Gambar untuk anotasi">
                                    <div id="bbox-overlay"></div>
                                </div>
                            </div>
                        </div>

                        {{-- Kolom Form Input & Daftar Bbox --}}
                        <div class="col-lg-4">
                            <form id="annotation-form" action="{{ route('annotate.save') }}" method="POST">
                                @csrf
                                {{-- PERBAIKI INPUT INI --}}
                                <input type="hidden" name="image_path" id="input-image-path"
                                    value="{{ $imagePathForCsv }}">
                                <input type="hidden" name="dataset_set" id="input-dataset-set"
                                    value="{{ $datasetSet }}">
                                <input type="hidden" name="annotations_json" id="input-annotations-json">

                                {{-- Section Deteksi --}}
                                <div class="annotation-section mb-4" id="detection-section">
                                    <label class="form-label section-title required">1. Deteksi Keberadaan
                                        Melon</label>
                                    <div class="section-content">
                                        <p class="section-instruction">Gambar apakah ini?</p>
                                        <div class="d-flex gap-3">
                                            <div class="form-check form-check-inline flex-fill">
                                                {{-- PERBAIKI INPUT INI (name="detection_choice") --}}
                                                <input class="form-check-input" type="radio"
                                                    name="detection_choice" id="detection-melon" value="melon"
                                                    required>
                                                <label class="form-check-label clickable" for="detection-melon">
                                                    <i class="fas fa-check-circle text-success"></i> Melon
                                                </label>
                                            </div>
                                            <div class="form-check form-check-inline flex-fill">
                                                {{-- PERBAIKI INPUT INI (name="detection_choice") --}}
                                                <input class="form-check-input" type="radio"
                                                    name="detection_choice" id="detection-nonmelon" value="non_melon"
                                                    required>
                                                <label class="form-check-label clickable" for="detection-nonmelon">
                                                    <i class="fas fa-times-circle text-danger"></i> Bukan Melon
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Area Anotasi Melon --}}
                                <div id="melon-annotation-area" class="hidden">
                                    <div class="annotation-section mb-4" id="bbox-list-section">
                                        <label class="form-label section-title">2. Tandai Lokasi Melon (<span
                                                id="bbox-count">0</span>)</label>
                                        <div class="section-content">
                                            <p class="section-instruction">Jika area melon tidak terdeteksi otomatis,
                                                klik & seret pada gambar. Pilih dari daftar untuk mengatur kematangan.
                                            </p>
                                            <div id="bbox-list-container">
                                                <ul id="bbox-list" class="list-group list-group-sm">
                                                    <li class="list-group-item text-muted no-bboxes">Belum ada Bbox
                                                        digambar.</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="annotation-section mb-4" id="ripeness-section">
                                        <div id="ripeness-options" class="hidden">
                                            <label class="form-label section-title required">3. Tingkat Kematangan
                                                (Bbox #<span id="selected-bbox-index"></span>)</label>
                                            <div class="section-content">
                                                <p class="section-instruction">Pilih tingkat kematangan untuk Bbox yang
                                                    sedang dipilih.</p>
                                                <div class="d-flex gap-3">
                                                    <div class="form-check form-check-inline flex-fill">
                                                        <input class="form-check-input ripeness-radio" type="radio"
                                                            name="ripeness_class_selector" id="ripeness-ripe"
                                                            value="ripe" disabled>
                                                        <label class="form-check-label clickable" for="ripeness-ripe">
                                                            <i class="fas fa-sun text-warning"></i> Matang
                                                        </label>
                                                    </div>
                                                    <div class="form-check form-check-inline flex-fill">
                                                        <input class="form-check-input ripeness-radio" type="radio"
                                                            name="ripeness_class_selector" id="ripeness-unripe"
                                                            value="unripe" disabled>
                                                        <label class="form-check-label clickable"
                                                            for="ripeness-unripe">
                                                            <i class="fas fa-seedling text-success"></i> Belum Matang
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Tombol Aksi --}}
                                <div class="d-grid gap-2 mt-4">
                                    <button type="submit" id="save-button" class="btn btn-primary" disabled>
                                        <i class="fas fa-save me-1"></i> Simpan Anotasi & Muat Berikutnya
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @else
            {{-- Fallback jika tidak ada gambar dan juga tidak selesai (error?) --}}
            <div class="card shadow">
                <div class="card-body text-center">
                    <div class="alert alert-warning mb-4">
                        <h4 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Tidak Ada Gambar
                        </h4>
                        <p class="mb-0">Tidak ada gambar yang tersedia untuk dianotasi saat ini.</p>
                    </div>
                    <a href="{{ route('melon.index') }}" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-1"></i> Kembali ke Dashboard
                    </a>
                </div>
            </div>
        @endif
    </div> {{-- Akhir #annotation-page-container --}}
</body>

</html>
