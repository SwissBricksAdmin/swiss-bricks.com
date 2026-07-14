<?php
function getActiveCurrency($conn) {
    $r = $conn->query("SELECT setting_value FROM settings WHERE setting_key='active_currency' LIMIT 1");
    return ($r && $r->num_rows) ? $r->fetch_row()[0] : 'CHF';
}

function formatPrice($price, $conn = null) {
    return 'CHF&nbsp;' . number_format((float)$price, 2, '.', "'");
}

function formatPricePlain($price) {
    return 'CHF ' . number_format((float)$price, 2, '.', "'");
}
