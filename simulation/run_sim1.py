import logging
import os
import re
import json
import time
import math
import random
import azure.functions as func
import mysql.connector


# =============================================================================
# BetterBred Sim Study I -- run_sim   (July 2026 rerun build)
#
# CHANGES FROM PRIOR VERSION
#   1. Four strategies only: OI, AGR, IR, RANDOM.  MIX and CAR removed.
#   2. Ho and FIS computed and stored for EVERY generation.
#      FIS = 1 - Ho/He, a WITHIN-population statistic.  A new, correctly named
#      `fis` column is added to expectedhet_* and is the column of record.
#      The legacy `fst` column held this same value under the wrong name; it is
#      kept in sync for backward compatibility but is not read.
#      sim_progress carries avg_ho and avg_fis.
#   3. litter_size is a parameter (was hardcoded 10).
#   4. generations is a parameter (was hardcoded 20).
#   5. sires_per_dam is a parameter (was implicitly 1).
#      Every dam breeds exactly once, so n_litters == n_dams.
#      Males that BREED = ceil(n_dams / sires_per_dam).  The rest of the male
#      pool exists but does not reproduce -- the popular-sire / stud-dog model.
#      Census stays balanced because each litter still yields 1 M + 1 F keeper.
#   6. sire_mode picks WHICH males breed when there are excess males:
#         'random'  (DEFAULT) -- draw the breeding sires at random
#         'ordered'           -- take the top males by carried-forward keeper score
#      At sires_per_dam = 1 there are no excess males, so sire_mode does nothing.
#   7. alleles_gdx_*.sel_score carries each keeper's score onto the parent it
#      becomes, so 'ordered' has something to sort on.  HIGHER IS ALWAYS BETTER:
#         OI -> oi        IR -> -ir        AGR -> -mean_gr      RANDOM -> random
#      Founders (gen 0) have no score, so generation 1 always falls back to a
#      random sire draw whatever sire_mode says.  Stated, not hidden.
#
# DEFAULTS REPRODUCE THE ORIGINAL BASELINE EXACTLY:
#   litter_size=10, generations=20, sires_per_dam=1, sire_mode='random'
# =============================================================================


STRATEGIES = ['OI', 'IR', 'AGR', 'RANDOM']
SFX_MAP = {'OI': 'oi', 'IR': 'ir', 'RANDOM': 'ran', 'AGR': 'agr'}


def get_db():
    return mysql.connector.connect(
        host=os.environ.get('BB_DB_HOST'),
        user=os.environ.get('BB_DB_USER'),
        password=os.environ.get('BB_DB_PASS'),
        database=os.environ.get('BB_DB_NAME', 'better_bred'),
        port=3306,
        ssl_ca='/etc/ssl/certs/ca-certificates.crt',
        ssl_verify_cert=False,
        autocommit=True,
        connection_timeout=30
    )


# -----------------------------------------------------------------------------
# SCHEMA GUARDS -- additive only, safe to run every time, never drops anything
# -----------------------------------------------------------------------------

def ensure_columns(cursor, s, tbl_progress, tbl_replicates):
    cursor.execute("SHOW COLUMNS FROM alleles_gdx_{} LIKE 'sel_score'".format(s))
    if not cursor.fetchone():
        cursor.execute("ALTER TABLE alleles_gdx_{} ADD COLUMN sel_score DOUBLE NULL".format(s))

    # The statistic is FIS = 1 - Ho/He.  The legacy column on expectedhet_* is
    # called `fst`, which is simply wrong -- FST is a between-population statistic
    # and this is a within-population one.  Add a correctly named `fis` column and
    # write to it.  `fst` is still written in parallel so that any older query
    # still returns a value, but FIS is the column of record.
    cursor.execute("SHOW COLUMNS FROM expectedhet_{} LIKE 'fis'".format(s))
    if not cursor.fetchone():
        cursor.execute("ALTER TABLE expectedhet_{} ADD COLUMN fis FLOAT(6,4) NULL".format(s))

    for col in ['avg_ho', 'avg_fis']:
        cursor.execute("SHOW COLUMNS FROM {} LIKE '{}'".format(tbl_progress, col))
        if not cursor.fetchone():
            cursor.execute("ALTER TABLE {} ADD COLUMN {} DOUBLE NULL".format(tbl_progress, col))

    for col in ['litter_size', 'sires_per_dam', 'num_breeding_sires']:
        cursor.execute("SHOW COLUMNS FROM {} LIKE '{}'".format(tbl_progress, col))
        if not cursor.fetchone():
            cursor.execute("ALTER TABLE {} ADD COLUMN {} INT NULL".format(tbl_progress, col))

    cursor.execute("SHOW COLUMNS FROM {} LIKE 'sire_mode'".format(tbl_progress))
    if not cursor.fetchone():
        cursor.execute("ALTER TABLE {} ADD COLUMN sire_mode VARCHAR(10) NULL".format(tbl_progress))

    for col in ['gen0_ho', 'gen0_fis', 'genN_ho', 'genN_fis', 'ho_delta', 'fis_delta']:
        cursor.execute("SHOW COLUMNS FROM {} LIKE '{}'".format(tbl_replicates, col))
        if not cursor.fetchone():
            cursor.execute("ALTER TABLE {} ADD COLUMN {} DOUBLE NULL".format(tbl_replicates, col))


# -----------------------------------------------------------------------------
# WANG LETTERS -- unchanged.  Coefficients come from the PARENT generation's
# frequency table, which is what anchors OI, IR and AGR to one frequency basis.
# -----------------------------------------------------------------------------

