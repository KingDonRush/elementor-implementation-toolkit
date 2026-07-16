export function matchesEntryCondition(actual, operator, expected) {
  if ("empty" === operator || "not_empty" === operator) {
    const empty =
      null == actual ||
      "" === actual ||
      (Array.isArray(actual) && 0 === actual.length);
    return "empty" === operator ? empty : !empty;
  }
  if ("in" === operator || "not_in" === operator) {
    const actualList = (Array.isArray(actual) ? actual : [actual]).map(
      conditionString,
    );
    const expectedList = (Array.isArray(expected) ? expected : [expected]).map(
      conditionString,
    );
    const found = actualList.some((value) => expectedList.includes(value));
    return "in" === operator ? found : !found;
  }
  if (["gt", "gte", "lt", "lte"].includes(operator)) {
    const left = conditionNumber(actual);
    const right = conditionNumber(expected);
    if (null === left || null === right) return false;
    return {
      gt: left > right,
      gte: left >= right,
      lt: left < right,
      lte: left <= right,
    }[operator];
  }
  const equal = conditionString(actual) === conditionString(expected);
  return "not_equals" === operator ? !equal : equal;
}

function conditionScalar(value) {
  if (value && "object" === typeof value && !Array.isArray(value)) {
    if (Object.hasOwn(value, "id")) return value.id;
    if (Object.hasOwn(value, "amount")) return value.amount;
  }
  return value;
}

function conditionString(value) {
  const scalar = conditionScalar(value);
  return null == scalar ? "" : String(scalar);
}

function conditionNumber(value) {
  const scalar = conditionScalar(value);
  if (
    null == scalar ||
    "boolean" === typeof scalar ||
    ("string" === typeof scalar && "" === scalar.trim())
  )
    return null;
  const number = Number(scalar);
  return Number.isFinite(number) ? number : null;
}
