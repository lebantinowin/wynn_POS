<div>
    <section class="panel">
        <div class="section-heading"><div><h2>Shop &amp; receipt details</h2><p class="muted">Philippine pesos · Saved details for new sales slips.</p></div><span class="badge">PHP · ₱</span></div>
        @if($message)<p class="success" role="status">{{ $message }}</p>@endif
        @error('settings')<p class="error" role="alert">{{ $message }}</p>@enderror
        @error('offline')<p class="error" role="alert">{{ $message }}</p>@enderror
        <form wire:submit="save">
            <div class="form-grid">
                <label>Shop display name<input wire:model="settings.shopName" maxlength="120" required>@error('settings.shopName')<span class="error">{{ $message }}</span>@enderror</label>
                <label>Contact details<input wire:model="settings.contact" maxlength="100" placeholder="Shop phone or email">@error('settings.contact')<span class="error">{{ $message }}</span>@enderror</label>
                <label>Shop address<textarea wire:model="settings.address" maxlength="300" rows="2" placeholder="Address to show on new sales slips"></textarea>@error('settings.address')<span class="error">{{ $message }}</span>@enderror</label>
                <label>Receipt footer<textarea wire:model="settings.footer" maxlength="200" rows="2" placeholder="Thank you for shopping at Wynn’s Mart"></textarea>@error('settings.footer')<span class="error">{{ $message }}</span>@enderror</label>
            </div>
            <h3>BIR invoice preparation</h3>
            <p class="notice"><strong>Invoice issuance is pending.</strong> Tax calculation is unconfigured. Saving registration details prepares setup; it does not activate or certify BIR invoices. Current printouts remain sales slips.</p>
            <div class="form-grid">
                <label>Tax registration status<select wire:model="settings.taxStatus"><option value="Unconfirmed">Not confirmed — leave tax unconfigured</option><option value="Non-VAT">Non-VAT registered — calculation pending</option><option value="VAT">VAT registered — calculation pending</option></select>@error('settings.taxStatus')<span class="error">{{ $message }}</span>@enderror</label>
                <label>BIR registered business / proprietor name<input wire:model="settings.registeredName" maxlength="160" placeholder="As shown on your Certificate of Registration">@error('settings.registeredName')<span class="error">{{ $message }}</span>@enderror</label>
                <label>Taxpayer Identification Number (TIN)<input wire:model="settings.tin" maxlength="11" placeholder="Nine digits, with optional hyphens">@error('settings.tin')<span class="error">{{ $message }}</span>@enderror</label>
                <label>BIR branch code<input wire:model="settings.branchCode" maxlength="5" inputmode="numeric" placeholder="As shown on your registration">@error('settings.branchCode')<span class="error">{{ $message }}</span>@enderror</label>
                <label>Revenue District Office (RDO)<input wire:model="settings.rdo" maxlength="60">@error('settings.rdo')<span class="error">{{ $message }}</span>@enderror</label>
                <label>POS permit / registration reference, if issued<input wire:model="settings.permitReference" maxlength="120">@error('settings.permitReference')<span class="error">{{ $message }}</span>@enderror</label>
            </div>
            <p class="muted">Leave unknown registration details blank. Before invoice activation, confirm the tax treatment and required buyer fields, invoice numbering, POS registration/accreditation and applicable electronic invoicing/reporting requirements with your accountant or RDO. Enter registration details here on your own device.</p>
            <p class="muted"><a href="https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2077-2024.pdf" target="_blank" rel="noopener noreferrer">BIR invoice guidance</a> · <a href="https://www.bir.gov.ph/registration-requirements-details" target="_blank" rel="noopener noreferrer">BIR registration requirements</a></p>
            <div class="form-actions"><button class="primary" type="submit" wire:loading.attr="disabled">Save shop details</button></div>
        </form>
    </section>
    <section class="panel receipt">
        <h2>Saved sales-slip header</h2><p class="muted">Uses saved settings · No sale is recorded by this preview.</p>
        <h3>{{ $profile['shopName'] }}</h3>
        @if($profile['address'])<p style="white-space:pre-line">{{ $profile['address'] }}</p>@endif
        @if($profile['contact'])<p>{{ $profile['contact'] }}</p>@endif
        <p class="receipt-note">PREVIEW · NOT A BIR INVOICE</p><p class="spread"><strong>Example total</strong><strong>{{ \App\Support\ShopPreferences::amount(12345, $profile) }}</strong></p>
        @if($profile['footer'])<p style="white-space:pre-line">{{ $profile['footer'] }}</p>@endif
        <p class="muted">{{ \App\Support\ShopPreferences::notice($profile) }}</p>
    </section>
</div>
