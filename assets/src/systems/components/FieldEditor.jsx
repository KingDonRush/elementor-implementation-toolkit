import { __ } from "@wordpress/i18n";
import {
  Button,
  SelectControl,
  TextControl,
  ToggleControl,
} from "@wordpress/components";
import { changeFieldPrimitive, humanize, uuid } from "../contracts";

const CHILD_TYPES = [
  "short_text",
  "integer",
  "decimal",
  "boolean",
  "date",
  "time",
  "datetime",
];

function ChoiceOptions({ field, onChange }) {
  const options = field.validation.options || [];
  const update = (next) =>
    onChange({ ...field, validation: { ...field.validation, options: next } });
  return (
    <details>
      <summary>
        {__("Choice options", "elementor-implementation-toolkit")}
      </summary>
      {options.map((option, index) => (
        <div className="eit-compact-editor" key={option.value}>
          <TextControl
            label={__("Option label", "elementor-implementation-toolkit")}
            value={option.label}
            onChange={(label) =>
              update(
                options.map((current, offset) =>
                  offset === index ? { ...current, label } : current,
                ),
              )
            }
          />
          <Button
            isDestructive
            variant="tertiary"
            onClick={() =>
              update(
                options.filter((current) => current.value !== option.value),
              )
            }
          >
            {__("Remove", "elementor-implementation-toolkit")}
          </Button>
        </div>
      ))}
      <Button
        variant="secondary"
        onClick={() =>
          update([
            ...options,
            {
              value: uuid(),
              label: __("New option", "elementor-implementation-toolkit"),
            },
          ])
        }
      >
        {__("Add option", "elementor-implementation-toolkit")}
      </Button>
    </details>
  );
}

function RepeaterChildren({ field, onChange }) {
  const children = field.validation.children || [];
  const update = (next) =>
    onChange({ ...field, validation: { ...field.validation, children: next } });
  return (
    <details>
      <summary>
        {__("Repeatable row fields", "elementor-implementation-toolkit")}
      </summary>
      {children.map((child, index) => (
        <div className="eit-compact-editor" key={child.id}>
          <TextControl
            label={__("Public name", "elementor-implementation-toolkit")}
            value={child.name}
            onChange={(name) =>
              update(
                children.map((current, offset) =>
                  offset === index ? { ...current, name } : current,
                ),
              )
            }
          />
          <SelectControl
            label={__("Value type", "elementor-implementation-toolkit")}
            value={child.type}
            options={CHILD_TYPES.map((type) => ({
              label: humanize(type),
              value: type,
            }))}
            onChange={(type) =>
              update(
                children.map((current, offset) =>
                  offset === index ? { ...current, type } : current,
                ),
              )
            }
          />
          <Button
            isDestructive
            variant="tertiary"
            onClick={() =>
              update(children.filter((current) => current.id !== child.id))
            }
          >
            {__("Remove", "elementor-implementation-toolkit")}
          </Button>
        </div>
      ))}
      <Button
        variant="secondary"
        onClick={() =>
          update([
            ...children,
            {
              id: uuid(),
              name: __("Row value", "elementor-implementation-toolkit"),
              type: "short_text",
            },
          ])
        }
      >
        {__("Add row field", "elementor-implementation-toolkit")}
      </Button>
    </details>
  );
}

function Calculation({ field, fields, onChange }) {
  const numeric = fields.filter(
    (candidate) =>
      candidate.id !== field.id &&
      ["integer", "decimal", "money", "percentage"].includes(candidate.type),
  );
  const formula = field.validation.formula || {
    left: numeric[0]?.id || "",
    operator: "*",
    right: "number",
    number: 1,
  };
  const update = (next) => {
    const right =
      "number" === next.right ? Number(next.number) || 0 : `{${next.right}}`;
    const expression = next.left
      ? `{${next.left}} ${next.operator} ${right}`
      : "";
    onChange({
      ...field,
      validation: { ...field.validation, formula: next, expression },
    });
  };
  const fieldOptions = numeric.map((candidate) => ({
    label: candidate.name,
    value: candidate.id,
  }));
  return (
    <details open>
      <summary>
        {__("Safe calculation", "elementor-implementation-toolkit")}
      </summary>
      <SelectControl
        label={__("Start with", "elementor-implementation-toolkit")}
        value={formula.left}
        options={[
          {
            label: __(
              "Choose a number field",
              "elementor-implementation-toolkit",
            ),
            value: "",
          },
          ...fieldOptions,
        ]}
        onChange={(left) => update({ ...formula, left })}
      />
      <SelectControl
        label={__("Operation", "elementor-implementation-toolkit")}
        value={formula.operator}
        options={[
          ["*", __("Multiply by", "elementor-implementation-toolkit")],
          ["/", __("Divide by", "elementor-implementation-toolkit")],
          ["+", __("Add", "elementor-implementation-toolkit")],
          ["-", __("Subtract", "elementor-implementation-toolkit")],
        ].map(([value, label]) => ({ value, label }))}
        onChange={(operator) => update({ ...formula, operator })}
      />
      <SelectControl
        label={__("Use", "elementor-implementation-toolkit")}
        value={formula.right}
        options={[
          {
            label: __("A fixed number", "elementor-implementation-toolkit"),
            value: "number",
          },
          ...fieldOptions,
        ]}
        onChange={(right) => update({ ...formula, right })}
      />
      {"number" === formula.right ? (
        <TextControl
          type="number"
          label={__("Fixed number", "elementor-implementation-toolkit")}
          value={formula.number}
          onChange={(number) => update({ ...formula, number })}
        />
      ) : null}
    </details>
  );
}

export default function FieldEditor({ field, fields, onChange, schema }) {
  return (
    <details className="eit-inspector-field">
      <summary>{field.name}</summary>
      <div>
        <TextControl
          label={__("Public name", "elementor-implementation-toolkit")}
          value={field.name}
          onChange={(name) => onChange({ ...field, name })}
        />
        <SelectControl
          label={__("Semantic type", "elementor-implementation-toolkit")}
          value={field.type}
          options={Object.keys(schema.primitives).map((type) => ({
            label: humanize(type),
            value: type,
          }))}
          onChange={(type) =>
            onChange(changeFieldPrimitive(field, schema, type))
          }
        />
        <ToggleControl
          label={__("Required", "elementor-implementation-toolkit")}
          checked={Boolean(field.validation.required)}
          onChange={(required) =>
            onChange({
              ...field,
              validation: { ...field.validation, required },
            })
          }
        />
        <ToggleControl
          label={__("Filterable", "elementor-implementation-toolkit")}
          checked={Boolean(field.indexing.filter)}
          disabled={!field.capabilities.filter}
          onChange={(filter) =>
            onChange({ ...field, indexing: { ...field.indexing, filter } })
          }
        />
        <ToggleControl
          label={__("Sortable", "elementor-implementation-toolkit")}
          checked={Boolean(field.indexing.sort)}
          disabled={!field.capabilities.sort}
          onChange={(sort) =>
            onChange({ ...field, indexing: { ...field.indexing, sort } })
          }
        />
        {["single_choice", "multiple_choice"].includes(field.type) ? (
          <ChoiceOptions field={field} onChange={onChange} />
        ) : null}
        {"repeatable_group" === field.type ? (
          <RepeaterChildren field={field} onChange={onChange} />
        ) : null}
        {"calculated" === field.type ? (
          <Calculation field={field} fields={fields} onChange={onChange} />
        ) : null}
      </div>
    </details>
  );
}