def wang_letters(cursor, frq_table, gen, loci_count):
    tmp = 'tmp_wf_py_{}'.format(abs(hash(frq_table + str(gen))) % 999997 + 1)
    cursor.execute("DROP TABLE IF EXISTS better_bred.{}".format(tmp))
    cursor.execute("""CREATE TABLE better_bred.{t} (
        locus_id  INT NOT NULL,
        a2 DOUBLE NOT NULL DEFAULT 0, a3 DOUBLE NOT NULL DEFAULT 0,
        a4 DOUBLE NOT NULL DEFAULT 0, u DOUBLE NOT NULL DEFAULT 0,
        ufraction DOUBLE NOT NULL DEFAULT 0, wl DOUBLE NOT NULL DEFAULT 0,
        a2w DOUBLE NOT NULL DEFAULT 0, a3w DOUBLE NOT NULL DEFAULT 0,
        a4w DOUBLE NOT NULL DEFAULT 0, a22w DOUBLE NOT NULL DEFAULT 0,
        INDEX locus_id (locus_id))""".format(t=tmp))

    cursor.execute("""INSERT INTO better_bred.{t} (locus_id, a2, a3, a4)
        SELECT locus_id,
               SUM(frq * frq)             AS a2,
               SUM(frq * frq * frq)       AS a3,
               SUM(frq * frq * frq * frq) AS a4
        FROM {ft} WHERE gen = {g} GROUP BY locus_id""".format(t=tmp, ft=frq_table, g=gen))

    cursor.execute("UPDATE better_bred.{} SET u = (2 * a2) - a3".format(tmp))
    cursor.execute("UPDATE better_bred.{} SET ufraction = 1.0 / u WHERE u != 0".format(tmp))
    cursor.execute("SELECT SUM(ufraction) / {} AS usum FROM better_bred.{}".format(loci_count, tmp))
    row = cursor.fetchone()
    usum = float(row[0]) if row and row[0] else 0.0
    if usum == 0:
        cursor.execute("DROP TABLE IF EXISTS better_bred.{}".format(tmp))
        return None

    cursor.execute("UPDATE better_bred.{} SET wl = 1.0 / (u * {}) WHERE u != 0".format(tmp, usum))
    cursor.execute("""UPDATE better_bred.{t} SET
        a2w = a2 * wl, a3w = a3 * wl, a4w = a4 * wl, a22w = a2 * a2 * wl""".format(t=tmp))

    cursor.execute("""SELECT
        (2 * AVG(a22w) - AVG(a4w)),
        (AVG(a2w) - 2 * AVG(a22w) + AVG(a4w)),
        (4 * (AVG(a3w) - AVG(a4w))),
        (2 * (AVG(a2w) - 3 * AVG(a3w) + 2 * AVG(a4w))),
        (4 * (AVG(a2w) - AVG(a22w) - 2 * AVG(a3w) + 2 * AVG(a4w))),
        (1 - 7*AVG(a2w) + 4*AVG(a22w) + 10*AVG(a3w) - 8*AVG(a4w))
    FROM better_bred.{}""".format(tmp))
    row = cursor.fetchone()
    if not row:
        cursor.execute("DROP TABLE IF EXISTS better_bred.{}".format(tmp))
        return None

    b, c, d, e, f, g = (float(x) if x else 0.0 for x in row)
    vee = ((1-b)*(1-b)*((e*e*f) + d*g*g)) \
        - ((1-b)*(e*f - d*g)*(e*f - d*g)) \
        + (2*c*d*f*(1-b)*(g + e)) \
        + (c*c*d*f*(d + f))

    cursor.execute("SELECT locus_id, wl FROM better_bred.{}".format(tmp))
    wl_map = {int(r[0]): float(r[1]) for r in cursor.fetchall()}
    cursor.execute("DROP TABLE IF EXISTS better_bred.{}".format(tmp))
    return {'b': b, 'c': c, 'd': d, 'e': e, 'f': f, 'g': g, 'vee': vee, 'wl': wl_map}


def compute_gr(alleles_i, alleles_j, wang, loci_count):
    if wang['vee'] == 0 or loci_count == 0:
        return 0.0
    bw = wang['b']; cw = wang['c']; dw = wang['d']
    ew = wang['e']; fw = wang['f']; gw = wang['g']
    vee = wang['vee']
    po1 = 0.0; po2 = 0.0; po3 = 0.0

    for locus_id, wl in wang['wl'].items():
        if locus_id not in alleles_i or locus_id not in alleles_j:
            continue
        ai = alleles_i[locus_id][0]; bi = alleles_i[locus_id][1]
        aj = alleles_j[locus_id][0]; bj = alleles_j[locus_id][1]

        if ((ai==bi and bi==aj and aj==bj) or (aj==bj and bj==ai and ai==bi) or
            (ai==aj and bi==bj and ai!=bi) or (aj==ai and bj==bi and aj!=bj) or
            (ai==bj and bi==aj and ai!=bi) or (aj==bi and bj==ai and aj!=bj)):
            po1 += wl
        elif ((ai==bi and bi==aj and aj!=bj) or (aj==bj and bj==ai and ai!=bi) or
              (ai==bi and bi==bj and bj!=aj) or (aj==bj and bj==bi and bi!=ai) or
              (ai!=bi and ai==aj and aj==bj) or (aj!=bj and aj==ai and ai==bi) or
              (ai!=bi and bi==aj and aj==bj) or (aj!=bj and bj==ai and ai==bi)):
            po2 += wl
        elif ((ai==aj and ai!=bi and ai!=bj and bj!=bi) or
              (aj==ai and aj!=bj and aj!=bi and bi!=bj) or
              (ai==bj and ai!=bi and ai!=aj and bi!=aj) or
              (aj==bi and aj!=bj and aj!=ai and bj!=ai) or
              (bi==bj and bi!=ai and bi!=aj and ai!=aj) or
              (bj==bi and bj!=aj and bj!=ai and aj!=ai) or
              (bi==aj and bi!=ai and bi!=bj and ai!=bj) or
              (bj==ai and bj!=aj and bj!=bi and aj!=bi)):
            po3 += wl

    p1 = po1 / loci_count
    p2 = po2 / loci_count
    p3 = po3 / loci_count

    theta = (
        ((dw*fw)*((ew+gw)*(1-bw)+(cw*(dw+fw)))*(p1-1)) +
        ((dw*(1-bw))*((gw*(1-bw-dw))+(fw*(cw+ew)))*p3) +
        ((fw*(1-bw))*((ew*(1-bw-fw))+(dw*(cw+gw)))*p2)
    ) / vee

    delta = (
        (cw*dw*fw*(ew+gw)*(p1+1-2*bw)) +
        ((((1-bw)*((fw*ew*ew)+(dw*gw*gw))) -
          ((ew*fw-dw*gw)*(ew*fw-dw*gw)))*(p1-bw)) +
        (cw*((dw*gw)-(ew*fw))*((dw*p3)-(fw*p2))) -
        ((cw*cw*dw*fw)*(p3+p2-dw-fw)) -
        (cw*(1-bw)*((dw*gw*p3)+(ew*fw*p2)))
    ) / vee

    return (theta / 2.0) + delta


# -----------------------------------------------------------------------------
# KEEPER SELECTION
# Returns (puppy_id, sel_score).  HIGHER sel_score is ALWAYS better, so the
# sire-ordering step can sort DESC without a per-strategy special case.
# -----------------------------------------------------------------------------

