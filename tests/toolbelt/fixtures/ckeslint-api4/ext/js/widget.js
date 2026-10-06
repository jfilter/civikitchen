// Every line marked `expect:` must be reported by civikitchen/api4-contract,
// and no other line may be.
(function (angular, CRM) {
  angular.module('widget').factory('widgetData', function (crmApi4) {
    return {
      typos: function () {
        CRM.api4('Contatc', 'get', {}); // expect: entity Contatc
        CRM.api4('Acitvity', 'get', {}); // expect: entity Acitvity
        CRM.api4('contact', 'get', {}); // expect: entity contact
        CRM.api4('Contact', 'gett', {}); // expect: action Contact::gett
        CRM.api4('Contact', 'get', { select: ['display_nam'] }); // expect: field Contact.display_nam
        crmApi4('Contact', 'get', { where: [['OR', [['contact_typ', '=', 'Individual']]]] }); // expect: field Contact.contact_typ
        crmApi4('Contact', 'get', { orderBy: { sort_nam: 'ASC' } }); // expect: field Contact.sort_nam
        crmApi4([['Activity', 'create', { values: { subjct: 'x' } }]]); // expect: field Activity.subjct
        crmApi4({ one: ['Contact', 'get', { select: [`frist_name`] }] }); // expect: field Contact.frist_name
        crmApi4('Contact', 'get', { select: ['COUNT(id) AS n'], groupBy: ['contact_typ'] }); // expect: field Contact.contact_typ
      },
      healthy: function (entityName) {
        CRM.api4('Contact', 'get', {
          select: ['display_name', 'contact_type:label', 'address_primary.city', 'COUNT(id) AS total', 'row_count'],
          where: [['segment_region', '=', 1]],
          groupBy: ['contact_type', 'total'],
          orderBy: { total: 'DESC' },
        });
        CRM.api4('Contact', 'getFields', { select: ['name', 'label'], where: [['fk_entity', '=', 'Address']] });
        CRM.api4('Activity', 'Get', { select: ['subject'] });
        CRM.api4('Activity', 'create', { values: { subject: 'x', skipStatusCal: true } });
        CRM.api4('Activity', 'archive', {});
        CRM.api4('Contact', 'greet', {});
        CRM.api4('Widget', 'get', { select: ['anything'] });
        CRM.api4('Greeter', 'get', { select: ['anything'] });
        CRM.api4('Custom_Survey', 'get', { select: ['anything'] });
        // Other extensions' entities: one or two edits from a core name, a swap
        // of a short one (ACL), and one near nothing.
        CRM.api4('Identity', 'get', {});
        CRM.api4('Project', 'get', {});
        CRM.api4('Folder', 'get', {});
        CRM.api4('Contract', 'get', {});
        CRM.api4('Groups', 'get', {});
        CRM.api4('Notes', 'get', {});
        CRM.api4('Reports', 'get', {});
        CRM.api4('Mail', 'get', {});
        CRM.api4('Vote', 'get', {});
        CRM.api4('Patch', 'get', {});
        CRM.api4('Cal', 'get', {});
        CRM.api4('Frobnicator', 'get', {});
        return crmApi4(entityName, 'get', { select: ['whatever'] });
      },
    };
  });
})(angular, CRM);
