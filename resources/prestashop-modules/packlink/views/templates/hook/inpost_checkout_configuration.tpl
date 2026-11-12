{**
 * 2026 DolceZampa
 *
 * This form is deliberately server rendered: the Packlink InPost checkout
 * mapping stays operable independently of the Packlink admin SPA.
 *}
<section class="panel" style="clear: both; margin-top: 24px;">
  <h3><i class="icon-map-marker"></i> Packlink × InPost — configurazione checkout</h3>
  <p>
    Attiva qui esclusivamente il servizio InPost gia configurato in Packlink.
    Il modulo usa solo l'API Packlink: non inserire chiavi, token o credenziali InPost.
  </p>
  <p>
    Scegli una coppia <strong>riferimento corriere / metodo Packlink</strong> dalla tabella.
    Al salvataggio il modulo verifica che la coppia corrisponda a un servizio Packlink attivo con consegna presso punto di ritiro.
    Rimuovendo tutte le associazioni l'endpoint checkout InPost viene disabilitato.
  </p>

  {if $inPostAvailableCarrierMappings|@count}
    <h4>Servizi Packlink disponibili per il ritiro</h4>
    <div class="table-responsive-row clearfix">
      <table class="table">
        <thead>
          <tr>
            <th>Corriere</th>
            <th>Riferimento corriere PrestaShop</th>
            <th>Metodo Packlink</th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$inPostAvailableCarrierMappings item=carrier}
            <tr>
              <td>{$carrier.carrierName|escape:'html':'UTF-8'}</td>
              <td><code>{$carrier.carrierReferenceId|escape:'html':'UTF-8'}</code></td>
              <td><code>{$carrier.methodId|escape:'html':'UTF-8'}</code></td>
            </tr>
          {/foreach}
        </tbody>
      </table>
    </div>
  {else}
    <div class="alert alert-warning">
      Non sono presenti servizi Packlink attivi con consegna presso punto di ritiro.
    </div>
  {/if}

  <form method="post" action="">
    <input type="hidden" name="token" value="{$inPostMappingToken|escape:'html':'UTF-8'}">
    <input type="hidden" name="submitPacklinkInPostCarrierMapping" value="1">
    <input type="hidden" name="inpost_mapping_action" value="save">
    <div class="form-group">
      <label for="inpost-carrier-reference-id">Riferimento corriere PrestaShop</label>
      <input class="form-control" id="inpost-carrier-reference-id" name="inpost_carrier_reference_id" type="number" min="1" required>
    </div>
    <div class="form-group">
      <label for="inpost-packlink-method-id">ID metodo Packlink</label>
      <input class="form-control" id="inpost-packlink-method-id" name="inpost_packlink_method_id" type="text" required>
    </div>
    <button class="btn btn-primary" type="submit">Salva associazione InPost</button>
  </form>

  {if $inPostCarrierMappings|@count}
    <h4 style="margin-top: 24px;">Associazioni InPost attive</h4>
    <table class="table">
      <thead>
        <tr>
          <th>Riferimento corriere PrestaShop</th>
          <th>Metodo Packlink</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$inPostCarrierMappings key=referenceId item=methodId}
          <tr>
            <td><code>{$referenceId|escape:'html':'UTF-8'}</code></td>
            <td><code>{$methodId|escape:'html':'UTF-8'}</code></td>
            <td class="text-right">
              <form method="post" action="">
                <input type="hidden" name="token" value="{$inPostMappingToken|escape:'html':'UTF-8'}">
                <input type="hidden" name="submitPacklinkInPostCarrierMapping" value="1">
                <input type="hidden" name="inpost_mapping_action" value="disable">
                <input type="hidden" name="inpost_carrier_reference_id" value="{$referenceId|escape:'html':'UTF-8'}">
                <button class="btn btn-default" type="submit">Disabilita</button>
              </form>
            </td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  {/if}
</section>
