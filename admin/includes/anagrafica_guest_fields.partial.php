<?php $prefix = "guests[$guestIndex]"; ?>
<div class="anagrafica-guest-card anagrafica-guest-card--primary" data-guest-card data-guest-index="<?= (int) $guestIndex ?>">
    <div class="doc-scan-panel" data-doc-scan>
        <div class="doc-scan-panel__header">
            <div>
                <h4>Acquisizione documento</h4>
                <p class="muted">Carica una foto nitida del documento. Se il retro contiene la zona MRZ, caricalo sempre: è la parte più affidabile per l'estrazione automatica.</p>
            </div>
            <button type="button" class="btn btn-light btn-sm" data-doc-extract>Leggi documento</button>
        </div>
        <div class="doc-scan-panel__grid">
            <label class="doc-upload-field">
                <span>Fronte documento</span>
                <input type="file" accept="image/*" capture="environment" data-doc-file="front">
            </label>
            <label class="doc-upload-field">
                <span>Retro documento</span>
                <input type="file" accept="image/*" capture="environment" data-doc-file="back">
            </label>
        </div>
        <div class="doc-scan-panel__meta">
            <span class="doc-scan-status" data-doc-status>Nessun file analizzato.</span>
        </div>
        <div class="doc-file-list" data-doc-preview></div>
        <div class="doc-result-box" data-doc-result hidden></div>
    </div>

    <div class="anagrafica-grid">
        <label>
            <span>Nome</span>
            <input type="text" name="<?= e($prefix) ?>[first_name]" data-field="first_name" maxlength="100" required>
        </label>
        <label>
            <span>Cognome</span>
            <input type="text" name="<?= e($prefix) ?>[last_name]" data-field="last_name" maxlength="100" required>
        </label>
        <label>
            <span>Sesso</span>
            <select name="<?= e($prefix) ?>[gender]" data-field="gender" required>
                <option value="M">Maschio</option>
                <option value="F">Femmina</option>
            </select>
        </label>
        <label>
            <span>Data di nascita</span>
            <input type="text" name="<?= e($prefix) ?>[birth_date]" data-field="birth_date" class="js-date" data-date-role="birth" placeholder="Seleziona la data" autocomplete="off" required>
        </label>
        <label>
            <span>Cittadinanza</span>
            <input list="citizenship-options" name="<?= e($prefix) ?>[citizenship_label]" data-field="citizenship_label" placeholder="Seleziona o digita" required>
        </label>
        <label>
            <span>Provincia di residenza</span>
            <input list="province-options" name="<?= e($prefix) ?>[residence_province]" data-field="residence_province" placeholder="Seleziona o digita" required>
        </label>
        <label>
            <span>Luogo di residenza</span>
            <input type="text" name="<?= e($prefix) ?>[residence_place]" data-field="residence_place" maxlength="120" required>
        </label>
        <label>
            <span>Tipologia documento</span>
            <select name="<?= e($prefix) ?>[document_type]" data-field="document_type" required>
                <?php foreach ($documentTypes as $value => $label): ?>
                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>N. documento</span>
            <input type="text" name="<?= e($prefix) ?>[document_number]" data-field="document_number" maxlength="50" required>
        </label>
        <label>
            <span>Data documento</span>
            <input type="text" name="<?= e($prefix) ?>[document_issue_date]" data-field="document_issue_date" class="js-date" data-date-role="document-issue" placeholder="Seleziona la data" autocomplete="off">
        </label>
        <label>
            <span>Scadenza documento</span>
            <input type="text" name="<?= e($prefix) ?>[document_expiry_date]" data-field="document_expiry_date" class="js-date" data-date-role="document-expiry" placeholder="Seleziona la data" autocomplete="off" required>
        </label>
        <label>
            <span>Luogo di emissione</span>
            <input list="city-options" name="<?= e($prefix) ?>[document_issue_place]" data-field="document_issue_place" placeholder="Seleziona o digita" required>
        </label>
        <label>
            <span>Email</span>
            <input type="email" name="<?= e($prefix) ?>[email]" data-field="email" maxlength="190">
        </label>
        <label>
            <span>Telefono</span>
            <input type="text" name="<?= e($prefix) ?>[phone]" data-field="phone" maxlength="40">
        </label>
        <label>
            <span>Tipo turismo</span>
            <select name="<?= e($prefix) ?>[tourism_type]" data-field="tourism_type" required>
                <?php foreach ($tourismTypes as $value): ?>
                    <option value="<?= e($value) ?>"><?= e($value) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Mezzo di trasporto</span>
            <select name="<?= e($prefix) ?>[transport_type]" data-field="transport_type" required>
                <?php foreach ($transportTypes as $value): ?>
                    <option value="<?= e($value) ?>"><?= e($value) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
</div>
