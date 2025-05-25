// resources/js/app.js

import './bootstrap'; // Jika ada, untuk Laravel
import * as bootstrap from 'bootstrap'; // Import Bootstrap JS
window.bootstrap = bootstrap; // Jadikan Bootstrap global jika diperlukan

// === AWAL PERUBAHAN: Deklarasi Variabel Global ===
let initialDefaultDetectionResultForButtonLogic = null; // Deklarasikan di sini agar bisa diakses global dalam scope DOMContentLoaded
// === AKHIR PERUBAHAN ===
let feedbackGivenForCurrentImage = false; // Flag untuk menandai feedback

document.addEventListener('DOMContentLoaded', () => {
    // === 1. Cache Element DOM Global ===
    const uploadForm = document.getElementById('upload-form');
    const imageInput = uploadForm ? uploadForm.querySelector('input[type="file"]') : null;
    const resultSection = document.getElementById('result-section');
    const overlay = document.getElementById('overlay');
    const overlayText = document.getElementById('overlay-text');
    const notificationAreaMain = document.getElementById('notification-area-main');
    const clearAppCacheBtn = document.getElementById('clear-app-cache-btn');
    const scrollToTopBtn = document.getElementById('scrollToTopBtn');
    const pendingAnnotationReminderContainer = document.getElementById('pending-annotation-reminder-container');
    const navPendingCountBadge = document.getElementById('nav-pending-count-badge');

    // Variabel global & konfigurasi
    const predictUrl = window.predictDefaultUrl;
    const allResultsUrl = window.getAllResultsUrl;
    const feedbackDetectionUrl = window.feedbackDetectionUrl;
    const feedbackClassificationUrl = window.feedbackClassificationUrl;
    const annotateUrlGlobal = window.annotateUrlGlobal;
    const clearCacheUrl = window.clearCacheUrl;
    const csrfToken = window.csrfToken;
    let currentResultData = window.resultData && window.resultData.filename && Object.keys(window.resultData).length > 2 ? window.resultData : {};
    const modelKeysForView = window.modelKeysForView || {};
    let currentPendingAnnotationCount = window.initialPendingAnnotationCount || 0;
    const triggerPiCameraUrl = window.triggerPiCameraUrl;
    const runBboxClassifyOnDemandUrl = window.runBboxClassifyOnDemandUrl;

    // Cache elemen yang dibutuhkan oleh fungsi-fungsi di bawah
    const detectorsResultsList = document.getElementById('detectors-results');
    const classifiersResultsList = document.getElementById('classifiers-results');
    const detectorsLoadingSpinner = document.getElementById('detectors-loading');
    const classifiersLoadingSpinner = document.getElementById('classifiers-loading');
    const majorityVoteDetectorResultDiv = document.getElementById('detector-majority-vote-container');
    const majorityVoteDetectorBadge = document.getElementById('detector-majority-vote-text-badge');
    const majorityVoteResultDiv = document.getElementById('majority-vote-result');
    const majorityVoteBadge = document.getElementById('majority-vote-badge');
    const otherModelResultTemplate = document.getElementById('other-model-result-template');
    const singleDetectorRerunSpinner = document.getElementById('single-detector-rerun-spinner');
    const runAllDetectorsButton = document.querySelector('.run-other-model[data-task="detector"][data-run="all"]');

    // --- BARU: Elemen untuk Toggle Mode Input ---
    const predictionModeToggle = document.getElementById('prediction-mode-toggle');
    const predictionModeLabel = document.getElementById('prediction-mode-label');
    const uploadManualSection = document.getElementById('upload-manual-section');
    const receivePiSection = document.getElementById('receive-pi-section');
    const triggerPiCameraButton = document.getElementById('trigger-pi-camera-btn'); // Tombol di dalam receivePiSection
    const piStatusDisplay = document.getElementById('pi-status-display'); // Elemen status di dalam receivePiSection

    function showOverlay(text = 'Memproses...') { if (overlay && overlayText) { overlayText.textContent = text; overlay.style.display = 'flex'; } }
    function hideOverlay() { if (overlay) { overlay.style.display = 'none'; } }

    function showNotification(message, type = 'info', area = notificationAreaMain, duration = 5000) {
        if (!area) { console.warn("Notification area tidak ditemukan:", area); return; }
        const alertClass = `alert-${type}`;
        const iconClass = { success: 'fa-check-circle', info: 'fa-info-circle', warning: 'fa-exclamation-triangle', danger: 'fa-times-circle' }[type] || 'fa-info-circle';
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert ${alertClass} alert-dismissible fade show`;
        alertDiv.innerHTML = `<i class="fas ${iconClass} me-2"></i>${message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
        area.prepend(alertDiv);
        if (duration > 0) { setTimeout(() => { const bsAlert = bootstrap.Alert.getOrCreateInstance(alertDiv); if (bsAlert) { bsAlert.close(); } }, duration); }
    }
    const formatPercent = (value, digits = 1) => (typeof value === 'number' ? (value * 100).toFixed(digits) + '%' : 'N/A');
    const ucfirst = (str) => (typeof str === 'string' && str.length > 0 ? str.charAt(0).toUpperCase() + str.slice(1) : '');

    function generateMetricsTableHTML(metricsRoot, isDetector) {
        if (!metricsRoot || typeof metricsRoot.accuracy === 'undefined') {
            return `<p class="text-muted small fst-italic text-center">Data metrik tidak lengkap atau tidak valid.</p>`;
        }
        const accuracy = metricsRoot.accuracy ?? null;
        const positiveClassKey = isDetector ? 'melon' : 'ripe';
        const negativeClassKey = isDetector ? 'non_melon' : 'unripe';
        const posData = metricsRoot[positiveClassKey] || metricsRoot.positive || {};
        const negData = metricsRoot[negativeClassKey] || metricsRoot.negative || {};
        const positiveDisplayLabel = ucfirst((posData.label || positiveClassKey).replace(/_/g, ' '));
        const negativeDisplayLabel = ucfirst((negData.label || negativeClassKey).replace(/_/g, ' '));

        let tableHtml = `<table class="table table-sm table-bordered table-hover small">
                            <thead class="table-light">
                                <tr><th scope="col">Metrik</th><th scope="col" class="text-center">Nilai</th></tr>
                            </thead>
                            <tbody>
                                <tr><td>Akurasi Global</td><td class="text-center">${formatPercent(accuracy)}</td></tr>
                            </tbody>
                        </table>`;
        const kelasData = [
            { label: positiveDisplayLabel, data: posData, type: "Positif" },
            { label: negativeDisplayLabel, data: negData, type: "Negatif" }
        ];
        kelasData.forEach(k => {
            if (Object.keys(k.data).length > 0 && (k.data.label || (isDetector ? (k.type === "Positif" ? 'melon' : 'non_melon') : (k.type === "Positif" ? 'ripe' : 'unripe'))) && typeof k.data.precision !== 'undefined') {
                tableHtml += `<table class="table table-sm table-bordered table-hover small mt-2">
                                <thead class="table-light">
                                    <tr><th colspan="2" class="text-center">Kelas ${k.label} (${k.type})</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td>Presisi</td><td class="text-center">${formatPercent(k.data.precision)}</td></tr>
                                    <tr><td>Recall</td><td class="text-center">${formatPercent(k.data.recall)}</td></tr>
                                    <tr><td>F1-Score</td><td class="text-center">${formatPercent(k.data.f1_score)}</td></tr>
                                    <tr><td>Support</td><td class="text-center">${Number(k.data.support || 0).toLocaleString()}</td></tr>
                                </tbody>
                              </table>`;
            } else {
                tableHtml += `<p class="text-muted small fst-italic text-center mt-2">Data metrik kelas ${k.label} (${k.type}) tidak tersedia/lengkap.</p>`;
            }
        });
        return tableHtml;
    }

    function updateMetricsDisplay(metricsContainerElement, modelNameElement, metricsDataSource, isDetector, modelName) {
        if (!metricsContainerElement || !modelNameElement) { return; }
        modelNameElement.textContent = modelName || 'N/A';
        if (!metricsDataSource || typeof metricsDataSource !== 'object') {
            metricsContainerElement.innerHTML = `<p class="text-muted small fst-italic text-center">Data metrik tidak tersedia.</p>`;
            return;
        }
        // Pastikan metricsDataSource.metrics yang di-pass ke generateMetricsTableHTML
        metricsContainerElement.innerHTML = generateMetricsTableHTML(metricsDataSource.metrics || metricsDataSource, isDetector);
    }

    function updatePendingAnnotationReminderDisplay(count) {
        if (pendingAnnotationReminderContainer) {
            const strongEl = pendingAnnotationReminderContainer.querySelector('strong');
            if (strongEl) strongEl.textContent = count; // Update count
            if (count > 0) {
                pendingAnnotationReminderContainer.style.display = 'block';
            } else {
                pendingAnnotationReminderContainer.style.display = 'none';
            }
        }
        if (navPendingCountBadge) {
            if (count > 0) {
                navPendingCountBadge.textContent = count;
                navPendingCountBadge.style.display = '';
            } else {
                navPendingCountBadge.style.display = 'none';
            }
        }
    }

    const markChangesSeenButtons = document.querySelectorAll('.mark-changes-seen-btn');
    markChangesSeenButtons.forEach(button => {
        button.addEventListener('click', async function () {
            const url = this.dataset.url;
            const notificationDiv = this.closest('#dataset-change-notification');
            const csrfTokenLocal = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrfTokenLocal
                    }
                });
                const data = await response.json();
                if (data.success) {
                    if (notificationDiv) {
                        const bsAlert = bootstrap.Alert.getOrCreateInstance(notificationDiv);
                        if (bsAlert) {
                            bsAlert.close();
                        } else {
                            notificationDiv.style.display = 'none';
                        }
                    }
                } else {
                    showNotification(data.message || 'Gagal menandai perubahan.', 'danger');
                }
            } catch (error) {
                console.error('Error marking dataset changes as seen:', error);
                showNotification('Error koneksi saat menandai perubahan.', 'danger');
            }
        });
    });

    function resetOtherModelsDisplay() {
        if (detectorsResultsList) detectorsResultsList.innerHTML = '';
        if (classifiersResultsList) classifiersResultsList.innerHTML = '';
        if (detectorsLoadingSpinner) detectorsLoadingSpinner.classList.add('d-none');
        if (classifiersLoadingSpinner) classifiersLoadingSpinner.classList.add('d-none');
        if (majorityVoteDetectorResultDiv) majorityVoteDetectorResultDiv.style.display = 'none';
        if (majorityVoteResultDiv) majorityVoteResultDiv.style.display = 'none';

        const runAllDetectorBtn = document.querySelector('.run-other-model[data-task="detector"][data-run="all"]');
        const runAllClassifierBtn = document.querySelector('.run-other-model[data-task="classifier"][data-run="all"]');
        if (runAllDetectorBtn) runAllDetectorBtn.disabled = false;
        if (runAllClassifierBtn) runAllClassifierBtn.disabled = false;
    }

    function displayFullPredictionResult(data, isInitialPrediction = false) { // Tambahkan parameter isInitialPrediction
        const resultSection = document.getElementById('result-section');
        const pipelineErrorDisplay = document.getElementById('pipeline-error-display');
        const resultFilenameElement = document.getElementById('result-filename-display');
        const uploadedImageElement = document.getElementById('uploaded-image');
        const uploadedImagePlaceholder = document.getElementById('uploaded-image-placeholder');
        const detectionImageDisplay = document.getElementById('detection-image-display');
        const detectionImagePlaceholder = document.getElementById('detection-image-placeholder');
        const bboxOverlayElement = document.getElementById('bbox-overlay');

        const mainSummaryCard = document.getElementById('main-summary-card');
        const summaryDetectorModelName = document.getElementById('summary-detector-model-name');
        const summaryDetectorBadge = document.getElementById('summary-detector-badge');
        const summaryDetectorConfidenceBar = document.getElementById('summary-detector-confidence-bar');
        const summaryDetectorConfidenceText = document.getElementById('summary-detector-confidence-text');
        const summaryBboxStatusMessage = document.getElementById('summary-bbox-status-message');
        const summaryClassificationColumn = document.getElementById('summary-classification-column');
        const summaryClassifierModelName = document.getElementById('summary-classifier-model-name');
        const summaryClassifierBadge = document.getElementById('summary-classifier-badge');
        const summaryClassifierConfidenceBar = document.getElementById('summary-classifier-confidence-bar');
        const summaryClassifierConfidenceText = document.getElementById('summary-classifier-confidence-text');

        const scientificExplanationCard = document.getElementById('scientific-explanation-card');
        const scientificExplanationContent = document.getElementById('scientific-explanation-content');

        const feedbackCard = document.getElementById('feedback-card');
        const detectorFeedbackWrapper = document.getElementById('detector-feedback-wrapper');
        const feedbackDetectionPromptText = document.getElementById('feedback-detection-prompt-text');
        const feedbackDetectionForm = document.getElementById('feedback-detection-form');
        const feedbackDetectionResultArea = document.getElementById('feedback-detection-result');
        const classifierFeedbackWrapper = document.getElementById('classifier-feedback-wrapper');
        const feedbackClassificationPredictionText = document.getElementById('feedback-classification-prediction-text');
        const feedbackClassificationForm = document.getElementById('feedback-classification-form');
        const feedbackClassificationResultArea = document.getElementById('feedback-classification-result');

        const advancedOptionsCard = document.getElementById('advanced-options-card');
        const retryDetectionOptionsWrapper = document.getElementById('retry-detection-options-wrapper');
        const retryDetectionPrompt = document.getElementById('retry-detection-prompt');
        const otherDetectorsColumn = document.getElementById('other-detectors-column');
        const otherClassifiersColumn = document.getElementById('other-classifiers-column');

        const modelPerformanceCard = document.getElementById('model-performance-card');
        const detectorMetricsTableColumn = document.getElementById('detector-metrics-table-column');
        const metricsDetectorModelName = document.getElementById('metrics-detector-model-name');
        const detectorMetricsTableContainer = document.getElementById('detector-metrics-table-container');
        const classifierMetricsTableColumn = document.getElementById('classifier-metrics-table-column');
        const metricsClassifierModelName = document.getElementById('metrics-classifier-model-name');
        const classifierMetricsTableContainer = document.getElementById('classifier-metrics-table-container');

        const classificationSkippedMessageEl = document.getElementById('classification-skipped-message');
        const classificationErrorDisplayEl = document.getElementById('classification-error-display');


        if (!resultSection) { console.error("displayFullPredictionResult: FATAL - resultSection element not found."); return; }

        resultSection.classList.remove('d-none', 'view-detected-true', 'view-detected-false', 'view-pipeline-error');
        if (pipelineErrorDisplay) pipelineErrorDisplay.style.display = 'none';
        if (mainSummaryCard) mainSummaryCard.style.display = 'none';
        if (summaryClassificationColumn) summaryClassificationColumn.style.display = 'none';
        if (summaryBboxStatusMessage) summaryBboxStatusMessage.style.display = 'none';
        if (scientificExplanationCard) scientificExplanationCard.style.display = 'none';
        if (feedbackCard) feedbackCard.style.display = 'none';
        if (detectorFeedbackWrapper) detectorFeedbackWrapper.style.display = 'none';
        if (classifierFeedbackWrapper) classifierFeedbackWrapper.style.display = 'none';
        if (advancedOptionsCard) advancedOptionsCard.style.display = 'none';
        if (retryDetectionOptionsWrapper) retryDetectionOptionsWrapper.style.display = 'none';
        if (otherDetectorsColumn) otherDetectorsColumn.style.display = 'none';
        if (otherClassifiersColumn) otherClassifiersColumn.style.display = 'none';
        if (modelPerformanceCard) modelPerformanceCard.style.display = 'none';
        if (detectorMetricsTableColumn) detectorMetricsTableColumn.style.display = 'none';
        if (classifierMetricsTableColumn) classifierMetricsTableColumn.style.display = 'none';
        if (classificationSkippedMessageEl) classificationSkippedMessageEl.style.display = 'none';
        if (classificationErrorDisplayEl) classificationErrorDisplayEl.style.display = 'none';
        if (bboxOverlayElement) bboxOverlayElement.style.display = 'none';
        if (detectorMetricsTableContainer) detectorMetricsTableContainer.innerHTML = '<p class="text-muted small fst-italic text-center">Tabel metrik detektor akan ditampilkan di sini.</p>';
        if (classifierMetricsTableContainer) classifierMetricsTableContainer.innerHTML = '<p class="text-muted small fst-italic text-center">Tabel metrik classifier akan ditampilkan di sini.</p>';
        if (metricsDetectorModelName) metricsDetectorModelName.textContent = 'N/A';
        if (metricsClassifierModelName) metricsClassifierModelName.textContent = 'N/A';

        resetOtherModelsDisplay();


        if (data.error && pipelineErrorDisplay) { // Jika ada error global dari backend (bukan error prediksi spesifik)
            pipelineErrorDisplay.textContent = data.error; // data.error dari initializeResultArray jika ada error PHP
            pipelineErrorDisplay.style.display = 'block';
            resultSection.classList.remove('d-none'); // Tampilkan result section untuk error message
            resultSection.classList.add('view-pipeline-error'); // Tambah kelas spesifik untuk styling error
            return; // Hentikan proses display lebih lanjut
        }

        if (runAllDetectorsButton) {
            if (currentResultData && currentResultData.filename) {
                runAllDetectorsButton.disabled = false;
                runAllDetectorsButton.title = "Jalankan semua model detektor untuk perbandingan.";
            } else {
                runAllDetectorsButton.disabled = true;
                runAllDetectorsButton.title = "Unggah gambar terlebih dahulu.";
            }
        }

        // --- PERBAIKAN POIN 1: Pengecekan Flag sebelum render feedback ---
        const mustDisableFeedback = feedbackGivenForCurrentImage || (data.context && data.context.feedback_exists);
        if (mustDisableFeedback) {
            feedbackGivenForCurrentImage = true; // Pastikan flag set jika data dari backend bilang ada
        }
        // --- AKHIR PERBAIKAN ---

        // Jika tidak ada error global, lanjutkan menampilkan data (atau pesan error spesifik prediksi)
        resultSection.classList.remove('d-none');

        if (resultFilenameElement) resultFilenameElement.textContent = data.filename || 'N/A';
        [uploadedImageElement, detectionImageDisplay].forEach((imgEl, index) => {
            const placeholderEl = index === 0 ? uploadedImagePlaceholder : detectionImagePlaceholder;
            if (imgEl && placeholderEl) {
                if (data.image_base64_data) {
                    imgEl.src = data.image_base64_data; imgEl.style.display = 'block'; placeholderEl.style.display = 'none';
                    imgEl.onerror = function () { if (this.parentElement) { this.style.display = 'none'; placeholderEl.innerHTML = `<p class="text-center text-muted p-3">Gambar (${index === 0 ? 'ori' : 'deteksi'}) tidak dapat dimuat.</p>`; placeholderEl.style.display = 'block'; } };
                } else { imgEl.style.display = 'none'; placeholderEl.textContent = 'Data gambar tidak tersedia.'; placeholderEl.style.display = 'block'; }
            }
        });

        console.log("Data received by displayFullPredictionResult:", JSON.parse(JSON.stringify(data)));
        const detResult = data.default_detection;
        const detectionSuccessful = detResult && detResult.success;
        const isMelon = detectionSuccessful && detResult.detected;
        const bboxSuccessful = isMelon && data.bbox_estimated_successfully && data.bbox;
        console.log({ detectionSuccessful, isMelon, bboxSuccessful });
        const clsResult = data.default_classification;
        const classificationDone = clsResult && clsResult.success;
        const showFullClassificationUI = bboxSuccessful && classificationDone;

        if (mainSummaryCard) mainSummaryCard.style.display = 'block';
        if (detResult && summaryDetectorModelName && summaryDetectorBadge && summaryDetectorConfidenceBar && summaryDetectorConfidenceText) {
            const detectorModelKey = detResult.model_key || 'N/A_det';
            const detectorModelName = modelKeysForView[detectorModelKey] || detectorModelKey.replace(/_detector|_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            summaryDetectorModelName.textContent = detectorModelName;
            if (detectionSuccessful) {
                summaryDetectorBadge.textContent = detResult.detected ? 'Melon' : 'Non-Melon';
                summaryDetectorBadge.className = `badge ${detResult.detected ? 'bg-success' : 'bg-danger'}`;
            } else {
                summaryDetectorBadge.textContent = 'Gagal'; summaryDetectorBadge.className = 'badge bg-secondary';
            }
            const detConfidencePercent = (detResult.probabilities?.melon ?? 0) * 100;
            summaryDetectorConfidenceBar.style.width = `${detConfidencePercent}%`;
            summaryDetectorConfidenceBar.classList.remove('is-melon', 'is-non-melon', 'bg-success', 'bg-danger', 'bg-secondary');
            if (detectionSuccessful) {
                summaryDetectorConfidenceBar.classList.toggle('is-melon', detResult.detected);
                summaryDetectorConfidenceBar.classList.toggle('is-non-melon', !detResult.detected);
                summaryDetectorConfidenceBar.classList.add(detResult.detected ? 'bg-success' : 'bg-danger');
            } else {
                summaryDetectorConfidenceBar.classList.add('bg-secondary');
            }
            summaryDetectorConfidenceText.textContent = `${detConfidencePercent.toFixed(1)}%`;
        }
        if (isMelon && !bboxSuccessful && summaryBboxStatusMessage) { summaryBboxStatusMessage.style.display = 'block'; }

        if (showFullClassificationUI && clsResult && summaryClassificationColumn && summaryClassifierModelName && summaryClassifierBadge && summaryClassifierConfidenceBar && summaryClassifierConfidenceText) {
            summaryClassificationColumn.style.display = 'block';
            const classifierModelKey = clsResult.model_key || 'N/A_cls';
            const classifierModelName = modelKeysForView[classifierModelKey] || classifierModelKey.replace(/_classifier|_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
            summaryClassifierModelName.textContent = classifierModelName;
            const prediction = clsResult.prediction;
            summaryClassifierBadge.textContent = prediction ? ucfirst(prediction) : 'N/A';
            summaryClassifierBadge.className = `badge ${prediction === 'matang' ? 'bg-success' : (prediction === 'belum_matang' ? 'bg-warning text-dark' : 'bg-secondary')}`;
            const confidenceProb = clsResult.probabilities ? (clsResult.probabilities[prediction] || clsResult.probabilities[prediction === 'matang' ? 'ripe' : 'unripe'] || 0) : 0;
            const clsConfidencePercent = confidenceProb * 100;
            summaryClassifierConfidenceBar.style.width = `${clsConfidencePercent}%`;
            summaryClassifierConfidenceBar.classList.remove('is-matang', 'is-belum-matang', 'bg-success', 'bg-warning', 'bg-secondary');
            summaryClassifierConfidenceBar.classList.toggle('is-matang', prediction === 'matang');
            summaryClassifierConfidenceBar.classList.toggle('is-belum-matang', prediction === 'belum_matang');
            if (prediction === 'matang') summaryClassifierConfidenceBar.classList.add('bg-success');
            else if (prediction === 'belum_matang') summaryClassifierConfidenceBar.classList.add('bg-warning');
            else summaryClassifierConfidenceBar.classList.add('bg-secondary');
            summaryClassifierConfidenceText.textContent = `${clsConfidencePercent.toFixed(1)}%`;
        }

        if (bboxSuccessful && bboxOverlayElement) {
            const b = data.bbox; // data.bbox adalah {cx, cy, w, h} relatif
            Object.assign(bboxOverlayElement.style, {
                left: `${(b.cx - b.w / 2) * 100}%`,
                top: `${(b.cy - b.h / 2) * 100}%`,
                width: `${b.w * 100}%`,
                height: `${b.h * 100}%`,
                display: 'block'
            });
        }


        if (bboxSuccessful) { // Ini berarti deteksi melon sukses DAN bbox sukses
            resultSection.classList.add('view-detected-true');
            if (scientificExplanationCard && scientificExplanationContent && showFullClassificationUI && clsResult && clsResult.scientific_explanation) {
                scientificExplanationCard.style.display = 'block';
                scientificExplanationContent.innerHTML = clsResult.scientific_explanation;
            }

            const shouldShowFeedbackCard = (data.default_detection && data.default_detection.success) || mustDisableFeedback;

            if (feedbackCard && shouldShowFeedbackCard) {
                feedbackCard.style.display = 'block';

                // Selalu tampilkan kedua wrapper, tapi kontrol tombol di dalamnya
                if (detectorFeedbackWrapper) detectorFeedbackWrapper.style.display = 'block';
                if (classifierFeedbackWrapper) classifierFeedbackWrapper.style.display = 'block';

                if (mustDisableFeedback) {
                    // Jika feedback SUDAH diberikan, nonaktifkan SEMUA
                    if (feedbackDetectionForm) feedbackDetectionForm.querySelectorAll('button').forEach(btn => btn.disabled = true);
                    if (feedbackClassificationForm) feedbackClassificationForm.querySelectorAll('button').forEach(btn => btn.disabled = true);
                    if (feedbackDetectionResultArea) feedbackDetectionResultArea.innerHTML = '<div class="alert alert-info small py-1 px-2 mb-0">Feedback sudah diberikan untuk gambar ini.</div>';
                    if (feedbackClassificationResultArea) feedbackClassificationResultArea.innerHTML = '<div class="alert alert-info small py-1 px-2 mb-0">Feedback sudah diberikan untuk gambar ini.</div>';

                    // Set tombol konfirmasi ke 'Tersimpan' jika memang sudah disimpan
                    // --- PERBAIKAN SINTAKS DI SINI ---
                    const detConfirmBtn = feedbackDetectionForm?.querySelector('#confirm-detection-feedback-btn');
                    if (detConfirmBtn) {
                        detConfirmBtn.innerHTML = '<i class="fas fa-check-double"></i> Tersimpan';
                    }
                    const clsConfirmBtn = feedbackClassificationForm?.querySelector('#confirm-classification-feedback-btn');
                    if (clsConfirmBtn) {
                        clsConfirmBtn.innerHTML = '<i class="fas fa-check-double"></i> Tersimpan';
                    }
                    // --- AKHIR PERBAIKAN SINTAKS ---
                } else {
                    // Jika feedback BELUM diberikan, atur berdasarkan logika prediksi
                    const detResult = data.default_detection;
                    const isMelon = detResult && detResult.success && detResult.detected;
                    const bboxSuccessful = isMelon && data.bbox_estimated_successfully && data.bbox;
                    const clsResult = data.default_classification;
                    const classificationDone = clsResult && clsResult.success;
                    const showFullClassificationUI = bboxSuccessful && classificationDone;

                    // Atur Feedback Deteksi
                    if (feedbackDetectionForm) {
                        feedbackDetectionForm.querySelectorAll('button').forEach(btn => btn.disabled = false);
                        feedbackDetectionForm.querySelector('#confirm-detection-feedback-btn').disabled = true; // Konfirmasi nonaktif dulu
                        const detectedObjectName = detResult.detected ? "MELON" : "BUKAN MELON";
                        feedbackDetectionPromptText.innerHTML = `Model mendeteksi ini sebagai <strong class="${detResult.detected ? 'text-success' : 'text-danger'}">${detectedObjectName}</strong>. Sebenarnya apakah ini?`;
                        if (feedbackDetectionResultArea) feedbackDetectionResultArea.innerHTML = '';
                    }

                    // Atur Feedback Klasifikasi
                    if (feedbackClassificationForm) {
                        // Nonaktifkan jika BBox tidak ada ATAU deteksi adalah non-melon (dan belum ada klasifikasi)
                        if (!bboxSuccessful || !isMelon) {
                            classifierFeedbackWrapper.style.display = 'none'; // Sembunyikan jika tidak relevan
                            feedbackClassificationForm.querySelectorAll('button').forEach(btn => btn.disabled = true);
                        } else if (showFullClassificationUI) {
                            classifierFeedbackWrapper.style.display = 'block'; // Tampilkan jika relevan
                            feedbackClassificationForm.querySelectorAll('button').forEach(btn => btn.disabled = false);
                            feedbackClassificationForm.querySelector('#confirm-classification-feedback-btn').disabled = true; // Konfirmasi nonaktif dulu
                            feedbackClassificationPredictionText.textContent = ucfirst(clsResult.prediction);

                            if (clsResult.prediction === 'matang') feedbackClassificationPredictionText.classList.add('text-success', 'fw-bold');
                            else if (clsResult.prediction === 'belum_matang') feedbackClassificationPredictionText.classList.add('text-warning', 'fw-bold');
                            else feedbackClassificationPredictionText.classList.add('text-secondary', 'fw-bold');

                            if (feedbackClassificationResultArea) feedbackClassificationResultArea.innerHTML = '';
                        } else {
                            classifierFeedbackWrapper.style.display = 'none'; // Sembunyikan jika tidak relevan
                            feedbackClassificationForm.querySelectorAll('button').forEach(btn => btn.disabled = true);
                        }
                    }
                }
            } else if (feedbackCard) {
                feedbackCard.style.display = 'none'; // Sembunyikan jika deteksi awal gagal & belum ada feedback
            }

            if (advancedOptionsCard) {
                advancedOptionsCard.style.display = 'block';
                if (retryDetectionOptionsWrapper) retryDetectionOptionsWrapper.style.display = 'none';
                if (otherDetectorsColumn) otherDetectorsColumn.style.display = 'block';
                if (otherClassifiersColumn) otherClassifiersColumn.style.display = showFullClassificationUI ? 'block' : 'none';
            }
            if (modelPerformanceCard) {
                modelPerformanceCard.style.display = 'block';
                if (detectorMetricsTableColumn && metricsDetectorModelName && detectorMetricsTableContainer && detResult && detResult.metrics) {
                    detectorMetricsTableColumn.style.display = 'block';
                    updateMetricsDisplay(detectorMetricsTableContainer, metricsDetectorModelName, detResult.metrics, true, modelKeysForView[detResult.model_key] || detResult.model_key);
                } else if (detectorMetricsTableColumn) { detectorMetricsTableColumn.style.display = 'block'; }

                if (classifierMetricsTableColumn && metricsClassifierModelName && classifierMetricsTableContainer) {
                    if (showFullClassificationUI && clsResult && clsResult.metrics) {
                        classifierMetricsTableColumn.style.display = 'block';
                        updateMetricsDisplay(classifierMetricsTableContainer, metricsClassifierModelName, clsResult.metrics, false, modelKeysForView[clsResult.model_key] || clsResult.model_key);
                    } else { classifierMetricsTableColumn.style.display = 'none'; }
                }
            }
            if (classificationSkippedMessageEl) classificationSkippedMessageEl.style.display = 'none';
            if (showFullClassificationUI && clsResult && !clsResult.success && clsResult.error) {
                showNotification(`Klasifikasi: ${clsResult.error}`, 'warning');
            }

        } else {
            resultSection.classList.add('view-detected-false');
            if (scientificExplanationCard) scientificExplanationCard.style.display = 'none';

            if (feedbackCard) {
                feedbackCard.style.display = detectionSuccessful ? 'block' : 'none';
                if (detectorFeedbackWrapper && feedbackDetectionPromptText && feedbackDetectionForm) {
                    detectorFeedbackWrapper.style.display = 'block';
                    let feedbackMsgDet = '';
                    if (isMelon && !bboxSuccessful) {
                        feedbackMsgDet = `Model mendeteksi ini sebagai <strong class="text-success">MELON</strong>, namun estimasi BBox otomatis gagal. Apakah ini benar melon?`;
                    } else if (detectionSuccessful && !isMelon) {
                        feedbackMsgDet = `Model mendeteksi ini sebagai <strong class="text-danger">BUKAN MELON</strong>. Apakah ini sebenarnya melon?`;
                    } else {
                        feedbackMsgDet = `Proses deteksi awal gagal. Apakah ini sebenarnya melon?`;
                        feedbackCard.style.display = 'block';
                    }
                    feedbackDetectionPromptText.innerHTML = feedbackMsgDet;
                    if (feedbackDetectionResultArea) feedbackDetectionResultArea.innerHTML = '';
                    if (feedbackDetectionForm) feedbackDetectionForm.querySelectorAll('button').forEach(btn => btn.disabled = false);
                } else if (detectorFeedbackWrapper) { detectorFeedbackWrapper.style.display = 'none'; }
                if (classifierFeedbackWrapper) classifierFeedbackWrapper.style.display = 'none';
            }


            if (advancedOptionsCard) {
                advancedOptionsCard.style.display = 'block';
                if (retryDetectionOptionsWrapper && retryDetectionPrompt) {
                    retryDetectionOptionsWrapper.style.display = 'block';
                    if (isMelon && !bboxSuccessful) {
                        retryDetectionPrompt.textContent = "Estimasi BBox otomatis gagal. Klik model di 'Model Deteksi Lain' untuk mencoba lagi dengan model lain.";
                    } else if (detectionSuccessful && !isMelon) {
                        retryDetectionPrompt.textContent = "Model utama tidak mendeteksi melon. Klik model di 'Model Deteksi Lain' untuk mencoba dengan model lain.";
                    } else {
                        retryDetectionPrompt.textContent = "Proses deteksi awal gagal. Klik model di 'Model Deteksi Lain' untuk mencoba dengan model lain.";
                    }
                }
                if (otherDetectorsColumn) otherDetectorsColumn.style.display = 'block';
                if (otherClassifiersColumn) otherClassifiersColumn.style.display = 'none';
            }


            if (modelPerformanceCard) {
                modelPerformanceCard.style.display = detectionSuccessful ? 'block' : 'none';
                if (detectorMetricsTableColumn && metricsDetectorModelName && detectorMetricsTableContainer && detResult && detResult.metrics) {
                    detectorMetricsTableColumn.style.display = 'block';
                    updateMetricsDisplay(detectorMetricsTableContainer, metricsDetectorModelName, detResult.metrics, true, modelKeysForView[detResult.model_key] || detResult.model_key);
                } else if (detectorMetricsTableColumn && detectionSuccessful) {
                    detectorMetricsTableColumn.style.display = 'block';
                    if (metricsDetectorModelName) metricsDetectorModelName.textContent = detResult ? (modelKeysForView[detResult.model_key] || detResult.model_key) : 'N/A';
                    if (detectorMetricsTableContainer) detectorMetricsTableContainer.innerHTML = '<p class="text-muted small fst-italic text-center">Metrik detektor tidak tersedia.</p>';
                } else if (detectorMetricsTableColumn) { detectorMetricsTableColumn.style.display = 'none'; }
                if (classifierMetricsTableColumn) classifierMetricsTableColumn.style.display = 'none';
            }

            let skipReasonMsg = '';
            if (isMelon && !bboxSuccessful) skipReasonMsg = "Klasifikasi dilewati: Estimasi BBox otomatis gagal.";
            else if (detectionSuccessful && !isMelon) skipReasonMsg = "Klasifikasi dilewati: Melon tidak terdeteksi oleh model utama.";
            else if (!detectionSuccessful && detResult && !detResult.success) skipReasonMsg = `Klasifikasi dilewati: Proses deteksi awal gagal (${detResult.error || 'kesalahan tidak diketahui'}).`;
            else if (!detectionSuccessful) skipReasonMsg = "Klasifikasi dilewati: Proses deteksi awal gagal.";

            if (skipReasonMsg && classificationSkippedMessageEl) {
                showNotification(skipReasonMsg, 'info');
            }
            if (classificationErrorDisplayEl) classificationErrorDisplayEl.style.display = 'none';
        }
    }

    // === Event Listener Utama ===
    if (uploadForm) {
        uploadForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (!imageInput || imageInput.files.length === 0) {
                showNotification('Pilih file gambar.', 'warning', notificationAreaMain, 3000);
                return;
            }

            // --- PANGGIL FUNGSI RESET DI SINI ---
            resetUIForNewUpload();
            resetFeedbackForms();
            // --- AKHIR PANGGILAN FUNGSI RESET ---

            resetOtherModelsDisplay();
            showOverlay('Mengunggah gambar...');

            const formDataForUpload = new FormData();
            formDataForUpload.append('imageFile', imageInput.files[0]);

            try {
                const uploadResponse = await fetch(window.uploadImageForPredictUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: formDataForUpload
                });
                if (!uploadResponse.ok) {
                    let errorMsg = `Gagal unggah gambar: ${uploadResponse.status} ${uploadResponse.statusText}`;
                    try { const errData = await uploadResponse.json(); errorMsg = errData.message || errorMsg; } catch (jsonErr) { /* ignore */ }
                    throw new Error(errorMsg);
                }
                const uploadData = await uploadResponse.json();
                if (!uploadData.success || !uploadData.s3_path || !uploadData.filename) {
                    throw new Error(uploadData.message || 'Respons server dari unggah gambar tidak valid.');
                }

                showNotification('Gambar berhasil diunggah, memulai analisis...', 'info', notificationAreaMain, 2000);
                showOverlay('Menganalisis gambar...');
                const feedbackExistsFromServer = uploadData.feedback_exists || false;

                const predictPayload = { filename: uploadData.filename, s3_path: uploadData.s3_path };
                const predictResponse = await fetch(window.predictDefaultUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(predictPayload)
                });
                if (!predictResponse.ok) {
                    let errorMsg = `Gagal prediksi: ${predictResponse.status} ${predictResponse.statusText}`;
                    try { const errData = await predictResponse.json(); errorMsg = errData.message || (errData.errors ? Object.values(errData.errors).flat().join(' ') : errorMsg); } catch (jsonErr) { /* ignore */ }
                    throw new Error(errorMsg);
                }
                const predictData = await predictResponse.json();
                if (!predictData.success && predictData.message) { // Jika backend mengirim success:false dengan pesan
                    throw new Error(predictData.message);
                } else if (!predictData.success) { // Fallback jika tidak ada pesan
                    throw new Error('Prediksi gagal dari server tanpa pesan spesifik.');
                }


                if (predictData) {
                    currentResultData = predictData;
                    // Simpan data tambahan SEBELUM memanggil display
                    currentResultData.original_filename_from_upload = uploadData.filename;
                    currentResultData.temp_server_filename_from_upload = uploadData.tempServerFilename;
                    currentResultData.s3_path_from_upload = uploadData.s3_path;
                    if (!currentResultData.context) currentResultData.context = {};
                    currentResultData.context.feedback_exists = feedbackExistsFromServer; // <-- Simpan di sini

                    if (predictData.default_detection) {
                        initialDefaultDetectionResultForButtonLogic = JSON.parse(JSON.stringify(predictData.default_detection));
                    } else {
                        initialDefaultDetectionResultForButtonLogic = { success: false, detected: false };
                    }

                    initialDefaultDetectionResultForButtonLogic = predictData.default_detection ? JSON.parse(JSON.stringify(predictData.default_detection)) : { success: false, detected: false };

                    // --- PERBAIKAN POIN 5 ---
                    // Panggil displayFullPredictionResult HANYA SEKALI di sini
                    displayFullPredictionResult(currentResultData, true);
                    // --- AKHIR PERBAIKAN POIN 5 ---

                    if (!currentResultData.error) {
                        showNotification('Analisis berhasil!', 'success', notificationAreaMain, 3000);
                    }

                } else {
                    throw new Error('Format respons prediksi tidak dikenali.');
                }

            } catch (error) {
                console.error('Error pada alur unggah & prediksi:', error);
                let errMsg = error.message || 'Gagal memproses gambar.';
                if (errMsg && typeof errMsg === 'string' && errMsg.toLowerCase().includes("unexpected token '<'")) {
                    errMsg = 'Gagal proses: Respons server tidak valid (bukan JSON). Cek log server.';
                }
                showNotification(errMsg, 'danger', notificationAreaMain, 8000);
                const resultSectionForError = document.getElementById('result-section');
                const pipelineErrorDisplayForError = document.getElementById('pipeline-error-display');
                if (resultSectionForError) resultSectionForError.classList.remove('d-none');
                if (pipelineErrorDisplayForError) { pipelineErrorDisplayForError.textContent = errMsg; pipelineErrorDisplayForError.style.display = 'block'; }
                const elementsToHideOnError = [
                    document.getElementById('main-summary-card'), document.getElementById('scientific-explanation-card'),
                    document.getElementById('feedback-card'), document.getElementById('advanced-options-card'),
                    document.getElementById('model-performance-card')
                ];
                elementsToHideOnError.forEach(el => { if (el) el.style.display = 'none'; });
                const uploadedImageElement = document.getElementById('uploaded-image');
                const uploadedImagePlaceholder = document.getElementById('uploaded-image-placeholder');
                const detectionImageDisplay = document.getElementById('detection-image-display');
                const detectionImagePlaceholder = document.getElementById('detection-image-placeholder');
                if (uploadedImageElement) uploadedImageElement.style.display = 'none';
                if (uploadedImagePlaceholder) { uploadedImagePlaceholder.textContent = 'Gagal memuat gambar.'; uploadedImagePlaceholder.style.display = 'block'; }
                if (detectionImageDisplay) detectionImageDisplay.style.display = 'none';
                if (detectionImagePlaceholder) { detectionImagePlaceholder.textContent = 'Gagal memuat hasil deteksi.'; detectionImagePlaceholder.style.display = 'block'; }
            } finally {
                hideOverlay();
                if (imageInput) imageInput.value = '';
            }
        });
    }

    async function handleRunOtherModels(taskType, runAll = false, targetModelKey = null) { // Tambah targetModelKey
        const listContainer = taskType === 'detector' ? detectorsResultsList : classifiersResultsList;
        const loadingSpinner = taskType === 'detector' ? detectorsLoadingSpinner : classifiersLoadingSpinner;
        const mjVoteDiv = taskType === 'detector' ? majorityVoteDetectorResultDiv : majorityVoteResultDiv;
        const mjVoteBadge = taskType === 'detector' ? majorityVoteDetectorBadge : majorityVoteBadge;
        const otherModelTpl = otherModelResultTemplate;

        if (!listContainer || !loadingSpinner) { console.error(`UI elements for other ${taskType}s not found.`); return; }

        let canProceed = false;
        let s3PathForProcessing = currentResultData?.s3_path_processed || currentResultData?.s3_path_from_upload;

        if (taskType === 'detector' && currentResultData?.context?.detectionFeatures && s3PathForProcessing) {
            canProceed = true;
            // --- AWAL BAGIAN YANG DIUBAH ---
        } else if (taskType === 'classifier' && s3PathForProcessing && currentResultData?.context?.bboxEstimated && currentResultData?.context?.bbox_from_python_rel && currentResultData.context?.colorFeatures) { // MODIFIKASI DI SINI
            canProceed = true;
        } else if (taskType === 'classifier' && s3PathForProcessing && currentResultData?.context?.bboxEstimated && currentResultData?.context?.bbox_from_python_rel && !currentResultData.context?.colorFeatures) { // MODIFIKASI DI SINI
            console.warn("Fitur warna (currentResultData.context.colorFeatures) tidak ditemukan untuk klasifikasi model lain, meskipun BBox ada.");
            canProceed = false; // Ini akan memicu pesan error jika fitur warna tidak ada
        }
        // --- AKHIR BAGIAN YANG DIUBAH ---

        if (!currentResultData || !currentResultData.filename || !currentResultData.context || !canProceed || !s3PathForProcessing) {
            let message = `Data prediksi awal atau fitur yang dibutuhkan tidak lengkap untuk menjalankan model ${taskType} lain.`;
            if (taskType === 'classifier' && !(currentResultData?.context?.bboxEstimated && currentResultData?.context?.bbox_from_python_rel)) {
                message = "Estimasi BBox harus berhasil terlebih dahulu sebelum menjalankan model klasifikasi lain.";
                // --- AWAL BAGIAN YANG DIUBAH ---
            } else if (taskType === 'classifier' && !(currentResultData.context?.colorFeatures)) { // MODIFIKASI DI SINI
                message = "Fitur warna dari BBox utama (currentResultData.context.colorFeatures) tidak tersedia untuk menjalankan model klasifikasi lain.";
                // --- AKHIR BAGIAN YANG DIUBAH ---
            } else if (!s3PathForProcessing) {
                message = "Path S3 gambar yang diproses tidak tersedia.";
            }
            showNotification(message, 'warning'); return;
        }


        loadingSpinner.classList.remove('d-none');
        listContainer.innerHTML = `<li class="list-group-item small text-muted fst-italic">Memuat...</li>`;
        if (mjVoteDiv) mjVoteDiv.style.display = 'none';
        document.querySelectorAll('.run-other-model[data-run="all"]').forEach(btn => btn.disabled = true);

        const payload = {
            filename: currentResultData.filename,
            context: {
                uploaded_s3_path: s3PathForProcessing,
                detectionFeatures: currentResultData.context_features_extracted?.detection ? currentResultData.context?.detectionFeatures : null,
                colorFeaturesFromContext: currentResultData.context_features_extracted?.classification ? currentResultData.context?.colorFeatures : null,
                bbox_estimated_successfully: currentResultData.bbox_estimated_successfully || false,
                bbox_from_python_rel: currentResultData.bbox || null
            },
            // !!! TAMBAHKAN HASIL PREDIKSI DEFAULT KE PAYLOAD !!!
            default_detection_result: currentResultData.default_detection || null,
            default_classification_result: currentResultData.default_classification || null,
            // !!! AKHIR PENAMBAHAN !!!
            run_all_detectors: taskType === 'detector' && runAll,
            run_all_classifiers: taskType === 'classifier' && runAll
        };

        if (!runAll && targetModelKey) {
            if (taskType === 'detector') payload.target_detector = targetModelKey;
            else if (taskType === 'classifier') payload.target_classifier = targetModelKey;
        }

        try {
            const response = await fetch(allResultsUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload)
            });
            const data = await response.json();
            if (!response.ok || !data.success) { throw new Error(data.message || `Gagal ambil hasil model ${taskType}.`); }

            if (data.updated_context_for_frontend && data.updated_context_for_frontend.colorFeatures) {
                currentResultData.context.colorFeaturesFromContext = data.updated_context_for_frontend.colorFeatures;
                currentResultData.context_features_extracted.classification = true;
            }
            if (data.updated_context_for_frontend && data.updated_context_for_frontend.bbox_rel) {
                currentResultData.bbox = data.updated_context_for_frontend.bbox_rel;
                currentResultData.bbox_estimated_successfully = true;
                currentResultData.context.bbox_from_python_rel = data.updated_context_for_frontend.bbox_rel;
                currentResultData.context.bboxEstimated = true;
            }


            const resultsToDisplay = (taskType === 'detector') ? data.detectors : data.classifiers;
            listContainer.innerHTML = '';
            if (resultsToDisplay && typeof resultsToDisplay === 'object' && Object.keys(resultsToDisplay).length > 0) {
                Object.entries(resultsToDisplay).forEach(([modelKey, resultItem]) => {
                    if (resultItem && typeof resultItem === 'object') {
                        resultItem.model_key = modelKey;
                        renderSingleOtherModelResult(resultItem, listContainer, taskType, otherModelTpl);
                    }
                });

                // --- UPDATED MAJORITY VOTE DISPLAY ---
                // mjVoteDiv adalah #detector-majority-vote-container atau #majority-vote-result
                // mjVoteBadge adalah #detector-majority-vote-text-badge atau #majority-vote-badge
                if (runAll && mjVoteDiv && mjVoteBadge) {
                    const voteResult = (taskType === 'detector') ? data.detector_majority_vote : data.majority_vote;

                    if (voteResult && typeof voteResult === 'object' && voteResult.predicted_class && voteResult.predicted_class !== 'N/A') {
                        const winningClass = voteResult.predicted_class;
                        const confidence = voteResult.confidence;
                        const details = voteResult.details || {}; // Pastikan details ada

                        let displayText = ucfirst(winningClass.replace(/_/g, ' '));
                        let badgeClass = 'bg-secondary';
                        let explanationText = '';

                        if (taskType === 'detector') {
                            if (winningClass === 'melon') badgeClass = 'bg-success';
                            else if (winningClass === 'non_melon') badgeClass = 'bg-danger';
                            explanationText = `Total Akumulasi P(Melon): ${details.accumulated_melon_prob?.toFixed(3) || 'N/A'}, P(Non-Melon): ${details.accumulated_non_melon_prob?.toFixed(3) || 'N/A'}`;
                        } else { // classifier
                            if (winningClass === 'matang') badgeClass = 'bg-success';
                            else if (winningClass === 'belum_matang') badgeClass = 'bg-warning text-dark';
                            explanationText = `Total Akumulasi P(Matang): ${details.accumulated_matang_prob?.toFixed(3) || 'N/A'}, P(Belum Matang): ${details.accumulated_belum_matang_prob?.toFixed(3) || 'N/A'}`;
                        }

                        mjVoteBadge.innerHTML = `
                        <span class="badge fs-6 ${badgeClass}">${displayText}</span>
                        <div class="mt-1">
                            <small class="text-muted" style="font-size: 0.8em;">
                                Keyakinan Akhir: <strong class="text-primary">${(confidence * 100).toFixed(1)}%</strong><br>
                                (${explanationText} dari ${details.valid_model_count || 0} model valid)
                            </small>
                        </div>`;
                        mjVoteDiv.style.display = 'block';
                    } else if (voteResult && voteResult.predicted_class === 'N/A' && voteResult.details && voteResult.details.message) {
                        mjVoteBadge.innerHTML = `<span class="badge fs-6 bg-secondary">N/A</span> <div class="mt-1"><small class="text-muted" style="font-size: 0.8em;">${voteResult.details.message}</small></div>`;
                        mjVoteDiv.style.display = 'block';
                    }
                    else { // Jika voteResult null atau tidak ada predicted_class
                        mjVoteBadge.innerHTML = `<span class="badge fs-6 bg-secondary">N/A</span> <div class="mt-1"><small class="text-muted" style="font-size: 0.8em;">Gagal menghitung mayoritas atau tidak ada data.</small></div>`;
                        mjVoteDiv.style.display = 'block';
                    }
                }
            } else {
                listContainer.innerHTML = `<li class="list-group-item small text-muted fst-italic">Tidak ada hasil ${taskType} lain yang tersedia.</li>`;
            }
        } catch (error) {
            console.error(`Error fetching/rendering other ${taskType} models:`, error);
            listContainer.innerHTML = `<li class="list-group-item text-danger small">Gagal memuat hasil: ${error.message}</li>`;
            showNotification(`Gagal memuat hasil model ${taskType} lain: ${error.message}`, 'danger');
        }
        finally {
            loadingSpinner.classList.add('d-none');
            document.querySelectorAll('.run-other-model[data-run="all"]').forEach(btn => btn.disabled = false);
        }
    }

    // 1. Modifikasi fungsi renderSingleOtherModelResult
    function renderSingleOtherModelResult(res, container, taskType, templateElement) {
        if (!templateElement || !templateElement.content) {
            console.error("Template #other-model-result-template tidak valid.");
            return;
        }
        const node = templateElement.content.cloneNode(true);
        const li = node.querySelector('li.other-model-list-item');
        if (!li) {
            console.error("Template <li> item tidak valid.");
            return;
        }

        li.dataset.modelKey = res.model_key || '';
        li.dataset.taskType = taskType;
        if (taskType === 'detector') {
            li.dataset.detected = res.detected ? 'true' : 'false';
            li.dataset.detectorResultItem = JSON.stringify(res); // Menyimpan seluruh hasil
        }

        if (taskType === 'detector' && res.detected) {
            li.classList.add('clickable-model-item-detector-positive');
            li.title = "Model ini mendeteksi MELON. Klik untuk info lebih lanjut jika deteksi default awal non-melon.";
        } else if (taskType === 'detector') {
            li.classList.add('clickable-model-item-detector-negative');
            li.title = `Model ini mendeteksi: ${res.detected === false ? 'Non-Melon' : 'Gagal/N/A'}`;
        }

        const nameEl = li.querySelector('.model-name');
        const predEl = li.querySelector('.model-prediction');
        const badgeEl = predEl?.querySelector('.badge');
        const errEl = li.querySelector('.model-error');
        const metricsEl = li.querySelector('.model-metrics');
        const accEl = metricsEl?.querySelector('.accuracy');
        const f1PosEl = metricsEl?.querySelector('.f1-pos');
        const confVisEl = li.querySelector('.confidence-visualization');
        const confBar = li.querySelector('.model-confidence-bar');
        const confText = li.querySelector('.model-confidence-text');

        if (nameEl) nameEl.textContent = modelKeysForView[res.model_key] || res.model_key.replace(/_detector|_classifier|_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) || 'Unknown Model';

        if (res.error) {
            if (predEl) predEl.style.display = 'none';
            if (metricsEl) metricsEl.style.display = 'none';
            if (errEl) { errEl.textContent = `Error: ${res.error}`; errEl.style.display = 'block'; }
            if (confVisEl) confVisEl.style.display = 'none';
        } else {
            if (errEl) errEl.style.display = 'none';
            if (predEl) predEl.style.display = 'flex';
            if (confVisEl) confVisEl.style.display = 'flex';

            let txt, clsName, pct;
            if (taskType === 'detector') {
                const isDetected = res.detected;
                txt = isDetected ? 'Melon' : 'Non-Melon';
                clsName = isDetected ? 'bg-success' : 'bg-danger';
                pct = (res.probabilities?.melon ?? 0);
            } else { // classifier
                const prediction = res.prediction;
                txt = prediction ? ucfirst(prediction) : 'N/A';
                clsName = prediction === 'matang' ? 'bg-success' : (prediction === 'belum_matang' ? 'bg-warning text-dark' : 'bg-secondary');
                const probKey = prediction === 'matang' ? 'matang' : (prediction === 'belum_matang' ? 'belum_matang' : null);
                pct = (res.probabilities && probKey) ? (res.probabilities[probKey] || 0) : 0;
                if (pct === 0 && res.probabilities) { // Fallback jika label di probabilitas masih 'ripe'/'unripe'
                    const rawProbKey = prediction === 'matang' ? 'ripe' : (prediction === 'belum_matang' ? 'unripe' : null);
                    if (rawProbKey) pct = res.probabilities[rawProbKey] || 0;
                }
            }

            const confidencePercent = pct * 100;
            if (confBar) {
                confBar.style.width = `${confidencePercent.toFixed(1)}%`;
                confBar.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-secondary');
                if (taskType === 'detector') {
                    confBar.classList.add(res.detected ? 'bg-success' : 'bg-danger');
                } else {
                    if (res.prediction === 'matang') confBar.classList.add('bg-success');
                    else if (res.prediction === 'belum_matang') confBar.classList.add('bg-warning');
                    else confBar.classList.add('bg-secondary');
                }
            }
            if (confText) confText.textContent = `${confidencePercent.toFixed(1)}%`;
            if (badgeEl) { badgeEl.textContent = txt; badgeEl.className = `badge small me-2 ${clsName}`; }

            if (res.metrics && metricsEl && accEl && f1PosEl) {
                const actualMetricsData = res.metrics.metrics; // Asumsi struktur metrik dari backend
                if (actualMetricsData && typeof actualMetricsData.accuracy !== 'undefined') {
                    const posKeyForMetrics = taskType === 'detector' ? 'melon' : 'ripe';
                    const positiveMetricsDataInActual = actualMetricsData[posKeyForMetrics] || actualMetricsData.positive || {};

                    if (accEl) accEl.textContent = formatPercent(actualMetricsData.accuracy, 1);
                    if (f1PosEl && typeof positiveMetricsDataInActual.f1_score !== 'undefined') {
                        f1PosEl.textContent = formatPercent(positiveMetricsDataInActual.f1_score, 1);
                    } else { f1PosEl.textContent = 'N/A'; }
                    metricsEl.style.display = 'block';
                } else {
                    metricsEl.style.display = 'none';
                }
            } else if (metricsEl) {
                metricsEl.style.display = 'none';
            }
        }
        container.appendChild(li);
    }

    async function processSingleDetectorRerun(targetDetectorKey) {
        const localSingleDetectorRerunSpinner = singleDetectorRerunSpinner;
        let s3PathForRerun = currentResultData?.s3_path_processed || currentResultData?.s3_path_from_upload;

        if (!currentResultData || !currentResultData.filename || !s3PathForRerun) {
            showNotification("Data awal atau path gambar utama tidak lengkap untuk menjalankan ulang deteksi.", "warning");
            console.error("Missing data for rerun:", { currentResultData, s3PathForRerun });
            return;
        }
        resetOtherModelsDisplay();

        const modelDisplayName = modelKeysForView[targetDetectorKey] || targetDetectorKey.replace(/_detector|_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        showOverlay(`Menguji ulang deteksi dengan ${modelDisplayName}...`);
        if (localSingleDetectorRerunSpinner) localSingleDetectorRerunSpinner.style.display = 'block';

        const payload = {
            filename: currentResultData.filename,
            context: {
                uploaded_s3_path: s3PathForRerun,
                detectionFeatures: currentResultData.context?.detectionFeatures,
                colorFeaturesFromContext: null,
                bbox_estimated_successfully: false,
                bbox_from_python_rel: null
            },
            target_detector: targetDetectorKey,
            run_all_detectors: false,
            run_all_classifiers: false
        };

        try {
            const response = await fetch(allResultsUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload)
            });
            const data = await response.json();

            if (!response.ok || !data.success || !data.detectors || !data.detectors[targetDetectorKey]) {
                throw new Error(data.message || `Gagal mendapatkan hasil dari ${targetDetectorKey}.`);
            }

            const newDetectionResult = data.detectors[targetDetectorKey];
            currentResultData.default_detection = newDetectionResult;
            currentResultData.bbox = newDetectionResult.bbox_rel?.[0] ?? null;
            currentResultData.bbox_estimated_successfully = newDetectionResult.bbox_estimated_successfully ?? (currentResultData.bbox !== null);
            currentResultData.context.bbox_from_python_rel = currentResultData.bbox;
            currentResultData.context.bboxEstimated = currentResultData.bbox_estimated_successfully;
            currentResultData.context.colorFeaturesFromContext = null;
            currentResultData.context_features_extracted.classification = false;


            if (data.triggered_classification_result) {
                currentResultData.default_classification = data.triggered_classification_result;
                currentResultData.classification_done = data.triggered_classification_result.success;
                currentResultData.classification_error = data.triggered_classification_result.error;
                if (data.updated_context_for_frontend && data.updated_context_for_frontend.colorFeatures) {
                    currentResultData.context.colorFeaturesFromContext = data.updated_context_for_frontend.colorFeatures;
                    currentResultData.context_features_extracted.classification = true;
                }
                showNotification(`Model ${modelDisplayName} mendeteksi MELON. BBox & Klasifikasi otomatis selesai!`, "success");
            } else {
                currentResultData.default_classification = null;
                currentResultData.classification_done = false;
                let clsErrorMsg = `Model ${modelDisplayName} `;
                if (newDetectionResult.detected) {
                    clsErrorMsg += currentResultData.bbox_estimated_successfully ?
                        "mendeteksi melon, BBox diestimasi, namun klasifikasi otomatis tidak terpicu/gagal." :
                        "mendeteksi melon, tapi estimasi BBox gagal.";
                } else {
                    clsErrorMsg += "juga tidak mendeteksi melon.";
                }
                currentResultData.classification_error = clsErrorMsg;

                if (newDetectionResult.detected && !currentResultData.bbox_estimated_successfully) {
                    showNotification(`Model ${modelDisplayName} mendeteksi melon, tapi estimasi BBox gagal. Klasifikasi dilewati.`, "warning");
                } else if (newDetectionResult.detected && currentResultData.bbox_estimated_successfully) {
                    showNotification(`Model ${modelDisplayName} mendeteksi melon dan BBox berhasil, namun klasifikasi otomatis tidak terpicu.`, "info");
                } else if (!newDetectionResult.detected) {
                    showNotification(`Model ${modelDisplayName} tidak mendeteksi melon. Klasifikasi dilewati.`, "info");
                }
            }

            displayFullPredictionResult(currentResultData);
            window.scrollTo({ top: 0, behavior: 'smooth' });

        } catch (error) {
            console.error(`Error saat menjalankan ulang dengan detektor ${targetDetectorKey}:`, error);
            showNotification(`Gagal menjalankan ulang dengan model ${targetDetectorKey}: ${error.message}`, 'danger');
            displayFullPredictionResult(currentResultData);
        } finally {
            hideOverlay();
            if (localSingleDetectorRerunSpinner) localSingleDetectorRerunSpinner.style.display = 'none';
        }
    }

    // --- BARU: Event Listener untuk Toggle Mode Input ---
    if (predictionModeToggle && uploadManualSection && receivePiSection && predictionModeLabel) {
        predictionModeToggle.addEventListener('change', function () {
            if (this.checked) { // Mode Unggah Manual (default)
                uploadManualSection.style.display = 'block';
                receivePiSection.style.display = 'none';
                predictionModeLabel.textContent = 'Mode: Unggah Manual';
            } else { // Mode Terima dari Pi
                uploadManualSection.style.display = 'none';
                receivePiSection.style.display = 'block';
                predictionModeLabel.textContent = 'Mode: Terima dari Raspberry Pi';
                if (piStatusDisplay) { // Reset status Pi jika beralih ke mode ini
                    piStatusDisplay.textContent = 'Siap menerima trigger dari Raspberry Pi.';
                    piStatusDisplay.className = 'alert alert-secondary small mt-3'; // Tambahkan margin top
                }
            }
            // Reset hasil prediksi saat mode diubah
            if (resultSection) resultSection.classList.add('d-none');
            currentResultData = {}; // Kosongkan data hasil sebelumnya
            if (imageInput) imageInput.value = ''; // Kosongkan input file jika ada
        });

        // Atur tampilan awal berdasarkan status checkbox (jika tidak `checked` secara default di HTML)
        if (predictionModeToggle.checked) {
            uploadManualSection.style.display = 'block';
            receivePiSection.style.display = 'none';
            predictionModeLabel.textContent = 'Mode: Unggah Manual';
        } else {
            uploadManualSection.style.display = 'none';
            receivePiSection.style.display = 'block';
            predictionModeLabel.textContent = 'Mode: Terima dari Raspberry Pi';
            if (piStatusDisplay) {
                piStatusDisplay.textContent = 'Siap menerima trigger dari Raspberry Pi.';
                piStatusDisplay.className = 'alert alert-secondary small mt-3';
            }
        }
    }

    // --- BARU: Event Listener untuk Tombol Trigger Raspberry Pi ---
    if (triggerPiCameraButton) {
        triggerPiCameraButton.addEventListener('click', async () => {
            // Pastikan hanya berjalan jika mode "Receive" aktif
            if (predictionModeToggle && predictionModeToggle.checked) {
                showNotification("Silakan ganti ke mode 'Terima dari Raspberry Pi' terlebih dahulu.", "warning");
                return;
            }

            if (piStatusDisplay) {
                piStatusDisplay.textContent = 'Menghubungi Raspberry Pi...';
                piStatusDisplay.className = 'alert alert-info small mt-3'; // Tambahkan margin top
            }
            showOverlay('Memicu kamera Raspberry Pi...');

            try {
                const response = await fetch(window.triggerPiCameraUrl, { // Menggunakan variabel global
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                hideOverlay();

                if (!response.ok) {
                    let errorMsg = `Gagal memicu kamera Pi: ${response.status}`;
                    try { const errData = await response.json(); errorMsg = errData.message || errorMsg; } catch (e) { /* ignore */ }
                    throw new Error(errorMsg);
                }

                const data = await response.json();

                if (data.success) {
                    if (data.filename && data.default_detection) { // Jika respons berisi hasil prediksi akhir
                        if (piStatusDisplay) {
                            piStatusDisplay.textContent = 'Prediksi dari Raspberry Pi diterima!';
                            piStatusDisplay.className = 'alert alert-success small mt-3';
                        }
                        showNotification('Prediksi dari Raspberry Pi berhasil diterima!', 'success');
                        currentResultData = data;
                        displayFullPredictionResult(data);
                    } else { // Jika respons hanya konfirmasi trigger
                        if (piStatusDisplay) {
                            piStatusDisplay.textContent = data.message || 'Trigger ke Pi berhasil. Menunggu gambar dan prediksi...';
                            piStatusDisplay.className = 'alert alert-info small mt-3';
                        }
                        showNotification(data.message || 'Trigger ke Raspberry Pi berhasil. Gambar sedang diproses.', 'info');
                    }
                } else {
                    throw new Error(data.message || 'Raspberry Pi melaporkan kegagalan.');
                }

            } catch (error) {
                hideOverlay();
                console.error('Error memicu kamera Pi:', error);
                if (piStatusDisplay) {
                    piStatusDisplay.textContent = `Error: ${error.message}`;
                    piStatusDisplay.className = 'alert alert-danger small mt-3';
                }
                showNotification(`Gagal memicu kamera: ${error.message}`, 'danger');
            }
        });
    }

    // Event listener utama lainnya
    const runOtherDetectorsButton = document.querySelector('.run-other-model[data-task="detector"][data-run="all"]');
    const runOtherClassifiersButton = document.querySelector('.run-other-model[data-task="classifier"][data-run="all"]');
    if (runOtherDetectorsButton) { runOtherDetectorsButton.addEventListener('click', () => handleRunOtherModels('detector', true)); }
    if (runOtherClassifiersButton) { runOtherClassifiersButton.addEventListener('click', () => handleRunOtherModels('classifier', true)); }

    // 2. Modifikasi Event Listener untuk detectorsResultsList
    // Pastikan detectorsResultsList sudah di-cache di atas (scope DOMContentLoaded)
    if (detectorsResultsList && runBboxClassifyOnDemandUrl) {
        detectorsResultsList.addEventListener('click', async function (event) {
            const targetListItem = event.target.closest('li.other-model-list-item[data-task-type="detector"]');
            if (!targetListItem || !targetListItem.dataset.modelKey) return;

            event.preventDefault();
            const clickedModelKey = targetListItem.dataset.modelKey;
            const isDetectedByClickedModel = targetListItem.dataset.detected === 'true';

            let clickedDetectorResultFull = null;
            const clickedDetectorResultItemString = targetListItem.dataset.detectorResultItem;
            if (clickedDetectorResultItemString) {
                try { clickedDetectorResultFull = JSON.parse(clickedDetectorResultItemString); } catch (e) { /* abaikan */ }
            }

            // === AWAL Point 1: Restriksi Klik Item List Detektor ===
            const initialDetection = initialDefaultDetectionResultForButtonLogic; // Ambil status awal
            if (initialDetection && initialDetection.success && initialDetection.detected === true) {
                showNotification(`Deteksi default awal sudah "Melon". Memilih model lain dari daftar ini tidak akan mengubah BBox/Klasifikasi utama.`, 'info');
                return; // Hentikan aksi jika deteksi awal sudah "melon"
            }
            // === AKHIR Point 1 ===

            if (isDetectedByClickedModel) {
                const s3PathForProcessing = currentResultData?.s3_path_processed || currentResultData?.s3_path_from_upload;
                if (!currentResultData || !s3PathForProcessing) {
                    showNotification('Data gambar utama (S3 path) tidak ditemukan untuk memulai proses ini.', 'warning');
                    return;
                }

                const modelDisplayName = targetListItem.querySelector('.model-name')?.textContent || clickedModelKey;
                showOverlay(`Memproses BBox & Klasifikasi berdasarkan ${modelDisplayName}...`);

                try {
                    const payload = { s3_image_path: s3PathForProcessing };

                    console.log('[OnDemandBC] Mengirim request ke:', runBboxClassifyOnDemandUrl, 'dengan payload:', payload);

                    const response = await fetch(runBboxClassifyOnDemandUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json', 'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify(payload)
                    });

                    // Dapatkan teks mentah dari respons dulu
                    const responseText = await response.text();
                    console.log('[OnDemandBC] Raw response text dari server:', responseText);

                    let dataFromServer;
                    try {
                        // Sekarang coba parse teks tersebut sebagai JSON
                        dataFromServer = JSON.parse(responseText);
                    } catch (jsonParseError) {
                        // Jika gagal parse, berarti server tidak mengirim JSON valid
                        console.error('[OnDemandBC] Gagal parse respons server sebagai JSON:', jsonParseError);
                        console.error('[OnDemandBC] Respons Server (kemungkinan HTML atau teks error):', responseText);

                        let specificErrorMessage = `Gagal memproses respons dari server (bukan JSON). Status: ${response.status}.`;
                        if (responseText.toLowerCase().includes("<html")) { // Cek sederhana apakah ini HTML
                            specificErrorMessage = `Server mengembalikan halaman HTML, bukan data JSON yang diharapkan. Kemungkinan ada error di backend. Status: ${response.status}.`;
                        }
                        // Lempar error baru dengan pesan yang lebih spesifik
                        throw new Error(specificErrorMessage);
                    }

                    // Jika parsing JSON berhasil, lanjutkan seperti biasa
                    if (!response.ok || !dataFromServer.success) {
                        throw new Error(dataFromServer.message || `Gagal memproses BBox & Klasifikasi on-demand (status: ${response.status}).`);
                    }

                    showNotification(`Berhasil! BBox & Klasifikasi berdasarkan ${modelDisplayName}. Tampilan utama diperbarui.`, 'success');

                    // === AWAL UPDATE currentResultData ===
                    currentResultData.bbox = dataFromServer.new_bbox_rel;
                    currentResultData.bbox_estimated_successfully = true;

                    if (!currentResultData.context) currentResultData.context = {};
                    currentResultData.context.bbox_from_python_rel = dataFromServer.new_bbox_rel;
                    currentResultData.context.bboxEstimated = true;

                    if (dataFromServer.new_color_features) {
                        currentResultData.context.colorFeatures = dataFromServer.new_color_features;
                        if (!currentResultData.context_features_extracted) currentResultData.context_features_extracted = {};
                        currentResultData.context_features_extracted.classification = true;
                        console.log("Fitur warna baru DITERIMA dan disimpan di currentResultData.context.colorFeatures");
                    } else {
                        currentResultData.context.colorFeatures = null;
                        if (currentResultData.context_features_extracted) currentResultData.context_features_extracted.classification = false;
                        console.warn("Fitur warna TIDAK diterima dari backend setelah on-demand BBox/Classify.");
                    }

                    currentResultData.default_classification = dataFromServer.triggered_classification_result;
                    currentResultData.classification_done = dataFromServer.triggered_classification_result ? (dataFromServer.triggered_classification_result.success || false) : false;
                    currentResultData.classification_error = (dataFromServer.triggered_classification_result && !dataFromServer.triggered_classification_result.success) ? dataFromServer.triggered_classification_result.error : null;

                    if (clickedDetectorResultFull) { // clickedDetectorResultFull didapat dari targetListItem.dataset.detectorResultItem
                        currentResultData.default_detection = clickedDetectorResultFull.model_result || clickedDetectorResultFull;
                        currentResultData.default_detection.metrics = clickedDetectorResultFull.metrics || currentResultData.default_detection.metrics;
                    } else {
                        currentResultData.default_detection = { success: true, detected: true, model_key: clickedModelKey, probabilities: { melon: 1.0, non_melon: 0.0 }, metrics: null };
                    }
                    if (!currentResultData.default_detection.probabilities) currentResultData.default_detection.probabilities = { melon: 1.0, non_melon: 0.0 };

                    currentResultData.last_triggered_by_detector = clickedModelKey;
                    currentResultData.last_used_classifier_for_trigger = dataFromServer.used_classifier_key;

                    displayFullPredictionResult(currentResultData, false);

                    const mainSummaryElement = document.getElementById('main-summary-card');
                    if (mainSummaryElement) mainSummaryElement.scrollIntoView({ behavior: 'smooth', block: 'start' });

                } catch (error) {
                    console.error(`Error saat on-demand BBox & Classify untuk ${clickedModelKey}:`, error);
                    // Pesan error di sini akan lebih informatif jika masalahnya adalah parsing JSON
                    showNotification(`Gagal memproses untuk ${modelDisplayName}: ${error.message}`, 'danger');
                } finally {
                    hideOverlay();
                }
            } else {
                const modelDisplayName = targetListItem.querySelector('.model-name')?.textContent || clickedModelKey;
                showNotification(`Model ${modelDisplayName} tidak mendeteksi melon. Tidak ada aksi BBox/Klasifikasi dijalankan.`, 'info');
            }
        });
    }

    // Fungsi untuk menangani klik di dalam area form feedback
    function handleFeedbackClick(event) {
        const form = event.currentTarget; // Form tempat klik terjadi
        const clickedButton = event.target.closest('button'); // Tombol yang diklik

        if (!clickedButton || !form) return; // Abaikan jika bukan klik pada tombol di dalam form

        const confirmButtonId = form.id === 'feedback-detection-form' ? 'confirm-detection-feedback-btn' : 'confirm-classification-feedback-btn';
        const confirmButton = form.querySelector(`#${confirmButtonId}`);
        const choiceButtons = form.querySelectorAll('button[type="submit"]'); // Tombol pilihan

        // 1. Jika yang diklik adalah TOMBOL PILIHAN (Yes/No atau Matang/Belum Matang)
        if (clickedButton.type === 'submit' && clickedButton !== confirmButton) {
            event.preventDefault(); // Mencegah submit standar

            choiceButtons.forEach(btn => btn.classList.remove('active-feedback-choice', 'btn-primary'));
            clickedButton.classList.add('active-feedback-choice', 'btn-primary');

            form.dataset.selectedFeedbackName = clickedButton.name;
            form.dataset.selectedFeedbackValue = clickedButton.value;

            if (confirmButton) {
                confirmButton.disabled = false;
                confirmButton.classList.remove('disabled');
            }
        }
        // 2. Jika yang diklik adalah TOMBOL KONFIRMASI
        else if (clickedButton === confirmButton) {
            event.preventDefault();
            submitFeedbackToServer(form); // Panggil fungsi untuk submit
        }
    }

    // Fungsi untuk mengirim data feedback ke server
    async function submitFeedbackToServer(form) {
        const feedbackType = form.id.includes('detection') ? 'detection' : 'classification';
        const resultArea = document.getElementById(`feedback-${feedbackType}-result`);
        const feedbackUrlToUse = feedbackType === 'detection' ? window.feedbackDetectionUrl : window.feedbackClassificationUrl;
        const confirmButton = form.querySelector('button[type="button"]');

        if (!form.dataset.selectedFeedbackName || !form.dataset.selectedFeedbackValue) {
            showNotification("Silakan pilih salah satu opsi feedback terlebih dahulu.", "warning");
            return;
        }

        const userChoiceDisplay = form.dataset.selectedFeedbackValue === 'yes' ? 'Melon' :
            form.dataset.selectedFeedbackValue === 'no' ? 'Bukan Melon' :
                form.dataset.selectedFeedbackValue === 'matang' ? 'Matang' : 'Belum Matang';

        if (!confirm(`Anda yakin ingin mengirim feedback "${userChoiceDisplay}"? Pilihan ini akan disimpan.`)) {
            return;
        }

        // Siapkan payload
        const payload = {}; // <-- PASTIKAN DEKLARASI ADA DI SINI
        payload[form.dataset.selectedFeedbackName] = form.dataset.selectedFeedbackValue;
        payload.s3_temp_image_path = currentResultData.s3_path_processed || currentResultData.s3_path_from_upload;
        payload.originalFilename = currentResultData.original_filename_from_upload || currentResultData.filename;

        if (feedbackType === 'detection' && currentResultData.default_detection) {
            payload.main_model_key = currentResultData.default_detection.model_key;
            payload.main_model_prediction = currentResultData.default_detection.detected ? 'melon' : 'non_melon';
            payload.estimated_bbox = (currentResultData.bbox_estimated_successfully && currentResultData.bbox) ? currentResultData.bbox : null;
        } else if (feedbackType === 'classification' && currentResultData.default_classification && currentResultData.bbox) {
            payload.main_model_key = currentResultData.default_classification.model_key;
            payload.main_model_prediction = currentResultData.default_classification.prediction;
            payload.tempServerFilename = currentResultData.temp_server_filename_from_upload; // Pastikan ini ada
            payload.estimated_bbox = currentResultData.bbox;
        } else {
            showNotification("Data prediksi tidak lengkap untuk mengirim feedback.", "danger");
            return;
        }

        const originalConfirmButtonHtml = confirmButton.innerHTML;
        confirmButton.disabled = true;
        confirmButton.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Mengirim...`;
        form.querySelectorAll('button[type="submit"]').forEach(btn => btn.disabled = true);
        if (resultArea) resultArea.innerHTML = `<div class="alert alert-secondary py-1 px-2 small border-0 mb-0"><i class="fas fa-spinner fa-spin me-1"></i> Mengirim feedback...</div>`;

        try {
            const response = await fetch(feedbackUrlToUse, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': window.csrfToken },
                body: JSON.stringify(payload)
            });
            const dataFromServer = await response.json();

            if (dataFromServer.message) {
                const alertType = (response.ok && dataFromServer.success) ? 'success' :
                    ((response.status === 409 || response.status === 403) ? 'info' : 'warning');
                if (resultArea) resultArea.innerHTML = `<div class="alert alert-${alertType} py-1 px-2 small border-0 mb-0"><i class="fas ${alertType === 'success' ? 'fa-check-circle' : 'fa-info-circle'} me-1"></i> ${dataFromServer.message}</div>`;
            }

            if (!response.ok || !dataFromServer.success) {
                throw new Error(dataFromServer.message || `Gagal kirim feedback (Status: ${response.status})`);
            }

            if (dataFromServer.saved_definitively || dataFromServer.success) {
                feedbackGivenForCurrentImage = true;
                const detForm = document.getElementById('feedback-detection-form');
                const clsForm = document.getElementById('feedback-classification-form');

                if (detForm) {
                    detForm.querySelectorAll('button').forEach(btn => btn.disabled = true);
                    detForm.classList.add('feedback-submitted');
                    detForm.querySelector('#confirm-detection-feedback-btn').innerHTML = `<i class="fas fa-check-double"></i> Tersimpan`;
                }
                if (clsForm) {
                    clsForm.querySelectorAll('button').forEach(btn => btn.disabled = true);
                    clsForm.classList.add('feedback-submitted');
                    clsForm.querySelector('#confirm-classification-feedback-btn').innerHTML = `<i class="fas fa-check-double"></i> Tersimpan`;
                }
                showNotification("Feedback Anda telah dicatat. Terima kasih!", "success", notificationAreaMain);
            }

            if (typeof dataFromServer.pending_annotations_count === 'number') {
                updatePendingAnnotationReminderDisplay(dataFromServer.pending_annotations_count);
            }
            if (dataFromServer.ask_for_annotation) {
                showNotification("Gambar ini ditandai memerlukan anotasi BBox. Silakan periksa di halaman Anotasi.", 'info', notificationAreaMain, 7000);
            }

        } catch (error) {
            console.error(`Error submitting ${feedbackType} feedback:`, error);
            const userMessage = error.message || `Terjadi kesalahan saat mengirim feedback ${feedbackType}.`;
            if (resultArea) resultArea.innerHTML = `<div class="alert alert-danger py-1 px-2 small border-0 mb-0"><i class="fas fa-exclamation-triangle me-1"></i> ${userMessage}</div>`;
            showNotification(userMessage, 'danger', notificationAreaMain, 7000);
            form.querySelectorAll('button').forEach(btn => btn.disabled = false);
            if (confirmButton) {
                confirmButton.innerHTML = originalConfirmButtonHtml;
                confirmButton.disabled = true;
            }
        } finally {
            delete form.dataset.selectedFeedbackName;
            delete form.dataset.selectedFeedbackValue;
        }
    }

    function resetFeedbackForms() {
        ['feedback-detection-form', 'feedback-classification-form'].forEach(formId => {
            const form = document.getElementById(formId);
            if (form) {
                form.classList.remove('feedback-submitted');
                form.querySelectorAll('button').forEach(btn => {
                    btn.disabled = false;
                    btn.classList.remove('active-feedback-choice', 'btn-primary'); // Hapus gaya aktif
                });

                const confirmBtn = form.querySelector('button[type="button"]');
                if (confirmBtn) {
                    confirmBtn.disabled = true; // Nonaktifkan confirm button
                    confirmBtn.classList.add('disabled');
                    // Reset teks tombol konfirmasi
                    confirmBtn.innerHTML = '<i class="fas fa-check"></i> Konfirmasi Pilihan'; // Set ke teks default
                }

                const resultArea = document.getElementById(formId.replace('-form', '-result'));
                if (resultArea) resultArea.innerHTML = ''; // Kosongkan area hasil

                // Hapus data pilihan yang tersimpan
                delete form.dataset.selectedFeedbackName;
                delete form.dataset.selectedFeedbackValue;
                delete form.dataset.userChoiceForConfirm;

                // Pastikan tombol pilihan kembali ke warna default (misal: success/danger)
                const yesBtn = form.querySelector('button[value="yes"]');
                const noBtn = form.querySelector('button[value="no"]');
                const matangBtn = form.querySelector('button[value="matang"]');
                const belumMatangBtn = form.querySelector('button[value="belum_matang"]');
                if (yesBtn) yesBtn.classList.add('btn-success');
                if (noBtn) noBtn.classList.add('btn-danger');
                if (matangBtn) matangBtn.classList.add('btn-success');
                if (belumMatangBtn) belumMatangBtn.classList.add('btn-warning');

            }
        });
        // --- AKHIR PERBAIKAN POIN 3 (Fungsi Reset) ---
    }

    // --- PERBAIKAN POIN 3: Fungsi Reset ---
    function resetUIForNewUpload() {
        console.log("Resetting UI for new upload...");
        currentResultData = {};
        initialDefaultDetectionResultForButtonLogic = null;
        feedbackGivenForCurrentImage = false; // Reset flag feedback

        // Sembunyikan result section
        const resultSection = document.getElementById('result-section');
        if (resultSection) resultSection.classList.add('d-none');

        // Reset gambar
        const uploadedImageElement = document.getElementById('uploaded-image');
        const uploadedImagePlaceholder = document.getElementById('uploaded-image-placeholder');
        const detectionImageDisplay = document.getElementById('detection-image-display');
        const detectionImagePlaceholder = document.getElementById('detection-image-placeholder');
        if (uploadedImageElement) uploadedImageElement.style.display = 'none';
        if (uploadedImagePlaceholder) { uploadedImagePlaceholder.textContent = 'Pilih gambar'; uploadedImagePlaceholder.style.display = 'block'; }
        if (detectionImageDisplay) detectionImageDisplay.style.display = 'none';
        if (detectionImagePlaceholder) { detectionImagePlaceholder.textContent = 'Menunggu hasil'; detectionImagePlaceholder.style.display = 'block'; }


        // Reset form feedback (termasuk label tombol konfirmasi)
        ['feedback-detection-form', 'feedback-classification-form'].forEach(formId => {
            const form = document.getElementById(formId);
            if (form) {
                form.classList.remove('feedback-submitted');
                form.querySelectorAll('button').forEach(btn => {
                    btn.disabled = false; // AKTIFKAN KEMBALI
                    btn.classList.remove('active-feedback-choice', 'btn-primary');
                });

                const confirmBtn = form.querySelector('button[type="button"]');
                if (confirmBtn) {
                    confirmBtn.disabled = true; // Nonaktifkan confirm button
                    confirmBtn.classList.add('disabled');
                    confirmBtn.innerHTML = '<i class="fas fa-check"></i> Konfirmasi Pilihan'; // Reset teks
                }

                const resultArea = document.getElementById(formId.replace('-form', '-result'));
                if (resultArea) resultArea.innerHTML = '';

                delete form.dataset.selectedFeedbackName;
                delete form.dataset.selectedFeedbackValue;
                delete form.dataset.userChoiceForConfirm;

                // Kembalikan warna default
                const yesBtn = form.querySelector('button[value="yes"]');
                const noBtn = form.querySelector('button[value="no"]');
                const matangBtn = form.querySelector('button[value="matang"]');
                const belumMatangBtn = form.querySelector('button[value="belum_matang"]');
                if (yesBtn) yesBtn.classList.add('btn-success');
                if (noBtn) noBtn.classList.add('btn-danger');
                if (matangBtn) matangBtn.classList.add('btn-success');
                if (belumMatangBtn) belumMatangBtn.classList.add('btn-warning');
            }
        });

        // Reset area model lain
        resetOtherModelsDisplay();
    }
    // --- AKHIR PERBAIKAN POIN 3 ---

    const feedbackDetectionForm = document.getElementById('feedback-detection-form');
    const feedbackClassificationForm = document.getElementById('feedback-classification-form');

    // Tambahkan event listener 'click' ke KEDUA form
    if (feedbackDetectionForm) {
        feedbackDetectionForm.addEventListener('click', handleFeedbackClick);
    }
    if (feedbackClassificationForm) {
        feedbackClassificationForm.addEventListener('click', handleFeedbackClick);
    }

    if (clearAppCacheBtn && window.clearCacheUrl) {
        clearAppCacheBtn.addEventListener('click', async () => {
            if (!confirm("Anda yakin ingin membersihkan cache aplikasi dan file sementara?\nIni mungkin akan membuat halaman sedikit lebih lambat saat pertama kali diakses lagi.")) return;
            showOverlay("Membersihkan cache...");
            try {
                const response = await fetch(window.clearCacheUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } });
                const data = await response.json();
                showNotification(data.message || (data.success ? "Cache aplikasi berhasil dibersihkan." : "Gagal membersihkan cache."), data.success ? 'success' : 'danger');
            } catch (error) { showNotification("Error koneksi saat membersihkan cache: " + error.message, 'danger'); }
            finally { hideOverlay(); }
        });
    }

    if (scrollToTopBtn) {
        window.addEventListener('scroll', () => {
            if (document.body.scrollTop > 200 || document.documentElement.scrollTop > 200) {
                scrollToTopBtn.style.display = "block";
            } else {
                scrollToTopBtn.style.display = "none";
            }
        });
        scrollToTopBtn.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }


    // Inisialisasi
    if (currentResultData && currentResultData.filename && (Object.keys(currentResultData).length > 2 || currentResultData.default_detection !== null)) {
        displayFullPredictionResult(currentResultData);
    } else {
        if (resultSection) { resultSection.classList.add('d-none'); }
    }
    updatePendingAnnotationReminderDisplay(currentPendingAnnotationCount);

}); // Akhir DOMContentLoaded
