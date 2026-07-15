export function evaluateEntryExpression(expression, values) {
  const tokens = tokenize(expression);
  if (!tokens) return null;
  const postfix = toPostfix(tokens);
  if (!postfix) return null;
  const stack = [];
  for (const token of postfix) {
    if ("number" === typeof token) {
      stack.push(token);
    } else if (token.field) {
      const raw = values[token.field];
      const value =
        raw && "object" === typeof raw && !Array.isArray(raw)
          ? raw.amount
          : raw;
      if (!Number.isFinite(Number(value))) return null;
      stack.push(Number(value));
    } else {
      const right = stack.pop();
      const left = stack.pop();
      if (
        !Number.isFinite(left) ||
        !Number.isFinite(right) ||
        ("/" === token.operator && 0 === right)
      )
        return null;
      stack.push(operate(token.operator, left, right));
    }
  }
  return 1 === stack.length && Number.isFinite(stack[0]) ? stack[0] : null;
}

function tokenize(expression) {
  const source = String(expression || "").trim();
  if (!source || source.length > 500) return null;
  const pattern =
    /\s*(\{([a-f0-9-]{36})\}|(?:\d+(?:\.\d+)?|\.\d+)|[()+\-*\/])\s*/giy;
  const tokens = [];
  let offset = 0;
  while (offset < source.length) {
    pattern.lastIndex = offset;
    const match = pattern.exec(source);
    if (!match || match.index !== offset) return null;
    tokens.push(match[2] ? { field: match[2].toLowerCase() } : match[1]);
    offset = pattern.lastIndex;
    if (tokens.length > 100) return null;
  }
  return tokens;
}

function toPostfix(tokens) {
  const output = [];
  const operators = [];
  const precedence = { "+": 1, "-": 1, "*": 2, "/": 2 };
  let expectsValue = true;
  for (const token of tokens) {
    if ("object" === typeof token || !Number.isNaN(Number(token))) {
      if (!expectsValue) return null;
      output.push("object" === typeof token ? token : Number(token));
      expectsValue = false;
    } else if ("(" === token) {
      if (!expectsValue) return null;
      operators.push(token);
    } else if (")" === token) {
      if (expectsValue || !closeParenthesis(operators, output)) return null;
      expectsValue = false;
    } else if (precedence[token]) {
      if (expectsValue) return null;
      while (
        operators.length &&
        precedence[operators[operators.length - 1]] >= precedence[token]
      ) {
        output.push({ operator: operators.pop() });
      }
      operators.push(token);
      expectsValue = true;
    }
  }
  if (expectsValue) return null;
  while (operators.length) {
    const operator = operators.pop();
    if ("(" === operator) return null;
    output.push({ operator });
  }
  return output;
}

function closeParenthesis(operators, output) {
  while (operators.length) {
    const operator = operators.pop();
    if ("(" === operator) return true;
    output.push({ operator });
  }
  return false;
}

function operate(operator, left, right) {
  if ("+" === operator) return left + right;
  if ("-" === operator) return left - right;
  if ("*" === operator) return left * right;
  return left / right;
}
