<?php

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/prenotazioni-anagrafica-sync.php';
require_once __DIR__ . '/../includes/alloggiati.php';
require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . admin_url('anagrafica.php')); exit; }
verify_csrf();
$month = trim((string) ($_POST['month'] ?? ''));
$day = trim((string) ($_POST['day'] ?? ''));
$prenotazioneId = (int) ($_POST['prenotazione_id'] ?? 0);
$recordId = (int) ($_POST['linked_record_id'] ?? 0);
$params=[]; if($month!=='')$params['month']=$month; if($day!=='')$params['day']=$day;
$redirect = admin_url('anagrafica.php' . ($params ? ('?' . http_build_query($params)) : ''));
if ($prenotazioneId <= 0 && $recordId <= 0) { set_flash('error','Elemento non valido.'); header('Location: '.$redirect); exit; }
try {
    $pdo->beginTransaction();
    $linkedBookingId = $prenotazioneId;
    $linkedRecordId = $recordId;

    if ($linkedRecordId <= 0 && $linkedBookingId > 0 && anagrafica_prenotazione_link_column_ready($pdo)) {
        $stmt = $pdo->prepare('SELECT id FROM anagrafica_records WHERE prenotazione_id = :prenotazione_id LIMIT 1');
        $stmt->execute(['prenotazione_id' => $linkedBookingId]);
        $linkedRecordId = (int) ($stmt->fetchColumn() ?: 0);
    }

    if ($linkedRecordId > 0 && $linkedBookingId <= 0 && anagrafica_prenotazione_link_column_ready($pdo)) {
        $stmt = $pdo->prepare('SELECT prenotazione_id FROM anagrafica_records WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $linkedRecordId]);
        $linkedBookingId = (int) ($stmt->fetchColumn() ?: 0);
    }

    if ($linkedRecordId > 0 && alloggiati_schedine_table_ready($pdo)) {
        $pdo->prepare('DELETE FROM alloggiati_schedine WHERE record_id = :record_id')->execute(['record_id' => $linkedRecordId]);
    }
    if ($linkedRecordId > 0) {
        $pdo->prepare('DELETE FROM anagrafica_records WHERE id = :id')->execute(['id' => $linkedRecordId]);
    }
    if ($linkedBookingId > 0) {
        $pdo->prepare('DELETE FROM prenotazioni WHERE id = :id LIMIT 1')->execute(['id' => $linkedBookingId]);
    }
    $pdo->commit();
    set_flash('success', 'Elemento eliminato correttamente.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    set_flash('error', "Errore durante l'eliminazione: " . $e->getMessage());
}
header('Location: ' . $redirect);
exit;
