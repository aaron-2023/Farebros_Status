(() => {
  const form = document.querySelector('[data-status-message-form]');
  if (!form) return;
  const autoPreview = form.querySelector('[data-status-auto-preview]');
  const manualField = form.querySelector('[data-status-manual-field]');
  const manualInput = manualField?.querySelector('textarea');
  const radios = [...form.querySelectorAll('input[name="status_message_mode"]')];
  const update = () => {
    const manual = radios.find(r => r.checked)?.value === 'manual';
    if (manualField) manualField.hidden = !manual;
    if (autoPreview) autoPreview.hidden = manual;
    if (manualInput) manualInput.required = manual;
  };
  radios.forEach(r => r.addEventListener('change', update));
  update();
})();
