<?php
require_once __DIR__ . '/currency.php';

// ── Auth ──────────────────────────────────────────────────
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        $redirect = urlencode($_SERVER['REQUEST_URI']);
        header('Location: ' . BASE_URL . '/pages/login.php?redirect=' . $redirect);
        exit;
    }
}

function getCurrentUser($conn) {
    if (!isLoggedIn()) return null;
    $id   = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function isAdmin($conn = null) {
    if (!isLoggedIn()) return false;
    if (isset($_SESSION['user_role'])) {
        return in_array($_SESSION['user_role'], ['admin', 'super_admin']);
    }
    return false;
}

// ── Cart ──────────────────────────────────────────────────
function getCart() {
    return $_SESSION['cart'] ?? [];
}

function addToCart($product_id, $qty = 1) {
    $product_id = (int)$product_id;
    $qty        = max(1, (int)$qty);
    if (!isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id] = 0;
    }
    $_SESSION['cart'][$product_id] += $qty;
}

function updateCartQty($product_id, $qty) {
    $product_id = (int)$product_id;
    $qty        = (int)$qty;
    if ($qty <= 0) {
        unset($_SESSION['cart'][$product_id]);
    } else {
        $_SESSION['cart'][$product_id] = min($qty, 99);
    }
}

function removeFromCart($product_id) {
    unset($_SESSION['cart'][(int)$product_id]);
}

function cleanCart($conn) {
    if (empty($_SESSION['cart'])) return;
    $ids  = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
    $rows = $conn->query("SELECT id FROM products WHERE id IN ($ids)");
    $valid = [];
    while ($row = $rows->fetch_assoc()) $valid[] = (int)$row['id'];
    foreach (array_keys($_SESSION['cart']) as $id) {
        if (!in_array((int)$id, $valid)) unset($_SESSION['cart'][$id]);
    }
}

function getCartCount() {
    if (empty($_SESSION['cart'])) return 0;
    return array_sum($_SESSION['cart']);
}

function getCartTotal($conn) {
    if (empty($_SESSION['cart'])) return 0;
    $ids   = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
    $rows  = $conn->query("SELECT id, price FROM products WHERE id IN ($ids)");
    $total = 0;
    while ($row = $rows->fetch_assoc()) {
        $total += $row['price'] * ($_SESSION['cart'][$row['id']] ?? 0);
    }
    return $total;
}

function getCartItems($conn) {
    if (empty($_SESSION['cart'])) return [];
    $ids   = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
    $rows  = $conn->query("SELECT p.*, c.name AS category_name, p.weight_grams, p.length_cm, p.width_cm, p.height_cm FROM products p JOIN categories c ON p.category_id=c.id WHERE p.id IN ($ids)");
    $items = [];
    $preorders = $_SESSION['preorder_items'] ?? [];
    while ($row = $rows->fetch_assoc()) {
        $row['qty']        = $_SESSION['cart'][$row['id']];
        $row['subtotal']   = $row['price'] * $row['qty'];
        $row['is_preorder'] = isset($preorders[$row['id']]);
        $items[]           = $row;
    }
    return $items;
}

// ── Swiss Post shipping calculator ───────────────────────
function calculateShipping(array $items, string $service = 'priority'): array {
    global $conn;

    // Load rates from DB once per request, fall back to 2026 published prices
    static $dbRates = null;
    if ($dbRates === null && isset($conn)) {
        $dbRates = [];
        $res = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'shipping_%'");
        if ($res) while ($row = $res->fetch_assoc()) $dbRates[$row['setting_key']] = (float)$row['setting_value'];
    }

    $rate = function(string $key, float $fallback) use ($dbRates): float {
        return isset($dbRates[$key]) ? (float)$dbRates[$key] : $fallback;
    };

    // ── Aggregate item dimensions and weight ──────────────
    // Each item is rotated into the orientation that minimises shipping cost.
    // For letters: choose the orientation with the smallest height that still fits
    //   the B5 footprint (25 × 17.6 cm). Buffer applied later to the combined box.
    // For parcels: always put the smallest dimension as height (minimises stacked height
    //   and thus the chance of hitting the bulky-goods surcharge).
    $totalWeightG = 0;
    $missingData  = false;
    $allFitLetter = true;

    $letterFootMaxL = 0; $letterFootMaxW = 0; $letterTotalH = 0;
    $parcelFootMaxL = 0; $parcelFootMaxW = 0; $parcelTotalH = 0;

    // Swiss Post letter B5 limit (buffer added later to combined box)
    $LTR_MAX_L = 25.0;
    $LTR_MAX_W = 17.6;

    foreach ($items as $item) {
        $qty = (int)($item['qty'] ?? $item['quantity'] ?? 1);
        $d   = [(float)($item['length_cm'] ?? 0), (float)($item['width_cm'] ?? 0), (float)($item['height_cm'] ?? 0)];
        $wg  = (float)($item['weight_grams'] ?? 0);

        if (!$d[0] || !$d[1] || !$d[2] || !$wg) $missingData = true;

        $totalWeightG += $wg * $qty;
        sort($d); // d[0]=min, d[1]=mid, d[2]=max

        // Parcel: smallest dim as height, largest two as footprint
        $parcelTotalH   += $d[0] * $qty;
        $parcelFootMaxL  = max($parcelFootMaxL, $d[2]);
        $parcelFootMaxW  = max($parcelFootMaxW, $d[1]);

        // Letter: try all 3 orientations in ascending height order; pick the
        // first (thinnest) that fits within the letter footprint limit.
        // Orientations: [footL, footW, height]
        $fits = false;
        foreach ([[$d[2], $d[1], $d[0]], [$d[2], $d[0], $d[1]], [$d[1], $d[0], $d[2]]] as [$fl, $fw, $fh]) {
            if ($fl < $fw) [$fl, $fw] = [$fw, $fl]; // normalise so fl ≥ fw
            if ($fl <= $LTR_MAX_L && $fw <= $LTR_MAX_W) {
                $letterFootMaxL  = max($letterFootMaxL, $fl);
                $letterFootMaxW  = max($letterFootMaxW, $fw);
                $letterTotalH   += $fh * $qty;
                $fits = true;
                break; // thinnest valid orientation found
            }
        }
        if (!$fits) $allFitLetter = false;
    }

    $weightKg = $totalWeightG / 1000;

    // ── Letter rate path ──────────────────────────────────
    $letterPackH = $letterTotalH + 1; // +1 cm packing buffer
    $letterPackL = $letterFootMaxL + 1;
    $letterPackW = $letterFootMaxW + 1;
    if ($allFitLetter && !$missingData && $totalWeightG <= 500 && $letterPackH <= 5.0) {

        $isThick      = $letterPackH > 2.0;
        $thickSurcharge = $isThick ? $rate('shipping_letter_thick_surcharge', 2.00) : 0.0;

        if ($service === 'economy') {
            if ($totalWeightG <= 100) {
                $base = $rate('shipping_letter_standard_bmail', 1.00);
                $tier = 'Standard Letter B-Mail (≤100g)';
            } else {
                $base = $rate('shipping_letter_midi_bmail', 1.40);
                $tier = 'Midi Letter B-Mail (≤500g)';
            }
        } else {
            if ($totalWeightG <= 100) {
                $base = $rate('shipping_letter_standard_amail', 1.20);
                $tier = 'Standard Letter A-Mail (≤100g)';
            } else {
                $base = $rate('shipping_letter_midi_amail', 1.70);
                $tier = 'Midi Letter A-Mail (≤500g)';
            }
        }

        return [
            'price'        => round(($base + $thickSurcharge) / 2, 2),
            'tier'         => $tier . ($isThick ? ' + thick surcharge' : ''),
            'weight_kg'    => round($weightKg, 3),
            'dimensions'   => round($letterPackL, 1) . ' × ' . round($letterPackW, 1) . ' × ' . round($letterPackH, 1) . ' cm',
            'is_bulky'     => false,
            'is_letter'    => true,
            'missing_data' => $missingData,
        ];
    }

    // ── Parcel rate path (5cm packing buffer) ─────────────
    $packL = $parcelFootMaxL + 5;
    $packW = $parcelFootMaxW + 5;
    $packH = $parcelTotalH   + 5;
    $isBulky  = ($packL > 100 || $packW > 60 || $packH > 60);

    $parcel = [
        'priority' => [
            '2kg'   => $rate('shipping_priority_2kg',   10.50),
            '10kg'  => $rate('shipping_priority_10kg',  13.50),
            '30kg'  => $rate('shipping_priority_30kg',  22.50),
            'bulky' => $rate('shipping_priority_bulky', 32.50),
        ],
        'economy' => [
            '2kg'   => $rate('shipping_economy_2kg',    9.00),
            '10kg'  => $rate('shipping_economy_10kg',  12.00),
            '30kg'  => $rate('shipping_economy_30kg',  21.00),
            'bulky' => $rate('shipping_economy_bulky', 31.00),
        ],
    ];
    $p = $parcel[$service] ?? $parcel['priority'];

    if ($isBulky)          { $price = $p['bulky']; $tier = 'Bulky goods'; }
    elseif ($weightKg <= 2)  { $price = $p['2kg'];   $tier = 'up to 2 kg'; }
    elseif ($weightKg <= 10) { $price = $p['10kg'];  $tier = 'up to 10 kg'; }
    else                     { $price = $p['30kg'];  $tier = 'up to 30 kg'; }

    return [
        'price'        => round($price / 2, 2),
        'tier'         => $tier,
        'weight_kg'    => round($weightKg, 3),
        'dimensions'   => round($packL, 1) . ' × ' . round($packW, 1) . ' × ' . round($packH, 1) . ' cm',
        'is_bulky'     => $isBulky,
        'is_letter'    => false,
        'missing_data' => $missingData,
    ];
}

