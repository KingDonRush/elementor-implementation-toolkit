import { __ } from "@wordpress/i18n";
import {
  CheckboxControl,
  SelectControl,
  TextControl,
  ToggleControl,
} from "@wordpress/components";
import { humanize } from "../contracts";
import { adapterFieldsForEntity } from "../collection-contracts";
import EntryActions from "./EntryActions";
import EntryConditions from "./EntryConditions";
import { EntrySteps, WorkflowDetails } from "./EntryWorkflowDetails";

function entityFields(entry, document, schema) {
  const edge = document.connections.find(
    (connection) =>
      "entry_for" === connection.type && entry.id === connection.to,
  );
  if (!edge) return [];
	const entity = document.nodes.find((node) => node.id === edge.from);
	const adapterFields = adapterFieldsForEntity(document, entity, schema).filter(
	  (field) => !field.validation?.read_only,
	);
	if (adapterFields.length) return adapterFields;
  const groups = document.connections
    .filter(
      (connection) =>
        "entity_fields" === connection.type && edge.from === connection.from,
    )
    .map((connection) =>
      document.nodes.find((node) => node.id === connection.to),
    )
    .filter(Boolean);
  return groups.flatMap((group) => group.config.fields || []);
}

export default function EntryDecisions({ node, document, schema, update }) {
  const config = node.config || {};
  const fields = entityFields(node, document, schema);
  const operations = config.operations || [];
  const updateConfig = (changes) =>
    update({ ...node, config: { ...config, ...changes } });
  const toggle = (operation, checked) =>
    updateConfig({
      operations: checked
        ? [...new Set([...operations, operation])]
        : operations.filter((value) => value !== operation),
    });
  return (
    <>
      <CheckboxControl
        label={__("Create entries", "elementor-implementation-toolkit")}
        checked={operations.includes("create")}
        onChange={(checked) => toggle("create", checked)}
      />
      <CheckboxControl
        label={__("Update entries", "elementor-implementation-toolkit")}
        checked={operations.includes("update")}
        onChange={(checked) => toggle("update", checked)}
      />
      <SelectControl
        label={__("New entry status", "elementor-implementation-toolkit")}
        value={config.initial_status || "draft"}
        options={["draft", "review", "publish"].map((value) => ({
          label: humanize(value),
          value,
        }))}
        onChange={(initial_status) => updateConfig({ initial_status })}
      />
      <ToggleControl
        label={__("Autosave drafts", "elementor-implementation-toolkit")}
        checked={Boolean(config.autosave?.enabled)}
        onChange={(enabled) =>
          updateConfig({ autosave: { ...config.autosave, enabled } })
        }
      />
      <ToggleControl
        label={__("Moderated guest intake", "elementor-implementation-toolkit")}
        checked={Boolean(config.guest?.enabled)}
        onChange={(enabled) =>
          updateConfig({ guest: { ...config.guest, enabled } })
        }
      />
      <div className="eit-advanced-decisions">
        <WorkflowDetails config={config} updateConfig={updateConfig} />
        {fields.some((field) => "short_text" === field.type) ? (
          <SelectControl
            label={__("Record title", "elementor-implementation-toolkit")}
            value={config.title_field_id || ""}
            options={[
              {
                label: __(
                  "Use the first short text field",
                  "elementor-implementation-toolkit",
                ),
                value: "",
              },
              ...fields
                .filter((field) => "short_text" === field.type)
                .map((field) => ({ label: field.name, value: field.id })),
            ]}
            onChange={(title_field_id) => updateConfig({ title_field_id })}
          />
        ) : null}
        <EntrySteps
          config={config}
          fields={fields}
          updateConfig={updateConfig}
        />
        <EntryConditions
          config={config}
          fields={fields}
          updateConfig={updateConfig}
        />
        <EntryActions config={config} updateConfig={updateConfig} />
        {config.guest?.enabled ? (
          <details>
            <summary>
              {__("Guest safeguards", "elementor-implementation-toolkit")}
            </summary>
            <TextControl
              type="number"
              min="1"
              max="30"
              label={__(
                "Submissions per hour",
                "elementor-implementation-toolkit",
              )}
              value={config.guest.rate_limit_per_hour || 5}
              onChange={(value) =>
                updateConfig({
                  guest: {
                    ...config.guest,
                    rate_limit_per_hour: Number(value) || 5,
                  },
                })
              }
            />
            <SelectControl
              label={__("Guest status", "elementor-implementation-toolkit")}
              value={config.guest.moderation_status || "review"}
              options={[
                {
                  label: __("In review", "elementor-implementation-toolkit"),
                  value: "review",
                },
                {
                  label: __("Draft", "elementor-implementation-toolkit"),
                  value: "draft",
                },
              ]}
              onChange={(moderation_status) =>
                updateConfig({ guest: { ...config.guest, moderation_status } })
              }
            />
            <ToggleControl
              label={__(
                "Allow image uploads",
                "elementor-implementation-toolkit",
              )}
              checked={Boolean(config.guest.upload_max_bytes)}
              onChange={(enabled) =>
                updateConfig({
                  guest: {
                    ...config.guest,
                    upload_max_bytes: enabled ? 5242880 : 0,
                    upload_mime_types: enabled
                      ? ["image/jpeg", "image/png", "image/webp"]
                      : [],
                  },
                })
              }
            />
          </details>
        ) : null}
      </div>
    </>
  );
}
