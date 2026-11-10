/* Social-Media-Posting: Admin-Modul „Social Media“ (ohne Build-Schritt, reines JavaScript). */
(function () {
    'use strict';
    if (!window.Shopware || !Shopware.Component || !Shopware.Module) { return; }

    var BASE = '/_action/social-post';
    var VERSION = '1.0.1';

    function http() { return Shopware.Application.getContainer('init').httpClient; }
    function headers() {
        return {
            Accept: 'application/json',
            Authorization: 'Bearer ' + Shopware.Service('loginService').getToken(),
            'Content-Type': 'application/json'
        };
    }
    function parseUtc(s) { return new Date(String(s).replace(' ', 'T') + 'Z'); }
    function dt(s) {
        if (!s) { return ''; }
        var d = parseUtc(s);
        return isNaN(d) ? String(s) : d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function toLocalInput(s) {
        if (!s) { return ''; }
        var d = parseUtc(s);
        if (isNaN(d)) { return ''; }
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }
    function fromLocalInput(v) {
        if (!v) { return null; }
        var d = new Date(v);
        return isNaN(d) ? null : d.toISOString();
    }

    var STATUS = {
        draft: 'Entwurf', scheduled: 'Geplant', sending: 'Wird gesendet', sent: 'Gesendet',
        partial: 'Teilweise gesendet', failed: 'Fehlgeschlagen', tested: 'Testlauf', skipped: 'Übersprungen'
    };
    var FILTERS = [
        ['all', 'Alle'], ['draft', 'Entwürfe'], ['scheduled', 'Geplant'], ['sent', 'Gesendet'],
        ['partial', 'Teilweise'], ['failed', 'Fehlgeschlagen'], ['tested', 'Testlauf']
    ];

    var TEMPLATE = `
<sw-page class="social-post-index">
  <template #smart-bar-header><h2>Social Media</h2></template>
  <template #smart-bar-actions>
    <button class="spa-btn" type="button" v-if="counts.scheduled > 0" @click="runDue">Fällige jetzt senden</button>
    <button class="spa-btn spa-btn--primary" type="button" @click="newPost">Neuer Beitrag</button>
  </template>
  <template #content>
    <sw-card-view>
      <div class="spa-tabs" role="tablist">
        <button type="button" role="tab" :class="{ 'is-on': tab === 'posts' }" @click="tab = 'posts'">Beiträge</button>
        <button type="button" role="tab" :class="{ 'is-on': tab === 'settings' }" @click="openSettings">Einstellungen</button>
        <button type="button" role="tab" :class="{ 'is-on': tab === 'help' }" @click="openHelp">Anleitung</button>
      </div>

      <div class="spa-banner spa-banner--test" v-if="dryRun">
        <strong>Testmodus ist an.</strong> Beiträge werden nur durchgespielt und nicht veröffentlicht. Zum Veröffentlichen den Testmodus unter <a href="#" @click.prevent="openSettings">Einstellungen</a> ausschalten.
      </div>

      <!-- Beiträge -->
      <template v-if="tab === 'posts'">
        <div class="spa-filters">
          <button type="button" v-for="f in filters" :key="f[0]" :class="{ 'is-on': filter === f[0] }" @click="setFilter(f[0])">
            {{ f[1] }} <span class="spa-n" v-if="f[0] !== 'all'">{{ counts[f[0]] || 0 }}</span>
          </button>
        </div>
        <sw-card title="Beiträge" position-identifier="spa-list">
          <table class="spa-table" v-if="posts.length">
            <thead><tr><th>Beitrag</th><th>Netzwerke</th><th>Status</th><th>Zeit</th></tr></thead>
            <tbody>
              <tr v-for="p in posts" :key="p.id" class="spa-click" tabindex="0" @click="openPost(p.id)" @keyup.enter="openPost(p.id)">
                <td>
                  <strong>{{ p.title || '(ohne Titel)' }}</strong>
                  <span class="spa-badge spa-badge--auto" v-if="p.origin === 'auto'">automatisch</span><br>
                  <small>{{ excerpt(p.text) }}</small>
                </td>
                <td>
                  <span v-for="k in p.networks" :key="k" class="spa-badge" :class="netClass(p, k)" :title="netTitle(p, k)">{{ netLabel(k) }}</span>
                  <small v-if="!p.networks.length">keine gewählt</small>
                </td>
                <td><span class="spa-badge" :class="'spa-st-' + p.status">{{ statusLabel(p.status) }}</span></td>
                <td>{{ dt(p.sentAt || p.scheduledAt || p.createdAt) }}</td>
              </tr>
            </tbody>
          </table>
          <div class="spa-empty" v-else-if="!loading">
            <p><strong>Noch keine Beiträge.</strong></p>
            <p>Mit „Neuer Beitrag“ einen Produkt- oder Blogbeitrag vorbereiten. Unter „Einstellungen“ verbindest du zuerst ein Netzwerk. Mit dem Testmodus kannst du alles gefahrlos ausprobieren.</p>
          </div>
          <p class="spa-hint" v-else>Lade …</p>
        </sw-card>
      </template>

      <div class="spa-settings" v-else-if="tab === 'settings'">
        <template v-if="cfg">
        <sw-card title="Allgemein" position-identifier="spa-general">
          <label class="spa-check"><input type="checkbox" v-model="cfg.dryRun"> Testmodus (es wird nichts veröffentlicht)</label>
          <p class="spa-hint">Im Testmodus laufen Vorschau, Planung und Ablauf wie gewohnt, nur das Senden an die Netzwerke wird simuliert. Erst nach dem Ausschalten geht ein Beitrag wirklich online.</p>
          <label class="spa-check"><input type="checkbox" v-model="cfg.utm"> Links mit Kampagnen-Parametern versehen (utm_source=Netzwerk)</label>
          <p class="spa-hint">So zeigt dir deine Statistik, wie viele Besucher von welchem Netzwerk kommen.</p>
          <label class="spa-field">Standard-Hashtags
            <input class="spa-input" type="text" v-model="cfg.hashtags" placeholder="#beispiel #shop">
            <small>Wird in die Textvorlagen eingesetzt (Platzhalter {hashtags}).</small>
          </label>
          <label class="spa-field">Standard-Bild (Adresse)
            <input class="spa-input" type="url" v-model="cfg.defaultImage" placeholder="https://…/bild.jpg">
            <small>Wird verwendet, wenn eine Quelle kein Bild hat. Instagram und Pinterest brauchen immer ein Bild; Instagram nur JPEG.</small>
          </label>
        </sw-card>

        <sw-card v-for="n in cfg.networks" :key="n.key" :title="n.label" :position-identifier="'spa-net-' + n.key">
          <label class="spa-check"><input type="checkbox" v-model="n.enabled"> {{ n.label }} verwenden</label>
          <div class="spa-grid" v-if="n.enabled">
            <label class="spa-field" v-for="f in n.fields" :key="f.key">{{ f.label }}
              <input class="spa-input" :type="f.secret ? 'password' : 'text'" v-model="n.values[f.key]" :placeholder="f.secret && n.has[f.key] ? 'gespeichert, leer lassen zum Behalten' : f.placeholder" autocomplete="off">
              <small v-if="f.hint">{{ f.hint }}</small>
            </label>
          </div>
          <div class="spa-row" v-if="n.enabled">
            <button type="button" class="spa-btn" :disabled="busy" @click="testNet(n)">Speichern und Verbindung prüfen</button>
            <span class="spa-test" :class="testRes[n.key] && testRes[n.key].ok ? 'is-ok' : 'is-bad'" v-if="testRes[n.key]">{{ testRes[n.key].message }}</span>
          </div>
          <details class="spa-details"><summary>Wo bekomme ich die Zugangsdaten?</summary><p>{{ n.help }}</p></details>
        </sw-card>

        <sw-card title="Automatik" position-identifier="spa-auto">
          <p class="spa-hint">Wird ein neues Produkt oder ein neuer Blogartikel aktiv geschaltet, kann das Plugin automatisch einen Beitrag anlegen. „Entwurf“ legt nur einen Entwurf an, den du prüfst und sendest. „Planen“ sendet nach der Wartezeit von selbst.</p>
          <div class="spa-grid">
            <label class="spa-field">Neue Blogartikel
              <select class="spa-input" v-model="cfg.autoLanding">
                <option value="off">Aus</option><option value="draft">Entwurf anlegen</option><option value="send">Planen und automatisch senden</option>
              </select>
            </label>
            <label class="spa-field">Neue Produkte
              <select class="spa-input" v-model="cfg.autoProduct">
                <option value="off">Aus</option><option value="draft">Entwurf anlegen</option><option value="send">Planen und automatisch senden</option>
              </select>
            </label>
            <label class="spa-field">Wartezeit bis zum Senden (Minuten)
              <input class="spa-input" type="number" min="0" step="5" v-model.number="cfg.autoDelay">
              <small>Zeit zum Prüfen oder Löschen. 15 oder mehr, damit die Seite sicher erreichbar ist.</small>
            </label>
            <label class="spa-field">Höchstens automatisch pro Tag
              <input class="spa-input" type="number" min="1" max="50" v-model.number="cfg.autoMaxPerDay">
              <small>Weitere warten auf den nächsten Tag.</small>
            </label>
            <label class="spa-field">Nur Quellen jünger als (Tage)
              <input class="spa-input" type="number" min="1" v-model.number="cfg.autoMaxAgeDays">
              <small>Schützt davor, dass nach einem Import ältere Produkte gepostet werden.</small>
            </label>
            <label class="spa-field">Blog nur bei Adresse mit Anfang
              <input class="spa-input" type="text" v-model="cfg.landingPrefix" placeholder="z. B. blog-">
              <small>Leer = alle Landingpages. Sonst nur Seiten, deren Adresse so beginnt.</small>
            </label>
          </div>
          <p class="spa-hint"><strong>Netzwerke für automatische Beiträge:</strong></p>
          <label class="spa-check" v-for="n in cfg.networks" :key="n.key"><input type="checkbox" :value="n.key" v-model="cfg.autoNetworks" :disabled="!n.enabled || !n.configured"> {{ n.label }} <small v-if="!n.enabled || !n.configured">(erst einrichten)</small></label>
          <p class="spa-hint">Sicherung: höchstens 10 automatische Beiträge pro Stunde, und jede Quelle wird nur einmal verwendet.</p>
        </sw-card>

        <sw-card title="Textvorlagen" position-identifier="spa-tpl">
          <p class="spa-hint">Vorlage für automatische Beiträge und für „Aus Quelle füllen“. Platzhalter: {name}, {description}, {hashtags}. Der Link wird automatisch angehängt.</p>
          <label class="spa-field">Produkte<textarea class="spa-input" rows="4" v-model="cfg.templateProduct"></textarea></label>
          <label class="spa-field">Blogartikel<textarea class="spa-input" rows="4" v-model="cfg.templateLanding"></textarea></label>
        </sw-card>

        <div class="spa-savebar">
          <button type="button" class="spa-btn spa-btn--primary" :disabled="busy" @click="doSave">{{ busy ? 'Speichere …' : 'Einstellungen speichern' }}</button>
          <button type="button" class="spa-btn" :disabled="busy" @click="loadSettings">Änderungen verwerfen</button>
        </div>
        </template>
        <p class="spa-hint" v-else>Lade …</p>
      </div>

      <div class="spa-help" v-else>
        <sw-card title="So funktioniert es" position-identifier="spa-help-1">
          <ol class="spa-steps">
            <li><strong>Testmodus lassen</strong> und unter „Einstellungen“ ein Netzwerk einschalten. Mit „Speichern und Verbindung prüfen“ siehst du sofort, ob die Zugangsdaten stimmen. Dabei wird nichts veröffentlicht. Der einfachste Einstieg ist Telegram oder Mastodon.</li>
            <li><strong>Beitrag erstellen:</strong> „Neuer Beitrag“, Quelle (Produkt, Blogartikel oder eigener Text) wählen, Text anpassen, Netzwerke ankreuzen. Rechts siehst du den Text, wie ihn jedes Netzwerk bekommt (inklusive Kürzung).</li>
            <li><strong>Senden oder planen:</strong> „Jetzt senden“ oder Zeitpunkt wählen und „Planen“. Geplante Beiträge sendet das Shop-System selbst.</li>
            <li><strong>Automatik</strong> (optional): neue Produkte und Blogartikel legen selbst einen Entwurf oder geplanten Beitrag an.</li>
            <li><strong>Testmodus ausschalten</strong>, wenn alles passt. Erst dann geht etwas online.</li>
          </ol>
        </sw-card>
        <sw-card title="Zeitplan einrichten" position-identifier="spa-help-2">
          <p>Geplante Beiträge werden alle 5 Minuten geprüft. Das braucht den Aufgaben-Dienst von Shopware (Messenger-Worker) oder einen Cronjob:</p>
          <pre class="spa-pre">bin/console scheduled-task:register
bin/console social:senden</pre>
          <p class="spa-hint">Der zweite Befehl sendet alle fälligen Beiträge sofort und eignet sich für einen Cronjob (alle 5 Minuten). Mit „Fällige jetzt senden“ oben geht es auch per Klick.</p>
        </sw-card>
        <sw-card title="Wichtig zu wissen" position-identifier="spa-help-3">
          <ul class="spa-steps">
            <li>Zugangsdaten liegen in der Systemkonfiguration des Shops und werden im Admin nie wieder angezeigt. Nur Administratoren mit dem Recht „Systemkonfiguration ändern“ dürfen Beiträge senden und einstellen.</li>
            <li>Instagram und Pinterest brauchen ein Bild, Instagram nur JPEG und eine öffentliche Adresse. Links in Instagram-Texten sind nicht anklickbar.</li>
            <li>Die Netzwerke legen ihre Bedingungen fest: Entwicklerzugang, Prüfungen, Kosten und Grenzen können sich ändern. Bitte beim jeweiligen Anbieter prüfen.</li>
            <li>Veröffentlichte Beiträge löscht das Plugin nicht, das geht nur im Netzwerk selbst.</li>
          </ul>
        </sw-card>
        <sw-card title="Über dieses Plugin" position-identifier="spa-help-4">
          <div class="spa-credits">
            <div><span>Programmierung</span><strong>Markus Czarnetzki</strong></div>
            <div><span>Webseite</span><strong><a href="https://macdata.de" target="_blank" rel="noopener noreferrer">macdata.de</a></strong></div>
            <div><span>Version</span><strong>${VERSION} · MIT-Lizenz · Shopware 6.7</strong></div>
          </div>
          <p class="spa-copy">© 1999 macdata.de · Plugin © 2026 Markus Czarnetzki</p>
        </sw-card>
      </div>
    </sw-card-view>

    <!-- Editor -->
    <div class="spa-overlay" v-if="form" @click.self="closeEditor">
      <aside class="spa-drawer" role="dialog" aria-modal="true" aria-label="Beitrag bearbeiten">
        <header class="spa-dhead">
          <div>
            <h3>{{ form.id ? 'Beitrag' : 'Neuer Beitrag' }}</h3>
            <span class="spa-badge" :class="'spa-st-' + form.status" v-if="form.id">{{ statusLabel(form.status) }}</span>
            <span class="spa-badge spa-badge--auto" v-if="form.origin === 'auto'">automatisch angelegt</span>
          </div>
          <button class="spa-x" type="button" @click="closeEditor" aria-label="Schließen">×</button>
        </header>

        <p class="spa-error" v-if="formError">{{ formError }}</p>

        <section class="spa-block">
          <h4>Quelle</h4>
          <div class="spa-seg">
            <button type="button" :class="{ 'is-on': form.sourceType === 'custom' }" :disabled="locked" @click="setSourceType('custom')">Eigener Text</button>
            <button type="button" :class="{ 'is-on': form.sourceType === 'product' }" :disabled="locked" @click="setSourceType('product')">Produkt</button>
            <button type="button" :class="{ 'is-on': form.sourceType === 'landing_page' }" :disabled="locked" @click="setSourceType('landing_page')">Blogartikel</button>
          </div>
          <div v-if="form.sourceType !== 'custom' && !locked">
            <p class="spa-picked" v-if="form.sourceId">Gewählt: <strong>{{ form.sourceLabel }}</strong>
              <button type="button" class="spa-btn spa-btn--small" @click="loadFromSource(true)">Text aus Quelle neu füllen</button></p>
            <input class="spa-input spa-full" type="search" v-model="sourceQuery" @input="onSourceSearch" :placeholder="form.sourceType === 'product' ? 'Produkt suchen: Name oder Artikelnummer' : 'Blogartikel suchen: Name'">
            <ul class="spa-results" v-if="sourceResults.length">
              <li v-for="r in sourceResults" :key="r.id"><button type="button" @click="pickSource(r)">{{ r.label }} <span class="spa-badge" v-if="!r.active">inaktiv</span></button></li>
            </ul>
          </div>
        </section>

        <section class="spa-block">
          <h4>Inhalt</h4>
          <label class="spa-field">Titel <small>(intern, bei Pinterest der Pin-Titel)</small>
            <input class="spa-input" type="text" v-model="form.title" maxlength="255" :disabled="locked">
          </label>
          <label class="spa-field">Text
            <textarea class="spa-input" rows="8" v-model="form.text" maxlength="5000" :disabled="locked" placeholder="Der Beitragstext. Der Link wird automatisch angehängt."></textarea>
          </label>
          <label class="spa-field">Link <small>(leer = Adresse der gewählten Quelle)</small>
            <input class="spa-input" type="url" v-model="form.link" placeholder="https://…" :disabled="locked">
          </label>
          <label class="spa-field">Bild (Adresse)
            <input class="spa-input" type="url" v-model="form.imageUrl" placeholder="https://…/bild.jpg" :disabled="locked">
          </label>
          <img class="spa-thumb" v-if="form.imageUrl" :src="form.imageUrl" alt="Bildvorschau">
        </section>

        <section class="spa-block">
          <h4>Netzwerke</h4>
          <p class="spa-hint" v-if="!readyNets.length">Noch kein Netzwerk eingerichtet. <a href="#" @click.prevent="closeEditor(); openSettings()">Zu den Einstellungen</a></p>
          <label class="spa-check" v-for="n in networks" :key="n.key">
            <input type="checkbox" :value="n.key" v-model="form.networks" :disabled="locked || !n.enabled || !n.configured">
            {{ n.label }} <small v-if="!n.enabled || !n.configured">(nicht eingerichtet)</small>
          </label>
        </section>

        <section class="spa-block" v-if="previews.length">
          <h4>Vorschau je Netzwerk</h4>
          <div class="spa-prev" v-for="p in previews" :key="p.key">
            <div class="spa-prev-head"><strong>{{ p.label }}</strong>
              <span class="spa-count" :class="{ 'is-over': p.length > p.limit }">{{ p.length }} / {{ p.limit }}</span>
              <span class="spa-badge spa-badge--warn" v-if="p.cut">wird gekürzt</span></div>
            <pre class="spa-prev-text">{{ p.text }}</pre>
            <p class="spa-warn" v-for="w in p.warnings" :key="w">⚠ {{ w }}</p>
          </div>
        </section>

        <section class="spa-block" v-if="resultKeys.length">
          <h4>Ergebnis</h4>
          <div class="spa-res" v-for="k in resultKeys" :key="k">
            <strong>{{ netLabel(k) }}</strong>
            <span class="spa-badge" :class="resClass(form.results[k])">{{ resLabel(form.results[k]) }}</span>
            <small>{{ dt(form.results[k].at) }}</small>
            <a v-if="form.results[k].url" :href="form.results[k].url" target="_blank" rel="noopener noreferrer">Beitrag ansehen</a>
            <p class="spa-error" v-if="form.results[k].error">{{ form.results[k].error }}</p>
          </div>
          <p class="spa-hint" v-if="form.results._note">{{ form.results._note.error }}</p>
        </section>

        <section class="spa-block" v-if="!locked">
          <h4>Zeitplan</h4>
          <label class="spa-field">Senden am
            <input class="spa-input" type="datetime-local" v-model="form.scheduledLocal">
            <small>Leer lassen, wenn du ihn gleich oder später von Hand sendest.</small>
          </label>
        </section>

        <footer class="spa-foot">
          <template v-if="!locked">
            <button class="spa-btn spa-btn--primary" type="button" :disabled="busy || !canSend" @click="sendNow">{{ dryRun ? 'Testlauf senden' : 'Jetzt senden' }}</button>
            <button class="spa-btn" type="button" :disabled="busy || !form.scheduledLocal || !canSend" @click="save('schedule')">Planen</button>
            <button class="spa-btn" type="button" :disabled="busy" @click="save('draft')">Als Entwurf speichern</button>
          </template>
          <button class="spa-btn" type="button" v-if="form.id" :disabled="busy" @click="duplicate">Duplizieren</button>
          <button class="spa-btn spa-btn--danger" type="button" v-if="form.id && form.status !== 'sending'" :disabled="busy" @click="remove">Löschen</button>
        </footer>
      </aside>
    </div>
  </template>
</sw-page>`;

    var STYLE = `
.spa-btn{display:inline-flex;align-items:center;gap:6px;border:1px solid #b6c3d1;background:#fff;color:#1b2a3a;border-radius:6px;padding:8px 14px;min-height:38px;font:inherit;font-weight:600;cursor:pointer}
.spa-btn:hover:not(:disabled){background:#f3f6fb;border-color:#1d4ed8}
.spa-btn:disabled{opacity:.5;cursor:not-allowed}
.spa-btn--primary{background:#1d4ed8;border-color:#1d4ed8;color:#fff}.spa-btn--primary:hover:not(:disabled){background:#1e3a8a;border-color:#1e3a8a;color:#fff}
.spa-btn--danger{color:#b3261e;border-color:#e3b3af;margin-left:auto}.spa-btn--danger:hover:not(:disabled){background:#fde7e5;border-color:#b3261e}
.spa-btn--small{padding:4px 10px;min-height:30px;font-size:13px}
.spa-tabs{display:flex;gap:4px;margin-bottom:14px;border-bottom:2px solid #d9e2ee}
.spa-tabs button{border:0;background:none;padding:10px 18px;font:inherit;font-weight:600;color:#5b6b7b;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-2px}
.spa-tabs button.is-on{color:#1d4ed8;border-bottom-color:#1d4ed8}
.spa-banner{border-radius:8px;padding:10px 14px;margin-bottom:14px;line-height:1.45}
.spa-banner--test{background:#fff7e0;border:1px solid #f0d98a;color:#5c4400}.spa-banner a{color:#1d4ed8}
.spa-filters{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.spa-filters button{border:1px solid #c5d0dc;background:#fff;border-radius:999px;padding:6px 14px;font:inherit;cursor:pointer}
.spa-filters button.is-on{background:#1d4ed8;border-color:#1d4ed8;color:#fff}
.spa-n{display:inline-block;min-width:20px;text-align:center;font-size:12px;background:rgba(0,0,0,.08);border-radius:999px;padding:0 6px;margin-left:4px}
.spa-table{width:100%;border-collapse:collapse}
.spa-table th{text-align:left;font-size:12px;color:#5b6b7b;padding:8px;border-bottom:2px solid #d9e2ee;white-space:nowrap}
.spa-table td{padding:10px 8px;border-bottom:1px solid #e6edf6;vertical-align:top}
.spa-click{cursor:pointer}.spa-click:hover,.spa-click:focus{background:#f3f6fb;outline:none}
.spa-badge{display:inline-block;font-size:12px;font-weight:600;padding:2px 9px;border-radius:999px;background:#eef2f6;color:#5b6b7b;margin:2px 4px 2px 0}
.spa-badge--ok,.spa-st-sent{background:#dcfce7;color:#166534}
.spa-badge--bad,.spa-st-failed{background:#fde7e5;color:#b3261e}
.spa-st-partial,.spa-badge--warn{background:#fff1d6;color:#8a5a00}
.spa-st-scheduled{background:#e5edff;color:#1d4ed8}
.spa-st-tested,.spa-badge--dry{background:#efe9fb;color:#5b3aa8}
.spa-st-sending{background:#e5edff;color:#1d4ed8}
.spa-badge--auto{background:#e8f4f1;color:#0f6b5a;margin-left:8px}
.spa-empty,.spa-hint{color:#5b6b7b;line-height:1.5}.spa-empty{text-align:center;padding:18px 0}
.spa-settings .spa-field,.spa-drawer .spa-field{display:flex;flex-direction:column;gap:4px;margin:10px 0;font-weight:600;color:#1f2d3d}
.spa-field small{font-weight:400;color:#5b6b7b}
.spa-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:4px 18px}
.spa-input{padding:8px 10px;border:1px solid #c5d0dc;border-radius:6px;background:#fff;min-height:38px;font:inherit;width:100%;box-sizing:border-box}
textarea.spa-input{resize:vertical;line-height:1.45}
.spa-check{display:flex;gap:8px;align-items:center;margin:8px 0;font-weight:600;color:#1f2d3d}
.spa-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin:8px 0}
.spa-test{font-size:13px}.spa-test.is-ok{color:#166534}.spa-test.is-bad{color:#b3261e}
.spa-details{margin-top:10px;color:#1f2d3d}.spa-details summary{cursor:pointer;color:#1d4ed8;font-weight:600}.spa-details p{line-height:1.5}
.spa-settings .sw-card{margin-bottom:16px}
.spa-savebar{position:sticky;bottom:0;display:flex;gap:10px;padding:12px 0;background:linear-gradient(transparent,#f0f2f5 30%)}
.spa-steps{line-height:1.6;color:#1f2d3d;padding-left:20px}.spa-steps li{margin:6px 0}
.spa-pre{background:#f3f6fb;padding:10px;border-radius:6px;white-space:pre-wrap}
.spa-credits{display:grid;gap:8px;max-width:520px}.spa-credits div{background:#f3f6fb;border-radius:8px;padding:10px 14px;display:flex;flex-direction:column}
.spa-credits span{font-size:12px;color:#5b6b7b;text-transform:uppercase;letter-spacing:.04em}.spa-credits strong{color:#1e3a8a}.spa-credits a{color:#1d4ed8}
.spa-copy{color:#5b6b7b;font-size:13px;margin:12px 0 0}
.spa-overlay{position:fixed;inset:0;background:rgba(4,30,80,.35);z-index:1000;display:flex;justify-content:flex-end}
.spa-drawer{width:min(640px,100%);height:100%;background:#fff;box-shadow:-8px 0 30px rgba(0,0,0,.2);overflow-y:auto;padding:20px 22px 0}
.spa-dhead{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}
.spa-dhead h3{margin:0 8px 4px 0;font-size:20px;color:#1e3a8a;display:inline-block}
.spa-x{border:0;background:none;font-size:30px;line-height:1;cursor:pointer;color:#5b6b7b;min-width:40px;min-height:40px}
.spa-block{border-top:1px solid #e6edf6;padding:6px 0 10px;margin-top:10px}.spa-block h4{margin:8px 0 6px;font-size:14px;color:#1e3a8a}
.spa-seg{display:flex;gap:0;margin-bottom:8px}
.spa-seg button{flex:1;border:1px solid #c5d0dc;background:#fff;padding:8px 6px;font:inherit;font-weight:600;cursor:pointer;color:#1b2a3a}
.spa-seg button:first-child{border-radius:6px 0 0 6px}.spa-seg button:last-child{border-radius:0 6px 6px 0}.spa-seg button+button{border-left:0}
.spa-seg button.is-on{background:#1d4ed8;border-color:#1d4ed8;color:#fff}.spa-seg button:disabled{opacity:.6;cursor:not-allowed}
.spa-picked{margin:6px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.spa-full{width:100%}
.spa-results{list-style:none;margin:6px 0 0;padding:0;border:1px solid #d9e2ee;border-radius:6px;max-height:220px;overflow-y:auto}
.spa-results button{display:block;width:100%;text-align:left;border:0;background:#fff;padding:8px 12px;font:inherit;cursor:pointer;border-bottom:1px solid #eef2f6}.spa-results button:hover{background:#f3f6fb}
.spa-thumb{max-width:180px;max-height:140px;border-radius:6px;border:1px solid #d9e2ee;margin-top:4px;object-fit:contain}
.spa-prev{background:#f6f8fb;border-radius:8px;padding:10px 12px;margin:8px 0}
.spa-prev-head{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.spa-count{font-size:12px;color:#5b6b7b}.spa-count.is-over{color:#b3261e;font-weight:700}
.spa-prev-text{white-space:pre-wrap;font:inherit;margin:6px 0 0;color:#1f2d3d;max-height:200px;overflow:auto}
.spa-warn{color:#8a5a00;font-size:13px;margin:4px 0 0}
.spa-error{color:#b3261e;margin:6px 0}
.spa-res{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:6px 0;border-bottom:1px solid #eef2f6}.spa-res .spa-error{flex-basis:100%;margin:0}
.spa-foot{position:sticky;bottom:0;display:flex;gap:8px;flex-wrap:wrap;padding:12px 0;background:#fff;border-top:1px solid #e6edf6;margin-top:12px}
@media (max-width:700px){.spa-seg button{font-size:13px;padding:8px 2px}}
`;

    Shopware.Component.register('social-post-index', {
        template: TEMPLATE,
        mixins: [Shopware.Mixin.getByName('notification')],
        data: function () {
            return {
                tab: 'posts', loading: false, busy: false, filter: 'all', filters: FILTERS,
                posts: [], counts: {}, networks: [], dryRun: true,
                form: null, formError: '', previews: [], sourceQuery: '', sourceResults: [],
                cfg: null, testRes: {}, timers: {}
            };
        },
        computed: {
            readyNets: function () { return this.networks.filter(function (n) { return n.enabled && n.configured; }); },
            locked: function () { return !!this.form && (this.form.status === 'sent' || this.form.status === 'sending'); },
            canSend: function () {
                return !!this.form && this.form.text.trim().length > 0 && this.form.networks.length > 0;
            },
            resultKeys: function () {
                if (!this.form || !this.form.results) { return []; }
                return Object.keys(this.form.results).filter(function (k) { return k.charAt(0) !== '_'; });
            }
        },
        watch: {
            form: {
                deep: true,
                handler: function () { this.schedulePreview(); }
            }
        },
        created: function () {
            this.addStyle();
            this.loadOverview();
            this._onKey = function (e) { if (e.key === 'Escape' && this.form) { this.closeEditor(); } }.bind(this);
            document.addEventListener('keydown', this._onKey);
        },
        beforeUnmount: function () { document.removeEventListener('keydown', this._onKey); },
        methods: {
            dt: dt,
            addStyle: function () {
                if (document.getElementById('spa-style')) { return; }
                var s = document.createElement('style');
                s.id = 'spa-style';
                s.textContent = STYLE;
                document.head.appendChild(s);
            },
            fail: function (e) {
                var m = e && e.response && e.response.data && e.response.data.error ? e.response.data.error : 'Aktion fehlgeschlagen.';
                this.createNotificationError({ message: m });
                return m;
            },
            statusLabel: function (s) { return STATUS[s] || s; },
            excerpt: function (t) { t = String(t || '').replace(/\s+/g, ' ').trim(); return t.length > 110 ? t.slice(0, 107) + '…' : t; },
            netLabel: function (key) {
                for (var i = 0; i < this.networks.length; i++) { if (this.networks[i].key === key) { return this.networks[i].label.replace(/ \(.*\)$/, ''); } }
                return key;
            },
            netClass: function (p, k) {
                var r = p.results && p.results[k];
                if (!r) { return ''; }
                return r.status === 'ok' ? 'spa-badge--ok' : (r.status === 'dry' ? 'spa-badge--dry' : 'spa-badge--bad');
            },
            netTitle: function (p, k) {
                var r = p.results && p.results[k];
                if (!r) { return 'noch nicht gesendet'; }
                return r.status === 'ok' ? 'gesendet' : (r.status === 'dry' ? 'Testlauf' : (r.error || 'fehlgeschlagen'));
            },
            resClass: function (r) { return r.status === 'ok' ? 'spa-badge--ok' : (r.status === 'dry' ? 'spa-badge--dry' : 'spa-badge--bad'); },
            resLabel: function (r) { return r.status === 'ok' ? 'gesendet' : (r.status === 'dry' ? 'Testlauf (nichts veröffentlicht)' : 'fehlgeschlagen'); },

            /* Übersicht */
            loadOverview: function () {
                var me = this;
                me.loading = true;
                http().get(BASE + '/overview', { headers: headers(), params: { status: me.filter } })
                    .then(function (r) {
                        me.posts = r.data.posts; me.counts = r.data.counts; me.networks = r.data.networks; me.dryRun = r.data.dryRun;
                    })
                    .catch(function (e) { me.fail(e); })
                    .then(function () { me.loading = false; });
            },
            setFilter: function (f) { this.filter = f; this.loadOverview(); },
            runDue: function () {
                var me = this;
                http().post(BASE + '/run-due', {}, { headers: headers() })
                    .then(function (r) { me.createNotificationSuccess({ message: r.data.sent + ' fällige Beiträge bearbeitet.' }); me.loadOverview(); })
                    .catch(function (e) { me.fail(e); });
            },

            /* Editor */
            blank: function () {
                return {
                    id: null, sourceType: 'custom', sourceId: null, sourceLabel: '', title: '', text: '', link: '', imageUrl: '',
                    networks: this.readyNets.map(function (n) { return n.key; }), scheduledLocal: '', status: 'draft', origin: 'manual', results: {}
                };
            },
            newPost: function () { this.formError = ''; this.previews = []; this.sourceQuery = ''; this.sourceResults = []; this.form = this.blank(); },
            openPost: function (id) {
                var me = this;
                http().get(BASE + '/post/' + id, { headers: headers() })
                    .then(function (r) {
                        var p = r.data;
                        me.formError = ''; me.previews = []; me.sourceQuery = ''; me.sourceResults = [];
                        me.form = {
                            id: p.id, sourceType: p.sourceType, sourceId: p.sourceId, sourceLabel: p.title, title: p.title, text: p.text,
                            link: p.link || '', imageUrl: p.imageUrl || '', networks: p.networks, scheduledLocal: toLocalInput(p.scheduledAt),
                            status: p.status, origin: p.origin, results: p.results || {}
                        };
                    })
                    .catch(function (e) { me.fail(e); });
            },
            closeEditor: function () { this.form = null; this.previews = []; this.loadOverview(); },
            setSourceType: function (t) {
                this.form.sourceType = t;
                if (t === 'custom') { this.form.sourceId = null; this.form.sourceLabel = ''; }
                this.sourceQuery = ''; this.sourceResults = [];
                if (t !== 'custom') { this.searchSource(); }
            },
            onSourceSearch: function () {
                var me = this;
                clearTimeout(me.timers.src);
                me.timers.src = setTimeout(function () { me.searchSource(); }, 300);
            },
            searchSource: function () {
                var me = this;
                http().get(BASE + '/search', { headers: headers(), params: { type: me.form.sourceType, q: me.sourceQuery } })
                    .then(function (r) { me.sourceResults = r.data.items; })
                    .catch(function (e) { me.fail(e); });
            },
            pickSource: function (item) {
                this.form.sourceId = item.id;
                this.form.sourceLabel = item.label;
                this.sourceResults = [];
                this.sourceQuery = '';
                this.loadFromSource(false);
            },
            loadFromSource: function (force) {
                var me = this;
                if (force && me.form.text.trim() && !window.confirm('Text, Titel und Bild werden aus der Quelle neu gefüllt. Deine Änderungen gehen verloren. Weiter?')) { return; }
                http().get(BASE + '/source', { headers: headers(), params: { type: me.form.sourceType, id: me.form.sourceId } })
                    .then(function (r) {
                        var s = r.data;
                        me.form.title = s.title;
                        me.form.text = s.text;
                        me.form.imageUrl = s.imageUrl || '';
                        me.form.link = '';
                        if (!s.active) { me.createNotificationWarning({ message: 'Die Quelle ist noch nicht aktiv, der Link führt erst nach dem Aktivieren zur Seite.' }); }
                    })
                    .catch(function (e) { me.fail(e); });
            },
            payload: function (action) {
                var f = this.form;
                return {
                    sourceType: f.sourceType, sourceId: f.sourceId, title: f.title, text: f.text, link: f.link, imageUrl: f.imageUrl,
                    networks: f.networks, scheduledAt: fromLocalInput(f.scheduledLocal), action: action
                };
            },
            schedulePreview: function () {
                var me = this;
                clearTimeout(me.timers.prev);
                if (!me.form || !me.form.networks.length) { me.previews = []; return; }
                me.timers.prev = setTimeout(function () {
                    if (!me.form) { return; }
                    http().post(BASE + '/preview', me.payload('draft'), { headers: headers() })
                        .then(function (r) { me.previews = r.data.items || []; })
                        .catch(function () { me.previews = []; });
                }, 450);
            },
            persist: function (action) {
                var me = this, url = me.form.id ? BASE + '/post/' + me.form.id : BASE + '/post';
                return http().post(url, me.payload(action), { headers: headers() }).then(function (r) {
                    var p = r.data;
                    me.form.id = p.id; me.form.status = p.status; me.form.results = p.results || {};
                    return p;
                });
            },
            save: function (action) {
                var me = this;
                me.busy = true; me.formError = '';
                me.persist(action)
                    .then(function () {
                        me.createNotificationSuccess({ message: action === 'schedule' ? 'Beitrag geplant.' : 'Entwurf gespeichert.' });
                        me.closeEditor();
                    })
                    .catch(function (e) { me.formError = me.fail(e); })
                    .then(function () { me.busy = false; });
            },
            sendNow: function () {
                var me = this;
                var names = me.form.networks.map(function (k) { return me.netLabel(k); }).join(', ');
                var q = me.dryRun ? 'Testlauf an ' + names + ' (nichts wird veröffentlicht)?' : 'Jetzt veröffentlichen auf: ' + names + '?\nDas lässt sich hier nicht zurücknehmen.';
                if (!window.confirm(q)) { return; }
                me.busy = true; me.formError = '';
                me.persist('draft')
                    .then(function (p) { return http().post(BASE + '/post/' + p.id + '/send', {}, { headers: headers() }); })
                    .then(function (r) {
                        var p = r.data;
                        me.form.status = p.status; me.form.results = p.results || {};
                        var ok = p.status === 'sent' || p.status === 'tested';
                        if (ok) { me.createNotificationSuccess({ message: p.status === 'tested' ? 'Testlauf abgeschlossen. Es wurde nichts veröffentlicht.' : 'Beitrag veröffentlicht.' }); }
                        else { me.createNotificationWarning({ message: 'Nicht alles wurde gesendet. Details unter „Ergebnis“.' }); }
                        me.loadOverview();
                    })
                    .catch(function (e) { me.formError = me.fail(e); })
                    .then(function () { me.busy = false; });
            },
            duplicate: function () {
                var me = this;
                http().post(BASE + '/post/' + me.form.id + '/duplicate', {}, { headers: headers() })
                    .then(function (r) { me.createNotificationSuccess({ message: 'Dupliziert als Entwurf.' }); me.openPost(r.data.id); })
                    .catch(function (e) { me.fail(e); });
            },
            remove: function () {
                var me = this;
                if (!window.confirm('Diesen Beitrag löschen? Bereits veröffentlichte Beiträge bleiben im Netzwerk bestehen.')) { return; }
                http().delete(BASE + '/post/' + me.form.id, { headers: headers() })
                    .then(function () { me.createNotificationSuccess({ message: 'Beitrag gelöscht.' }); me.closeEditor(); })
                    .catch(function (e) { me.fail(e); });
            },

            /* Einstellungen */
            openSettings: function () { this.tab = 'settings'; if (!this.cfg) { this.loadSettings(); } },
            openHelp: function () { this.tab = 'help'; if (!this.cfg) { this.loadSettings(); } },
            loadSettings: function () {
                var me = this;
                http().get(BASE + '/settings', { headers: headers() })
                    .then(function (r) { me.cfg = r.data; })
                    .catch(function (e) { me.fail(e); });
            },
            saveSettings: function () {
                var me = this;
                me.busy = true;
                return http().post(BASE + '/settings', me.cfg, { headers: headers() })
                    .then(function (r) {
                        me.cfg = r.data;
                        me.createNotificationSuccess({ message: 'Einstellungen gespeichert.' });
                        me.loadOverview();
                    })
                    .catch(function (e) { me.fail(e); throw e; })
                    .then(function (x) { me.busy = false; return x; }, function (e) { me.busy = false; throw e; });
            },
            doSave: function () { this.saveSettings().catch(function () { /* schon gemeldet */ }); },
            testNet: function (n) {
                var me = this;
                me.saveSettings().then(function () {
                    return http().post(BASE + '/network-test/' + n.key, {}, { headers: headers() });
                }).then(function (r) {
                    me.testRes = Object.assign({}, me.testRes, (function () { var o = {}; o[n.key] = r.data; return o; })());
                }).catch(function () { /* Fehler wurden schon gemeldet */ });
            }
        }
    });

    Shopware.Module.register('social-post', {
        type: 'plugin',
        name: 'social-post',
        title: 'Social Media',
        description: 'Beiträge zu Produkten und Blogartikeln auf Social-Media-Netzwerken veröffentlichen',
        color: '#1d4ed8',
        icon: 'regular-share',
        routes: {
            index: {
                component: 'social-post-index',
                path: 'index',
                meta: { parentPath: 'sw.marketing.index' }
            }
        },
        navigation: [{
            id: 'social-post',
            label: 'Social Media',
            color: '#1d4ed8',
            icon: 'regular-share',
            path: 'social.post.index',
            parent: 'sw-marketing',
            position: 110
        }]
    });
})();
