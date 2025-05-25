# estimate_bbox.py (Prioritas Satu BBox Terbaik)
import cv2
import numpy as np
import sys
import json
import os
import traceback

def eprint(*args, **kwargs):
    print(*args, file=sys.stderr, **kwargs)

def calculate_iou(boxA, boxB):
    """ Menghitung Intersection over Union (IoU) antara dua bounding box.
        Format box: {'x', 'y', 'w', 'h'}
    """
    xA = max(boxA['x'], boxB['x'])
    yA = max(boxA['y'], boxB['y'])
    xB = min(boxA['x'] + boxA['w'], boxB['x'] + boxB['w'])
    yB = min(boxA['y'] + boxA['h'], boxB['y'] + boxB['h'])
    interArea = max(0, xB - xA) * max(0, yB - yA)
    boxAArea = boxA['w'] * boxA['h']
    boxBArea = boxB['w'] * boxB['h']
    denominator = float(boxAArea + boxBArea - interArea)
    if denominator == 0: return 0.0
    iou = interArea / denominator
    return iou

def non_max_suppression(boxes, scores, threshold):
    """ Melakukan Non-Maximum Suppression.
        Mengembalikan list box yang dipilih, diurutkan berdasarkan skor (menurun).
    """
    if not boxes: return []
    if not scores: return []
    if len(boxes) != len(scores):
        eprint(f"NMS Error: Length mismatch between boxes ({len(boxes)}) and scores ({len(scores)})")
        return []

    # Konversi ke numpy array untuk kemudahan operasi
    np_boxes = np.array([[b['x'], b['y'], b['x'] + b['w'], b['y'] + b['h']] for b in boxes], dtype="float")
    np_scores = np.array(scores, dtype="float")

    # Ambil koordinat bounding box
    x1 = np_boxes[:,0]
    y1 = np_boxes[:,1]
    x2 = np_boxes[:,2]
    y2 = np_boxes[:,3]

    # Hitung area bounding box
    areas = (x2 - x1) * (y2 - y1)

    # Urutkan bounding box berdasarkan skor (dari tertinggi ke terendah)
    order = np_scores.argsort()[::-1]

    keep_indices = []
    while order.size > 0:
        # Ambil indeks dengan skor tertinggi
        i = order[0]
        keep_indices.append(i)

        # Hitung koordinat intersection
        xx1 = np.maximum(x1[i], x1[order[1:]])
        yy1 = np.maximum(y1[i], y1[order[1:]])
        xx2 = np.minimum(x2[i], x2[order[1:]])
        yy2 = np.minimum(y2[i], y2[order[1:]])

        # Hitung lebar dan tinggi intersection
        w = np.maximum(0.0, xx2 - xx1)
        h = np.maximum(0.0, yy2 - yy1)

        # Hitung IoU
        inter = w * h
        iou = inter / (areas[i] + areas[order[1:]] - inter + 1e-6) # Tambah epsilon untuk menghindari pembagian dengan nol

        # Simpan indeks yang memiliki IoU kurang dari threshold
        inds_to_keep = np.where(iou <= threshold)[0]
        order = order[inds_to_keep + 1] # +1 karena iou dihitung dari order[1:]

    # Kembalikan bounding box asli yang terpilih, dalam format dictionary
    picked_boxes_original_format = [boxes[idx] for idx in keep_indices]
    return picked_boxes_original_format


