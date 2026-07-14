<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
$adminTitle = 'Picture Proof Requests';
$msg = '';
$msgType = 'success';

// Handle photo upload + send email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_proof'])) {
    $reqId = (int)$_POST['request_id'];
    $req   = $conn->query("SELECT * FROM picture_proof_requests WHERE id = $reqId")->fetch_assoc();

    if ($req && !empty($_FILES['proof_images']['name'][0])) {
        $uploadDir  = __DIR__ . '/../uploads/picture_proof/' . $reqId . '/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $savedPaths    = [];
        $savedRelPaths = [];
        $allowed       = ['jpg','jpeg','png','gif','webp','heic','heif'];
        foreach ($_FILES['proof_images']['tmp_name'] as $i => $tmp) {
            if ($_FILES['proof_images']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($_FILES['proof_images']['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) continue;
            $filename = uniqid() . '.' . $ext;
            $dest     = $uploadDir . $filename;
            if (move_uploaded_file($tmp, $dest)) {
                // Convert HEIC/HEIF → JPEG for browser compatibility
                if (in_array($ext, ['heic', 'heif'])) {
                    $jpegDest  = substr($dest, 0, -strlen($ext)) . 'jpg';
                    $converted = false;
                    if (extension_loaded('imagick')) {
                        try {
                            $im = new \Imagick($dest);
                            $im->setImageFormat('jpeg');
                            $im->setImageCompressionQuality(90);
                            $im->writeImage($jpegDest);
                            $im->clear();
                            $converted = true;
                        } catch (\Exception $e) {}
                    }
                    if (!$converted) {
                        exec('convert ' . escapeshellarg($dest) . ' ' . escapeshellarg($jpegDest) . ' 2>&1', $out, $code);
                        if ($code === 0 && file_exists($jpegDest)) $converted = true;
                    }
                    if (!$converted) {
                        // macOS sips fallback (works on local MAMP)
                        exec('sips -s format jpeg ' . escapeshellarg($dest) . ' --out ' . escapeshellarg($jpegDest) . ' 2>&1', $out, $code);
                        if ($code === 0 && file_exists($jpegDest)) $converted = true;
                    }
                    if ($converted) {
                        unlink($dest);
                        $dest     = $jpegDest;
                        $filename = basename($jpegDest);
                        $ext      = 'jpg';
                    }
                }

                $savedPaths[]    = $dest;
                $relPath         = 'uploads/picture_proof/' . $reqId . '/' . $filename;
                $savedRelPaths[] = $relPath;
                $stmt2 = $conn->prepare('INSERT INTO picture_proof_photos (request_id, file_path) VALUES (?, ?)');
                $stmt2->bind_param('is', $reqId, $relPath);
                $stmt2->execute();
            }
        }

        if (!empty($savedPaths)) {
            $ok = sendPictureProofEmail($req['customer_email'], $req['customer_name'], $req['product_name'], $savedPaths, $req['reference_number'] ?? '', (int)($req['product_id'] ?? 0));
            if ($ok) {
                $conn->query("UPDATE picture_proof_requests SET status='sent', sent_at=NOW() WHERE id=$reqId");
                $msg = 'Photos sent to ' . htmlspecialchars($req['customer_email']) . '.';
            } else {
                $msg = 'Upload saved but email failed to send. Check SMTP settings.';
                $msgType = 'error';
            }
        } else {
            $msg = 'No valid images were uploaded.';
            $msgType = 'error';
        }
    } else {
        $msg = 'Please select at least one image.';
        $msgType = 'error';
    }
    header('Location: ' . BASE_URL . '/admin/picture-proof.php?msg=' . urlencode($msg) . '&type=' . $msgType);
    exit;
}

if (isset($_GET['msg'])) {
    $msg     = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'success';
}

$waiting = $conn->query("SELECT * FROM picture_proof_requests WHERE status='waiting' ORDER BY created_at DESC");
$sent    = $conn->query("SELECT * FROM picture_proof_requests WHERE status='sent'   ORDER BY sent_at DESC");

require_once __DIR__ . '/layout.php';
?>

<?php if ($msg): ?>
  <div class="alert alert-<?= $msgType === 'error' ? 'danger' : 'success' ?>" style="margin-bottom:20px;"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Waiting requests -->
<div class="admin-card" style="margin-bottom:28px;">
  <div class="admin-card-header">
    <div class="admin-card-title">
      Waiting
      <?php $wCount = $waiting->num_rows; ?>
      <?php if ($wCount > 0): ?>
        <span style="background:rgba(237,30,40,0.12);color:var(--accent);border:1px solid rgba(237,30,40,0.3);border-radius:20px;padding:2px 10px;font-size:0.72rem;font-weight:800;margin-left:8px;"><?= $wCount ?></span>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($wCount === 0): ?>
    <div style="padding:40px;text-align:center;color:var(--text3);font-size:0.9rem;">No pending requests.</div>
  <?php else: ?>
    <div style="padding:16px 20px;display:flex;flex-direction:column;gap:16px;">
    <?php while ($r = $waiting->fetch_assoc()): ?>
      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
        <!-- Header row -->
        <div style="display:flex;align-items:center;gap:14px;padding:16px 20px;border-bottom:1px solid var(--border);">
          <?php if (!empty($r['product_image'])): ?>
            <img src="<?= htmlspecialchars($r['product_image']) ?>" alt="" style="width:52px;height:52px;object-fit:contain;border-radius:8px;background:#fff;flex-shrink:0;">
          <?php endif; ?>
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px;">
              <div style="font-weight:700;font-size:0.95rem;color:var(--text);"><?= htmlspecialchars($r['product_name']) ?></div>
              <?php if (!empty($r['reference_number'])): ?>
                <span style="font-size:0.72rem;font-weight:700;color:var(--accent);letter-spacing:0.04em;"><?= htmlspecialchars($r['reference_number']) ?></span>
              <?php endif; ?>
            </div>
            <div style="font-size:0.82rem;color:var(--text3);"><?= htmlspecialchars($r['customer_name']) ?> &middot; <?= htmlspecialchars($r['customer_email']) ?></div>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <div style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:#f59e0b;background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.3);border-radius:20px;padding:3px 10px;margin-bottom:4px;">WAITING</div>
            <div style="font-size:0.75rem;color:var(--text3);"><?= date('d M Y', strtotime($r['created_at'])) ?></div>
          </div>
        </div>
        <!-- Upload form -->
        <form method="POST" enctype="multipart/form-data" style="padding:16px 20px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
          <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="send_proof" value="1">
          <label style="display:inline-flex;align-items:center;gap:8px;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:8px 14px;cursor:pointer;font-size:0.84rem;color:var(--text2);font-weight:600;transition:border-color 0.18s;"
                 onmouseover="this.style.borderColor='#6b6b80'" onmouseout="this.style.borderColor='var(--border)'">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Choose Photos
            <input type="file" name="proof_images[]" multiple accept="image/*" style="display:none;" onchange="updateFileLabel(this)">
          </label>
          <span id="file-label-<?= $r['id'] ?>" style="font-size:0.8rem;color:var(--text3);">No files selected</span>
          <button type="submit" class="btn btn-primary btn-sm" style="margin-left:auto;"
                  onclick="return confirm('Send these photos to <?= htmlspecialchars(addslashes($r['customer_email'])) ?>?')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            Send Photos
          </button>
        </form>
      </div>
    <?php endwhile; ?>
    </div>
  <?php endif; ?>
</div>

<!-- Sent requests -->
<div class="admin-card">
  <div class="admin-card-header">
    <div class="admin-card-title">Sent</div>
  </div>

  <?php if ($sent->num_rows === 0): ?>
    <div style="padding:40px;text-align:center;color:var(--text3);font-size:0.9rem;">No sent requests yet.</div>
  <?php else: ?>
    <div style="padding:16px 20px;display:flex;flex-direction:column;gap:12px;">
    <?php while ($r = $sent->fetch_assoc()):
      $rid     = (int)$r['id'];
      $photos  = $conn->query("SELECT file_path FROM picture_proof_photos WHERE request_id=$rid ORDER BY id ASC");
      $photoRows = [];
      while ($ph = $photos->fetch_assoc()) $photoRows[] = $ph;
    ?>
      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
        <!-- Header row — clickable if photos exist -->
        <div style="display:flex;align-items:center;gap:14px;padding:14px 16px;<?= !empty($photoRows) ? 'cursor:pointer;' : '' ?>"
             <?= !empty($photoRows) ? 'onclick="toggleSentProof(' . $rid . ', this)"' : '' ?>>
          <?php if (!empty($r['product_image'])): ?>
            <img src="<?= htmlspecialchars($r['product_image']) ?>" alt="" style="width:44px;height:44px;object-fit:contain;border-radius:6px;background:#fff;flex-shrink:0;opacity:0.7;">
          <?php endif; ?>
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px;">
              <div style="font-weight:700;font-size:0.9rem;color:var(--text2);"><?= htmlspecialchars($r['product_name']) ?></div>
              <?php if (!empty($r['reference_number'])): ?>
                <span style="font-size:0.72rem;font-weight:700;color:var(--accent);opacity:0.7;letter-spacing:0.04em;"><?= htmlspecialchars($r['reference_number']) ?></span>
              <?php endif; ?>
            </div>
            <div style="font-size:0.8rem;color:var(--text3);"><?= htmlspecialchars($r['customer_name']) ?> &middot; <?= htmlspecialchars($r['customer_email']) ?></div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;">
            <div style="text-align:right;">
              <div style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:#22c55e;background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.25);border-radius:20px;padding:3px 10px;margin-bottom:4px;">SENT</div>
              <div style="font-size:0.75rem;color:var(--text3);"><?= $r['sent_at'] ? date('d M Y', strtotime($r['sent_at'])) : '' ?></div>
            </div>
            <?php if (!empty($photoRows)): ?>
              <svg id="chevron-<?= $rid ?>" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--text3)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="transition:transform 0.2s;flex-shrink:0;"><polyline points="6 9 12 15 18 9"/></svg>
            <?php endif; ?>
          </div>
        </div>
        <!-- Expandable photo grid -->
        <?php if (!empty($photoRows)): ?>
          <div id="sent-proof-<?= $rid ?>" style="display:none;border-top:1px solid var(--border);padding:16px;">
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;">
              <?php foreach ($photoRows as $ph):
                $ext     = strtolower(pathinfo($ph['file_path'], PATHINFO_EXTENSION));
                $isHeic  = in_array($ext, ['heic', 'heif']);
                $photoUrl = BASE_URL . '/' . $ph['file_path'];
              ?>
                <?php if ($isHeic): ?>
                  <a href="<?= htmlspecialchars($photoUrl) ?>" download
                     style="display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;border-radius:8px;aspect-ratio:1;background:var(--surface);border:1px solid var(--border);text-decoration:none;color:var(--text3);font-size:0.72rem;transition:border-color 0.15s;"
                     onmouseover="this.style.borderColor='#6b6b80'" onmouseout="this.style.borderColor='var(--border)'">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download HEIC
                  </a>
                <?php else: ?>
                  <div style="position:relative;border-radius:8px;overflow:hidden;aspect-ratio:1;background:var(--surface);">
                    <a href="<?= htmlspecialchars($photoUrl) ?>" target="_blank" style="display:block;width:100%;height:100%;">
                      <img src="<?= htmlspecialchars($photoUrl) ?>" alt="Proof photo" style="width:100%;height:100%;object-fit:cover;transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
                    </a>
                    <a href="<?= htmlspecialchars($photoUrl) ?>" download target="_blank" title="Download"
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
      </div>
    <?php endwhile; ?>
    </div>
  <?php endif; ?>
</div>

<script>
function updateFileLabel(input) {
  var label = input.closest('form').querySelector('[id^="file-label-"]');
  if (!label) return;
  var count = input.files.length;
  label.textContent = count === 0 ? 'No files selected' : count + ' file' + (count > 1 ? 's' : '') + ' selected';
}

function toggleSentProof(id, header) {
  var panel   = document.getElementById('sent-proof-' + id);
  var chevron = document.getElementById('chevron-' + id);
  var open    = panel.style.display === 'none' || panel.style.display === '';
  panel.style.display = open ? 'block' : 'none';
  if (chevron) chevron.style.transform = open ? 'rotate(180deg)' : 'rotate(0deg)';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
