// assets/js/driver-ride-creation.js
// Ride-mode selection feedback + operation gating for the "Create ride" flow.
//
// The operation list (Join Ride / Pickup Ride / Keke) used to be filtered once
// at DOMContentLoaded by sniffing for the substring "keke" inside
// window.vehicleSummary — a "make model · colour · plate" display string that
// never contains the vehicle type. That test could never be true, so the Keke
// option was shown to every driver and hidden from none, and it ran before
// driver.js had even fetched the vehicle. Gating now keys off the canonical
// vehicles.vehicle_type published by driver.js's refreshVehicleInfo(), and
// re-runs whenever that data arrives or the operation sheet is opened.
(() => {
  const OP_SELECTOR = '#operationSelectionContainer';

  function applyOperationGating() {
    const container = qs(OP_SELECTOR);
    if (!container) return;

    // Undefined until the first get_vehicle_info response lands. Treat that as
    // "unknown, don't guess": leave the sheet as-is rather than flashing the
    // wrong option set, since applyOperationGating runs again on the
    // mohtoh:vehicle-loaded event.
    if (!window.vehicleType) return;

    const isKeke = window.isKekeVehicle === true;

    // A keke is a 3-wheeler: it runs the Keke lane, not the car-only lanes.
    // A car driver gets the car lanes and never the Keke lane.
    qsa('.op-keke', container).forEach((btn) => btn.classList.toggle('u-hidden', !isKeke));
    qsa('.op-shared, .op-pickup', container).forEach((btn) => btn.classList.toggle('u-hidden', isKeke));

    const visible = qsa('.btn:not(.u-hidden)', container).length;
    let note = qs('#operationGatingNote', container);
    if (!note) {
      note = document.createElement('p');
      note.id = 'operationGatingNote';
      note.className = 'u-text-xs u-text-soft';
      container.appendChild(note);
    }
    note.textContent = visible
      ? (isKeke
        ? 'Your approved vehicle is a Keke, so Keke trips are the operation available to you.'
        : 'Operations available for your approved vehicle.')
      : 'No ride operations are available for your approved vehicle yet.';
  }

  document.addEventListener('DOMContentLoaded', () => {
    const rideModeList = qs('#rideModeList');
    rideModeList?.addEventListener('change', () => {
      // Visual selection first: this used to sit BELOW a bare
      // checkCommunityPricing() call, which is scoped inside driver.js's IIFE
      // and therefore threw a ReferenceError here — so picking "Private Ride"
      // never highlighted. driver.js now publishes it on window.
      qsa('#rideModeList .choice-option').forEach((el) => {
        el.classList.toggle('is-selected', el.querySelector('input').checked);
      });
      window.checkCommunityPricing?.();
    });

    applyOperationGating();
  });

  // driver.js fires this once the vehicles row has actually been read from the
  // backend — this is the run that matters, the DOMContentLoaded one above is
  // just so the sheet isn't stale if the vehicle was already cached.
  document.addEventListener('mohtoh:vehicle-loaded', applyOperationGating);

  // Re-assert on open too: the driver can add or change a vehicle without a
  // page reload, and openCreateRideSheet() re-runs refreshVehicleInfo().
  qs('[data-quick-action="create-ride"]')?.addEventListener('click', () => {
    setTimeout(applyOperationGating, 0);
  });
})();
