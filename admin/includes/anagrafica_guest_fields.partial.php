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
$currentRecordType = (string) ($currentRecordType ?? ($formRecord['record_type'] ?? ($bookingModalRecord['record_type'] ?? 'single')));
$currentGuestTypeCode = trim($fieldValue($guestData, 'tipoalloggiato_code', $fieldValue($guestData, 'tipo_alloggiato_code')));
if ($currentGuestTypeCode === '') {
    $currentGuestTypeCode = anagrafica_tipo_alloggiato_code_for_record_type($currentRecordType, $isLeaderGuest);
}
$currentGuestTypeLabel = anagrafica_tipo_alloggiato_options()[$currentGuestTypeCode] ?? '';
$currentGuestTypeDisplay = $currentGuestTypeLabel !== '' ? $currentGuestTypeLabel : $currentGuestTypeCode;
$componentCardTitle = $currentRecordType === 'family' ? 'Familiare' : ($currentRecordType === 'group' ? 'Membro gruppo' : 'Componente');
$currentCitizenship = anagrafica_find_state_by_value($fieldValue($guestData, 'citizenship_label', $fieldValue($guestData, 'citizenship_code')));
$currentBirthState = anagrafica_find_state_by_value($fieldValue($guestData, 'birth_state_label', $fieldValue($guestData, 'birth_state_code')));
$currentResidenceState = anagrafica_find_state_by_value($fieldValue($guestData, 'residence_state_label', $fieldValue($guestData, 'residence_state_code')));
$currentBirthProvinceCode = anagrafica_find_province_code($fieldValue($guestData, 'birth_province')) ?? '';
$currentResidenceProvinceCode = anagrafica_find_province_code($fieldValue($guestData, 'residence_province')) ?? '';
$italyCode = anagrafica_default_italy_state_code();
$currentBirthPlaceValue = ($currentBirthState && ($currentBirthState['code'] ?? '') === $italyCode)
    ? $fieldValue($guestData, 'birth_city_code', $fieldValue($guestData, 'birth_place_label'))
    : '';
$currentResidencePlaceValue = ($currentResidenceState && ($currentResidenceState['code'] ?? '') === $italyCode)
    ? $fieldValue($guestData, 'residence_place_code', $fieldValue($guestData, 'residence_place_label'))
    : '';
$currentResidenceTextValue = ($currentResidenceState && ($currentResidenceState['code'] ?? '') === $italyCode)
    ? ''
    : $fieldValue($guestData, 'residence_place_label', $fieldValue($guestData, 'residence_place'));