def select_keeper(strategy, group, litter_id, gen, pup_avgs, pup_alleles,
                  parent_alleles_agr, wang_agr, count_loci, cursor):
    if not group:
        return None, None

    if strategy == 'OI':
        g_csv = ','.join(str(p) for p in group)
        cursor.execute(
            "SELECT puppy_id, oi FROM {} WHERE litter_id={} AND gen={} AND puppy_id IN ({}) "
            "ORDER BY oi DESC LIMIT 1".format(pup_avgs, litter_id, gen, g_csv))
        row = cursor.fetchone()
        if row:
            return int(row[0]), (float(row[1]) if row[1] is not None else 0.0)
        return group[0], 0.0

    if strategy == 'IR':
        g_csv = ','.join(str(p) for p in group)
        cursor.execute(
            "SELECT puppy_id, ir FROM {} WHERE litter_id={} AND gen={} AND puppy_id IN ({}) "
            "ORDER BY ir ASC LIMIT 1".format(pup_avgs, litter_id, gen, g_csv))
        row = cursor.fetchone()
        if row:
            return int(row[0]), (-float(row[1]) if row[1] is not None else 0.0)
        return group[0], 0.0

    if strategy == 'RANDOM':
        return random.choice(group), random.random()

    if strategy == 'AGR':
        if not wang_agr or not parent_alleles_agr:
            return random.choice(group), random.random()
        best_pup = group[0]
        best_mgr = float('inf')
        for cand in group:
            if cand not in pup_alleles:
                continue
            gr_sum = sum(compute_gr(pup_alleles[cand], pa, wang_agr, count_loci)
                         for pa in parent_alleles_agr.values())
            mgr = gr_sum / len(parent_alleles_agr) if parent_alleles_agr else 0.0
            if mgr < best_mgr:
                best_mgr = mgr
                best_pup = cand
        if best_mgr == float('inf'):
            return best_pup, 0.0
        return best_pup, -best_mgr

    return group[0], 0.0


# -----------------------------------------------------------------------------
# PAIRING
# Every dam breeds once.  n_litters == n_dams.
# Breeding males = ceil(n_dams / sires_per_dam).  Excess males do not reproduce.
# -----------------------------------------------------------------------------

def build_pairs(cursor, s, parent_gen, sires_per_dam, sire_mode):
    cursor.execute(
        "SELECT DISTINCT dog_id FROM alleles_gdx_{} WHERE gender='F' AND gen={}".format(s, parent_gen))
    dams = [int(r[0]) for r in cursor.fetchall()]

    cursor.execute(
        "SELECT dog_id, MAX(sel_score) FROM alleles_gdx_{} WHERE gender='M' AND gen={} "
        "GROUP BY dog_id".format(s, parent_gen))
    male_rows = [(int(r[0]), r[1]) for r in cursor.fetchall()]

    if not dams or not male_rows:
        raise ValueError("No parents available at gen {}".format(parent_gen))

    random.shuffle(dams)

    n_dams = len(dams)
    n_breeding_sires = int(math.ceil(float(n_dams) / float(sires_per_dam)))
    n_breeding_sires = min(n_breeding_sires, len(male_rows))

    all_scored = all(m[1] is not None for m in male_rows)
    if sire_mode == 'ordered' and all_scored:
        male_rows.sort(key=lambda m: float(m[1]), reverse=True)
        breeding_sires = [m[0] for m in male_rows[:n_breeding_sires]]
    else:
        # 'random', or generation 1 where founders carry no sel_score
        random.shuffle(male_rows)
        breeding_sires = [m[0] for m in male_rows[:n_breeding_sires]]

    pairs = []
    for i, dam in enumerate(dams):
        pairs.append((breeding_sires[i % len(breeding_sires)], dam))

    return pairs, len(breeding_sires)


# -----------------------------------------------------------------------------
# Ho / FIS
# Ho per locus = (n_dogs - n_homozygous) / n_dogs, from the homozygous flag
# already stored on alleles_gdx_*.   FIS = 1 - Ho/He.
# Stored in expectedhet_*.ho and expectedhet_*.fis
# -----------------------------------------------------------------------------

def fill_ho_fis(cursor, s, gen):
    cursor.execute("""UPDATE expectedhet_{s} e
        LEFT JOIN (
            SELECT locus_id,
                   ((COUNT(dog_id) - SUM(homozygous)) / COUNT(dog_id)) AS ho
            FROM alleles_gdx_{s} WHERE gen={g} GROUP BY locus_id
        ) AS calc ON e.locus_id = calc.locus_id
        SET e.ho = calc.ho
        WHERE e.gen={g} AND e.id > 0""".format(s=s, g=gen))

    # FIS = 1 - Ho/He.  Written to `fis`, the column of record.
    cursor.execute("""UPDATE expectedhet_{s}
        SET fis = (1 - (ho / he))
        WHERE gen={g} AND he > 0 AND ho IS NOT NULL AND id > 0""".format(s=s, g=gen))

    # Legacy mirror: the old `fst` column held this same value under a wrong name.
    # Kept in sync so nothing that still reads it silently returns NULL.
    cursor.execute("""UPDATE expectedhet_{s}
        SET fst = fis
        WHERE gen={g} AND fis IS NOT NULL AND id > 0""".format(s=s, g=gen))


def build_expectedhet(cursor, s, gen):
    cursor.execute("SELECT COUNT(*) FROM expectedhet_{} WHERE gen={}".format(s, gen))
    if int(cursor.fetchone()[0]) == 0:
        cursor.execute(
            "INSERT INTO expectedhet_{s}(gen,locus_id,he,numstrs) "
            "SELECT gen,locus_id,(1-SUM(frq*frq)) AS he,COUNT(str) AS numstrs "
            "FROM frq_gdx_{s} WHERE gen={g} GROUP BY gen,locus_id".format(s=s, g=gen))
        cursor.execute(
            "UPDATE expectedhet_{} SET effective_alleles=1/(1-he) "
            "WHERE gen={} AND id>=0".format(s, gen))

    fill_ho_fis(cursor, s, gen)


