<?php
/* WP DEPENDENCIES:
 * - is_user_logged_in(), current_user_can('edit_posts') -- admin auth check
 * - wp_redirect(), wp_login_url()                       -- auth redirect
 * - admin_url('admin-ajax.php')                         -- AJAX endpoint
 * - $wpdb->get_results()                                -- breed dropdown
 * - wp_head() / wp_footer()                             -- hook system (TRANSITIONAL)
 * Notes: Admin-only simulation tool. Sim AJAX handlers registered in functions.php
 *        as bb_sim_ajax. All DB writes use getBBDatabase() -- no $wpdb for better_bred.*.
 */

show_admin_bar(false);

if (!is_user_logged_in() || !current_user_can('edit_posts')) {
    wp_redirect(wp_login_url(get_permalink())); exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sim Runner I — BetterBred Admin</title>
<?php wp_head(); ?>
<style>
/* -- THEME SUPPRESSION -- */
body { font-family: 'Jost', sans-serif !important; }
body *, body *::before, body *::after { font-family: inherit; }
.site-header, #masthead, .page-header, .entry-header,
.breadcrumbs, .breadcrumb, .page-title-wrap,
h1.posttitle, .bb-page-title, .wp-block-post-title,
.site-breadcrumb, .page-hero { display: none !important; }
.site-content, #content, .content-area,
.entry-content, .page-content,
main, #main, .main-content { padding: 0 !important; margin: 0 !important; }
body.bb-fullpage { margin-top: 0 !important; padding-top: 0 !important; }
#wpadminbar { display: none !important; }
html { margin-top: 0 !important; }
/* -- PAGE CSS -- */

:root { --forest:#1B3A2D; --gold:#C9A84C; --cream:#F5F0E8; --sage:#7A9E7E; }
body  { background:var(--cream); }
:root { --forest:#1B3A2D; --gold:#C9A84C; --cream:#F5F0E8; --sage:#7A9E7E; }
body  { font-family:'Jost',sans-serif; background:var(--cream); }
.sim-wrap { max-width:1400px; margin:2rem auto; padding:0 2rem; }
h1  { font-family:'Cormorant Garamond',serif; color:var(--forest); font-size:2rem; margin-bottom:.25rem; }
h2  { font-family:'Jost',sans-serif; color:var(--forest); font-size:1rem; font-weight:700;
      text-transform:uppercase; letter-spacing:.1em; margin:0 0 1rem; }
.sim-card {
    background:#fff; border:1px solid #ddd; border-radius:6px;
    padding:1.8rem; margin-bottom:1.5rem;
}
.sim-card.locked { opacity:.45; pointer-events:none; }
/* Results are stale from the moment founders are reseeded until a run
   completes: the working tables have been truncated, so anything on screen
   describes the PREVIOUS replicate. Grey it out rather than let it be read
   as current. */
.sim-card.stale #results-content,
.sim-card.stale #progress-content {
    opacity: .35;
    pointer-events: none;
    filter: grayscale(100%);
}
.sim-card.stale .stale-note { display: block; }
.stale-note {
    display: none;
    font-size: .82rem;
    color: #c0392b;
    font-weight: 600;
    margin-bottom: .75rem;
}
.breed-row { display:flex; gap:1.5rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1rem; }
.breed-row label { font-weight:600; color:var(--forest); font-size:.8rem;
                   text-transform:uppercase; letter-spacing:.08em; display:block; margin-bottom:.3rem; }
.breed-row select, .breed-row input[type=number], .breed-row input[type=text] {
    padding:.45rem .7rem; font-size:.95rem; border:1px solid #ccc;
    border-radius:3px; background:#fff; }
.strategy-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; margin:1rem 0; }
.strategy-grid label {
    border:2px solid #ccc; border-radius:4px; padding:.6rem 1rem;
    text-align:center; cursor:pointer; font-weight:600; transition:all .2s; }
.strategy-grid input[type=checkbox] { display:none; }
.strategy-grid label:has(input:checked) { border-color:var(--forest); background:var(--forest); color:#fff; }
.sim-btn {
    background:var(--gold); color:var(--forest); border:none;
    padding:.7rem 2rem; font-size:1rem; font-weight:700; border-radius:4px; cursor:pointer; }
.sim-btn:disabled { opacity:.4; cursor:not-allowed; }
.sim-btn.danger { background:#c0392b; color:#fff; }
#sim-log, #founder-log {
    font-family:monospace; font-size:.82rem; background:#111; color:#7FFF7F;
    padding:1rem; border-radius:4px; max-height:500px; overflow-y:auto;
    white-space:pre-wrap; margin-top:1rem; display:none; }
.log-warn  { color:#FFD700; }
.log-err   { color:#ff6b6b; }
.progress-bars { margin-top:1rem; }
.pb-row { display:flex; align-items:center; gap:.75rem; margin-bottom:.5rem; font-size:.9rem; }
.pb-label { width:70px; font-weight:600; color:var(--forest); }
.pb-track { flex:1; background:#e0e0e0; border-radius:3px; height:10px; }
.pb-fill  { height:10px; border-radius:3px; background:var(--gold); transition:width .3s; }
.pb-count { width:60px; text-align:right; font-size:.8rem; color:#666; }
.status-badge {
    display:inline-block; padding:.3rem .9rem; border-radius:20px;
    font-size:.78rem; font-weight:600; letter-spacing:.07em; text-transform:uppercase;
    background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7; margin-left:1rem; }
.status-badge.pending { background:#fff8e1; color:#f57f17; border-color:#ffe082; }
#baseline-stats { font-size:.88rem; color:#555; margin-top:.75rem; display:none; }
#baseline-stats strong { color:var(--forest); }


@media (max-width: 600px) {
    .sim-wrap { padding: 0 .75rem; }
    .strategy-grid { grid-template-columns: repeat(2,1fr); }
}
</style>
</head>
<body <?php body_class('bb-fullpage'); ?>>

<div style="background:#1B3A2D;padding:.5rem 1.5rem;font-size:.82rem;">
    <a href="<?php echo home_url('/admin-hub/'); ?>" style="color:#C9A84C;text-decoration:none;font-weight:600;">&#8592; Admin Hub</a>
</div>


<?php include('/home/site/wwwroot/wp-content/themes/betterbred/bb-nav.php'); ?>

<div class="sim-wrap">
    <h1>Generational Simulation Runner</h1>
    <p style="color:#666;margin-bottom:1.5rem">Select a breed, seed founders, then run the simulation. All strategies use the same founder population.</p>

    <!-- ======================================================
         CARD 1: BREED SELECTION (shared by both sections)
         ====================================================== -->
    <div class="sim-card">
        <h2>1. Select Breed</h2>
        <div class="breed-row">
            <div>
                <label for="breed-suffix">Breed</label>
                <select id="breed-suffix">
                    <option value="">Base tables (no suffix)</option>
                    <?php
                    global $wpdb;
                    $breeds = $wpdb->get_results("SELECT id, name, sim_suffix FROM better_bred.breed WHERE sim_suffix IS NOT NULL ORDER BY name");
                    foreach ($breeds as $b) {
                        $sel = ($b->sim_suffix === 'sp') ? ' selected' : '';
                        echo '<option value="' . esc_attr($b->sim_suffix) . '" data-id="' . intval($b->id) . '"' . $sel . '>' . esc_html($b->name) . ' (_' . esc_attr($b->sim_suffix) . ')</option>';
                    }
                    ?>
                </select>
            </div>
            <div>
                <label for="breed-id">Breed ID</label>
                <input type="text" id="breed-id" value="8" style="width:80px">
            </div>
            <div>
                <label for="founders-m">Founding males</label>
                <input type="number" id="founders-m" value="100" min="10" max="500" style="width:80px">
            </div>
            <div>
                <label for="founders-f">Founding females</label>
                <input type="number" id="founders-f" value="100" min="10" max="500" style="width:80px">
            </div>
            <div>
                <label for="min-loci">Loci required</label>
                <input type="number" id="min-loci" value="33" min="8" max="33" style="width:80px">
            </div>
        </div>
    </div>

    <!-- ======================================================
         CARD 2: FOUNDER SETUP
         ====================================================== -->
    <div class="sim-card" id="card-founders">
        <h2>2. Seed Founders
            <span class="status-badge pending" id="founder-status">Not seeded</span>
        </h2>
        <p style="color:#555;font-size:.88rem">
            Seeds gen=0 from enrolled dogs in the database. Run once per breed before each replicate.<br>
            <strong style="color:#c0392b">Tables must be TRUNCATEd before re-running a new replicate.</strong>
        </p>
        <div style="display:flex;gap:1rem;margin-top:1rem;flex-wrap:wrap;align-items:center">
            <button class="sim-btn" id="btn-truncate" style="background:#c0392b;color:#fff">&#9003; Truncate Tables</button>
            <button class="sim-btn" id="btn-seed" disabled>&#9654; Seed Founders</button>
            <span id="truncate-note" style="font-size:.8rem;color:#888;font-style:italic">Truncate first, then seed.</span>
        </div>
        <div id="active-run-display" style="display:none;margin-top:.6rem;font-size:.82rem;color:#555">
            Active run: <strong id="active-run-label" style="color:var(--forest)"></strong>
            &nbsp;<button id="btn-select-run" class="sim-btn" style="font-size:.72rem;padding:.3rem .8rem;background:#4a6fa5;color:#fff">&#128270; Select / Resume Run</button>
        </div>
        <div id="select-run-panel" style="display:none;margin-top:.75rem;padding:.75rem;background:#f5f0e8;border:1px solid #ddd;border-radius:4px;font-size:.84rem">
            <strong>Available runs for this breed:</strong>
            <div id="run-list" style="margin:.5rem 0"></div>
        </div>
        <div id="baseline-stats"></div>
        <div id="truncate-log" style="font-family:monospace;font-size:.82rem;background:#111;color:#7FFF7F;padding:1rem;border-radius:4px;max-height:400px;overflow-y:auto;white-space:pre-wrap;margin-top:1rem;display:none"></div>
        <div id="founder-log">Ready.</div>
    </div>

    <!-- ======================================================
         CARD 3: SIMULATION RUNNER (locked until founders seeded)
         ====================================================== -->
    <div class="sim-card locked" id="card-runner">
        <h2>3. Run Simulation</h2>

        <div style="margin-bottom:1rem">
            <div style="display:flex;align-items:center;gap:1rem;margin-bottom:.5rem">
                <label style="font-weight:600;color:var(--forest);font-size:.8rem;text-transform:uppercase;letter-spacing:.08em">
                    Select Strategies
                </label>
                <span style="font-size:.78rem;color:#888">
                    <a href="#" id="strat-all" style="color:var(--forest);text-decoration:none;font-weight:600">All</a>
                    &nbsp;/&nbsp;
                    <a href="#" id="strat-none" style="color:var(--forest);text-decoration:none;font-weight:600">None</a>
                </span>
                <span id="strat-hint" style="font-size:.78rem;color:#c0392b;font-style:italic;display:none">Select at least one strategy.</span>
            </div>
            <div class="strategy-grid">
                <?php foreach (['OI','IR','AGR','RANDOM'] as $strat): ?>
                <label>
                    <input type="checkbox" class="strat-check" value="<?= $strat ?>" checked>
                    <?= $strat ?>
                </label>
                <?php endforeach; ?>
            </div>
            <p style="font-size:.78rem;color:#888;margin:.4rem 0 0;font-style:italic">
                Dark green = will run. Click a strategy to toggle it on/off.
                AGR is a population-level benchmark: it uses Wang GR (available in BetterBred) but requires real-time coordination across every breeder selecting in the same generation, so it is not deployable in practice. RANDOM is the null that the others must beat.
            </p>
        </div>

        <div style="margin-bottom:1.5rem;display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start">
            <div>
                <label style="font-weight:600;color:var(--forest);font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:.5rem">
                    Generations per strategy
                </label>
                <input type="number" id="gen-target" value="20" min="1" max="50"
                       style="width:80px;padding:.45rem;font-size:1rem;border:1px solid #ccc;border-radius:3px">
            </div>

            <div>
                <label style="font-weight:600;color:var(--forest);font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:.5rem">
                    Litter size
                </label>
                <input type="number" id="litter-size" value="10" min="2" max="20"
                       style="width:80px;padding:.45rem;font-size:1rem;border:1px solid #ccc;border-radius:3px">
                <div style="font-size:.72rem;color:#888;font-style:italic;margin-top:.25rem">
                    Two keepers per litter regardless.
                </div>
            </div>

            <div>
                <label style="font-weight:600;color:var(--forest);font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:.5rem">
                    Dams per sire
                </label>
                <select id="sires-per-dam"
                        style="padding:.45rem;font-size:1rem;border:1px solid #ccc;border-radius:3px">
                    <option value="1" selected>1 : 1 &mdash; every male breeds</option>
                    <option value="2">1 : 2</option>
                    <option value="4">1 : 4</option>
                    <option value="8">1 : 8</option>
                </select>
                <div style="font-size:.72rem;color:#888;font-style:italic;margin-top:.25rem">
                    Every dam breeds once. This sets how many males are used.
                </div>
            </div>

            <div>
                <label style="font-weight:600;color:var(--forest);font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;display:block;margin-bottom:.5rem">
                    Which males breed
                </label>
                <select id="sire-mode"
                        style="padding:.45rem;font-size:1rem;border:1px solid #ccc;border-radius:3px">
                    <option value="random" selected>Random draw (default)</option>
                    <option value="ordered">Ordered by strategy score</option>
                </select>
                <div id="sire-mode-note" style="font-size:.72rem;color:#888;font-style:italic;margin-top:.25rem">
                    Inert at 1:1 &mdash; no excess males, nothing to choose.
                </div>
            </div>
        </div>

        <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:center">
            <!-- Retired PHP engine. The element is kept but hidden: its click
                 handler and five other references are still wired up in the JS
                 below, and removing the element would make getElementById()
                 return null and throw, taking the whole page's script with it.
                 Hidden = gone from the UI, which is what matters. -->
            <button class="sim-btn" id="btn-start" style="display:none">&#9654; Start (PHP - retired)</button>
            <button class="sim-btn" id="btn-api-start" style="background:#1B3A2D !important;color:#fff !important;border:2px solid #C9A84C !important">&#9654; Run Simulation</button>
            <button class="sim-btn danger" id="btn-stop" style="display:none">&#9632; Stop</button>
            <button class="sim-btn" id="btn-resume-all" style="display:none;background:#2c7a4b;color:#fff">&#8635; Resume Interrupted</button>
            <button class="sim-btn" id="btn-check-resume" style="background:#4a6fa5;color:#fff;font-size:.8rem;padding:.5rem 1.2rem">&#128269; Check for Incomplete</button>
            <button class="sim-btn" id="btn-clear-progress" style="background:#888;color:#fff;font-size:.8rem;padding:.5rem 1.2rem">Clear Sim Progress</button>
        </div>
        <div id="resume-status" style="margin-top:.5rem;font-size:.82rem;color:#555;display:none"></div>

        <div class="progress-bars" id="progress-bars" style="margin-top:1.5rem"></div>
        <div id="sim-log">Seed founders first.</div>
    </div>

    <!-- ======================================================
         CARD 3b: BATCH RUNNER
         Truncate -> seed -> run all strategies -> verify, N times.
         Halts hard on the first failure and leaves the broken
         replicate in place for inspection.
         ====================================================== -->
    <div class="sim-card" id="card-batch">
        <h2>Batch Runner
            <span class="status-badge pending" id="batch-status">Idle</span>
        </h2>
        <p style="font-size:.84rem;color:#555;margin-bottom:1rem">
            Runs the full truncate &rarr; seed &rarr; simulate &rarr; verify cycle repeatedly,
            using the breed, founder, and run parameters set above and the strategy
            checkboxes in the Simulation card.
            <strong style="color:#c0392b">Stops immediately on any failure.</strong>
            Keep this tab open and set the machine not to sleep.
        </p>

        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1rem">
            <div>
                <label for="batch-size" style="display:block;font-size:.78rem;color:#555">Replicates this batch</label>
                <input type="number" id="batch-size" value="10" min="1" max="50" style="width:90px">
            </div>
            <div>
                <label for="batch-run-suffix" style="display:block;font-size:.78rem;color:#555">Pin run_suffix (optional)</label>
                <input type="text" id="batch-run-suffix" placeholder="e.g. sp_jul2026" style="width:170px">
            </div>
            <div>
                <button class="sim-btn" id="btn-batch-start" style="background:#1B3A2D !important;color:#fff !important;border:2px solid #C9A84C !important">&#9654; Run Batch</button>
                <button class="sim-btn danger" id="btn-batch-stop" style="display:none">&#9632; Stop After Current</button>
            </div>
        </div>
        <p style="font-size:.78rem;color:#888;font-style:italic;margin-bottom:1rem">
            Leave run_suffix blank on the very first batch of a new run &mdash; the seeder will
            create it from breed + month. Paste it back in for every batch after that, so a run
            spanning a month boundary cannot silently split into a second set of tables.
        </p>

        <div id="batch-log" style="font-family:monospace;font-size:.8rem;background:#111;color:#7FFF7F;padding:1rem;border-radius:4px;max-height:420px;overflow-y:auto;white-space:pre-wrap;display:none"></div>
    </div>

    <!-- ======================================================
         CARD 4: RESULTS
         ====================================================== -->
    <div class="sim-card" id="card-results">
        <h2>Replicate Results
            <button class="sim-btn" id="btn-load-results" style="font-size:.75rem;padding:.4rem 1rem;margin-left:1rem">&#8635; Load Results</button>
            <button class="sim-btn" id="btn-load-progress" style="font-size:.75rem;padding:.4rem 1rem;margin-left:.5rem;background:#2c7a4b;color:#fff">&#8635; Load Progress</button>
        </h2>
        <p style="font-size:.84rem;color:#555;margin-bottom:1rem">
            Recorded automatically when a run reaches its final generation. Shows gen=0 → final gen for each replicate.
            Final-generation values are stored in <code>genN_*</code> columns and <code>final_gen</code> records the
            generation each replicate actually ran to.
        </p>
        <div class="stale-note">Founders reseeded &mdash; these results are from the previous replicate. Rerun and reload to refresh.</div>
        <div id="results-content" style="font-size:.84rem;color:#888;font-style:italic">Click Load Results to fetch replicate summaries.</div>
        <div id="progress-content" style="margin-top:1.5rem;font-size:.84rem;color:#888;font-style:italic"></div>
    </div>
</div>

<script>
(function() {
    const ajaxUrl = '<?php echo esc_url(admin_url("admin-ajax.php")); ?>';
    let stopRequested = false;
    let foundersReady = false;
    let runSuffix     = '';   // set on seed, passed on every subsequent call
    let lastRepNum    = null; // replicate number the last successful seed claimed

    // --- Element refs
    const breedSuffixEl = document.getElementById('breed-suffix');
    const breedIdEl     = document.getElementById('breed-id');
    const foundersMEl   = document.getElementById('founders-m');
    const foundersFEl    = document.getElementById('founders-f');
    const minLociEl      = document.getElementById('min-loci');
    const btnSeed       = document.getElementById('btn-seed');
    const founderLog    = document.getElementById('founder-log');
    const founderStatus = document.getElementById('founder-status');
    const baselineStats = document.getElementById('baseline-stats');
    const cardRunner    = document.getElementById('card-runner');
    const cardResults   = document.getElementById('card-results');

    // The Results card is stale from the moment founders are (re)seeded until a
    // run completes. Between those two points the working tables have been
    // truncated, so whatever is on screen belongs to the previous replicate.
    function markResultsStale(isStale) {
        if (!cardResults) { return; }
        if (isStale) { cardResults.classList.add('stale'); }
        else { cardResults.classList.remove('stale'); }
    }
    const btnStart      = document.getElementById('btn-start');

    // Sire mode only means something when there are EXCESS males. At 1:1 every
    // male breeds, so there is nothing to choose between and the control is
    // disabled rather than left looking active.
    (function () {
        var ratioEl = document.getElementById('sires-per-dam');
        var modeEl  = document.getElementById('sire-mode');
        var noteEl  = document.getElementById('sire-mode-note');
        function syncSireMode() {
            var ratio = parseInt(ratioEl.value, 10) || 1;
            var inert = (ratio === 1);
            modeEl.disabled = inert;
            noteEl.textContent = inert
                ? 'Inert at 1:1 \u2014 no excess males, nothing to choose.'
                : 'Active \u2014 ' + ratio + ' dams per sire leaves excess males that do not breed.';
        }
        ratioEl.addEventListener('change', syncSireMode);
        syncSireMode();
    })();
    const btnStop       = document.getElementById('btn-stop');
    const simLog        = document.getElementById('sim-log');
    const pbsEl         = document.getElementById('progress-bars');


    const btnTruncate  = document.getElementById('btn-truncate');
    const truncateLog  = document.getElementById('truncate-log');
    const truncateNote = document.getElementById('truncate-note');

    // When breed changes, re-lock seed button
    const origBreedChange = breedSuffixEl.onchange;
    breedSuffixEl.addEventListener('change', function() {
        btnSeed.disabled = true;
        truncateNote.textContent = 'Truncate first, then seed.';
        truncateLog.style.display = 'none';
        truncateLog.textContent = '';
        // Clear the pinned run. Carrying a run_suffix across a breed change
        // would write the new breed's founders into the old breed's tables.
        runSuffix = '';
        const arl = document.getElementById('active-run-label');
        if (arl) { arl.textContent = ''; }
        const ard = document.getElementById('active-run-display');
        if (ard) { ard.style.display = 'none'; }
    });

    // Core truncate. Returns true on success, false on failure. No confirm()
    // and no alert() so the batch runner can call it unattended.
    async function doTruncate(breedSuffix) {
        let ok = false;
        btnTruncate.disabled = true;
        btnSeed.disabled = true;
        truncateLog.style.display = 'block';
        truncateLog.textContent = 'Truncating...';
        truncateNote.textContent = '';

        const fd = new FormData();
        fd.append('action', 'bb_sim_ajax');
        fd.append('sub_action', 'truncate_tables');
        fd.append('breed_suffix', breedSuffix);

        try {
            const resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const data = await resp.json();

            if (data.success) {
                let msg = 'Tables cleared:\n';
                if (data.dropped  && data.dropped.length)   msg += '  Dropped: '   + data.dropped.join(', ')   + '\n';
                if (data.truncated && data.truncated.length) msg += '  Truncated: ' + data.truncated.join(', ') + '\n';
                truncateLog.textContent = msg + '\nReady to seed.';
                truncateNote.textContent = 'Tables cleared. Now seed founders.';
                btnSeed.disabled = false;

                // Reset sim state
                foundersReady = false;
                founderStatus.textContent = 'Not seeded';
                founderStatus.className = 'status-badge pending';
                baselineStats.style.display = 'none';
                cardRunner.classList.add('locked');
                simLog.textContent = 'Seed founders first.';
                pbsEl.innerHTML = '';
                founderLog.textContent = '';
                founderLog.style.display = 'none';
                markResultsStale(true);
                ok = true;
            } else {
                truncateLog.textContent = 'ERRORS:\n' + (data.errors || []).join('\n');
                truncateNote.textContent = 'Truncate failed — check errors above.';
            }
        } catch(e) {
            truncateLog.textContent = 'FETCH ERROR: ' + e.message;
        }

        btnTruncate.disabled = false;
        return ok;
    }

    btnTruncate.addEventListener('click', async function() {
        if (!confirm('This will DROP all derived simulation tables and TRUNCATE alleles tables for the selected breed suffix. Continue?')) return;
        await doTruncate(breedSuffixEl.value);
    });
    // SELECT / RESUME RUN
    document.getElementById('btn-select-run').addEventListener('click', async function() {
        const panel  = document.getElementById('select-run-panel');
        const listEl = document.getElementById('run-list');
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        if (panel.style.display === 'none') return;
        listEl.textContent = 'Loading...';
        const fd = new FormData();
        fd.append('action', 'bb_sim_ajax');
        fd.append('sub_action', 'list_runs');
        fd.append('breed_suffix', breedSuffixEl.value);
        try {
            const resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success && data.runs && data.runs.length) {
                listEl.innerHTML = data.runs.map(function(r) {
                    return '<div style="margin:.3rem 0">' +
                        '<a href="#" class="run-pick" data-suffix="' + r.run_suffix + '" style="color:var(--forest);font-weight:600">' + r.run_suffix + '</a>' +
                        ' &nbsp;<span style="color:#888;font-size:.78rem">' + r.rows + ' gen rows &middot; ' + r.created + '</span></div>';
                }).join('');
                listEl.querySelectorAll('.run-pick').forEach(function(el) {
                    el.addEventListener('click', function(e) {
                        e.preventDefault();
                        runSuffix = this.dataset.suffix;
                        document.getElementById('active-run-label').textContent = runSuffix;
                        document.getElementById('active-run-display').style.display = 'block';
                        panel.style.display = 'none';
                        foundersReady = true;
                        cardRunner.classList.remove('locked');
                        simLog.textContent = 'Resumed run: ' + runSuffix + '. Select strategies and click Start.';
                        founderStatus.textContent = '\u2713 Resumed';
                        founderStatus.className = 'status-badge';
                    });
                });
            } else {
                listEl.textContent = 'No previous runs found for this breed.';
            }
        } catch(e) { listEl.textContent = 'Error: ' + e.message; }
    });

    // Auto-fill breed ID when breed changes
    breedSuffixEl.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        breedIdEl.value = selected.dataset.id || '';

        // Reset founder status if breed changes
        if (foundersReady) {
            foundersReady = false;
            founderStatus.textContent = 'Not seeded';
            founderStatus.className = 'status-badge pending';
            baselineStats.style.display = 'none';
            cardRunner.classList.add('locked');
            simLog.textContent = 'Seed founders first.';
            pbsEl.innerHTML = '';
        }
    });

    // --- FOUNDER LOG helpers
    function logFounder(msg, type) {
        founderLog.style.display = 'block';
        const line = document.createElement('span');
        line.className = type ? 'log-' + type : '';
        line.textContent = msg + '\n';
        founderLog.appendChild(line);
        founderLog.scrollTop = founderLog.scrollHeight;
    }

    // --- SIM LOG helpers
    function logSim(msg) {
        simLog.style.display = 'block';
        simLog.textContent += '\n' + msg;
        simLog.scrollTop = simLog.scrollHeight;
    }

    function updateProgressBar(strategy, gen, total) {
        let row = document.getElementById('pb-' + strategy);
        if (!row) {
            row = document.createElement('div');
            row.className = 'pb-row';
            row.id = 'pb-' + strategy;
            row.innerHTML =
                '<span class="pb-label">' + strategy + '</span>' +
                '<div class="pb-track"><div class="pb-fill" style="width:0%"></div></div>' +
                '<span class="pb-count">0/' + total + '</span>';
            pbsEl.appendChild(row);
        }
        const pct = Math.round((gen / total) * 100);
        row.querySelector('.pb-fill').style.width = pct + '%';
        row.querySelector('.pb-count').textContent = gen + '/' + total;
    }

    // =========================================================
    // SEED FOUNDERS
    // =========================================================
    // Core seed. Returns true on success, false on failure.
    // pinnedSuffix: pass a run_suffix to force the target tables. Empty string
    // lets the server derive it from breed + current month -- which silently
    // starts a NEW table set if a run crosses a month boundary.
    async function doSeed(pinnedSuffix) {
        const breedSuffix = breedSuffixEl.value;
        const breedId     = parseInt(breedIdEl.value, 10);
        const foundersM   = parseInt(foundersMEl.value, 10);
        const foundersF   = parseInt(foundersFEl.value, 10);
        const minLoci     = parseInt(minLociEl.value, 10);

        if (!breedId) { logFounder('ERROR: no breed ID.', 'err'); return false; }

        btnSeed.disabled = true;
        founderLog.textContent = '';
        founderLog.style.display = 'block';
        baselineStats.style.display = 'none';
        founderStatus.textContent = 'Seeding\u2026';
        founderStatus.className = 'status-badge pending';

        const fd = new FormData();
        fd.append('action', 'bb_sim_ajax');
        fd.append('sub_action', 'seed_founders');
        fd.append('breed_suffix', breedSuffix);
        fd.append('breed_id', breedId);
        fd.append('n_founders_m', foundersM);
        fd.append('n_founders_f', foundersF);
        fd.append('min_loci', minLoci);
        if (pinnedSuffix) { fd.append('run_suffix', pinnedSuffix); }

        try {
            const resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const data = await resp.json();

            if (data.log) {
                data.log.forEach(function(line) {
                    logFounder(line.msg, line.type || '');
                });
            }

            if (data.success) {
                founderStatus.textContent = '\u2713 Seeded';
                founderStatus.className = 'status-badge';

                baselineStats.style.display = 'block';
                baselineStats.innerHTML =
                    'Baseline &mdash; ' +
                    '<strong>He: ' + data.baseline_he + '</strong> &nbsp;&middot;&nbsp; ' +
                    '<strong>Ne: ' + data.baseline_ne + '</strong> &nbsp;&middot;&nbsp; ' +
                    '<strong>Na: ' + data.baseline_na + '</strong> &nbsp;&middot;&nbsp; ' +
                    (data.n_founders_m || data.pop_size) + ' sires + ' +
                    (data.n_founders_f || data.pop_size) + ' dams &nbsp;&middot;&nbsp; ' +
                    data.loci + ' loci';

                foundersReady = true;
                runSuffix = data.run_suffix || '';
                lastRepNum = data.replicate_num ? parseInt(data.replicate_num, 10) : null;
                markResultsStale(true);
                document.getElementById('active-run-label').textContent = runSuffix;
                document.getElementById('active-run-display').style.display = 'block';
                cardRunner.classList.remove('locked');
                simLog.textContent = 'Founders ready (' + runSuffix + '). Select strategies and click Start.';
                // btnSeed stays disabled after a successful seed.
                // Truncate Tables is the only correct path back to re-seeding.
                return true;
            } else {
                founderStatus.textContent = 'Error';
                founderStatus.className = 'status-badge pending';
                logFounder('ERROR: ' + (data.error || 'Unknown error'), 'err');
                btnSeed.disabled = false; // re-enable only on failure so user can retry
                return false;
            }
        } catch(e) {
            logFounder('FETCH ERROR: ' + e.message, 'err');
            founderStatus.textContent = 'Error';
            founderStatus.className = 'status-badge pending';
            btnSeed.disabled = false; // re-enable only on failure so user can retry
            return false;
        }
    }

    btnSeed.addEventListener('click', async function() {
        if (!parseInt(breedIdEl.value, 10)) { alert('Enter a breed ID.'); return; }
        await doSeed(runSuffix || '');
    });

    // =========================================================
    // RUN SIMULATION
    // =========================================================
    async function runOneGeneration(strategy) {
        const fd = new FormData();
        fd.append('action', 'bb_sim_ajax');
        fd.append('sub_action', 'run_generation');
        fd.append('strategy', strategy);
        fd.append('breed_suffix', breedSuffixEl.value);
        fd.append('breed_id', breedIdEl.value);
        fd.append('run_suffix', runSuffix);
        const resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        return await resp.json();
    }

    async function runStrategy(strategy, totalGens, startGen) {
        startGen = startGen || 1;
        logSim('\n=== ' + strategy + ' \u2014 running gens ' + startGen + '\u2013' + totalGens + ' ===');
        for (let g = startGen; g <= totalGens; g++) {
            if (stopRequested) { logSim('[' + strategy + '] Stopped at gen ' + (g-1)); return; }
            try {
                const result = await runOneGeneration(strategy);
                if (!result.success) {
                    logSim('[' + strategy + '] ERROR gen ' + g + ': ' + result.error);
                    return;
                }
                logSim('[' + strategy + '] Gen ' + result.gen
                    + ' \u2713  ' + result.elapsed + 's'
                    + '  He=' + result.avg_he
                    + '  Ne=' + result.avg_ne
                    + '  Na=' + result.avg_na
                    + '  Ho=' + (result.avg_ho !== undefined ? result.avg_ho : '--')
                    + '  OI=' + (result.avg_oi !== undefined ? result.avg_oi : '--')
                    + '  IR=' + (result.avg_ir !== undefined ? result.avg_ir : '--')
                    + (result.band_infr !== undefined ? '  frq:' + Math.round(result.band_infr*100) + '/' + Math.round(result.band_norm*100) + '/' + Math.round(result.band_hfreq*100) + '%' : ''));
                updateProgressBar(strategy, g, totalGens);
            } catch(e) {
                logSim('[' + strategy + '] FETCH ERROR: ' + e.message);
                return;
            }
        }
        logSim('[' + strategy + '] Complete.');
    }

    async function runStrategyToPanel(strategy, totalGens, startGen, logFn) {
        startGen = startGen || 1;
        logFn(strategy, 'Starting gens ' + startGen + '\u2013' + totalGens + '\u2026');
        for (let g = startGen; g <= totalGens; g++) {
            if (stopRequested) { logFn(strategy, 'Stopped at gen ' + (g-1)); return; }
            try {
                const result = await runOneGeneration(strategy);
                if (!result.success) {
                    logFn(strategy, 'ERROR gen ' + g + ': ' + (result.error || 'unknown'));
                    return;
                }
                logFn(strategy, 'Gen ' + result.gen
                    + ' \u2713 ' + result.elapsed + 's'
                    + '  He=' + result.avg_he
                    + '  Na=' + result.avg_na
                    + (result.avg_oi !== undefined ? '  OI=' + result.avg_oi : '')
                    + (result.avg_ir !== undefined ? '  IR=' + result.avg_ir : ''));
                updateProgressBar(strategy, g, totalGens);
            } catch(e) {
                logFn(strategy, 'FETCH ERROR: ' + e.message);
                return;
            }
        }
    }

    btnStart.addEventListener('click', async function() {
        const checked = [...document.querySelectorAll('.strat-check:checked')].map(el => el.value);
        if (checked.length === 0) {
            document.getElementById('strat-hint').style.display = 'inline';
            return;
        }
        document.getElementById('strat-hint').style.display = 'none';

        const totalGens   = parseInt(document.getElementById('gen-target').value, 10) || 20;
        const breedLabel  = breedSuffixEl.value ? ' [' + breedSuffixEl.value.toUpperCase() + ']' : ' [base]';
        stopRequested     = false;
        incompleteStrategies = [];
        document.getElementById('btn-resume-all').style.display = 'none';
        document.getElementById('resume-status').style.display = 'none';
        pbsEl.innerHTML   = '';
        btnStart.disabled  = true;
        btnStop.style.display = 'inline-block';

        // Build per-strategy log panels
        simLog.style.display = 'none';
        const stratLogContainer = document.getElementById('strat-log-container') || (function() {
            const el = document.createElement('div');
            el.id = 'strat-log-container';
            el.style.cssText = 'margin-top:1rem;display:grid;gap:.75rem';
            simLog.parentNode.insertBefore(el, simLog.nextSibling);
            return el;
        })();
        stratLogContainer.innerHTML = '';
        const stratLogEls = {};
        const stratColors = {OI:'#1B3A2D', IR:'#b07d00', RANDOM:'#555', AGR:'#4a6fa5'};
        checked.forEach(function(s) {
            const wrap = document.createElement('div');
            wrap.style.cssText = 'background:#111;border-radius:4px;overflow:hidden';
            const header = document.createElement('div');
            header.style.cssText = 'padding:.35rem .75rem;font-size:.75rem;font-weight:700;letter-spacing:.1em;background:' + (stratColors[s]||'#333') + ';color:#fff';
            header.textContent = s;
            const log = document.createElement('div');
            log.style.cssText = 'font-family:monospace;font-size:.78rem;color:#7FFF7F;padding:.75rem;max-height:200px;overflow-y:auto;white-space:pre-wrap';
            log.textContent = 'Waiting...';
            wrap.appendChild(header);
            wrap.appendChild(log);
            stratLogContainer.appendChild(wrap);
            stratLogEls[s] = log;
        });

        const logStrat = function(s, msg) {
            if (stratLogEls[s]) {
                stratLogEls[s].textContent += '\n' + msg;
                stratLogEls[s].scrollTop = stratLogEls[s].scrollHeight;
            }
        };

        // Run all selected strategies in parallel
        await Promise.all(checked.map(function(strategy) {
            return runStrategyToPanel(strategy, totalGens, 1, logStrat);
        }));

        btnStart.disabled = false;
        btnStop.style.display = 'none';
        if (!stopRequested) {
            checked.forEach(function(s) { logStrat(s, '\n✓ Complete.'); });
        }
    });

    btnStop.addEventListener('click', function() {
        stopRequested = true;
        logSim('\n[Stopping after current generation completes\u2026]');
    });

    // ── CHECK FOR INCOMPLETE / RESUME ───────────────────────────────────────
    var incompleteStrategies = [];  // [{strategy, nextGen}]

    document.getElementById('btn-check-resume').addEventListener('click', async function() {
        const statusEl = document.getElementById('resume-status');
        const btnResumeAll = document.getElementById('btn-resume-all');
        const checked = [...document.querySelectorAll('.strat-check:checked')].map(function(el) { return el.value; });
        if (checked.length === 0) { statusEl.textContent = 'Select at least one strategy to check.'; statusEl.style.display = 'block'; return; }

        statusEl.textContent = 'Checking...';
        statusEl.style.display = 'block';
        btnResumeAll.style.display = 'none';
        incompleteStrategies = [];

        const totalGens = parseInt(document.getElementById('gen-target').value, 10) || 20;

        await Promise.all(checked.map(async function(strategy) {
            const fd = new FormData();
            fd.append('action', 'bb_sim_ajax');
            fd.append('sub_action', 'get_max_gen');
            fd.append('strategy', strategy);
            fd.append('breed_suffix', breedSuffixEl.value);
            fd.append('run_suffix', runSuffix);
            try {
                const resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
                const data = await resp.json();
                if (data.success && data.next_gen <= totalGens && data.max_gen > 0) {
                    incompleteStrategies.push({ strategy: strategy, nextGen: data.next_gen, maxGen: data.max_gen });
                }
            } catch(e) {}
        }));

        if (incompleteStrategies.length === 0) {
            statusEl.textContent = 'All selected strategies are complete or not yet started.';
        } else {
            var msg = 'Incomplete: ' + incompleteStrategies.map(function(x) {
                return x.strategy + ' (at gen ' + x.maxGen + ', needs ' + x.nextGen + '-' + totalGens + ')';
            }).join(', ');
            statusEl.textContent = msg;
            btnResumeAll.style.display = 'inline-block';
        }
    });

    document.getElementById('btn-resume-all').addEventListener('click', async function() {
        if (incompleteStrategies.length === 0) return;
        const totalGens = parseInt(document.getElementById('gen-target').value, 10) || 20;
        const statusEl  = document.getElementById('resume-status');
        const btnResumeAll = document.getElementById('btn-resume-all');

        stopRequested = false;
        btnStart.disabled = true;
        btnStop.style.display = 'inline-block';
        btnResumeAll.style.display = 'none';
        statusEl.style.display = 'none';
        simLog.style.display = 'block';

        logSim('\n[Resuming ' + incompleteStrategies.length + ' interrupted strategies in parallel...]');

        await Promise.all(incompleteStrategies.map(function(x) {
            return runStrategy(x.strategy, totalGens, x.nextGen);
        }));

        btnStart.disabled = false;
        btnStop.style.display = 'none';
        if (!stopRequested) logSim('\nResume complete.');
        incompleteStrategies = [];
    });

    // ── CLEAR SIM PROGRESS ──────────────────────────────────────────────────
    document.getElementById('btn-clear-progress').addEventListener('click', async function() {
        if (!confirm('Clear all sim_progress records? This does not affect sim_replicates or alleles data.')) return;
        const fd = new FormData();
        fd.append('action', 'bb_sim_ajax');
        fd.append('sub_action', 'clear_sim_progress');
        fd.append('breed_suffix', breedSuffixEl.value);
        fd.append('run_suffix', runSuffix);
        try {
            const resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const data = await resp.json();
            logSim(data.success ? '\n[sim_progress cleared.]' : '\n[Error clearing: ' + data.error + ']');
        } catch(e) { logSim('\n[Clear failed: ' + e.message + ']'); }
    });

    // ── LOAD RESULTS ────────────────────────────────────────────────────────
    document.getElementById('btn-load-results').addEventListener('click', async function() {
        const fd = new FormData();
        fd.append('action', 'bb_sim_ajax');
        fd.append('sub_action', 'get_replicates');
        fd.append('breed_suffix', breedSuffixEl.value);
        fd.append('run_suffix', runSuffix);
        const el = document.getElementById('results-content');
        el.innerHTML = '<em>Loading...</em>';
        try {
            const resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const data = await resp.json();
            if (!data.success) { el.innerHTML = '<em>Error: ' + (data.error||'unknown') + '</em>'; return; }
            if (!data.replicates || data.replicates.length === 0) {
                el.innerHTML = '<em>No completed replicates yet. A replicate is recorded when a run reaches its final generation.</em>';
                return;
            }
            renderResults(data.replicates, el);
        } catch(e) { el.innerHTML = '<em>Load failed: ' + e.message + '</em>'; }
    });

    function renderResults(rows, el) {
        // Group by breed then strategy
        const breeds = {};
        rows.forEach(function(r) {
            const bk = r.breed_id + '|' + (r.breed_name || r.breed_suffix || 'Unknown');
            if (!breeds[bk]) breeds[bk] = {};
            if (!breeds[bk][r.strategy]) breeds[bk][r.strategy] = [];
            breeds[bk][r.strategy].push(r);
        });

        let html = '';
        const stratOrder = ['OI','AGR','IR','RANDOM'];
        const stratColors = {OI:'#1B3A2D', IR:'#C9A84C', RANDOM:'#888', AGR:'#4a6fa5'};

        Object.keys(breeds).forEach(function(bk) {
            const breedLabel = bk.split('|')[1];
            html += '<div style="margin-bottom:2rem">';
            html += '<h3 style="font-family:Georgia,serif;color:#1B3A2D;font-size:1.3rem;margin-bottom:.75rem;border-bottom:2px solid #C9A84C;padding-bottom:.3rem">' + breedLabel + '</h3>';

            stratOrder.forEach(function(strat) {
                const reps = breeds[bk][strat];
                if (!reps || reps.length === 0) return;
                html += '<div style="margin-bottom:1.2rem">';
                html += '<div style="font-weight:700;font-size:.8rem;letter-spacing:.1em;text-transform:uppercase;color:' + (stratColors[strat]||'#333') + ';margin-bottom:.4rem">' + strat + ' — ' + reps.length + ' replicate' + (reps.length>1?'s':'') + '</div>';
                html += '<table style="width:100%;border-collapse:collapse;font-size:.82rem">';
                html += '<thead><tr style="background:#1B3A2D;color:#fff">';
                // genN_* columns hold the final generation; final_gen records
                // which generation that was. Label from the data when present.
                const gLabel = 'gen' + (reps[0].final_gen || parseInt(document.getElementById('gen-target').value, 10) || 20);
                ['Rep','Date','N','He gen0','He ' + gLabel,'ΔHe','Ho gen0','Ho ' + gLabel,'ΔHo','Ne gen0','Ne ' + gLabel,'ΔNe','Na gen0','Na ' + gLabel,'ΔNa','OI gen0','OI ' + gLabel,'ΔOI','IR gen0','IR ' + gLabel,'ΔIR','AGR gen0','AGR ' + gLabel,'ΔAGR'].forEach(function(h){
                    html += '<th style="padding:5px 8px;text-align:right;font-weight:600">' + h + '</th>';
                });
                html += '</tr></thead><tbody>';

                reps.forEach(function(r, i) {
                    const bg = i % 2 === 0 ? '#fff' : '#f5f0e8';
                    const dColor = function(v) { return v > 0 ? '#2c7a4b' : v < 0 ? '#c0392b' : '#666'; };
                    const fmt = function(v,d) { const p = parseFloat(v); return isNaN(p) ? '—' : p.toFixed(d||4); };
                    const date = r.completed_at ? r.completed_at.substring(0,10) : '';
                    const dCell = function(v,d) { return '<td style="padding:5px 8px;text-align:right;color:' + dColor(v) + ';font-weight:600">' + (v > 0 ? '+' : '') + fmt(v,d) + '</td>'; };
                    html += '<tr style="background:' + bg + '">';
                    html += '<td style="padding:5px 8px;text-align:right">' + r.replicate_num + '</td>';
                    html += '<td style="padding:5px 8px;text-align:right">' + date + '</td>';
                    html += '<td style="padding:5px 8px;text-align:right">' + (r.final_gen || '—') + '</td>';
                    html += '<td style="padding:5px 8px;text-align:right">' + fmt(r.gen0_he) + '</td>';
                    html += '<td style="padding:5px 8px;text-align:right">' + fmt(r.genN_he) + '</td>';
                    html += dCell(r.he_delta);
                    html += '<td style="padding:5px 8px;text-align:right">' + fmt(r.gen0_ho) + '</td>';
                    html += '<td style="padding:5px 8px;text-align:right">' + fmt(r.genN_ho) + '</td>';
                    html += dCell(r.ho_delta);
                    html += '<td style="padding:5px 8px;text-align:right">' + fmt(r.gen0_ne) + '</td>';
                    html += '<td style="padding:5px 8px;text-align:right">' + fmt(r.genN_ne) + '</td>';
                    html += dCell(r.ne_delta);
                    html += '<td style="padding:5px 8px;text-align:right">' + fmt(r.gen0_na,2) + '</td>';
                    html += '<td style="padding:5px 8px;text-align:right">' + fmt(r.genN_na,2) + '</td>';
                    html += dCell(r.na_delta,2);
                    html += '<td style="padding:5px 8px;text-align:right">' + fmt(r.gen0_oi) + '</td>';
                    html += '<td style="padding:5px 8px;text-align:right">' + fmt(r.genN_oi) + '</td>';
                    html += dCell(r.oi_delta);
                    html += '<td style="padding:5px 8px;text-align:right">' + fmt(r.gen0_ir) + '</td>';
                    html += '<td style="padding:5px 8px;text-align:right">' + fmt(r.genN_ir) + '</td>';
                    html += dCell(r.ir_delta);
                    html += '<td style="padding:5px 8px;text-align:right">' + fmt(r.gen0_agr) + '</td>';
                    html += '<td style="padding:5px 8px;text-align:right">' + fmt(r.genN_agr) + '</td>';
                    html += dCell(r.agr_delta);
                    html += '</tr>';
                });

                // Mean row if >1 replicate
                if (reps.length > 1) {
                    const mean = function(key) { const vals = reps.map(function(r){return parseFloat(r[key]);}).filter(function(v){return !isNaN(v);}); return vals.length ? vals.reduce(function(a,v){return a+v;},0)/vals.length : NaN; };
                    const dColor = function(v) { return v > 0 ? '#2c7a4b' : v < 0 ? '#c0392b' : '#666'; };
                    const fmt = function(v,d) { return isNaN(v) ? '—' : v.toFixed(d||4); };
                    html += '<tr style="background:#1B3A2D;color:#C9A84C;font-weight:700">';
                    html += '<td style="padding:5px 8px;text-align:right" colspan="3">Mean</td>';
                    ['gen0_he','genN_he','he_delta','gen0_ho','genN_ho','ho_delta','gen0_ne','genN_ne','ne_delta','gen0_na','genN_na','na_delta','gen0_oi','genN_oi','oi_delta','gen0_ir','genN_ir','ir_delta','gen0_agr','genN_agr','agr_delta'].forEach(function(k,i){
                        const v = mean(k);
                        const d = k.includes('na') ? 2 : 4;
                        const prefix = k.includes('delta') && v > 0 ? '+' : '';
                        const color = k.includes('delta') ? dColor(v) : '#C9A84C';
                        html += '<td style="padding:5px 8px;text-align:right;color:' + color + '">' + prefix + fmt(v,d) + '</td>';
                    });
                    html += '</tr>';
                }
                html += '</tbody></table>';
                html += '</div>';
            });
            html += '</div>';
        });

        // Glossary
        html += '<div style="margin-top:2rem;padding:1.2rem;background:#f5f0e8;border-radius:6px;font-size:.82rem;line-height:1.8;color:#2C2C2C;border-left:4px solid #C9A84C">';
        html += '<strong style="font-size:.85rem;color:#1B3A2D;text-transform:uppercase;letter-spacing:.08em">Definitions</strong><br><br>';
        html += '<strong>He — Expected Heterozygosity.</strong> The probability that two randomly drawn alleles at a locus are different. Rises when alleles become more evenly distributed across the population. A higher He at the final generation than gen 0 indicates increasing allele frequency balance — not more alleles, but more even representation of existing alleles.<br><br>';
        html += '<strong>Ho — Observed Heterozygosity.</strong> The directly counted fraction of heterozygous genotypes, averaged across loci. Unlike He it is a direct count rather than a function of allele frequencies, so it serves as an independent outcome. FIS = 1 − Ho/He.<br><br>';
        html += '<strong>OI — Outlier Index.</strong> A per-dog measure of how much of the breed\'s less-common allelic variation the dog carries; the population mean tracks how well uncommon alleles are represented in the breeding population. Rising mean OI means uncommon alleles are gaining carriers.<br><br>';
        html += '<strong>IR — Internal Relatedness.</strong> A per-dog measure of parental similarity weighted by allele frequency; the population mean tracks inbreeding. Falling mean IR under a strategy means matings are producing less inbred offspring on average.<br><br>';
        html += '<strong>Ne — Effective Alleles.</strong> How many equally-frequent alleles would produce the observed He. Ne = 1/(1−He). More sensitive than Na to frequency shifts. Rising Ne means the population is moving away from a few dominant alleles toward more even representation. A Ne that rises while Na is stable means alleles are being redistributed, not gained — which is what OI-guided selection is designed to produce.<br><br>';
        html += '<strong>Na — Average Alleles per Locus.</strong> The raw count of distinct alleles observed per locus, averaged across all 33 STR loci. Falling Na means alleles are being permanently lost from the population through genetic drift or selection. Na is the most direct measure of allelic richness. A strategy that preserves Na while also improving Ne is achieving the dual goal of retention and redistribution.<br><br>';
        html += '<strong>AGR (columns) — Population Mean Relatedness.</strong> The mean pairwise Wang GR over all dogs in the population at that generation, using that generation\'s own allele frequencies. Falling mean AGR means the population as a whole is becoming less related.<br><br>';
        html += '<strong>AGR — Average Genetic Relatedness.</strong> Selects the puppy with the lowest mean pairwise Wang GR against all already-selected keepers this generation. Wang GR is available through BetterBred, but the sequential coordination across all breeders selecting simultaneously is not feasible in practice. Included as a performance benchmark.<br><br>';
        html += '</div>';

        el.innerHTML = html;
    }

    document.getElementById('strat-all').addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.strat-check').forEach(function(cb) { cb.checked = true; });
        document.getElementById('strat-hint').style.display = 'none';
    });

    document.getElementById('strat-none').addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.strat-check').forEach(function(cb) { cb.checked = false; });
    });

    // Hide hint when any strategy is toggled on
    document.querySelectorAll('.strat-check').forEach(function(cb) {
        cb.addEventListener('change', function() {
            if (this.checked) document.getElementById('strat-hint').style.display = 'none';
        });
    });

    // ── LOAD PROGRESS ───────────────────────────────────────────────────────
    document.getElementById('btn-load-progress').addEventListener('click', async function() {
        const fd = new FormData();
        fd.append('action', 'bb_sim_ajax');
        fd.append('sub_action', 'get_progress');
        fd.append('breed_suffix', breedSuffixEl.value);
        fd.append('run_suffix', runSuffix);
        const el = document.getElementById('progress-content');
        el.innerHTML = '<em>Loading...</em>';
        try {
            const resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const data = await resp.json();
            if (!data.success) { el.innerHTML = '<em>Error: ' + (data.error||'unknown') + '</em>'; return; }
            if (!data.progress || data.progress.length === 0) {
                el.innerHTML = '<em>No sim_progress data for this breed yet.</em>';
                return;
            }
            renderProgress(data.progress, el);
        } catch(e) { el.innerHTML = '<em>Load failed: ' + e.message + '</em>'; }
    });

    function renderProgress(rows, el) {
        var byStrat = {};
        rows.forEach(function(r) {
            if (!byStrat[r.strategy]) byStrat[r.strategy] = [];
            byStrat[r.strategy].push(r);
        });

        var stratOrder  = ['OI','AGR','IR','RANDOM'];
        var stratColors = {OI:'#1B3A2D', IR:'#C9A84C', RANDOM:'#888', AGR:'#4a6fa5'};
        var fmt = function(v, d) { return (v !== null && v !== undefined) ? parseFloat(v).toFixed(d||4) : '--'; };
        var pct = function(v)    { return (v !== null && v !== undefined) ? (parseFloat(v)*100).toFixed(1)+'%' : '--'; };

        var html = '<div style="margin-top:.5rem;padding-top:1rem;border-top:2px solid #C9A84C">';
        html += '<h3 style="font-family:Georgia,serif;color:#1B3A2D;font-size:1.1rem;margin-bottom:.75rem">Per-Generation Progress (mean across replicates)</h3>';

        stratOrder.forEach(function(strat) {
            var gens = byStrat[strat];
            if (!gens || gens.length === 0) return;
            var color = stratColors[strat] || '#333';
            html += '<div style="margin-bottom:1.5rem">';
            html += '<div style="font-weight:700;font-size:.8rem;letter-spacing:.1em;text-transform:uppercase;color:' + color + ';margin-bottom:.4rem">';
            html += strat + ' <span style="font-weight:400;color:#888;font-size:.75rem">(n=' + gens[0].n_reps + ' reps avg)</span></div>';
            html += '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.78rem;min-width:560px">';
            html += '<thead><tr style="background:#1B3A2D;color:#fff">';
            ['Gen','He','Ne','Na','Avg OI','Avg IR','% Low Frq','% Mid Frq','% Hi Frq'].forEach(function(h) {
                html += '<th style="padding:4px 7px;text-align:right;font-weight:600;white-space:nowrap">' + h + '</th>';
            });
            html += '</tr></thead><tbody>';
            gens.forEach(function(r, i) {
                var bg      = i % 2 === 0 ? '#fff' : '#f5f0e8';
                var oiColor = parseFloat(r.avg_oi) > 0 ? '#2c7a4b' : '#c0392b';
                var irColor = parseFloat(r.avg_ir) < 0 ? '#888' : '#888';
                html += '<tr style="background:' + bg + '">';
                html += '<td style="padding:4px 7px;text-align:right;font-weight:600">' + r.gen + '</td>';
                html += '<td style="padding:4px 7px;text-align:right">' + fmt(r.avg_he) + '</td>';
                html += '<td style="padding:4px 7px;text-align:right">' + fmt(r.avg_ne) + '</td>';
                html += '<td style="padding:4px 7px;text-align:right">' + fmt(r.avg_na, 2) + '</td>';
                html += '<td style="padding:4px 7px;text-align:right;color:' + oiColor + ';font-weight:600">' + fmt(r.avg_oi) + '</td>';
                html += '<td style="padding:4px 7px;text-align:right;color:' + irColor + '">' + fmt(r.avg_ir) + '</td>';
                html += '<td style="padding:4px 7px;text-align:right">' + pct(r.band_infr) + '</td>';
                html += '<td style="padding:4px 7px;text-align:right">' + pct(r.band_norm) + '</td>';
                html += '<td style="padding:4px 7px;text-align:right">' + pct(r.band_hfreq) + '</td>';
                html += '</tr>';
            });
            html += '</tbody></table></div></div>';
        });
        html += '</div>';
        el.innerHTML = html;
    }

    // =========================================================
    // RUN VIA PYTHON API  (no per-generation AJAX loop)
    // One fetch per strategy — runs all 20 gens server-side, no timeout
    // =========================================================
    async function runStrategyViaApi(strategy, breedSuffix, breedId, logFn) {
        logFn(strategy, 'Sending to API...');
        try {
            var totalGens = parseInt(document.getElementById('gen-target').value, 10) || 20;

            var fd = new FormData();
            fd.append('action', 'bb_sim_api');
            fd.append('strategy', strategy);
            fd.append('breed_suffix', breedSuffix);
            fd.append('breed_id', parseInt(breedId, 10));
            fd.append('run_suffix', runSuffix);
            // Run parameters. Without these the engine falls back to its
            // defaults and every control above becomes decorative.
            fd.append('generations',   totalGens);
            fd.append('litter_size',   parseInt(document.getElementById('litter-size').value, 10) || 10);
            fd.append('sires_per_dam', parseInt(document.getElementById('sires-per-dam').value, 10) || 1);
            fd.append('sire_mode',     document.getElementById('sire-mode').value);
            const resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const data = await resp.json();
            if (data.success) {
                var f = data.final || {};
                logFn(strategy, 'Done. Gens run: ' + data.gens_run
                    + '  He=' + (f.avg_he ? parseFloat(f.avg_he).toFixed(4) : '--')
                    + '  Na=' + (f.avg_na ? parseFloat(f.avg_na).toFixed(2) : '--')
                    + '  (' + (f.elapsed || '--') + 's)');
                updateProgressBar(strategy, totalGens, totalGens);
                // Partial completion is a failure. The engine can return
                // success:true having run fewer generations than asked for.
                if (parseInt(data.gens_run, 10) !== totalGens) {
                    logFn(strategy, 'INCOMPLETE: ran ' + data.gens_run + ' of ' + totalGens + ' generations.');
                    return false;
                }
                return true;
            } else {
                logFn(strategy, 'ERROR: ' + (data.error || 'unknown'));
                return false;
            }
        } catch(e) {
            logFn(strategy, 'FETCH ERROR: ' + e.message);
            return false;
        }
    }

    document.getElementById('btn-api-start').addEventListener('click', async function() {
        if (!foundersReady) { alert('Seed founders first.'); return; }
        var checked = Array.from(document.querySelectorAll('.strat-check:checked')).map(function(el) { return el.value; });
        if (checked.length === 0) { document.getElementById('strat-hint').style.display = 'inline'; return; }
        document.getElementById('strat-hint').style.display = 'none';

        var breedSuffix = breedSuffixEl.value;
        var breedId     = breedIdEl.value;

        pbsEl.innerHTML = '';
        var btnApi = document.getElementById('btn-api-start');
        btnApi.disabled = true;

        // Build per-strategy log panels (same pattern as PHP runner)
        simLog.style.display = 'none';
        var container = document.getElementById('strat-log-container') || (function() {
            var el = document.createElement('div');
            el.id = 'strat-log-container';
            el.style.cssText = 'margin-top:1rem;display:grid;gap:.75rem';
            simLog.parentNode.insertBefore(el, simLog.nextSibling);
            return el;
        })();
        container.innerHTML = '';
        var logEls = {};
        var stratColors = {OI:'#1B3A2D', IR:'#b07d00', RANDOM:'#555', AGR:'#4a6fa5'};
        checked.forEach(function(s) {
            var wrap = document.createElement('div');
            wrap.style.cssText = 'background:#111;border-radius:4px;overflow:hidden';
            var header = document.createElement('div');
            header.style.cssText = 'padding:.35rem .75rem;font-size:.75rem;font-weight:700;letter-spacing:.1em;background:' + (stratColors[s]||'#333') + ';color:#fff';
            header.textContent = s + ' (API)';
            var log = document.createElement('div');
            log.style.cssText = 'font-family:monospace;font-size:.78rem;color:#7FFF7F;padding:.75rem;max-height:200px;overflow-y:auto;white-space:pre-wrap';
            log.textContent = 'Waiting...';
            wrap.appendChild(header); wrap.appendChild(log);
            container.appendChild(wrap);
            logEls[s] = log;
        });
        var logFn = function(s, msg) {
            if (logEls[s]) {
                logEls[s].textContent += '\n' + msg;
                logEls[s].scrollTop = logEls[s].scrollHeight;
            }
        };

        // Fire strategies sequentially -- parallel writes conflict on shared tables.
        // HALT on the first failure. The previous version logged the error and
        // carried on to the next strategy, then printed "All strategies
        // complete" regardless -- so a mid-run failure looked like a clean run
        // unless a human was reading the log.
        var allOk = true;
        for (var si = 0; si < checked.length; si++) {
            var ok = await runStrategyViaApi(checked[si], breedSuffix, breedId, logFn);
            if (!ok) {
                allOk = false;
                logFn(checked[si], '\nHALTED. ' + checked[si] + ' did not complete. '
                    + 'Remaining strategies were NOT run. This replicate is incomplete '
                    + 'and must be deleted or rerun before it is used.');
                break;
            }
        }

        btnApi.disabled = false;
        if (allOk) {
            markResultsStale(false);
            logFn(checked[0], '\nAll strategies complete. Load Results to verify.');
        }
        return allOk;
    });

    // Same run sequence, callable without the button. Returns true only if
    // every requested strategy completed all generations.
    async function doRunAllStrategies(strategies, breedSuffix, breedId, logFn) {
        for (var si = 0; si < strategies.length; si++) {
            var ok = await runStrategyViaApi(strategies[si], breedSuffix, breedId, logFn);
            if (!ok) { return false; }
        }
        return true;
    }


    // =========================================================
    // BATCH RUNNER
    // truncate -> seed -> run all strategies -> verify, N times.
    // =========================================================
    let batchStopRequested = false;

    const batchLog       = document.getElementById('batch-log');
    const batchStatus    = document.getElementById('batch-status');
    const btnBatchStart  = document.getElementById('btn-batch-start');
    const btnBatchStop   = document.getElementById('btn-batch-stop');

    function logBatch(msg) {
        batchLog.style.display = 'block';
        const t = new Date().toLocaleTimeString();
        batchLog.textContent += '[' + t + '] ' + msg + '\n';
        batchLog.scrollTop = batchLog.scrollHeight;
    }

    // Ask the server whether this replicate is actually complete.
    // Row counts alone are NOT sufficient: DP replicate 18 had all 20
    // generation rows for all four strategies and still carried NULL
    // avg_ho / avg_fis the whole way through.
    async function verifyReplicate(suffix, repNum, strategies, gens) {
        const fd = new FormData();
        fd.append('action', 'bb_sim_ajax');
        fd.append('sub_action', 'verify_replicate');
        fd.append('breed_suffix', breedSuffixEl.value);
        fd.append('run_suffix', suffix);
        fd.append('replicate_num', repNum);
        fd.append('expect_gens', gens);
        fd.append('expect_strategies', strategies.join(','));
        try {
            const resp = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const data = await resp.json();
            if (!data.success) {
                return { ok: false, problems: ['verify call failed: ' + (data.error || 'unknown')] };
            }
            return { ok: data.ok, problems: data.problems || [] };
        } catch(e) {
            return { ok: false, problems: ['verify fetch error: ' + e.message] };
        }
    }

    btnBatchStop.addEventListener('click', function() {
        batchStopRequested = true;
        btnBatchStop.disabled = true;
        logBatch('Stop requested. Finishing the current replicate, then halting.');
    });

    btnBatchStart.addEventListener('click', async function() {

        const nReps = parseInt(document.getElementById('batch-size').value, 10) || 0;
        if (nReps < 1) { alert('Set a batch size of at least 1.'); return; }

        const breedId = parseInt(breedIdEl.value, 10);
        if (!breedId) { alert('Enter a breed ID.'); return; }

        const strategies = Array.from(document.querySelectorAll('.strat-check:checked'))
                                .map(function(el) { return el.value; });
        if (strategies.length === 0) {
            alert('Select at least one strategy in the Simulation card.');
            return;
        }

        const gens = parseInt(document.getElementById('gen-target').value, 10) || 20;
        const breedSuffix = breedSuffixEl.value;

        // Pin the run_suffix if one was given, otherwise use whatever run is
        // already active. Blank on both = let the seeder mint a new one from
        // breed + month, and adopt it for the rest of the batch.
        let pinned = document.getElementById('batch-run-suffix').value.trim().toLowerCase();
        if (!pinned && runSuffix) { pinned = runSuffix; }

        if (!confirm('Run ' + nReps + ' replicate(s) of ' + breedSuffix.toUpperCase()
            + ' x ' + strategies.join('/') + ' at ' + gens + ' generations?\n\n'
            + 'This truncates and reseeds ' + nReps + ' times. It will stop on the first failure.')) {
            return;
        }

        batchStopRequested = false;
        btnBatchStart.disabled = true;
        btnBatchStop.style.display = 'inline-block';
        btnBatchStop.disabled = false;
        batchLog.textContent = '';
        batchStatus.textContent = 'Running';
        batchStatus.className = 'status-badge pending';

        const t0 = Date.now();
        let done = 0;
        let failed = false;

        const silentLog = function(strategy, msg) { logBatch('    [' + strategy + '] ' + msg); };

        logBatch('BATCH START  breed=' + breedSuffix + '  reps=' + nReps
                 + '  strategies=' + strategies.join(',') + '  gens=' + gens
                 + (pinned ? ('  run_suffix=' + pinned) : '  run_suffix=(new)'));

        for (let i = 1; i <= nReps; i++) {

            if (batchStopRequested) {
                logBatch('Stopped by request before replicate ' + i + ' of ' + nReps + '.');
                break;
            }

            logBatch('--- Replicate ' + i + ' of ' + nReps + ' ---');

            // 1. TRUNCATE
            logBatch('  Truncating working tables...');
            if (!await doTruncate(breedSuffix)) {
                logBatch('  TRUNCATE FAILED. Halting. See the Truncate log above.');
                failed = true; break;
            }

            // 2. SEED
            logBatch('  Seeding founders...');
            if (!await doSeed(pinned)) {
                logBatch('  SEED FAILED. Halting. See the Founder log above.');
                failed = true; break;
            }
            // Adopt the suffix the seeder actually used, and hold it for the
            // rest of the batch so a month rollover cannot split the run.
            if (!pinned) {
                pinned = runSuffix;
                document.getElementById('batch-run-suffix').value = pinned;
                logBatch('  Run suffix set to ' + pinned + ' and pinned for this batch.');
            }

            if (!lastRepNum) {
                logBatch('  SEED returned no replicate number. Halting.');
                failed = true; break;
            }
            const repNum = lastRepNum;
            logBatch('  Seeded as replicate ' + repNum + '.');

            // 3. RUN ALL STRATEGIES -- halts internally on first failure
            logBatch('  Running ' + strategies.join(', ') + '...');
            if (!await doRunAllStrategies(strategies, breedSuffix, breedId, silentLog)) {
                logBatch('  RUN FAILED. Halting.');
                logBatch('  This replicate is INCOMPLETE. Delete it before using this run,');
                logBatch('  or the next seed will number around it. Do not just rerun on top.');
                failed = true; break;
            }

            // 4. VERIFY -- the check a row count would pass
            const v = await verifyReplicate(pinned, repNum, strategies, gens);
            if (!v.ok) {
                logBatch('  VERIFY FAILED on replicate ' + repNum + ':');
                v.problems.forEach(function(pr) { logBatch('    - ' + pr); });
                logBatch('  Halting. Delete replicate ' + repNum + ' before continuing.');
                failed = true; break;
            }

            done++;
            markResultsStale(false);
            const mins = ((Date.now() - t0) / 60000);
            logBatch('  Replicate ' + repNum + ' VERIFIED clean. '
                     + done + '/' + nReps + ' done, ' + mins.toFixed(1) + ' min elapsed.');
        }

        const totalMin = ((Date.now() - t0) / 60000).toFixed(1);
        btnBatchStart.disabled = false;
        btnBatchStop.style.display = 'none';

        if (failed) {
            batchStatus.textContent = 'FAILED';
            batchStatus.className = 'status-badge pending';
            logBatch('BATCH HALTED after ' + done + ' clean replicate(s), ' + totalMin + ' min.');
        } else {
            batchStatus.textContent = '\u2713 ' + done + ' clean';
            batchStatus.className = 'status-badge';
            logBatch('BATCH COMPLETE. ' + done + ' replicate(s) verified clean in ' + totalMin + ' min.');
        }
    });

})();
</script>


<?php wp_footer(); ?>
</body>
</html>