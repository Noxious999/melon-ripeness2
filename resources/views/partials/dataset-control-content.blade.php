{{-- resources/views/partials/dataset-control-content.blade.php --}}
{{-- Bagian Aksi Dataset & Analisis Kualitas --}}
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-light py-3">
                <h6 class="mb-0 text-primary-emphasis"><i class="fas fa-cogs me-2 text-primary"></i>Aksi Kualitas
                    &
                    Keseimbangan</h6>
            </div>
            <div class="card-body d-flex flex-column">
                <p class="small text-muted mb-3">Gunakan tombol di bawah untuk menganalisis atau menyesuaikan
                    dataset Anda secara otomatis.</p>
                <div class="d-grid gap-2 mb-3">
                    <button id="analyze-quality-btn" class="btn btn-info text-white btn-sm" data-action="analyze">
                        <i class="fas fa-flask-vial me-1"></i> Analisis Kualitas Dataset
                    </button>
                    <button id="adjust-balance-btn" class="btn btn-warning text-dark btn-sm" data-action="adjust"
                        data-confirm="true"
                        data-confirm-message="PERHATIAN! Aksi ini akan MEMINDAHKAN file gambar fisik dan MENULIS ULANG file anotasi CSV Anda. Pastikan Anda memiliki backup data jika diperlukan. Lanjutkan penyesuaian?">
                        <i class="fas fa-balance-scale-right me-1"></i> Sesuaikan Keseimbangan Set
                    </button>
                </div>
                <div id="dataset-action-result" class="mt-auto small">
                    {{-- Hasil dari aksi AJAX akan muncul di sini --}}
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-light py-3">
                <h6 class="mb-0 text-primary-emphasis"><i class="fas fa-clipboard-check me-2 text-primary"></i>Hasil
                    Analisis Kualitas</h6>
            </div>
            <div class="card-body" id="dataset-analysis-display" style="min-height: 200px;">
                <div class="d-flex flex-column justify-content-center align-items-center text-center h-100"
                    id="initial-analysis-placeholder">
                    <i class="fas fa-file-medical-alt fa-3x text-muted mb-3"></i>
                    <p class="text-muted">Hasil analisis kualitas dataset akan ditampilkan di sini.</p>
                    <small class="text-muted">Klik tombol "Analisis Kualitas Dataset" untuk memulai.</small>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12 mb-4">
        <div class="card shadow-sm control-card">
            <div class="card-body">
                <h6 class="card-title text-primary"><i class="fas fa-cogs me-2"></i>Ekstraksi Fitur Dataset</h6>
                <p class="small text-muted">[BELUM ADA MODEL? LAKUKAN TRAINING MODEL DETEKSI+KLASIFIKASI AGAR EKSTRAKSI
                    FITUR DAPAT BEKERJA] Lakukan ekstraksi fitur gambar sebagai data kelengkapan
                    pelatihan model deteksi dan klasifikasi, seusai ekstraksi harap lakukan training model.</p>

                {{-- Indikator Ekstraksi Terakhir (Incremental) --}}
                @if (isset($lastExtractIncrementalAction) && $lastExtractIncrementalAction)
                    <div class="last-action-info small text-muted mb-2 p-2 border rounded bg-light">
                        <strong><i class="fas fa-history me-1"></i>Mode Incremental Terakhir:</strong>
                        {{ $lastExtractIncrementalAction['performed_at_human'] }}<br>
                        Status: <span
                            class="fw-bold {{ $lastExtractIncrementalAction['status'] === 'Sukses' ? 'text-success' : 'text-danger' }}">{{ $lastExtractIncrementalAction['status'] }}</span>
                        |
                        Durasi: {{ $lastExtractIncrementalAction['duration_seconds'] ?? 'N/A' }}s <br>
                        <details class="mt-1">
                            <summary class="text-info" style="cursor: pointer; font-size: 0.9em;">Detail Output
                            </summary>
                            <pre class="bg-dark text-white p-2 rounded mt-1" style="max-height: 100px; overflow-y: auto; font-size: 0.8em;">{{ $lastExtractIncrementalAction['output_summary'] ?? 'Tidak ada ringkasan output.' }}</pre>
                        </details>
                    </div>
                @endif
                {{-- Indikator Ekstraksi Terakhir (Overwrite) --}}
                @if (isset($lastExtractOverwriteAction) && $lastExtractOverwriteAction)
                    <div class="last-action-info small text-muted mb-2 p-2 border rounded bg-light">
                        <strong><i class="fas fa-history me-1"></i>Mode Timpa Terakhir:</strong>
                        {{ $lastExtractOverwriteAction['performed_at_human'] }}<br>
                        Status: <span
                            class="fw-bold {{ $lastExtractOverwriteAction['status'] === 'Sukses' ? 'text-success' : 'text-danger' }}">{{ $lastExtractOverwriteAction['status'] }}</span>
                        |
                        Durasi: {{ $lastExtractOverwriteAction['duration_seconds'] ?? 'N/A' }}s <br>
                        <details class="mt-1">
                            <summary class="text-info" style="cursor: pointer; font-size: 0.9em;">Detail Output
                            </summary>
                            <pre class="bg-dark text-white p-2 rounded mt-1" style="max-height: 100px; overflow-y: auto; font-size: 0.8em;">{{ $lastExtractOverwriteAction['output_summary'] ?? 'Tidak ada ringkasan output.' }}</pre>
                        </details>
                    </div>
                @endif

                <div class="d-flex flex-wrap gap-2">
                    <button id="extract-features-incremental-btn" class="btn btn-info flex-grow-1 sse-action-btn"
                        data-stream-url="{{ route('evaluate.stream.extract_features_incremental') }}"
                        data-log-id="extract-features-log" data-status-id="extract-features-status"
                        data-command-name="Ekstraksi Fitur (Incremental)" data-confirm="true"
                        data-confirm-message="Anda yakin ingin menjalankan ekstraksi fitur (mode incremental/append)? Ini akan memproses item baru dan menambahkannya ke file fitur yang ada di S3.">
                        <i class="fas fa-plus-circle me-1"></i> Ekstrak Fitur (Tambah Baru)
                    </button>
                    <button id="extract-features-overwrite-btn" class="btn btn-warning flex-grow-1 sse-action-btn"
                        data-stream-url="{{ route('evaluate.stream.extract_features_overwrite') }}"
                        data-log-id="extract-features-log" {{-- Bisa pakai log & status ID yang sama --}}
                        data-status-id="extract-features-status" data-command-name="Ekstraksi Fitur (Timpa Semua)"
                        data-confirm="true"
                        data-confirm-message="PERHATIAN: Ini akan MENGHAPUS dan membuat ulang SEMUA file fitur di S3 berdasarkan anotasi saat ini. Proses bisa lama. Anda yakin?">
                        <i class="fas fa-bolt me-1"></i> Ekstrak Fitur (Timpa Total)
                    </button>
                </div>
                <div id="extract-features-status" class="text-muted small text-center mt-2 mb-1"></div>
                <pre id="extract-features-log" class="sse-log bg-dark text-white p-2 rounded small"
                    style="display:none; max-height: 200px; overflow-y: auto;"></pre>
            </div>
        </div>
    </div>

    {{-- Training Detektor --}}
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm control-card h-100">
            <div class="card-body">
                <h6 class="card-title text-primary"><i class="fas fa-search-location me-2"></i>Training Model
                    Detektor</h6>
                @if (isset($lastTrainDetectorAction) && $lastTrainDetectorAction)
                    <div class="last-action-info small text-muted mb-2 p-2 border rounded bg-light">
                        <strong><i class="fas fa-history me-1"></i>Training Terakhir:</strong>
                        {{ $lastTrainDetectorAction['performed_at_human'] }}<br>
                        Status: <span
                            class="fw-bold {{ $lastTrainDetectorAction['status'] === 'Sukses' ? 'text-success' : 'text-danger' }}">{{ $lastTrainDetectorAction['status'] }}</span>
                        |
                        Durasi: {{ $lastTrainDetectorAction['duration_seconds'] ?? 'N/A' }}s <br>
                        <details class="mt-1">
                            <summary class="text-info" style="cursor: pointer; font-size: 0.9em;">Detail Output
                            </summary>
                            <pre class="bg-dark text-white p-2 rounded mt-1" style="max-height: 100px; overflow-y: auto; font-size: 0.8em;">{{ $lastTrainDetectorAction['output_summary'] ?? 'Tidak ada ringkasan output.' }}</pre>
                        </details>
                    </div>
                @endif
                <button id="train-detector-btn" class="btn btn-primary w-100 sse-action-btn"
                    data-stream-url="{{ route('evaluate.stream.train_detector') }}" data-log-id="train-detector-log"
                    data-status-id="train-detector-status" data-command-name="Training Model Detektor"
                    data-confirm="true"
                    data-confirm-message="Anda yakin ingin memulai training ulang SEMUA model detektor? Proses ini bisa memakan waktu yang signifikan.">
                    <i class="fas fa-brain me-1"></i> Latih Model Detektor
                </button>
                <div id="train-detector-status" class="text-muted small text-center mt-2 mb-1"></div>
                <pre id="train-detector-log" class="sse-log bg-dark text-white p-2 rounded small"
                    style="display:none; max-height: 200px; overflow-y: auto;"></pre>
            </div>
        </div>
    </div>

    {{-- Training Classifier --}}
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm control-card h-100">
            <div class="card-body">
                <h6 class="card-title text-primary"><i class="fas fa-tags me-2"></i>Training Model Klasifikasi
                </h6>
                @if (isset($lastTrainClassifierAction) && $lastTrainClassifierAction)
                    <div class="last-action-info small text-muted mb-2 p-2 border rounded bg-light">
                        <strong><i class="fas fa-history me-1"></i>Training Terakhir:</strong>
                        {{ $lastTrainClassifierAction['performed_at_human'] }}<br>
                        Status: <span
                            class="fw-bold {{ $lastTrainClassifierAction['status'] === 'Sukses' ? 'text-success' : 'text-danger' }}">{{ $lastTrainClassifierAction['status'] }}</span>
                        |
                        Durasi: {{ $lastTrainClassifierAction['duration_seconds'] ?? 'N/A' }}s <br>
                        <details class="mt-1">
                            <summary class="text-info" style="cursor: pointer; font-size: 0.9em;">Detail Output
                            </summary>
                            <pre class="bg-dark text-white p-2 rounded mt-1" style="max-height: 100px; overflow-y: auto; font-size: 0.8em;">{{ $lastTrainClassifierAction['output_summary'] ?? 'Tidak ada ringkasan output.' }}</pre>
                        </details>
                    </div>
                @endif
                <button id="train-classifier-btn" class="btn btn-primary w-100 sse-action-btn"
                    data-stream-url="{{ route('evaluate.stream.train_classifier') }}"
                    data-log-id="train-classifier-log" data-status-id="train-classifier-status"
                    data-command-name="Training Model Klasifikasi" data-confirm="true"
                    data-confirm-message="Anda yakin ingin memulai training ulang SEMUA model klasifikasi? Proses ini bisa memakan waktu yang signifikan. Pastikan fitur sudah diekstrak.">
                    <i class="fas fa-brain me-1"></i> Latih Model Klasifikasi
                </button>
                <div id="train-classifier-status" class="text-muted small text-center mt-2 mb-1"></div>
                <pre id="train-classifier-log" class="sse-log bg-dark text-white p-2 rounded small"
                    style="display:none; max-height: 200px; overflow-y: auto;"></pre>
            </div>
        </div>
    </div>
</div>

{{-- Placeholder untuk Fitur CRUD Dataset Manual --}}
<div class="card shadow-sm mt-4">
    <div class="card-header bg-light py-3">
        <h5 class="mb-0 text-primary-emphasis"><i class="fas fa-folder-open me-2 text-primary"></i>Akses & Modifikasi
            Dataset Manual</h5>
    </div>
    <div class="card-body p-lg-4">
        <p class="text-muted small">Fitur untuk mengelola file dataset (gambar dan CSV anotasi/fitur) secara langsung
            akan tersedia di sini di masa mendatang. Untuk saat ini, silakan kelola file melalui sistem file server Anda
            atau alat eksternal.</p>
        <div class="alert alert-info">
            <i class="fas fa-tools me-1"></i> Fitur CRUD manual dataset (seperti file manager atau editor CSV) sedang
            dalam pertimbangan untuk pengembangan di masa depan.
        </div>
    </div>
</div>