// ── Email verification ────────────────────────────────────
function sendVerificationEmail($conn, int $userId, string $toEmail, string $toName, string $mode = 'new'): bool {
    require_once __DIR__ . '/mailer.php';
    $token  = bin2hex(random_bytes(32));
    $expiry = date('Y-m-d H:i:s', time() + 86400); // 24 hours
    $conn->execute_query("UPDATE users SET verify_token=?, verify_expiry=? WHERE id=?", [$token, $expiry, $userId]);
    $link = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . BASE_URL . '/pages/verify.php?token=' . $token . '&mode=' . $mode;
    $subject = $mode === 'email' ? 'Confirm your new email - SwissBricks' : 'Verify your SwissBricks account';
    $action  = $mode === 'email' ? 'confirm your new email address' : 'verify your account';
    $html = '
    <div style="background:#0a0a0f;padding:40px 0;font-family:sans-serif;">
      <div style="max-width:520px;margin:0 auto;background:#16161f;border:1px solid #2a2a3a;border-radius:16px;overflow:hidden;">
        <div style="background:#ED1E28;padding:24px 32px;">
          <!-- ONCE LIVE: replace the span below with: <img src="https://swiss-bricks.com/assets/logo.png" alt="SwissBricks" style="height:36px;"> -->
          <span style="font-family:sans-serif;font-size:1.3rem;font-weight:800;color:#ffffff;letter-spacing:-0.02em;">Swiss<span style="opacity:0.85;">Bricks</span></span>
        </div>
        <div style="padding:32px;">
          <h2 style="color:#ffffff;margin:0 0 12px;font-size:1.3rem;">Hi ' . htmlspecialchars($toName) . ',</h2>
          <p style="color:#a0a0b8;line-height:1.7;margin:0 0 24px;">Please click the button below to ' . $action . '. This link expires in 24 hours.</p>
          <a href="' . $link . '" style="display:inline-block;background:#ED1E28;color:#ffffff;text-decoration:none;padding:14px 28px;border-radius:8px;font-weight:700;font-size:0.95rem;">Verify Email</a>
          <p style="color:#555570;font-size:0.8rem;margin:24px 0 0;">If you didn\'t create a SwissBricks account, you can safely ignore this email.</p>
        </div>
      </div>
    </div>';
    return sendMail($toEmail, $toName, $subject, $html);
}

