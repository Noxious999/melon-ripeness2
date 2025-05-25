{{-- resources/views/partials/dataset-stats-content.blade.php --}}
{{-- Konten untuk Sub-Tab "Statistik Kualitas" --}}

{{-- Bagian Statistik Dataset --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-light py-3">
        <h5 class="mb-0 d-flex align-items-center text-primary-emphasis">
            <i class="fas fa-chart-pie me-2 text-primary"></i>Statistik Komposisi Dataset
        </h5>
    </div>
    <div class="card-body p-lg-4">
        <div class="d-flex justify-content-end mb-3">
            <button id="get-stats-btn" class="btn btn-sm btn-outline-primary rounded-pill">
                <i class="fas fa-sync-alt me-1"></i> Muat Ulang Statistik
            </button>
        </div>

        <div id="dataset-stats-container">
            {{-- Loader untuk Statistik --}}
            <div class="d-flex flex-column justify-content-center align-items-center py-5" id="stats-loader">
                <div class="spinner-border text-primary" role="status" style="width: 2.5rem; height: 2.5rem;">
                    <span class="visually-hidden">Memuat Statistik...</span>
                </div>
                <p class="mt-3 mb-0 text-muted">Memuat statistik dataset terkini...</p>
            </div>

            {{-- TAMBAHKAN INI: Tempat untuk menampilkan pesan error statistik --}}
            <div id="stats-error-message" class="mt-3" style="display: none;"></div>

            {{-- Konten Statistik (Awalnya disembunyikan) --}}
            <div id="stats-content" style="display: none;">
                {{-- Ringkasan Total dalam Stat Cards --}}
                {{-- BARIS PERTAMA STAT CARDS --}}
                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-lg-4"> {{-- Ubah ke col-lg-4 untuk 3 kartu per baris --}}
                        <div class="stat-card text-center h-100">
                            <div class="stat-card-icon text-primary mb-2"><i class="fas fa-images fa-2x"></i></div>
                            <h6 class="stat-title text-muted small text-uppercase">Total Gambar (di CSV Anotasi)</h6>
                            <p class="stat-value mb-0 display-6 fw-bold" id="stat-total-images">0</p>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-4"> {{-- Ubah ke col-lg-4 --}}
                        <div class="stat-card text-center h-100">
                            <div class="stat-card-icon text-success mb-2"><i class="fas fa-seedling fa-2x"></i>
                            </div>
                            <h6 class="stat-title text-muted small text-uppercase">Anotasi Melon</h6>
                            <p class="stat-value mb-0 display-6 fw-bold" id="stat-total-melon-annotations">0</p>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-4"> {{-- Ubah ke col-lg-4 --}}
                        <div class="stat-card text-center h-100">
                            <div class="stat-card-icon text-secondary mb-2"><i class="fas fa-ban fa-2x"></i></div>
                            <h6 class="stat-title text-muted small text-uppercase">Anotasi Non-Melon</h6>
                            <p class="stat-value mb-0 display-6 fw-bold" id="stat-total-nonmelon-annotations">0</p>
                        </div>
                    </div>
                </div>

                {{-- BARIS KEDUA STAT CARDS --}}
                <div class="row g-3 mb-4">
                    <div class="col-sm-6 col-lg-4"> {{-- Ubah ke col-lg-4 --}}
                        <div class="stat-card text-center h-100">
                            <div class="stat-card-icon text-warning mb-2"><i class="fas fa-sun fa-2x"></i></div>
                            <h6 class="stat-title text-muted small text-uppercase">Anotasi Matang</h6>
                            <p class="stat-value mb-0 display-6 fw-bold" id="stat-total-ripe-annotations">0</p>
                        </div>
                    </div>
                    {{-- BARU: Stat Card untuk Total Fitur Detektor --}}
                    <div class="col-sm-6 col-lg-4"> {{-- Ubah ke col-lg-4 --}}
                        <div class="stat-card text-center h-100">
                            <div class="stat-card-icon text-info mb-2"><i class="fas fa-search-location fa-2x"></i>
                            </div>
                            <h6 class="stat-title text-muted small text-uppercase">Total Fitur Detektor</h6>
                            <p class="stat-value mb-0 display-6 fw-bold" id="stat-total-detector-features">0</p>
                        </div>
                    </div>
                    {{-- BARU: Stat Card untuk Total Fitur Classifier --}}
                    <div class="col-sm-6 col-lg-4"> {{-- Ubah ke col-lg-4 --}}
                        <div class="stat-card text-center h-100">
                            <div class="stat-card-icon text-purple mb-2"><i class="fas fa-brain fa-2x"></i></div>
                            {{-- Contoh ikon baru, sesuaikan --}}
                            <h6 class="stat-title text-muted small text-uppercase">Total Fitur Classifier</h6>
                            <p class="stat-value mb-0 display-6 fw-bold" id="stat-total-classifier-features">0</p>
                        </div>
                    </div>
                </div>

                {{-- Detail Anotasi per Set dalam Tabel --}}
                <h6 class="text-dark fw-semibold mb-3 mt-4 pt-2 border-top">
                    <i class="fas fa-list-alt me-2 text-muted"></i>Rincian Anotasi per Set Data:
                </h6>
                <div class="table-responsive rounded-2 border">
                    <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="table-light">
                            <tr class="text-center">
                                <th class="text-start ps-3">Set Data</th>
                                <th>Total Anotasi</th>
                                <th>Melon</th>
                                <th>Non_Melon</th>
                                <th>Matang <small class="d-block text-muted">(dari Melon)</small></th>
                                <th>Belum_Matang <small class="d-block text-muted">(dari Melon)</small></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="text-center">
                                <td class="fw-medium text-start ps-3">Data Latih (Train)</td>
                                <td id="stat-detail-train-total">0</td>
                                <td id="stat-detail-train-melon">0</td>
                                <td id="stat-detail-train-non_melon">0</td>
                                <td id="stat-detail-train-ripe">0</td>
                                <td id="stat-detail-train-unripe">0</td>
                            </tr>
                            <tr class="text-center">
                                <td class="fw-medium text-start ps-3">Data Validasi (Validation)</td>
                                <td id="stat-detail-valid-total">0</td>
                                <td id="stat-detail-valid-melon">0</td>
                                <td id="stat-detail-valid-non_melon">0</td>
                                <td id="stat-detail-valid-ripe">0</td>
                                <td id="stat-detail-valid-unripe">0</td>
                            </tr>
                            <tr class="text-center">
                                <td class="fw-medium text-start ps-3">Data Uji (Test)</td>
                                <td id="stat-detail-test-total">0</td>
                                <td id="stat-detail-test-melon">0</td>
                                <td id="stat-detail-test-non_melon">0</td>
                                <td id="stat-detail-test-ripe">0</td>
                                <td id="stat-detail-test-unripe">0</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                {{-- BARU: Rincian Ekstraksi Fitur per Label --}}
                <h6 class="text-dark fw-semibold mb-3 mt-4 pt-3 border-top">
                    <i class="fas fa-cogs me-2 text-muted"></i>Rincian Ekstraksi Fitur per Label:
                </h6>
                <div class="table-responsive rounded-2 border mb-4">
                    <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="table-light">
                            <tr class="text-center">
                                <th class="text-start ps-3" rowspan="2" style="vertical-align: middle;">Set Data
                                </th>
                                <th colspan="3">Fitur Detektor</th>
                                <th colspan="3">Fitur Classifier</th>
                            </tr>
                            <tr class="text-center">
                                <th>Melon</th>
                                <th>Non-Melon</th>
                                <th>Total Det.</th>
                                <th>Matang</th>
                                <th>Belum Matang</th>
                                <th>Total Cls.</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (['train', 'valid', 'test'] as $set)
                                <tr class="text-center">
                                    <td class="fw-medium text-start ps-3">{{ ucfirst($set) }}</td>
                                    <td id="stat-feat-det-{{ $set }}-melon">0</td>
                                    <td id="stat-feat-det-{{ $set }}-non_melon">0</td>
                                    <td id="stat-feat-det-{{ $set }}-total" class="fw-bold">0</td>
                                    <td id="stat-feat-cls-{{ $set }}-ripe">0</td>
                                    <td id="stat-feat-cls-{{ $set }}-unripe">0</td>
                                    <td id="stat-feat-cls-{{ $set }}-total" class="fw-bold">0</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-end text-muted small mt-2 mb-0">
                    <i class="fas fa-clock me-1"></i>Terakhir diperbarui: <span id="stat-timestamp"
                        class="fw-semibold">Memuat...</span>
                </p>
            </div>
        </div>
    </div>
</div>
