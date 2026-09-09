document.querySelector('.menu-button')?.addEventListener('click', function () {
    const open = document.querySelector('.sidebar').classList.toggle('open');
    this.setAttribute('aria-expanded', String(open));
});
document.querySelectorAll('form[data-confirm]').forEach(form => form.addEventListener('submit', event => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
}));
document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => window.print()));
const scaled = value => /^\d+(\.\d{1,2})?$/.test(value) ? Math.round(Number(value) * 100) : null;
const kilometerFields = ['odometer_start', 'odometer_end', 'business_km', 'commute_km', 'private_km'];
function updateDistance() {
    const values = kilometerFields.map(id => scaled(document.getElementById(id)?.value ?? ''));
    const output = document.getElementById('distance-output');
    if (!output) return;
    const difference = values[0] !== null && values[1] !== null ? values[1] - values[0] : null;
    output.textContent = difference === null ? '–' : (difference / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' km';
    document.getElementById('distance-message').textContent = values.every(v => v !== null) ?
        (difference === values[2] + values[3] + values[4] && difference >= 0 ? '✓ Kilometeraufteilung stimmt' : 'Bitte Kilometeraufteilung prüfen') : 'Kilometerstände und Aufteilung ergänzen';
}
kilometerFields.forEach(id => document.getElementById(id)?.addEventListener('input', updateDistance));
updateDistance();