// ── Order confirmation email ──────────────────────────────
function sendOrderConfirmationEmail(string $toEmail, string $toName, string $orderNumber, float $total, array $items, string $paymentMethod, string $shippingAddress, string $shippingMethod = '', float $shippingCost = 0): bool {
    require_once __DIR__ . '/mailer.php';

    $subject = 'Order Received: ' . $orderNumber;

    $itemRows = '';
    $hasPreorder = false;
    foreach ($items as $item) {
        $isPreorder = !empty($item['is_preorder']);
        if ($isPreorder) $hasPreorder = true;
        $preorderBadge = $isPreorder
            ? ' <span style="display:inline-block;background:rgba(237,30,40,0.15);color:#ED1E28;border:1px solid rgba(237,30,40,0.4);border-radius:4px;padding:1px 6px;font-size:0.65rem;font-weight:800;letter-spacing:0.06em;vertical-align:middle;">PRE-ORDER</span>'
            : '';
        $imgHtml = !empty($item['image_url'])
            ? '<img src="' . htmlspecialchars($item['image_url']) . '" alt="" width="48" height="48" style="width:48px;height:48px;object-fit:contain;background:#ffffff;border-radius:6px;display:block;">'
            : '';
        $itemRows .= '
        <tr>
          <td style="padding:10px 0;border-bottom:1px solid #2a2a3a;vertical-align:middle;">
            <table style="border-collapse:collapse;"><tr>
              <td style="padding:0 12px 0 0;vertical-align:middle;">' . $imgHtml . '</td>
              <td style="vertical-align:middle;color:#e0e0f0;font-size:0.9rem;">' . htmlspecialchars($item['name']) . ' &times; ' . (int)$item['qty'] . $preorderBadge . '</td>
            </tr></table>
          </td>
          <td style="padding:10px 0;border-bottom:1px solid #2a2a3a;color:#e0e0f0;font-size:0.9rem;text-align:right;vertical-align:middle;">CHF ' . number_format($item['subtotal'], 2) . '</td>
        </tr>';
    }

    $preorderBlock = $hasPreorder ? '
          <div style="margin-top:24px;padding-top:20px;border-top:1px solid #2a2a3a;">
            <div style="font-size:0.72rem;font-weight:800;letter-spacing:0.08em;text-transform:uppercase;color:#ED1E28;margin-bottom:12px;">Pre-order Information</div>
            <p style="color:#a0a0b8;font-size:0.87rem;line-height:1.7;margin:0 0 10px;">Our sets are often retired and rare to find, therefore stock can run out. By requesting and pre-ordering we will make your product a priority.</p>
            <p style="color:#a0a0b8;font-size:0.87rem;line-height:1.7;margin:0 0 10px;">A <strong style="color:#e0e0f0;">30 day period</strong> will initiate where we will maximise our efforts to re-stock. By pre-ordering you avoid missing out and you will be the first to receive the product upon re-stock!</p>
            <p style="color:#a0a0b8;font-size:0.87rem;line-height:1.7;margin:0 0 10px;">Often we are only able to re-stock a handful of sets and you can miss out if not pre-ordering.</p>
            <p style="color:#777;font-size:0.82rem;line-height:1.6;margin:0;">If we are unable to restock within this period you will <strong style="color:#a0a0b8;">automatically be refunded</strong>. Thank you for shopping with us &#128578;</p>
          </div>' : '';

    $paymentNote = $paymentMethod === 'Bank Transfer'
        ? '<p style="color:#a0a0b8;font-size:0.85rem;margin:16px 0 0;padding:12px 16px;background:#1e1e2e;border-radius:8px;border-left:3px solid #ED1E28;">Please transfer CHF ' . number_format($total, 2) . ' to Jasper Luca Weening, IBAN <strong style="color:#ffffff;">CH68 0020 4204 3038 7040 A</strong> with reference <strong style="color:#ED1E28;">' . $orderNumber . '</strong>.</p>'
        : '<p style="color:#a0a0b8;font-size:0.85rem;margin:16px 0 0;">Payment received — thank you!</p>';

    $addressDisplay = nl2br(htmlspecialchars($shippingAddress));

    $html = '
    <div style="background:#0a0a0f;padding:40px 0;font-family:sans-serif;">
      <div style="max-width:560px;margin:0 auto;background:#16161f;border:1px solid #2a2a3a;border-radius:16px;overflow:hidden;">
        <div style="background:#ED1E28;padding:24px 32px;">
          <!-- ONCE LIVE: replace the span below with: <img src="https://swiss-bricks.com/assets/logo.png" alt="SwissBricks" style="height:36px;"> -->
          <span style="font-family:sans-serif;font-size:1.3rem;font-weight:800;color:#ffffff;letter-spacing:-0.02em;">Swiss<span style="opacity:0.85;">Bricks</span></span>
        </div>
        <div style="padding:32px;">
          <h2 style="color:#ffffff;margin:0 0 4px;font-size:1.3rem;">Order Confirmed!</h2>
          <p style="color:#a0a0b8;margin:0 0 24px;font-size:0.9rem;">Hi ' . htmlspecialchars($toName) . ', your order has been received.</p>

          <div style="background:#0a0a0f;border-radius:8px;padding:16px 20px;margin-bottom:20px;">
            <div style="font-size:0.75rem;color:#555570;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">Order Number</div>
            <div style="font-size:1.1rem;font-weight:700;color:#ED1E28;font-family:monospace;">' . $orderNumber . '</div>
          </div>

          <table style="width:100%;border-collapse:collapse;margin-bottom:8px;">' . $itemRows . '
            ' . ($shippingCost > 0 ? '
            <tr>
              <td style="padding:8px 0 0;color:#a0a0b8;font-size:0.85rem;">Shipping (' . ($shippingMethod === 'economy' ? 'Swiss Post Economy' : 'Swiss Post Priority') . ')</td>
              <td style="padding:8px 0 0;color:#a0a0b8;font-size:0.85rem;text-align:right;">CHF ' . number_format($shippingCost, 2) . '</td>
            </tr>' : '') . '
            <tr>
              <td style="padding:12px 0 0;color:#ffffff;font-weight:700;border-top:1px solid #2a2a3a;">Total</td>
              <td style="padding:12px 0 0;text-align:right;border-top:1px solid #2a2a3a;">
                <span style="color:#ED1E28;font-weight:700;">CHF ' . number_format($total, 2) . '</span>
                <div style="font-size:0.72rem;color:#555570;margin-top:3px;">non-refundable</div>
              </td>
            </tr>
          </table>

          ' . $paymentNote . $preorderBlock . '

          <div style="margin-top:24px;padding-top:20px;border-top:1px solid #2a2a3a;">
            <div style="font-size:0.75rem;color:#555570;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:8px;">Shipping To</div>
            <div style="color:#a0a0b8;font-size:0.85rem;line-height:1.7;">' . $addressDisplay . '</div>
          </div>

          <p style="color:#555570;font-size:0.8rem;margin:24px 0 0;">Questions? Reply to this email or contact us at <a href="mailto:info@swiss-bricks.com" style="color:#ED1E28;">info@swiss-bricks.com</a></p>
        </div>
      </div>
    </div>';

    $sent = sendMail($toEmail, $toName, $subject, $html);

    // Admin notification to info@swiss-bricks.com
    $adminSubject = 'New Order Received: ' . $orderNumber . ' (' . $paymentMethod . ')';
    $adminHtml = '
    <div style="background:#0a0a0f;padding:40px 0;font-family:sans-serif;">
      <div style="max-width:560px;margin:0 auto;background:#16161f;border:1px solid #2a2a3a;border-radius:16px;overflow:hidden;">
        <div style="background:#ED1E28;padding:24px 32px;">
          <span style="font-family:sans-serif;font-size:1.3rem;font-weight:800;color:#ffffff;letter-spacing:-0.02em;">Swiss<span style="opacity:0.85;">Bricks</span> &mdash; New Order</span>
        </div>
        <div style="padding:32px;">
          <div style="background:#0a0a0f;border-radius:8px;padding:16px 20px;margin-bottom:20px;">
            <div style="font-size:0.75rem;color:#555570;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">Order Number</div>
            <div style="font-size:1.1rem;font-weight:700;color:#ED1E28;font-family:monospace;">' . $orderNumber . '</div>
          </div>

          <table style="width:100%;border-collapse:collapse;margin-bottom:8px;">' . $itemRows . '
            ' . ($shippingCost > 0 ? '
            <tr>
              <td style="padding:8px 0 0;color:#a0a0b8;font-size:0.85rem;">Shipping (' . ($shippingMethod === 'economy' ? 'Swiss Post Economy' : 'Swiss Post Priority') . ')</td>
              <td style="padding:8px 0 0;color:#a0a0b8;font-size:0.85rem;text-align:right;">CHF ' . number_format($shippingCost, 2) . '</td>
            </tr>' : '') . '
            <tr>
              <td style="padding:12px 0 0;color:#ffffff;font-weight:700;border-top:1px solid #2a2a3a;">Total</td>
              <td style="padding:12px 0 0;text-align:right;border-top:1px solid #2a2a3a;">
                <span style="color:#ED1E28;font-weight:700;">CHF ' . number_format($total, 2) . '</span>
                <div style="font-size:0.72rem;color:#555570;margin-top:3px;">non-refundable</div>
              </td>
            </tr>
          </table>

          ' . $preorderBlock . '

          <div style="margin-top:20px;padding-top:20px;border-top:1px solid #2a2a3a;">
            <div style="font-size:0.75rem;color:#555570;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:8px;">Customer</div>
            <div style="color:#a0a0b8;font-size:0.85rem;">' . htmlspecialchars($toName) . ' &mdash; <a href="mailto:' . htmlspecialchars($toEmail) . '" style="color:#ED1E28;">' . htmlspecialchars($toEmail) . '</a></div>
          </div>

          <div style="margin-top:16px;padding-top:16px;border-top:1px solid #2a2a3a;">
            <div style="font-size:0.75rem;color:#555570;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:8px;">Shipping Address</div>
            <div style="color:#a0a0b8;font-size:0.85rem;line-height:1.7;">' . $addressDisplay . '</div>
          </div>

          <div style="margin-top:16px;padding-top:16px;border-top:1px solid #2a2a3a;">
            <div style="font-size:0.75rem;color:#555570;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">Payment Method</div>
            <div style="color:#e0e0f0;font-size:0.9rem;">' . htmlspecialchars($paymentMethod) . ($paymentMethod === 'Bank Transfer' ? ' <span style="color:#f59e0b;">(awaiting transfer)</span>' : ' <span style="color:#22c55e;">(paid)</span>') . '</div>
          </div>
        </div>
      </div>
    </div>';

    sendMail('info@swiss-bricks.com', 'SwissBricks', $adminSubject, $adminHtml);

    return $sent;
}