def build_frq(cursor, s, gen, slot_ct):
    cursor.execute("SELECT COUNT(*) FROM frq_gdx_{} WHERE gen={}".format(s, gen))
    if int(cursor.fetchone()[0]) > 0:
        return
    if slot_ct <= 0:
        raise ValueError("Zero allele slots at gen {}".format(gen))

    cursor.execute(
        "INSERT INTO frq_gdx_{s}(locus_id,str,gen) "
        "SELECT DISTINCT locus_id,str,{g} AS gen FROM ("
        "  SELECT DISTINCT locus_id,str_a AS str FROM alleles_gdx_{s} WHERE gen={g} "
        "  UNION "
        "  SELECT DISTINCT locus_id,str_b AS str FROM alleles_gdx_{s} WHERE gen={g}"
        ") AS calc ORDER BY locus_id,str".format(s=s, g=gen))

    for ab in ['a', 'b']:
        t = "better_bred.str_{ab}_{s}".format(ab=ab, s=s)
        cursor.execute(
            "CREATE TABLE IF NOT EXISTS {t} (id INT AUTO_INCREMENT PRIMARY KEY,"
            "gen INT,locus_id INT,str_{ab} FLOAT,ct INT)".format(t=t, ab=ab))
        cursor.execute(
            "INSERT INTO {t}(gen,locus_id,str_{ab},ct) "
            "SELECT {g},locus_id,str_{ab},COUNT(str_{ab}) FROM alleles_gdx_{s} "
            "WHERE gen={g} GROUP BY locus_id,str_{ab}".format(t=t, ab=ab, g=gen, s=s))
        cursor.execute(
            "UPDATE frq_gdx_{s} f JOIN {t} x ON x.locus_id=f.locus_id AND x.str_{ab}=f.str "
            "SET f.count_str_{ab}=x.ct WHERE f.gen={g} AND f.id>0".format(s=s, t=t, ab=ab, g=gen))
        cursor.execute("DROP TABLE {}".format(t))

    cursor.execute(
        "UPDATE frq_gdx_{} SET count_str_total=(count_str_a+count_str_b) "
        "WHERE gen={} AND id>0".format(s, gen))
    cursor.execute(
        "UPDATE frq_gdx_{} SET frq=(count_str_total/{}) "
        "WHERE gen={} AND id>0".format(s, slot_ct, gen))


# -----------------------------------------------------------------------------
# ONE GENERATION
# -----------------------------------------------------------------------------

