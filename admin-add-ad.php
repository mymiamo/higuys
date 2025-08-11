<?php
session_start();
require_once 'config.php';

 

// CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
function verify_csrf() {
    if (!isset($_POST['csrf_token']) 
     || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('Geçersiz CSRF.');
    }
}

$errors = [];
$success = false;

// Form gönderildiyse işle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // Alanlar
    $title       = trim($_POST['title']      ?? '');
    $description = trim($_POST['description']?? '');
    $target_url  = trim($_POST['target_url'] ?? '');
    $slot        = trim($_POST['slot']       ?? 'slider');
    $start_date  = $_POST['start_date'] ?: null;
    $end_date    = $_POST['end_date']   ?: null;
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if ($title === '') {
        $errors[] = 'Başlık boş bırakılamaz.';
    }

    // Resim yükleme
    $image_url = null;
    if (!empty($_FILES['image']['name'])) {
        $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg','jpeg','png','gif'];
        if (!in_array(strtolower($ext), $allowed)) {
            $errors[] = 'Sadece JPG/PNG/GIF yükleyebilirsiniz.';
        } else {
            $newName = uniqid('ad_') . '.' . $ext;
            $dest = __DIR__ . '/ads/' . $newName;
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
                $errors[] = 'Resim kaydedilemedi.';
            } else {
                // Web üzerinden erişilecek yol
                $image_url = '/ads/' . $newName;
            }
        }
    }

    if (empty($errors)) {
        // DB kaydı
        $sql = "INSERT INTO ads 
            (title, description, image_url, target_url, slot, start_date, end_date, is_active)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $title,
            $description,
            $image_url,
            $target_url,
            $slot,
            $start_date,
            $end_date,
            $is_active
        ]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Reklam Ekle</title>
  <link rel="stylesheet" href="path/to/your.css">
</head>
<body>
  <h1>Yeni Reklam Ekle</h1>

  <?php if ($success): ?>
    <p style="color:green;">Reklam başarıyla eklendi!</p>
  <?php endif; ?>

  <?php if ($errors): ?>
    <ul style="color:red;">
      <?php foreach ($errors as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <form action="" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

    <label>
      Başlık:<br>
      <input type="text" name="title" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
    </label><br><br>

    <label>
      Açıklama:<br>
      <textarea name="description" rows="4"><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
    </label><br><br>

    <label>
      Görsel (opsiyonel):<br>
      <input type="file" name="image" accept="image/*">
    </label><br><br>

    <label>
      Hedef URL:<br>
      <input type="url" name="target_url" value="<?= htmlspecialchars($_POST['target_url'] ?? '') ?>">
    </label><br><br>

    <label>
      Slot:<br>
      <select name="slot">
        <option value="slider" <?= (($_POST['slot'] ?? '')==='slider')?'selected':'' ?>>Slider</option>
        <option value="sidebar"<?= (($_POST['slot'] ?? '')==='sidebar')?'selected':'' ?>>Sidebar</option>
        <option value="header" <?= (($_POST['slot'] ?? '')==='header')?'selected':'' ?>>Header</option>
        <option value="footer" <?= (($_POST['slot'] ?? '')==='footer')?'selected':'' ?>>Footer</option>
      </select>
    </label><br><br>

    <label>
      Başlangıç Tarihi:<br>
      <input type="date" name="start_date" value="<?= htmlspecialchars($_POST['start_date'] ?? '') ?>">
    </label><br><br>

    <label>
      Bitiş Tarihi:<br>
      <input type="date" name="end_date" value="<?= htmlspecialchars($_POST['end_date'] ?? '') ?>">
    </label><br><br>

    <label>
      <input type="checkbox" name="is_active" <?= isset($_POST['is_active']) ? 'checked':'' ?>>
      Aktif Olsun
    </label><br><br>

    <button type="submit">Reklamı Kaydet</button>
  </form>

</body>
</html>
