(() => {
	'use strict';
	function initialize(root) {
		if (root.dataset.reviewInitialized) return;
		root.dataset.reviewInitialized = 'true';
		const clinic = root.querySelector('select[name="clinic-id"]');
		const doctor = root.querySelector('select[name="review-doctor"]');
		const status = root.querySelector('[data-review-doctor-status]');
		const retry = root.querySelector('[data-review-doctor-retry]');
		if (!clinic || !doctor || !status || !retry) return;
		// Keep live feedback beside its field rather than below the submit button.
		const doctorLabel = doctor.closest('label');
		if (doctorLabel) (doctorLabel.closest('p') || doctorLabel).after(status, retry);
		const form = root.closest('form');
		let request = 0;
		let controller;
		status.id = `${form.id || root.closest('.wpcf7').id}-doctor-status`;
		doctor.setAttribute('aria-describedby', status.id);
		function clear(label) {
			doctor.replaceChildren(new Option(label, ''));
			doctor.value = '';
			doctor.disabled = true;
			retry.hidden = true;
		}
		async function load() {
			const serial = ++request;
			if (controller) controller.abort();
			const clinicId = clinic.value;
			clear(clinicId ? 'Loading doctors…' : 'Select a clinic first');
			doctor.removeAttribute('aria-busy');
			if (!clinicId) {
				status.textContent = clinic.options.length > 1 ? 'Choose a clinic to see its doctors.' : 'No clinics are currently available. Please try again later.';
				return;
			}
			status.textContent = 'Loading doctors…';
			doctor.setAttribute('aria-busy', 'true');
			const activeController = new AbortController();
			controller = activeController;
			const timeout = setTimeout(() => activeController.abort(), 15000);
			try {
				const response = await fetch(`${root.dataset.doctorsUrl}${encodeURIComponent(clinicId)}/doctors`, {signal: activeController.signal, cache: 'no-store'});
				if (!response.ok) throw new Error('Lookup failed');
				const data = await response.json();
				if (serial !== request) return;
				if (String(data.clinic_id) !== clinicId || !Array.isArray(data.doctors) || data.doctors.some(item => !Number.isInteger(item.id) || item.id < 1 || typeof item.name !== 'string')) throw new Error('Invalid response');
				doctor.replaceChildren(new Option('Select a doctor or clinic overall', ''), new Option('Clinic overall / No specific doctor', '0'));
				data.doctors.forEach(item => doctor.add(new Option(item.name, String(item.id))));
				doctor.disabled = false;
				status.textContent = data.doctors.length ? 'Choose a doctor, or review the clinic overall.' : 'No doctors are listed for this clinic. You can review the clinic overall.';
			} catch (error) {
				if (serial !== request) return;
				clear('Doctors could not be loaded');
				status.textContent = 'Unable to load doctors. Retry or choose another clinic.';
				retry.hidden = false;
			} finally {
				clearTimeout(timeout);
				if (serial === request) doctor.removeAttribute('aria-busy');
			}
		}
		clinic.addEventListener('change', load);
		retry.addEventListener('click', load);
		form.addEventListener('reset', () => setTimeout(load, 0));
		window.addEventListener('pageshow', load);
		load();
	}
	function boot() { document.querySelectorAll('[data-global360-review-form]').forEach(initialize); }
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();
})();