def run_generation(cursor, strategy, s, breed_raw, gen, parent_gen, count_loci, t0,
                   litter_size, sires_per_dam, sire_mode, tbl_progress, rep_num=None):

    cursor.execute(
        "SELECT COUNT(DISTINCT dog_id)*2 FROM alleles_gdx_{} WHERE gen={}".format(s, parent_gen))
    parent_slots = int(cursor.fetchone()[0])
    build_frq(cursor, s, parent_gen, parent_slots)
    build_expectedhet(cursor, s, parent_gen)

    pairs, n_breeding_sires = build_pairs(cursor, s, parent_gen, sires_per_dam, sire_mode)
    pair_count = len(pairs)
    if pair_count == 0:
        raise ValueError("No parent pairs for gen {}".format(parent_gen))

    cursor.execute(
        "SELECT dog_id,locus_id,str_a,str_b FROM alleles_gdx_{} WHERE gen={}".format(s, parent_gen))
    parent_alleles = {}
    for row in cursor.fetchall():
        did, lid, sa, sb = int(row[0]), int(row[1]), float(row[2]), float(row[3])
        if did not in parent_alleles:
            parent_alleles[did] = {}
        parent_alleles[did][lid] = (sa, sb)

    # --- puppies ------------------------------------------------------------
    puppies_tbl = "puppies_gdx_{}".format(s)
    cursor.execute("""CREATE TABLE IF NOT EXISTS {t} (
        id INT AUTO_INCREMENT PRIMARY KEY,
        gen INT, litter_id INT, sire_id INT, dam_id INT, puppy_id INT,
        locus_id INT, str_a FLOAT, str_b FLOAT, homozygous TINYINT,
        INDEX gen_litter(gen,litter_id), INDEX puppy(gen,puppy_id))""".format(t=puppies_tbl))
    cursor.execute("DELETE FROM {} WHERE gen={}".format(puppies_tbl, gen))

    pup_rows = []
    pup_ctr = 1
    for k, (sid, did) in enumerate(pairs):
        if sid not in parent_alleles or did not in parent_alleles:
            continue
        sa = parent_alleles[sid]
        da = parent_alleles[did]
        common = sorted(set(sa.keys()) & set(da.keys()))
        for p in range(litter_size):
            pid = pup_ctr
            pup_ctr += 1
            for lid in common:
                str_a = random.choice(sa[lid])
                str_b = random.choice(da[lid])
                hom = 1 if str_a == str_b else 0
                pup_rows.append((gen, k, sid, did, pid, lid, str_a, str_b, hom))

    if pup_rows:
        cursor.executemany(
            "INSERT INTO {} (gen,litter_id,sire_id,dam_id,puppy_id,locus_id,str_a,str_b,homozygous) "
            "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)".format(puppies_tbl), pup_rows)

    # --- score puppies ------------------------------------------------------
    pup_avgs = "pup_avgs_gdx_{}".format(s)
    cursor.execute("""CREATE TABLE IF NOT EXISTS {t} (
        id INT AUTO_INCREMENT PRIMARY KEY, gen INT, litter_id INT,
        sire_id INT, dam_id INT, puppy_id INT,
        ir DECIMAL(10,5), hl DECIMAL(10,5), oi DECIMAL(10,5),
        infr_als INT, norm_als INT, hfreq_als INT,
        rarehet INT, rarehom INT, normhom INT, comhet INT, comhom INT, mixedhet INT,
        unusual_anc DECIMAL(10,5), typical_anc DECIMAL(10,5),
        balanced_anc DECIMAL(10,5), mixed_anc DECIMAL(10,5), pcho DECIMAL(10,4),
        INDEX gen(gen), INDEX litter(litter_id), INDEX parents(sire_id,dam_id),
        INDEX puppy(puppy_id), INDEX spec_pup(gen,litter_id,puppy_id), INDEX ir(ir))""".format(t=pup_avgs))
    cursor.execute("DELETE FROM {} WHERE gen={}".format(pup_avgs, gen))

    thr = "better_bred.tmp_locus_thr_{}".format(s)
    cursor.execute("DROP TABLE IF EXISTS {}".format(thr))
    cursor.execute(
        "CREATE TABLE {t} (locus_id INT, numstrs INT, low_thr FLOAT, high_thr FLOAT, "
        "INDEX locus_id (locus_id))".format(t=thr))
    cursor.execute(
        "INSERT INTO {t} (locus_id,numstrs,low_thr,high_thr) "
        "SELECT locus_id,COUNT(*) AS numstrs,(0.75/COUNT(*)) AS low_thr,(1.25/COUNT(*)) AS high_thr "
        "FROM frq_gdx_{s} WHERE gen={pg} GROUP BY locus_id".format(t=thr, s=s, pg=parent_gen))

    # IR denominator is 2L.  Was hardcoded 66 (33 loci); now derived from count_loci
    # so a reduced-panel run (e.g. the Wang 16-locus split-half) is still correct.
    ir_denom = count_loci * 2

    cursor.execute("""INSERT INTO {pa}
        (gen,litter_id,sire_id,dam_id,puppy_id,ir,oi,hl,pcho,
         infr_als,norm_als,hfreq_als,rarehet,rarehom,normhom,comhet,comhom,mixedhet)
        SELECT p.gen,p.litter_id,p.sire_id,p.dam_id,p.puppy_id,
            ROUND(((2*SUM(p.homozygous))-SUM(fa.frq+fb.frq))/NULLIF(({ird}-SUM(fa.frq+fb.frq)),0),5),
            IFNULL(SUM(CASE WHEN fa.frq<lt.low_thr THEN 1 ELSE 0 END+CASE WHEN fb.frq<lt.low_thr THEN 1 ELSE 0 END)
                /NULLIF(SUM(CASE WHEN fa.frq>lt.high_thr THEN 1 ELSE 0 END+CASE WHEN fb.frq>lt.high_thr THEN 1 ELSE 0 END),0),0)
            +(SUM(CASE WHEN fa.frq BETWEEN lt.low_thr AND lt.high_thr THEN 1 ELSE 0 END
                 +CASE WHEN fb.frq BETWEEN lt.low_thr AND lt.high_thr THEN 1 ELSE 0 END)/({cl}*2)),
            SUM(CASE WHEN p.homozygous=1 THEN eh.he ELSE 0 END)/NULLIF(SUM(eh.he),0),
            (SUM(p.homozygous)/{cl})*100,
            SUM(CASE WHEN fa.frq<lt.low_thr THEN 1 ELSE 0 END+CASE WHEN fb.frq<lt.low_thr THEN 1 ELSE 0 END),
            SUM(CASE WHEN fa.frq BETWEEN lt.low_thr AND lt.high_thr THEN 1 ELSE 0 END+CASE WHEN fb.frq BETWEEN lt.low_thr AND lt.high_thr THEN 1 ELSE 0 END),
            SUM(CASE WHEN fa.frq>lt.high_thr THEN 1 ELSE 0 END+CASE WHEN fb.frq>lt.high_thr THEN 1 ELSE 0 END),
            SUM(CASE WHEN (fa.frq<lt.low_thr AND fb.frq BETWEEN lt.low_thr AND lt.high_thr) OR (fb.frq<lt.low_thr AND fa.frq BETWEEN lt.low_thr AND lt.high_thr) THEN 1 ELSE 0 END),
            SUM(CASE WHEN fa.frq<lt.low_thr AND fb.frq<lt.low_thr THEN 1 ELSE 0 END),
            SUM(CASE WHEN fa.frq BETWEEN lt.low_thr AND lt.high_thr AND fb.frq BETWEEN lt.low_thr AND lt.high_thr THEN 1 ELSE 0 END),
            SUM(CASE WHEN (fa.frq>lt.high_thr AND fb.frq BETWEEN lt.low_thr AND lt.high_thr) OR (fb.frq>lt.high_thr AND fa.frq BETWEEN lt.low_thr AND lt.high_thr) THEN 1 ELSE 0 END),
            SUM(CASE WHEN fa.frq>lt.high_thr AND fb.frq>lt.high_thr THEN 1 ELSE 0 END),
            SUM(CASE WHEN (fa.frq<lt.low_thr AND fb.frq>lt.high_thr) OR (fb.frq<lt.low_thr AND fa.frq>lt.high_thr) THEN 1 ELSE 0 END)
        FROM {pt} p
        JOIN frq_gdx_{s} fa ON fa.locus_id=p.locus_id AND fa.str=p.str_a AND fa.gen={pg}
        JOIN frq_gdx_{s} fb ON fb.locus_id=p.locus_id AND fb.str=p.str_b AND fb.gen={pg}
        JOIN {thr} lt ON lt.locus_id=p.locus_id
        JOIN expectedhet_{s} eh ON eh.locus_id=p.locus_id AND eh.gen={pg}
        WHERE p.gen={g}
        GROUP BY p.gen,p.litter_id,p.sire_id,p.dam_id,p.puppy_id""".format(
        pa=pup_avgs, cl=count_loci, ird=ir_denom, pt=puppies_tbl, s=s,
        pg=parent_gen, thr=thr, g=gen))

    cursor.execute(
        "UPDATE {pa} SET unusual_anc=((rarehom+rarehet)/{cl})*100,"
        "typical_anc=((comhom+comhet)/{cl})*100,balanced_anc=(normhom/{cl})*100,"
        "mixed_anc=(mixedhet/{cl})*100 WHERE gen={g}".format(pa=pup_avgs, cl=count_loci, g=gen))
    cursor.execute("DROP TABLE IF EXISTS {}".format(thr))

    # --- preload for AGR ----------------------------------------------------
    pup_alleles = {}
    wang_agr = None
    par_alleles_agr = {}

    if strategy == 'AGR':
        cursor.execute(
            "SELECT puppy_id,locus_id,str_a,str_b FROM {} WHERE gen={}".format(puppies_tbl, gen))
        for row in cursor.fetchall():
            pid, lid, sa, sb = int(row[0]), int(row[1]), float(row[2]), float(row[3])
            if pid not in pup_alleles:
                pup_alleles[pid] = {}
            pup_alleles[pid][lid] = (sa, sb)

        wang_agr = wang_letters(cursor, "frq_gdx_{}".format(s), parent_gen, count_loci)
        if not wang_agr:
            raise ValueError("Wang letters failed for AGR")
        par_alleles_agr = parent_alleles

    # --- keeper selection: 2 per litter, one M one F ------------------------
    cursor.execute(
        "SELECT IFNULL(MAX(dog_id),0) FROM alleles_gdx_{} WHERE gen={}".format(s, gen))
    next_dog_id = int(cursor.fetchone()[0]) + 1
    selected_pup_ids = []

    for k in range(pair_count):
        cursor.execute(
            "SELECT puppy_id FROM {} WHERE litter_id={} AND gen={}".format(pup_avgs, k, gen))
        litter_ids = [int(r[0]) for r in cursor.fetchall()]
        if len(litter_ids) < 2:
            continue
        random.shuffle(litter_ids)
        half = len(litter_ids) // 2
        g1 = litter_ids[:half]
        g2 = litter_ids[half:]

        c1, sc1 = select_keeper(strategy, g1, k, gen, pup_avgs, pup_alleles,
                                par_alleles_agr, wang_agr, count_loci, cursor)
        c2, sc2 = select_keeper(strategy, g2, k, gen, pup_avgs, pup_alleles,
                                par_alleles_agr, wang_agr, count_loci, cursor)
        if not c1 or not c2:
            continue

        selected_pup_ids.extend([c1, c2])
        sexes = ['M', 'F']
        random.shuffle(sexes)
        for idx, cp_sc in enumerate([(c1, sc1), (c2, sc2)]):
            cp = cp_sc[0]
            sc = cp_sc[1]
            gender = sexes[idx]
            dog_id = next_dog_id
            next_dog_id += 1
            score_sql = 'NULL' if sc is None else repr(float(sc))
            cursor.execute("""INSERT INTO alleles_gdx_{s}
                (dog_id,gender,locus_id,str_a,str_b,homozygous,gen,sire_id,dam_id,sel_score)
                SELECT {d},'{gn}',locus_id,str_a,str_b,homozygous,{g},sire_id,dam_id,{sc}
                FROM {pt} WHERE puppy_id={cp} AND litter_id={k} AND gen={g}""".format(
                s=s, d=dog_id, gn=gender, g=gen, pt=puppies_tbl, cp=cp, k=k, sc=score_sql))

    # --- keeper generation frq + He/Ho/FIS ---------------------------------
    keeper_slots = len(selected_pup_ids) * 2
    build_frq(cursor, s, gen, keeper_slots)
    build_expectedhet(cursor, s, gen)

    # --- mean OI / IR of keepers -------------------------------------------
    mean_oi = 0.0
    mean_ir = 0.0
    if selected_pup_ids:
        ids_csv = ','.join(str(p) for p in selected_pup_ids)
        cursor.execute(
            "SELECT AVG(oi),AVG(ir) FROM {} WHERE gen={} AND puppy_id IN ({})".format(
                pup_avgs, gen, ids_csv))
        row = cursor.fetchone()
        if row:
            mean_oi = round(float(row[0]) if row[0] else 0.0, 6)
            mean_ir = round(float(row[1]) if row[1] else 0.0, 6)

    # --- band proportions ---------------------------------------------------
    cursor.execute("""SELECT
        AVG(CASE WHEN f.frq < (0.75/lt.numstrs) THEN 1.0 ELSE 0.0 END),
        AVG(CASE WHEN f.frq >= (0.75/lt.numstrs) AND f.frq <= (1.25/lt.numstrs) THEN 1.0 ELSE 0.0 END),
        AVG(CASE WHEN f.frq > (1.25/lt.numstrs) THEN 1.0 ELSE 0.0 END)
        FROM frq_gdx_{s} f
        JOIN (SELECT locus_id,COUNT(*) AS numstrs FROM frq_gdx_{s} WHERE gen={g} GROUP BY locus_id) lt
          ON lt.locus_id=f.locus_id
        WHERE f.gen={g}""".format(s=s, g=gen))
    row = cursor.fetchone()
    band_infr = round(float(row[0]) if row and row[0] else 0.0, 6)
    band_norm = round(float(row[1]) if row and row[1] else 0.0, 6)
    band_hfreq = round(float(row[2]) if row and row[2] else 0.0, 6)

    # --- generation statistics ---------------------------------------------
    cursor.execute(
        "SELECT AVG(he),AVG(effective_alleles),AVG(ho),AVG(fis) "
        "FROM expectedhet_{} WHERE gen={}".format(s, gen))
    sr = cursor.fetchone()
    avg_he = float(sr[0]) if sr and sr[0] is not None else 0.0
    avg_ne = float(sr[1]) if sr and sr[1] is not None else 0.0
    avg_ho = float(sr[2]) if sr and sr[2] is not None else 0.0
    avg_fis = float(sr[3]) if sr and sr[3] is not None else 0.0

    cursor.execute(
        "SELECT AVG(allele_count) FROM (SELECT locus_id,COUNT(DISTINCT str) AS allele_count "
        "FROM frq_gdx_{} WHERE gen={} GROUP BY locus_id) AS lc".format(s, gen))
    nr = cursor.fetchone()
    avg_na = float(nr[0]) if nr and nr[0] else 0.0

    cursor.execute("SELECT COUNT(*) FROM frq_gdx_{} WHERE gen={}".format(s, gen))
    total_alleles = int(cursor.fetchone()[0])
    elapsed = round(time.time() - t0, 1)

    cursor.execute("""INSERT INTO {tp}
        (strategy,gen,completed_at,elapsed_seconds,avg_he,avg_ne,avg_na,avg_ho,avg_fis,
         num_breeders,num_breeding_sires,breed_suffix,
         avg_oi,avg_ir,band_infr,band_norm,band_hfreq,total_alleles,replicate_num,
         litter_size,sires_per_dam,sire_mode)
        VALUES (%s,%s,NOW(),%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""".format(tp=tbl_progress),
        (strategy, gen, elapsed, avg_he, avg_ne, avg_na, avg_ho, avg_fis,
         len(selected_pup_ids), n_breeding_sires, breed_raw,
         mean_oi, mean_ir, band_infr, band_norm, band_hfreq, total_alleles, rep_num,
         litter_size, sires_per_dam, sire_mode))

    return {'gen': gen, 'avg_he': avg_he, 'avg_ne': avg_ne, 'avg_na': avg_na,
            'avg_ho': avg_ho, 'avg_fis': avg_fis,
            'pair_count': pair_count, 'n_breeding_sires': n_breeding_sires,
            'keepers': len(selected_pup_ids), 'elapsed': elapsed}


