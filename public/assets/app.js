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
    const total = values.slice(2).every(v => v !== null) ? values[2] + values[3] + values[4] : null;
    const distance = difference ?? total;
    output.textContent = distance === null ? '–' : (distance / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' km';
    document.getElementById('distance-message').textContent = difference !== null && total !== null ?
        (difference === total && difference >= 0 ? '✓ Kilometeraufteilung stimmt' : 'Bitte Kilometeraufteilung prüfen') : total !== null ? 'Entfernung direkt erfasst · Kilometerstände optional' : 'Entfernungen ergänzen';
}
kilometerFields.forEach(id => document.getElementById(id)?.addEventListener('input', updateDistance));
updateDistance();

const returnCheckbox = document.getElementById('include_return');
const returnFields = document.getElementById('return-fields');
const journeyForm = returnCheckbox?.form;
if (journeyForm && returnFields) {
    const restored = returnFields.dataset.restored === 'true';
    const editedFields = new Set();
    const field = name => journeyForm.elements.namedItem(name);
    returnFields.querySelectorAll('input').forEach(input => {
        input.addEventListener('input', () => editedFields.add(input.name));
        if (restored) editedFields.add(input.name);
    });
    const copy = (target, source) => {
        if (field(target) && field(source) && !editedFields.has(target)) field(target).value = field(source).value;
    };
    const outboundRoute = field('route');
    let automaticRoute = !outboundRoute?.value || outboundRoute.value === `${field('origin')?.value} → ${field('destination')?.value}`;
    outboundRoute?.addEventListener('input', () => { automaticRoute = false; });
    const returnDistance = field('return_distance');
    if (returnDistance?.value && returnDistance.value !== field('distance')?.value) editedFields.add('return_distance');
    function updateJourney() {
        returnFields.disabled = !returnCheckbox.checked;
        returnFields.hidden = !returnCheckbox.checked;
        copy('return_date', 'date');
        returnFields.querySelectorAll('[data-return-copy]').forEach(input => copy(input.name, input.dataset.returnCopy));
        if (!editedFields.has('return_route') && field('return_origin')?.value && field('return_destination')?.value) {
            field('return_route').value = `${field('return_origin').value} → ${field('return_destination').value}`;
        }
        if (automaticRoute && field('origin')?.value && field('destination')?.value) {
            outboundRoute.value = `${field('origin').value} → ${field('destination').value}`;
        }
        const totalOutput = document.getElementById('journey-distance');
        if (totalOutput) {
            const outbound = scaled(field('distance')?.value ?? '');
            const returning = returnCheckbox.checked ? scaled(field('return_distance')?.value ?? '') : 0;
            totalOutput.textContent = outbound === null || returning === null ? 'Entfernung ergänzen' :
                ((outbound + returning) / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' km';
        }
    }
    journeyForm.addEventListener('input', updateJourney);
    journeyForm.addEventListener('change', updateJourney);
    updateJourney();
}
