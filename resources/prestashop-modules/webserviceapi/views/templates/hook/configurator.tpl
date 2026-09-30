<div class="panel">
  <h3>Configuratore</h3>
  <input type="hidden" name="webserviceapi_configurator_active" value="0">
  <label><input type="checkbox" name="webserviceapi_configurator_active" value="1" {if $configurator_active}checked{/if}> Configuratore attivo</label>
  <label for="webserviceapi_configurator_json">Configurazione JSON</label>
  <textarea class="form-control" id="webserviceapi_configurator_json" name="webserviceapi_configurator_json" rows="12">{$configurator_json|escape:'html':'UTF-8'}</textarea>
  <p class="help-block">Inserisci un JSON valido oppure lascia il campo vuoto.</p>
</div>