# -----------------------------------------------------------------------------
# REPLICATE ENDPOINT ROW
# NOTE: the gen20_* column names in sim_replicates are historical.  They hold
# the FINAL generation, whatever `generations` was set to.
# -----------------------------------------------------------------------------

def record_replicate(cursor, strategy, s, breed_raw, breed_id, count_loci, gN, final_gen,
                     tbl_progress, tbl_replicates, tbl_founders):
    cursor.execute(
        "SELECT AVG(he),AVG(effective_alleles),AVG(ho),AVG(fis) "
        "FROM expectedhet_{} WHERE gen=0".format(s))
    r0 = cursor.fetchone()
    cursor.execute(
        "SELECT AVG(allele_count) FROM (SELECT locus_id,COUNT(DISTINCT str) AS allele_count "
        "FROM frq_gdx_{} WHERE gen=0 GROUP BY locus_id) AS lc".format(s))
    na0r = cursor.fetchone()
    cursor.execute("SELECT COUNT(*) FROM frq_gdx_{} WHERE gen=0".format(s))
    g0_total_alleles = int(cursor.fetchone()[0])

    g0_he = round(float(r0[0]) if r0 and r0[0] is not None else 0.0, 4)
    g0_ne = round(float(r0[1]) if r0 and r0[1] is not None else 0.0, 4)
    g0_ho = round(float(r0[2]) if r0 and r0[2] is not None else 0.0, 4)
    g0_fis = round(float(r0[3]) if r0 and r0[3] is not None else 0.0, 4)
    g0_na = round(float(na0r[0]) if na0r and na0r[0] else 0.0, 2)

    gN_he = round(gN['avg_he'], 4)
    gN_ne = round(gN['avg_ne'], 4)
    gN_na = round(gN['avg_na'], 2)
    gN_ho = round(gN['avg_ho'], 4)
    gN_fis = round(gN['avg_fis'], 4)

    cursor.execute(
        "SELECT MAX(replicate_num) FROM better_bred.{} WHERE breed_suffix=%s".format(tbl_founders),
        (breed_raw,))
    fr_rep = cursor.fetchone()
    founder_rep = int(fr_rep[0]) if fr_rep and fr_rep[0] else 1

    cursor.execute("""SELECT AVG(oi), AVG(ir),
        AVG(num_lo_alleles/(num_lo_alleles+num_mid_alleles+num_hi_alleles)),
        AVG(num_mid_alleles/(num_lo_alleles+num_mid_alleles+num_hi_alleles)),
        AVG(num_hi_alleles/(num_lo_alleles+num_mid_alleles+num_hi_alleles))
        FROM better_bred.{}
        WHERE breed_suffix=%s AND replicate_num=%s""".format(tbl_founders),
        (breed_raw, founder_rep))
    fr = cursor.fetchone()
    g0_oi = round(float(fr[0]) if fr and fr[0] else 0.0, 6)
    g0_ir = round(float(fr[1]) if fr and fr[1] else 0.0, 6)
    g0_band_infr = round(float(fr[2]) if fr and fr[2] else 0.0, 6)
    g0_band_norm = round(float(fr[3]) if fr and fr[3] else 0.0, 6)
    g0_band_hfreq = round(float(fr[4]) if fr and fr[4] else 0.0, 6)

    cursor.execute("""SELECT avg_oi, avg_ir, band_infr, band_norm, band_hfreq, total_alleles
        FROM better_bred.{}
        WHERE strategy=%s AND breed_suffix=%s AND gen=%s
        ORDER BY completed_at DESC LIMIT 1""".format(tbl_progress),
        (strategy, breed_raw, final_gen))
    pr = cursor.fetchone()
    gN_oi = round(float(pr[0]) if pr and pr[0] else 0.0, 6)
    gN_ir = round(float(pr[1]) if pr and pr[1] else 0.0, 6)
    gN_band_infr = round(float(pr[2]) if pr and pr[2] else 0.0, 6)
    gN_band_norm = round(float(pr[3]) if pr and pr[3] else 0.0, 6)
    gN_band_hfreq = round(float(pr[4]) if pr and pr[4] else 0.0, 6)
    gN_total_alleles = int(pr[5]) if pr and pr[5] else 0

    # Replicate number comes from the FOUNDERS table, which is the single
    # source of truth. sim1_progress already derives its replicate_num the
    # same way (see the MAX(replicate_num) lookup in the request handler).
    #
    # This used to be COUNT(*)+1 over the replicates table, filtered per
    # strategy. That is independent of the founders numbering and desynchronises
    # the moment any strategy has a different row count from the others -- e.g.
    # when a run halts after OI succeeds but AGR fails. The next run would then
    # write OI as replicate 12 and AGR as replicate 11 for the SAME founder set.
    # It also collides with an existing replicate whenever a row is deleted.
    #
    # Bookkeeping only. This changes the integer label on the summary row and
    # nothing else -- no selection, mating, frequency, He, Ho, Na, OI, IR or AGR
    # value depends on it.
    rep_num = founder_rep

    cursor.execute("""INSERT INTO {tr}
        (strategy, breed_suffix, breed_id, replicate_num, completed_at, pop_size, loci,
         gen0_he, gen0_ne, gen0_na, gen0_oi, gen0_ir,
         gen0_band_infr, gen0_band_norm, gen0_band_hfreq, gen0_total_alleles,
         gen20_he, gen20_ne, gen20_na, gen20_oi, gen20_ir,
         gen20_band_infr, gen20_band_norm, gen20_band_hfreq, gen20_total_alleles,
         he_delta, ne_delta, na_delta, oi_delta, ir_delta,
         band_infr_delta, band_norm_delta, band_hfreq_delta, total_alleles_delta,
         gen0_ho, gen0_fis, genN_ho, genN_fis, ho_delta, fis_delta)
        VALUES (%s,%s,%s,%s,NOW(),%s,%s,
                %s,%s,%s,%s,%s,%s,%s,%s,%s,
                %s,%s,%s,%s,%s,%s,%s,%s,%s,
                %s,%s,%s,%s,%s,%s,%s,%s,%s,
                %s,%s,%s,%s,%s,%s)""".format(tr=tbl_replicates),
        (strategy, breed_raw, breed_id, rep_num, gN['keepers'], count_loci,
         g0_he, g0_ne, g0_na, g0_oi, g0_ir,
         g0_band_infr, g0_band_norm, g0_band_hfreq, g0_total_alleles,
         gN_he, gN_ne, gN_na, gN_oi, gN_ir,
         gN_band_infr, gN_band_norm, gN_band_hfreq, gN_total_alleles,
         round(gN_he-g0_he, 4), round(gN_ne-g0_ne, 4), round(gN_na-g0_na, 2),
         round(gN_oi-g0_oi, 6), round(gN_ir-g0_ir, 6),
         round(gN_band_infr-g0_band_infr, 6), round(gN_band_norm-g0_band_norm, 6),
         round(gN_band_hfreq-g0_band_hfreq, 6), gN_total_alleles-g0_total_alleles,
         g0_ho, g0_fis, gN_ho, gN_fis,
         round(gN_ho-g0_ho, 4), round(gN_fis-g0_fis, 4)))


