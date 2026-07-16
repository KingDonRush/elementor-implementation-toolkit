import { $ } from './runtime.js';
import { handleImportPreset, handleSavePreset } from './presets.js';
import { installCadence } from './cadence.js';
import { renderEditorCompatWarning } from './compat.js';
import { renderPanelHelper } from './targets.js';
import { installCollectionPairing } from './collection-pairing.js';
import { installDynamicTagContext } from './dynamic-tag-context.js';

function refreshPanelTools() {
  renderPanelHelper();
  renderEditorCompatWarning();
}

$(document).on('click', '[data-eit-save-preset]', handleSavePreset);
$(document).on('click', '[data-eit-import-preset]', handleImportPreset);

installCadence(refreshPanelTools);
installCollectionPairing();
installDynamicTagContext();
