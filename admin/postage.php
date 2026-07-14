<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
$adminTitle = 'Postage Rates';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_rates'])) {
    $keys = [
        'shipping_priority_2kg', 'shipping_priority_10kg', 'shipping_priority_30kg', 'shipping_priority_bulky',
        'shipping_economy_2kg',  'shipping_economy_10kg',  'shipping_economy_30kg',  'shipping_economy_bulky',
        'shipping_letter_standard_amail', 'shipping_letter_standard_bmail',
        'shipping_letter_midi_amail',     'shipping_letter_midi_bmail',
        'shipping_letter_thick_surcharge',
    ];
    foreach ($keys as $key) {
        if (isset($_POST[$key]) && is_numeric($_POST[$key])) {
            $val = number_format((float)$_POST[$key], 2, '.', '');
            $conn->query("INSERT INTO settings (setting_key, setting_value) VALUES ('$key','$val') ON DUPLICATE KEY UPDATE setting_value='$val'");
        }
    }
    $msg = 'Postage rates updated.';
}

// Load current rates
$rateRows = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'shipping_%'");
$rates = [];
while ($r = $rateRows->fetch_assoc()) $rates[$r['setting_key']] = $r['setting_value'];

$v = fn(string $key, string $fallback) => htmlspecialchars($rates[$key] ?? $fallback);

require_once __DIR__ . '/layout.php';
?>

<?php if ($msg): ?>
  <div class="alert alert-success" style="margin-bottom:20px;"><?= $msg ?></div>
<?php endif; ?>

