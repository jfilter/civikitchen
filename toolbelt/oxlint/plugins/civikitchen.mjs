// CiviKitchen's oxlint rules. `api4-contract` applies the judgement of the
// phpstan extension's Api4Contract to CRM.api4() / crmApi4() literals.
import { readFileSync } from 'node:fs';

const CLAUSE_OPERATORS = ['AND', 'OR', 'NOT'];

// Actions whose clauses name the entity's own fields; getFields and custom
// actions filter rows of another shape.
const RECORD_ACTIONS = ['get', 'create', 'update', 'save', 'delete', 'replace'];

let loaded = null;

function catalog() {
  if (loaded !== null) {
    return loaded;
  }
  const path = process.env.CIVIKITCHEN_API4_CATALOG;
  if (!path) {
    throw new Error('civikitchen/api4-contract: CIVIKITCHEN_API4_CATALOG is not set - run it through ckeslint');
  }
  const raw = JSON.parse(readFileSync(path, 'utf8'));
  loaded = {
    ...raw,
    extensionEntities: new Set(raw.extensionEntities),
    extensionActions: new Set(raw.extensionActions.map((name) => name.toLowerCase())),
  };
  return loaded;
}

function words(value) {
  return value ? value.split(' ') : [];
}

function levenshtein(a, b) {
  let previous = Array.from({ length: b.length + 1 }, (_, i) => i);
  for (let i = 1; i <= a.length; i++) {
    const current = [i];
    for (let j = 1; j <= b.length; j++) {
      current[j] = Math.min(previous[j] + 1, current[j - 1] + 1, previous[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
    }
    previous = current;
  }
  return previous[b.length];
}

// Two edits away from a four-letter entity is a different word, not a typo.
function nearestEntity(api, entity) {
  if (entity.length < 4) {
    return null;
  }
  const tolerance = entity.length >= 6 ? 2 : 1;
  let best = null;
  let bestDistance = Infinity;
  for (const known of Object.keys(api.entities)) {
    const distance = levenshtein(entity.toLowerCase(), known.toLowerCase());
    if (distance < bestDistance) {
      bestDistance = distance;
      best = known;
    }
  }
  return bestDistance <= tolerance ? best : null;
}

function entityIsKnown(api, entity) {
  return entity in api.entities
    || api.dynamicPrefixes.some((prefix) => entity.startsWith(prefix))
    || api.extensionEntities.has(entity);
}

function entityClass(api, entity) {
  const alias = Object.keys(api.aliases).find((name) => api.aliases[name] === entity);
  return alias ?? entity;
}

function checkEntity(api, entity) {
  if (entityIsKnown(api, entity)) {
    return null;
  }
  const suggestion = nearestEntity(api, entity);
  return suggestion === null ? null
    : `APIv4 entity ${entity} does not exist in CiviCRM ${api.version} — did you mean ${suggestion}?`;
}

function checkAction(api, entity, action) {
  // php method names are case-insensitive: `User::Update` runs `update`.
  const actions = words(api.entities[entity]?.a).map((name) => name.toLowerCase());
  if (!(entity in api.entities) || actions.includes(action.toLowerCase())) {
    return null;
  }
  const actionClass = `${entityClass(api, entity)}\\${action.charAt(0).toUpperCase()}${action.slice(1)}`;
  if (api.extensionActions.has(actionClass.toLowerCase())) {
    return null;
  }
  return `APIv4 action ${entity}::${action} does not exist in CiviCRM ${api.version}`;
}

// A dot is a join or custom field, a star/space/paren/quote a SQL expression.
function isCheckableFieldName(api, field) {
  if (field === '' || field === 'row_count' || /[.*() "'@]/.test(field)) {
    return false;
  }
  if (api.dynamicFieldPrefixes.some((prefix) => field.startsWith(prefix))) {
    return false;
  }
  return /^[A-Za-z_][A-Za-z0-9_]*(:[A-Za-z_]+)?$/.test(field);
}

function checkField(api, entity, field, clause) {
  const known = api.entities[entity];
  if (known === undefined || known.c !== true || !isCheckableFieldName(api, field)) {
    return null;
  }
  const [name] = field.split(':');
  if (words(known.f).includes(name) || api.anyEntityFields.includes(name)) {
    return null;
  }
  // Writes pass camelCase control params (skipStatusCal) on to the BAO.
  if (clause === 'values' && /[A-Z]/.test(name)) {
    return null;
  }
  return `APIv4 field ${entity}.${name} does not exist in CiviCRM ${api.version} — ${clause}`;
}

function stringValue(node) {
  if (node?.type === 'Literal' && typeof node.value === 'string') {
    return node.value;
  }
  if (node?.type === 'TemplateLiteral' && node.expressions.length === 0) {
    return node.quasis[0].value.cooked;
  }
  return null;
}

function propertyName(property) {
  if (property.type !== 'Property' || property.computed) {
    return null;
  }
  return property.key.type === 'Identifier' ? property.key.name : stringValue(property.key);
}

function stringElements(node) {
  if (node?.type !== 'ArrayExpression') {
    return [];
  }
  return node.elements.filter((element) => stringValue(element) !== null);
}

function whereFields(node, depth = 0) {
  if (node?.type !== 'ArrayExpression' || depth > 4) {
    return [];
  }
  const found = [];
  for (const clause of node.elements) {
    if (clause?.type !== 'ArrayExpression') {
      continue;
    }
    const first = stringValue(clause.elements[0]);
    if (first === null) {
      continue;
    }
    if (CLAUSE_OPERATORS.includes(first.toUpperCase())) {
      found.push(...whereFields(clause.elements[1], depth + 1));
    } else {
      found.push(clause.elements[0]);
    }
  }
  return found;
}

function keyNodes(node) {
  if (node?.type !== 'ObjectExpression') {
    return [];
  }
  return node.properties.filter((property) => propertyName(property) !== null).map((property) => property.key);
}

function paramFields(params) {
  if (params?.type !== 'ObjectExpression') {
    return [];
  }
  const entries = new Map();
  for (const property of params.properties) {
    const name = propertyName(property);
    if (name !== null) {
      entries.set(name, property.value);
    }
  }
  // `SUM(qty) AS total` makes `total` a legal name in orderBy and groupBy.
  const aliases = new Set();
  for (const select of stringElements(entries.get('select'))) {
    const match = /\sAS\s+([A-Za-z_][A-Za-z0-9_]*)$/i.exec(stringValue(select));
    if (match) {
      aliases.add(match[1]);
    }
  }
  const found = [];
  for (const [clause, value] of entries) {
    let nodes = [];
    if (clause === 'select' || clause === 'groupBy') {
      nodes = stringElements(value);
    } else if (clause === 'where') {
      nodes = whereFields(value);
    } else if (clause === 'orderBy' || clause === 'values') {
      nodes = keyNodes(value);
    }
    for (const node of nodes) {
      const field = stringValue(node) ?? node.name;
      if (!aliases.has(field)) {
        found.push({ node, field, clause });
      }
    }
  }
  return found;
}

function isApi4Callee(callee) {
  if (callee.type === 'Identifier') {
    return callee.name === 'crmApi4';
  }
  return callee.type === 'MemberExpression' && !callee.computed
    && callee.object.type === 'Identifier' && callee.object.name === 'CRM'
    && callee.property.type === 'Identifier' && callee.property.name === 'api4';
}

// [entity, action, params] argument lists: one direct call, or a batch given
// as an array or object of such tuples.
function calls(args) {
  if (stringValue(args[0]) !== null) {
    return [args];
  }
  let tuples = [];
  if (args[0]?.type === 'ArrayExpression') {
    tuples = args[0].elements;
  } else if (args[0]?.type === 'ObjectExpression') {
    tuples = args[0].properties.filter((property) => property.type === 'Property').map((property) => property.value);
  }
  return tuples.filter((tuple) => tuple?.type === 'ArrayExpression' && stringValue(tuple.elements[0]) !== null)
    .map((tuple) => tuple.elements);
}

function checkCall(context, api, [entityNode, actionNode, params]) {
  const entity = stringValue(entityNode);
  const entityError = checkEntity(api, entity);
  if (entityError !== null) {
    context.report({ node: entityNode, message: entityError });
    return;
  }
  const action = stringValue(actionNode);
  const actionError = action === null ? null : checkAction(api, entity, action);
  if (actionError !== null) {
    context.report({ node: actionNode, message: actionError });
  }
  if (action === null || !RECORD_ACTIONS.includes(action.toLowerCase())) {
    return;
  }
  for (const { node, field, clause } of paramFields(params)) {
    const fieldError = checkField(api, entity, field, clause);
    if (fieldError !== null) {
      context.report({ node, message: fieldError });
    }
  }
}

const api4Contract = {
  meta: {
    type: 'problem',
    docs: { description: 'Entity, action and field names in APIv4 calls must exist in the pinned CiviCRM core.' },
  },
  create(context) {
    const api = catalog();
    return {
      CallExpression(node) {
        if (!isApi4Callee(node.callee)) {
          return;
        }
        for (const call of calls(node.arguments)) {
          checkCall(context, api, call);
        }
      },
    };
  },
};

export default {
  meta: { name: 'civikitchen' },
  rules: { 'api4-contract': api4Contract },
};
