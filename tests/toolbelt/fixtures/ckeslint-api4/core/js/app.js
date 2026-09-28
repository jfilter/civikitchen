// Every line marked `expect:` must be reported by `ckeslint --core`, and no
// other line may be.
(function ($, _) {
  var link = document.createElement('a');
  link.downloadurl = 'text/plain:x.txt:x'; // expect: downloadurl
  CRM.api4('Contact', 'get', { select: ['display_nam'] }); // expect: field Contact.display_nam
  CRM.api4('Contact', 'get', { select: ['display_name'] });
  $('#widget').text(ts('Hello %1', { 1: _.escape(link.download) }));
})(CRM.$, CRM._);
