# Rules Engine DSL — Comprehensive Documentation

## Overview
This Rules Engine provides a declarative YAML/JSON-based DSL for evaluating pricing logic, rule-driven workflows, conditional flows, and context-based variable transformation.  
It is deterministic, side‑effect free, and designed for safe expression evaluation with strict control over what is exposed to the ExpressionLanguage runtime.

The engine supports:
- **Inputs & Outputs**
- **Rule-based flow control**
- **Expressions (`#! expr` syntax)**
- **Dot-notation (`foo.bar.baz`)**
- **Macros (reusable expression expansions)** ← *new*
- **Config merging**
- **Local rule inputs**
- **Safe variable sanitisation**
- **Custom expression extensions (Core, Money, Pricing)**

---

## Inputs & Outputs

### Inputs
Inputs define initial variables available to the rules.

```yaml
inputs:
  device_value:
  premium: 5.99
  sku: "FH123"
```

Notes:
- Inputs may contain literal values or expression values.
- Expression values begin with `#!`.

### Outputs
Outputs define the final projected shape returned by `.output()`:

```yaml
outputs:
  premium_formatted: "#! money_formatted(premium)"
  label: "#! min ~ '-' ~ max"
  sku:
```

Rules apply changes into global **context**. Outputs simply read from the context or evaluate expressions.

---

## Rules Structure

Each rule has:

```yaml
- rule: name
  inputs: {}        # optional local-scoped inputs
  when: "#! expr"   # optional condition
  then:             # executed if condition true or no when
    set:
      inputs: {}    # update context
      config: {}    # update config
    goto: next|return|<rule-name>
    throw: ...      # error
  otherwise: {...}  # executed if condition false
```

### Local Inputs
Rules may create local variables (not modifying global context):

```yaml
inputs:
  tax_rate: 0.2
  tier: "#! band_for(device_value, bands)"
```

---

## Expressions

Expressions use Symfony ExpressionLanguage.  
Literal strings → returned directly  
Strings beginning with `#!` → evaluated with ExpressionLanguage.

Example:

```yaml
premium: "#! money_plus(premium, 1)"
```

Available expression extensions:
- **Core functions** (empty, any, all, get, eq, neq, etc.)
- **Money functions** (money_of, money_plus, money_minus, money_formatted, etc.)
- **Pricing functions** (band_for, etc.)

### Dot-Notation Support
You can access nested values:

```
device_band.provider_code
config.money.currency
```

The DSL rewrites these into safe array access:

```
device_band['provider_code']
config['money']['currency']
```

---

## 🚀 Macros (Reusable Expression Expansions)

Macros allow you to define reusable expression fragments that behave like inline expression templates.

### Macro Definition
Macros are defined under a top‑level key:

```yaml
macros:
  premium_with_delta: "money_plus(premium, $1)"
  range_label: "min ~ '-' ~ max"
  surcharge: "premium + $1 + $2"
```

Each macro body is:
- A **string expression**, no `#!` prefix
- May reference positional parameters `$1`, `$2`, `$3`...
- May reference **context variables** (`premium`, `min`, etc.)

### Macro Usage

```yaml
premium: "#! premium_with_delta(2)"
label:   "#! range_label()"
```

### Expansion Rules
- Macros are expanded **before** dot-notation rewriting.
- Expansion is string substitution only — not evaluated until the final pass.
- You may nest macros:

```yaml
macros:
  add2: "$1 + 2"
  mul3: "$1 * 3"
  combo: "add2(mul3($1))"

value: "#! combo(10)"
```

### Limitations
- Maximum 5 recursive expansions (to prevent infinite recursion).
- Macro names must be valid identifiers (`[a-zA-Z_][a-zA-Z0-9_]*`).
- Arguments are simple positional values.
- Macros cannot define new variables; they only expand expressions.

---

## Control Flow

### goto
```yaml
goto: next
goto: return
goto: set_pricing
```

### throw
```yaml
throw:
  message: "Device band missing"
  code: "BAND_NOT_FOUND"
```

### when / otherwise
The conditional structure:

```yaml
when: "#! premium > 10"
then: ...
otherwise: ...
```

---

## Example: Full Pricing Model

```yaml
macros:
  premium_with_delta: "money_plus(premium, $1)"
  range_label: "min ~ '-' ~ max"

inputs:
  device_value:
  premium: 5.99
  sku: "FH0001"

outputs:
  label: "#! max === null ? money_formatted(min) ~ '+' : money_formatted(min) ~ '-' ~ money_formatted(max)"
  premium_formatted: "#! money_formatted(premium)"
  pricing_sku: "#! sku ~ '-' ~ provider_code"
  is_premium:
  min:
  max:

rules:
  - rule: find_band
    inputs:
      bands:
        - { min: 0, max: 149, provider_code: ABC, premium_delta: 0 }
        - { min: 150, max: 299, provider_code: DEF, premium_delta: 1 }
        - { min: 300, max: 399, provider_code: GHI, premium_delta: 2 }
        - { min: 400, max: 749, provider_code: JKL, premium_delta: 3 }
        - { min: 750, max: null, provider_code: XYZ, premium_delta: 4 }
    when: "#! true"
    then:
      set:
        inputs:
          device_band: "#! band_for(device_value, bands)"
      goto: set_pricing

  - rule: set_pricing
    when: "#! device_band !== null"
    then:
      set:
        inputs:
          provider_code: "#! device_band.provider_code"
          min: "#! device_band.min"
          max: "#! device_band.max"
          premium: "#! premium_with_delta(device_band.premium_delta)"
          is_premium: "#! device_value > 999"
      goto: return
```