def estimate_best_melon_bbox(image_path):
    """
    Mencoba mengestimasi SATU bounding box melon TERBAIK dalam gambar.
    """
    initial_valid_bboxes = []
    initial_scores = [] # Skor bisa berupa area atau confidence dari metode lain
    try:
        if not os.path.exists(image_path):
            eprint(f"Error: Image path not found: {image_path}")
            return None # Mengembalikan None jika gambar tidak ditemukan

        img = cv2.imread(image_path)
        if img is None:
            eprint(f"Error: Could not read image from path: {image_path}")
            return None # Mengembalikan None jika gambar tidak bisa dibaca

        height, width = img.shape[:2]
        image_area = float(width * height)
        if image_area == 0:
            eprint(f"Error: Image has zero area: {image_path}")
            return None

        gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
        blurred = cv2.GaussianBlur(gray, (5, 5), 0)
        thresh = cv2.adaptiveThreshold(blurred, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
                                       cv2.THRESH_BINARY_INV, 11, 2)
        eprint(f"--- [PYTHON DEBUG] Adaptive threshold applied for {image_path} ---")

        kernel_open = np.ones((3, 3), np.uint8)
        kernel_close = np.ones((9, 9), np.uint8) # Kernel besar untuk menutup lubang

        mask_morphed = cv2.morphologyEx(thresh, cv2.MORPH_OPEN, kernel_open, iterations=1)
        mask_morphed = cv2.morphologyEx(mask_morphed, cv2.MORPH_CLOSE, kernel_close, iterations=1)
        eprint(f"--- [PYTHON DEBUG] Morphology applied. Non-zero pixels: {cv2.countNonZero(mask_morphed)} ---")

        contours, _ = cv2.findContours(mask_morphed, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

        if not contours:
            eprint("--- [PYTHON DEBUG] No contours found. ---")
            return None

        # Filter parameter (bisa disesuaikan)
        min_contour_area_ratio = 0.01  # Minimal 1% dari area gambar
        max_contour_area_ratio = 0.90  # Maksimal 90%
        min_aspect_ratio = 0.3
        max_aspect_ratio = 3.0
        min_solidity = 0.7 # Bentuk harus cukup solid (mengisi area convex hull-nya)
                           # Solidity adalah area kontur / area convex hull kontur

        count_before_filter = len(contours)

        for contour in contours:
            area = cv2.contourArea(contour)
            if area < 20: continue # Abaikan kontur yang sangat kecil

            area_ratio = area / image_area
            if area_ratio < min_contour_area_ratio or area_ratio > max_contour_area_ratio:
                continue

            x, y, w, h = cv2.boundingRect(contour)
            if w <= 0 or h <= 0: continue

            aspect_ratio_val = float(h) / w # Tinggi / Lebar
            if aspect_ratio_val < min_aspect_ratio or aspect_ratio_val > max_aspect_ratio:
                continue

            # Hitung solidity
            hull = cv2.convexHull(contour)
            hull_area = cv2.contourArea(hull)
            if hull_area == 0: solidity = 0
            else: solidity = float(area) / hull_area

            if solidity < min_solidity:
                continue

            current_bbox = {"x": float(x), "y": float(y), "w": float(w), "h": float(h)}
            initial_valid_bboxes.append(current_bbox)
            # Skor bisa lebih canggih, misal kombinasi area dan solidity, atau jarak ke tengah
            # Untuk saat ini, area masih menjadi skor utama untuk NMS
            initial_scores.append(area * solidity) # Memberi bobot pada solidity

        eprint(f"--- [PYTHON DEBUG] Contours found: {count_before_filter}, Passed filters: {len(initial_valid_bboxes)} ---")

        if not initial_valid_bboxes:
            eprint("--- [PYTHON DEBUG] No bboxes passed initial filtering. ---")
            return None

        # Non-Maximum Suppression
        iou_threshold = 0.3 # Jika ada beberapa kandidat yang tumpang tindih
        eprint(f"--- [PYTHON DEBUG] Bboxes before NMS ({len(initial_valid_bboxes)}): {initial_valid_bboxes} ---")

        # NMS akan mengurutkan berdasarkan skor (area * solidity)
        # dan menghilangkan yang tumpang tindih
        surviving_bboxes = non_max_suppression(initial_valid_bboxes, initial_scores, iou_threshold)
        eprint(f"--- [PYTHON DEBUG] Bboxes after NMS ({len(surviving_bboxes)}): {surviving_bboxes} ---")

        if not surviving_bboxes:
            eprint("--- [PYTHON DEBUG] No bboxes survived NMS. ---")
            return None

        # Kembalikan HANYA bounding box pertama (terbaik setelah NMS)
        best_bbox = surviving_bboxes[0]
        eprint(f"--- [PYTHON DEBUG] Best BBox selected: {best_bbox} ---")
        return best_bbox # Mengembalikan satu dictionary bbox, atau None

    except Exception as e:
        eprint(f"Python Error in estimate_best_melon_bbox: {str(e)}")
        eprint(traceback.format_exc())
        return None # Mengembalikan None jika terjadi error

if __name__ == "__main__":
    if len(sys.argv) != 2:
        eprint("Usage: python estimate_bbox.py <image_path>")
        sys.exit(1)

    image_file_path = sys.argv[1]

    # Panggil fungsi yang baru
    best_bbox_result = estimate_best_melon_bbox(image_file_path)

    # Struktur output JSON diubah untuk konsistensi:
    # 'success' akan true jika best_bbox_result bukan None
    # 'bboxes' akan menjadi array berisi satu bbox jika ditemukan, atau array kosong jika tidak.
    if best_bbox_result:
        output_data = {"success": True, "bboxes": [best_bbox_result]}
        eprint(f"--- [PYTHON DEBUG] Final JSON output (success): {output_data} ---")
    else:
        output_data = {"success": True, "bboxes": []} # Tetap success=True, tapi bboxes kosong
        eprint(f"--- [PYTHON DEBUG] Final JSON output (no bbox found): {output_data} ---")

    print(json.dumps(output_data))
    sys.stdout.flush()
