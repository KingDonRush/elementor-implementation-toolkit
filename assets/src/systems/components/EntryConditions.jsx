import { __ } from "@wordpress/i18n";
import { Button, SelectControl, TextControl } from "@wordpress/components";
import { humanize, uuid } from "../contracts";

export default function EntryConditions({ config, fields, updateConfig }) {
  const conditions = config.conditions || [];
  const update = (next) => updateConfig({ conditions: next });
  const options = fields.map((field) => ({
    label: field.name,
    value: field.id,
  }));
  return (
    <details>
      <summary>
        {__("Conditional fields", "elementor-implementation-toolkit")}
      </summary>
      {conditions.map((condition, index) => (
        <div className="eit-compact-editor" key={condition.id}>
          <SelectControl
            label={__("When field", "elementor-implementation-toolkit")}
            value={condition.source_field_id}
            options={options}
            onChange={(source_field_id) =>
              update(
                conditions.map((current, offset) =>
                  offset === index ? { ...current, source_field_id } : current,
                ),
              )
            }
          />
          <SelectControl
            label={__("Comparison", "elementor-implementation-toolkit")}
            value={condition.operator}
            options={[
              "equals",
              "not_equals",
              "empty",
              "not_empty",
              "gt",
              "gte",
              "lt",
              "lte",
            ].map((value) => ({ label: humanize(value), value }))}
            onChange={(operator) =>
              update(
                conditions.map((current, offset) =>
                  offset === index ? { ...current, operator } : current,
                ),
              )
            }
          />
          {!["empty", "not_empty"].includes(condition.operator) ? (
            <TextControl
              label={__("Compared with", "elementor-implementation-toolkit")}
              value={condition.value || ""}
              onChange={(value) =>
                update(
                  conditions.map((current, offset) =>
                    offset === index ? { ...current, value } : current,
                  ),
                )
              }
            />
          ) : null}
          <SelectControl
            label={__("Then", "elementor-implementation-toolkit")}
            value={condition.effect}
            options={["show", "hide", "require"].map((value) => ({
              label: humanize(value),
              value,
            }))}
            onChange={(effect) =>
              update(
                conditions.map((current, offset) =>
                  offset === index ? { ...current, effect } : current,
                ),
              )
            }
          />
          <SelectControl
            label={__("Target field", "elementor-implementation-toolkit")}
            value={condition.target_field_id}
            options={options}
            onChange={(target_field_id) =>
              update(
                conditions.map((current, offset) =>
                  offset === index ? { ...current, target_field_id } : current,
                ),
              )
            }
          />
          <Button
            isDestructive
            variant="tertiary"
            onClick={() =>
              update(
                conditions.filter((current) => current.id !== condition.id),
              )
            }
          >
            {__("Remove condition", "elementor-implementation-toolkit")}
          </Button>
        </div>
      ))}
      <Button
        variant="secondary"
        disabled={fields.length < 2}
        onClick={() =>
          update([
            ...conditions,
            {
              id: uuid(),
              source_field_id: fields[0]?.id || "",
              operator: "equals",
              value: "",
              effect: "show",
              target_field_id: fields[1]?.id || "",
            },
          ])
        }
      >
        {__("Add condition", "elementor-implementation-toolkit")}
      </Button>
    </details>
  );
}
