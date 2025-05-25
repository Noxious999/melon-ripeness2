// resources/js/evaluate.js

import { Chart, registerables } from 'chart.js';
Chart.register(...registerables);
import * as bootstrap from 'bootstrap';

document.addEventListener('DOMContentLoaded', function () {
    const evaluationData = window.evaluationData;
    const datasetActionsEndpoint = window.datasetActionsEndpoint;
    const csrfToken = window.csrfToken;
    const streamExtractFeaturesUrl = window.streamExtractFeaturesUrl;
    const streamExtractFeaturesOverwriteUrl = window.streamExtractFeaturesOverwriteUrl;
    const streamTrainClassifierUrl = window.streamTrainClassifierUrl;
    const streamTrainDetectorUrl = window.streamTrainDetectorUrl;

    let activeEventSources = {};
    const originalButtonHtmlCache = {};
    let sseInProgress = false;

    const sseButtonIds = [
        'extract-features-incremental-btn',
        'extract-features-overwrite-btn',
        'train-classifier-btn',
        'train-detector-btn'
    ];

    // --- DIPINDAHKAN KE ATAS: Cache elemen DOM untuk Indikator Progres SSE ---
    const sseProgressIndicator = document.getElementById('sse-progress-indicator');
    const minimizeSseBtn = document.getElementById('sse-minimize-btn');
    const sseDetailsCollapsible = document.querySelector('#sse-progress-indicator .sse-details-collapsible');
    const progressTitle = document.getElementById('sse-progress-title'); // Pindahkan juga ini jika sering diakses
    const progressStatusText = document.getElementById('sse-progress-status-text');
    const progressLogSummary = document.getElementById('sse-progress-log-summary');
    const globalProgressBar = document.getElementById('sse-global-progress-bar');
    const globalPercentageTextEl = document.getElementById('sse-global-percentage-text');


    function capitalizeFirstLetter(string) {
        if (!string) return '';
        return string.charAt(0).toUpperCase() + string.slice(1);
    }

    const refreshBtnTab = document.getElementById('refresh-dataset-status-btn-tab');
    if (refreshBtnTab) {
        refreshBtnTab.addEventListener('click', function () {
            this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Memperbarui...';
            this.disabled = true;
            window.location.reload();
        });
    }
    const markChangesSeenBtnTab = document.querySelector('.mark-changes-seen-btn-tab');
    if (markChangesSeenBtnTab) {
        markChangesSeenBtnTab.addEventListener('click', async function () {
            const url = this.dataset.url;
            const notificationDiv = this.closest('#dataset-change-notification-tab');
            const csrfTokenLocal = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfTokenLocal }
                });
                const data = await response.json();
                if (data.success && notificationDiv) {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(notificationDiv);
                    if (bsAlert) bsAlert.close(); else notificationDiv.style.display = 'none';
                } else {
                    alert(data.message || 'Gagal menandai perubahan.');
                }
            } catch (error) {
                alert('Error koneksi saat menandai perubahan.');
            }
        });
    }
    const hash = window.location.hash;
    if (hash) {
        const tabTriggerEl = document.querySelector(`.nav-tabs button[data-bs-target="${hash}"]`);
        if (tabTriggerEl) {
            const tab = new bootstrap.Tab(tabTriggerEl);
            tab.show();
            const parentTabPaneId = tabTriggerEl.closest('.tab-pane')?.id;
            if (parentTabPaneId && document.getElementById(parentTabPaneId)) {
                const parentTabTrigger = document.querySelector(`.nav-tabs.main-tabs button[data-bs-target="#${parentTabPaneId}"]`);
                if (parentTabTrigger) {
                    const parentTab = new bootstrap.Tab(parentTabTrigger);
                    parentTab.show();
                }
            }
        }
    }

    // Ganti dengan:
    function setupTabEventForCharts() {
        const modelTabButtons = document.querySelectorAll(
            '#classifierModelNavTabsEvaluate .nav-link, #detectorModelNavTabsEvaluate .nav-link'
        );

        modelTabButtons.forEach(button => {
            button.addEventListener('shown.bs.tab', function (event) {
                const paneId = event.target.getAttribute('data-bs-target').substring(1); // e.g., k-nearest-neighbors-classifier-eval-pane
                const modelKey = paneId.replace('-eval-pane', '').replace(/-/g, '_'); // e.g., k_nearest_neighbors_classifier

                if (window.evaluationData && window.evaluationData[modelKey]) {
                    const modelEvalData = window.evaluationData[modelKey];
                    const isDetector = modelKey.includes('_detector');
                    const chartIdSuffix = modelKey.replace(/_/g, '-');

                    console.log(`Tab shown for ${modelKey}, initializing charts.`);
                    initializeSingleModelCharts(modelKey, modelEvalData, isDetector, chartIdSuffix);
                } else {
                    console.warn(`No evaluation data found for modelKey: ${modelKey} when tab was shown.`);
                }
            });
        });

        // Inisialisasi chart untuk tab yang aktif saat halaman pertama kali dimuat
        const activeClassifierTab = document.querySelector('#classifierModelNavTabsEvaluate .nav-link.active');
        if (activeClassifierTab) {
            activeClassifierTab.dispatchEvent(new Event('shown.bs.tab', { bubbles: true, cancelable: true }));
        }
        const activeDetectorTab = document.querySelector('#detectorModelNavTabsEvaluate .nav-link.active');
        if (activeDetectorTab) {
            activeDetectorTab.dispatchEvent(new Event('shown.bs.tab', { bubbles: true, cancelable: true }));
        }
    }

    // Ganti pemanggilan initializeCharts() dengan yang baru:
    // initializeCharts(window.evaluationData); // HAPUS ATAU KOMENTARI INI
    if (typeof Chart !== 'undefined' && window.evaluationData) { // Pastikan Chart.js dan data ada
        setupTabEventForCharts();
    } else {
        console.warn("Chart.js atau window.evaluationData tidak tersedia, setup event chart dilewati.");
    }

    async function handleDatasetActionAjax(actionType) {
        const analysisResultDisplay = document.getElementById('dataset-analysis-display');
        const actionResultContainer = document.getElementById('dataset-action-result');
        const analyzeBtn = document.getElementById('analyze-quality-btn');
        const adjustBtn = document.getElementById('adjust-balance-btn');

        if (!analysisResultDisplay || !actionResultContainer || !analyzeBtn || !adjustBtn || !datasetActionsEndpoint || !csrfToken) {
            console.error("handleDatasetActionAjax: Missing critical elements or configuration.");
            if (actionResultContainer) actionResultContainer.innerHTML = '<div class="alert alert-danger mt-2 small p-2">Error konfigurasi: Elemen penting hilang.</div>';
            return;
        }
        const clickedBtn = (actionType === 'analyze') ? analyzeBtn : adjustBtn;
        if (!originalButtonHtmlCache[clickedBtn.id]) {
            originalButtonHtmlCache[clickedBtn.id] = clickedBtn.innerHTML;
        }
        const originalBtnHtml = originalButtonHtmlCache[clickedBtn.id];

        if (actionType === 'adjust' && clickedBtn.dataset.confirm) {
            if (!confirm(clickedBtn.dataset.confirmMessage || 'Anda yakin ingin melanjutkan aksi ini? Ini mungkin mengubah data Anda.')) {
                return;
            }
        }
        clickedBtn.disabled = true;
        clickedBtn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Memproses...`;
        if (actionType === 'analyze') {
            adjustBtn.disabled = true;
        } else {
            analyzeBtn.disabled = true;
        }
        actionResultContainer.innerHTML = '';
        if (actionType === 'analyze') {
            analysisResultDisplay.innerHTML = '<div class="d-flex justify-content-center align-items-center" style="min-height:100px;"><div class="spinner-border spinner-border-sm text-secondary" role="status"></div><span class="ms-2 text-muted small">Menganalisis kualitas dataset...</span></div>';
        }

        try {
            const response = await fetch(datasetActionsEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ action: actionType })
            });
            const data = await response.json();
            if (!response.ok) {
                throw new Error(data.message || `HTTP error ${response.status}`);
            }

            if (actionType === 'analyze') {
                let analysisHtml = '';
                if (data.success && data.details) {
                    const d = data.details;
                    if (d.issues && d.issues.length > 0 && !(d.issues.length === 1 && d.issues[0].startsWith("Tidak ada masalah"))) {
                        analysisHtml += '<div class="alert analysis-alert alert-warning mb-2"><h6 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>Masalah Terdeteksi</h6><ul class="mb-0 ps-3">';
                        d.issues.forEach(i => analysisHtml += `<li>${i}</li>`);
                        analysisHtml += '</ul></div>';
                    } else {
                        analysisHtml += '<div class="alert analysis-alert alert-success mb-2"><h6 class="alert-heading"><i class="fas fa-check-circle me-2"></i>Status Kualitas</h6><p class="mb-0">Tidak ada masalah keseimbangan signifikan atau kualitas data yang terdeteksi berdasarkan kriteria saat ini.</p></div>';
                    }
                    if (d.recommendations && d.recommendations.length > 0) {
                        analysisHtml += `<div class="alert analysis-alert alert-info mt-2"><h6 class="alert-heading"><i class="fas fa-lightbulb me-2"></i>Rekomendasi</h6><ul class="mb-0 ps-3">`;
                        d.recommendations.forEach(r => analysisHtml += `<li>${r}</li>`);
                        analysisHtml += '</ul></div>';
                    }
                } else {
                    analysisHtml = `<div class="alert alert-danger small p-2 mb-0">${data.message || 'Gagal memuat analisis dataset.'}</div>`;
                }
                analysisResultDisplay.innerHTML = analysisHtml;
            } else if (actionType === 'adjust') {
                let alertClass = data.success ? 'alert-success' : 'alert-danger';
                let icon = data.success ? 'fa-check-circle' : 'fa-times-circle';
                let detailsHtml = '';
                if (data.details) {
                    detailsHtml += '<hr class="my-2"><ul class="list-unstyled small mt-2 mb-0">';
                    if (data.details.files_moved?.length) detailsHtml += `<li><i class="fas fa-check text-success me-1"></i>${data.details.files_moved.length} file berhasil dipindahkan.</li>`;
                    if (data.details.files_failed_move?.length) detailsHtml += `<li><i class="fas fa-times text-danger me-1"></i>${data.details.files_failed_move.length} file gagal dipindahkan.</li>`;
                    if (data.details.csv_updates?.length) detailsHtml += `<li><i class="fas fa-file-csv text-info me-1"></i>Pembaruan CSV: ${data.details.csv_updates.join(', ')}</li>`;
                    if (data.details.errors?.length) detailsHtml += `<li><i class="fas fa-exclamation-triangle text-danger me-1"></i>${data.details.errors.length} error lain terdeteksi (cek log server).</li>`;
                    detailsHtml += '</ul>';
                }
                actionResultContainer.innerHTML = `<div class="alert ${alertClass} alert-dismissible fade show mt-2 small" role="alert"><i class="fas ${icon} me-2"></i>${data.message || (data.success ? 'Proses penyesuaian berhasil.' : 'Proses penyesuaian gagal.')}${detailsHtml}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert" aria-label="Close"></button></div>`;
                if (data.success) {
                    loadDatasetStats();
                }
            }
        } catch (error) {
            console.error(`Error on dataset action (${actionType}):`, error);
            actionResultContainer.innerHTML = `<div class="alert alert-danger mt-2 small p-2">Terjadi kesalahan: ${error.message}</div>`;
            if (actionType === 'analyze') {
                analysisResultDisplay.innerHTML = '<p class="text-danger small text-center">Gagal memuat analisis. Silakan coba lagi.</p>';
            }
        } finally {
            clickedBtn.innerHTML = originalBtnHtml;
            clickedBtn.disabled = false;
            analyzeBtn.disabled = false;
            adjustBtn.disabled = false;
        }
    }

    function setSseButtonsState(disabled) {
        sseButtonIds.forEach(id => {
            const button = document.getElementById(id);
            if (button) {
                button.disabled = disabled;
            }
        });
    }

    function setupArtisanStream(buttonId, streamUrl, logId, statusId, defaultCommandName) {
        const btn = document.getElementById(buttonId);
        const logEl = document.getElementById(logId);
        const statusEl = document.getElementById(statusId);
        const sourceKey = buttonId;

        if (!btn || !logEl || !statusEl || !streamUrl) {
            console.warn(`setupArtisanStream: Missing elements for buttonId: ${buttonId}`);
            return;
        }

        const commandName = btn.dataset.commandName || defaultCommandName;
        if (!originalButtonHtmlCache[btn.id]) {
            originalButtonHtmlCache[btn.id] = btn.innerHTML;
        }

        btn.addEventListener('click', () => {
            // Variabel UI Progress SSE dideklarasikan di scope DOMContentLoaded
            // jadi bisa diakses di sini.
            if (sseProgressIndicator && sseDetailsCollapsible) {
                sseProgressIndicator.classList.remove('minimized');
                sseDetailsCollapsible.style.display = 'block';
                if (minimizeSseBtn) { // Pastikan minimizeSseBtn ada sebelum diakses
                    minimizeSseBtn.innerHTML = '<i class="fas fa-minus"></i>';
                    minimizeSseBtn.setAttribute('title', 'Minimize Detail');
                    const tooltipInstance = bootstrap.Tooltip.getInstance(minimizeSseBtn);
                    if (tooltipInstance) {
                        tooltipInstance.setContent({ '.tooltip-inner': 'Minimize Detail' });
                    }
                }
            }

            if (sseInProgress) {
                showGlobalNotification('Proses lain sedang berjalan. Harap tunggu hingga selesai.', 'warning', 'ajax-notification-placeholder');
                return;
            }

            const confirmMsg = btn.dataset.confirmMessage || `Anda yakin ingin menjalankan ${commandName}?`;
            if (btn.dataset.confirm && !confirm(confirmMsg)) {
                return;
            }

            if (activeEventSources[sourceKey]) {
                activeEventSources[sourceKey].close();
                console.log(`Closed existing EventSource for ${sourceKey}`);
            }

            sseInProgress = true;
            setSseButtonsState(true);

            const currentOriginalBtnHtml = originalButtonHtmlCache[btn.id];
            btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Menghubungkan...`;

            logEl.innerHTML = '';
            logEl.style.display = 'block';
            statusEl.textContent = `Menghubungkan ke server untuk ${commandName}...`;
            statusEl.className = 'text-muted small text-center mt-2 mb-1';

            // Akses variabel UI Progress SSE yang sudah di-cache
            if (sseProgressIndicator && progressTitle && progressStatusText && progressLogSummary && globalProgressBar && globalPercentageTextEl) {
                progressTitle.textContent = `${commandName}`;
                progressStatusText.textContent = "Menghubungkan...";
                progressLogSummary.innerHTML = "Menunggu output log...";
                if (sseDetailsCollapsible) sseDetailsCollapsible.style.display = 'block'; // Pastikan detail terlihat
                globalProgressBar.style.width = "0%";
                globalProgressBar.classList.remove('bg-success', 'bg-danger');
                globalProgressBar.classList.add('progress-bar-animated', 'bg-info');
                globalPercentageTextEl.textContent = "0%"; // Reset persentase teks
                sseProgressIndicator.style.display = 'block';
                sseProgressIndicator.classList.remove('minimized');
                if (minimizeSseBtn) minimizeSseBtn.innerHTML = '<i class="fas fa-minus"></i>';
            }

            const newEventSource = new EventSource(streamUrl);
            activeEventSources[sourceKey] = newEventSource;

            newEventSource.onopen = () => {
                statusEl.textContent = `Terhubung. Proses ${commandName} dimulai...`;
                statusEl.className = 'text-primary small text-center mt-2 mb-1 fw-semibold';
                btn.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Berjalan...`;
                if (progressStatusText) progressStatusText.textContent = "Berjalan...";
                if (globalProgressBar) globalProgressBar.style.width = "2%";
                if (globalPercentageTextEl) globalPercentageTextEl.textContent = "2%";
            };

            newEventSource.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    let displayLog = '';

                    if (progressTitle && progressTitle.textContent !== commandName && sseProgressIndicator && !sseProgressIndicator.classList.contains('minimized')) {
                        progressTitle.textContent = commandName;
                    }

                    if (data.log) {
                        const trimmedLine = data.log.trim();
                        if (trimmedLine.startsWith('PROGRESS_UPDATE:')) {
                            const progressText = trimmedLine.substring('PROGRESS_UPDATE:'.length).trim();
                            const percentMatchJs = progressText.match(/(\d{1,3}(?:[.,]\d{1,2})?%)/);
                            if (percentMatchJs && globalProgressBar) {
                                let percent = parseFloat(percentMatchJs[1].replace('%', '').replace(',', '.'));
                                percent = Math.min(Math.max(percent, 0), 100);
                                globalProgressBar.style.width = `${percent}%`;
                                if (globalPercentageTextEl) globalPercentageTextEl.textContent = `${percent.toFixed(0)}%`;
                                if (progressStatusText && sseProgressIndicator && !sseProgressIndicator.classList.contains('minimized')) {
                                    progressStatusText.textContent = `Berjalan... ${percent.toFixed(0)}%`;
                                }
                            }
                            displayLog = progressText;
                        } else if (trimmedLine.startsWith('EVENT_LOG:') || data.type === 'important' || trimmedLine.startsWith('✅') || trimmedLine.startsWith('🚀') || trimmedLine.startsWith('Error') || trimmedLine.startsWith('Warning') || trimmedLine.startsWith('Total') || trimmedLine.startsWith('Duration') || trimmedLine.includes('Written') || trimmedLine.includes('Skipped')) {
                            displayLog = trimmedLine.startsWith('EVENT_LOG:') ? trimmedLine.substring('EVENT_LOG:'.length).trim() : trimmedLine;
                        } else if (data.type === 'verbose' && trimmedLine.length < 150 && !trimmedLine.startsWith('EVENT_STATUS:')) {
                            // displayLog = `> ${trimmedLine}`;
                        }

                        if (displayLog && sseProgressIndicator && !sseProgressIndicator.classList.contains('minimized')) {
                            const p = document.createElement('p');
                            p.className = 'mb-0';
                            p.textContent = displayLog;
                            logEl.appendChild(p);
                            logEl.scrollTop = logEl.scrollHeight;
                            if (progressLogSummary) {
                                progressLogSummary.textContent = displayLog.length > 70 ? displayLog.substring(0, 70) + "..." : displayLog;
                            }
                        }
                    }

                    if (data.status || (data.log && data.log.startsWith('EVENT_STATUS:'))) {
                        let currentStatus = data.status;
                        let currentMessage = data.message;
                        let commandDone = data.command_name_done;

                        if (data.log && data.log.startsWith('EVENT_STATUS:')) {
                            const parts = data.log.substring('EVENT_STATUS:'.length).trim().split(',');
                            currentStatus = parts[0].trim();
                            currentMessage = parts.length > 1 ? parts.slice(1).join(',').trim() : `Proses ${commandName} ${currentStatus}.`;
                            commandDone = commandName;
                        }

                        if (currentStatus === 'DONE' || currentStatus === 'ERROR' || currentStatus === 'TIMEOUT') {
                            statusEl.textContent = currentMessage || `Proses ${commandName} ${currentStatus}.`;
                            statusEl.className = `small text-center mt-2 mb-1 fw-semibold ${currentStatus === 'DONE' ? 'text-success' : 'text-danger'}`;
                            if (progressStatusText) progressStatusText.textContent = currentStatus === 'DONE' ? "Selesai!" : "Gagal/Timeout";
                            if (globalProgressBar) {
                                globalProgressBar.style.width = "100%";
                                globalProgressBar.classList.remove('progress-bar-animated', 'bg-info');
                                globalProgressBar.classList.add(currentStatus === 'DONE' ? 'bg-success' : 'bg-danger');
                            }
                            if (globalPercentageTextEl) globalPercentageTextEl.textContent = "100%";


                            newEventSource.close();
                            activeEventSources[sourceKey] = null;
                            btn.innerHTML = currentOriginalBtnHtml;

                            sseInProgress = false;
                            setSseButtonsState(false);

                            setTimeout(() => {
                                if (sseProgressIndicator && !sseProgressIndicator.classList.contains('minimized')) {
                                    sseProgressIndicator.style.display = 'none';
                                }
                                // Tidak mereset kelas 'minimized' di sini agar pilihan pengguna tetap
                            }, currentStatus === 'DONE' ? 4000 : 8000);

                            if (currentStatus === 'DONE' && commandDone === commandName) {
                                const notificationMessage = currentMessage || `${commandName} selesai. Halaman akan dimuat ulang.`;
                                showGlobalNotification(notificationMessage, 'success', 'ajax-notification-placeholder');
                                setTimeout(() => window.location.reload(), 3000);
                            } else if (currentStatus !== 'DONE') {
                                showGlobalNotification(currentMessage || `${commandName} gagal atau timeout.`, 'danger', 'ajax-notification-placeholder');
                            }
                        } else if (currentStatus === 'START') {
                            statusEl.textContent = currentMessage || `Proses ${commandName} dimulai...`;
                            if (progressStatusText) progressStatusText.textContent = "Memulai...";
                            if (globalPercentageTextEl) globalPercentageTextEl.textContent = "0%";
                        }
                    }
                } catch (e) {
                    console.error('Error parsing SSE data:', e, event.data);
                    if (logEl) {
                        logEl.textContent += "Error parsing data: " + event.data + '\n';
                        logEl.scrollTop = logEl.scrollHeight;
                    }
                }
            };

            newEventSource.onerror = (err) => {
                console.error(`SSE error for ${commandName}:`, err);
                statusEl.textContent = `Koneksi error atau terputus untuk ${commandName}.`;
                statusEl.className = 'text-danger small text-center mb-1 fw-semibold';
                if (activeEventSources[sourceKey]) {
                    activeEventSources[sourceKey].close();
                }
                activeEventSources[sourceKey] = null;
                btn.innerHTML = currentOriginalBtnHtml;

                sseInProgress = false;
                setSseButtonsState(false);

                if (sseProgressIndicator) sseProgressIndicator.style.display = 'none';
            };
        });
    }

    function showGlobalNotification(message, type = 'info', areaId = 'ajax-notification-placeholder', duration = 7000) {
        const area = document.getElementById(areaId);
        if (!area) { console.warn("Global notification area tidak ditemukan:", areaId); return; }
        const alertClass = `alert-${type}`;
        const iconClass = { success: 'fa-check-circle', info: 'fa-info-circle', warning: 'fa-exclamation-triangle', danger: 'fa-times-circle' }[type] || 'fa-info-circle';
        const alertId = `global-alert-${Date.now()}`;
        const alertHTML = `
            <div id="${alertId}" class="alert ${alertClass} alert-dismissible fade show shadow-sm" role="alert">
                <i class="fas ${iconClass} me-2"></i>${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>`;
        area.insertAdjacentHTML('beforeend', alertHTML);
        const alertElement = document.getElementById(alertId);
        if (duration > 0) {
            setTimeout(() => {
                if (alertElement) {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alertElement);
                    if (bsAlert) { bsAlert.close(); }
                    else { alertElement.remove(); }
                }
            }, duration);
        }
    }

    setupArtisanStream('extract-features-incremental-btn', streamExtractFeaturesUrl, 'extract-features-log', 'extract-features-status', 'Ekstraksi Fitur (Incremental)');
    setupArtisanStream('extract-features-overwrite-btn', streamExtractFeaturesOverwriteUrl, 'extract-features-log', 'extract-features-status', 'Ekstraksi Fitur (Timpa)');
    setupArtisanStream('train-classifier-btn', streamTrainClassifierUrl, 'train-classifier-log', 'train-classifier-status', 'Training Klasifikasi');
    setupArtisanStream('train-detector-btn', streamTrainDetectorUrl, 'train-detector-log', 'train-detector-status', 'Training Detektor');

    // Event listener untuk tombol minimize SSE progress (sudah di atas, dekat deklarasi variabelnya)
    if (minimizeSseBtn && sseProgressIndicator && sseDetailsCollapsible) {
        minimizeSseBtn.addEventListener('click', () => {
            sseProgressIndicator.classList.toggle('minimized');
            // Tandai bahwa minimize/maximize dilakukan oleh pengguna
            if (sseProgressIndicator.classList.contains('minimized')) {
                sseDetailsCollapsible.style.display = 'none';
                minimizeSseBtn.innerHTML = '<i class="fas fa-plus"></i>';
                minimizeSseBtn.setAttribute('title', 'Maximize Detail');
                sseProgressIndicator.classList.add('minimized-by-user'); // Tambah flag
            } else {
                sseDetailsCollapsible.style.display = 'block';
                minimizeSseBtn.innerHTML = '<i class="fas fa-minus"></i>';
                minimizeSseBtn.setAttribute('title', 'Minimize Detail');
                sseProgressIndicator.classList.remove('minimized-by-user'); // Hapus flag
            }
            const tooltipInstance = bootstrap.Tooltip.getInstance(minimizeSseBtn);
            if (tooltipInstance) {
                tooltipInstance.setContent({ '.tooltip-inner': minimizeSseBtn.getAttribute('title') });
            }
        });
    }


    async function loadDatasetStats() {
        const loadingEl = document.getElementById('stats-loader');
        const statsContentEl = document.getElementById('stats-content');
        const timestampEl = document.getElementById('stat-timestamp');
        const statsErrorEl = document.getElementById('stats-error-message');

        if (!loadingEl || !statsContentEl || !timestampEl || !statsErrorEl || !datasetActionsEndpoint || !csrfToken) {
            if (loadingEl) loadingEl.style.display = 'none';
            if (statsErrorEl) {
                statsErrorEl.innerHTML = '<div class="alert alert-danger small p-2"><i class="fas fa-exclamation-circle me-1"></i> Error konfigurasi UI: Elemen penting tidak ditemukan pada halaman.</div>';
                statsErrorEl.style.display = 'block';
            }
            return;
        }
        loadingEl.innerHTML = '<div class="spinner-border text-primary" role="status" style="width: 2rem; height: 2rem;"><span class="visually-hidden">Memuat...</span></div><span class="ms-2 text-muted">Memuat statistik dataset...</span>';
        loadingEl.style.display = 'flex';
        statsContentEl.style.display = 'none';
        statsErrorEl.style.display = 'none';
        statsErrorEl.innerHTML = '';

        try {
            const response = await fetch(datasetActionsEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ action: 'get_stats' })
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ message: `HTTP error ${response.status}` }));
                throw new Error(errorData.message || `Gagal mengambil data: Status ${response.status}`);
            }
            const data = await response.json();

            if (data.success && data.stats && Object.keys(data.stats).length > 0) {
                updateStatsDisplayNew(data.stats);
                timestampEl.textContent = data.timestamp ?
                    new Date(data.timestamp).toLocaleString('id-ID', {
                        day: '2-digit', month: 'short', year: 'numeric',
                        hour: '2-digit', minute: '2-digit', second: '2-digit'
                    }) : 'N/A';
                statsContentEl.style.display = 'block';
                console.log('Set statsContentEl display to block');
                loadingEl.style.display = 'none';
            } else {
                let errorMessage = data.message || 'Gagal memproses data statistik dari server.';
                if (data.success && (!data.stats || Object.keys(data.stats).length === 0)) {
                    errorMessage = 'Data statistik tidak ditemukan atau kosong.';
                }
                statsErrorEl.innerHTML = `<div class="alert alert-warning small p-2"><i class="fas fa-info-circle me-1"></i> ${errorMessage}</div>`;
                statsErrorEl.style.display = 'block';
                loadingEl.style.display = 'none';
                statsContentEl.style.display = 'none';
            }
        } catch (error) {
            statsErrorEl.innerHTML = `<div class="alert alert-danger small p-2"><i class="fas fa-exclamation-circle me-1"></i> Gagal memuat statistik: ${error.message}. Coba lagi nanti.</div>`;
            statsErrorEl.style.display = 'block';
            loadingEl.style.display = 'none';
            statsContentEl.style.display = 'none';
        } finally {
            if (loadingEl) {
                loadingEl.classList.add('hidden-by-js');
                loadingEl.classList.remove('d-flex', 'flex-column', 'justify-content-center', 'align-items-center', 'py-5');
            }
        }
    }

    const recentUpdatesTabContentUrl = window.recentUpdatesTabContentUrl; // Akan kita definisikan di Blade
    const refreshDatasetStatusBtnOnUpdatesTab = document.getElementById('refresh-dataset-status-btn-tab');
    // Wrapper untuk konten yang akan diganti HTML-nya
    const updatesTabContentWrapper = document.getElementById('updates-quality-subtab-pane');
    // Elemen untuk menampilkan timestamp pembaruan di header card "Status Integritas..."
    const lastUpdatedTimestampElement = document.querySelector('#quality-main-tab-pane .card-header .text-muted');

    if (refreshDatasetStatusBtnOnUpdatesTab && updatesTabContentWrapper && recentUpdatesTabContentUrl) {
        if (!originalButtonHtmlCache[refreshDatasetStatusBtnOnUpdatesTab.id]) {
            originalButtonHtmlCache[refreshDatasetStatusBtnOnUpdatesTab.id] = refreshDatasetStatusBtnOnUpdatesTab.innerHTML;
        }

        refreshDatasetStatusBtnOnUpdatesTab.addEventListener('click', async function () {
            const originalHtml = originalButtonHtmlCache[this.id];
            this.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Memperbarui...';
            this.disabled = true;

            if (updatesTabContentWrapper) updatesTabContentWrapper.style.opacity = '0.5';

            try {
                const response = await fetch(recentUpdatesTabContentUrl, {
                    method: 'GET', // Method GET karena kita hanya mengambil data/HTML
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfToken // CSRF mungkin tidak wajib untuk GET, tapi tidak masalah disertakan
                    }
                });

                if (!response.ok) {
                    let errorMsg = 'Gagal memuat ulang status.';
                    try {
                        const errData = await response.json();
                        errorMsg = errData.message || errorMsg;
                    } catch (e) { /* abaikan jika bukan json */ }
                    throw new Error(errorMsg);
                }

                const data = await response.json();

                if (data.success && data.html) {
                    updatesTabContentWrapper.innerHTML = data.html;

                    // Update timestamp "Terakhir diperbarui" di header card
                    if (lastUpdatedTimestampElement && data.timestamp) {
                        lastUpdatedTimestampElement.textContent = 'Terakhir diperbarui: ' + data.timestamp;
                    }

                    // Inisialisasi ulang komponen Bootstrap seperti tooltip jika ada di dalam konten baru
                    const newTooltips = updatesTabContentWrapper.querySelectorAll('[data-bs-toggle="tooltip"]');
                    newTooltips.forEach(tooltipEl => new bootstrap.Tooltip(tooltipEl, { html: true, boundary: 'window' }));

                    // Inisialisasi ulang listener untuk tombol "mark changes as seen" di dalam konten baru
                    const newMarkSeenBtn = updatesTabContentWrapper.querySelector('.mark-changes-seen-btn-tab'); // Sesuaikan selector jika perlu
                    if (newMarkSeenBtn) {
                        // Anda perlu fungsi handleMarkSeen terpisah atau menyalin logikanya ke sini
                        // atau re-attach listener jika fungsi handleMarkSeen sudah ada global
                        // Contoh sederhana:
                        newMarkSeenBtn.addEventListener('click', async function () {
                            const url = this.dataset.url;
                            const notificationDiv = this.closest('#dataset-change-notification-tab'); // ID ini ada di dalam partial
                            try {
                                const resp = await fetch(url, {
                                    method: 'POST',
                                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrfToken }
                                });
                                const d = await resp.json();
                                if (d.success && notificationDiv) {
                                    const bsAlert = bootstrap.Alert.getOrCreateInstance(notificationDiv);
                                    if (bsAlert) bsAlert.close(); else notificationDiv.style.display = 'none';
                                } else {
                                    showGlobalNotification(d.message || 'Gagal menandai perubahan.', 'danger', 'ajax-notification-placeholder');
                                }
                            } catch (err) {
                                showGlobalNotification('Error koneksi saat menandai perubahan.', 'danger', 'ajax-notification-placeholder');
                            }
                        });
                    }


                    showGlobalNotification('Status Integritas & Kelengkapan Dataset berhasil diperbarui.', 'success', 'ajax-notification-placeholder');
                } else {
                    throw new Error(data.message || 'Gagal memuat ulang konten status dari server.');
                }

            } catch (error) {
                console.error('Error refreshing dataset integrity status:', error);
                showGlobalNotification(`Gagal memperbarui status: ${error.message}`, 'danger', 'ajax-notification-placeholder');
            } finally {
                this.innerHTML = originalHtml;
                this.disabled = false;
                if (updatesTabContentWrapper) updatesTabContentWrapper.style.opacity = '1';
            }
        });
    }

    function updateStatsDisplayNew(stats) {
        const sets = ['train', 'valid', 'test'];
        let grandTotalImagesInCsv = 0;
        let grandTotalMelonAnnotations = 0;
        let grandTotalNonMelonAnnotations = 0;
        let grandTotalRipeAnnotations = 0;
        let grandTotalUnripeAnnotations = 0;
        let grandTotalDetectorFeatures = 0;
        let grandTotalClassifierFeatures = 0;
        let detailElement;

        sets.forEach(set => {
            const setDataAnnotations = stats[set]?.annotations || { total_images_in_csv: 0, melon_annotations: 0, non_melon_annotations: 0, ripe_annotations: 0, unripe_annotations: 0 };
            const setDataFeatures = stats[set]?.features || {
                detector: { melon: 0, non_melon: 0, total: 0 },
                classifier: { ripe: 0, unripe: 0, total: 0 }
            };

            detailElement = document.getElementById(`stat-detail-${set}-total`);
            if (detailElement) detailElement.textContent = setDataAnnotations.total_images_in_csv || 0;
            detailElement = document.getElementById(`stat-detail-${set}-melon`);
            if (detailElement) detailElement.textContent = setDataAnnotations.melon_annotations || 0;
            detailElement = document.getElementById(`stat-detail-${set}-non_melon`);
            if (detailElement) detailElement.textContent = setDataAnnotations.non_melon_annotations || 0;
            detailElement = document.getElementById(`stat-detail-${set}-ripe`);
            if (detailElement) detailElement.textContent = setDataAnnotations.ripe_annotations || 0;
            detailElement = document.getElementById(`stat-detail-${set}-unripe`);
            if (detailElement) detailElement.textContent = setDataAnnotations.unripe_annotations || 0;

            grandTotalImagesInCsv += parseInt(setDataAnnotations.total_images_in_csv || 0);
            grandTotalMelonAnnotations += parseInt(setDataAnnotations.melon_annotations || 0);
            grandTotalNonMelonAnnotations += parseInt(setDataAnnotations.non_melon_annotations || 0);
            grandTotalRipeAnnotations += parseInt(setDataAnnotations.ripe_annotations || 0);
            grandTotalUnripeAnnotations += parseInt(setDataAnnotations.unripe_annotations || 0);

            detailElement = document.getElementById(`stat-feat-det-${set}-melon`);
            if (detailElement) detailElement.textContent = setDataFeatures.detector?.melon || 0;
            detailElement = document.getElementById(`stat-feat-det-${set}-non_melon`);
            if (detailElement) detailElement.textContent = setDataFeatures.detector?.non_melon || 0;
            detailElement = document.getElementById(`stat-feat-det-${set}-total`);
            if (detailElement) detailElement.textContent = setDataFeatures.detector?.total || 0;
            grandTotalDetectorFeatures += parseInt(setDataFeatures.detector?.total || 0);

            detailElement = document.getElementById(`stat-feat-cls-${set}-ripe`);
            if (detailElement) detailElement.textContent = setDataFeatures.classifier?.ripe || 0;
            detailElement = document.getElementById(`stat-feat-cls-${set}-unripe`);
            if (detailElement) detailElement.textContent = setDataFeatures.classifier?.unripe || 0;
            detailElement = document.getElementById(`stat-feat-cls-${set}-total`);
            if (detailElement) detailElement.textContent = setDataFeatures.classifier?.total || 0;
            grandTotalClassifierFeatures += parseInt(setDataFeatures.classifier?.total || 0);
        });

        const totalImagesEl = document.getElementById('stat-total-images');
        if (totalImagesEl) totalImagesEl.textContent = grandTotalImagesInCsv;
        const totalMelonEl = document.getElementById('stat-total-melon-annotations');
        if (totalMelonEl) totalMelonEl.textContent = grandTotalMelonAnnotations;
        const totalNonMelonEl = document.getElementById('stat-total-nonmelon-annotations');
        if (totalNonMelonEl) totalNonMelonEl.textContent = grandTotalNonMelonAnnotations;
        const totalRipeEl = document.getElementById('stat-total-ripe-annotations');
        if (totalRipeEl) totalRipeEl.textContent = grandTotalRipeAnnotations;
        const totalDetectorFeaturesEl = document.getElementById('stat-total-detector-features');
        if (totalDetectorFeaturesEl) totalDetectorFeaturesEl.textContent = grandTotalDetectorFeatures;
        const totalClassifierFeaturesEl = document.getElementById('stat-total-classifier-features');
        if (totalClassifierFeaturesEl) totalClassifierFeaturesEl.textContent = grandTotalClassifierFeatures;
    }

    function createSingleBarChart(canvasId, metricsDataSource, modelTypeLabel, isDetectorChart, chartTitlePrefix = '') {
        const canvasElement = document.getElementById(canvasId);
        if (!canvasElement) {
            console.warn(`Canvas element #${canvasId} not found.`);
            return;
        }
        const metricsContainer = canvasElement.closest('.metric-chart-container');
        if (!metricsContainer) {
            console.warn(`Container .metric-chart-container for #${canvasId} not found.`);
            return;
        }

        metricsContainer.innerHTML = '';
        const newCanvas = document.createElement('canvas');
        newCanvas.id = canvasId;
        newCanvas.style.maxHeight = metricsContainer.dataset.chartHeight || "220px";
        metricsContainer.appendChild(newCanvas);

        if (!metricsDataSource || typeof metricsDataSource !== 'object') {
            metricsContainer.innerHTML = `<p class="text-center text-muted small my-auto">Grafik metrik ${modelTypeLabel.toLowerCase()} tidak tersedia (data sumber hilang).</p>`;
            return;
        }
        const metrics = metricsDataSource.metrics || metricsDataSource;
        const positiveClassKey = isDetectorChart ? 'melon' : 'ripe';
        const negativeClassKey = isDetectorChart ? 'non_melon' : 'unripe';
        const positiveMetrics = metrics[positiveClassKey] || metrics.positive;
        const negativeMetrics = metrics[negativeClassKey] || metrics.negative;

        if (!metrics || typeof metrics.accuracy === 'undefined' || !positiveMetrics || !negativeMetrics) {
            metricsContainer.innerHTML = `<p class="text-center text-muted small my-auto">Struktur data metrik ${modelTypeLabel.toLowerCase()} tidak lengkap untuk ${positiveClassKey}/${negativeClassKey}.</p>`;
            console.warn("Incomplete metrics structure:", metrics, "Expected keys:", positiveClassKey, negativeClassKey);
            return;
        }

        const positiveDisplayLabel = capitalizeFirstLetter((positiveMetrics.label || positiveClassKey).replace(/_/g, ' '));
        const negativeDisplayLabel = capitalizeFirstLetter((negativeMetrics.label || negativeClassKey).replace(/_/g, ' '));
        const precisionPos = (positiveMetrics.precision ?? 0) * 100;
        const recallPos = (positiveMetrics.recall ?? 0) * 100;
        const f1Pos = (positiveMetrics.f1_score ?? 0) * 100;
        const precisionNeg = (negativeMetrics.precision ?? 0) * 100;
        const recallNeg = (negativeMetrics.recall ?? 0) * 100;
        const f1Neg = (negativeMetrics.f1_score ?? 0) * 100;

        const chartData = {
            labels: [
                `Presisi (${positiveDisplayLabel})`, `Recall (${positiveDisplayLabel})`, `F1 (${positiveDisplayLabel})`,
                `Presisi (${negativeDisplayLabel})`, `Recall (${negativeDisplayLabel})`, `F1 (${negativeDisplayLabel})`
            ],
            datasets: [{
                label: `Nilai Metrik (%)`,
                data: [precisionPos, recallPos, f1Pos, precisionNeg, recallNeg, f1Neg],
                backgroundColor: [
                    'rgba(25, 135, 84, 0.6)', 'rgba(25, 135, 84, 0.8)', 'rgba(25, 135, 84, 1)',
                    'rgba(255, 193, 7, 0.6)', 'rgba(255, 193, 7, 0.8)', 'rgba(255, 193, 7, 1)'
                ],
                borderColor: [
                    'rgba(25, 135, 84, 1)', 'rgba(25, 135, 84, 1)', 'rgba(25, 135, 84, 1)',
                    'rgba(255, 193, 7, 1)', 'rgba(255, 193, 7, 1)', 'rgba(255, 193, 7, 1)'
                ],
                borderWidth: 1, barThickness: 'flex', maxBarThickness: 30, borderRadius: 5
            }]
        };

        try {
            new Chart(newCanvas, {
                type: 'bar', data: chartData, options: {
                    responsive: true, maintainAspectRatio: false,
                    indexAxis: 'x',
                    scales: {
                        y: { beginAtZero: true, max: 100, ticks: { callback: value => value + '%', font: { size: 10 } } },
                        x: { ticks: { font: { size: 9 }, autoSkip: false, maxRotation: 25, minRotation: 0 } }
                    },
                    plugins: {
                        title: { display: true, text: `${chartTitlePrefix}Metrik Performa Kelas`, font: { size: 13, weight: '600' }, padding: { bottom: 15 } },
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(0,0,0,0.8)', titleFont: { weight: 'bold' }, bodyFont: { size: 11 },
                            callbacks: {
                                label: function (context) {
                                    let label = context.label || '';
                                    if (context.parsed.y !== null) { label += `: ${context.parsed.y.toFixed(1)}%`; }
                                    return label;
                                }
                            }
                        }
                    },
                    animation: { duration: 800, easing: 'easeInOutQuart' }
                }
            });
        } catch (error) {
            console.error(`Error creating bar chart #${canvasId}:`, error);
            metricsContainer.innerHTML = '<p class="text-center text-danger small my-auto">Gagal membuat grafik metrik.</p>';
        }
    }

    function createLearningCurveChart(canvasId, lcDataSource) {
        const canvasElement = document.getElementById(canvasId);
        if (!canvasElement) { return; }

        const curveContainer = canvasElement.closest('.learning-curve-graphic-container');
        if (!curveContainer) return;

        curveContainer.innerHTML = '';
        const newCanvas = document.createElement('canvas');
        newCanvas.id = canvasId;
        newCanvas.style.maxHeight = curveContainer.dataset.chartHeight || "180px";
        curveContainer.appendChild(newCanvas);


        const hasValidLCData = lcDataSource && Array.isArray(lcDataSource.train_sizes) && lcDataSource.train_sizes.length > 0 &&
            Array.isArray(lcDataSource.train_scores) && Array.isArray(lcDataSource.test_scores) &&
            lcDataSource.train_sizes.length === lcDataSource.train_scores.length &&
            lcDataSource.train_sizes.length === lcDataSource.test_scores.length;

        if (!hasValidLCData) {
            curveContainer.innerHTML = '<p class="text-center text-muted small my-auto">Data grafik learning curve tidak lengkap atau tidak valid.</p>';
            return;
        }

        const data = {
            labels: lcDataSource.train_sizes,
            datasets: [
                {
                    label: 'Skor Training', data: lcDataSource.train_scores.map(score => (score !== null ? score * 100 : null)),
                    borderColor: 'rgba(54, 162, 235, 1)', backgroundColor: 'rgba(54, 162, 235, 0.1)',
                    fill: false, tension: 0.2, borderWidth: 2.5, pointRadius: 3, pointHoverRadius: 5,
                },
                {
                    label: 'Skor Validasi Silang', data: lcDataSource.test_scores.map(score => (score !== null ? score * 100 : null)),
                    borderColor: 'rgba(255, 99, 132, 1)', backgroundColor: 'rgba(255, 99, 132, 0.1)',
                    fill: false, tension: 0.2, borderWidth: 2.5, pointRadius: 3, pointHoverRadius: 5
                }
            ]
        };

        try {
            new Chart(newCanvas, {
                type: 'line', data: data, options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        title: { display: true, text: 'Learning Curve', font: { size: 13, weight: '600' }, padding: { top: 5, bottom: 15 } },
                        legend: { position: 'bottom', labels: { font: { size: 10 }, usePointStyle: true, boxWidth: 15, padding: 15 } },
                        tooltip: {
                            mode: 'index', intersect: false, backgroundColor: 'rgba(0,0,0,0.8)',
                            callbacks: { label: context => `${context.dataset.label}: ${context.parsed.y !== null ? context.parsed.y.toFixed(1) + '%' : 'N/A'}` }
                        }
                    },
                    scales: {
                        x: { title: { display: true, text: 'Jumlah Sampel Training', font: { size: 11 } }, ticks: { font: { size: 9 } } },
                        y: { title: { display: true, text: 'Skor Akurasi (%)', font: { size: 11 } }, min: 0, max: 100, ticks: { callback: value => value + '%', font: { size: 9 }, stepSize: 10 } }
                    },
                    hover: { mode: 'nearest', intersect: true },
                    animation: { duration: 800, easing: 'easeInOutQuart' }
                }
            });
        } catch (error) {
            console.error(`Error creating learning curve chart #${canvasId}:`, error);
            curveContainer.innerHTML = '<p class="text-center text-danger small my-auto">Gagal membuat grafik learning curve.</p>';
        }
    }

    function initializeSingleModelCharts(modelKey, modelEvalData, isDetector, chartIdSuffix) {
        if (!modelEvalData) return;

        const validationChartCanvasId = `metricsChart_${chartIdSuffix}_validation`;
        const testChartCanvasId = `metricsChart_${chartIdSuffix}_test`;
        const lcCanvasId = `learningCurve_${chartIdSuffix}`;

        // Logika render chart validasi (pastikan canvas ada dan belum ada chart)
        const validationChartContainer = document.getElementById(validationChartCanvasId)?.closest('.metric-chart-container');
        if (validationChartContainer && document.getElementById(validationChartCanvasId) && !Chart.getChart(validationChartCanvasId)) {
            const validationMetricsDataForChart = modelEvalData.validation_metrics?.metrics_per_class;
            if (validationMetricsDataForChart) {
                createSingleBarChart(validationChartCanvasId, validationMetricsDataForChart, 'Valid', isDetector, 'Valid: ');
            } else {
                validationChartContainer.innerHTML = '<p class="text-center text-muted small my-auto">Data metrik validasi tidak tersedia untuk grafik.</p>';
            }
        }

        // Logika render chart test set (pastikan canvas ada dan belum ada chart)
        const testChartContainer = document.getElementById(testChartCanvasId)?.closest('.metric-chart-container');
        if (testChartContainer && document.getElementById(testChartCanvasId) && !Chart.getChart(testChartCanvasId)) {
            const testMetricsDataForChart = modelEvalData.test_results?.metrics;
            if (testMetricsDataForChart) {
                createSingleBarChart(testChartCanvasId, testMetricsDataForChart, 'Test', isDetector, 'Test: ');
            } else {
                testChartContainer.innerHTML = '<p class="text-center text-muted small my-auto">Data metrik test set tidak tersedia untuk grafik.</p>';
            }
        }

        // Logika render learning curve (pastikan canvas ada dan belum ada chart)
        const lcContainer = document.getElementById(lcCanvasId)?.closest('.learning-curve-graphic-container');
        if (lcContainer && document.getElementById(lcCanvasId) && !Chart.getChart(lcCanvasId)) {
            const learningCurveData = modelEvalData.learning_curve_data;
            if (learningCurveData && learningCurveData.train_sizes && learningCurveData.train_sizes.length > 0) { // Tambah cek data LC
                createLearningCurveChart(lcCanvasId, learningCurveData);
            } else {
                lcContainer.innerHTML = '<p class="text-center text-muted small my-auto">Data grafik learning curve tidak tersedia.</p>';
            }
        }
    }

    loadDatasetStats();

    const getStatsBtn = document.getElementById('get-stats-btn');
    const analyzeBtn = document.getElementById('analyze-quality-btn');
    const adjustBtn = document.getElementById('adjust-balance-btn');

    if (getStatsBtn) getStatsBtn.addEventListener('click', () => loadDatasetStats());
    if (analyzeBtn) analyzeBtn.addEventListener('click', () => handleDatasetActionAjax('analyze'));
    if (adjustBtn) adjustBtn.addEventListener('click', () => handleDatasetActionAjax('adjust'));

    const scrollToTopButton = document.getElementById('evaluateScrollToTopBtn');
    if (scrollToTopButton) {
        window.onscroll = function () {
            if (document.body.scrollTop > 200 || document.documentElement.scrollTop > 200) {
                scrollToTopButton.style.display = "flex";
                scrollToTopButton.style.justifyContent = "center";
                scrollToTopButton.style.alignItems = "center";
            } else {
                scrollToTopButton.style.display = "none";
            }
        };
        scrollToTopButton.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl, {
            html: true,
            boundary: 'window'
        });
    });
});
