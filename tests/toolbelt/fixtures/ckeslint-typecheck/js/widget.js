// Every line marked `expect:` must be reported by the type check, and no other
// line may be.
(function (CRM, ts) {
  /** @returns {{ id: number }} */
  function widget() {
    return { id: 1, label: 'x' }; // expect: 'label' does not exist in type '{ id: number; }'
  }
  const count = widget().id;
  count.toUpperCase(); // expect: Property 'toUpperCase' does not exist on type 'number'
  CRM.alert(ts('Saved'), CRM.vars.widget.title);
  document.body.classList.add('ready');
})(CRM, ts);