<form method="POST">

  <div class="admin-card" style="margin-bottom:20px;">
    <div class="admin-card-header">
      <div class="admin-card-title">Swiss Post — Domestic Parcel Rates (CHF incl. VAT)</div>
      <div style="font-size:0.8rem;color:var(--text3);">Max dimensions: 100 × 60 × 60 cm &nbsp;·&nbsp; Max weight: 30 kg</div>
    </div>
    <div style="padding:24px;">

      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr>
            <th style="text-align:left;padding:10px 16px;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text3);border-bottom:1px solid var(--border);">Weight Tier</th>
            <th style="text-align:left;padding:10px 16px;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text3);border-bottom:1px solid var(--border);">Dimensions</th>
            <th style="text-align:center;padding:10px 16px;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text3);border-bottom:1px solid var(--border);">
              <span style="display:inline-flex;align-items:center;gap:6px;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                Swiss Post Priority
              </span>
            </th>
            <th style="text-align:center;padding:10px 16px;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text3);border-bottom:1px solid var(--border);">
              <span style="display:inline-flex;align-items:center;gap:6px;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                Swiss Post Economy
              </span>
            </th>
          </tr>
        </thead>
        <tbody>
          <?php
          $tiers = [
            ['key' => '2kg',   'label' => 'Up to 2 kg',   'dims' => 'A6 min — 100 × 60 × 60 cm max'],
            ['key' => '10kg',  'label' => 'Up to 10 kg',  'dims' => 'A6 min — 100 × 60 × 60 cm max'],
            ['key' => '30kg',  'label' => 'Up to 30 kg',  'dims' => 'A6 min — 100 × 60 × 60 cm max'],
            ['key' => 'bulky', 'label' => 'Bulky goods',  'dims' => 'Exceeds 100 × 60 × 60 cm'],
          ];
          foreach ($tiers as $i => $tier):
            $bg = $i % 2 === 0 ? 'background:var(--surface2);' : '';
          ?>
          <tr style="<?= $bg ?>">
            <td style="padding:14px 16px;font-weight:600;font-size:0.9rem;">
              <?= $tier['label'] ?>
              <?php if ($tier['key'] === 'bulky'): ?>
                <div style="font-size:0.72rem;color:var(--accent);font-weight:500;margin-top:2px;">Special rate</div>
              <?php endif; ?>
            </td>
            <td style="padding:14px 16px;font-size:0.82rem;color:var(--text3);"><?= $tier['dims'] ?></td>
            <td style="padding:14px 16px;text-align:center;">
              <div style="display:inline-flex;align-items:center;gap:6px;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:6px 12px;">
                <span style="font-size:0.82rem;color:var(--text3);">CHF</span>
                <input type="number" name="shipping_priority_<?= $tier['key'] ?>" step="0.05" min="0"
                       value="<?= $v('shipping_priority_' . $tier['key'], '') ?>"
                       style="width:60px;background:transparent;border:none;outline:none;font-size:0.95rem;font-weight:700;color:var(--accent);text-align:center;font-family:var(--font-head);">
              </div>
            </td>
            <td style="padding:14px 16px;text-align:center;">
              <div style="display:inline-flex;align-items:center;gap:6px;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:6px 12px;">
                <span style="font-size:0.82rem;color:var(--text3);">CHF</span>
                <input type="number" name="shipping_economy_<?= $tier['key'] ?>" step="0.05" min="0"
                       value="<?= $v('shipping_economy_' . $tier['key'], '') ?>"
                       style="width:60px;background:transparent;border:none;outline:none;font-size:0.95rem;font-weight:700;color:var(--accent);text-align:center;font-family:var(--font-head);">
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

    </div>
  </div>

  <div class="admin-card" style="margin-bottom:20px;">
    <div class="admin-card-header">
      <div class="admin-card-title">Swiss Post — Letters &amp; Small Consignments (CHF incl. VAT)</div>
      <div style="font-size:0.8rem;color:var(--text3);">Applies automatically when packed order fits within 25 × 17.6 × 5 cm and ≤ 500g</div>
    </div>
    <div style="padding:24px;">

      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr>
            <th style="text-align:left;padding:10px 16px;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text3);border-bottom:1px solid var(--border);">Format</th>
            <th style="text-align:left;padding:10px 16px;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text3);border-bottom:1px solid var(--border);">Conditions</th>
            <th style="text-align:center;padding:10px 16px;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text3);border-bottom:1px solid var(--border);">A-Mail (Priority)</th>
            <th style="text-align:center;padding:10px 16px;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:var(--text3);border-bottom:1px solid var(--border);">B-Mail (Economy)</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $letterTiers = [
            ['a' => 'shipping_letter_standard_amail', 'b' => 'shipping_letter_standard_bmail',
             'label' => 'Standard Letter', 'cond' => '≤ 25 × 17.6 × 2 cm &nbsp;·&nbsp; ≤ 100g', 'fa' => '1.20', 'fb' => '1.00'],
            ['a' => 'shipping_letter_midi_amail',     'b' => 'shipping_letter_midi_bmail',
             'label' => 'Midi Letter',     'cond' => '≤ 25 × 17.6 × 2 cm &nbsp;·&nbsp; 101–500g', 'fa' => '1.70', 'fb' => '1.40'],
          ];
          foreach ($letterTiers as $i => $t):
            $bg = $i % 2 === 0 ? 'background:var(--surface2);' : '';
          ?>
          <tr style="<?= $bg ?>">
            <td style="padding:14px 16px;font-weight:600;font-size:0.9rem;"><?= $t['label'] ?></td>
            <td style="padding:14px 16px;font-size:0.82rem;color:var(--text3);"><?= $t['cond'] ?></td>
            <td style="padding:14px 16px;text-align:center;">
              <div style="display:inline-flex;align-items:center;gap:6px;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:6px 12px;">
                <span style="font-size:0.82rem;color:var(--text3);">CHF</span>
                <input type="number" name="<?= $t['a'] ?>" step="0.05" min="0"
                       value="<?= $v($t['a'], $t['fa']) ?>"
                       style="width:60px;background:transparent;border:none;outline:none;font-size:0.95rem;font-weight:700;color:var(--accent);text-align:center;font-family:var(--font-head);">
              </div>
            </td>
            <td style="padding:14px 16px;text-align:center;">
              <div style="display:inline-flex;align-items:center;gap:6px;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:6px 12px;">
                <span style="font-size:0.82rem;color:var(--text3);">CHF</span>
                <input type="number" name="<?= $t['b'] ?>" step="0.05" min="0"
                       value="<?= $v($t['b'], $t['fb']) ?>"
                       style="width:60px;background:transparent;border:none;outline:none;font-size:0.95rem;font-weight:700;color:var(--accent);text-align:center;font-family:var(--font-head);">
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <!-- Thick surcharge row -->
          <tr>
            <td style="padding:14px 16px;font-weight:600;font-size:0.9rem;">Thick surcharge</td>
            <td style="padding:14px 16px;font-size:0.82rem;color:var(--text3);">Thickness 2–5 cm &nbsp;·&nbsp; added to any letter rate</td>
            <td colspan="2" style="padding:14px 16px;text-align:center;">
              <div style="display:inline-flex;align-items:center;gap:6px;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:6px 12px;">
                <span style="font-size:0.82rem;color:var(--text3);">+ CHF</span>
                <input type="number" name="shipping_letter_thick_surcharge" step="0.05" min="0"
                       value="<?= $v('shipping_letter_thick_surcharge', '2.00') ?>"
                       style="width:60px;background:transparent;border:none;outline:none;font-size:0.95rem;font-weight:700;color:var(--accent);text-align:center;font-family:var(--font-head);">
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="admin-card" style="margin-bottom:20px;">
    <div style="padding:20px;">
      <div style="font-size:0.8rem;color:var(--text3);line-height:1.7;margin-bottom:16px;">
        <strong style="color:var(--text2);">How prices are calculated at checkout:</strong><br>
        <strong style="color:var(--text2);">Letter path:</strong> If every item in the cart individually fits within 25 × 17.6 cm and the combined stacked height (+ 1cm buffer) ≤ 5cm and total weight ≤ 500g — letter rates apply automatically with a <strong style="color:var(--text2);">+1cm packing buffer</strong> on each dimension.<br><br>
        <strong style="color:var(--text2);">Parcel path:</strong> All other orders use a <strong style="color:var(--text2);">+5cm packing buffer</strong> on each dimension. Heights are stacked. If packed L &gt; 100cm or W/H &gt; 60cm → bulky goods rate.<br><br>
        <strong style="color:var(--text2);">Delivery times:</strong> Priority / A-Mail = next working day &nbsp;·&nbsp; Economy / B-Mail = 2–3 working days
      </div>
      <a href="https://www.post.ch/en/sending-parcels/domestic-parcels" target="_blank"
         style="font-size:0.8rem;color:var(--accent);text-decoration:none;">
        ↗ Swiss Post official price page
      </a>
    </div>
  </div>

  <button type="submit" name="save_rates" class="btn btn-primary">Save Rates</button>

</form>

<?php require_once __DIR__ . '/footer.php'; ?>