# -----------------------------------------------------------------------------
# ENTRY POINT
# -----------------------------------------------------------------------------

def main(req: func.HttpRequest) -> func.HttpResponse:
    logging.info('run_sim called')
    try:
        body = req.get_json()
    except ValueError:
        body = {}

    strategy = str(body.get('strategy', '')).upper()
    breed_suffix = str(body.get('breed_suffix', '')).lower().strip()
    breed_id = int(body.get('breed_id', 0))

    # run_suffix names the permanent per-run tables:
    #   sim1_founders_<run_suffix>, sim1_progress_<run_suffix>, sim1_replicates_<run_suffix>
    # It is created by the PHP seeder and MUST be sent by the caller. Do not
    # reconstruct it from the current date -- seeding in one month and running
    # in the next would silently write to a different table.
    run_suffix = re.sub(r'[^a-z0-9_]', '', str(body.get('run_suffix', '')).lower().strip())

    # Defaults reproduce the original baseline exactly.
    litter_size = int(body.get('litter_size', 10))
    generations = int(body.get('generations', 20))
    sires_per_dam = int(body.get('sires_per_dam', 1))
    sire_mode = str(body.get('sire_mode', 'random')).lower().strip()

    if not run_suffix:
        return func.HttpResponse(
            json.dumps({'success': False,
                        'error': 'run_suffix is required (e.g. sp_jul2026). Seed founders first.'}),
            mimetype='application/json', status_code=400)
    if strategy not in STRATEGIES:
        return func.HttpResponse(
            json.dumps({'success': False,
                        'error': 'Invalid strategy. Must be one of: ' + ', '.join(STRATEGIES)}),
            mimetype='application/json', status_code=400)
    if breed_id <= 0:
        return func.HttpResponse(json.dumps({'success': False, 'error': 'Invalid breed_id'}),
                                 mimetype='application/json', status_code=400)
    if litter_size < 2 or litter_size > 20:
        return func.HttpResponse(
            json.dumps({'success': False,
                        'error': 'litter_size must be 2-20 (a litter must split into two groups)'}),
            mimetype='application/json', status_code=400)
    if generations < 1 or generations > 50:
        return func.HttpResponse(json.dumps({'success': False, 'error': 'generations must be 1-50'}),
                                 mimetype='application/json', status_code=400)
    if sires_per_dam < 1 or sires_per_dam > 20:
        return func.HttpResponse(json.dumps({'success': False, 'error': 'sires_per_dam must be 1-20'}),
                                 mimetype='application/json', status_code=400)
    if sire_mode not in ('random', 'ordered'):
        return func.HttpResponse(
            json.dumps({'success': False, 'error': "sire_mode must be 'random' or 'ordered'"}),
            mimetype='application/json', status_code=400)

    breed_sfx = '' if breed_suffix == '' else '_' + breed_suffix
    s = SFX_MAP[strategy] + breed_sfx

    tbl_founders   = 'sim1_founders_'   + run_suffix
    tbl_progress   = 'sim1_progress_'   + run_suffix
    tbl_replicates = 'sim1_replicates_' + run_suffix

    t0 = time.time()

    conn = None
    cursor = None
    try:
        conn = get_db()
        cursor = conn.cursor()

        # Fail loudly if the seeder has not created this run's tables.
        for t in (tbl_founders, tbl_progress, tbl_replicates):
            cursor.execute(
                "SELECT COUNT(*) FROM information_schema.tables "
                "WHERE table_schema='better_bred' AND table_name=%s", (t,))
            if int(cursor.fetchone()[0]) == 0:
                cursor.close(); conn.close()
                return func.HttpResponse(json.dumps({
                    'success': False,
                    'error': "Table better_bred.{} does not exist. Seed founders for run_suffix "
                             "'{}' first.".format(t, run_suffix)}),
                    mimetype='application/json', status_code=400)

        ensure_columns(cursor, s, tbl_progress, tbl_replicates)

        cursor.execute("SELECT MAX(gen) FROM alleles_gdx_{}".format(s))
        row = cursor.fetchone()
        if row is None or row[0] is None:
            cursor.close(); conn.close()
            return func.HttpResponse(json.dumps({
                'success': False,
                'error': 'alleles_gdx_{} empty. Seed founders first.'.format(s)}),
                mimetype='application/json', status_code=400)

        current_max = int(row[0])
        if current_max >= generations:
            cursor.close(); conn.close()
            return func.HttpResponse(json.dumps({
                'success': True,
                'message': 'Already at gen {}'.format(generations),
                'gen': generations}),
                mimetype='application/json', status_code=200)

        cursor.execute("SELECT COUNT(DISTINCT locus_id) FROM alleles_gdx_{}".format(s))
        count_loci = int(cursor.fetchone()[0])

        cursor.execute(
            "SELECT MAX(replicate_num) FROM better_bred.{} WHERE breed_suffix=%s".format(tbl_founders),
            (breed_suffix,))
        rep_row = cursor.fetchone()
        rep_num = int(rep_row[0]) if rep_row and rep_row[0] else None

        results = []
        for target_gen in range(current_max + 1, generations + 1):
            g = run_generation(cursor, strategy, s, breed_suffix, target_gen, target_gen - 1,
                               count_loci, t0, litter_size, sires_per_dam, sire_mode,
                               tbl_progress, rep_num)
            results.append(g)
            logging.info(
                "Gen {} done: He={:.4f} Ho={:.4f} FIS={:.4f} Na={:.2f} sires={} elapsed={:.0f}s".format(
                    target_gen, g['avg_he'], g['avg_ho'], g['avg_fis'], g['avg_na'],
                    g['n_breeding_sires'], g['elapsed']))
            if target_gen == generations:
                record_replicate(cursor, strategy, s, breed_suffix, breed_id,
                                 count_loci, g, generations,
                                 tbl_progress, tbl_replicates, tbl_founders)

        cursor.close(); conn.close()
        final = results[-1] if results else {}
        return func.HttpResponse(json.dumps({
            'success': True, 'strategy': strategy, 'breed': breed_suffix,
            'gens_run': len(results), 'final': final,
            'run_suffix': run_suffix, 'tables': {'progress': tbl_progress,
                                                 'replicates': tbl_replicates},
            'params': {'litter_size': litter_size, 'generations': generations,
                       'sires_per_dam': sires_per_dam, 'sire_mode': sire_mode}}),
            mimetype='application/json', status_code=200)

    except Exception as e:
        logging.error("Sim error: {}".format(str(e)))
        try:
            cursor.close(); conn.close()
        except Exception:
            pass
        return func.HttpResponse(json.dumps({'success': False, 'error': str(e)}),
                                 mimetype='application/json', status_code=500)
