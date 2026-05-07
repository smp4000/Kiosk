<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

session_start();

$messages = [];
$error    = null;
$resetDone = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset' && ($_POST['confirm'] ?? '') === 'JA') {
    try {
        reset_database(db());
        $resetDone = true;
        kiosk_log('Datenbank wurde komplett zurückgesetzt.', 'INFO');
    } catch (Throwable $e) {
        $error = 'Reset fehlgeschlagen: ' . $e->getMessage();
        kiosk_log($error, 'ERROR');
    }
}

try {
    $pdo = db();
    $messages[] = 'Datenbank "<code>' . htmlspecialchars(DB_NAME) . '</code>" ist vorhanden.';
    $messages[] = 'Alle Tabellen wurden geprüft / angelegt.';

    $tables = [
        'articles', 'article_issues',
        'invoices', 'order_lines', 'price_change_log', 'imports',
        'deliveries', 'delivery_items',
        'remi_packages', 'remi_items',
        'inventory_runs', 'inventory_items',
    ];
    $stats = [];
    foreach ($tables as $t) {
        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM `$t`");
        $row  = $stmt->fetch();
        $stats[$t] = (int)$row['c'];
    }

    $pdftotext = find_pdftotext();
    if ($pdftotext) {
        $messages[] = 'pdftotext gefunden: <code>' . htmlspecialchars($pdftotext) . '</code>';
    } else {
        $messages[] = '⚠ pdftotext NICHT gefunden. PDF-Textextraktion wird fehlschlagen. Installiere Poppler oder Git Bash und passe <code>config.php</code> an.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    kiosk_log('Install fehlgeschlagen: ' . $error, 'ERROR');
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Kiosk – Installation</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">
    <header>
        <h1>Kiosk <span class="muted">– Installation</span></h1>
        <nav><a href="index.php">Dashboard</a> · <a href="install.php" class="active">Installation</a></nav>
    </header>

    <?php if ($error): ?>
        <div class="msg error"><strong>Fehler:</strong> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($resetDone): ?>
        <div class="msg ok"><strong>Datenbank wurde komplett zurückgesetzt.</strong> Alle Tabellen sind leer.</div>
    <?php endif; ?>

    <section class="card">
        <h2>Status</h2>
        <?php foreach ($messages as $m): ?>
            <div class="msg ok"><?= $m ?></div>
        <?php endforeach; ?>

        <?php if (!empty($stats)): ?>
        <table>
            <thead><tr><th>Tabelle</th><th class="num">Datensätze</th></tr></thead>
            <tbody>
            <?php foreach ($stats as $t => $c): ?>
                <tr><td><code><?= htmlspecialchars($t) ?></code></td><td class="num"><?= number_format($c, 0, ',', '.') ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>

    <section class="card" style="border-color:#fecaca; background:#fef2f2;">
        <h2 style="color:#b91c1c;">Datenbank zurücksetzen</h2>
        <p>Löscht <strong>alle Tabellen und Daten</strong> und legt das Schema neu an. Nicht umkehrbar.</p>
        <form method="post" onsubmit="return confirm('Wirklich ALLE Daten löschen?');">
            <input type="hidden" name="action" value="reset">
            <label>Zur Bestätigung tippe <code>JA</code> in das Feld:
                <input type="text" name="confirm" required pattern="JA" style="margin-left:8px; padding:6px;">
            </label>
            <button type="submit" class="btn" style="background:#b91c1c; color:white; border-color:#b91c1c; margin-left:8px;">DB zurücksetzen</button>
        </form>
    </section>

    <p><a href="index.php" class="btn primary">Zum Dashboard →</a></p>
</div>
</body>
</html>
