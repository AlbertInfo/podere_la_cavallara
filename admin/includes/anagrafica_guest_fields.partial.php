<?php
$guestData = $guestData ?? [];
$prefix = "guests[$guestIndex]";
$isRepeaterGuest = (bool) ($isRepeaterGuest ?? false);
$guestNumber = (int) ($guestNumber ?? ($guestIndex + 1));

$fieldValue = static function (array $data, string $key, string $default = ''): string {
    $value = $data[$key] ?? $default;
    if ($value === null) {
        return $default;
    }
    return is_scalar($value) ? (string) $value : $default;
};

$fieldDate = static function (array $data, string $key): string {
    $value = $data[$key] ?? '';
    if (!is_scalar($value) || trim((string) $value) === '') {
        return '';
    }
    $ts = strtotime((string) $value);
    return $ts ? date('d/m/Y', $ts) : (string) $value;
};


$currentDocumentValue = $fieldValue($guestData, 'document_type_label', $fieldValue($guestData, 'document_type'));
$currentDocument = anagrafica_find_document_by_value($currentDocumentValue);
$currentDocumentLabel = $currentDocument['description'] ?? $currentDocumentValue;
?>
<div class="anagrafica-guest-card<?= $isRepeaterGuest ? '' : ' anagrafica-guest-card--primary' ?>"<?= $isRepeaterGuest ? ' data-guest-card' : '' ?>>
    <?php if ($isRepeaterGuest): ?>
        <div class="anagrafica-guest-card__top">
            <div>
                <strong>Componente <span data-guest-number><?= e((string) $guestNumber) ?></span></strong>
                <p class="muted">Questo ospite verrà collegato automaticamente al capogruppo.</p>
            </div>
            <button class="btn btn-light btn-sm" type="button" data-remove-guest>Rimuovi</button>
        </div>
    <?php endif; ?>

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
            <input list="state-options" name="<?= e($prefix) ?>[citizenship_label]" value="<?= e($fieldValue($guestData, 'citizenship_label')) ?>" placeholder="Seleziona o digita" required>
        </label>
        <label>
            <span>Stato di nascita</span>
            <input list="state-options" name="<?= e($prefix) ?>[birth_state_label]" value="<?= e($fieldValue($guestData, 'birth_state_label', anagrafica_default_state_label())) ?>" placeholder="Seleziona o digita">
        </label>
        <label>
            <span>Provincia nascita (se Italia)</span>
            <input list="province-options" name="<?= e($prefix) ?>[birth_province]" value="<?= e($fieldValue($guestData, 'birth_province')) ?>" placeholder="Seleziona o digita">
        </label>
        <label>
            <span>Luogo/comune nascita</span>
            <input list="city-options" name="<?= e($prefix) ?>[birth_place_label]" value="<?= e($fieldValue($guestData, 'birth_place_label')) ?>" placeholder="Se Italia scegli il comune">
        </label>

        <label>
            <span>Stato di residenza</span>
            <input list="state-options" name="<?= e($prefix) ?>[residence_state_label]" value="<?= e($fieldValue($guestData, 'residence_state_label', anagrafica_default_state_label())) ?>" placeholder="Seleziona o digita" required>
        </label>
        <label>
            <span>Provincia residenza (se Italia)</span>
            <input list="province-options" name="<?= e($prefix) ?>[residence_province]" value="<?= e($fieldValue($guestData, 'residence_province')) ?>" placeholder="Seleziona o digita">
        </label>
        <label>
            <span>Luogo residenza</span>
            <input list="place-options" name="<?= e($prefix) ?>[residence_place_label]" value="<?= e($fieldValue($guestData, 'residence_place_label', $fieldValue($guestData, 'residence_place'))) ?>" placeholder="Comune italiano, NUTS o località" required>
        </label>
        <label>
            <span>Tipologia documento</span>
            <select name="<?= e($prefix) ?>[document_type_label]" required>
                <option value="">Seleziona</option>
                <?php foreach ($documentTypes as $value => $label): ?>
                    <option value="<?= e($label) ?>" <?= $currentDocumentLabel === $label ? 'selected' : '' ?>><?= e($label) ?></option>
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
            <span>Luogo emissione documento</span>
            <input list="city-options" name="<?= e($prefix) ?>[document_issue_place]" value="<?= e($fieldValue($guestData, 'document_issue_place')) ?>" placeholder="Seleziona o digita">
        </label>

        <label>
            <span>Email</span>
            <input type="email" name="<?= e($prefix) ?>[email]" maxlength="190" value="<?= e($fieldValue($guestData, 'email')) ?>">
        </label>
        <label>
            <span>Telefono</span>
            <input type="text" name="<?= e($prefix) ?>[phone]" maxlength="40" value="<?= e($fieldValue($guestData, 'phone')) ?>">
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
                    <option value="<?= e($value) ?>" <?= $fieldValue($guestData, 'transport_type', 'Non Specificato') === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            <span>Titolo di studio</span>
            <select name="<?= e($prefix) ?>[education_level]">
                <option value="">Seleziona</option>
                <?php foreach ($educationLevels as $value): ?>
                    <option value="<?= e($value) ?>" <?= $fieldValue($guestData, 'education_level') === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Professione</span>
            <input type="text" name="<?= e($prefix) ?>[profession]" maxlength="120" value="<?= e($fieldValue($guestData, 'profession')) ?>">
        </label>
        <label>
            <span>Codice esenzione imposta</span>
            <input type="text" name="<?= e($prefix) ?>[tax_exemption_code]" maxlength="40" value="<?= e($fieldValue($guestData, 'tax_exemption_code')) ?>">
        </label>
    </div>
</div>
