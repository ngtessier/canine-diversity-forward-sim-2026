<?php
/**
 * BetterBred Simulation Study I
 * Requires: sim-helpers.php (loaded via functions.php)
 * PHP 7.3 -- no arrow functions, no backticks in SQL.
 */

/**
 * BetterBred Simulation Functions
 *
 * Contains AJAX handlers for Study I, Study II, and Study 1A simulations,
 * plus shared Wang GR computation helpers.
 *
 * Loaded via: require_once get_template_directory() . '/sim-functions.php';
 * in functions.php (one-line addition).
 *
 * PHP 7.3  -- no arrow functions, no backticks in SQL, no $wpdb->insert() for
 * better_bred.* tables.
 *
 * Study I strategies:
 *   OI      -- highest OI from each group (breeder-accessible, BetterBred tool)
 *   IR      -- lowest IR from each group (legacy criterion)
 *   MIX     -- OI from group 1, IR from group 2 (behavioral: breeder hedging)
 *   RANDOM  -- random from each group (null baseline)
 *   AGR     -- lowest mean Wang GR to current keeper pool (population benchmark)
 *   CAR     -- highest marginal allelic richness contribution (population benchmark)
 *
 * AGR and CAR require population-level information unavailable to an
 * individual breeder at decision time. They serve as performance ceilings
 * against which the breeder-accessible OI criterion is evaluated.
 * See strategy_expansion.md for full methods note.
 *
 * Keeper selection design:
 *   10 puppies generated per litter (5 from each parent pair).
 *   Randomly split into two groups of 5. Best from each group selected by
 *   strategy criterion. Sex randomly assigned to the two selected keepers.
 *   This prevents within-litter redundancy without imposing sex-linked
 *   selection pressure.
 */

// ============================================================================
// STUDY I  -- AJAX HANDLER
// ============================================================================

// Only run during WordPress AJAX requests -- prevents file scanners
// (e.g. Wordfence) from executing handler code when loading this file directly.
if (!defined('DOING_AJAX') || !DOING_AJAX) { return; }

add_action('wp_ajax_bb_sim_ajax', 'bb_sim_ajax_handler');

