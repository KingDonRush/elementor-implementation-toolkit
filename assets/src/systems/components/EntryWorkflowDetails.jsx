import { __ } from "@wordpress/i18n";
import { Button, CheckboxControl, TextControl } from "@wordpress/components";
import { uuid } from "../contracts";

export function WorkflowDetails({ config, updateConfig }) {
  const operations = config.operations || [];
  const toggle = (operation, checked) =>
    updateConfig({
      operations: checked
        ? [...new Set([...operations, operation])]
        : operations.filter((value) => value !== operation),
    });
  return (
    <details>
      <summary>
        {__("Lifecycle transitions", "elementor-implementation-toolkit")}
      </summary>
      {[
        [
          "submit_review",
          __("Submit for review", "elementor-implementation-toolkit"),
        ],
        ["publish", __("Publish", "elementor-implementation-toolkit")],
        ["archive", __("Archive", "elementor-implementation-toolkit")],
        ["restore", __("Restore", "elementor-implementation-toolkit")],
      ].map(([operation, label]) => (
        <CheckboxControl
          key={operation}
          label={label}
          checked={operations.includes(operation)}
          onChange={(checked) => toggle(operation, checked)}
        />
      ))}
    </details>
  );
}

export function EntrySteps({ config, fields, updateConfig }) {
  const steps = config.steps || [];
  const update = (next) => updateConfig({ steps: next });
  return (
    <details>
      <summary>
        {__("Custom steps", "elementor-implementation-toolkit")}
      </summary>
      <p>
        {steps.length
          ? __(
              "Each field can be placed by its public name; storage identifiers remain hidden.",
              "elementor-implementation-toolkit",
            )
          : __(
              "Without custom steps, the Surface compiles one concise Details step.",
              "elementor-implementation-toolkit",
            )}
      </p>
      {steps.map((step, index) => (
        <details className="eit-compact-editor" key={step.id}>
          <summary>{step.name}</summary>
          <TextControl
            label={__("Step name", "elementor-implementation-toolkit")}
            value={step.name}
            onChange={(name) =>
              update(
                steps.map((current, offset) =>
                  offset === index ? { ...current, name } : current,
                ),
              )
            }
          />
          {fields.map((field) => (
            <CheckboxControl
              key={field.id}
              label={field.name}
              checked={step.field_ids.includes(field.id)}
              onChange={(checked) =>
                update(
                  steps.map((current, offset) =>
                    offset === index
                      ? {
                          ...current,
                          field_ids: checked
                            ? [...new Set([...current.field_ids, field.id])]
                            : current.field_ids.filter((id) => id !== field.id),
                        }
                      : current,
                  ),
                )
              }
            />
          ))}
          <Button
            isDestructive
            variant="tertiary"
            onClick={() =>
              update(steps.filter((current) => current.id !== step.id))
            }
          >
            {__("Remove step", "elementor-implementation-toolkit")}
          </Button>
        </details>
      ))}
      <Button
        variant="secondary"
        onClick={() =>
          update([
            ...steps,
            {
              id: uuid(),
              name: __("New step", "elementor-implementation-toolkit"),
              field_ids: [],
            },
          ])
        }
      >
        {__("Add step", "elementor-implementation-toolkit")}
      </Button>
    </details>
  );
}