$birthComuneOptions = $currentBirthProvinceCode !== '' ? ($comuniOptionsByProvince[$currentBirthProvinceCode] ?? []) : [];
$residenceComuneOptions = $currentResidenceProvinceCode !== '' ? ($comuniOptionsByProvince[$currentResidenceProvinceCode] ?? []) : [];
if (!isset($documentOcrReady)) {
    $documentOcrConfigLocal = require __DIR__ . '/document-ocr-config.php';
    $documentOcrReady = !empty($documentOcrConfigLocal['enabled'])
        && trim((string) ($documentOcrConfigLocal['endpoint'] ?? '')) !== '';
}
?>
<div class="anagrafica-guest-card<?= $isRepeaterGuest ? '' : ' anagrafica-guest-card--primary' ?>"<?= $isRepeaterGuest ? ' data-guest-card' : '' ?> data-guest-scope data-guest-index="<?= e((string) $guestIndex) ?>">
    <?php if ($isRepeaterGuest): ?>
        <div class="anagrafica-guest-card__top">
            <div>
                <strong><span data-guest-role-label><?= e($componentCardTitle) ?></span> <span data-guest-number><?= e((string) $guestNumber) ?></span></strong>
                <p class="muted">Compila i dati essenziali e il documento del componente collegato.</p>
            </div>
            <button class="btn btn-light btn-sm" type="button" data-remove-guest>Rimuovi</button>
        </div>
    <?php endif; ?>

    <div class="anagrafica-guest-groups">
        <section class="anagrafica-subsection" data-step-section="identity">
            <div class="anagrafica-subsection__header">
                <div>
                    <h4>Dati persona</h4>
                    <p class="muted">Nome, cognome, sesso, nascita e cittadinanza.</p>
                </div>
            </div>
            <div class="anagrafica-grid">
                <label class="anagrafica-field<?= e($errorClassFor('first_name')) ?>">
                    <span>Nome</span>
                    <input type="text" name="<?= e($prefix) ?>[first_name]" data-name="first_name" maxlength="100" value="<?= e($fieldValue($guestData, 'first_name')) ?>" required data-next-manual="1">
                    <?php if ($errorTextFor('first_name') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('first_name')) ?></small><?php endif; ?>
                </label>

                <label class="anagrafica-field<?= e($errorClassFor('last_name')) ?>">
                    <span>Cognome</span>
                    <input type="text" name="<?= e($prefix) ?>[last_name]" data-name="last_name" maxlength="100" value="<?= e($fieldValue($guestData, 'last_name')) ?>" required data-next-manual="1">
                    <?php if ($errorTextFor('last_name') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('last_name')) ?></small><?php endif; ?>
                </label>

                <label class="anagrafica-field<?= e($errorClassFor('gender')) ?>">
                    <span>Sesso</span>
                    <select name="<?= e($prefix) ?>[gender]" data-name="gender" required data-auto-advance="1">
                        <option value="">Seleziona</option>
                        <option value="M" <?= $fieldValue($guestData, 'gender', 'M') === 'M' ? 'selected' : '' ?>>Maschio</option>
                        <option value="F" <?= $fieldValue($guestData, 'gender') === 'F' ? 'selected' : '' ?>>Femmina</option>
                    </select>
                    <?php if ($errorTextFor('gender') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('gender')) ?></small><?php endif; ?>
                </label>

                <label class="anagrafica-field<?= e($errorClassFor('birth_date')) ?>">
                    <span>Data di nascita</span>
                    <input type="text" name="<?= e($prefix) ?>[birth_date]" data-name="birth_date" class="js-date" data-date-role="birth" value="<?= e($fieldDate($guestData, 'birth_date')) ?>" placeholder="gg/mm/aaaa" autocomplete="off" required data-auto-advance="1">
                    <?php if ($errorTextFor('birth_date') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('birth_date')) ?></small><?php endif; ?>
                </label>

                <label class="anagrafica-field<?= e($errorClassFor('citizenship_label')) ?>">
                    <span>Cittadinanza</span>
                    <select name="<?= e($prefix) ?>[citizenship_label]" data-name="citizenship_label" required data-state-role="citizenship" data-auto-advance="1">
                        <option value="">Seleziona uno stato</option>
                        <?php foreach ($stateOptions as $stateCode => $stateLabel): ?>
                            <option value="<?= e($stateCode) ?>" <?= (($currentCitizenship['code'] ?? '') === $stateCode) ? 'selected' : '' ?>><?= e($stateLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($errorTextFor('citizenship_label') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('citizenship_label')) ?></small><?php endif; ?>
                </label>

                <label class="anagrafica-field<?= e($errorClassFor('birth_state_label')) ?>">
                    <span>Stato di nascita</span>
                    <select name="<?= e($prefix) ?>[birth_state_label]" data-name="birth_state_label" data-state-role="birth" required data-auto-advance="1">
                        <option value="">Seleziona uno stato</option>
                        <?php foreach ($stateOptions as $stateCode => $stateLabel): ?>
                            <option value="<?= e($stateCode) ?>" <?= (($currentBirthState['code'] ?? '') === $stateCode) ? 'selected' : '' ?>><?= e($stateLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="anagrafica-field-hint">Se scegli Italia, si attivano provincia e comune.</small>
                    <?php if ($errorTextFor('birth_state_label') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('birth_state_label')) ?></small><?php endif; ?>
                </label>
            </div>
        </section>

        <section class="anagrafica-subsection" data-step-section="birth-residence">
            <div class="anagrafica-subsection__header">
                <div>
                    <h4>Nascita e residenza</h4>
                    <p class="muted">I campi italiani si attivano in base allo stato selezionato.</p>
                </div>
            </div>
            <div class="anagrafica-grid">
                <label class="anagrafica-field<?= e($errorClassFor('birth_province')) ?>" data-italy-only="birth">
                    <span>Provincia nascita (se Italia)</span>
                    <select name="<?= e($prefix) ?>[birth_province]" data-name="birth_province" data-province-role="birth" data-auto-advance="1">
                        <option value="">Seleziona provincia</option>
                        <?php foreach ($province as $provinceCode => $provinceName): ?>
                            <option value="<?= e($provinceCode) ?>" <?= $currentBirthProvinceCode === $provinceCode ? 'selected' : '' ?>><?= e($provinceName) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($errorTextFor('birth_province') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('birth_province')) ?></small><?php endif; ?>
                </label>

                <label class="anagrafica-field<?= e($errorClassFor('birth_place_label')) ?>" data-italy-only="birth">
                    <span>Comune nascita</span>
                    <select name="<?= e($prefix) ?>[birth_place_label]" data-name="birth_place_label" data-place-role="birth" data-auto-advance="1">
                        <option value=""><?= e($currentBirthProvinceCode !== '' ? 'Seleziona comune di nascita' : 'Seleziona prima la provincia') ?></option>
                        <?php foreach ($birthComuneOptions as $option): ?>
                            <option value="<?= e((string) ($option['code'] ?? '')) ?>" <?= $currentBirthPlaceValue === (string) ($option['code'] ?? '') ? 'selected' : '' ?>><?= e((string) ($option['label'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($errorTextFor('birth_place_label') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('birth_place_label')) ?></small><?php endif; ?>
                </label>

                <label class="anagrafica-field<?= e($errorClassFor('residence_state_label')) ?>">
                    <span>Stato di residenza</span>
                    <select name="<?= e($prefix) ?>[residence_state_label]" data-name="residence_state_label" data-state-role="residence" required data-auto-advance="1">
                        <option value="">Seleziona uno stato</option>
                        <?php foreach ($stateOptions as $stateCode => $stateLabel): ?>
                            <option value="<?= e($stateCode) ?>" <?= (($currentResidenceState['code'] ?? '') === $stateCode) ? 'selected' : '' ?>><?= e($stateLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="anagrafica-field-hint">Per l'estero puoi indicare località libera o codice NUTS.</small>
                    <?php if ($errorTextFor('residence_state_label') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('residence_state_label')) ?></small><?php endif; ?>
                </label>

                <label class="anagrafica-field<?= e($errorClassFor('residence_province')) ?>" data-italy-only="residence">
                    <span>Provincia residenza (se Italia)</span>
                    <select name="<?= e($prefix) ?>[residence_province]" data-name="residence_province" data-province-role="residence" data-auto-advance="1">
                        <option value="">Seleziona provincia</option>
                        <?php foreach ($province as $provinceCode => $provinceName): ?>
                            <option value="<?= e($provinceCode) ?>" <?= $currentResidenceProvinceCode === $provinceCode ? 'selected' : '' ?>><?= e($provinceName) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($errorTextFor('residence_province') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('residence_province')) ?></small><?php endif; ?>
                </label>

                <label class="anagrafica-field<?= e($errorClassFor('residence_place_label')) ?>">
                    <span data-residence-place-label>Comune / località residenza</span>
                    <select name="<?= e($prefix) ?>[residence_place_label]" data-place-role="residence-select" data-name="residence_place_label" data-auto-advance="1" <?= (($currentResidenceState['code'] ?? '') === $italyCode) ? 'required' : 'hidden disabled' ?>>
                        <option value=""><?= e($currentResidenceProvinceCode !== '' ? 'Seleziona comune di residenza' : 'Seleziona prima la provincia') ?></option>
                        <?php foreach ($residenceComuneOptions as $option): ?>
                            <option value="<?= e((string) ($option['code'] ?? '')) ?>" <?= $currentResidencePlaceValue === (string) ($option['code'] ?? '') ? 'selected' : '' ?>><?= e((string) ($option['label'] ?? '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="<?= e($prefix) ?>[residence_place_label]" data-place-role="residence-text" data-name="residence_place_label" value="<?= e($currentResidenceTextValue) ?>" placeholder="Località o codice NUTS" <?= (($currentResidenceState['code'] ?? '') === $italyCode) ? 'hidden disabled' : 'required' ?> data-next-manual="1">
                    <?php if ($errorTextFor('residence_place_label') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('residence_place_label')) ?></small><?php endif; ?>
                </label>
            </div>
        </section>

        <section class="anagrafica-subsection" data-step-section="document">
            <div class="anagrafica-subsection__header">
                <div>
                    <h4>Documento e dettaglio soggiorno</h4>
                    <p class="muted">Documento sempre raccolto anche per familiari e membri gruppo.</p>
                </div>
                <div class="anagrafica-subsection__actions">
                    <button class="btn btn-light btn-sm anagrafica-ocr-trigger<?= !empty($documentOcrReady) ? '' : ' is-disabled' ?>" type="button" data-document-ocr-trigger<?= !empty($documentOcrReady) ? '' : ' disabled' ?>>Scansiona documento OCR</button>
                </div>
            </div>
            <div class="anagrafica-ocr-feedback" data-document-ocr-status hidden></div>
            <div class="anagrafica-grid">
                <label class="anagrafica-field anagrafica-field--readonly">
                    <span>Tipologia alloggiato</span>
                    <input type="text" value="<?= e($currentGuestTypeDisplay) ?>" readonly data-alloggiati-type-display data-guest-index="<?= e((string) $guestIndex) ?>">
                </label>

                <label class="anagrafica-field<?= e($errorClassFor('document_type_label')) ?>">
                    <span>Tipo documento</span>
                    <select name="<?= e($prefix) ?>[document_type_label]" data-name="document_type_label" required data-auto-advance="1">
                        <option value="">Seleziona</option>
                        <?php foreach ($documentTypes as $value => $label): ?>
                            <option value="<?= e($label) ?>" <?= $currentDocumentLabel === $label ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($errorTextFor('document_type_label') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('document_type_label')) ?></small><?php endif; ?>
                </label>

                <label class="anagrafica-field<?= e($errorClassFor('document_number')) ?>">
                    <span>Numero documento</span>
                    <input type="text" name="<?= e($prefix) ?>[document_number]" data-name="document_number" maxlength="50" value="<?= e($fieldValue($guestData, 'document_number')) ?>" required data-next-manual="1">
                    <?php if ($errorTextFor('document_number') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('document_number')) ?></small><?php endif; ?>
                </label>

                <label class="anagrafica-field<?= e($errorClassFor('document_issue_place')) ?>">
                    <span>Luogo rilascio documento</span>
                    <input list="document-issue-options" name="<?= e($prefix) ?>[document_issue_place]" data-name="document_issue_place" value="<?= e($fieldValue($guestData, 'document_issue_place')) ?>" placeholder="Comune italiano o stato estero" required data-next-manual="1">
                    <?php if ($errorTextFor('document_issue_place') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('document_issue_place')) ?></small><?php endif; ?>
                </label>

                <label class="anagrafica-field<?= e($errorClassFor('tourism_type')) ?>">
                    <span>Tipo turismo</span>
                    <select name="<?= e($prefix) ?>[tourism_type]" data-name="tourism_type" required data-auto-advance="1">
                        <option value="">Seleziona</option>
                        <?php foreach ($tourismTypes as $value): ?>
                            <option value="<?= e($value) ?>" <?= $fieldValue($guestData, 'tourism_type', 'Non specificato') === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($errorTextFor('tourism_type') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('tourism_type')) ?></small><?php endif; ?>
                </label>

                <label class="anagrafica-field<?= e($errorClassFor('transport_type')) ?>">
                    <span>Mezzo di trasporto</span>
                    <select name="<?= e($prefix) ?>[transport_type]" data-name="transport_type" required data-auto-advance="1">
                        <option value="">Seleziona</option>
                        <?php foreach ($transportTypes as $value): ?>
                            <option value="<?= e($value) ?>" <?= $fieldValue($guestData, 'transport_type', 'Non Specificato') === $value ? 'selected' : '' ?>><?= e($value) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($errorTextFor('transport_type') !== ''): ?><small class="anagrafica-field-error"><?= e($errorTextFor('transport_type')) ?></small><?php endif; ?>
                </label>
            </div>
        </section>
    </div>
</div>
