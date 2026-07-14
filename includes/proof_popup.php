<?php
if (!isLoggedIn() || !isset($conn)) return;

$user = getCurrentUser($conn);
if (!$user) return;

$email    = $conn->real_escape_string($user['email']);
$proofs   = $conn->query("SELECT * FROM picture_proof_requests WHERE customer_email='$email' AND status='sent' AND popup_shown=0 ORDER BY sent_at DESC");
if (!$proofs || $proofs->num_rows === 0) return;

$proofRows = [];
while ($p = $proofs->fetch_assoc()) {
    $rid    = (int)$p['id'];
    $photos = $conn->query("SELECT file_path FROM picture_proof_photos WHERE request_id=$rid ORDER BY id ASC");
    $p['web_photos'] = [];
    while ($ph = $photos->fetch_assoc()) {
        $p['web_photos'][] = [
            'url'  => BASE_URL . '/' . $ph['file_path'],
            'heic' => in_array(strtolower(pathinfo($ph['file_path'], PATHINFO_EXTENSION)), ['heic', 'heif']),
        ];
    }
    $proofRows[] = $p;
}

if (empty($proofRows)) return;
?>

<!-- Picture Proof Popup -->
<div id="proof-popup-overlay" style="position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.7);backdrop-filter:blur(4px);" onclick="if(event.target===this)closeProofPopup()">
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:20px;max-width:640px;width:calc(100% - 32px);max-height:90vh;overflow-y:auto;box-shadow:0 24px 80px rgba(0,0,0,0.6);">

    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:24px 28px 20px;border-bottom:1px solid var(--border);">
      <div style="display:flex;align-items:center;gap:12px;">
        <div style="width:40px;height:40px;background:rgba(237,30,40,0.12);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ED1E28" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
        </div>
        <div style="font-weight:800;font-size:1.05rem;color:var(--text);">Picture Proof Received</div>
      </div>
      <button onclick="closeProofPopup()" style="background:none;border:none;cursor:pointer;color:var(--text3);padding:4px;border-radius:6px;transition:color 0.15s;" onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--text3)'">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <!-- Proof items -->
    <div style="padding:20px 28px;display:flex;flex-direction:column;gap:20px;">
    <?php foreach ($proofRows as $p): ?>
      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:14px;overflow:hidden;" data-proof-id="<?= (int)$p['id'] ?>">

        <!-- Product info -->
        <div style="padding:16px 20px;border-bottom:1px solid var(--border);">
          <div style="font-weight:700;font-size:0.92rem;color:var(--text);"><?= htmlspecialchars($p['product_name']) ?></div>
          <?php if (!empty($p['reference_number'])): ?>
            <div style="font-size:0.72rem;font-weight:700;color:var(--accent);letter-spacing:0.04em;margin-top:2px;"><?= htmlspecialchars($p['reference_number']) ?></div>
          <?php endif; ?>
        </div>

        <!-- Photo grid -->
        <?php if (!empty($p['web_photos'])): ?>
          <div style="padding:16px 20px;">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;">
              <?php foreach ($p['web_photos'] as $photo): ?>
                <?php if ($photo['heic']): ?>
                  <a href="<?= htmlspecialchars($photo['url']) ?>" target="_blank" download
                     style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;border-radius:8px;aspect-ratio:1;background:#1a1a2a;border:1px solid var(--border);text-decoration:none;color:var(--text3);font-size:0.72rem;transition:border-color 0.15s;"
                     onmouseover="this.style.borderColor='#6b6b80'" onmouseout="this.style.borderColor='var(--border)'">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    Download HEIC
                  </a>
                <?php else: ?>
                  <div style="position:relative;border-radius:8px;overflow:hidden;aspect-ratio:1;background:#0a0a0f;">
                    <a href="<?= htmlspecialchars($photo['url']) ?>" target="_blank" style="display:block;width:100%;height:100%;">
                      <img src="<?= htmlspecialchars($photo['url']) ?>" alt="Proof photo" style="width:100%;height:100%;object-fit:cover;transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
                    </a>
                    <a href="<?= htmlspecialchars($photo['url']) ?>" download target="_blank" title="Download"
                       style="position:absolute;top:6px;right:6px;background:rgba(0,0,0,0.65);border-radius:6px;padding:5px;display:flex;align-items:center;justify-content:center;color:#fff;backdrop-filter:blur(4px);transition:background 0.15s;"
                       onmouseover="this.style.background='rgba(237,30,40,0.85)'" onmouseout="this.style.background='rgba(0,0,0,0.65)'">
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </a>
                  </div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Buy Now -->
        <div style="padding:0 20px 16px;">
          <a href="<?= BASE_URL ?>/pages/buy_now.php?product_id=<?= (int)$p['product_id'] ?>" onclick="closeProofPopup()" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 20px;background:var(--accent);border-radius:10px;color:#fff;font-weight:800;font-size:0.9rem;text-decoration:none;transition:opacity 0.15s;" onmouseover="this.style.opacity='0.88'" onmouseout="this.style.opacity='1'">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            Buy Now
          </a>
        </div>

      </div>
    <?php endforeach; ?>
    </div>

    <!-- Footer -->
    <div style="padding:0 28px 24px;">
      <a href="<?= BASE_URL ?>/pages/myaccount.php?tab=picture-proof" style="font-size:0.82rem;color:var(--text3);text-decoration:none;transition:color 0.15s;" onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--text3)'">Go to picture proof →</a>
    </div>

  </div>
</div>

<script>
(function() {
  var proofIds = [<?= implode(',', array_map(fn($p) => (int)$p['id'], $proofRows)) ?>];

  function markSeen() {
    proofIds.forEach(function(id) {
      var fd = new FormData();
      fd.append('proof_id', id);
      var url = '<?= BASE_URL ?>/pages/mark_proof_seen.php';
      if (navigator.sendBeacon) {
        navigator.sendBeacon(url, fd);
      } else {
        fetch(url, { method: 'POST', body: fd });
      }
    });
  }

  window.closeProofPopup = function() {
    var el = document.getElementById('proof-popup-overlay');
    if (el) {
      el.style.opacity = '0';
      el.style.transition = 'opacity 0.2s';
      setTimeout(function() { el.remove(); }, 200);
    }
    markSeen();
  };

  var overlay = document.getElementById('proof-popup-overlay');
  if (overlay) {
    overlay.style.opacity = '0';
    overlay.style.transition = 'opacity 0.25s';
    requestAnimationFrame(function() {
      requestAnimationFrame(function() { overlay.style.opacity = '1'; });
    });
  }
})();
</script>