// ── Back-in-stock notification email ─────────────────────
function sendBackInStockEmail(string $toEmail, string $toName, array $product): bool {
    require_once __DIR__ . '/mailer.php';
    $subject = 'Back in Stock: ' . $product['name'];
    $productUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'swiss-bricks.com') . BASE_URL . '/pages/product.php?slug=' . urlencode($product['slug']);
    $price = 'CHF ' . number_format((float)$product['price'], 2);
    $html = '
    <div style="background:#0a0a0f;padding:40px 0;font-family:sans-serif;">
      <div style="max-width:520px;margin:0 auto;background:#16161f;border:1px solid #2a2a3a;border-radius:16px;overflow:hidden;">
        <div style="background:#ED1E28;padding:24px 32px;">
          <!-- ONCE LIVE: replace span below with <img src="https://swiss-bricks.com/assets/logo.png" alt="SwissBricks" style="height:36px;"> -->
          <span style="font-family:sans-serif;font-size:1.3rem;font-weight:800;color:#ffffff;letter-spacing:-0.02em;">Swiss<span style="opacity:0.85;">Bricks</span></span>
        </div>
        <div style="padding:32px;">
          <h2 style="color:#ffffff;margin:0 0 6px;font-size:1.2rem;">Good news, ' . htmlspecialchars($toName) . '!</h2>
          <p style="color:#a0a0b8;line-height:1.7;margin:0 0 24px;">A product on your wishlist is back in stock and ready to order.</p>
          <a href="' . $productUrl . '" style="display:block;text-decoration:none;background:#1e1e2e;border:1px solid #ED1E28;border-radius:12px;overflow:hidden;margin-bottom:24px;">
            <img src="' . htmlspecialchars($product['image_url']) . '" alt="' . htmlspecialchars($product['name']) . '" style="width:100%;max-height:220px;object-fit:contain;background:#fff;display:block;">
            <div style="padding:16px 20px;">
              <div style="color:#ffffff;font-weight:700;font-size:1rem;margin-bottom:4px;">' . htmlspecialchars($product['name']) . '</div>
              <div style="color:#ED1E28;font-weight:700;font-size:1.1rem;">' . $price . '</div>
            </div>
          </a>
          <a href="' . $productUrl . '" style="display:inline-block;background:#ED1E28;color:#ffffff;text-decoration:none;padding:14px 28px;border-radius:8px;font-weight:700;font-size:0.95rem;">View Product &rarr;</a>
          <p style="color:#555570;font-size:0.8rem;margin:24px 0 0;">You saved this item to your SwissBricks wishlist. <a href="' . (isset($_SERVER['HTTP_HOST']) ? ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']) : '') . BASE_URL . '/pages/myaccount.php?tab=wishlist" style="color:#ED1E28;">Manage wishlist</a></p>
        </div>
      </div>
    </div>';
    return sendMail($toEmail, $toName, $subject, $html);
}

// ── Sanitize & utility ────────────────────────────────────
function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
}

function generateProofNumber(): string {
    return 'PP-' . strtoupper(substr(md5(uniqid((string)rand(), true)), 0, 8));
}

function generateOrderNumber() {
    return 'SB-' . strtoupper(substr(uniqid(), -8));
}

// ── Pre-order refund notification email ──────────────────
function sendPreorderRefundEmail(string $toEmail, string $toName, string $orderNumber, float $total, array $items): bool {
    require_once __DIR__ . '/mailer.php';
    $subject = 'Pre-order Update: ' . $orderNumber . ' — Refund Processed';

    $itemRows = '';
    foreach ($items as $item) {
        $imgHtml = !empty($item['image_url'])
            ? '<img src="' . htmlspecialchars($item['image_url']) . '" alt="" width="48" height="48" style="width:48px;height:48px;object-fit:contain;background:#ffffff;border-radius:6px;display:block;">'
            : '';
        $itemRows .= '
        <tr>
          <td style="padding:10px 0;border-bottom:1px solid #2a2a3a;vertical-align:middle;">
            <table style="border-collapse:collapse;"><tr>
              <td style="padding:0 12px 0 0;vertical-align:middle;">' . $imgHtml . '</td>
              <td style="vertical-align:middle;color:#e0e0f0;font-size:0.9rem;">' . htmlspecialchars($item['name']) . ' &times; ' . (int)$item['quantity'] . '
                <span style="display:inline-block;background:rgba(237,30,40,0.15);color:#ED1E28;border:1px solid rgba(237,30,40,0.4);border-radius:4px;padding:1px 6px;font-size:0.65rem;font-weight:800;letter-spacing:0.06em;vertical-align:middle;">PRE-ORDER</span>
              </td>
            </tr></table>
          </td>
          <td style="padding:10px 0;border-bottom:1px solid #2a2a3a;color:#e0e0f0;font-size:0.9rem;text-align:right;vertical-align:middle;">CHF ' . number_format($item['price'] * $item['quantity'], 2) . '</td>
        </tr>';
    }

    $html = '
    <div style="background:#0a0a0f;padding:40px 0;font-family:sans-serif;">
      <div style="max-width:560px;margin:0 auto;background:#16161f;border:1px solid #2a2a3a;border-radius:16px;overflow:hidden;">
        <div style="background:#ED1E28;padding:24px 32px;">
          <!-- ONCE LIVE: replace span below with <img src="https://swiss-bricks.com/assets/logo.png" alt="SwissBricks" style="height:36px;"> -->
          <span style="font-family:sans-serif;font-size:1.3rem;font-weight:800;color:#ffffff;letter-spacing:-0.02em;">Swiss<span style="opacity:0.85;">Bricks</span></span>
        </div>
        <div style="padding:32px;">
          <h2 style="color:#ffffff;margin:0 0 4px;font-size:1.3rem;">Pre-order Unsuccessful</h2>
          <p style="color:#a0a0b8;margin:0 0 24px;font-size:0.9rem;">Hi ' . htmlspecialchars($toName) . ', unfortunately we were unable to restock your pre-ordered item within the 30 day period.</p>

          <div style="background:#0a0a0f;border-radius:8px;padding:16px 20px;margin-bottom:20px;">
            <div style="font-size:0.75rem;color:#555570;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">Order Number</div>
            <div style="font-size:1.1rem;font-weight:700;color:#ED1E28;font-family:monospace;">' . $orderNumber . '</div>
          </div>

          <table style="width:100%;border-collapse:collapse;margin-bottom:8px;">' . $itemRows . '
            <tr>
              <td style="padding:12px 0 0;color:#ffffff;font-weight:700;">Refund Amount</td>
              <td style="padding:12px 0 0;color:#ED1E28;font-weight:700;text-align:right;">CHF ' . number_format($total, 2) . '</td>
            </tr>
          </table>

          <div style="margin-top:24px;padding-top:20px;border-top:1px solid #2a2a3a;">
            <p style="color:#a0a0b8;font-size:0.87rem;line-height:1.7;margin:0 0 10px;">A full refund of <strong style="color:#e0e0f0;">CHF ' . number_format($total, 2) . '</strong> has been processed to your original payment method. Please allow 5–10 business days for the refund to appear.</p>
            <p style="color:#a0a0b8;font-size:0.87rem;line-height:1.7;margin:0;">We are sorry we could not fulfil your order. If you have any questions, please don\'t hesitate to reach out.</p>
          </div>

          <p style="color:#555570;font-size:0.8rem;margin:24px 0 0;">Questions? Reply to this email or contact us at <a href="mailto:info@swiss-bricks.com" style="color:#ED1E28;">info@swiss-bricks.com</a></p>
        </div>
      </div>
    </div>';

    return sendMail($toEmail, $toName, $subject, $html);
}

