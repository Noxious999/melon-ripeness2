{{-- resources/views/partials/confusion-matrix-table.blade.php --}}
{{-- Partial untuk menampilkan tabel Confusion Matrix di halaman web --}}

@php
    // Variabel yang di-pass:
    // $tp (int)
    // $fn (int)
    // $fp (int)
    // $tn (int)
    // $posLabel (string, e.g., 'Matang' atau 'Melon')
    // $negLabel (string, e.g., 'Belum Matang' atau 'Non-Melon')
@endphp

<div class="table-responsive confusion-matrix-table-wrapper-web">
    <table class="table table-bordered cm-table cm-table-web text-center align-middle caption-top">
        <caption class="text-center text-muted small mb-1">
            <i class="fas fa-th-large me-1"></i>Matriks Konfusi
        </caption>
        <thead>
            <tr>
                <td rowspan="2" colspan="1" class="cm-corner align-middle">
                </td>
                <th rowspan="2" colspan="1" scope="col" class="cm-header-predicted"><small
                        class="text-muted fst-italic fw-bold"
                        style="writing-mode: horizontal-rl; white-space: nowrap;">Prediksi</small>
                </th>
                <th scope="col" class="cm-label-positive header-positive" data-bs-toggle="tooltip"
                    data-bs-placement="top" title="{{ $posLabel }}/Matang | Kelas Positif (Prediksi)">
                    <i class="fas fa-check-circle me-1 icon-positive"></i>{{ $posLabel }}
                </th>
                <th scope="col" class="cm-label-negative header-negative" data-bs-toggle="tooltip"
                    data-bs-placement="top" title="{{ $negLabel }}/Belum_Matang | Kelas Negatif (Prediksi)">
                    <i class="fas fa-times-circle me-1 icon-negative"></i>{{ $negLabel }}
                </th>
            </tr>
        </thead>
        <tbody>
            <tr>

                <th rowspan="2" scope="rowgroup" class="cm-header-actual align-middle">
                    <small class="text-muted fst-italic"
                        style="writing-mode: vertical-rl; transform: rotate(180deg); white-space: nowrap;">Aktual</small>
                </th>
                <th scope="row" class="cm-label-positive label-positive" data-bs-toggle="tooltip"
                    data-bs-placement="left" title="{{ $posLabel }}/Matang | Kelas Positif (Aktual)">
                    <i class="fas fa-check-circle me-1 icon-positive"></i>{{ $posLabel }}
                </th>
                <td class="cm-value cm-cell-tp" data-bs-toggle="tooltip" data-bs-placement="top"
                    title="True Positive (TP): Aktual {{ $posLabel }}, Prediksi {{ $posLabel }}">
                    {{ $tp ?? 0 }}
                    <small class="cm-cell-label d-block">(TP)</small>
                </td>
                <td class="cm-value cm-cell-fn" data-bs-toggle="tooltip" data-bs-placement="top"
                    title="False Negative (FN): Aktual {{ $posLabel }}, Prediksi {{ $negLabel }}">
                    {{ $fn ?? 0 }}
                    <small class="cm-cell-label d-block">(FN)</small>
                </td>
            </tr>
            <tr>
                <th scope="row" class="cm-label-negative label-negative" data-bs-toggle="tooltip"
                    data-bs-placement="left" title="{{ $negLabel }}/Belum_Matang | Kelas Negatif (Aktual)">
                    <i class="fas fa-times-circle me-1 icon-negative"></i>{{ $negLabel }}
                </th>
                <td class="cm-value cm-cell-fp" data-bs-toggle="tooltip" data-bs-placement="top"
                    title="False Positive (FP): Aktual {{ $negLabel }}, Prediksi {{ $posLabel }}">
                    {{ $fp ?? 0 }}
                    <small class="cm-cell-label d-block">(FP)</small>
                </td>
                <td class="cm-value cm-cell-tn" data-bs-toggle="tooltip" data-bs-placement="top"
                    title="True Negative (TN): Aktual {{ $negLabel }}, Prediksi {{ $negLabel }}">
                    {{ $tn ?? 0 }}
                    <small class="cm-cell-label d-block">(TN)</small>
                </td>
            </tr>
        </tbody>
    </table>
</div>
