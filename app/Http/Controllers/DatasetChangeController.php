<?php
namespace App\Http\Controllers;

use App\Services\DatasetChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
// Pastikan di-import

class DatasetChangeController extends Controller
{
    protected DatasetChangeService $datasetChangeService;

    public function __construct(DatasetChangeService $datasetChangeService)
    {
        $this->datasetChangeService = $datasetChangeService;
    }

    public function markAsSeen(Request $request): JsonResponse
    {
        // Meskipun tidak ada data dari request yang digunakan, parameter Request tetap ada
        // untuk memastikan method signature cocok dengan ekspektasi Laravel untuk controller actions.
        // Anda bisa menambahkan validasi jika $request->ajax() diperlukan.
        $this->datasetChangeService->markChangesAsSeen();
        return response()->json(['success' => true, 'message' => 'Notifikasi perubahan dataset telah ditandai sudah dilihat.']);
    }
}
