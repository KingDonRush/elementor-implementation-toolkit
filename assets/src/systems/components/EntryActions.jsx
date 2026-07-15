import { __ } from "@wordpress/i18n";
import { Button, SelectControl, TextControl } from "@wordpress/components";
import { humanize, uuid } from "../contracts";

export default function EntryActions({ config, updateConfig }) {
  const actions = config.actions || [];
  const update = (next) => updateConfig({ actions: next });
  return (
    <details>
      <summary>
        {__("After-save actions", "elementor-implementation-toolkit")}
      </summary>
      {actions.map((action, index) => (
        <div className="eit-compact-editor" key={action.id}>
          <SelectControl
            label={__("Action", "elementor-implementation-toolkit")}
            value={action.type}
            options={["redirect", "email", "notification", "webhook"].map(
              (value) => ({ label: humanize(value), value }),
            )}
            onChange={(type) =>
              update(
                actions.map((current, offset) =>
                  offset === index ? { ...current, type, config: {} } : current,
                ),
              )
            }
          />
          <SelectControl
            label={__("Runs after", "elementor-implementation-toolkit")}
            value={action.events[0]}
            options={[
              "created",
              "updated",
              "submitted_for_review",
              "published",
              "archived",
              "restored",
            ].map((value) => ({ label: humanize(value), value }))}
            onChange={(event) =>
              update(
                actions.map((current, offset) =>
                  offset === index ? { ...current, events: [event] } : current,
                ),
              )
            }
          />
          {["redirect", "webhook"].includes(action.type) ? (
            <TextControl
              type="url"
              label={
                action.type === "webhook"
                  ? __("Webhook URL", "elementor-implementation-toolkit")
                  : __("Safe redirect URL", "elementor-implementation-toolkit")
              }
              value={action.config.url || ""}
              onChange={(url) =>
                update(
                  actions.map((current, offset) =>
                    offset === index
                      ? { ...current, config: { ...current.config, url } }
                      : current,
                  ),
                )
              }
            />
          ) : null}
          {"email" === action.type ? (
            <TextControl
              label={__("Email subject", "elementor-implementation-toolkit")}
              value={action.config.subject || ""}
              onChange={(subject) =>
                update(
                  actions.map((current, offset) =>
                    offset === index
                      ? { ...current, config: { recipient: "admin", subject } }
                      : current,
                  ),
                )
              }
            />
          ) : null}
          <Button
            isDestructive
            variant="tertiary"
            onClick={() =>
              update(actions.filter((current) => current.id !== action.id))
            }
          >
            {__("Remove action", "elementor-implementation-toolkit")}
          </Button>
        </div>
      ))}
      <Button
        variant="secondary"
        onClick={() =>
          update([
            ...actions,
            {
              id: uuid(),
              type: "notification",
              events: ["created"],
              config: {},
            },
          ])
        }
      >
        {__("Add action", "elementor-implementation-toolkit")}
      </Button>
    </details>
  );
}
