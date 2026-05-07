<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

session_start();

$pdo = db();

$totalArticles = (int)$pdo->query("SELECT COUNT(*) FROM articles")->fetchColumn();
$totalIssues   = (int)$pdo->query("SELECT COUNT(*) FROM article_issues")->fetchColumn();
$totalInvoices = (int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
$totalLines    = (int)$pdo->query("SELECT COUNT(*) FROM order_lines")->fetchColumn();
$totalChanges  = (int)$pdo->query("SELECT COUNT(*) FROM price_change_log")->fetchColumn();
$pendingArt    = (int)$pdo->query("SELECT COUNT(*) FROM articles WHERE is_pending = 1")->fetchColumn();

$recentImports = $pdo->query("
    SELECT i.*, inv.rechnungsnummer, inv.supplier
      FROM imports i
      LEFT JOIN invoices inv ON inv.id = i.invoice_id
     ORDER BY i.created_at DESC
     LIMIT 10
")->fetchAll();

$recentChanges = $pdo->query("
    SELECT *
      FROM price_change_log
     ORDER BY changed_at DESC
     LIMIT 10
")->fetchAll();

$recentLines = $pdo->query("
    SELECT ol.*, a.bezeichnung, a.ean, a.weekday, a.objekt, inv.rechnungsnummer
      FROM order_lines ol
      JOIN articles a   ON a.id = ol.article_id
      JOIN invoices inv ON inv.id = ol.invoice_id
     ORDER BY ol.created_at DESC, ol.id DESC
     LIMIT 50
")->fetchAll();

$articlesList = $pdo->query("
    SELECT a.*,
           (SELECT COUNT(*) FROM article_issues ai WHERE ai.article_id = a.id) AS issues_count,
           (SELECT GROUP_CONCAT(ai.ausgabe ORDER BY ai.ausgabe DESC SEPARATOR ', ')
              FROM article_issues ai WHERE ai.article_id = a.id) AS issues_list
      FROM articles a
     ORDER BY a.bezeichnung
     LIMIT 200
")->fetchAll();

$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

function de_money($v): string
{
    if ($v === null || $v === '') return '–';
    return number_format((float)$v, 2, ',', '.') . ' €';
}
function de_money4($v): string
{
    if ($v === null || $v === '') return '–';
    return number_format((float)$v, 4, ',', '.') . ' €';
}
function de_qty($v): string
{
    return (string)(int)$v;
}
function de_date($v): string
{
    if (!$v) return '–';
    $ts = strtotime((string)$v);
    return $ts ? date('d.m.Y', $ts) : (string)$v;
}
function de_dt($v): string
{
    if (!$v) return '–';
    $ts = strtotime((string)$v);
    return $ts ? date('d.m.Y H:i', $ts) : (string)$v;
}
function weekday_label(?int $d): string
{
    return [1=>'Mo',2=>'Di',3=>'Mi',4=>'Do',5=>'Fr',6=>'Sa',7=>'So'][$d] ?? '–';
}
function marge(?float $vkp_brutto, ?float $ek, ?float $mwst): ?float
{
    if (!$vkp_brutto || !$ek || $mwst === null) return null;
    $ek_brutto = $ek * (1 + $mwst/100);
    return $vkp_brutto - $ek_brutto;
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<title>Kiosk – Zeitungsrechnungs-Dashboard</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="container">

<header>
    <h1>Kiosk <span class="muted">– Zeitungsrechnungs-Dashboard</span></h1>
    <nav><a href="index.php" class="active">Dashboard</a> · <a href="install.php">Installation</a></nav>
</header>

<?php foreach ($flashes as $f): ?>
    <div class="msg <?= htmlspecialchars($f['type']) ?>"><?= $f['msg'] ?></div>
<?php endforeach; ?>

<section class="kpi-row">
    <div class="kpi"><div class="num"><?= number_format($totalInvoices, 0, ',', '.') ?></div><div class="lbl">Rechnungen</div></div>
    <div class="kpi"><div class="num"><?= number_format($totalArticles, 0, ',', '.') ?></div><div class="lbl">Artikel</div></div>
    <div class="kpi"><div class="num"><?= number_format($totalIssues, 0, ',', '.') ?></div><div class="lbl">Ausgaben</div></div>
    <div class="kpi"><div class="num"><?= number_format($totalLines, 0, ',', '.') ?></div><div class="lbl">Positionen</div></div>
    <div class="kpi"><div class="num"><?= number_format($totalChanges, 0, ',', '.') ?></div><div class="lbl">Preisänderungen</div></div>
    <?php if ($pendingArt > 0): ?>
    <div class="kpi" style="background:#fef3c7;"><div class="num" style="color:#b45309;"><?= $pendingArt ?></div><div class="lbl">Pending</div></div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>PDF-Rechnung hochladen</h2>
    <form method="post" action="upload.php" enctype="multipart/form-data" class="upload">
        <input type="file" name="pdf" accept="application/pdf" required>
        <button type="submit" class="btn primary">Hochladen &amp; Importieren</button>
    </form>
    <p class="hint">PVG-Rechnungen oder ZUGFeRD/Factur-X-Rechnungen. EK kommt aus Rechnung, VKP aus EAN-Code.</p>
</section>

<section class="card">
    <h2>Letzte Importe</h2>
    <?php if (!$recentImports): ?>
        <p class="muted">Noch keine Importe.</p>
    <?php else: ?>
    <table>
        <thead>
        <tr>
            <th>Zeitpunkt</th>
            <th>Datei</th>
            <th>Rechnung</th>
            <th>Status</th>
            <th class="num">Neu Art.</th>
            <th class="num">Preisänd.</th>
            <th class="num">Übersp.</th>
            <th>Fehler</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($recentImports as $imp): ?>
            <tr>
                <td><?= htmlspecialchars(de_dt($imp['created_at'])) ?></td>
                <td><?= htmlspecialchars($imp['filename']) ?></td>
                <td><?= htmlspecialchars($imp['rechnungsnummer'] ?? '–') ?></td>
                <td><span class="status status-<?= $imp['status'] ?>"><?= $imp['status'] ?></span></td>
                <td class="num"><?= (int)$imp['inserted_count'] ?></td>
                <td class="num"><?= (int)$imp['updated_count'] ?></td>
                <td class="num"><?= (int)$imp['skipped_count'] ?></td>
                <td><?= htmlspecialchars($imp['error_message'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Artikel-Stammdaten <span class="muted">(VKP aus EAN, EK aus Rechnung)</span></h2>
    <?php if (!$articlesList): ?>
        <p class="muted">Noch keine Artikel. Lade eine PVG-Rechnung hoch.</p>
    <?php else: ?>
    <table>
        <thead>
        <tr>
            <th>Bezeichnung</th>
            <th>Objekt</th>
            <th>EAN</th>
            <th>Tag</th>
            <th class="num">VKP brutto</th>
            <th class="num">EK netto</th>
            <th class="num">Marge</th>
            <th class="num">MwSt</th>
            <th class="num">Ausgaben</th>
            <th>Letzte KW</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($articlesList as $a):
            $m = marge((float)$a['aktueller_preis_brutto'], $a['ek'] === null ? null : (float)$a['ek'], (float)$a['mwst_satz']);
        ?>
            <tr<?= $a['is_pending'] ? ' style="background:#fef3c7;"' : '' ?>>
                <td><?= htmlspecialchars($a['bezeichnung']) ?><?= $a['is_pending'] ? ' <span class="badge b-rem">pending</span>' : '' ?></td>
                <td><code><?= htmlspecialchars($a['objekt']) ?></code></td>
                <td><code><?= htmlspecialchars($a['ean']) ?></code></td>
                <td><?= weekday_label($a['weekday'] === null ? null : (int)$a['weekday']) ?></td>
                <td class="num"><?= de_money($a['aktueller_preis_brutto']) ?></td>
                <td class="num"><?= de_money4($a['ek']) ?></td>
                <td class="num <?= $m === null ? '' : ($m > 0 ? 'pos' : 'neg') ?>"><?= $m === null ? '–' : de_money($m) ?></td>
                <td class="num"><?= number_format((float)$a['mwst_satz'], 0, ',', '') ?> %</td>
                <td class="num"><?= (int)$a['issues_count'] ?></td>
                <td><span class="muted" style="font-size:11px;"><?= htmlspecialchars((string)($a['issues_list'] ?? '')) ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Letzte Preisänderungen</h2>
    <?php if (!$recentChanges): ?>
        <p class="muted">Noch keine Preisänderungen.</p>
    <?php else: ?>
    <table>
        <thead>
        <tr>
            <th>Zeitpunkt</th>
            <th>Was</th>
            <th>Artikel</th>
            <th>EAN</th>
            <th class="num">VKP alt</th>
            <th class="num">VKP neu</th>
            <th class="num">EK alt</th>
            <th class="num">EK neu</th>
            <th class="num">MwSt</th>
            <th>Rechnung</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($recentChanges as $c): ?>
            <tr>
                <td><?= htmlspecialchars(de_dt($c['changed_at'])) ?></td>
                <td><span class="badge b-lief"><?= htmlspecialchars($c['change_type']) ?></span></td>
                <td><?= htmlspecialchars($c['bezeichnung']) ?></td>
                <td><code><?= htmlspecialchars($c['ean']) ?></code></td>
                <td class="num"><?= de_money($c['old_vkp_brutto']) ?></td>
                <td class="num"><?= de_money($c['new_vkp_brutto']) ?></td>
                <td class="num"><?= de_money4($c['old_ek']) ?></td>
                <td class="num"><?= de_money4($c['new_ek']) ?></td>
                <td class="num"><?= number_format((float)$c['old_mwst_satz'], 0, ',', '') ?>→<?= number_format((float)$c['new_mwst_satz'], 0, ',', '') ?> %</td>
                <td><?= htmlspecialchars($c['rechnungsnummer']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Letzte Positionen</h2>
    <?php if (!$recentLines): ?>
        <p class="muted">Noch keine Positionen.</p>
    <?php else: ?>
    <table>
        <thead>
        <tr>
            <th>Typ</th>
            <th>Bezeichnung</th>
            <th>EAN</th>
            <th>Tag</th>
            <th>KW</th>
            <th class="num">Menge</th>
            <th class="num">EK netto</th>
            <th class="num">Gesamt netto</th>
            <th>Lieferschein/Paket</th>
            <th>Rechnung</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($recentLines as $ol):
            $isRem = $ol['typ'] === 'remission';
        ?>
            <tr class="<?= $isRem ? 'rem' : 'lief' ?>">
                <td><span class="badge <?= $isRem ? 'b-rem' : 'b-lief' ?>"><?= $isRem ? 'Remi' : 'Lief.' ?></span></td>
                <td><?= htmlspecialchars($ol['bezeichnung']) ?></td>
                <td><code><?= htmlspecialchars($ol['ean']) ?></code></td>
                <td><?= weekday_label($ol['weekday'] === null ? null : (int)$ol['weekday']) ?></td>
                <td><?= htmlspecialchars($ol['ausgabe']) ?></td>
                <td class="num <?= $ol['menge'] < 0 ? 'neg' : '' ?>"><?= de_qty($ol['menge']) ?></td>
                <td class="num"><?= de_money4($ol['einzelpreis_netto']) ?></td>
                <td class="num <?= (float)$ol['gesamt_netto'] < 0 ? 'neg' : '' ?>"><?= de_money($ol['gesamt_netto']) ?></td>
                <td>
                    <?php if ($ol['lieferschein_nr']): ?>
                        #<?= htmlspecialchars($ol['lieferschein_nr']) ?>
                        <span class="muted"><?= de_date($ol['lieferschein_datum']) ?></span>
                    <?php elseif ($ol['paket']): ?>
                        Paket <?= htmlspecialchars($ol['paket']) ?>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($ol['rechnungsnummer']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<footer><p class="muted">Kiosk · Lokal über XAMPP · <?= date('Y') ?></p></footer>

</div>
</body>
</html>
