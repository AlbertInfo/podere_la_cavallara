<?php
$prefix = sprintf('guests[%d]', (int) $guestIndex);
$currentRecordType = $currentRecordType ?? 'single';

$fieldValue = static function (array $source, string $key, string $fallback = ''): string {
    $value = $source[$key] ?? $fallback;
    return is_string($value) || is_numeric($value) ? (string) $value : $fallback;
};

$fieldDate = static function (array $source, string $key): string {
    $value = trim((string) ($source[$key] ?? ''));
    if ($value === '') {
        return '';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : $value;
};

$defaultTipoAlloggiato = $isRepeaterGuest
    ? '20'
    : ($currentRecordType === 'group' ? '18' : '16');
?>
<div class="anagrafica-guest-card" <?= $isRepeaterGuest ? 'data-guest-card' : '' ?>>
    <input type="hidden" name="<?= e($prefix) ?>[guest_idswh]" value="<?= e($fieldValue($guestData, 'guest_idswh')) ?>">

    <?php if ($isRepeaterGuest): ?>
        <div class="anagrafica-guest-card__top">
            <div>
                <strong>Componente <span data-guest-number><?= e((string) $guestNumber) ?></span></strong>
                <p class="muted">Questo ospite verrà collegato al capogruppo.</p>
            </div>
            <button class="btn btn-light btn-sm" type="button" data-remove-guest>Rimuovi</button>
        </div>
    <?php endif; ?>

    <div class="anagrafica-guest-block">
        <div class="anagrafica-guest-block__title">Anagrafica e documento</div>
        <div class="anagrafica-grid">
            <label>
                <span>Nome</span>
                <input type="text" name="<?= e($prefix) ?>[first_name]" maxlength="100" value="<?= e($fieldValue($guestData, 'first_name')) ?>" required>
            </label>
            <label>
                <span>Cognome</span>
                <input type="text" name="<?= e($prefix) ?>[last_name]" maxlength="100" value="<?= e($fieldValue($guestData, 'last_name')) ?>" required>
            </label>
            <label>
                <span>Sesso</span>
                <select name="<?= e($prefix) ?>[gender]" required>
                    <option value="M" <?= $fieldValue($guestData, 'gender', 'M') === 'M' ? 'selected' : '' ?>>Maschio</option>
                    <option value="F" <?= $fieldValue($guestData, 'gender') === 'F' ? 'selected' : '' ?>>Femmina</option>
                </select>
            </label>
            <label>
                <span>Data di nascita</span>
                <input type="text" name="<?= e($prefix) ?>[birth_date]" class="js-date" data-date-role="birth" value="<?= e($fieldDate($guestData, 'birth_date')) ?>" placeholder="Seleziona la data" autocomplete="off" required>
            </label>
            <label>
                <span>Cittadinanza</span>
                <input list="citizenship-options" name="<?= e($prefix) ?>[citizenship_label]" value="<?= e($fieldValue($guestData, 'citizenship_label')) ?>" placeholder="Seleziona o digita" required>
            </label>
            <label>
                <span>Provincia di residenza</span>
                <input list="province-options" name="<?= e($prefix) ?>[residence_province]" value="<?= e($fieldValue($guestData, 'residence_province')) ?>" placeholder="Seleziona o digita">
            </label>
            <label>
                <span>Luogo di residenza</span>
                <input type="text" name="<?= e($prefix) ?>[residence_place]" maxlength="120" value="<?= e($fieldValue($guestData, 'residence_place')) ?>">
            </label>
            <label>
                <span>Tipologia documento</span>
                <select name="<?= e($prefix) ?>[document_type]" required>
                    <?php foreach ($documentTypes as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $fieldValue($guestData, 'document_type', 'carta_identita') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>N. documento</span>
                <input type="text" name="<?= e($prefix) ?>[document_number]" maxlength="50" value="<?= e($fieldValue($guestData, 'document_number')) ?>" required>
            </label>
            <label>
                <span>Data documento</span>
                <input type="text" name="<?= e($prefix) ?>[document_issue_date]" class="js-date" data-date-role="document-issue" value="<?= e($fieldDate($guestData, 'document_issue_date')) ?>" placeholder="Seleziona la data" autocomplete="off">
            </label>
            <label>
                <span>Scadenza documento</span>
                <input type="text" name="<?= e($prefix) ?>[document_expiry_date]" class="js-date" data-date-role="document-expiry" value="<?= e($fieldDate($guestData, 'document_expiry_date')) ?>" placeholder="Seleziona la data" autocomplete="off" required>
            </label>
            <label>
                <span>Luogo di emissione</span>
                <input list="city-options" name="<?= e($prefix) ?>[document_issue_place]" value="<?= e($fieldValue($guestData, 'document_issue_place')) ?>" placeholder="Seleziona o digita" required>
            </label>
            <label>
                <span>Email</span>
                <input type="email" name="<?= e($prefix) ?>[email]" maxlength="190" value="<?= e($fieldValue($guestData, 'email')) ?>">
            </label>
            <label>
                <span>Telefono</span>
                <input type="text" name="<?= e($prefix) ?>[phone]" maxlength="40" value="<?= e($fieldValue($guestData, 'phone')) ?>">
            </label>
        </div>
    </div>

    <div class="anagrafica-guest-block anagrafica-guest-block--ross">
        <div class="anagrafica-guest-block__title">Codifiche ROSS1000</div>
        <div class="anagrafica-grid anagrafica-grid--compact">
            <label>
                <span>Tipo alloggiato</span>
                <select name="<?= e($prefix) ?>[tipoalloggiato_code]" <?= $isRepeaterGuest ? 'data-guest-type' : 'data-leader-type' ?> required>
                    <?php foreach ($tipoAlloggiatoOptions as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $fieldValue($guestData, 'tipoalloggiato_code', $defaultTipoAlloggiato) === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Codice cittadinanza</span>
                <input type="text" name="<?= e($prefix) ?>[citizenship_code]" maxlength="20" value="<?= e($fieldValue($guestData, 'citizenship_code')) ?>" placeholder="Es. 100000100" required>
            </label>
            <label>
                <span>Codice stato residenza</span>
                <input type="text" name="<?= e($prefix) ?>[residence_state_code]" maxlength="20" value="<?= e($fieldValue($guestData, 'residence_state_code', anagrafica_default_italy_state_code())) ?>" placeholder="Es. 100000100" required>
            </label>
            <label>
                <span>Codice luogo residenza</span>
                <input type="text" name="<?= e($prefix) ?>[residence_place_code]" maxlength="30" value="<?= e($fieldValue($guestData, 'residence_place_code')) ?>" placeholder="Comune / NUTS / località" required>
            </label>
            <label>
                <span>Codice stato nascita</span>
                <input type="text" name="<?= e($prefix) ?>[birth_state_code]" maxlength="20" value="<?= e($fieldValue($guestData, 'birth_state_code', anagrafica_default_italy_state_code())) ?>" placeholder="Es. 100000100" required>
            </label>
            <label>
                <span>Codice comune nascita</span>
                <input type="text" name="<?= e($prefix) ?>[birth_city_code]" maxlength="20" value="<?= e($fieldValue($guestData, 'birth_city_code')) ?>" placeholder="Solo se nato in Italia">
            </label>
            <label>
                <span>Tipo turismo</span>
                <select name="<?= e($prefix) ?>[tourism_type]" required>
                    <?php foreach ($tourismTypes as $value): ?>
                        <option value="<?= e($value) ?>" <?= $fieldValue($guestData, 'tourism_type', 'Non specificato') === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Mezzo di trasporto</span>
                <select name="<?= e($prefix) ?>[transport_type]" required>
                    <?php foreach ($transportTypes as $value): ?>
                        <option value="<?= e($value) ?>" <?= $fieldValue($guestData, 'transport_type', 'Non specificato') === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Canale prenotazione ospite</span>
                <select name="<?= e($prefix) ?>[guest_booking_channel]">
                    <option value="">Usa quello della prenotazione</option>
                    <?php foreach ($channels as $value): ?>
                        <option value="<?= e($value) ?>" <?= $fieldValue($guestData, 'guest_booking_channel') === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Titolo di studio</span>
                <select name="<?= e($prefix) ?>[education_level]">
                    <option value="">Non compilato</option>
                    <?php foreach ($educationLevels as $value): ?>
                        <option value="<?= e($value) ?>" <?= $fieldValue($guestData, 'education_level') === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Professione</span>
                <input type="text" name="<?= e($prefix) ?>[profession]" maxlength="120" value="<?= e($fieldValue($guestData, 'profession')) ?>" placeholder="Es. Medico">
            </label>
            <label>
                <span>Codice esenzione imposta</span>
                <input type="text" name="<?= e($prefix) ?>[tax_exemption_code]" maxlength="30" value="<?= e($fieldValue($guestData, 'tax_exemption_code')) ?>" placeholder="Se previsto dal Comune">
            </label>
        </div>
    </div>
</div>