// ── Picture Proof: customer confirmation email ────────────
function sendPictureProofConfirmationEmail(string $toEmail, string $toName, string $productName, string $productImage = '', string $refNumber = ''): bool {
    require_once __DIR__ . '/mailer.php';
    $firstName = htmlspecialchars(explode(' ', $toName)[0]);
    $imgHtml = !empty($productImage)
        ? '<img src="' . htmlspecialchars($productImage) . '" width="72" height="72" style="width:72px;height:72px;object-fit:contain;background:#ffffff;border-radius:8px;display:block;flex-shrink:0;">'
        : '';
    $refHtml = $refNumber ? '<div style="background:#0a0a0f;border-radius:10px;padding:14px 20px;margin-bottom:16px;"><div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#555570;margin-bottom:4px;">Reference Number</div><div style="color:#ED1E28;font-weight:800;font-size:1.05rem;letter-spacing:0.03em;">' . htmlspecialchars($refNumber) . '</div></div>' : '';
    $productBox = $imgHtml
        ? '<table cellpadding="0" cellspacing="0"><tr><td style="padding-right:16px;vertical-align:middle;">' . $imgHtml . '</td><td style="vertical-align:middle;"><div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#555570;margin-bottom:6px;">Product</div><div style="color:#fff;font-weight:700;font-size:1rem;">' . htmlspecialchars($productName) . '</div></td></tr></table>'
        : '<div><div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#555570;margin-bottom:6px;">Product</div><div style="color:#fff;font-weight:700;font-size:1rem;">' . htmlspecialchars($productName) . '</div></div>';
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#0a0a0f;font-family:\'DM Sans\',Arial,sans-serif;">
<!-- ' . uniqid('sb_', true) . ' -->
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px;">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
  <tr><td style="background:#ED1E28;border-radius:16px 16px 0 0;padding:24px 32px;">
    <!-- ONCE LIVE: replace span below with <img src="https://swiss-bricks.com/assets/logo.png" alt="SwissBricks" style="height:36px;"> -->
    <span style="color:#fff;font-weight:800;font-size:1.3rem;font-family:Arial,sans-serif;letter-spacing:-0.02em;">Swiss<span style="opacity:0.85;">Bricks</span></span>
  </td></tr>
  <tr><td style="background:#16161f;border-radius:0 0 16px 16px;padding:36px;">
    <h2 style="color:#fff;font-size:1.4rem;font-weight:800;margin:0 0 8px;">Request Received!</h2>
    <p style="color:#a0a0b8;margin:0 0 20px;">Hi ' . $firstName . ', we\'ve received your picture proof request.</p>
    ' . $refHtml . '
    <div style="background:#0a0a0f;border-radius:10px;padding:20px 24px;margin-bottom:24px;">' . $productBox . '</div>
    <p style="color:#a0a0b8;line-height:1.7;margin:0 0 8px;">We will photograph the set and send the pictures to this email address. This typically happens within 1–2 business days.</p>
    <p style="color:#a0a0b8;line-height:1.7;margin:0;">Thank you for choosing us!</p>
    <p style="font-size:0.75rem;color:#555570;margin:24px 0 0;">SwissBricks &mdash; Swiss LEGO Specialist</p>
  </td></tr>
</table></td></tr></table></body></html>';
    return sendMail($toEmail, $toName, 'Picture Proof Request Received — SwissBricks', $html);
}

// ── Picture Proof: send photos to customer ────────────────
function sendPictureProofEmail(string $toEmail, string $toName, string $productName, array $imagePaths, string $refNumber = '', int $productId = 0): bool {
    require_once __DIR__ . '/mailer.php';
    require_once __DIR__ . '/../vendor/autoload.php';
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();

    $firstName = htmlspecialchars(explode(' ', $toName)[0]);
    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'] ?? 'swiss-bricks.com';
    $buyNowUrl  = $productId ? ($protocol . '://' . $host . BASE_URL . '/pages/buy_now.php?product_id=' . $productId) : '';
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = trim($_ENV['SMTP_HOST']);
        $mail->SMTPAuth   = true;
        $mail->Username   = trim($_ENV['SMTP_USER']);
        $mail->Password   = trim($_ENV['SMTP_PASS']);
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)trim($_ENV['SMTP_PORT']);
        $mail->setFrom(trim($_ENV['SMTP_USER']), trim($_ENV['SMTP_FROM_NAME']));
        $mail->addAddress($toEmail, $toName);
        $mail->CharSet = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $mail->isHTML(true);
        $mail->Subject = 'Your Picture Proof' . ($refNumber ? ' ' . $refNumber : '') . ' — ' . $productName . ' — SwissBricks';

        // Embed each image and collect CIDs
        $cids = [];
        foreach ($imagePaths as $i => $path) {
            if (!file_exists($path)) continue;
            $cid = 'proof_img_' . $i;
            $mail->addEmbeddedImage($path, $cid, basename($path));
            $cids[] = $cid;
        }

        // Build 2-column image grid
        $gridHtml = '';
        if (!empty($cids)) {
            $gridHtml = '<table cellpadding="0" cellspacing="0" style="width:100%;margin:24px 0;border-collapse:collapse;"><tr>';
            foreach ($cids as $idx => $cid) {
                if ($idx > 0 && $idx % 2 === 0) $gridHtml .= '</tr><tr>';
                $gridHtml .= '<td style="padding:4px;width:50%;vertical-align:top;">
                    <img src="cid:' . $cid . '" style="width:100%;max-width:260px;height:auto;border-radius:8px;display:block;" alt="Picture Proof">
                  </td>';
            }
            // Fill empty cell if odd number
            if (count($cids) % 2 !== 0) $gridHtml .= '<td style="padding:4px;width:50%;"></td>';
            $gridHtml .= '</tr></table>';
        }

        $mail->Body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#0a0a0f;font-family:\'DM Sans\',Arial,sans-serif;">
<!-- ' . uniqid('sb_', true) . ' -->
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px;">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
  <tr><td style="background:#ED1E28;border-radius:16px 16px 0 0;padding:24px 32px;">
    <!-- ONCE LIVE: replace span below with <img src="https://swiss-bricks.com/assets/logo.png" alt="SwissBricks" style="height:36px;"> -->
    <span style="color:#fff;font-weight:800;font-size:1.3rem;font-family:Arial,sans-serif;letter-spacing:-0.02em;">Swiss<span style="opacity:0.85;">Bricks</span></span>
  </td></tr>
  <tr><td style="background:#16161f;border-radius:0 0 16px 16px;padding:36px;">
    <h2 style="color:#fff;font-size:1.4rem;font-weight:800;margin:0 0 8px;">Your Picture Proof</h2>
    <p style="color:#a0a0b8;margin:0 0 20px;">Hi ' . $firstName . ', here are the photos of your set.</p>
    ' . ($refNumber ? '<div style="background:#0a0a0f;border-radius:10px;padding:14px 20px;margin-bottom:16px;"><div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#555570;margin-bottom:4px;">Reference Number</div><div style="color:#ED1E28;font-weight:800;font-size:1.05rem;letter-spacing:0.03em;">' . htmlspecialchars($refNumber) . '</div></div>' : '') . '
    <div style="background:#0a0a0f;border-radius:10px;padding:20px 24px;margin-bottom:8px;">
      <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#555570;margin-bottom:6px;">Set</div>
      <div style="color:#fff;font-weight:700;font-size:1rem;">' . htmlspecialchars($productName) . '</div>
    </div>
    ' . $gridHtml . '
    ' . ($buyNowUrl ? '<div style="text-align:center;margin:28px 0;">
      <a href="' . $buyNowUrl . '" style="display:inline-block;background:#ED1E28;color:#fff;font-weight:800;font-size:1rem;text-decoration:none;padding:14px 36px;border-radius:10px;letter-spacing:-0.01em;">Buy Now</a>
    </div>' : '') . '
    <p style="color:#a0a0b8;line-height:1.7;margin:24px 0 8px;">If you have any further questions, please reply to this message.</p>
    <p style="color:#a0a0b8;line-height:1.7;margin:0;">Thank you for choosing SwissBricks!</p>
    <p style="font-size:0.75rem;color:#555570;margin:24px 0 0;">SwissBricks &mdash; Swiss LEGO Specialist</p>
  </td></tr>
