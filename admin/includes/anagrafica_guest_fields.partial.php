<?php
$guestData = $guestData ?? [];
$prefix = "guests[$guestIndex]";
$isRepeaterGuest = (bool) ($isRepeaterGuest ?? false);
$guestNumber = (int) ($guestNumber ?? ($guestIndex + 1));
$isLeaderGuest = $guestIndex === 0;

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

$errorClassFor = static function (string $key) use ($fieldClass, $guestIndex): string {
    return $fieldClass('guests.' . $guestIndex . '.' . $key);
};

$errorTextFor = static function (string $key) use ($errorFor, $guestIndex): string {
    return $errorFor('guests.' . $guestIndex . '.' . $key);
};

$birthListId = 'birth-place-options-' . $guestIndex;
$residenceListId = 'residence-place-options-' . $guestIndex;
$currentDocumentValue = $fieldValue($guestData, 'document_type_label', $fieldValue($guestData, 'document_type'));
$currentDocument = anagrafica_find_document_by_value($currentDocumentValue);
$currentDocumentLabel = $currentDocument['description'] ?? $currentDocumentValue;
?>
<div class="anagrafica-guest-card<?= $isRepeaterGuest ? '' : ' anagrafica-guest-card--primary' ?>"<?= $isRepeaterGuest ? ' data-guest-card' : '' ?> data-guest-scope data-guest-index="<?= e((string) $guestIndex) ?>">
    <?php if ($isRepeaterGuest): ?>
        <div class="anagrafica-guest-card__top">
            <div>
                <strong>Componente <span data-guest-number><?= e((string) $guestNumber) ?></span></strong>
                <p class="muted">Compila solo i dati realmente necessari per ROSS1000 e Alloggiati Web.</p>
            </div>
            <button class="btn btn-light btn-sm" type="button" data-remove-guest>Rimuovi</button>
        </div>
    <?php endif; ?>

    <div class="anagrafica-grid">
        <label class="anagrafica-field<?= e($errorClassFor('first_name')) ?>">
            <span>Nome</span>
            <input type="text" name="<?= e($prefix) ?>[first_name]" maxlength="100" value="<?= e($fieldValue($guestData, 'first_name')) ?>" required>
            <?php if ($errorTextFor('first_name') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('first_name')) ?></small><?php endif; ?>
        </label>

        <label class="anagrafica-field<?= e($errorClassFor('last_name')) ?>">
            <span>Cognome</span>
            <input type="text" name="<?= e($prefix) ?>[last_name]" maxlength="100" value="<?= e($fieldValue($guestData, 'last_name')) ?>" required>
            <?php if ($errorTextFor('last_name') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('last_name')) ?></small><?php endif; ?>
        </label>

        <label class="anagrafica-field<?= e($errorClassFor('gender')) ?>">
            <span>Sesso</span>
            <select name="<?= e($prefix) ?>[gender]" required>
                <option value="">Seleziona</option>
                <option value="M" <?= $fieldValue($guestData, 'gender', 'M') === 'M' ? 'selected' : '' ?>>Maschio</option>
                <option value="F" <?= $fieldValue($guestData, 'gender') === 'F' ? 'selected' : '' ?>>Femmina</option>
            </select>
            <?php if ($errorTextFor('gender') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('gender')) ?></small><?php endif; ?>
        </label>

        <label class="anagrafica-field<?= e($errorClassFor('birth_date')) ?>">
            <span>Data di nascita</span>
            <input type="text" name="<?= e($prefix) ?>[birth_date]" class="js-date" data-date-role="birth" value="<?= e($fieldDate($guestData, 'birth_date')) ?>" placeholder="Seleziona la data" autocomplete="off" required>
            <?php if ($errorTextFor('birth_date') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('birth_date')) ?></small><?php endif; ?>
        </label>

        <label class="anagrafica-field<?= e($errorClassFor('citizenship_label')) ?>">
            <span>Cittadinanza</span>
            <input list="state-options" name="<?= e($prefix) ?>[citizenship_label]" value="<?= e($fieldValue($guestData, 'citizenship_label')) ?>" placeholder="Seleziona uno stato" required>
            <?php if ($errorTextFor('citizenship_label') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('citizenship_label')) ?></small><?php endif; ?>
        </label>

        <label class="anagrafica-field<?= e($errorClassFor('birth_state_label')) ?>">
            <span>Stato di nascita</span>
            <input list="state-options" name="<?= e($prefix) ?>[birth_state_label]" data-state-role="birth" value="<?= e($fieldValue($guestData, 'birth_state_label')) ?>" placeholder="Seleziona uno stato" required>
            <?php if ($errorTextFor('birth_state_label') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('birth_state_label')) ?></small><?php endif; ?>
        </label>

        <label class="anagrafica-field<?= e($errorClassFor('birth_province')) ?>">
            <span>Provincia nascita (se Italia)</span>
            <input list="province-options" name="<?= e($prefix) ?>[birth_province]" data-province-role="birth" value="<?= e($fieldValue($guestData, 'birth_province')) ?>" placeholder="Seleziona provincia">
            <?php if ($errorTextFor('birth_province') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('birth_province')) ?></small><?php endif; ?>
        </label>

        <label class="anagrafica-field<?= e($errorClassFor('birth_place_label')) ?>">
            <span>Comune nascita</span>
            <input list="<?= e($birthListId) ?>" name="<?= e($prefix) ?>[birth_place_label]" data-place-role="birth" value="<?= e($fieldValue($guestData, 'birth_place_label')) ?>" placeholder="Se scegli Italia, seleziona il comune">
            <datalist id="<?= e($birthListId) ?>"></datalist>
            <?php if ($errorTextFor('birth_place_label') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('birth_place_label')) ?></small><?php endif; ?>
        </label>

        <label class="anagrafica-field<?= e($errorClassFor('residence_state_label')) ?>">
            <span>Stato di residenza</span>
            <input list="state-options" name="<?= e($prefix) ?>[residence_state_label]" data-state-role="residence" value="<?= e($fieldValue($guestData, 'residence_state_label')) ?>" placeholder="Seleziona uno stato" required>
            <?php if ($errorTextFor('residence_state_label') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('residence_state_label')) ?></small><?php endif; ?>
        </label>

        <label class="anagrafica-field<?= e($errorClassFor('residence_province')) ?>">
            <span>Provincia residenza (se Italia)</span>
            <input list="province-options" name="<?= e($prefix) ?>[residence_province]" data-province-role="residence" value="<?= e($fieldValue($guestData, 'residence_province')) ?>" placeholder="Seleziona provincia">
            <?php if ($errorTextFor('residence_province') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('residence_province')) ?></small><?php endif; ?>
        </label>

        <label class="anagrafica-field<?= e($errorClassFor('residence_place_label')) ?>">
            <span><?= $isLeaderGuest ? 'Comune / località residenza' : 'Comune / località residenza' ?></span>
            <input list="<?= e($residenceListId) ?>" name="<?= e($prefix) ?>[residence_place_label]" data-place-role="residence" value="<?= e($fieldValue($guestData, 'residence_place_label', $fieldValue($guestData, 'residence_place'))) ?>" placeholder="Comune italiano, NUTS o località" required>
            <datalist id="<?= e($residenceListId) ?>"></datalist>
            <?php if ($errorTextFor('residence_place_label') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('residence_place_label')) ?></small><?php endif; ?>
        </label>

        <?php if ($isLeaderGuest): ?>
            <label class="anagrafica-field<?= e($errorClassFor('document_type_label')) ?>">
                <span>Tipo documento</span>
                <select name="<?= e($prefix) ?>[document_type_label]" required>
                    <option value="">Seleziona</option>
                    <?php foreach ($documentTypes as $value => $label): ?>
                        <option value="<?= e($label) ?>" <?= $currentDocumentLabel === $label ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($errorTextFor('document_type_label') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('document_type_label')) ?></small><?php endif; ?>
            </label>

            <label class="anagrafica-field<?= e($errorClassFor('document_number')) ?>">
                <span>Numero documento</span>
                <input type="text" name="<?= e($prefix) ?>[document_number]" maxlength="50" value="<?= e($fieldValue($guestData, 'document_number')) ?>" required>
                <?php if ($errorTextFor('document_number') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('document_number')) ?></small><?php endif; ?>
            </label>

            <label class="anagrafica-field<?= e($errorClassFor('document_issue_place')) ?>">
                <span>Luogo rilascio documento</span>
                <input list="place-options" name="<?= e($prefix) ?>[document_issue_place]" value="<?= e($fieldValue($guestData, 'document_issue_place')) ?>" placeholder="Comune italiano o stato estero" required>
                <?php if ($errorTextFor('document_issue_place') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('document_issue_place')) ?></small><?php endif; ?>
            </label>
        <?php endif; ?>

        <label class="anagrafica-field<?= e($errorClassFor('tourism_type')) ?>">
            <span>Tipo turismo</span>
            <select name="<?= e($prefix) ?>[tourism_type]" required>
                <option value="">Seleziona</option>
                <?php foreach ($tourismTypes as $value): ?>
                    <option value="<?= e($value) ?>" <?= $fieldValue($guestData, 'tourism_type', 'Non specificato') === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($errorTextFor('tourism_type') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('tourism_type')) ?></small><?php endif; ?>
        </label>

        <label class="anagrafica-field<?= e($errorClassFor('transport_type')) ?>">
            <span>Mezzo di trasporto</span>
            <select name="<?= e($prefix) ?>[transport_type]" required>
                <option value="">Seleziona</option>
                <?php foreach ($transportTypes as $value): ?>
                    <option value="<?= e($value) ?>" <?= $fieldValue($guestData, 'transport_type', 'Non Specificato') === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($errorTextFor('transport_type') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('transport_type')) ?></small><?php endif; ?>
        </label>
    </div>
</div>