if (!function_exists('bb_sim_ajax_handler')) {

function bb_sim_ajax_handler() {

    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    @ini_set('display_errors', 0);
    error_reporting(0);

    if (!is_user_logged_in() || !current_user_can('edit_posts')) {
        echo json_encode(array('success' => false, 'error' => 'Not authorized.'));
        exit;
    }

    set_time_limit(300);
    ignore_user_abort(true);

    $conn = getBBDatabase();
    if (!$conn) {
        echo json_encode(array('success' => false, 'error' => 'DB connection failed.')); exit;
    }
    $allowed_breeds_result = mysqli_query($conn, "SELECT sim_suffix FROM better_bred.breed WHERE sim_suffix IS NOT NULL");
    $allowed_breeds = array('');
    // Each breed contributes TWO legal suffixes:
    //   'bmd'   -- Sim I / Sim II namespace  -> alleles_gdx_oi_bmd
    //   'xbmd'  -- Sim III crossbreeding      -> alleles_gdx_oi_xbmd
    // The 'x' namespace exists so a crossbreeding run can never seed into, or
    // DROP via truncate_tables, a Sim I working table for the same breed.
    // breed_suffix is VARCHAR(5) in sim1_founders_* / sim1_origin_* /
    // sim1_novel_* AND is used as a lookup key (WHERE breed_suffix = ...), so
    // the namespaced form is capped at 'x' + 4 chars = 5. This must stay in
    // lockstep with nsBreed() in crossbreeding-runner-ui.php.
    while ($r = mysqli_fetch_assoc($allowed_breeds_result)) {
        $allowed_breeds[] = $r['sim_suffix'];
        $allowed_breeds[] = 'x' . substr($r['sim_suffix'], 0, 4);
    }

    $allowed_strategies = array('OI', 'IR', 'RANDOM', 'AGR');

    $breed_raw = strtolower(trim($_POST['breed_suffix'] ?? ''));
    if (!in_array($breed_raw, $allowed_breeds, true)) {
        echo json_encode(array('success' => false, 'error' => 'Invalid breed suffix.')); exit;
    }
    $breed_sfx     = ($breed_raw === '') ? '' : '_' . $breed_raw;
    $breed_id_post = (int)($_POST['breed_id'] ?? 0);

    $start      = microtime(true);
    $sub_action = trim($_POST['sub_action'] ?? '');

    // run_suffix identifies the permanent tables for this run.
    // Generated at seed as {breed}_{mon}{year} e.g. sp_apr2026.
    // Passed back unchanged on every subsequent call.
    $run_suffix_raw = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['run_suffix'] ?? '')));

    // ========================================================================
    // MODE A: SEED FOUNDERS
    // ========================================================================
    if ($sub_action === 'seed_founders') {

        $breed_id = (int)($_POST['breed_id'] ?? 0);
        // Founder census, now set per sex. pop_size kept as a fallback so any
        // older caller that still sends only pop_size keeps working.
        $pop_size     = min((int)($_POST['pop_size']     ?? 100), 500);
        $n_founders_m = min((int)($_POST['n_founders_m'] ?? $pop_size), 500);
        $n_founders_f = min((int)($_POST['n_founders_f'] ?? $pop_size), 500);
        $min_loci     = (int)($_POST['min_loci'] ?? 33);
        $log      = array();

        // ---- Crossbreeding (Sim III): optional donor founders -------------
        // donors is a JSON array from the caller:
        //   [{"breed_id":17,"n_m":1,"n_f":1},{"breed_id":36,"n_m":1,"n_f":1}]
        // Empty/absent => ordinary single-breed run (backward compatible).
        // Donor founders DISPLACE recipient founders 1:1, so the per-sex
        // census stays exactly n_founders_m / n_founders_f.
        $donors     = array();
        $donors_raw = isset($_POST['donors']) ? $_POST['donors'] : '';
        if ($donors_raw !== '') {
            $decoded = json_decode(stripslashes($donors_raw), true);
            if (is_array($decoded)) {
                foreach ($decoded as $dnr) {
                    $bid = (int)(isset($dnr['breed_id']) ? $dnr['breed_id'] : 0);
                    $dnm = (int)(isset($dnr['n_m']) ? $dnr['n_m'] : 0);
                    $dnf = (int)(isset($dnr['n_f']) ? $dnr['n_f'] : 0);
                    if ($bid > 0 && ($dnm + $dnf) > 0) {
                        $donors[] = array('breed_id' => $bid, 'n_m' => $dnm, 'n_f' => $dnf);
                    }
                }
            }
        }

        // Compute run suffix and permanent table names.
        // If the caller pinned a run_suffix (batch runner, resume), honor it.
        // Otherwise derive from breed + current month. Deriving from the date
        // on every seed means a run that crosses a month boundary silently
        // splits into a new set of tables.
        if ($run_suffix_raw !== '') {
            $run_suffix = $run_suffix_raw;
        } else {
            $run_suffix = ($breed_raw !== '' ? $breed_raw . '_' : '') . strtolower(date('MY'));
        }
        $tbl_founders   = 'sim1_founders_'   . $run_suffix;
        $tbl_progress   = 'sim1_progress_'   . $run_suffix;
        $tbl_replicates = 'sim1_replicates_' . $run_suffix;

        // Ensure all permanent tables exist before seeding
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS {$tbl_founders} (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            replicate_num   INT,
            breed_suffix    VARCHAR(5),
            dog_id          INT,
            gender          CHAR(1),
            oi              FLOAT NULL,
            ir              FLOAT NULL,
            agr             FLOAT NULL,
            num_lo_alleles  INT NULL,
            num_mid_alleles INT NULL,
            num_hi_alleles  INT NULL,
            INDEX rep_breed (replicate_num, breed_suffix)
        )");
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS {$tbl_progress} (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            strategy         VARCHAR(20),
            gen              INT,
            completed_at     DATETIME,
            elapsed_seconds  FLOAT,
            avg_he           FLOAT,
            avg_ne           FLOAT,
            avg_na           FLOAT,
            avg_ho           DOUBLE NULL,
            avg_fis          DOUBLE NULL,
            avg_oi           FLOAT,
            avg_ir           FLOAT,
            band_infr        FLOAT,
            band_norm        FLOAT,
            band_hfreq       FLOAT,
            num_breeders     INT,
            num_breeding_sires INT NULL,
            breed_suffix     VARCHAR(5),
            replicate_num    INT,
            total_alleles    INT,
            litter_size      INT NULL,
            sires_per_dam    INT NULL,
            sire_mode        VARCHAR(10) NULL,
            INDEX strat_gen  (strategy, gen)
        )");
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS {$tbl_replicates} (
            id                      INT AUTO_INCREMENT PRIMARY KEY,
            strategy                VARCHAR(20),
            breed_suffix            VARCHAR(5),
            breed_id                INT,
            replicate_num           INT,
            completed_at            DATETIME,
            pop_size                INT,
            loci                    INT,
            final_gen INT NULL,
            gen0_he FLOAT, gen0_ne FLOAT, gen0_na FLOAT,
            genN_he FLOAT, genN_ne FLOAT, genN_na FLOAT,
            he_delta FLOAT, ne_delta FLOAT, na_delta FLOAT,
            gen0_oi FLOAT, genN_oi FLOAT, oi_delta FLOAT,
            gen0_ir FLOAT, genN_ir FLOAT, ir_delta FLOAT,
            gen0_band_infr FLOAT, genN_band_infr FLOAT,
            gen0_band_norm FLOAT, genN_band_norm FLOAT,
            gen0_band_hfreq FLOAT, genN_band_hfreq FLOAT,
            band_infr_delta FLOAT, band_norm_delta FLOAT, band_hfreq_delta FLOAT,
            gen0_total_alleles INT, genN_total_alleles INT, total_alleles_delta INT,
            gen0_ho DOUBLE NULL, gen0_fis DOUBLE NULL,
            genN_ho DOUBLE NULL, genN_fis DOUBLE NULL,
            ho_delta DOUBLE NULL, fis_delta DOUBLE NULL,
            gen0_agr DOUBLE NULL, genN_agr DOUBLE NULL, agr_delta DOUBLE NULL,
            INDEX strat_breed (strategy, breed_suffix)
        )");


        $push = function($msg, $type = '') use (&$log) {
            $log[] = array('msg' => $msg, 'type' => $type);
        };

        if ($breed_id <= 0) {
            echo json_encode(array('success' => false, 'error' => 'Invalid breed ID.')); exit;
        }
        if ($n_founders_m < 10 || $n_founders_f < 10) {
            echo json_encode(array('success' => false, 'error' => 'Each sex needs at least 10 founders.')); exit;
        }

        $push("=== Founder Setup | Breed ID: {$breed_id} | Suffix: '" . ($breed_sfx ?: 'none') . "' | Founders: {$n_founders_m}M / {$n_founders_f}F | Loci required: {$min_loci} ===");

        $push("[1] Fetching enrolled dogs...");
        $sires = array();
        $dams  = array();

        // ELIGIBILITY = COMPLETE GENOTYPE. Nothing else.
        //
        //   All 33 loci, exactly. A founder missing a locus has no allele to
        //   transmit there, which breaks the transmission model. This is a
        //   correctness requirement, not tidiness. (The old ">= 15" had no
        //   documented justification and is retired.)
        //
        //   The `active` flag is deliberately NOT used. It is a user-set
        //   platform preference. It says nothing biological about the dog and
        //   nothing about the quality of the dog. Filtering on it would import
        //   the users' own selection decisions into the founding population.
        //
        // FUTURE CRITERIA (health testing, etc.) attach here as explicit ANDs.
        $rS = mysqli_query($conn,
            "SELECT d.dog_id FROM dog d
             LEFT JOIN alleles a ON a.dog_id = d.dog_id
             WHERE d.breed_id = {$breed_id}
               AND d.dob > '2016-01-01' AND d.dob > 0
               AND d.gender = 'M'
             GROUP BY d.dog_id HAVING COUNT(a.locus_id) = {$min_loci}");
        while ($r = mysqli_fetch_assoc($rS)) { $sires[] = (int)$r['dog_id']; }

        $rD = mysqli_query($conn,
            "SELECT d.dog_id FROM dog d
             LEFT JOIN alleles a ON a.dog_id = d.dog_id
             WHERE d.breed_id = {$breed_id}
               AND d.dob > '2016-01-01' AND d.dob > 0
               AND d.gender = 'F'
             GROUP BY d.dog_id HAVING COUNT(a.locus_id) = {$min_loci}");
        while ($r = mysqli_fetch_assoc($rD)) { $dams[] = (int)$r['dog_id']; }

        shuffle($sires);
        shuffle($dams);

        $n_s = count($sires);
        $n_d = count($dams);
        $push("  Found {$n_s} eligible sires, {$n_d} eligible dams.");

        // ---- Donor displacement: donors take slots from the recipient ------
        $donor_m = 0;
        $donor_f = 0;
        foreach ($donors as $dnr) { $donor_m += $dnr['n_m']; $donor_f += $dnr['n_f']; }

        if ($donor_m > $n_founders_m || $donor_f > $n_founders_f) {
            echo json_encode(array('success' => false,
                'error' => "Donor dose ({$donor_m}M/{$donor_f}F) exceeds pool ({$n_founders_m}M/{$n_founders_f}F).",
                'log' => $log)); exit;
        }
        $recip_m = $n_founders_m - $donor_m;
        $recip_f = $n_founders_f - $donor_f;

        if ($n_s < $recip_m) {
            $recip_m      = $n_s;
            $n_founders_m = $recip_m + $donor_m;
            $push("  WARNING: only {$n_s} eligible sires; reducing recipient male founders to {$n_s}.", 'warn');
        }
        if ($n_d < $recip_f) {
            $recip_f      = $n_d;
            $n_founders_f = $recip_f + $donor_f;
            $push("  WARNING: only {$n_d} eligible dams; reducing recipient female founders to {$n_d}.", 'warn');
        }
        if ($n_founders_m < 10 || $n_founders_f < 10) {
            echo json_encode(array('success' => false, 'error' => 'Too few founders (need at least 10 of each sex). Check breed ID.', 'log' => $log)); exit;
        }
        if ($donor_m + $donor_f > 0) {
            $push("  Donor dose: {$donor_m}M / {$donor_f}F across " . count($donors) . " donor breed(s).");
        }
        $push("  Using {$n_founders_m} sires + {$n_founders_f} dams ({$recip_m}M/{$recip_f}F recipient).");

        $push("[2] Seeding alleles_gdx_ir{$breed_sfx}...");
        $a_ir = "alleles_gdx_ir{$breed_sfx}";

        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS {$a_ir} (
            id INT AUTO_INCREMENT, dog_id INT(11), vglid VARCHAR(45), gender VARCHAR(3),
            locus_id INT(11), str_a DECIMAL(10,2), str_b DECIMAL(10,2), homozygous TINYINT(1),
            origin_a INT(11), origin_b INT(11),
            gen INT(11), sire_id INT(11), dam_id INT(11),
            PRIMARY KEY (id), INDEX dog(dog_id), INDEX gen(gen), INDEX locus(locus_id),
            INDEX gender(gender), INDEX parents(sire_id,dam_id))");

        $chk = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS ct FROM {$a_ir} WHERE gen = 0"));
        if ((int)$chk['ct'] > 0) {
            $push("  WARNING: gen=0 rows already exist  -- TRUNCATE tables first.", 'warn');
        }

        // ---- Seat helper --------------------------------------------------
        // Identical procedure for recipient and every donor breed: same
        // eligibility filter, random shuffle, per-dog locus check. Tags every
        // allele copy with origin_a = origin_b = $origin (the source breed_id).
        // Returns how many were actually seated.
        $seat = function($seat_breed_id, $gender, $need, $origin)
                use ($conn, $a_ir, $min_loci, $push) {
            if ($need <= 0) { return 0; }
            $g   = ($gender === 'M') ? 'M' : 'F';
            $ids = array();
            $rs  = mysqli_query($conn,
                "SELECT d.dog_id FROM dog d
                 LEFT JOIN alleles a ON a.dog_id = d.dog_id
                 WHERE d.breed_id = " . (int)$seat_breed_id . "
                   AND d.dob > '2016-01-01' AND d.dob > 0
                   AND d.gender = '" . $g . "'
                 GROUP BY d.dog_id HAVING COUNT(a.locus_id) = " . (int)$min_loci);
            while ($r = mysqli_fetch_assoc($rs)) { $ids[] = (int)$r['dog_id']; }
            shuffle($ids);

            $ok = 0;
            $i  = 0;
            while ($ok < $need && $i < count($ids)) {
                $did = $ids[$i++];
                mysqli_query($conn,
                    "INSERT INTO {$a_ir} (dog_id,vglid,gender,locus_id,str_a,str_b,homozygous,origin_a,origin_b,gen,sire_id,dam_id)
                     SELECT d.dog_id,d.vglid,d.gender,a.locus_id,a.str_a,a.str_b,a.homozygous,"
                        . (int)$origin . "," . (int)$origin . ",0,0,0
                     FROM dog d JOIN alleles a ON a.dog_id=d.dog_id
                     WHERE d.dog_id=" . (int)$did . " ORDER BY a.locus_id");
                $chk = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT COUNT(*) AS ct FROM {$a_ir} WHERE dog_id={$did} AND gen=0"));
                if ((int)$chk['ct'] === $min_loci) {
                    $ok++;
                } else {
                    mysqli_query($conn, "DELETE FROM {$a_ir} WHERE dog_id={$did} AND gen=0");
                    $push("  WARNING: dog {$did} (breed {$seat_breed_id}) yielded " . (int)$chk['ct'] . " loci rows -- skipped.", 'warn');
                }
            }
            return $ok;
        };

        // ---- 1) Donor founders first (guarantees the dose) ----------------
        foreach ($donors as $dnr) {
            $gm = $seat($dnr['breed_id'], 'M', $dnr['n_m'], $dnr['breed_id']);
            if ($gm < $dnr['n_m']) {
                mysqli_query($conn, "TRUNCATE TABLE {$a_ir}");
                echo json_encode(array('success' => false,
                    'error' => "Donor breed {$dnr['breed_id']}: only {$gm} of {$dnr['n_m']} eligible males could be seated.",
                    'log' => $log)); exit;
            }
            $gf = $seat($dnr['breed_id'], 'F', $dnr['n_f'], $dnr['breed_id']);
            if ($gf < $dnr['n_f']) {
                mysqli_query($conn, "TRUNCATE TABLE {$a_ir}");
                echo json_encode(array('success' => false,
                    'error' => "Donor breed {$dnr['breed_id']}: only {$gf} of {$dnr['n_f']} eligible females could be seated.",
                    'log' => $log)); exit;
            }
            $push("  Seeded donor breed {$dnr['breed_id']}: {$gm}M / {$gf}F (origin={$dnr['breed_id']}).");
        }

        // ---- 2) Recipient fills the remaining slots -----------------------
        $ok_s = $seat($breed_id, 'M', $recip_m, $breed_id);
        if ($ok_s < $recip_m) {
            mysqli_query($conn, "TRUNCATE TABLE {$a_ir}");
            echo json_encode(array('success' => false,
                'error' => "Seed failed: only {$ok_s} sires could be seated (need {$recip_m}). Breed pool exhausted.",
                'log' => $log)); exit;
        }
        $push("  Seeded {$ok_s} recipient sires (origin={$breed_id}).");

        $ok_d = $seat($breed_id, 'F', $recip_f, $breed_id);
        if ($ok_d < $recip_f) {
            mysqli_query($conn, "TRUNCATE TABLE {$a_ir}");
            echo json_encode(array('success' => false,
                'error' => "Seed failed: only {$ok_d} dams could be seated (need {$recip_f}). Breed pool exhausted.",
                'log' => $log)); exit;
        }
        $push("  Seeded {$ok_d} recipient dams (origin={$breed_id}).");

        // Hard verification -- must be exactly pop_size of each sex, no tolerance.
        $gender_counts = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT
                SUM(CASE WHEN gender='M' THEN 1 ELSE 0 END) AS n_m,
                SUM(CASE WHEN gender='F' THEN 1 ELSE 0 END) AS n_f
             FROM (SELECT DISTINCT dog_id, gender FROM {$a_ir} WHERE gen=0) AS g"));
        $actual_m = (int)$gender_counts['n_m'];
        $actual_f = (int)$gender_counts['n_f'];
        $push("  Verified: {$actual_m}M / {$actual_f}F in alleles table.");
        if ($actual_m !== $n_founders_m || $actual_f !== $n_founders_f) {
            mysqli_query($conn, "TRUNCATE TABLE {$a_ir}");
            $msg = "Seed verification failed: expected {$n_founders_m}M + {$n_founders_f}F, got {$actual_m}M + {$actual_f}F.";
            $push("  ERROR: {$msg}", 'err');
            mysqli_close($conn);
            echo json_encode(array('success' => false, 'error' => $msg, 'log' => $log)); exit;
        }

        $countLoci = $min_loci;
        $push("  Loci: {$min_loci} (every founder must carry all of them).");
        $loci_actual = (int)mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(DISTINCT locus_id) AS ct FROM {$a_ir}"))['ct'];
        if ($loci_actual !== $min_loci) {
            echo json_encode(array('success' => false,
                'error' => "Expected {$min_loci} loci in alleles table, found {$loci_actual}. Check alleles data.",
                'log' => $log)); exit;
        }

        // Total allele slots = (number of founders) x 2 alleles each.
        // The old formula pop_size*4 was correct ONLY because pop_size was
        // per-sex AND the sexes were equal, giving 2*pop_size founders and so
        // 4*pop_size slots. With unequal sexes that identity breaks and every
        // gen-0 allele frequency is divided by the wrong denominator, silently
        // poisoning OI, IR and the band thresholds for the entire run.
        $total_slots = ($n_founders_m + $n_founders_f) * 2;

        $push("[3] Building frequency table frq_gdx_ir{$breed_sfx}...");
        $f_ir = "frq_gdx_ir{$breed_sfx}";
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS {$f_ir} (
            id INT AUTO_INCREMENT, gen INT, locus_id INT, str FLOAT(4,1),
            count_str_a INT NOT NULL DEFAULT 0, count_str_b INT NOT NULL DEFAULT 0,
            count_str_total INT, frq FLOAT(6,5),
            PRIMARY KEY(id), INDEX gen(gen), INDEX locus(locus_id),
            INDEX gen_locus(gen,locus_id), INDEX spec_str(gen,locus_id,str),
            INDEX spec_frq(gen,locus_id,str,frq))");

        mysqli_query($conn, "INSERT INTO {$f_ir} (locus_id,str,gen)
            SELECT DISTINCT locus_id,str,0 FROM (
                SELECT DISTINCT locus_id,str_a AS str FROM {$a_ir} WHERE gen=0
                UNION
                SELECT DISTINCT locus_id,str_b AS str FROM {$a_ir} WHERE gen=0
            ) AS calc ORDER BY locus_id,str");

        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS better_bred.str_a (id INT AUTO_INCREMENT PRIMARY KEY,gen INT,locus_id INT,str_a FLOAT,ct INT)");
        mysqli_query($conn, "INSERT INTO better_bred.str_a(gen,locus_id,str_a,ct) SELECT 0,locus_id,str_a,COUNT(str_a) FROM {$a_ir} WHERE gen=0 GROUP BY locus_id,str_a");
        mysqli_query($conn, "UPDATE {$f_ir} f JOIN better_bred.str_a s ON s.locus_id=f.locus_id AND s.str_a=f.str SET f.count_str_a=s.ct WHERE f.gen=0 AND f.id>0");
        mysqli_query($conn, "DROP TABLE better_bred.str_a");

        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS better_bred.str_b (id INT AUTO_INCREMENT PRIMARY KEY,gen INT,locus_id INT,str_b FLOAT,ct INT)");
        mysqli_query($conn, "INSERT INTO better_bred.str_b(gen,locus_id,str_b,ct) SELECT 0,locus_id,str_b,COUNT(str_b) FROM {$a_ir} WHERE gen=0 GROUP BY locus_id,str_b");
        mysqli_query($conn, "UPDATE {$f_ir} f JOIN better_bred.str_b s ON s.locus_id=f.locus_id AND s.str_b=f.str SET f.count_str_b=s.ct WHERE f.gen=0 AND f.id>0");
        mysqli_query($conn, "DROP TABLE better_bred.str_b");

        mysqli_query($conn, "UPDATE {$f_ir} SET count_str_total=(count_str_a+count_str_b) WHERE id>0");
        mysqli_query($conn, "UPDATE {$f_ir} SET frq=(count_str_total/{$total_slots}) WHERE id>0");

        $push("[4] Building expectedhet_ir{$breed_sfx}...");
        $e_ir = "expectedhet_ir{$breed_sfx}";
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS {$e_ir} (
            id INT AUTO_INCREMENT PRIMARY KEY, gen INT, locus_id INT,
            ho FLOAT(5,4), he FLOAT(5,4), fis FLOAT(6,4), fst FLOAT(5,4), numstrs INT,
            effective_alleles DECIMAL(6,4),
            INDEX gen(gen), INDEX locus(locus_id), INDEX gen_locus(gen,locus_id))");

        mysqli_query($conn, "INSERT INTO {$e_ir}(gen,locus_id,he,numstrs)
            SELECT gen,locus_id,(1-SUM(frq*frq)) AS he,COUNT(str) AS numstrs
            FROM {$f_ir} WHERE gen=0 GROUP BY gen,locus_id");

        mysqli_query($conn, "UPDATE {$e_ir} e
            LEFT JOIN (SELECT locus_id,((COUNT(dog_id)-SUM(homozygous))/COUNT(dog_id)) AS ho
                FROM {$a_ir} WHERE gen=0 GROUP BY locus_id) AS calc ON e.locus_id=calc.locus_id
            SET e.ho=calc.ho WHERE e.gen=0 AND e.id>0");

        // FIS = 1 - Ho/He. A WITHIN-population inbreeding coefficient.
        // The legacy column is named `fst`, which is simply wrong: FST is a
        // BETWEEN-population statistic. `fis` is now the column of record.
        // FLOAT(6,4) because FIS is SIGNED -- it goes negative when Ho > He,
        // which is exactly the result this study is being run to detect.
        mysqli_query($conn, "UPDATE {$e_ir} SET fis=(1-(ho/he)) WHERE gen=0 AND he>0 AND id>0");
        // Legacy mirror, kept in sync so any older query does not return NULL.
        mysqli_query($conn, "UPDATE {$e_ir} SET fst=fis WHERE gen=0 AND fis IS NOT NULL AND id>0");
        mysqli_query($conn, "UPDATE {$e_ir} SET effective_alleles=1/(1-he) WHERE gen=0 AND id>=0");

        $eh = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT AVG(he) AS avg_he, AVG(effective_alleles) AS avg_ne FROM {$e_ir} WHERE gen=0"));
        $base_he = round((float)$eh['avg_he'], 4);
        $base_ne = round((float)$eh['avg_ne'], 4);

        $na_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT AVG(allele_count) AS avg_na FROM (
                SELECT locus_id, COUNT(DISTINCT str) AS allele_count
                FROM frq_gdx_ir{$breed_sfx} WHERE gen = 0
                GROUP BY locus_id
            ) AS locus_counts"));
        $base_na = round((float)$na_row['avg_na'], 2);
        $push(sprintf("  Baseline He = %.4f  |  Baseline Na = %.4f", $base_he, $base_na));

        // Copy founders to the strategy tables. Four arms: OI, IR, AGR, RANDOM.
        // `ir` is the SOURCE table (alleles_gdx_ir*) so it is not in this list.
        // MIX and CAR are retired: no longer created, seeded or refreshed.
        // Their old tables are left in place, with their old data.
        $push("[5] Copying into OI, RAN, AGR tables...");
        foreach (array('oi', 'ran', 'agr') as $strat) {
            $at = "alleles_gdx_{$strat}{$breed_sfx}";
            $ft = "frq_gdx_{$strat}{$breed_sfx}";
            $et = "expectedhet_{$strat}{$breed_sfx}";

            mysqli_query($conn, "CREATE TABLE IF NOT EXISTS {$at} LIKE {$a_ir}");
            mysqli_query($conn, "INSERT INTO {$at}(dog_id,vglid,gender,locus_id,str_a,str_b,homozygous,origin_a,origin_b,gen,sire_id,dam_id)
                SELECT dog_id,vglid,gender,locus_id,str_a,str_b,homozygous,origin_a,origin_b,gen,sire_id,dam_id FROM {$a_ir}");

            mysqli_query($conn, "CREATE TABLE IF NOT EXISTS {$ft} LIKE {$f_ir}");
            mysqli_query($conn, "INSERT INTO {$ft}(gen,locus_id,str,count_str_a,count_str_b,count_str_total,frq)
                SELECT gen,locus_id,str,count_str_a,count_str_b,count_str_total,frq FROM {$f_ir}");

            mysqli_query($conn, "CREATE TABLE IF NOT EXISTS {$et} LIKE {$e_ir}");
            mysqli_query($conn, "INSERT INTO {$et}(gen,locus_id,ho,he,fst,numstrs,effective_alleles)
                SELECT gen,locus_id,ho,he,fst,numstrs,effective_alleles FROM {$e_ir}");

            $push("  {$strat}: done.");
        }

        $elapsed = round(microtime(true) - $start, 1);
        $push("=== Complete in {$elapsed}s. Founders ready. ===");

        // -- STEP 6: Score founders and insert into sim_founders --------------
        $push("[6] Scoring founders for sim_founders...");

        // Replicate number = highest existing replicate for this breed + 1.
        // COUNT(DISTINCT ...) + 1 was WRONG: it only equals MAX + 1 while the
        // numbering is perfectly contiguous. Delete a single bad replicate and
        // COUNT + 1 collides with a replicate that already exists.
        $rep_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT IFNULL(MAX(replicate_num), 0) + 1 AS next_rep
             FROM better_bred.{$tbl_founders}
             WHERE breed_suffix = '" . mysqli_real_escape_string($conn, $breed_raw) . "'"));
        $rep_num = (int)$rep_row['next_rep'];

        // Build threshold table from gen=0 frequencies (same logic as run_generation)
        $ftmp = "better_bred.tmp_locus_thr_seed_{$breed_raw}";
        mysqli_query($conn, "DROP TABLE IF EXISTS {$ftmp}");
        mysqli_query($conn, "CREATE TABLE {$ftmp} (
            locus_id INT, numstrs INT, low_thr FLOAT, high_thr FLOAT,
            INDEX locus_id (locus_id))");
        mysqli_query($conn, "INSERT INTO {$ftmp} (locus_id, numstrs, low_thr, high_thr)
            SELECT locus_id, COUNT(*) AS numstrs,
                (0.75/COUNT(*)) AS low_thr, (1.25/COUNT(*)) AS high_thr
            FROM {$f_ir} WHERE gen=0 GROUP BY locus_id");

        // Score each founder: OI, IR, band counts
        // Pull the actual dog_ids that were seeded (avoids array slice mismatch)
        $founder_scores = array();
        $rSeeded = mysqli_query($conn,
            "SELECT DISTINCT dog_id FROM {$a_ir} WHERE gen=0");
        $seeded_ids = array();
        while ($sr = mysqli_fetch_assoc($rSeeded)) { $seeded_ids[] = (int)$sr['dog_id']; }

        foreach ($seeded_ids as $did) {
            $row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT
                    ROUND(((2*SUM(a.homozygous))-SUM(fa.frq+fb.frq))/NULLIF((66-SUM(fa.frq+fb.frq)),0),4) AS ir,
                    IFNULL(
                        SUM(CASE WHEN fa.frq<lt.low_thr THEN 1 ELSE 0 END+CASE WHEN fb.frq<lt.low_thr THEN 1 ELSE 0 END)
                        /NULLIF(SUM(CASE WHEN fa.frq>lt.high_thr THEN 1 ELSE 0 END+CASE WHEN fb.frq>lt.high_thr THEN 1 ELSE 0 END),0)
                    ,0)
                    +(SUM(CASE WHEN fa.frq BETWEEN lt.low_thr AND lt.high_thr THEN 1 ELSE 0 END
                         +CASE WHEN fb.frq BETWEEN lt.low_thr AND lt.high_thr THEN 1 ELSE 0 END)/({$countLoci}*2)) AS oi,
                    SUM(CASE WHEN fa.frq<lt.low_thr THEN 1 ELSE 0 END+CASE WHEN fb.frq<lt.low_thr THEN 1 ELSE 0 END) AS num_lo,
                    SUM(CASE WHEN fa.frq BETWEEN lt.low_thr AND lt.high_thr THEN 1 ELSE 0 END
                        +CASE WHEN fb.frq BETWEEN lt.low_thr AND lt.high_thr THEN 1 ELSE 0 END) AS num_mid,
                    SUM(CASE WHEN fa.frq>lt.high_thr THEN 1 ELSE 0 END+CASE WHEN fb.frq>lt.high_thr THEN 1 ELSE 0 END) AS num_hi,
                    MAX(a.gender) AS gender
                FROM {$a_ir} a
                JOIN {$f_ir} fa ON fa.locus_id=a.locus_id AND ROUND(fa.str,1)=ROUND(a.str_a,1) AND fa.gen=0
                JOIN {$f_ir} fb ON fb.locus_id=a.locus_id AND ROUND(fb.str,1)=ROUND(a.str_b,1) AND fb.gen=0
                JOIN {$ftmp} lt ON lt.locus_id=a.locus_id
                WHERE a.dog_id={$did} AND a.gen=0"));
            if ($row) {
                $founder_scores[$did] = array(
                    'oi'     => (float)$row['oi'],
                    'ir'     => (float)$row['ir'],
                    'num_lo' => (int)$row['num_lo'],
                    'num_mid'=> (int)$row['num_mid'],
                    'num_hi' => (int)$row['num_hi'],
                    'gender' => $row['gender'],
                    'agr'    => 0.0
                );
            }
        }

        mysqli_query($conn, "DROP TABLE IF EXISTS {$ftmp}");

        // Compute AGR: Wang GR of each founder against all other founders
        $wang_seed = bb_sim_ii_wang_letters($conn, $f_ir, 0, $countLoci);
        if ($wang_seed) {
            // Load alleles for all founders
            $founder_alleles = array();
            $rAll = mysqli_query($conn,
                "SELECT dog_id, locus_id, str_a, str_b FROM {$a_ir} WHERE gen=0");
            while ($fa = mysqli_fetch_assoc($rAll)) {
                $did = (int)$fa['dog_id'];
                $lid = (int)$fa['locus_id'];
                if (!isset($founder_alleles[$did])) { $founder_alleles[$did] = array(); }
                $founder_alleles[$did][$lid] = array((float)$fa['str_a'], (float)$fa['str_b']);
            }

            // Mean GR of each founder against all others
            $dog_ids_arr = array_keys($founder_alleles);
            $n_founders  = count($dog_ids_arr);
            foreach ($dog_ids_arr as $did) {
                if (!isset($founder_scores[$did])) { continue; }
                $gr_sum = 0.0;
                foreach ($dog_ids_arr as $other_id) {
                    if ($other_id === $did) { continue; }
                    $gr_sum += bb_sim_ii_compute_gr(
                        $founder_alleles[$did],
                        $founder_alleles[$other_id],
                        $wang_seed, $countLoci
                    );
                }
                $founder_scores[$did]['agr'] = ($n_founders > 1)
                    ? round($gr_sum / ($n_founders - 1), 6)
                    : 0.0;
            }
        }

        // Batch INSERT into sim_founders
        if (!empty($founder_scores)) {
            $vals = array();
            foreach ($founder_scores as $did => $sc) {
                $g   = mysqli_real_escape_string($conn, $sc['gender']);
                $br  = mysqli_real_escape_string($conn, $breed_raw);
                $vals[] = "({$rep_num}, '{$br}', {$did}, '{$g}', "
                    . $sc['oi'] . ', ' . $sc['ir'] . ', ' . $sc['agr'] . ', '
                    . $sc['num_lo'] . ', ' . $sc['num_mid'] . ', ' . $sc['num_hi'] . ')';
            }
            mysqli_query($conn,
                "INSERT INTO {$tbl_founders}
                 (replicate_num, breed_suffix, dog_id, gender, oi, ir, agr,
                  num_lo_alleles, num_mid_alleles, num_hi_alleles)
                 VALUES " . implode(',', $vals));
            $err = mysqli_error($conn);
            if ($err) { $push("  WARNING: sim_founders insert error: {$err}", 'warn'); }
            else { $push("  Inserted " . count($vals) . " founders as replicate #{$rep_num}."); }
        }

        mysqli_close($conn);
        echo json_encode(array(
            'success'     => true,
            'log'         => $log,
            'baseline_he' => $base_he,
            'baseline_ne' => $base_ne,
            'baseline_na' => $base_na,
            'pop_size'    => $pop_size,
            'loci'          => $countLoci,
            'run_suffix'    => $run_suffix,
            // The replicate number this seed just claimed. The batch runner
            // needs it to verify the right replicate; nothing else knows it.
            'replicate_num' => $rep_num
        )); exit;
    }

    // ========================================================================
    // MODE B: RUN ONE GENERATION
    // ========================================================================
    if ($sub_action === 'run_generation') {

        $strategy = strtoupper(trim($_POST['strategy'] ?? ''));
        if (!in_array($strategy, $allowed_strategies)) {
            echo json_encode(array('success' => false, 'error' => 'Invalid strategy: ' . htmlspecialchars($strategy))); exit;
        }

        $run_suffix = $run_suffix_raw;
        if (empty($run_suffix)) {
            echo json_encode(array('success' => false, 'error' => 'run_suffix required. Seed founders first or select a run.')); exit;
        }
        $tbl_progress   = 'sim1_progress_'   . $run_suffix;
        $tbl_replicates = 'sim1_replicates_' . $run_suffix;
        $tbl_founders   = 'sim1_founders_'   . $run_suffix;
        // Progress table is created by the Azure function on first generation run.
        // Check alleles table instead -- that's the true "not seeded" signal.
        $sfx_map = array('OI' => 'oi', 'IR' => 'ir', 'RANDOM' => 'ran', 'AGR' => 'agr');
        $s       = $sfx_map[$strategy] . $breed_sfx;

        $alleles_check = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS ct FROM information_schema.tables
             WHERE table_schema='better_bred' AND table_name='alleles_gdx_{$s}'"));
        if ((int)$alleles_check['ct'] === 0) {
            echo json_encode(array('success' => false,
                'error' => "alleles_gdx_{$s} not found. Seed founders first.")); exit;
        }

        // RESTART LOGIC: progress table is the source of truth for completed generations.
        // If alleles_gdx is ahead of the last confirmed progress row, a generation started
        // but did not finish (crash mid-write). Roll it back before proceeding.
        $alleles_max_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT IFNULL(MAX(gen),-1) AS mg FROM alleles_gdx_{$s}"));
        $alleles_max = (int)$alleles_max_row['mg'];

        if ($alleles_max < 0) {
            echo json_encode(array('success' => false,
                'error' => "alleles_gdx_{$s} is empty. Seed founders first.")); exit;
        }

        // Get max confirmed gen from progress for the in-progress replicate.
        // Completed replicates have replicate_num backfilled, so IS NULL targets
        // only the currently running replicate.
        $tbl_progress_chk = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS ct FROM information_schema.tables
             WHERE table_schema='better_bred' AND table_name='{$tbl_progress}'"));
        $progress_max = 0;
        if ((int)$tbl_progress_chk['ct'] > 0) {
            $prog_row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT IFNULL(MAX(gen),0) AS mg FROM {$tbl_progress}
                 WHERE strategy='" . mysqli_real_escape_string($conn, $strategy) . "'
                   AND breed_suffix='" . mysqli_real_escape_string($conn, $breed_raw) . "'
                   AND replicate_num IS NULL"));
            $progress_max = (int)$prog_row['mg'];
        }

        // Detect completed-but-not-yet-truncated: alleles at 20, no in-progress rows.
        if ($alleles_max >= 20 && $progress_max === 0) {
            $rep_ct = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) AS ct FROM {$tbl_replicates}
                 WHERE strategy='" . mysqli_real_escape_string($conn, $strategy) . "'
                   AND breed_suffix='" . mysqli_real_escape_string($conn, $breed_raw) . "'"));
            echo json_encode(array('success' => false,
                'error' => 'Replicate complete. Truncate tables before starting next replicate.',
                'completed_replicates' => (int)$rep_ct['ct'])); exit;
        }

        // Rollback any partial generation (alleles written, progress not yet recorded).
        if ($alleles_max > $progress_max) {
            mysqli_query($conn, "DELETE FROM alleles_gdx_{$s} WHERE gen > {$progress_max}");
            mysqli_query($conn, "DELETE FROM frq_gdx_{$s} WHERE gen > {$progress_max}");
            mysqli_query($conn, "DELETE FROM expectedhet_{$s} WHERE gen > {$progress_max}");
            $pup_chk = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) AS ct FROM information_schema.tables
                 WHERE table_schema='better_bred' AND table_name='puppies_gdx_{$s}'"));
            if ((int)$pup_chk['ct'] > 0) {
                mysqli_query($conn, "DELETE FROM puppies_gdx_{$s} WHERE gen > {$progress_max}");
            }
            $avg_chk = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) AS ct FROM information_schema.tables
                 WHERE table_schema='better_bred' AND table_name='pup_avgs_gdx_{$s}'"));
            if ((int)$avg_chk['ct'] > 0) {
                mysqli_query($conn, "DELETE FROM pup_avgs_gdx_{$s} WHERE gen > {$progress_max}");
            }
        }

        $parentGen = $progress_max;
        $gen       = $parentGen + 1;

        if ($gen > 20) {
            echo json_encode(array('success' => false,
                'error' => 'All 20 generations complete. Truncate tables to start next replicate.')); exit;
        }

        $countLoci = 33;
        $loci_actual = (int)mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(DISTINCT locus_id) AS ct FROM alleles_gdx_{$s}"))['ct'];
        if ($loci_actual !== 33) {
            echo json_encode(array('success' => false,
                'error' => "Expected 33 loci in alleles_gdx_{$s}, found {$loci_actual}.")); exit;
        }

        // STEP 1: Ensure frq table exists
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS frq_gdx_{$s} (
            id INT AUTO_INCREMENT, gen INT, locus_id INT, str FLOAT(4,1),
            count_str_a INT NOT NULL DEFAULT 0, count_str_b INT NOT NULL DEFAULT 0,
            count_str_total INT, frq FLOAT(6,5),
            PRIMARY KEY(id), INDEX gen(gen), INDEX locus(locus_id),
            INDEX gen_locus(gen,locus_id), INDEX spec_str(gen,locus_id,str),
            INDEX spec_frq(gen,locus_id,str,frq))");

        // STEP 2: Populate frq for parentGen if needed
        $frq_ct = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS ct FROM frq_gdx_{$s} WHERE gen={$parentGen}"));
        if ((int)$frq_ct['ct'] === 0) {
            mysqli_query($conn, "INSERT INTO frq_gdx_{$s}(locus_id,str,gen)
                SELECT DISTINCT locus_id,str_a AS str,{$parentGen} FROM alleles_gdx_{$s} WHERE gen={$parentGen}
                UNION
                SELECT DISTINCT locus_id,str_b AS str,{$parentGen} FROM alleles_gdx_{$s} WHERE gen={$parentGen}
                ORDER BY locus_id,str");

            $slot_ct = (int)mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(DISTINCT dog_id)*2 AS ct FROM alleles_gdx_{$s} WHERE gen={$parentGen}"))['ct'];

            mysqli_query($conn, "CREATE TABLE IF NOT EXISTS better_bred.str_a_{$s} (id INT AUTO_INCREMENT PRIMARY KEY,gen INT,locus_id INT,str_a FLOAT,ct INT)");
            mysqli_query($conn, "INSERT INTO better_bred.str_a_{$s}(gen,locus_id,str_a,ct) SELECT {$parentGen},locus_id,str_a,COUNT(str_a) FROM alleles_gdx_{$s} WHERE gen={$parentGen} GROUP BY locus_id,str_a");
            mysqli_query($conn, "UPDATE frq_gdx_{$s} f JOIN better_bred.str_a_{$s} s ON s.locus_id=f.locus_id AND s.str_a=f.str SET f.count_str_a=s.ct WHERE f.gen={$parentGen} AND f.id>0");
            mysqli_query($conn, "DROP TABLE better_bred.str_a_{$s}");
            mysqli_query($conn, "CREATE TABLE IF NOT EXISTS better_bred.str_b_{$s} (id INT AUTO_INCREMENT PRIMARY KEY,gen INT,locus_id INT,str_b FLOAT,ct INT)");
            mysqli_query($conn, "INSERT INTO better_bred.str_b_{$s}(gen,locus_id,str_b,ct) SELECT {$parentGen},locus_id,str_b,COUNT(str_b) FROM alleles_gdx_{$s} WHERE gen={$parentGen} GROUP BY locus_id,str_b");
            mysqli_query($conn, "UPDATE frq_gdx_{$s} f JOIN better_bred.str_b_{$s} s ON s.locus_id=f.locus_id AND s.str_b=f.str SET f.count_str_b=s.ct WHERE f.gen={$parentGen} AND f.id>0");
            mysqli_query($conn, "DROP TABLE better_bred.str_b_{$s}");
            mysqli_query($conn, "UPDATE frq_gdx_{$s} SET count_str_total=(count_str_a+count_str_b) WHERE gen={$parentGen} AND id>0");
            mysqli_query($conn, "UPDATE frq_gdx_{$s} SET frq=(count_str_total/{$slot_ct}) WHERE gen={$parentGen} AND id>0");
        }

        // STEP 3: expectedhet for parentGen
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS expectedhet_{$s} (
            id INT AUTO_INCREMENT PRIMARY KEY, gen INT, locus_id INT,
            ho FLOAT(5,4), he FLOAT(5,4), fis FLOAT(6,4), fst FLOAT(5,4), numstrs INT,
            effective_alleles DECIMAL(6,4),
            INDEX gen(gen), INDEX locus(locus_id), INDEX gen_locus(gen,locus_id))");

        $eh_ct = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS ct FROM expectedhet_{$s} WHERE gen={$parentGen}"));
        if ((int)$eh_ct['ct'] === 0) {
            mysqli_query($conn, "INSERT INTO expectedhet_{$s}(gen,locus_id,he,numstrs)
                SELECT gen,locus_id,(1-SUM(frq*frq)) AS he,COUNT(str) AS numstrs
                FROM frq_gdx_{$s} WHERE gen={$parentGen} GROUP BY gen,locus_id");
            mysqli_query($conn, "UPDATE expectedhet_{$s} SET effective_alleles=1/(1-he) WHERE gen={$parentGen} AND id>=0");
        }

        // STEP 4: Get parent lists
        $rS = mysqli_query($conn, "SELECT DISTINCT dog_id FROM alleles_gdx_{$s} WHERE gender='M' AND gen={$parentGen}");
        $rD = mysqli_query($conn, "SELECT DISTINCT dog_id FROM alleles_gdx_{$s} WHERE gender='F' AND gen={$parentGen}");
        $sirecollection = array();
        $damcollection  = array();
        while ($r = mysqli_fetch_assoc($rS)) { $sirecollection[] = (int)$r['dog_id']; }
        while ($r = mysqli_fetch_assoc($rD)) { $damcollection[]  = (int)$r['dog_id']; }

        $pairCount = min(count($sirecollection), count($damcollection));
        if ($pairCount === 0) {
            echo json_encode(array('success' => false, 'error' => 'No parent pairs for gen ' . $parentGen)); exit;
        }

        shuffle($sirecollection);
        shuffle($damcollection);

        // STEP 5: Generate puppies (10 per litter)
        $puppies_table = "puppies_gdx_{$s}";
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS {$puppies_table} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gen INT, litter_id INT, sire_id INT, dam_id INT, puppy_id INT,
            locus_id INT, str_a FLOAT, str_b FLOAT, homozygous TINYINT,
            INDEX gen_litter(gen,litter_id), INDEX puppy(gen,puppy_id))");
        mysqli_query($conn, "DELETE FROM {$puppies_table} WHERE gen={$gen}");

        $puppy_id_counter = 1;
        for ($k = 0; $k < $pairCount; $k++) {
            $sireId = $sirecollection[$k];
            $damId  = $damcollection[$k];
            // 10 puppies per litter
            for ($p = 0; $p < 10; $p++) {
                $pup_id = $puppy_id_counter++;
                mysqli_query($conn, "
                    INSERT INTO {$puppies_table}
                        (gen,litter_id,sire_id,dam_id,puppy_id,locus_id,str_a,str_b,homozygous)
                    SELECT
                        {$gen},{$k},{$sireId},{$damId},{$pup_id},
                        dog1.locus_id,
                        IF(RAND()<0.5, dog1.str_a, dog1.str_b) AS str_a,
                        IF(RAND()<0.5, dog2.str_a, dog2.str_b) AS str_b,
                        0
                    FROM (SELECT locus_id,str_a,str_b FROM alleles_gdx_{$s} WHERE dog_id={$sireId} AND gen={$parentGen}) AS dog1
                    LEFT JOIN (SELECT locus_id,str_a,str_b FROM alleles_gdx_{$s} WHERE dog_id={$damId} AND gen={$parentGen}) AS dog2
                        ON dog2.locus_id=dog1.locus_id");
                mysqli_query($conn, "UPDATE {$puppies_table}
                    SET homozygous=IF(str_a=str_b,1,0)
                    WHERE gen={$gen} AND puppy_id={$pup_id}");
            }
        }

        // STEP 6: Score all puppies with OI/IR/band counts (pup_avgs)
        mysqli_query($conn, "DELETE FROM pup_avgs_gdx_{$s} WHERE gen={$gen}");
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS pup_avgs_gdx_{$s} (
            id INT AUTO_INCREMENT PRIMARY KEY, gen INT, litter_id INT,
            sire_id INT, dam_id INT, puppy_id INT,
            ir DECIMAL(10,5), hl DECIMAL(10,5), oi DECIMAL(10,5),
            infr_als INT, norm_als INT, hfreq_als INT,
            rarehet INT, rarehom INT, normhom INT, comhet INT, comhom INT, mixedhet INT,
            unusual_anc DECIMAL(10,5), typical_anc DECIMAL(10,5),
            balanced_anc DECIMAL(10,5), mixed_anc DECIMAL(10,5), pcho DECIMAL(10,4),
            INDEX gen(gen), INDEX litter(litter_id), INDEX parents(sire_id,dam_id),
            INDEX puppy(puppy_id), INDEX spec_pup(gen,litter_id,puppy_id), INDEX ir(ir))");

        // Build threshold table (relative: low_thr = 0.75/N, high_thr = 1.25/N)
        mysqli_query($conn, "DROP TABLE IF EXISTS better_bred.tmp_locus_thr_{$s}");
        mysqli_query($conn, "CREATE TABLE better_bred.tmp_locus_thr_{$s} (
            locus_id INT, numstrs INT, low_thr FLOAT, high_thr FLOAT,
            INDEX locus_id (locus_id))");
        mysqli_query($conn, "INSERT INTO better_bred.tmp_locus_thr_{$s} (locus_id, numstrs, low_thr, high_thr)
            SELECT locus_id, COUNT(*) AS numstrs,
                (0.75/COUNT(*)) AS low_thr, (1.25/COUNT(*)) AS high_thr
            FROM frq_gdx_{$s} WHERE gen={$parentGen} GROUP BY locus_id");

        mysqli_query($conn, "
        INSERT INTO pup_avgs_gdx_{$s}
            (gen,litter_id,sire_id,dam_id,puppy_id,ir,oi,hl,pcho,
             infr_als,norm_als,hfreq_als,rarehet,rarehom,normhom,comhet,comhom,mixedhet)
        SELECT p.gen,p.litter_id,p.sire_id,p.dam_id,p.puppy_id,
            ROUND(((2*SUM(p.homozygous))-SUM(fa.frq+fb.frq))/NULLIF((66-SUM(fa.frq+fb.frq)),0),2),
            IFNULL(
                SUM(CASE WHEN fa.frq<lt.low_thr THEN 1 ELSE 0 END+CASE WHEN fb.frq<lt.low_thr THEN 1 ELSE 0 END)
                /NULLIF(SUM(CASE WHEN fa.frq>lt.high_thr THEN 1 ELSE 0 END+CASE WHEN fb.frq>lt.high_thr THEN 1 ELSE 0 END),0)
            ,0)
            +(SUM(CASE WHEN fa.frq BETWEEN lt.low_thr AND lt.high_thr THEN 1 ELSE 0 END
                 +CASE WHEN fb.frq BETWEEN lt.low_thr AND lt.high_thr THEN 1 ELSE 0 END)/({$countLoci}*2)),
            SUM(CASE WHEN p.homozygous=1 THEN eh.he ELSE 0 END)/NULLIF(SUM(eh.he),0),
            (SUM(p.homozygous)/{$countLoci})*100,
            SUM(CASE WHEN fa.frq<lt.low_thr THEN 1 ELSE 0 END+CASE WHEN fb.frq<lt.low_thr THEN 1 ELSE 0 END),
            SUM(CASE WHEN fa.frq BETWEEN lt.low_thr AND lt.high_thr THEN 1 ELSE 0 END+CASE WHEN fb.frq BETWEEN lt.low_thr AND lt.high_thr THEN 1 ELSE 0 END),
            SUM(CASE WHEN fa.frq>lt.high_thr THEN 1 ELSE 0 END+CASE WHEN fb.frq>lt.high_thr THEN 1 ELSE 0 END),
            SUM(CASE WHEN (fa.frq<lt.low_thr AND fb.frq BETWEEN lt.low_thr AND lt.high_thr) OR (fb.frq<lt.low_thr AND fa.frq BETWEEN lt.low_thr AND lt.high_thr) THEN 1 ELSE 0 END),
            SUM(CASE WHEN fa.frq<lt.low_thr AND fb.frq<lt.low_thr THEN 1 ELSE 0 END),
            SUM(CASE WHEN fa.frq BETWEEN lt.low_thr AND lt.high_thr AND fb.frq BETWEEN lt.low_thr AND lt.high_thr THEN 1 ELSE 0 END),
            SUM(CASE WHEN (fa.frq>lt.high_thr AND fb.frq BETWEEN lt.low_thr AND lt.high_thr) OR (fb.frq>lt.high_thr AND fa.frq BETWEEN lt.low_thr AND lt.high_thr) THEN 1 ELSE 0 END),
            SUM(CASE WHEN fa.frq>lt.high_thr AND fb.frq>lt.high_thr THEN 1 ELSE 0 END),
            SUM(CASE WHEN (fa.frq<lt.low_thr AND fb.frq>lt.high_thr) OR (fb.frq<lt.low_thr AND fa.frq>lt.high_thr) THEN 1 ELSE 0 END)
        FROM {$puppies_table} p
        JOIN frq_gdx_{$s} fa ON fa.locus_id=p.locus_id AND ROUND(fa.str,1)=ROUND(p.str_a,1) AND fa.gen={$parentGen}
        JOIN frq_gdx_{$s} fb ON fb.locus_id=p.locus_id AND ROUND(fb.str,1)=ROUND(p.str_b,1) AND fb.gen={$parentGen}
        JOIN better_bred.tmp_locus_thr_{$s} lt ON lt.locus_id=p.locus_id
        JOIN expectedhet_{$s} eh ON eh.locus_id=p.locus_id AND eh.gen={$parentGen}
        WHERE p.gen={$gen}
        GROUP BY p.gen,p.litter_id,p.sire_id,p.dam_id,p.puppy_id");

        $pup_avgs_ct = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS ct FROM pup_avgs_gdx_{$s} WHERE gen={$gen}"));
        if ((int)$pup_avgs_ct['ct'] === 0) {
            $pup_ct = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS ct FROM {$puppies_table} WHERE gen={$gen}"));
            $thr_ct = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS ct FROM better_bred.tmp_locus_thr_{$s}"));
            $eh_ct2 = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS ct FROM expectedhet_{$s} WHERE gen={$parentGen}"));
            $debug  = array(
                'puppies_gen'   => (int)$pup_ct['ct'],
                'tmp_locus_thr' => (int)$thr_ct['ct'],
                'expectedhet'   => (int)$eh_ct2['ct'],
                'parentGen'     => $parentGen,
                'gen'           => $gen,
                'strategy'      => $strategy,
                'suffix'        => $s,
                'mysql_error'   => mysqli_error($conn)
            );
            echo json_encode(array('success' => false, 'error' => 'pup_avgs empty after INSERT', 'debug' => $debug)); exit;
        }

        mysqli_query($conn, "UPDATE pup_avgs_gdx_{$s}
            SET unusual_anc=((rarehom+rarehet)/{$countLoci})*100,
                typical_anc=((comhom+comhet)/{$countLoci})*100,
                balanced_anc=(normhom/{$countLoci})*100,
                mixed_anc=(mixedhet/{$countLoci})*100
            WHERE gen={$gen}");
        mysqli_query($conn, "DROP TABLE IF EXISTS better_bred.tmp_locus_thr_{$s}");

        // STEP 7: Pre-loop setup for AGR and CAR
        $selectedPuppyIds            = array();
        $pup_alleles                 = array();  // [puppy_id => [locus_id => [str_a, str_b]]]
        $wang_agr                    = null;
        $parent_alleles_agr          = array();  // [dog_id => [locus_id => [str_a, str_b]]] parent gen
        $car_keeper_alleles_by_locus = array();  // [locus_id => [str_val => true]]
        $frq_lookup                  = array();  // [locus_id => [str_val => frq]] for CAR

        if ($strategy === 'AGR' || $strategy === 'CAR') {
            $rPupAll = mysqli_query($conn,
                "SELECT puppy_id, locus_id, str_a, str_b FROM {$puppies_table} WHERE gen={$gen}");
            while ($pa = mysqli_fetch_assoc($rPupAll)) {
                $pid = (int)$pa['puppy_id'];
                $lid = (int)$pa['locus_id'];
                if (!isset($pup_alleles[$pid])) { $pup_alleles[$pid] = array(); }
                $pup_alleles[$pid][$lid] = array((float)$pa['str_a'], (float)$pa['str_b']);
            }
        }

        if ($strategy === 'AGR') {
            $wang_agr = bb_sim_ii_wang_letters($conn, "frq_gdx_{$s}", $parentGen, $countLoci);
            if (!$wang_agr) {
                echo json_encode(array('success' => false, 'error' => 'Wang letters failed for AGR strategy.')); exit;
            }
            // Load full parent generation alleles -- each puppy scored against all 200 parents
            $rParAll = mysqli_query($conn,
                "SELECT dog_id, locus_id, str_a, str_b FROM alleles_gdx_{$s} WHERE gen={$parentGen}");
            while ($pa = mysqli_fetch_assoc($rParAll)) {
                $did = (int)$pa['dog_id'];
                $lid = (int)$pa['locus_id'];
                if (!isset($parent_alleles_agr[$did])) { $parent_alleles_agr[$did] = array(); }
                $parent_alleles_agr[$did][$lid] = array((float)$pa['str_a'], (float)$pa['str_b']);
            }
        }

        if ($strategy === 'CAR') {
            $rFrq = mysqli_query($conn,
                "SELECT locus_id, str, frq FROM frq_gdx_{$s} WHERE gen={$parentGen}");
            while ($fr = mysqli_fetch_assoc($rFrq)) {
                $lid = (int)$fr['locus_id'];
                if (!isset($frq_lookup[$lid])) { $frq_lookup[$lid] = array(); }
                $frq_lookup[$lid][(string)(float)$fr['str']] = (float)$fr['frq'];
            }
        }

        // STEP 8: Keeper selection loop
        // Each litter: 10 puppies split randomly into 2 groups of 5.
        // Best from each group selected by strategy criterion.
        // Sex randomly assigned to the two keepers.
        $maxIdRow  = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT IFNULL(MAX(dog_id),0) AS mx FROM alleles_gdx_{$s} WHERE gen={$gen}"));
        $nextDogId = (int)$maxIdRow['mx'] + 1;

        for ($k = 0; $k < $pairCount; $k++) {

            // Get all 10 puppy_ids for this litter
            $rLitterPups = mysqli_query($conn,
                "SELECT puppy_id FROM pup_avgs_gdx_{$s} WHERE litter_id={$k} AND gen={$gen}");
            $litterPupIds = array();
            while ($lp = mysqli_fetch_assoc($rLitterPups)) {
                $litterPupIds[] = (int)$lp['puppy_id'];
            }
            if (count($litterPupIds) < 2) {
                mysqli_close($conn);
                echo json_encode(array('success' => false,
                    'error' => "Litter {$k} gen {$gen} strategy {$strategy}: only " . count($litterPupIds) . " scored puppies. Check ROUND precision in pup_avgs JOIN.",
                    'litter_id' => $k, 'gen' => $gen, 'strategy' => $strategy
                )); exit;
            }

            // Random 5/5 split
            shuffle($litterPupIds);
            $half   = (int)floor(count($litterPupIds) / 2);
            $group1 = array_slice($litterPupIds, 0, $half);
            $group2 = array_slice($litterPupIds, $half);

            $chosen1 = null;
            $chosen2 = null;

            // --- Select from group 1 ---
            $g1_csv = implode(',', array_map('intval', $group1));

            if ($strategy === 'OI' || $strategy === 'MIX') {
                $r = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT puppy_id FROM pup_avgs_gdx_{$s} WHERE litter_id={$k} AND gen={$gen} AND puppy_id IN ({$g1_csv}) ORDER BY oi DESC LIMIT 1"));
                $chosen1 = $r ? (int)$r['puppy_id'] : (int)$group1[0];
            } elseif ($strategy === 'IR') {
                $r = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT puppy_id FROM pup_avgs_gdx_{$s} WHERE litter_id={$k} AND gen={$gen} AND puppy_id IN ({$g1_csv}) ORDER BY ir ASC LIMIT 1"));
                $chosen1 = $r ? (int)$r['puppy_id'] : (int)$group1[0];
            } elseif ($strategy === 'RANDOM') {
                $r = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT puppy_id FROM pup_avgs_gdx_{$s} WHERE litter_id={$k} AND gen={$gen} AND puppy_id IN ({$g1_csv}) ORDER BY RAND() LIMIT 1"));
                $chosen1 = $r ? (int)$r['puppy_id'] : (int)$group1[0];
            } elseif ($strategy === 'AGR') {
                // Score each candidate against full parent generation; lowest mean GR wins.
                // Random default if pup_alleles missing (should not occur in practice).
                $shuffled1 = $group1;
                shuffle($shuffled1);
                $best_pup = (int)$shuffled1[0];
                $best_mgr = PHP_FLOAT_MAX;
                foreach ($group1 as $cand_pid) {
                    if (!isset($pup_alleles[$cand_pid])) { continue; }
                    $gr_sum = 0.0; $gr_cnt = 0;
                    foreach ($parent_alleles_agr as $par_alleles) {
                        $gr_sum += bb_sim_ii_compute_gr($pup_alleles[$cand_pid], $par_alleles, $wang_agr, $countLoci);
                        $gr_cnt++;
                    }
                    $mean_gr = ($gr_cnt > 0) ? ($gr_sum / $gr_cnt) : 0.0;
                    if ($mean_gr < $best_mgr) { $best_mgr = $mean_gr; $best_pup = $cand_pid; }
                }
                $chosen1 = $best_pup;
            } elseif ($strategy === 'CAR') {
                $best_pup   = (int)$group1[0];
                $best_score = -1.0;
                foreach ($group1 as $cand_pid) {
                    if (!isset($pup_alleles[$cand_pid])) { continue; }
                    $score = 0.0;
                    foreach ($pup_alleles[$cand_pid] as $lid => $ab) {
                        $pool = isset($car_keeper_alleles_by_locus[$lid]) ? $car_keeper_alleles_by_locus[$lid] : array();
                        foreach (array((string)$ab[0], (string)$ab[1]) as $allele_str) {
                            if (!isset($pool[$allele_str])) {
                                $frq    = isset($frq_lookup[$lid][$allele_str]) ? $frq_lookup[$lid][$allele_str] : 0.0;
                                $score += (1.0 - $frq);
                            }
                        }
                    }
                    if ($score > $best_score) { $best_score = $score; $best_pup = $cand_pid; }
                }
                $chosen1 = $best_pup;
            } else {
                $chosen1 = (int)$group1[0];
            }

            // --- Select from group 2 ---
            $g2_csv = implode(',', array_map('intval', $group2));

            if ($strategy === 'OI') {
                $r = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT puppy_id FROM pup_avgs_gdx_{$s} WHERE litter_id={$k} AND gen={$gen} AND puppy_id IN ({$g2_csv}) ORDER BY oi DESC LIMIT 1"));
                $chosen2 = $r ? (int)$r['puppy_id'] : (int)$group2[0];
            } elseif ($strategy === 'IR' || $strategy === 'MIX') {
                $r = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT puppy_id FROM pup_avgs_gdx_{$s} WHERE litter_id={$k} AND gen={$gen} AND puppy_id IN ({$g2_csv}) ORDER BY ir ASC LIMIT 1"));
                $chosen2 = $r ? (int)$r['puppy_id'] : (int)$group2[0];
            } elseif ($strategy === 'RANDOM') {
                $r = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT puppy_id FROM pup_avgs_gdx_{$s} WHERE litter_id={$k} AND gen={$gen} AND puppy_id IN ({$g2_csv}) ORDER BY RAND() LIMIT 1"));
                $chosen2 = $r ? (int)$r['puppy_id'] : (int)$group2[0];
            } elseif ($strategy === 'AGR') {
                $shuffled2 = $group2;
                shuffle($shuffled2);
                $best_pup = (int)$shuffled2[0];
                $best_mgr = PHP_FLOAT_MAX;
                foreach ($group2 as $cand_pid) {
                    if (!isset($pup_alleles[$cand_pid])) { continue; }
                    $gr_sum = 0.0; $gr_cnt = 0;
                    foreach ($parent_alleles_agr as $par_alleles) {
                        $gr_sum += bb_sim_ii_compute_gr($pup_alleles[$cand_pid], $par_alleles, $wang_agr, $countLoci);
                        $gr_cnt++;
                    }
                    $mean_gr = ($gr_cnt > 0) ? ($gr_sum / $gr_cnt) : 0.0;
                    if ($mean_gr < $best_mgr) { $best_mgr = $mean_gr; $best_pup = $cand_pid; }
                }
                $chosen2 = $best_pup;
            } elseif ($strategy === 'CAR') {
                $best_pup   = (int)$group2[0];
                $best_score = -1.0;
                foreach ($group2 as $cand_pid) {
                    if (!isset($pup_alleles[$cand_pid])) { continue; }
                    $score = 0.0;
                    foreach ($pup_alleles[$cand_pid] as $lid => $ab) {
                        $pool = isset($car_keeper_alleles_by_locus[$lid]) ? $car_keeper_alleles_by_locus[$lid] : array();
                        foreach (array((string)$ab[0], (string)$ab[1]) as $allele_str) {
                            if (!isset($pool[$allele_str])) {
                                $frq    = isset($frq_lookup[$lid][$allele_str]) ? $frq_lookup[$lid][$allele_str] : 0.0;
                                $score += (1.0 - $frq);
                            }
                        }
                    }
                    if ($score > $best_score) { $best_score = $score; $best_pup = $cand_pid; }
                }
                $chosen2 = $best_pup;
            } else {
                $chosen2 = (int)$group2[0];
            }

            if (!$chosen1 || !$chosen2) { continue; }

            // Random sex assignment to the two selected keepers
            $keeperSex = array('M', 'F');
            shuffle($keeperSex);

            foreach (array($chosen1, $chosen2) as $idx => $chosenPupId) {
                $selectedPuppyIds[] = (int)$chosenPupId;
                $gender = $keeperSex[$idx];
                $dogId  = $nextDogId++;

                mysqli_query($conn, "
                    INSERT INTO alleles_gdx_{$s}
                        (dog_id,gender,locus_id,str_a,str_b,homozygous,gen,sire_id,dam_id)
                    SELECT
                        {$dogId},'{$gender}',locus_id,str_a,str_b,homozygous,{$gen},sire_id,dam_id
                    FROM {$puppies_table}
                    WHERE puppy_id={$chosenPupId} AND litter_id={$k} AND gen={$gen}");

                // Update CAR keeper pool
                if ($strategy === 'CAR' && isset($pup_alleles[$chosenPupId])) {
                    foreach ($pup_alleles[$chosenPupId] as $lid => $ab) {
                        if (!isset($car_keeper_alleles_by_locus[$lid])) {
                            $car_keeper_alleles_by_locus[$lid] = array();
                        }
                        $car_keeper_alleles_by_locus[$lid][(string)$ab[0]] = true;
                        $car_keeper_alleles_by_locus[$lid][(string)$ab[1]] = true;
                    }
                }
            }
        }

        // Hard verification: every generation must have exactly pairCount M + pairCount F.
        $gen_verify = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT SUM(gender='M') AS n_m, SUM(gender='F') AS n_f
             FROM (SELECT DISTINCT dog_id, gender FROM alleles_gdx_{$s} WHERE gen={$gen}) AS dg"));
        $got_m = (int)$gen_verify['n_m'];
        $got_f = (int)$gen_verify['n_f'];
        if ($got_m !== $pairCount || $got_f !== $pairCount) {
            mysqli_close($conn);
            echo json_encode(array('success' => false,
                'error' => "Gen {$gen} keeper count mismatch: expected {$pairCount}M + {$pairCount}F, got {$got_m}M + {$got_f}F.",
                'gen' => $gen, 'strategy' => $strategy
            )); exit;
        }

        // STEP 9: frq + expectedhet for keeper population at $gen
        $keeperSlots = $pairCount * 4;
        $frq_ct2 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS ct FROM frq_gdx_{$s} WHERE gen={$gen}"));
        if ((int)$frq_ct2['ct'] === 0) {
            mysqli_query($conn, "INSERT INTO frq_gdx_{$s}(locus_id,str,gen)
                SELECT DISTINCT locus_id, str, {$gen} AS gen FROM (
                    SELECT DISTINCT locus_id, str_a AS str FROM alleles_gdx_{$s} WHERE gen={$gen}
                    UNION
                    SELECT DISTINCT locus_id, str_b AS str FROM alleles_gdx_{$s} WHERE gen={$gen}
                ) AS calc ORDER BY locus_id, str");
            mysqli_query($conn, "CREATE TABLE IF NOT EXISTS better_bred.str_a_{$s} (id INT AUTO_INCREMENT PRIMARY KEY,gen INT,locus_id INT,str_a FLOAT,ct INT)");
            mysqli_query($conn, "INSERT INTO better_bred.str_a_{$s}(gen,locus_id,str_a,ct) SELECT {$gen},locus_id,str_a,COUNT(str_a) FROM alleles_gdx_{$s} WHERE gen={$gen} GROUP BY locus_id,str_a");
            mysqli_query($conn, "UPDATE frq_gdx_{$s} f JOIN better_bred.str_a_{$s} s ON s.locus_id=f.locus_id AND s.str_a=f.str SET f.count_str_a=s.ct WHERE f.gen={$gen} AND f.id>0");
            mysqli_query($conn, "DROP TABLE better_bred.str_a_{$s}");
            mysqli_query($conn, "CREATE TABLE IF NOT EXISTS better_bred.str_b_{$s} (id INT AUTO_INCREMENT PRIMARY KEY,gen INT,locus_id INT,str_b FLOAT,ct INT)");
            mysqli_query($conn, "INSERT INTO better_bred.str_b_{$s}(gen,locus_id,str_b,ct) SELECT {$gen},locus_id,str_b,COUNT(str_b) FROM alleles_gdx_{$s} WHERE gen={$gen} GROUP BY locus_id,str_b");
            mysqli_query($conn, "UPDATE frq_gdx_{$s} f JOIN better_bred.str_b_{$s} s ON s.locus_id=f.locus_id AND s.str_b=f.str SET f.count_str_b=s.ct WHERE f.gen={$gen} AND f.id>0");
            mysqli_query($conn, "DROP TABLE better_bred.str_b_{$s}");
            mysqli_query($conn, "UPDATE frq_gdx_{$s} SET count_str_total=(count_str_a+count_str_b) WHERE gen={$gen} AND id>0");
            mysqli_query($conn, "UPDATE frq_gdx_{$s} SET frq=(count_str_total/{$keeperSlots}) WHERE gen={$gen} AND id>0");
        }
        $eh_ct3 = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS ct FROM expectedhet_{$s} WHERE gen={$gen}"));
        if ((int)$eh_ct3['ct'] === 0) {
            mysqli_query($conn, "INSERT INTO expectedhet_{$s}(gen,locus_id,he,numstrs)
                SELECT gen,locus_id,(1-SUM(frq*frq)) AS he,COUNT(str) AS numstrs
                FROM frq_gdx_{$s} WHERE gen={$gen} GROUP BY gen,locus_id");
            mysqli_query($conn, "UPDATE expectedhet_{$s} SET effective_alleles=1/(1-he) WHERE gen={$gen} AND id>=0");
        }

        // STEP 10: Mean OI and IR of selected keepers this generation
        $mean_oi = 0.0;
        $mean_ir = 0.0;
        if (!empty($selectedPuppyIds)) {
            $ids_csv = implode(',', array_map('intval', $selectedPuppyIds));
            $krow = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT AVG(oi) AS m_oi, AVG(ir) AS m_ir FROM pup_avgs_gdx_{$s} WHERE gen={$gen} AND puppy_id IN ({$ids_csv})"));
            if ($krow) {
                $mean_oi = round((float)$krow['m_oi'], 6);
                $mean_ir = round((float)$krow['m_ir'], 6);
            }
        }

        // STEP 11: Band proportions from keeper frequency table
        // Uses relative thresholds (0.75/N and 1.25/N) matching OI formula
        $band_infr  = 0.0;
        $band_norm  = 0.0;
        $band_hfreq = 0.0;
        $brow = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT
                AVG(CASE WHEN f.frq < (0.75/lt.numstrs) THEN 1.0 ELSE 0.0 END) AS b_infr,
                AVG(CASE WHEN f.frq >= (0.75/lt.numstrs) AND f.frq <= (1.25/lt.numstrs) THEN 1.0 ELSE 0.0 END) AS b_norm,
                AVG(CASE WHEN f.frq > (1.25/lt.numstrs) THEN 1.0 ELSE 0.0 END) AS b_hfreq
             FROM frq_gdx_{$s} f
             JOIN (SELECT locus_id, COUNT(*) AS numstrs FROM frq_gdx_{$s} WHERE gen={$gen} GROUP BY locus_id) lt
               ON lt.locus_id = f.locus_id
             WHERE f.gen = {$gen}"));
        if ($brow) {
            $band_infr  = round((float)$brow['b_infr'],  6);
            $band_norm  = round((float)$brow['b_norm'],  6);
            $band_hfreq = round((float)$brow['b_hfreq'], 6);
        }

        // STEP 12: Stats
        $stats = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT AVG(he) AS avg_he, AVG(effective_alleles) AS avg_ne
             FROM expectedhet_{$s} WHERE gen={$gen}"));
        $na_gen = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT AVG(allele_count) AS avg_na FROM (
                SELECT locus_id, COUNT(DISTINCT str) AS allele_count
                FROM frq_gdx_{$s} WHERE gen = {$gen}
                GROUP BY locus_id
            ) AS locus_counts"));
        $elapsed     = round(microtime(true) - $start, 1);
        $numBreeders = count($sirecollection) + count($damcollection);

        // STEP 13: Record in sim_progress
        $total_alleles_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS total_alleles FROM frq_gdx_{$s} WHERE gen={$gen}"));
        $total_alleles = (int)$total_alleles_row['total_alleles'];

        mysqli_query($conn, "INSERT INTO {$tbl_progress}
            (strategy,gen,completed_at,elapsed_seconds,avg_he,avg_ne,avg_na,num_breeders,breed_suffix,
             avg_oi,avg_ir,band_infr,band_norm,band_hfreq,total_alleles)
            VALUES ('{$strategy}',{$gen},NOW(),{$elapsed},"
            . (float)$stats['avg_he'] . ","
            . (float)$stats['avg_ne'] . ","
            . (float)$na_gen['avg_na'] . ","
            . $numBreeders . ",'" . mysqli_real_escape_string($conn, $breed_raw) . "',"
            . $mean_oi . "," . $mean_ir . ","
            . $band_infr . "," . $band_norm . "," . $band_hfreq . ","
            . $total_alleles . ")");

        // STEP 14: If gen=20, record completed replicate
        if ($gen === 20) {
            mysqli_query($conn, "CREATE TABLE IF NOT EXISTS {$tbl_replicates} (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                strategy       VARCHAR(10),
                breed_suffix   VARCHAR(5),
                breed_id       INT,
                replicate_num  INT,
                completed_at   DATETIME,
                pop_size       INT,
                loci           INT,
                final_gen      INT NULL,
                gen0_he        FLOAT,
                gen0_ne        FLOAT,
                gen0_na        FLOAT,
                genN_he        FLOAT,
                genN_ne        FLOAT,
                genN_na        FLOAT,
                gen0_agr DOUBLE NULL, genN_agr DOUBLE NULL, agr_delta DOUBLE NULL,
                he_delta       FLOAT,
                ne_delta       FLOAT,
                na_delta       FLOAT,
                INDEX strat_breed (strategy, breed_suffix),
                INDEX breed (breed_id)
            )");

            $gen0_stats = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT AVG(he) AS he, AVG(effective_alleles) AS ne
                 FROM expectedhet_{$s} WHERE gen=0"));
            $gen0_na_row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT AVG(allele_count) AS na FROM (
                    SELECT locus_id, COUNT(DISTINCT str) AS allele_count
                    FROM frq_gdx_{$s} WHERE gen=0 GROUP BY locus_id
                ) AS lc"));

            $g0_he  = round((float)$gen0_stats['he'], 4);
            $g0_ne  = round((float)$gen0_stats['ne'], 4);
            $g0_na  = round((float)$gen0_na_row['na'], 2);
            $g20_he = round((float)$stats['avg_he'], 4);
            $g20_ne = round((float)$stats['avg_ne'], 4);
            $g20_na = round((float)$na_gen['avg_na'], 2);

            $rep_row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*)+1 AS next_rep FROM {$tbl_replicates}
                 WHERE strategy='{$strategy}' AND breed_suffix='" . mysqli_real_escape_string($conn, $breed_raw) . "'"));
            $rep_num = (int)$rep_row['next_rep'];

            // Pull gen=0 OI/IR/bands from sim_founders for this breed replicate
            // (same replicate_num -- founders are shared across strategies)
            // We use the breed's founder replicate that matches the current run.
            // Since all strategies share one seeding, founder rep = same count basis.
            $founder_rep_row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT MAX(replicate_num) AS fr FROM better_bred.{$tbl_founders}
                 WHERE breed_suffix='" . mysqli_real_escape_string($conn, $breed_raw) . "'"));
            $founder_rep = (int)$founder_rep_row['fr'];

            $g0_founder = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT AVG(oi) AS avg_oi, AVG(ir) AS avg_ir
                 FROM better_bred.{$tbl_founders}
                 WHERE breed_suffix='" . mysqli_real_escape_string($conn, $breed_raw) . "'
                   AND replicate_num={$founder_rep}"));

            $g0_oi         = round((float)$g0_founder['avg_oi'],    6);
            $g0_ir         = round((float)$g0_founder['avg_ir'],    6);

            // Gen-0 band composition: identical distinct-allele SQL as the
            // per-generation computation in STEP 13, at gen=0.
            // (The founders table num_lo/mid/hi_alleles are per-dog metrics --
            // the OI ingredients, also shown on each dog's BetterBred page.
            // The population band composition is a different statistic and
            // comes from the frequency tables; a dog-level average must never
            // be stored in these columns.)
            $g0_band_row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT
                    AVG(CASE WHEN f.frq < (0.75/lt.numstrs) THEN 1.0 ELSE 0.0 END) AS b_infr,
                    AVG(CASE WHEN f.frq >= (0.75/lt.numstrs) AND f.frq <= (1.25/lt.numstrs) THEN 1.0 ELSE 0.0 END) AS b_norm,
                    AVG(CASE WHEN f.frq > (1.25/lt.numstrs) THEN 1.0 ELSE 0.0 END) AS b_hfreq
                 FROM frq_gdx_{$s} f
                 JOIN (SELECT locus_id, COUNT(*) AS numstrs FROM frq_gdx_{$s} WHERE gen=0 GROUP BY locus_id) lt
                   ON lt.locus_id = f.locus_id
                 WHERE f.gen = 0"));

            $g0_band_infr  = round((float)$g0_band_row['b_infr'],  6);
            $g0_band_norm  = round((float)$g0_band_row['b_norm'],  6);
            $g0_band_hfreq = round((float)$g0_band_row['b_hfreq'], 6);

            // Total unique alleles at gen=0 and gen=20
            $g0_total_row = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) AS total_alleles FROM frq_gdx_{$s} WHERE gen=0"));
            $g0_total_alleles  = (int)$g0_total_row['total_alleles'];
            $g20_total_alleles = $total_alleles; // already computed in STEP 13

            mysqli_query($conn, "INSERT INTO {$tbl_replicates}
                (strategy, breed_suffix, breed_id, replicate_num, completed_at, pop_size, loci,
                 final_gen,
                 gen0_he, gen0_ne, gen0_na, genN_he, genN_ne, genN_na,
                 he_delta, ne_delta, na_delta,
                 gen0_oi, genN_oi, oi_delta,
                 gen0_ir, genN_ir, ir_delta,
                 gen0_band_infr, genN_band_infr, band_infr_delta,
                 gen0_band_norm, genN_band_norm, band_norm_delta,
                 gen0_band_hfreq, genN_band_hfreq, band_hfreq_delta,
                 gen0_total_alleles, genN_total_alleles, total_alleles_delta)
                VALUES (
                    '{$strategy}',
                    '" . mysqli_real_escape_string($conn, $breed_raw) . "',
                    {$breed_id_post},
                    {$rep_num},
                    NOW(),
                    {$numBreeders},
                    {$countLoci},
                    {$gen},
                    {$g0_he}, {$g0_ne}, {$g0_na},
                    {$g20_he}, {$g20_ne}, {$g20_na},
                    " . round($g20_he - $g0_he, 4) . ",
                    " . round($g20_ne - $g0_ne, 4) . ",
                    " . round($g20_na - $g0_na, 2) . ",
                    {$g0_oi}, {$mean_oi}, " . round($mean_oi - $g0_oi, 6) . ",
                    {$g0_ir}, {$mean_ir}, " . round($mean_ir - $g0_ir, 6) . ",
                    {$g0_band_infr}, {$band_infr}, " . round($band_infr - $g0_band_infr, 6) . ",
                    {$g0_band_norm},  {$band_norm},  " . round($band_norm  - $g0_band_norm,  6) . ",
                    {$g0_band_hfreq}, {$band_hfreq}, " . round($band_hfreq - $g0_band_hfreq, 6) . ",
                    {$g0_total_alleles}, {$g20_total_alleles}, " . ($g20_total_alleles - $g0_total_alleles) . "
                )");

            // Backfill replicate_num into sim_progress for this run
            mysqli_query($conn,
                "UPDATE {$tbl_progress}
                 SET replicate_num = {$rep_num}
                 WHERE strategy='{$strategy}'
                   AND breed_suffix='" . mysqli_real_escape_string($conn, $breed_raw) . "'
                   AND replicate_num IS NULL");

            // Reset puppies for next replicate
            $frq_ct_20 = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) AS ct FROM frq_gdx_{$s} WHERE gen=20"));
            if ((int)$frq_ct_20['ct'] === 0) {
                mysqli_query($conn, "INSERT INTO frq_gdx_{$s}(locus_id,str,gen)
                    SELECT DISTINCT locus_id,str_a AS str,20 FROM alleles_gdx_{$s} WHERE gen=20
                    UNION
                    SELECT DISTINCT locus_id,str_b AS str,20 FROM alleles_gdx_{$s} WHERE gen=20");
                mysqli_query($conn, "CREATE TABLE IF NOT EXISTS better_bred.str_a_{$s} (id INT AUTO_INCREMENT PRIMARY KEY,gen INT,locus_id INT,str_a FLOAT,ct INT)");
                mysqli_query($conn, "INSERT INTO better_bred.str_a_{$s}(gen,locus_id,str_a,ct) SELECT 20,locus_id,str_a,COUNT(str_a) FROM alleles_gdx_{$s} WHERE gen=20 GROUP BY locus_id,str_a");
                mysqli_query($conn, "UPDATE frq_gdx_{$s} f JOIN better_bred.str_a_{$s} s ON s.locus_id=f.locus_id AND s.str_a=f.str SET f.count_str_a=s.ct WHERE f.gen=20 AND f.id>0");
                mysqli_query($conn, "DROP TABLE better_bred.str_a_{$s}");
                mysqli_query($conn, "CREATE TABLE IF NOT EXISTS better_bred.str_b_{$s} (id INT AUTO_INCREMENT PRIMARY KEY,gen INT,locus_id INT,str_b FLOAT,ct INT)");
                mysqli_query($conn, "INSERT INTO better_bred.str_b_{$s}(gen,locus_id,str_b,ct) SELECT 20,locus_id,str_b,COUNT(str_b) FROM alleles_gdx_{$s} WHERE gen=20 GROUP BY locus_id,str_b");
                mysqli_query($conn, "UPDATE frq_gdx_{$s} f JOIN better_bred.str_b_{$s} s ON s.locus_id=f.locus_id AND s.str_b=f.str SET f.count_str_b=s.ct WHERE f.gen=20 AND f.id>0");
                mysqli_query($conn, "DROP TABLE better_bred.str_b_{$s}");
            }
        }

        mysqli_close($conn);
        echo json_encode(array(
            'success'      => true,
            'strategy'     => $strategy,
            'gen'          => $gen,
            'parent_gen'   => $parentGen,
            'elapsed'      => $elapsed,
            'avg_he'       => round((float)$stats['avg_he'], 4),
            'avg_ne'       => round((float)$stats['avg_ne'], 4),
            'avg_na'       => round((float)$na_gen['avg_na'], 2),
            'num_breeders' => $numBreeders,
            'avg_oi'       => round($mean_oi, 4),
            'avg_ir'       => round($mean_ir, 4)
        )); exit;
    }

    // ========================================================================
    // MODE C: TRUNCATE TABLES
    // ========================================================================
    if ($sub_action === 'truncate_tables') {

        // Four arms only: OI, IR, AGR, RANDOM (ran).
        // MIX and CAR are retired. Their tables are NO LONGER CREATED by the
        // seeder, so they must NOT be listed here -- a reset on a freshly seeded
        // breed would hit a table that does not exist. Any pre-existing
        // alleles_gdx_mix* / alleles_gdx_car* tables are left alone with their
        // old data; drop them by hand if you want them gone.
        $tables = array(
            "frq_gdx_oi{$breed_sfx}",     "frq_gdx_ir{$breed_sfx}",
            "frq_gdx_ran{$breed_sfx}",    "frq_gdx_agr{$breed_sfx}",
            "expectedhet_oi{$breed_sfx}", "expectedhet_ir{$breed_sfx}",
            "expectedhet_ran{$breed_sfx}","expectedhet_agr{$breed_sfx}",
            "puppies_gdx_oi{$breed_sfx}", "puppies_gdx_ir{$breed_sfx}",
            "puppies_gdx_ran{$breed_sfx}","puppies_gdx_agr{$breed_sfx}",
            "pup_avgs_gdx_oi{$breed_sfx}","pup_avgs_gdx_ir{$breed_sfx}",
            "pup_avgs_gdx_ran{$breed_sfx}","pup_avgs_gdx_agr{$breed_sfx}",
        );

        $alleles_tables = array(
            "alleles_gdx_oi{$breed_sfx}",  "alleles_gdx_ir{$breed_sfx}",
            "alleles_gdx_ran{$breed_sfx}", "alleles_gdx_agr{$breed_sfx}",
        );

        $dropped   = array();
        $truncated = array();
        $errors    = array();

        foreach ($tables as $tbl) {
            mysqli_query($conn, "DROP TABLE IF EXISTS better_bred.{$tbl}");
            $err = mysqli_error($conn);
            if ($err) { $errors[] = "DROP {$tbl}: {$err}"; }
            else { $dropped[] = $tbl; }
        }

        foreach ($alleles_tables as $tbl) {
            $exists = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) AS ct FROM information_schema.tables
                 WHERE table_schema='better_bred' AND table_name='{$tbl}'"));
            if ((int)$exists['ct'] > 0) {
                mysqli_query($conn, "TRUNCATE TABLE better_bred.{$tbl}");
                $err = mysqli_error($conn);
                if ($err) { $errors[] = "TRUNCATE {$tbl}: {$err}"; }
                else { $truncated[] = $tbl; }
            }
        }

        mysqli_close($conn);
        echo json_encode(array(
            'success'   => empty($errors),
            'dropped'   => $dropped,
            'truncated' => $truncated,
            'errors'    => $errors,
        )); exit;
    }

    // ========================================================================
    // MODE D: ROLLBACK incomplete generation
    // ========================================================================
    if ($sub_action === 'rollback_gen') {
        $strategy = strtoupper(trim($_POST['strategy'] ?? ''));
        if (!in_array($strategy, $allowed_strategies)) {
            echo json_encode(array('success' => false, 'error' => 'Invalid strategy.')); exit;
        }
        $run_suffix   = $run_suffix_raw;
        $tbl_progress = 'sim1_progress_' . $run_suffix;
        $sfx_map = array('OI' => 'oi', 'IR' => 'ir', 'RANDOM' => 'ran', 'AGR' => 'agr');
        $s = $sfx_map[$strategy] . $breed_sfx;

        $last_gen_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX(gen) AS mg FROM alleles_gdx_{$s}"));
        $last_gen = (int)$last_gen_row['mg'];

        if ($last_gen <= 0) {
            echo json_encode(array('success' => false, 'error' => 'Nothing to roll back  -- only gen=0 exists.')); exit;
        }

        $dog_count = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(DISTINCT dog_id) AS ct FROM alleles_gdx_{$s} WHERE gen={$last_gen}"));
        $ct = (int)$dog_count['ct'];

        mysqli_query($conn, "DELETE FROM alleles_gdx_{$s} WHERE gen={$last_gen}");
        mysqli_query($conn, "DELETE FROM puppies_gdx_{$s} WHERE gen={$last_gen}");
        mysqli_query($conn, "DELETE FROM pup_avgs_gdx_{$s} WHERE gen={$last_gen}");
        mysqli_query($conn, "DELETE FROM frq_gdx_{$s} WHERE gen={$last_gen}");
        mysqli_query($conn, "DELETE FROM expectedhet_{$s} WHERE gen={$last_gen}");
        mysqli_query($conn, "DELETE FROM {$tbl_progress} WHERE strategy='{$strategy}' AND gen={$last_gen} AND breed_suffix='" . mysqli_real_escape_string($conn, $breed_raw) . "'");

        $new_max = mysqli_fetch_assoc(mysqli_query($conn, "SELECT MAX(gen) AS mg FROM alleles_gdx_{$s}"));

        mysqli_close($conn);
        echo json_encode(array(
            'success'      => true,
            'rolled_back'  => $last_gen,
            'was_complete' => ($ct >= 190),
            'dog_count'    => $ct,
            'resume_from'  => (int)$new_max['mg'] + 1
        )); exit;
    }

    // ========================================================================
    // MODE E: CLEAR SIM PROGRESS
    // ========================================================================
    if ($sub_action === 'clear_sim_progress') {
        $run_suffix   = $run_suffix_raw;
        $tbl_progress = 'sim1_progress_' . $run_suffix;
        $ex = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS ct FROM information_schema.tables
             WHERE table_schema='better_bred' AND table_name='{$tbl_progress}'"));
        if ((int)$ex['ct'] > 0) {
            mysqli_query($conn, "TRUNCATE TABLE {$tbl_progress}");
        }
        $err = mysqli_error($conn);
        mysqli_close($conn);
        if ($err) {
            echo json_encode(array('success' => false, 'error' => $err)); exit;
        }
        echo json_encode(array('success' => true, 'message' => '{$tbl_progress} cleared.')); exit;
    }

    // ========================================================================
    // MODE F: GET REPLICATES
    // ========================================================================
    if ($sub_action === 'get_replicates') {
        // FIX 1: $tbl_replicates was never assigned on this branch. It is only
        // set inside seed_founders (l.153), run_generation (l.690) and
        // verify_replicate (l.1675), none of which run here. The query was
        // therefore built against an empty table name, mysqli_query returned
        // false, the while loop never ran, and this returned success:true with
        // an empty array -- a silent failure with no error message.
        //
        // FIX 1b: $run_suffix is NOT top-level either. It is assigned inside
        // seed_founders (147/149), run_generation (685), rollback_gen (1503),
        // clear_sim_progress (1542), get_progress (1623) and verify_replicate
        // (1689) -- none of which run on this branch. Only $run_suffix_raw
        // (l.103) is set before the sub_action dispatch. Without the line
        // below, $tbl_replicates resolved to 'sim1_replicates_' with an empty
        // suffix and the information_schema check reported "does not exist".
        $run_suffix = $run_suffix_raw;
        if ($run_suffix === '') {
            mysqli_close($conn);
            echo json_encode(array('success' => false,
                'error' => 'run_suffix required for get_replicates.')); exit;
        }
        $tbl_replicates = 'sim1_replicates_' . $run_suffix;

        // FIX 2: dropped "LEFT JOIN breed b ... b.name AS breed_name". This was
        // the only read of a breed name column anywhere in the file, and the
        // caller does not use breed_name. Removing it also removes the risk of
        // the whole query failing on a column that may not exist.
        $rows = array();

        $chk = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS ct FROM information_schema.tables
             WHERE table_schema='better_bred' AND table_name='{$tbl_replicates}'"));
        if ((int)$chk['ct'] === 0) {
            mysqli_close($conn);
            echo json_encode(array(
                'success'    => true,
                'replicates' => array(),
                'note'       => $tbl_replicates . ' does not exist yet.'
            )); exit;
        }

        $sql = "SELECT * FROM {$tbl_replicates}
                ORDER BY breed_suffix, strategy, replicate_num";
        $result = mysqli_query($conn, $sql);
        if (!$result) {
            // FIX 3: report the failure instead of returning an empty success.
            $err = mysqli_error($conn);
            mysqli_close($conn);
            echo json_encode(array('success' => false,
                'error' => 'get_replicates query failed: ' . $err)); exit;
        }
        while ($row = mysqli_fetch_assoc($result)) { $rows[] = $row; }
        mysqli_close($conn);
        echo json_encode(array('success' => true, 'replicates' => $rows)); exit;
    }

    // ========================================================================
    // MODE G: GET MAX GEN (used by resume)
    // ========================================================================
    if ($sub_action === 'get_max_gen') {
        $strategy = strtoupper(trim($_POST['strategy'] ?? ''));
        if (!in_array($strategy, $allowed_strategies)) {
            echo json_encode(array('success' => false, 'error' => 'Invalid strategy.')); exit;
        }
        $sfx_map = array('OI' => 'oi', 'IR' => 'ir', 'RANDOM' => 'ran', 'AGR' => 'agr');
        $s = $sfx_map[$strategy] . $breed_sfx;
        $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT IFNULL(MAX(gen), 0) AS mg FROM alleles_gdx_{$s}"));
        $max_gen = (int)$row['mg'];
        mysqli_close($conn);
        echo json_encode(array('success' => true, 'max_gen' => $max_gen, 'next_gen' => $max_gen + 1)); exit;
    }

    // ========================================================================
    // MODE H: GET SIM PROGRESS (per-gen avg OI/IR/bands, averaged across replicates)
    // ========================================================================
    if ($sub_action === 'get_progress') {
        $run_suffix   = $run_suffix_raw;
        $tbl_progress = 'sim1_progress_' . $run_suffix;
        $rows = array();
        $result = mysqli_query($conn,
            "SELECT strategy, breed_suffix, gen,
                COUNT(*) AS n_reps,
                ROUND(AVG(avg_he),        4) AS avg_he,
                ROUND(AVG(avg_ne),        4) AS avg_ne,
                ROUND(AVG(avg_na),        2) AS avg_na,
                -- avg_ho / avg_fis exist in sim1_progress_* and are populated by
                -- run_sim_iii, but were never selected here, so the page could not
                -- show Ho or FIS. Added.
                ROUND(AVG(avg_ho),        4) AS avg_ho,
                ROUND(AVG(avg_fis),       4) AS avg_fis,
                ROUND(AVG(avg_oi),        4) AS avg_oi,
                ROUND(AVG(avg_ir),        4) AS avg_ir,
                ROUND(AVG(band_infr),     4) AS band_infr,
                ROUND(AVG(band_norm),     4) AS band_norm,
                ROUND(AVG(band_hfreq),    4) AS band_hfreq,
                ROUND(AVG(total_alleles), 1) AS total_alleles
             FROM {$tbl_progress}
             WHERE breed_suffix = '" . mysqli_real_escape_string($conn, $breed_raw) . "'
             GROUP BY strategy, breed_suffix, gen
             ORDER BY strategy, gen");
        $err = mysqli_error($conn);
        if ($err) {
            mysqli_close($conn);
            echo json_encode(array('success' => false, 'error' => $err)); exit;
        }
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) { $rows[] = $row; }
        }
        mysqli_close($conn);
        echo json_encode(array('success' => true, 'progress' => $rows, 'breed' => $breed_raw, 'count' => count($rows))); exit;
    }

    // ========================================================================
    // MODE I: LIST AVAILABLE RUNS (for resume picker after page refresh)
    // ========================================================================
    if ($sub_action === 'list_runs') {
        $prefix = 'sim1_progress_' . ($breed_raw !== '' ? $breed_raw . '_' : '');
        $like   = mysqli_real_escape_string($conn, $prefix) . '%';
        $result = mysqli_query($conn,
            "SELECT table_name, table_rows, create_time
             FROM information_schema.tables
             WHERE table_schema='better_bred' AND table_name LIKE '{$like}'
             ORDER BY create_time DESC");
        $runs = array();
        while ($r = mysqli_fetch_assoc($result)) {
            $suffix = substr($r['table_name'], strlen('sim1_progress_'));
            $runs[] = array('run_suffix' => $suffix,
                            'rows'       => (int)$r['table_rows'],
                            'created'    => $r['create_time']);
        }
        mysqli_close($conn);
        echo json_encode(array('success' => true, 'runs' => $runs)); exit;
    }

    // ========================================================================
    // MODE: VERIFY REPLICATE
    // Completeness check for one replicate. Row counts alone are not enough:
    // DP replicate 18 had all 20 generation rows for all four strategies and
    // still carried NULL avg_ho / avg_fis right through. This checks both.
    // ========================================================================
    if ($sub_action === 'verify_replicate') {

        $run_suffix = $run_suffix_raw;
        if ($run_suffix === '') {
            echo json_encode(array('success' => false, 'error' => 'run_suffix required.')); exit;
        }

        $rep       = (int)($_POST['replicate_num'] ?? 0);
        $exp_gens  = (int)($_POST['expect_gens'] ?? 20);
        $strat_raw = trim($_POST['expect_strategies'] ?? 'OI,AGR,IR,RANDOM');

        $expect = array();
        foreach (explode(',', $strat_raw) as $s) {
            $s = strtoupper(trim($s));
            if (in_array($s, $allowed_strategies, true)) { $expect[] = $s; }
        }
        if ($rep < 1 || empty($expect)) {
            echo json_encode(array('success' => false, 'error' => 'replicate_num and expect_strategies required.')); exit;
        }

        $tbl_progress   = 'sim1_progress_'   . $run_suffix;
        $tbl_replicates = 'sim1_replicates_' . $run_suffix;

        $found  = array();
        $result = mysqli_query($conn,
            "SELECT strategy,
                    COUNT(*)                                  AS gens,
                    SUM(CASE WHEN avg_ho  IS NULL THEN 1 ELSE 0 END) AS null_ho,
                    SUM(CASE WHEN avg_fis IS NULL THEN 1 ELSE 0 END) AS null_fis
             FROM better_bred.{$tbl_progress}
             WHERE replicate_num = {$rep}
             GROUP BY strategy");
        if (!$result) {
            echo json_encode(array('success' => false, 'error' => 'Progress query failed: ' . mysqli_error($conn))); exit;
        }
        while ($r = mysqli_fetch_assoc($result)) {
            $found[$r['strategy']] = array(
                'gens'     => (int)$r['gens'],
                'null_ho'  => (int)$r['null_ho'],
                'null_fis' => (int)$r['null_fis']
            );
        }

        $rep_row = mysqli_fetch_assoc(mysqli_query($conn,
            "SELECT COUNT(*) AS n FROM better_bred.{$tbl_replicates}
             WHERE replicate_num = {$rep}"));
        $rep_rows = (int)$rep_row['n'];

        $problems = array();
        foreach ($expect as $s) {
            if (!isset($found[$s])) {
                $problems[] = $s . ': MISSING - no progress rows';
                continue;
            }
            $f = $found[$s];
            if ($f['gens'] !== $exp_gens) {
                $problems[] = $s . ': ' . $f['gens'] . ' generation rows, expected ' . $exp_gens;
            }
            if ($f['null_ho']  > 0) { $problems[] = $s . ': ' . $f['null_ho']  . ' NULL avg_ho'; }
            if ($f['null_fis'] > 0) { $problems[] = $s . ': ' . $f['null_fis'] . ' NULL avg_fis'; }
        }
        if ($rep_rows !== count($expect)) {
            $problems[] = 'replicates table has ' . $rep_rows . ' rows, expected ' . count($expect);
        }

        mysqli_close($conn);
        echo json_encode(array(
            'success'       => true,
            'ok'            => empty($problems),
            'replicate_num' => $rep,
            'problems'      => $problems,
            'found'         => $found
        ));
        exit;
    }

    echo json_encode(array('success' => false, 'error' => 'Unknown sub_action.'));
    exit;
}

} // end function_exists guard

// ============================================================================
// STUDY I  -- AZURE API PROXY
// Proxies run_sim requests to bb-sim-functions via server-side curl.
// Avoids CORS -- browser calls admin-ajax.php, PHP calls Azure.
// ============================================================================

add_action('wp_ajax_bb_sim_api', 'bb_sim_api_proxy_handler');

function bb_sim_api_proxy_handler() {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');

    if (!is_user_logged_in() || !current_user_can('edit_posts')) {
        echo json_encode(array('success' => false, 'error' => 'Not authorized.')); exit;
    }

    $allowed_strategies = array('OI', 'IR', 'RANDOM', 'AGR');
    $strategy  = strtoupper(trim($_POST['strategy'] ?? ''));
    $breed_sfx = strtolower(trim($_POST['breed_suffix'] ?? ''));
    $breed_id  = (int)($_POST['breed_id'] ?? 0);

    // run_suffix names this run's permanent tables:
    //   sim1_founders_<run_suffix> / sim1_progress_<run_suffix> / sim1_replicates_<run_suffix>
    // It MUST be forwarded. The engine used to reconstruct it from the current
    // date, which silently breaks across a month boundary (seed in July, run in
    // August, and the rows land in a different table).
    $run_suffix = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['run_suffix'] ?? '')));

    if (!in_array($strategy, $allowed_strategies)) {
        echo json_encode(array('success' => false, 'error' => 'Invalid strategy.')); exit;
    }
    if ($breed_id <= 0) {
        echo json_encode(array('success' => false, 'error' => 'Invalid breed_id.')); exit;
    }
    if ($run_suffix === '') {
        echo json_encode(array('success' => false, 'error' => 'run_suffix required. Seed founders first or select a run.')); exit;
    }

    // Run parameters. Defaults reproduce the original baseline exactly:
    // litter 10, 20 generations, 1:1 sex ratio, random sire draw.
    // If these are not forwarded, the engine silently falls back to defaults
    // and every control on the runner page becomes decorative.
    $litter_size   = (int)($_POST['litter_size']   ?? 10);
    $generations   = (int)($_POST['generations']   ?? 20);
    $sires_per_dam = (int)($_POST['sires_per_dam'] ?? 1);
    $sire_mode     = strtolower(trim($_POST['sire_mode'] ?? 'random'));

    if ($litter_size < 2 || $litter_size > 20) {
        echo json_encode(array('success' => false, 'error' => 'litter_size must be 2-20.')); exit;
    }
    if ($generations < 1 || $generations > 50) {
        echo json_encode(array('success' => false, 'error' => 'generations must be 1-50.')); exit;
    }
    if ($sires_per_dam < 1 || $sires_per_dam > 20) {
        echo json_encode(array('success' => false, 'error' => 'sires_per_dam must be 1-20.')); exit;
    }
    if (!in_array($sire_mode, array('random', 'ordered'), true)) {
        echo json_encode(array('success' => false, 'error' => "sire_mode must be 'random' or 'ordered'.")); exit;
    }

    // Function host key: wp-config constant takes priority; WP option is
    // fallback. Same pattern as bb_calc_ajax_handlers.php. NEVER hardcode
    // the key in this file -- it is deposited publicly with the study data.
    if (defined('BB_API_KEY') && BB_API_KEY !== '') {
        $bb_sim_key = BB_API_KEY;
    } else {
        $bb_sim_key = get_option('bb_calc_api_key', '');
    }
    if ($bb_sim_key === '') {
        echo json_encode(array('success' => false,
            'error' => 'BB_API_KEY not defined. Add define(\'BB_API_KEY\',\'your-key\') to wp-config.php or set the bb_calc_api_key WP option.')); exit;
    }

    $api_url = 'https://bb-sim-functions-erabe2achchza7fk.eastus-01.azurewebsites.net/api/run_sim'
             . '?code=' . rawurlencode($bb_sim_key);

    $payload = json_encode(array(
        'strategy'      => $strategy,
        'breed_suffix'  => $breed_sfx,
        'breed_id'      => $breed_id,
        'run_suffix'    => $run_suffix,
        'litter_size'   => $litter_size,
        'generations'   => $generations,
        'sires_per_dam' => $sires_per_dam,
        'sire_mode'     => $sire_mode,
    ));

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3600);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        echo json_encode(array('success' => false, 'error' => 'Curl error: ' . $curl_err)); exit;
    }

    $data = json_decode($response, true);
    if ($data === null) {
        echo json_encode(array('success' => false, 'error' => 'Invalid response from API. HTTP ' . $http_code)); exit;
    }

    echo json_encode($data); exit;
}