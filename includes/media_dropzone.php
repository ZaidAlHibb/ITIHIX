<?php

/**
 * Zona unggah foto/video untuk form laporan (dipakai upload.php & edit_report.php).
 * JS-nya ada di assets/js/app.js (blok "Form laporan"), gaya di assets/css/main.css (blok ".mz").
 */
function media_dropzone(int $max): string
{
    $h  = '<div class="mz" id="mediaZone">';
    $h .= '<label class="mz-drop" for="reportFile" id="mediaDrop">';
    $h .= '<span class="mz-ico">' . icon('image') . '</span>';
    $h .= '<span class="mz-txt"><b>Tambahkan foto atau video</b><em>Seret &amp; lepas ke sini, tempel (Ctrl+V), atau <u>telusuri dari perangkat</u></em></span>';
    $h .= '<span class="mz-btn">' . icon('plus') . ' Pilih file</span>';
    $h .= '<input type="file" id="reportFile" name="media[]" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/quicktime" multiple hidden'
        . ' data-max="' . $max . '" data-img-mb="' . (int)MAX_IMAGE_MB . '" data-vid-mb="' . (int)MAX_VIDEO_MB . '">';
    $h .= '</label>';
    $h .= '<div class="mz-rules">'
        . '<span class="mz-rule">' . icon('image') . ' Foto JPG, PNG, WebP, GIF · maks. ' . (int)MAX_IMAGE_MB . ' MB</span>'
        . '<span class="mz-rule">' . icon('video') . ' Video MP4, WebM, MOV · maks. ' . (int)MAX_VIDEO_MB . ' MB</span>'
        . '<span class="mz-rule">' . icon('paperclip') . ' Maks. ' . $max . ' file</span>'
        . '<span class="mz-rule">' . icon('pen') . ' Foto bisa dicoret, diblur &amp; dimosaik</span>'
        . '<span class="mz-rule">' . icon('shield') . ' Samarkan data pribadi</span>'
        . '</div>';
    $h .= '<div class="mz-head hidden" id="mzHead"><span class="mz-count" id="mzCount"></span><span class="mz-bar"><i id="mzBar"></i></span>'
        . '<button type="button" class="mz-clear" id="mzClear">Hapus semua</button></div>';
    $h .= '<div class="mz-grid" id="mediaPreview" aria-live="polite"></div>';
    $h .= '</div>';
    return $h;
}