</table></td></tr></table></body></html>';
        $mail->AltBody = "Hi $firstName, your picture proof photos for $productName are embedded in the HTML version of this email. If you have any further questions, please reply to this message. Thank you for choosing SwissBricks!";
        $mail->send();
        return true;
    } catch (\Exception $e) {
        error_log('Picture proof mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}

function convertImageToPng(string $srcPath, string $destPath, string $originalName = ''): bool {
    // Resolve extension from original filename first, then fall back to mime type
    $ext = strtolower(pathinfo($originalName ?: $srcPath, PATHINFO_EXTENSION));
    if (!$ext || $ext === 'tmp') {
        $mime = mime_content_type($srcPath) ?: '';
        $ext = match($mime) {
            'image/jpeg'    => 'jpg',
            'image/png'     => 'png',
            'image/gif'     => 'gif',
            'image/webp'    => 'webp',
            'image/bmp'     => 'bmp',
            'image/avif'    => 'avif',
            'image/heic',
            'image/heif'    => 'heic',
            default         => '',
        };
    }
    // Try sips first (macOS) — runs as external process, no PHP memory cost
    exec('sips -s format png ' . escapeshellarg($srcPath) . ' --out ' . escapeshellarg($destPath) . ' 2>&1', $sipsOut, $sipsCode);
    if ($sipsCode === 0 && file_exists($destPath)) return true;

    // Fallback: imagick
    if (extension_loaded('imagick')) {
        try { $im = new \Imagick($srcPath); $im->setImageFormat('png'); $im->writeImage($destPath); $im->clear(); return file_exists($destPath); } catch (\Exception $e) {}
    }

    // Last resort: GD (memory-intensive for large images)
    $img = match($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($srcPath),
        'png'         => @imagecreatefrompng($srcPath),
        'gif'         => @imagecreatefromgif($srcPath),
        'webp'        => @imagecreatefromwebp($srcPath),
        'bmp'         => @imagecreatefrombmp($srcPath),
        'avif'        => function_exists('imagecreatefromavif') ? @imagecreatefromavif($srcPath) : false,
        default       => false,
    };
    if (!$img) return false;
    // Preserve transparency
    $w = imagesx($img); $h = imagesy($img);
    $out = imagecreatetruecolor($w, $h);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
    imagefill($out, 0, 0, $transparent);
    imagecopy($out, $img, 0, 0, 0, 0, $w, $h);
    imagedestroy($img);
    $ok = imagepng($out, $destPath, 8);
    imagedestroy($out);
    return $ok;
}

function generateVoucherCode(): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $suffix = '';
    for ($i = 0; $i < 8; $i++) $suffix .= $chars[random_int(0, strlen($chars) - 1)];
    return 'SB-' . $suffix;
}

function sendVoucherEmail(string $toEmail, string $toName, string $code, string $type, float $amount, float $minSpend, ?string $expiresAt = null): bool {
    require_once __DIR__ . '/mailer.php';
    $firstName    = htmlspecialchars(explode(' ', $toName)[0]);
    $discountText = $type === 'cash' ? 'CHF ' . number_format($amount, 2) . ' off' : number_format($amount, 0) . '% off';
    $minSpendText = $minSpend > 0 ? 'Valid on orders of CHF ' . number_format($minSpend, 2) . ' or more.' : 'No minimum spend required.';
    $expiryLine   = $expiresAt ? '<p style="color:#a0a0b8;line-height:1.7;margin:0 0 6px;">Expires: <strong style="color:#fff;">' . date('d F Y', strtotime($expiresAt)) . '</strong></p>' : '';
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#0a0a0f;font-family:\'DM Sans\',Arial,sans-serif;">
<!-- ' . uniqid('sb_', true) . ' -->
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px;">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
  <tr><td style="background:#ED1E28;border-radius:16px 16px 0 0;padding:24px 32px;">
    <!-- ONCE LIVE: replace span below with <img src="https://swiss-bricks.com/assets/logo.png" alt="SwissBricks" style="height:36px;"> -->
    <span style="color:#fff;font-weight:800;font-size:1.3rem;font-family:Arial,sans-serif;letter-spacing:-0.02em;">Swiss<span style="opacity:0.85;">Bricks</span></span>
  </td></tr>
  <tr><td style="background:#16161f;border-radius:0 0 16px 16px;padding:36px;">
    <h2 style="color:#fff;font-size:1.4rem;font-weight:800;margin:0 0 8px;">You have a voucher!</h2>
    <p style="color:#a0a0b8;margin:0 0 24px;">Hi ' . $firstName . ', here is your exclusive discount voucher.</p>
    <div style="background:#0a0a0f;border-radius:12px;padding:24px;text-align:center;margin-bottom:20px;border:1px solid #2a2a3a;">
      <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#555570;margin-bottom:10px;">Your Voucher Code</div>
      <div style="font-family:monospace;font-size:2rem;font-weight:900;color:#ED1E28;letter-spacing:0.12em;">' . htmlspecialchars($code) . '</div>
      <div style="margin-top:12px;font-size:0.9rem;color:#fff;font-weight:700;">' . $discountText . '</div>
    </div>
    <p style="color:#a0a0b8;line-height:1.7;margin:0 0 6px;">' . $minSpendText . '</p>
    ' . $expiryLine . '
    <p style="color:#a0a0b8;line-height:1.7;margin:0 0 24px;">Enter the code at checkout to redeem your discount.</p>
    <p style="font-size:0.75rem;color:#555570;margin:0;">SwissBricks &mdash; Swiss LEGO Specialist</p>
  </td></tr>
</table></td></tr></table></body></html>';
    return sendMail($toEmail, $toName, 'Your SwissBricks Voucher — ' . $discountText, $html);
}

function getStarRating($rating = 5) {
    $filled = (int)round($rating);
    return '<span style="color:#f59e0b;">' . str_repeat('★', $filled) . '</span>'
         . '<span style="color:#3a3a4a;">' . str_repeat('★', 5 - $filled) . '</span>';
}

function getStatusBadge($status) {
    $map = [
        'pending'    => ['#6b7280', 'Pending'],
        'processing' => ['#3b82f6', 'Processing'],
        'shipped'    => ['#8b5cf6', 'Shipped'],
        'delivered'  => ['#10b981', 'Delivered'],
        'cancelled'  => ['#ef4444', 'Cancelled'],
    ];
    [$color, $label] = $map[$status] ?? ['#6b7280', ucfirst($status)];
    return "<span style='background:{$color}22;color:{$color};padding:3px 10px;border-radius:20px;font-size:0.78rem;font-weight:600;'>{$label}</span>";
}

function sendOrderShippedEmail(string $toEmail, string $toName, string $orderNumber, string $trackingNumber = ''): bool {
    require_once __DIR__ . '/mailer.php';
    $firstName = htmlspecialchars(explode(' ', trim($toName))[0]);
    $uid  = uniqid('sb_shipped_', true);
    $trackingBox = '';
    if ($trackingNumber !== '') {
        $safeTracking = htmlspecialchars($trackingNumber);
        $trackUrl = 'https://track.post.ch/?formattedParcelCodes=' . urlencode($trackingNumber);
        $trackingBox = '
        <div style="background:#0a0a0f;border-radius:12px;padding:18px 20px;margin-bottom:24px;border:1px solid #2a2a3a;text-align:center;">
          <div style="font-size:0.72rem;color:#555570;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:6px;">Tracking Number</div>
          <a href="' . $trackUrl . '" style="font-family:monospace;font-size:1.2rem;font-weight:900;color:#ED1E28;letter-spacing:0.08em;text-decoration:none;">' . $safeTracking . '</a>
          <div style="font-size:0.75rem;color:#555570;margin-top:6px;">Click to track on Swiss Post</div>
        </div>';
    }
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#0a0a0f;font-family:Arial,sans-serif;">
<!-- ' . $uid . ' -->
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px;">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">
  <tr><td style="background:#16161f;border-radius:20px;overflow:hidden;border:1px solid #2a2a3a;">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr><td style="background:#ED1E28;padding:24px 32px;">
        <!-- ONCE LIVE: replace span below with <img src="https://swiss-bricks.com/assets/logo.png" alt="SwissBricks" style="height:36px;"> -->
        <span style="color:#fff;font-weight:800;font-size:1.3rem;font-family:Arial,sans-serif;letter-spacing:-0.02em;">Swiss<span style="opacity:0.85;">Bricks</span></span>
      </td></tr>
    </table>
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr><td style="padding:32px 36px;">
        <div style="text-align:center;margin-bottom:24px;">
          <div style="display:inline-block;background:#1e1e2e;border-radius:50%;width:64px;height:64px;line-height:64px;font-size:2rem;text-align:center;">📦</div>
        </div>
        <h1 style="color:#fff;font-size:1.5rem;font-weight:900;margin:0 0 12px;text-align:center;font-family:Arial,sans-serif;">Your order is on its way!</h1>
        <p style="color:#a0a0b8;font-size:0.95rem;line-height:1.75;margin:0 0 24px;text-align:center;">Hi <strong style="color:#fff;">' . $firstName . '</strong>, great news — your order <strong style="color:#fff;">' . htmlspecialchars($orderNumber) . '</strong> has been shipped and is on its way to you.</p>
        ' . $trackingBox . '
        <p style="color:#a0a0b8;font-size:0.85rem;line-height:1.7;margin:0 0 24px;text-align:center;">If you have any questions about your delivery, feel free to reply to this email.</p>
        <p style="font-size:0.72rem;color:#44445a;margin:0;text-align:center;">SwissBricks &mdash; Swiss LEGO Specialist</p>
      </td></tr>
    </table>
  </td></tr>
</table></td></tr></table>
</body></html>';
    return sendMail($toEmail, $toName, 'Your SwissBricks order ' . $orderNumber . ' has been shipped! 📦', $html);
}

function sendOrderCancelledEmail(string $toEmail, string $toName, string $orderNumber): bool {
    require_once __DIR__ . '/mailer.php';
    $firstName = htmlspecialchars(explode(' ', trim($toName))[0]);
    $uid  = uniqid('sb_cancelled_', true);
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#0a0a0f;font-family:Arial,sans-serif;">
<!-- ' . $uid . ' -->
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px;">
<table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">
  <tr><td style="background:#16161f;border-radius:20px;overflow:hidden;border:1px solid #2a2a3a;">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr><td style="background:#ED1E28;padding:24px 32px;">
        <!-- ONCE LIVE: replace span below with <img src="https://swiss-bricks.com/assets/logo.png" alt="SwissBricks" style="height:36px;"> -->
        <span style="color:#fff;font-weight:800;font-size:1.3rem;font-family:Arial,sans-serif;letter-spacing:-0.02em;">Swiss<span style="opacity:0.85;">Bricks</span></span>
      </td></tr>
    </table>
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr><td style="padding:32px 36px;">
        <div style="text-align:center;margin-bottom:24px;">
          <div style="display:inline-block;background:#ED1E28;border-radius:50%;width:64px;height:64px;line-height:64px;font-size:2rem;text-align:center;color:#fff;">✕</div>
        </div>
        <h1 style="color:#fff;font-size:1.5rem;font-weight:900;margin:0 0 12px;text-align:center;font-family:Arial,sans-serif;">Order Cancelled</h1>
        <p style="color:#a0a0b8;font-size:0.95rem;line-height:1.75;margin:0 0 24px;text-align:center;">Hi <strong style="color:#fff;">' . $firstName . '</strong>, your order <strong style="color:#fff;">' . htmlspecialchars($orderNumber) . '</strong> has been cancelled.</p>
        <div style="background:#0a0a0f;border-radius:12px;padding:18px 20px;margin-bottom:24px;border:1px solid #2a2a3a;text-align:center;">
          <div style="font-size:0.72rem;color:#555570;text-transform:uppercase;letter-spacing:0.1em;margin-bottom:6px;">Order Number</div>
          <div style="font-family:monospace;font-size:1.2rem;font-weight:900;color:#ED1E28;letter-spacing:0.08em;">' . htmlspecialchars($orderNumber) . '</div>
        </div>
        <p style="color:#a0a0b8;font-size:0.85rem;line-height:1.7;margin:0 0 24px;text-align:center;">If a payment was made, a refund will be processed to your original payment method. This can take 5–10 business days depending on your bank. We apologize for the inconvenience.</p>
        <p style="font-size:0.72rem;color:#44445a;margin:0;text-align:center;">SwissBricks &mdash; Swiss LEGO Specialist</p>
      </td></tr>
    </table>
  </td></tr>
</table></td></tr></table>
</body></html>';
    return sendMail($toEmail, $toName, 'Your SwissBricks order ' . $orderNumber . ' has been cancelled', $html);
}

function sendAdminReviewNotification(string $customerName, string $customerEmail, string $orderNumber, int $rating, string $reviewText): bool {
    require_once __DIR__ . '/mailer.php';
    $stars = '<span style="color:#f59e0b;">' . str_repeat('★', $rating) . '</span>'
           . '<span style="color:#3a3a4a;">' . str_repeat('★', 5 - $rating) . '</span>';
    $adminUrl   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                  . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . '/admin/reviews.php';
    $tpUrl      = 'https://www.trustpilot.com/review/swiss-bricks.com';
    $uid        = uniqid('sb_admin_review_', true);
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#0a0a0f;font-family:Arial,sans-serif;">
<!-- ' . $uid . ' -->
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 20px;">
<table width="540" cellpadding="0" cellspacing="0" style="max-width:540px;width:100%;">
  <tr><td style="background:#16161f;border-radius:16px;overflow:hidden;border:1px solid #2a2a3a;">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr><td style="background:#ED1E28;padding:24px 32px;">
        <!-- ONCE LIVE: replace span below with <img src="https://swiss-bricks.com/assets/logo.png" alt="SwissBricks" style="height:36px;"> -->
        <span style="color:#fff;font-weight:800;font-size:1.3rem;font-family:Arial,sans-serif;letter-spacing:-0.02em;">Swiss<span style="opacity:0.85;">Bricks</span></span>
      </td></tr>
    </table>
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr><td style="padding:28px;">
        <p style="color:#fff;font-size:1.1rem;font-weight:700;margin:0 0 16px;">New review received for order <span style="color:#ED1E28;">' . htmlspecialchars($orderNumber) . '</span></p>
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#0a0a0f;border-radius:10px;margin-bottom:20px;border:1px solid #2a2a3a;">
          <tr><td style="padding:16px 20px;">
            <div style="font-size:0.75rem;color:#555570;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">Customer</div>
            <div style="color:#fff;font-weight:600;">' . htmlspecialchars($customerName) . '</div>
            <div style="color:#a0a0b8;font-size:0.85rem;">' . htmlspecialchars($customerEmail) . '</div>
          </td></tr>
          <tr><td style="padding:0 20px 16px;">
            <div style="font-size:0.75rem;color:#555570;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">Rating</div>
            <div style="font-size:1.3rem;color:#f59e0b;">' . $stars . '</div>
          </td></tr>
          <tr><td style="padding:0 20px 16px;">
            <div style="font-size:0.75rem;color:#555570;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:6px;">Review</div>
            <div style="color:#a0a0b8;font-size:0.9rem;line-height:1.6;">' . nl2br(htmlspecialchars($reviewText)) . '</div>
          </td></tr>
        </table>
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td width="48%" style="padding-right:6px;">
              <a href="' . $adminUrl . '" style="display:block;background:#ED1E28;color:#fff;text-decoration:none;padding:12px 10px;border-radius:10px;font-weight:800;font-size:0.88rem;font-family:Arial,sans-serif;text-align:center;">View in Admin →</a>
            </td>
            <td width="4%"></td>
            <td width="48%" style="padding-left:6px;">
              <a href="' . $tpUrl . '" style="display:block;background:#00B67A;color:#fff;text-decoration:none;padding:12px 10px;border-radius:10px;font-weight:800;font-size:0.88rem;font-family:Arial,sans-serif;text-align:center;">Check Trustpilot</a>
            </td>
          </tr>
        </table>
        <p style="color:#44445a;font-size:0.72rem;margin:20px 0 0;text-align:center;">Release the voucher from the Reviews admin page once you have verified both reviews.</p>
      </td></tr>
    </table>
  </td></tr>
</table></td></tr></table>
</body></html>';
    return sendMail('info@swiss-bricks.com', 'SwissBricks Admin', 'New review — ' . $orderNumber . ' (' . $rating . '/5 ★)', $html);
}

function generateReviewToken(): string {
    return bin2hex(random_bytes(32));
}

function sendReviewInviteEmail(string $toEmail, string $toName, string $orderNumber, string $token, float $voucherAmount, float $minSpend, int $expiryMonths = 6): bool {
    require_once __DIR__ . '/mailer.php';
    $firstName      = htmlspecialchars(explode(' ', trim($toName))[0]);
    $reviewUrl      = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                      . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                      . BASE_URL . '/pages/review.php?token=' . $token;
    $trustpilotUrl  = 'https://www.trustpilot.com/evaluate/swiss-bricks.com?email=' . urlencode($toEmail) . '&name=' . urlencode(trim($toName));
    $amountInt      = (fmod($voucherAmount, 1.0) === 0.0) ? (int)$voucherAmount : number_format($voucherAmount, 2);
    $amount         = number_format($voucherAmount, 2);
    $minStr         = number_format($minSpend, 2);
    $uid            = uniqid('sb_review_', true);

    // Voucher icon: ticket shape, right-angle corners, semicircular side notches, 45° diagonal
    $voucherSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="170" viewBox="0 0 200 170">'
        . '<g transform="translate(100,85) rotate(-20) translate(-80,-45)">'
        . '<path d="M 0 0 L 160 0 L 160 34 A 11 11 0 0 0 160 56 L 160 90 L 0 90 L 0 56 A 11 11 0 0 0 0 34 Z" fill="#ED1E28"/>'
        . '<line x1="45" y1="6" x2="45" y2="84" stroke="#ffffff" stroke-width="1.5" stroke-dasharray="5,4" opacity="0.7"/>'
        . '<text x="103" y="55" text-anchor="middle" font-family="Arial,sans-serif" font-size="36" font-weight="900" fill="#ffffff">%</text>'
        . '</g>'
        . '</svg>';

    // Trustpilot star icon (green star on dark green pill)
    $tpIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:7px;">'
        . '<polygon points="12,2 15.09,8.26 22,9.27 17,14.14 18.18,21.02 12,17.77 5.82,21.02 7,14.14 2,9.27 8.91,8.26" fill="#ffffff"/>'
        . '</svg>';

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#0a0a0f;font-family:Arial,sans-serif;">
<!-- ' . $uid . ' -->
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px;">
<table width="580" cellpadding="0" cellspacing="0" style="max-width:580px;width:100%;">
  <tr><td style="background:#16161f;border-radius:20px;overflow:hidden;border:1px solid #2a2a3a;">

    <!-- Red header -->
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr><td style="background:#ED1E28;padding:24px 32px;">
        <!-- ONCE LIVE: replace span below with <img src="https://swiss-bricks.com/assets/logo.png" alt="SwissBricks" style="height:36px;"> -->
        <span style="color:#fff;font-weight:800;font-size:1.3rem;font-family:Arial,sans-serif;letter-spacing:-0.02em;">Swiss<span style="opacity:0.85;">Bricks</span></span>
      </td></tr>
    </table>

    <!-- Body -->
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr><td style="padding:36px 40px 32px;text-align:center;">

        <!-- Title -->
        <h1 style="color:#ffffff;font-size:1.8rem;font-weight:900;margin:0 0 28px;font-family:Arial,sans-serif;line-height:1.2;">Get ' . $amountInt . ' CHF off your next order!</h1>

        <!-- Voucher icon -->
        <div style="margin:0 auto 18px;display:inline-block;">' . $voucherSvg . '</div>

        <!-- Stars -->
        <div style="margin:0 0 28px;text-align:center;">
          <span style="font-size:2.1rem;letter-spacing:8px;color:#f59e0b;">&#9733;&#9733;&#9733;&#9733;&#9733;</span>
        </div>

        <!-- Body text -->
        <p style="color:#a0a0b8;font-size:0.97rem;line-height:1.75;margin:0 0 32px;text-align:left;">Hi <strong style="color:#ffffff;">' . $firstName . '</strong>, your order <strong style="color:#ffffff;">' . htmlspecialchars($orderNumber) . '</strong> has been delivered! We would love to hear what you have to say! Leave a review and receive a <strong style="color:#ED1E28;">CHF ' . $amount . ' gift voucher</strong> off your next order!</p>

        <!-- Two CTA buttons -->
        <table width="100%" cellpadding="0" cellspacing="0">
          <tr>
            <td width="48%" style="padding-right:6px;">
              <a href="' . $reviewUrl . '" style="display:block;background:#ED1E28;color:#ffffff;text-decoration:none;padding:14px 10px;border-radius:12px;font-weight:800;font-size:0.92rem;font-family:Arial,sans-serif;text-align:center;">Leave a Review &rarr;</a>
            </td>
            <td width="4%"></td>
            <td width="48%" style="padding-left:6px;">
              <a href="' . $trustpilotUrl . '" style="display:block;background:#00B67A;color:#ffffff;text-decoration:none;padding:14px 10px;border-radius:12px;font-weight:800;font-size:0.92rem;font-family:Arial,sans-serif;text-align:center;">' . $tpIcon . 'Review on Trustpilot</a>
            </td>
          </tr>
        </table>

        <!-- Fine print -->
        <p style="color:#44445a;font-size:0.75rem;margin:24px 0 0;text-align:center;line-height:1.6;">To receive your CHF ' . $amount . ' voucher, a review must be left both on our website and on Trustpilot. Voucher is valid for ' . $expiryMonths . ' month' . ($expiryMonths > 1 ? 's' : '') . ' from date of issue and on orders over CHF ' . $minStr . '. This link is personal to your order and expires after one use.</p>

      </td></tr>
    </table>

  </td></tr>
</table></td></tr></table>
</body></html>';
    return sendMail($toEmail, $toName, 'Get CHF ' . $amount . ' off — leave a review for your SwissBricks order!', $html);
}
