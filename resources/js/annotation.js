function initializeAnnotationTool() {
    const annotationPageContainer = document.getElementById('annotation-page-container');
    if (!annotationPageContainer) { return; }

    const annotationImage = document.getElementById('annotation-image'); // Pastikan ini dideklarasikan
    // Pengecekan annotationImage di awal penting. Jika tidak ada, UI utama tidak aktif.
    if (!annotationImage) {
        console.log("Annotation UI not active: #annotation-image not found. Skipping annotation tool specific setup.");
        // Jika halaman memang seharusnya menampilkan "Anotasi Selesai" dari server (via Blade),
        // maka return di sini sudah benar.
        return;
    }

    const annotationContainer = document.getElementById('annotation-container');
    const bboxOverlay = document.getElementById('bbox-overlay');
    const annotationForm = document.getElementById('annotation-form');
    const saveButton = document.getElementById('save-button');
    const detectionRadios = document.querySelectorAll('input[name="detection_choice"]');
    const melonAnnotationArea = document.getElementById('melon-annotation-area');
    const ripenessOptionsDiv = document.getElementById('ripeness-options');
    const ripenessRadios = document.querySelectorAll('.ripeness-radio');
    const bboxListContainer = document.getElementById('bbox-list-container');
    const bboxListUl = document.getElementById('bbox-list');
    const annotationsJsonInput = document.getElementById('input-annotations-json');
    const imagePathInput = document.getElementById('input-image-path');
    const datasetSetInput = document.getElementById('input-dataset-set');
    const bboxCountSpan = document.getElementById('bbox-count');
    const selectedBboxIndexSpan = document.getElementById('selected-bbox-index');
    const notificationArea = document.getElementById('notification-area');
    const prefillNotificationArea = document.getElementById('prefill-notification-area');
    const thumbnailContainer = document.getElementById('thumbnail-container');
    const prevPageBtn = document.getElementById('prev-page-btn');
    const nextPageBtn = document.getElementById('next-page-btn');
    const galleryInfoDiv = document.getElementById('gallery-info');
    const currentPageDisplay = document.getElementById('current-page-display');
    const totalPagesDisplay = document.getElementById('total-pages-display');
    const totalImagesDisplay = document.getElementById('total-images-display');
    const activeImagePathSpan = document.getElementById('active-image-path');

    // --- State Aplikasi ---
    let imageRect;
    let annotations = [];
    let selectedBoxId = -1;
    let nextBoxId = 0;
    let currentImagePath = annotationPageContainer.dataset.initialS3Path ||
        annotationPageContainer.dataset.initialImagePath ||
        null;
    let galleryCurrentPage = parseInt(annotationPageContainer.dataset.initialCurrentPage || '1');
    let galleryTotalPages = parseInt(annotationPageContainer.dataset.initialTotalPages || '1');
    const csrfToken = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : null;
    const estimateBboxEndpoint = annotationPageContainer.dataset.estimateBboxEndpoint || null;
    const minBoxSize = 5;
    let isEstimatingBbox = false;
    const clearCacheUrlForAnnotate = annotationPageContainer.dataset.clearCacheUrl || window.clearCacheUrl; // Ambil dari data attr atau global

    // --- State Interaksi Bbox ---
    let interactionState = { type: 'none', targetBox: null, handleType: null, startX: 0, startY: 0, initialBoxRect: null };

    // --- Inisialisasi ---
    // Tidak perlu cek `!annotationImage` lagi karena sudah di atas
    if (annotationImage) { // Hanya lanjutkan jika elemen gambar utama ada
        annotationImage.style.visibility = 'hidden'; // Sembunyikan dulu sampai benar-benar siap

        // Ambil semua data awal dari data attributes
        const initialImageUrl = annotationPageContainer.dataset.initialImageUrl;
        const initialFilename = annotationPageContainer.dataset.initialFilename;
        const initialSet = annotationPageContainer.dataset.initialSet;
        const initialImagePathForCsv = annotationPageContainer.dataset.initialImagePath;
        const initialS3PathActual = annotationPageContainer.dataset.initialS3Path; // Ini yang kita mau
        const initialIsPendingBbox = annotationPageContainer.dataset.initialIsPendingBbox === 'true';

        // Jika ada data gambar awal yang valid, panggil updateMainImage
        if (initialImageUrl && initialS3PathActual && initialFilename && initialSet && initialImagePathForCsv) {
            const initialImageData = {
                imageUrl: initialImageUrl,
                filename: initialFilename,
                datasetSet: initialSet,
                imagePathForCsv: initialImagePathForCsv,
                s3Path: initialS3PathActual, // Gunakan path S3 lengkap untuk konsistensi internal JS
                isPendingBbox: initialIsPendingBbox
            };
            // Memanggil updateMainImage akan mengatur currentImagePath dengan benar (path S3 lengkap)
            // dan juga akan mengatur event listener onload yang memanggil requestBboxEstimation jika perlu
            updateMainImage(initialImageData);
        } else if (document.getElementById('annotation-image')) { // Jika tidak ada data awal tapi elemen gambar ada
            // Mungkin ini kasus halaman "anotasi selesai" atau error awal dari controller
            // Biarkan kosong atau tampilkan placeholder jika #annotation-image ada tapi tidak ada src
            annotationImage.style.visibility = 'visible';
            if (annotationImage.parentElement) annotationImage.parentElement.innerHTML = '<p class="text-muted text-center p-5">Tidak ada gambar untuk dianotasi atau anotasi telah selesai.</p>';
        }
    }
    // setupAnnotationImage(); // Pemanggilan ini mungkin tidak diperlukan lagi jika updateMainImage menangani semua untuk gambar awal

    updatePaginationButtons(); // Pastikan ini dipanggil setelah galleryCurrentPage & totalPages terdefinisi
    renderBboxList();
    validateAndEnableSave();

    // --- Fungsi Helper ---
    function showNotification(message, type = 'info', area = notificationArea) {
        if (!area) return;
        const alertClass = `alert-${type}`;
        const iconClass = type === 'success' ? 'fa-check-circle' : (type === 'danger' ? 'fa-exclamation-triangle' : 'fa-info-circle');
        const notificationHTML = `
             <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                 <i class="fas ${iconClass} me-2"></i> ${message}
                 <button type="button" class="btn-close btn-sm py-0" data-bs-dismiss="alert" aria-label="Close"></button>
             </div>`;
        area.innerHTML = notificationHTML;
        // Auto-dismiss notifikasi info/sukses setelah beberapa detik
        if (type === 'success' || type === 'info') {
            setTimeout(() => {
                const alertElement = area.querySelector('.alert');
                if (alertElement && bootstrap.Alert.getInstance(alertElement)) {
                    bootstrap.Alert.getInstance(alertElement).close();
                } else if (alertElement) {
                    alertElement.remove(); // Fallback jika instance tidak ditemukan
                }
            }, 5000);
        }
    }

    function updateImageRect() {
        if (annotationImage && annotationImage.complete && annotationImage.naturalWidth > 0) {
            imageRect = annotationImage.getBoundingClientRect();
        } else {
            imageRect = null;
        }
    }

    function resetAnnotationState(clearDetectionChoice = true) {
        console.log("Resetting annotation state...");
        annotations.forEach(anno => anno.element?.remove());
        annotations = [];
        nextBoxId = 0;
        selectedBoxId = -1;
        interactionState.type = 'none';

        renderBboxList();
        if (ripenessOptionsDiv) ripenessOptionsDiv.classList.add('hidden');
        disableRipenessRadios();
        if (selectedBboxIndexSpan) selectedBboxIndexSpan.textContent = '-';
        if (clearDetectionChoice) {
            detectionRadios.forEach(radio => radio.checked = false);
        }
        if (melonAnnotationArea) melonAnnotationArea.classList.add('hidden');
        if (annotationsJsonInput) annotationsJsonInput.value = '';
        validateAndEnableSave();
        if (annotationContainer) annotationContainer.style.cursor = 'crosshair';
        document.querySelectorAll('.bbox-div.selected').forEach(el => el.classList.remove('selected'));
        if (prefillNotificationArea) prefillNotificationArea.innerHTML = ''; // Bersihkan notif prefill
    }

    function setupAnnotationImage() {
        if (!annotationImage) return;
        annotationImage.onload = null;
        annotationImage.onerror = null;

        annotationImage.onload = () => {
            console.log("Image loaded:", annotationImage.src);
            updateImageRect();
            // Reset state, tapi jangan clear pilihan detection jika gambar baru dimuat karena pilihan user
            resetAnnotationState(false);
            // Panggil estimasi bbox jika pilihan 'melon' sudah aktif
            const melonRadio = document.querySelector('input[name="detection_choice"][value="melon"]');
            if (melonRadio && melonRadio.checked) {
                requestBboxEstimation();
            }
        };
        annotationImage.onerror = () => {
            console.error("Failed to load image:", annotationImage.src);
            if (annotationContainer) {
                annotationContainer.innerHTML = '<p class="text-danger p-5 text-center"><i class="fas fa-exclamation-triangle me-1"></i> Gagal memuat gambar.</p>';
                annotationContainer.style.cursor = 'default';
            }
            if (saveButton) saveButton.disabled = true;
            showNotification("Gagal memuat gambar utama. Coba muat gambar lain dari galeri atau segarkan halaman.", "danger");
        };

        if (annotationImage.complete) {
            if (annotationImage.naturalWidth > 0 && annotationImage.naturalHeight > 0) {
                setTimeout(() => { if (annotationImage.onload) annotationImage.onload(); }, 50);
            } else if (annotationImage.src) { // Hanya trigger error jika src ada tapi gagal load
                setTimeout(() => { if (annotationImage.onerror) annotationImage.onerror(); }, 50);
            }
        }
    }
    window.addEventListener('resize', updateImageRect);

    function getRelativeCoords(event) {
        if (!imageRect) return null;
        return {
            x: Math.max(0, Math.min(event.clientX - imageRect.left, imageRect.width)),
            y: Math.max(0, Math.min(event.clientY - imageRect.top, imageRect.height))
        };
    }

    function updateAnnotationCoords(boxId) {
        const index = annotations.findIndex(a => a.id === boxId);
        if (index === -1 || !annotations[index].element || !imageRect) return;

        const boxElement = annotations[index].element;
        const naturalW = annotationImage.naturalWidth;
        const naturalH = annotationImage.naturalHeight;
        const displayW = imageRect.width;
        const displayH = imageRect.height;

        if (!naturalW || !naturalH || !displayW || !displayH) {
            console.error("Invalid image dimensions for coordinate conversion.");
            return;
        }

        const scaleX = naturalW / displayW;
        const scaleY = naturalH / displayH;
        const finalLeftPx = parseFloat(boxElement.style.left);
        const finalTopPx = parseFloat(boxElement.style.top);
        const finalWidthPx = parseFloat(boxElement.style.width);
        const finalHeightPx = parseFloat(boxElement.style.height);
        const bboxNaturalX = finalLeftPx * scaleX;
        const bboxNaturalY = finalTopPx * scaleY;
        const bboxNaturalW = finalWidthPx * scaleX;
        const bboxNaturalH = finalHeightPx * scaleY;
        const cx = Math.max(0, Math.min(1, (bboxNaturalX + bboxNaturalW / 2) / naturalW));
        const cy = Math.max(0, Math.min(1, (bboxNaturalY + bboxNaturalH / 2) / naturalH));
        const w = Math.max(0.001, Math.min(1, bboxNaturalW / naturalW)); // w dan h tidak boleh 0
        const h = Math.max(0.001, Math.min(1, bboxNaturalH / naturalH)); // w dan h tidak boleh 0

        annotations[index].cx = parseFloat(cx.toFixed(6));
        annotations[index].cy = parseFloat(cy.toFixed(6));
        annotations[index].w = parseFloat(w.toFixed(6));
        annotations[index].h = parseFloat(h.toFixed(6));

        validateAndEnableSave();
    }

    function renderBboxList() {
        if (!bboxListUl || !bboxCountSpan) return;
        bboxListUl.innerHTML = '';
        bboxCountSpan.textContent = annotations.length;

        if (annotations.length === 0) {
            bboxListUl.innerHTML = '<li class="list-group-item text-muted no-bboxes">Belum ada Bbox digambar.</li>';
            if (ripenessOptionsDiv) ripenessOptionsDiv.classList.add('hidden');
            disableRipenessRadios();
            if (selectedBboxIndexSpan) selectedBboxIndexSpan.textContent = '-';
            return;
        }

        annotations.forEach((anno, index) => {
            const li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-center clickable';
            li.dataset.id = anno.id;

            const textSpan = document.createElement('span');
            textSpan.textContent = `Bbox #${index + 1}`;

            const ripenessBadge = document.createElement('span');
            ripenessBadge.className = 'badge rounded-pill ms-2';
            if (anno.ripeness === 'ripe') { ripenessBadge.classList.add('bg-success'); ripenessBadge.textContent = 'Matang'; }
            else if (anno.ripeness === 'unripe') { ripenessBadge.classList.add('bg-warning', 'text-dark'); ripenessBadge.textContent = 'Belum Matang'; }
            else { ripenessBadge.classList.add('bg-secondary'); ripenessBadge.textContent = 'Atur?'; }

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button'; deleteBtn.className = 'btn btn-danger btn-sm btn-delete-bbox';
            deleteBtn.innerHTML = '<i class="fas fa-trash-alt fa-xs"></i>'; deleteBtn.dataset.id = anno.id; deleteBtn.title = 'Hapus Bbox';

            textSpan.appendChild(ripenessBadge);
            li.appendChild(textSpan);
            li.appendChild(deleteBtn);

            li.addEventListener('click', (e) => { if (!e.target.closest('.btn-delete-bbox')) { selectAnnotation(anno.id); } });
            deleteBtn.addEventListener('click', (e) => { e.stopPropagation(); deleteAnnotation(anno.id); });

            if (anno.id === selectedBoxId) { li.classList.add('active'); }
            bboxListUl.appendChild(li);
        });
    }

    function selectAnnotation(id) {
        const currentlySelected = document.querySelector('.bbox-div.selected');
        if (currentlySelected) {
            currentlySelected.classList.remove('selected');
            removeResizeHandles(currentlySelected);
        }

        const index = annotations.findIndex(a => a.id === id);
        console.log(`Selecting annotation ID: ${id}, Index: ${index}`);

        if (index === -1 || id === -1) {
            selectedBoxId = -1;
            if (ripenessOptionsDiv) ripenessOptionsDiv.classList.add('hidden');
            disableRipenessRadios();
            if (selectedBboxIndexSpan) selectedBboxIndexSpan.textContent = '-';
        } else {
            selectedBoxId = id;
            const selectedAnno = annotations[index];
            const boxElement = selectedAnno.element;

            if (boxElement) {
                boxElement.classList.add('selected');
                addResizeHandles(boxElement);
            }

            if (ripenessOptionsDiv) ripenessOptionsDiv.classList.remove('hidden');
            enableRipenessRadios();
            // Periksa radio button yang sesuai dengan ripeness, atau kosongkan jika null
            const radioToCheckValue = selectedAnno.ripeness || '';
            ripenessRadios.forEach(r => r.checked = (r.value === radioToCheckValue));

            if (selectedBboxIndexSpan) selectedBboxIndexSpan.textContent = index + 1;
        }
        renderBboxList();
        validateAndEnableSave();
    }

    function deleteAnnotation(id) {
        const index = annotations.findIndex(a => a.id === id);
        if (index !== -1) {
            console.log(`Deleting annotation ID: ${id}`);
            if (annotations[index].element) {
                removeResizeHandles(annotations[index].element);
                annotations[index].element.remove();
            }
            annotations.splice(index, 1);
            if (selectedBoxId === id) { selectAnnotation(-1); }
            renderBboxList();
            validateAndEnableSave();
        }
    }

    function updateAnnotationRipeness(id, ripeness) {
        const index = annotations.findIndex(a => a.id === id);
        if (index !== -1) {
            console.log(`Updating ripeness for ID ${id} to ${ripeness}`);
            annotations[index].ripeness = ripeness;
            renderBboxList();
            validateAndEnableSave();
        }
    }
    function enableRipenessRadios() { ripenessRadios.forEach(r => r.disabled = false); }
    function disableRipenessRadios() { ripenessRadios.forEach(r => { r.disabled = true; r.checked = false; }); }

    function validateAndEnableSave() {
        if (!saveButton || !annotationsJsonInput) return;
        const detectionClassChecked = document.querySelector('input[name="detection_choice"]:checked');
        let enabled = false;
        let jsonValueForInput = '[]'; // Default ke array kosong (untuk non_melon atau melon tanpa bbox valid)

        if (detectionClassChecked) {
            const choice = detectionClassChecked.value;
            if (choice === 'non_melon') {
                enabled = true;
                // jsonValueForInput tetap '[]'
            } else if (choice === 'melon') {
                if (annotations.length > 0 && annotations.every(anno => anno.ripeness && anno.cx > 0 && anno.cy > 0 && anno.w > 0 && anno.h > 0)) {
                    enabled = true;
                    jsonValueForInput = JSON.stringify(annotations.map(a => ({
                        cx: a.cx, cy: a.cy, w: a.w, h: a.h,
                        ripeness: a.ripeness
                    })));
                } else {
                    enabled = false; // Tombol simpan nonaktif jika melon tapi data bbox tidak lengkap
                    // jsonValueForInput tetap '[]' atau bisa juga string kosong jika backend mengharapkan itu untuk error
                }
            }
        } else {
            enabled = false; // Jika tidak ada pilihan deteksi, tombol simpan nonaktif
        }
        saveButton.disabled = !enabled;
        annotationsJsonInput.value = jsonValueForInput;
        console.log("validateAndEnableSave - Enabled:", enabled, "JSON Value:", jsonValueForInput);
    }

    function addResizeHandles(boxElement) {
        removeResizeHandles(boxElement);
        const handleTypes = ['nw', 'ne', 'sw', 'se', 'n', 's', 'e', 'w'];
        handleTypes.forEach(type => {
            const handle = document.createElement('div');
            handle.classList.add('bbox-handle', `handle-${type}`);
            handle.dataset.handleType = type;
            boxElement.appendChild(handle);
        });
    }

    function removeResizeHandles(boxElement) {
        if (boxElement) {
            boxElement.querySelectorAll('.bbox-handle').forEach(handle => handle.remove());
        }
    }

    async function fetchImageData(imagePath) {
        console.log(`Fetching image data for: ${imagePath}`);
        console.log("[Annotation JS] Path being sent to server for getImageData:", imagePath);
        showLoadingIndicator(true, "Memuat data gambar..."); // Tampilkan loading
        try {
            const endpointUrl = annotationPageContainer.dataset.imageDataEndpoint;
            if (!endpointUrl) throw new Error("Image data endpoint URL is not defined.");
            const url = new URL(endpointUrl, window.location.origin);
            url.searchParams.append('getImageData', imagePath);
            console.log("[Annotation JS] Path being sent to server for getImageData:", imagePath);

            const response = await fetch(url, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
            if (!response.ok) {
                let errorMsg = `HTTP error! status: ${response.status}`;
                try { const errorData = await response.json(); errorMsg = errorData.message || errorMsg; } catch (e) { /* ignore */ }
                throw new Error(errorMsg);
            }
            const data = await response.json();
            if (data.success && data.imageData) {
                console.log("Image data received:", data.imageData);
                updateMainImage(data.imageData);
                const newPageUrl = new URL(window.location.href);
                newPageUrl.searchParams.set('image', data.imageData.imagePathForCsv);
                history.pushState({ path: newPageUrl.href }, '', newPageUrl.href);
            } else { throw new Error(data.message || 'Failed to get valid image data from server.'); }
        } catch (error) {
            console.error('Error fetching image data:', error);
            showNotification(`Gagal memuat data gambar: ${error.message}`, 'danger');
        } finally {
            showLoadingIndicator(false); // Sembunyikan loading
        }
    }

    async function fetchGalleryPage(page) {
        console.log(`Fetching gallery page: ${page}`);
        if (thumbnailContainer) thumbnailContainer.innerHTML = '<div class="d-flex justify-content-center p-3"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>'; // Indikator loading galeri
        try {
            const endpointUrl = annotationPageContainer.dataset.galleryEndpoint;
            if (!endpointUrl) throw new Error("Gallery endpoint URL is not defined.");
            const url = new URL(endpointUrl, window.location.origin);
            url.searchParams.append('getGalleryPage', page);

            const response = await fetch(url, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
            if (!response.ok) {
                let errorMsg = `HTTP error! status: ${response.status}`;
                try { const errorData = await response.json(); errorMsg = errorData.message || errorMsg; } catch (e) { /* ignore */ }
                throw new Error(errorMsg);
            }
            const data = await response.json();
            if (data.success) {
                console.log("Gallery data received:", data);
                renderThumbnails(data.galleryImages);
                galleryCurrentPage = data.currentPage;
                galleryTotalPages = data.totalPages;
                updateGalleryInfo(data.currentPage, data.totalPages, data.totalImages);
                updatePaginationButtons();
            } else { throw new Error(data.message || 'Failed to get gallery data from server.'); }
        } catch (error) {
            console.error('Error fetching gallery page:', error);
            if (thumbnailContainer) thumbnailContainer.innerHTML = '<p class="text-danger small w-100 text-center my-auto">Gagal memuat thumbnail.</p>';
            showNotification(`Gagal memuat galeri: ${error.message}`, 'danger');
        }
    }

    async function requestBboxEstimation() {
        // Variabel currentImagePath HARUSNYA sudah berisi path S3 lengkap
        // (misal: "dataset/train/namafile.jpg") dari updateMainImage
        const s3PathForEstimation = currentImagePath; // Langsung gunakan currentImagePath

        if (!estimateBboxEndpoint || !csrfToken || !s3PathForEstimation || isEstimatingBbox) {
            console.warn("Skipping bbox estimation request (missing config, path, or already in progress):",
                { estimateBboxEndpoint, csrfToken, s3PathForEstimationValue: s3PathForEstimation, isEstimatingBbox }
            );
            if (!s3PathForEstimation && prefillNotificationArea) {
                showNotification("Path gambar (currentImagePath) tidak valid atau kosong untuk estimasi BBox otomatis.", "warning", prefillNotificationArea);
            }
            return;
        }

        isEstimatingBbox = true;
        if (prefillNotificationArea) showNotification("Mencoba mendeteksi area melon otomatis...", "info", prefillNotificationArea);

        // !!! TAMBAHKAN ATAU PASTIKAN LOG INI ADA DAN AKTIF !!!
        console.log(`[Annotation JS] requestBboxEstimation: Mengirim path "${s3PathForEstimation}" ke server untuk estimasi BBox.`);

        try {
            const response = await fetch(estimateBboxEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ image_path: s3PathForEstimation }) // Kirim path S3 lengkap
            });

            if (!response.ok) {
                let errorMsg = `Gagal memuat estimasi BBox. Status: ${response.status}`;
                try {
                    const errorData = await response.json();
                    errorMsg = errorData.message || errorMsg;
                } catch (e) { /* Biarkan errorMsg default jika respons bukan JSON */ }
                throw new Error(errorMsg);
            }

            const data = await response.json();
            console.log(`[Annotation JS] requestBboxEstimation: Mengirim path "${s3PathForEstimation}" ke server untuk estimasi BBox.`);

            if (data.success && Array.isArray(data.bboxes)) {
                if (data.bboxes.length > 0) {
                    console.log(`Prefill Bboxes diterima (${data.bboxes.length}):`, data.bboxes);
                    annotations.forEach(anno => anno.element?.remove()); // Hapus anotasi lama jika ada
                    annotations = [];
                    nextBoxId = 0;

                    data.bboxes.forEach(bboxRel => {
                        if (bboxRel && typeof bboxRel === 'object') {
                            drawPrefilledBbox(bboxRel);
                        }
                    });

                    if (annotations.length > 0) {
                        selectAnnotation(annotations[annotations.length - 1].id); // Pilih BBox terakhir yang ditambahkan
                    }
                    showNotification(`Ditemukan ${data.bboxes.length} area melon otomatis. Silakan periksa dan atur kematangan.`, "success", prefillNotificationArea);
                } else {
                    console.log("Tidak ada BBox yang diestimasi oleh backend.");
                    showNotification("Tidak ada area melon yang terdeteksi otomatis. Silakan gambar manual jika ada.", "warning", prefillNotificationArea);
                }
            } else if (data.success && data.bboxes === null) { // Tambahan kondisi jika bboxes adalah null
                console.log("Backend melaporkan sukses tapi tidak ada BBox (null).");
                showNotification("Tidak ada area melon yang terdeteksi otomatis. Silakan gambar manual jika ada.", "warning", prefillNotificationArea);
            }
            else {
                throw new Error(data.message || "Gagal mendapatkan estimasi BBox dari server atau format data tidak sesuai.");
            }
        } catch (error) {
            console.error("Error requesting bbox estimation:", error);
            showNotification(`Gagal melakukan estimasi otomatis: ${error.message}`, "danger", prefillNotificationArea);
        } finally {
            isEstimatingBbox = false;
        }
    }

    function showLoadingIndicator(show, message = "Memproses...") {
        let loadingDiv = document.getElementById('page-loading-indicator');
        if (show) {
            if (!loadingDiv) {
                loadingDiv = document.createElement('div');
                loadingDiv.id = 'page-loading-indicator';
                // Styling untuk memastikan full viewport dan centering
                loadingDiv.style.position = 'fixed'; // Tetap fixed untuk overlay
                loadingDiv.style.top = '0';
                loadingDiv.style.left = '0';
                loadingDiv.style.width = '100vw'; // Gunakan viewport width
                loadingDiv.style.height = '100vh'; // Gunakan viewport height
                loadingDiv.style.backgroundColor = 'rgba(0,0,0,0.5)';
                loadingDiv.style.zIndex = '10000'; // Pastikan di atas segalanya
                loadingDiv.style.display = 'flex';
                loadingDiv.style.justifyContent = 'center'; // Tengah secara horizontal
                loadingDiv.style.alignItems = 'center';   // Tengah secara vertikal
                // Konten spinner dan pesan
                loadingDiv.innerHTML = `
                    <div class="card p-3 d-flex flex-row align-items-center">
                        <div class="spinner-border text-primary me-2" role="status" style="width: 1.5rem; height: 1.5rem;"></div>
                        <span style="font-size: 0.9rem;">${message}</span>
                    </div>`;
                document.body.appendChild(loadingDiv);
            }
            // Update pesan jika sudah ada dan tampilkan
            const messageSpan = loadingDiv.querySelector('span');
            if (messageSpan) {
                messageSpan.textContent = message;
            }
            loadingDiv.style.display = 'flex'; // Pastikan display adalah flex untuk centering
        } else {
            if (loadingDiv) {
                loadingDiv.style.display = 'none';
            }
        }
    }

    function updateMainImage(imageData) {
        if (!annotationImage || !imagePathInput || !datasetSetInput || !activeImagePathSpan) {
            console.error("One or more target elements for main image update not found.");
            return;
        }
        console.log("updateMainImage dipanggil dengan:", imageData); // Log data yang diterima
        annotationImage.style.visibility = 'hidden';
        annotationImage.src = imageData.imageUrl;

        imagePathInput.value = imageData.imagePathForCsv; // contoh: "train/namafile.jpg"
        datasetSetInput.value = imageData.datasetSet;

        // !!! PASTIKAN BARIS INI MENGGUNAKAN imageData.s3Path !!!
        // imageData.s3Path dari server adalah path S3 lengkap (misal: "dataset/train/namafile.jpg")
        currentImagePath = imageData.s3Path; // <--- PASTIKAN INI MENGGUNAKAN imageData.s3Path
        console.log("[Annotation JS] currentImagePath DISET ke:", currentImagePath, "di updateMainImage");

        activeImagePathSpan.textContent = `${imageData.datasetSet}/${imageData.filename}`;
        highlightActiveThumbnail(imageData.s3Path); // Gunakan path S3 lengkap untuk highlight

        annotationImage.onload = () => {
            annotationImage.style.visibility = 'visible';
            updateImageRect();
            resetAnnotationState(false);
            const melonRadio = document.querySelector('input[name="detection_choice"][value="melon"]');
            if (melonRadio && melonRadio.checked) {
                // Saat onload, currentImagePath sudah diupdate dengan benar dari imageData.s3Path
                requestBboxEstimation();
            }
        };

        if (annotationImage.complete && annotationImage.naturalWidth > 0) {
            setTimeout(() => { if (annotationImage.onload) annotationImage.onload(); }, 50);
        } else if (annotationImage.src && (!annotationImage.naturalWidth || annotationImage.naturalWidth === 0)) {
            setTimeout(() => { if (annotationImage.onerror) annotationImage.onerror(); }, 50);
        }

        // Update URL dengan base64 encoded path (pastikan btoa ada atau gunakan polyfill jika target browser lama)
        if (window.history.pushState && imageData.imagePathForCsv) {
            const newPageUrl = new URL(window.location.href);
            try {
                // imagePathForCsv adalah 'set/filename.jpg', ini yang digunakan untuk parameter URL 'image'
                // dan AnnotationController->index() sudah mengharapkan format ini untuk di-decode
                const imageParamForUrl = btoa(imageData.imagePathForCsv);
                newPageUrl.searchParams.set('image', imageParamForUrl);
                history.pushState({ path: newPageUrl.href }, '', newPageUrl.href);
            } catch (e) {
                console.error("Error base64 encoding path for URL:", e, "Input path:", imageData.imagePathForCsv);
            }
        }
    }

    function renderThumbnails(images) {
        if (!thumbnailContainer) return;
        thumbnailContainer.innerHTML = '';
        if (images.length === 0) {
            thumbnailContainer.innerHTML = '<p class="text-muted small w-100 text-center my-auto">Tidak ada gambar di halaman ini.</p>';
            return;
        }
        images.forEach(imgData => {
            const img = document.createElement('img');
            img.src = imgData.thumbnailUrl; img.alt = `Thumbnail ${imgData.filename}`; img.title = `${imgData.filename} (${imgData.set})`;
            img.className = 'gallery-thumbnail clickable'; img.dataset.imagePath = imgData.relativePath; img.loading = 'lazy';
            if (imgData.relativePath === currentImagePath) { img.classList.add('active-thumb'); }
            img.addEventListener('click', handleThumbnailClick);
            thumbnailContainer.appendChild(img);
        });
    }

    function highlightActiveThumbnail(activeS3Path) { // Parameter diubah untuk kejelasan
        if (!thumbnailContainer) return;
        thumbnailContainer.querySelectorAll('.gallery-thumbnail').forEach(thumb => {
            thumb.classList.toggle('active-thumb', thumb.dataset.imagePath === activeS3Path);
        });
    }

    function updateGalleryInfo(currentPage, totalPages, totalImages) {
        if (!currentPageDisplay || !totalPagesDisplay || !totalImagesDisplay) return;
        currentPageDisplay.textContent = currentPage; totalPagesDisplay.textContent = totalPages; totalImagesDisplay.textContent = totalImages;
        if (galleryInfoDiv) { galleryInfoDiv.dataset.currentPage = currentPage; galleryInfoDiv.dataset.totalPages = totalPages; }
    }

    function updatePaginationButtons() {
        if (!prevPageBtn || !nextPageBtn) return;
        prevPageBtn.disabled = galleryCurrentPage <= 1;
        nextPageBtn.disabled = galleryCurrentPage >= galleryTotalPages;
    }

    function drawPrefilledBbox(bboxRel) {
        updateImageRect(); // Pastikan imageRect terbaru
        if (!imageRect || !bboxOverlay) {
            console.warn("Cannot draw prefilled BBox: imageRect or bboxOverlay is not available.");
            return;
        }
        const displayW = imageRect.width;
        const displayH = imageRect.height;

        const absWidthPx = bboxRel.w * displayW;
        const absHeightPx = bboxRel.h * displayH;
        const absLeftPx = (bboxRel.cx - bboxRel.w / 2.0) * displayW;
        const absTopPx = (bboxRel.cy - bboxRel.h / 2.0) * displayH;

        if (absWidthPx < minBoxSize || absHeightPx < minBoxSize) {
            console.warn("Estimated bbox is too small to draw:", { absWidthPx, absHeightPx });
            showNotification("Estimasi area melon terlalu kecil untuk ditampilkan.", "warning", prefillNotificationArea);
            return;
        }

        const newId = nextBoxId++;
        const newBox = document.createElement('div');
        newBox.classList.add('bbox-div');
        newBox.dataset.id = newId;
        newBox.style.left = absLeftPx + 'px';
        newBox.style.top = absTopPx + 'px';
        newBox.style.width = absWidthPx + 'px';
        newBox.style.height = absHeightPx + 'px';

        bboxOverlay.appendChild(newBox);

        const newAnno = {
            id: newId,
            cx: parseFloat(bboxRel.cx.toFixed(6)),
            cy: parseFloat(bboxRel.cy.toFixed(6)),
            w: parseFloat(bboxRel.w.toFixed(6)),
            h: parseFloat(bboxRel.h.toFixed(6)),
            ripeness: null,
            element: newBox
        };
        annotations.push(newAnno);
        // Tidak perlu addResizeHandles dan selectAnnotation di sini, karena akan dilakukan oleh selectAnnotation jika dipanggil
        // Biarkan selectAnnotation yang mengelola tampilan selected dan handles
        renderBboxList();
        validateAndEnableSave();
        console.log("Prefilled annotation added:", newAnno);
    }

    function handleThumbnailClick(event) {
        const clickedThumb = event.target.closest('.gallery-thumbnail');
        if (!clickedThumb) return;
        const imagePath = clickedThumb.dataset.imagePath;
        if (imagePath && imagePath !== currentImagePath) {
            // Reset pilihan deteksi agar user memilih lagi untuk gambar baru
            detectionRadios.forEach(radio => radio.checked = false);
            if (melonAnnotationArea) melonAnnotationArea.classList.add('hidden');
            resetAnnotationState(true); // Clear pilihan deteksi dan bbox
            fetchImageData(imagePath);
        } else if (imagePath === currentImagePath) {
            console.log("Clicked the already active thumbnail.");
        }
    }

    function handleMouseDown(e) {
        if (e.button !== 0) return; // Hanya tombol kiri mouse
        updateImageRect();
        if (!imageRect) return;

        const target = e.target;
        const relativePos = getRelativeCoords(e);
        if (!relativePos) return;

        const detectionChoiceChecked = document.querySelector('input[name="detection_choice"]:checked');

        if (target.classList.contains('bbox-handle')) {
            e.stopPropagation();
            const handleType = target.dataset.handleType;
            const boxElement = target.closest('.bbox-div');
            if (boxElement && boxElement.classList.contains('selected')) {
                interactionState.type = 'resize';
                interactionState.targetBox = boxElement;
                interactionState.handleType = handleType;
                interactionState.startX = relativePos.x;
                interactionState.startY = relativePos.y;
                interactionState.initialBoxRect = { left: parseFloat(boxElement.style.left), top: parseFloat(boxElement.style.top), width: parseFloat(boxElement.style.width), height: parseFloat(boxElement.style.height) };
                boxElement.classList.add('resizing');
                document.addEventListener('mousemove', handleMouseMove);
                document.addEventListener('mouseup', handleMouseUp);
                console.log("Start resizing:", handleType);
            }
        } else if (target.classList.contains('bbox-div') && target.classList.contains('selected')) {
            e.stopPropagation();
            interactionState.type = 'move';
            interactionState.targetBox = target;
            interactionState.handleType = null; // Tidak ada handle spesifik untuk move
            interactionState.startX = relativePos.x;
            interactionState.startY = relativePos.y;
            interactionState.initialBoxRect = { left: parseFloat(target.style.left), top: parseFloat(target.style.top), width: parseFloat(target.style.width), height: parseFloat(target.style.height) };
            target.classList.add('moving');
            document.addEventListener('mousemove', handleMouseMove);
            document.addEventListener('mouseup', handleMouseUp);
            console.log("Start moving");
        } else if ((target === annotationImage || target === bboxOverlay) && detectionChoiceChecked && detectionChoiceChecked.value === 'melon') {
            // Memulai menggambar Bbox baru
            interactionState.type = 'draw';
            interactionState.startX = relativePos.x;
            interactionState.startY = relativePos.y;

            const newBox = document.createElement('div');
            newBox.classList.add('bbox-div', 'drawing');
            newBox.style.left = interactionState.startX + 'px';
            newBox.style.top = interactionState.startY + 'px';
            newBox.style.width = '0px';
            newBox.style.height = '0px';
            if (bboxOverlay) bboxOverlay.appendChild(newBox);
            interactionState.targetBox = newBox;

            document.addEventListener('mousemove', handleMouseMove);
            document.addEventListener('mouseup', handleMouseUp);
            console.log("Start drawing");
        } else if (target === annotationImage || target === annotationContainer || (bboxOverlay && target === bboxOverlay.parentElement) || target === activeImagePathSpan.parentElement) {
            // Deselect jika klik di area kosong di dalam container anotasi atau di overlay judul
            if (!target.closest('.bbox-div') && !target.closest('.bbox-handle')) {
                selectAnnotation(-1);
            }
        }
    }

    function handleMouseMove(e) {
        if (interactionState.type === 'none' || !imageRect) return;
        const relativePos = getRelativeCoords(e);
        if (!relativePos) return;

        const box = interactionState.targetBox;
        const initial = interactionState.initialBoxRect; // Untuk move dan resize

        if (interactionState.type === 'draw' && box) {
            const x = Math.min(interactionState.startX, relativePos.x);
            const y = Math.min(interactionState.startY, relativePos.y);
            const width = Math.abs(relativePos.x - interactionState.startX);
            const height = Math.abs(relativePos.y - interactionState.startY);
            box.style.left = x + 'px';
            box.style.top = y + 'px';
            box.style.width = width + 'px';
            box.style.height = height + 'px';
        } else if (interactionState.type === 'move' && box && initial) {
            const dx = relativePos.x - interactionState.startX;
            const dy = relativePos.y - interactionState.startY;
            let newLeft = initial.left + dx;
            let newTop = initial.top + dy;
            // Batasi pergerakan Bbox di dalam gambar
            newLeft = Math.max(0, Math.min(newLeft, imageRect.width - initial.width));
            newTop = Math.max(0, Math.min(newTop, imageRect.height - initial.height));
            box.style.left = newLeft + 'px';
            box.style.top = newTop + 'px';
        } else if (interactionState.type === 'resize' && box && initial) {
            let newLeft = initial.left;
            let newTop = initial.top;
            let newWidth = initial.width;
            let newHeight = initial.height;
            const dx = relativePos.x - interactionState.startX; // Perubahan X dari start resize
            const dy = relativePos.y - interactionState.startY; // Perubahan Y dari start resize

            if (interactionState.handleType.includes('n')) { newHeight = initial.height - dy; newTop = initial.top + dy; }
            if (interactionState.handleType.includes('s')) { newHeight = initial.height + dy; }
            if (interactionState.handleType.includes('w')) { newWidth = initial.width - dx; newLeft = initial.left + dx; }
            if (interactionState.handleType.includes('e')) { newWidth = initial.width + dx; }

            // Jaga ukuran minimum dan batas gambar
            if (newWidth < minBoxSize) {
                if (interactionState.handleType.includes('w')) newLeft = initial.left + initial.width - minBoxSize;
                newWidth = minBoxSize;
            }
            if (newHeight < minBoxSize) {
                if (interactionState.handleType.includes('n')) newTop = initial.top + initial.height - minBoxSize;
                newHeight = minBoxSize;
            }

            // Cek batas gambar
            if (newLeft < 0) { newWidth += newLeft; newLeft = 0; } // newWidth akan berkurang
            if (newTop < 0) { newHeight += newTop; newTop = 0; } // newHeight akan berkurang
            if (newLeft + newWidth > imageRect.width) { newWidth = imageRect.width - newLeft; }
            if (newTop + newHeight > imageRect.height) { newHeight = imageRect.height - newTop; }

            // Pastikan width dan height tidak negatif setelah penyesuaian batas
            newWidth = Math.max(minBoxSize, newWidth);
            newHeight = Math.max(minBoxSize, newHeight);


            box.style.left = newLeft + 'px';
            box.style.top = newTop + 'px';
            box.style.width = newWidth + 'px';
            box.style.height = newHeight + 'px';
        }
    }

    function handleMouseUp(e) {
        if (interactionState.type === 'none') return;
        const box = interactionState.targetBox;

        if (interactionState.type === 'draw' && box) {
            box.classList.remove('drawing');
            const finalWidthPx = parseFloat(box.style.width);
            const finalHeightPx = parseFloat(box.style.height);
            if (finalWidthPx < minBoxSize || finalHeightPx < minBoxSize) {
                box.remove(); // Hapus jika terlalu kecil
            } else {
                const newId = nextBoxId++;
                box.dataset.id = newId;
                const newAnno = { id: newId, cx: 0, cy: 0, w: 0, h: 0, ripeness: null, element: box };
                annotations.push(newAnno);
                updateAnnotationCoords(newId); // Hitung koordinat relatif
                addResizeHandles(box);       // Tambahkan handle setelah ukuran final
                selectAnnotation(newId);     // Pilih Bbox baru
                console.log("New annotation added:", newAnno);
            }
        } else if ((interactionState.type === 'move' || interactionState.type === 'resize') && box) {
            box.classList.remove('moving', 'resizing');
            const boxId = parseInt(box.dataset.id);
            updateAnnotationCoords(boxId); // Hitung ulang koordinat relatif setelah move/resize
            console.log(`Finished ${interactionState.type} Box ID:`, boxId);
        }

        interactionState.type = 'none';
        interactionState.targetBox = null;
        interactionState.handleType = null;
        interactionState.initialBoxRect = null;
        document.removeEventListener('mousemove', handleMouseMove);
        document.removeEventListener('mouseup', handleMouseUp);
        validateAndEnableSave(); // Validasi ulang setelah interaksi selesai
    }

    // --- Event Listeners ---
    detectionRadios.forEach(radio => {
        radio.addEventListener('change', function () {
            const isMelon = this.value === 'melon';
            console.log("Pilihan Deteksi diubah. Apakah Melon:", isMelon); // Log standar
            if (melonAnnotationArea) melonAnnotationArea.classList.toggle('hidden', !isMelon);

            annotations.forEach(anno => anno.element?.remove());
            annotations = [];
            nextBoxId = 0;
            selectAnnotation(-1);
            renderBboxList();

            if (isMelon) {
                // !!! Log currentImagePath DI SINI sebelum memanggil requestBboxEstimation !!!
                console.log("[Annotation JS] Radio 'Melon' dipilih, currentImagePath saat ini:", currentImagePath);
                if (currentImagePath) {
                    requestBboxEstimation();
                } else {
                    console.warn("[Annotation JS] Tidak bisa estimasi BBox: currentImagePath belum terdefinisi.");
                    if (prefillNotificationArea) showNotification("Tidak ada gambar aktif untuk estimasi BBox.", "warning", prefillNotificationArea);
                }
            } else {
                if (prefillNotificationArea) prefillNotificationArea.innerHTML = '';
                console.log("Pilihan Deteksi diubah ke NON-MELON.");
            }
            validateAndEnableSave();
        });
    });

    ripenessRadios.forEach(radio => {
        radio.addEventListener('change', function () { if (selectedBoxId !== -1) { updateAnnotationRipeness(selectedBoxId, this.value); } });
    });

    if (thumbnailContainer) { thumbnailContainer.addEventListener('click', handleThumbnailClick); }
    if (prevPageBtn) { prevPageBtn.addEventListener('click', () => { if (galleryCurrentPage > 1) { fetchGalleryPage(galleryCurrentPage - 1); } }); }
    if (nextPageBtn) { nextPageBtn.addEventListener('click', () => { if (galleryCurrentPage < galleryTotalPages) { fetchGalleryPage(galleryCurrentPage + 1); } }); }

    // Listener mousedown utama di annotationContainer untuk menangani klik pada gambar atau overlay bbox
    if (annotationContainer) { annotationContainer.addEventListener('mousedown', handleMouseDown); }


    // *** INI BAGIAN KRUSIAL YANG DITAMBAHKAN/DIPERBAIKI ***
    if (annotationForm) {
        annotationForm.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (saveButton) saveButton.disabled = true;
            showLoadingIndicator(true, "Menyimpan anotasi...");

            // validateAndEnableSave() seharusnya sudah mengisi annotationsJsonInput.value
            // jadi kita bisa langsung ambil dari sana.
            // Jika belum, pastikan dipanggil:
            // validateAndEnableSave(); // Panggil sekali lagi untuk memastikan nilai terbaru

            const formData = new FormData(this); // 'this' merujuk ke form

            try {
                const response = await fetch(this.action, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                        // FormData akan mengatur Content-Type yang sesuai secara otomatis
                    },
                    body: formData
                });

                if (!response.ok) {
                    let errorDataFromServer = { success: false, message: `Gagal menyimpan. Status: ${response.status}` };
                    try {
                        // Backend mungkin mengirim JSON error
                        const errorJson = await response.json();
                        errorDataFromServer = { ...errorDataFromServer, ...errorJson };
                    } catch (e) {
                        // Jika respons bukan JSON, pesan status sudah cukup
                    }
                    throw errorDataFromServer;
                }

                const dataFromServer = await response.json();

                if (dataFromServer.success) {
                    showNotification(dataFromServer.message || 'Anotasi berhasil disimpan.', 'success');

                    if (dataFromServer.annotation_complete) {
                        console.log("Semua anotasi telah selesai!");
                        const mainPageContent = document.getElementById('annotation-page-container');
                        const dashboardUrl = mainPageContent.dataset.dashboardUrl || (window.melonDashboardUrl || '/melon'); // Fallback jika ada
                        const evaluationUrl = mainPageContent.dataset.evaluationUrl || (window.evaluationUrl || '/evaluate'); // Fallback jika ada

                        const elementsToRemove = mainPageContent.querySelectorAll('h1, .alert.alert-info, .card.shadow.mb-4, #gallery-controls, #thumbnail-container');
                        elementsToRemove.forEach(el => el.remove());

                        const completionHtml = `
                            <div class="card shadow mt-4">
                                <div class="card-body text-center">
                                    <div class="alert alert-success mb-4">
                                        <h4 class="alert-heading"><i class="fas fa-check-circle me-2"></i>Anotasi Selesai!</h4>
                                        <p class="mb-0">${dataFromServer.message || 'Semua gambar dalam dataset telah berhasil dianotasi.'}</p>
                                        ${dataFromServer.pending_annotations_count > 0 ? `<p class="small mt-2">Masih ada ${dataFromServer.pending_annotations_count} anotasi pending dari feedback.</p>` : ''}
                                    </div>
                                    <a href="${dashboardUrl}" class="btn btn-primary">
                                        <i class="fas fa-arrow-left me-1"></i> Kembali ke Dashboard
                                    </a>
                                    <a href="${evaluationUrl}" class="btn btn-outline-secondary ms-2">
                                        Lihat Evaluasi <i class="fas fa-arrow-right me-1"></i>
                                    </a>
                                </div>
                            </div>`;
                        mainPageContent.insertAdjacentHTML('beforeend', completionHtml);
                        // Sembunyikan notifikasi umum jika ada, karena sudah ada pesan selesai
                        if (notificationArea && notificationArea.firstChild) {
                            notificationArea.firstChild.remove();
                        }

                        // --- MULAI PERUBAHAN DI SINI ---
                    } else if (dataFromServer.next_image_data &&
                        dataFromServer.next_image_data.imageUrl &&
                        dataFromServer.next_image_data.filename &&
                        dataFromServer.next_image_data.datasetSet &&
                        dataFromServer.next_image_data.imagePathForCsv &&
                        dataFromServer.next_image_data.s3Path) {

                        const nextImageDataToLoad = dataFromServer.next_image_data;
                        console.log("Data gambar berikutnya diterima, membersihkan BBox lama:", nextImageDataToLoad);

                        // !!! LANGKAH BARU: Bersihkan BBox LAMA secara eksplisit SEBELUM memuat gambar baru !!!
                        if (annotations && typeof annotations.forEach === 'function') {
                            annotations.forEach(anno => {
                                if (anno.element && typeof anno.element.remove === 'function') {
                                    anno.element.remove(); // Hapus elemen BBox dari DOM
                                }
                            });
                        }
                        annotations = []; // Kosongkan array data anotasi
                        nextBoxId = 0;    // Reset ID counter untuk BBox baru
                        selectAnnotation(-1); // Deselect BBox apapun yang mungkin terpilih & update UI terkait BBox
                        renderBboxList();    // Perbarui tampilan daftar BBox (akan menunjukkan pesan "Belum ada Bbox")

                        // Eksplisit update UI yang berkaitan dengan BBox dan anotasi
                        if (bboxCountSpan) bboxCountSpan.textContent = '0';
                        if (selectedBboxIndexSpan) selectedBboxIndexSpan.textContent = '-';
                        if (prefillNotificationArea) prefillNotificationArea.innerHTML = ''; // Bersihkan notif prefill BBox lama
                        if (annotationsJsonInput) annotationsJsonInput.value = '[]'; // Reset JSON anotasi

                        // Reset pilihan deteksi juga di sini, sebelum memuat gambar baru,
                        // agar requestBboxEstimation tidak terpicu dengan konteks lama jika radio 'Melon' sudah terpilih.
                        detectionRadios.forEach(radio => radio.checked = false);
                        if (melonAnnotationArea) melonAnnotationArea.classList.add('hidden');
                        if (ripenessOptionsDiv) ripenessOptionsDiv.classList.add('hidden');
                        disableRipenessRadios();
                        // !!! AKHIR LANGKAH BARU !!!

                        updateMainImage(nextImageDataToLoad); // Panggil untuk memuat gambar berikutnya

                        // Update galeri dan pending count (ini sudah ada)
                        if (typeof fetchGalleryPage === "function" && typeof galleryCurrentPage !== 'undefined') {
                            fetchGalleryPage(galleryCurrentPage);
                        }
                        const pendingCountDisplay = document.getElementById('pending-annotation-count-display');
                        if (pendingCountDisplay && typeof dataFromServer.pending_annotations_count === 'number') {
                            pendingCountDisplay.textContent = dataFromServer.pending_annotations_count;
                        }

                    } else {
                        // Blok ini seharusnya jarang sekali dimasuki jika backend selalu konsisten
                        // mengirim 'annotation_complete: true' atau 'next_image_data' yang valid.
                        console.warn("Respons server setelah simpan anotasi tidak mengandung data gambar berikutnya yang valid atau status selesai yang jelas:", dataFromServer);
                        showNotification('Anotasi disimpan, tapi ada masalah saat memuat data gambar berikutnya. Silakan muat ulang atau pilih dari galeri.', 'warning', notificationArea, 7000);
                    }
                    // --- AKHIR PERUBAHAN ---
                } else {
                    // Jika backend mengirim success: false
                    let errorMsg = dataFromServer.message || 'Gagal menyimpan anotasi.';
                    if (dataFromServer.errors) {
                        errorMsg += " Detail: " + Object.values(dataFromServer.errors).map(err => err.join(', ')).join('; ');
                    }
                    showNotification(errorMsg, 'danger');
                }

            } catch (error) {
                console.error('Error saat menyimpan anotasi:', error);
                let errorMessageToShow = "Terjadi kesalahan teknis saat menyimpan.";
                if (error && error.message) {
                    errorMessageToShow = error.message;
                }
                showNotification(`Gagal menyimpan: ${errorMessageToShow}`, 'danger');
            } finally {
                if (saveButton) saveButton.disabled = false; // Aktifkan kembali setelah selesai
                showLoadingIndicator(false);
                // Panggil validateAndEnableSave() setelah semua proses selesai dan UI direset (jika perlu)
                // untuk memastikan tombol simpan memiliki state yang benar untuk anotasi berikutnya.
                // Ini akan dipanggil secara otomatis oleh interaksi lain (seperti perubahan radio).
                validateAndEnableSave();
            }
        });
    }

    // Tambahkan listener untuk tombol clear cache jika ada di halaman anotasi
    const clearCacheButtonOnAnnotate = document.getElementById('clear-app-cache-btn'); // Sesuaikan ID jika berbeda
    if (clearCacheButtonOnAnnotate && clearCacheUrlForAnnotate) {
        clearCacheButtonOnAnnotate.addEventListener('click', async () => {
            if (!confirm("Anda yakin ingin membersihkan cache aplikasi dan file sementara?")) return;
            showLoadingIndicator(true, "Membersihkan cache...");
            try {
                const response = await fetch(clearCacheUrlForAnnotate, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
                });
                const data = await response.json();
                showNotification(data.message || (data.success ? "Cache berhasil dibersihkan." : "Gagal membersihkan cache."), data.success ? 'success' : 'danger', notificationArea);
            } catch (error) {
                showNotification("Error koneksi saat membersihkan cache: " + error.message, 'danger', notificationArea);
            } finally {
                showLoadingIndicator(false);
            }
        });
    }

} // Akhir fungsi initializeAnnotationTool

// Panggil fungsi inisialisasi setelah DOM siap
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeAnnotationTool);
} else {
    initializeAnnotationTool();
}
