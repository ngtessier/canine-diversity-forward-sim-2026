"""
BetterBred Simulation Study 1A -- Azure Function
OI threshold sensitivity study: T1-T5 + RANDOM control.
T3 (0.75/1.25) = Study I standard. Use as control.

POST body:
  strategy     : T1 | T2 | T3 | T4 | T5 | RANDOM
  breed_suffix : sp | dp | fcr | (empty)
  breed_id     : int

Tables:
  Working : alleles_1a_*, frq_1a_*, expectedhet_1a_*, puppies_1a_*, pup_avgs_1a_*
  Permanent: sim1a_progress, sim1a_replicates, sim1a_founders
"""

import logging
import os
import json
import time
import random
import azure.functions as func
import mysql.connector


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


THR_MAP = {
    'T1':     (0.900, 1.100),
    'T2':     (0.825, 1.175),
    'T3':     (0.750, 1.250),
    'T4':     (0.675, 1.325),
    'T5':     (0.600, 1.400),
    'RANDOM': (0.750, 1.250),
}
SFX_MAP = {'T1':'t1','T2':'t2','T3':'t3','T4':'t4','T5':'t5','RANDOM':'ran'}
VALID_STRATEGIES = list(THR_MAP.keys())


def ensure_progress_table(cursor):
    cursor.execute("""CREATE TABLE IF NOT EXISTS sim1a_progress (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        strategy         VARCHAR(10),
        gen              INT,
        completed_at     DATETIME,
        elapsed_seconds  FLOAT,
        avg_he           FLOAT,
        avg_ne           FLOAT,
        avg_na           FLOAT,
        avg_oi           FLOAT,
        avg_ir           FLOAT,
        band_infr        FLOAT,
        band_norm        FLOAT,
        band_hfreq       FLOAT,
        num_breeders     INT,
        breed_suffix     VARCHAR(5),
        replicate_num    INT,
        total_alleles    INT,
        INDEX strat_gen(strategy, gen))""")


def ensure_replicates_table(cursor):
    cursor.execute("""CREATE TABLE IF NOT EXISTS sim1a_replicates (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        strategy            VARCHAR(10),
        breed_suffix        VARCHAR(5),
        breed_id            INT,
        replicate_num       INT,
        completed_at        DATETIME,
        pop_size            INT,
        loci                INT,
        gen0_he             FLOAT, gen0_ne  FLOAT, gen0_na  FLOAT,
        gen20_he            FLOAT, gen20_ne FLOAT, gen20_na FLOAT,
        he_delta            FLOAT, ne_delta FLOAT, na_delta FLOAT,
        gen0_oi             FLOAT, gen20_oi FLOAT, oi_delta FLOAT,
        gen0_ir             FLOAT, gen20_ir FLOAT, ir_delta FLOAT,
        gen0_band_infr      FLOAT, gen20_band_infr  FLOAT,
        gen0_band_norm      FLOAT, gen20_band_norm  FLOAT,
        gen0_band_hfreq     FLOAT, gen20_band_hfreq FLOAT,
        band_infr_delta     FLOAT, band_norm_delta  FLOAT, band_hfreq_delta FLOAT,
        gen0_total_alleles  INT,   gen20_total_alleles INT, total_alleles_delta INT,
        INDEX strat_breed(strategy, breed_suffix))""")


def build_frq(cursor, s, gen, slot_ct):
    cursor.execute("INSERT INTO frq_1a_{s}(locus_id,str,gen) SELECT DISTINCT locus_id,str_a AS str,{g} FROM alleles_1a_{s} WHERE gen={g} UNION SELECT DISTINCT locus_id,str_b AS str,{g} FROM alleles_1a_{s} WHERE gen={g} ORDER BY locus_id,str".format(s=s,g=gen))
    for ab in ['a','b']:
        t = "better_bred.str_{ab}_{s}".format(ab=ab,s=s)
        cursor.execute("CREATE TABLE IF NOT EXISTS {t} (id INT AUTO_INCREMENT PRIMARY KEY,gen INT,locus_id INT,str_{ab} FLOAT,ct INT)".format(t=t,ab=ab))
        cursor.execute("INSERT INTO {t}(gen,locus_id,str_{ab},ct) SELECT {g},locus_id,str_{ab},COUNT(str_{ab}) FROM alleles_1a_{s} WHERE gen={g} GROUP BY locus_id,str_{ab}".format(t=t,ab=ab,g=gen,s=s))
        cursor.execute("UPDATE frq_1a_{s} f JOIN {t} x ON x.locus_id=f.locus_id AND x.str_{ab}=f.str SET f.count_str_{ab}=x.ct WHERE f.gen={g} AND f.id>0".format(s=s,t=t,ab=ab,g=gen))
        cursor.execute("DROP TABLE {}".format(t))
    cursor.execute("UPDATE frq_1a_{} SET count_str_total=(count_str_a+count_str_b) WHERE gen={} AND id>0".format(s,gen))
    cursor.execute("UPDATE frq_1a_{} SET frq=(count_str_total/{}) WHERE gen={} AND id>0".format(s,slot_ct,gen))


def build_eh(cursor, s, gen):
    cursor.execute("INSERT INTO expectedhet_1a_{s}(gen,locus_id,he,numstrs) SELECT gen,locus_id,(1-SUM(frq*frq)) AS he,COUNT(str) AS numstrs FROM frq_1a_{s} WHERE gen={g} GROUP BY gen,locus_id".format(s=s,g=gen))
    cursor.execute("UPDATE expectedhet_1a_{} SET effective_alleles=1/(1-he) WHERE gen={} AND id>=0".format(s,gen))


def get_bands(cursor, s, gen, thr_low, thr_high):
    cursor.execute("""SELECT
        AVG(CASE WHEN f.frq<({lo}/lt.n) THEN 1.0 ELSE 0.0 END),
        AVG(CASE WHEN f.frq>=({lo}/lt.n) AND f.frq<=({hi}/lt.n) THEN 1.0 ELSE 0.0 END),
        AVG(CASE WHEN f.frq>({hi}/lt.n) THEN 1.0 ELSE 0.0 END),
        COUNT(*)
        FROM frq_1a_{s} f
        JOIN (SELECT locus_id,COUNT(*) AS n FROM frq_1a_{s} WHERE gen={g} GROUP BY locus_id) lt
          ON lt.locus_id=f.locus_id
        WHERE f.gen={g}""".format(lo=thr_low,hi=thr_high,s=s,g=gen))
    row = cursor.fetchone()
    if row:
        return (round(float(row[0]) if row[0] else 0.0,6),
                round(float(row[1]) if row[1] else 0.0,6),
                round(float(row[2]) if row[2] else 0.0,6),
                int(row[3]) if row[3] else 0)
    return (0.0, 0.0, 0.0, 0)


def run_generation(cursor, strategy, s, breed_raw, gen, parent_gen, count_loci, t0, thr_low, thr_high, rep_num):
    # frq for parent_gen
    cursor.execute("SELECT COUNT(*) FROM frq_1a_{} WHERE gen={}".format(s, parent_gen))
    if int(cursor.fetchone()[0]) == 0:
        cursor.execute("SELECT COUNT(DISTINCT dog_id)*2 FROM alleles_1a_{} WHERE gen={}".format(s, parent_gen))
        slot_ct = int(cursor.fetchone()[0])
        build_frq(cursor, s, parent_gen, slot_ct)

    # expectedhet for parent_gen
    cursor.execute("SELECT COUNT(*) FROM expectedhet_1a_{} WHERE gen={}".format(s, parent_gen))
    if int(cursor.fetchone()[0]) == 0:
        build_eh(cursor, s, parent_gen)

    # parents
    cursor.execute("SELECT DISTINCT dog_id FROM alleles_1a_{} WHERE gender='M' AND gen={}".format(s, parent_gen))
    sires = [int(r[0]) for r in cursor.fetchall()]
    cursor.execute("SELECT DISTINCT dog_id FROM alleles_1a_{} WHERE gender='F' AND gen={}".format(s, parent_gen))
    dams = [int(r[0]) for r in cursor.fetchall()]
    random.shuffle(sires); random.shuffle(dams)
    pair_count = min(len(sires), len(dams))
    if pair_count == 0:
        raise ValueError("No parent pairs for gen {}".format(parent_gen))

    # load parent alleles
    cursor.execute("SELECT dog_id,locus_id,str_a,str_b FROM alleles_1a_{} WHERE gen={}".format(s, parent_gen))
    pa = {}
    for row in cursor.fetchall():
        did,lid,sa,sb = int(row[0]),int(row[1]),float(row[2]),float(row[3])
        if did not in pa: pa[did] = {}
        pa[did][lid] = (sa, sb)

    # generate puppies
    pup_tbl = "puppies_1a_{}".format(s)
    cursor.execute("""CREATE TABLE IF NOT EXISTS {t} (
        id INT AUTO_INCREMENT PRIMARY KEY,
        gen INT, litter_id INT, sire_id INT, dam_id INT, puppy_id INT,
        locus_id INT, str_a FLOAT, str_b FLOAT, homozygous TINYINT,
        INDEX gen_litter(gen,litter_id), INDEX puppy(gen,puppy_id))""".format(t=pup_tbl))
    cursor.execute("DELETE FROM {} WHERE gen={}".format(pup_tbl, gen))

    rows=[]; ctr=1
    for k,(sid,did) in enumerate(zip(sires[:pair_count],dams[:pair_count])):
        if sid not in pa or did not in pa: continue
        common = sorted(set(pa[sid].keys()) & set(pa[did].keys()))
        for _ in range(5):
            pid=ctr; ctr+=1
            for lid in common:
                sa=random.choice(pa[sid][lid]); sb=random.choice(pa[did][lid])
                rows.append((gen,k,sid,did,pid,lid,sa,sb,1 if sa==sb else 0))
    if rows:
        cursor.executemany("INSERT INTO {} (gen,litter_id,sire_id,dam_id,puppy_id,locus_id,str_a,str_b,homozygous) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)".format(pup_tbl), rows)

    # score puppies
    avg_tbl = "pup_avgs_1a_{}".format(s)
    cursor.execute("""CREATE TABLE IF NOT EXISTS {t} (
        id INT AUTO_INCREMENT PRIMARY KEY, gen INT, litter_id INT,
        sire_id INT, dam_id INT, puppy_id INT,
        ir DECIMAL(10,5), hl DECIMAL(10,5), oi DECIMAL(10,5),
        infr_als INT, norm_als INT, hfreq_als INT,
        rarehet INT, rarehom INT, normhom INT, comhet INT, comhom INT, mixedhet INT,
        unusual_anc DECIMAL(10,5), typical_anc DECIMAL(10,5),
        balanced_anc DECIMAL(10,5), mixed_anc DECIMAL(10,5), pcho DECIMAL(10,4),
        INDEX gen(gen), INDEX litter(litter_id), INDEX ir(ir))""".format(t=avg_tbl))
    cursor.execute("DELETE FROM {} WHERE gen={}".format(avg_tbl, gen))

    thr = "better_bred.tmp_locus_thr_{}".format(s)
    cursor.execute("DROP TABLE IF EXISTS {}".format(thr))
    cursor.execute("CREATE TABLE {t} (locus_id INT, numstrs INT, low_thr FLOAT, high_thr FLOAT, INDEX locus_id(locus_id))".format(t=thr))
    cursor.execute("INSERT INTO {t}(locus_id,numstrs,low_thr,high_thr) SELECT locus_id,COUNT(*),({lo}/COUNT(*)),({hi}/COUNT(*)) FROM frq_1a_{s} WHERE gen={g} GROUP BY locus_id".format(t=thr,lo=thr_low,hi=thr_high,s=s,g=parent_gen))

    cursor.execute("""INSERT INTO {pa}
        (gen,litter_id,sire_id,dam_id,puppy_id,ir,oi,hl,pcho,
         infr_als,norm_als,hfreq_als,rarehet,rarehom,normhom,comhet,comhom,mixedhet)
        SELECT p.gen,p.litter_id,p.sire_id,p.dam_id,p.puppy_id,
            ROUND(((2*SUM(p.homozygous))-SUM(fa.frq+fb.frq))/NULLIF((66-SUM(fa.frq+fb.frq)),0),2),
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
        JOIN frq_1a_{s} fa ON fa.locus_id=p.locus_id AND fa.str=p.str_a AND fa.gen={pg}
        JOIN frq_1a_{s} fb ON fb.locus_id=p.locus_id AND fb.str=p.str_b AND fb.gen={pg}
        JOIN {thr} lt ON lt.locus_id=p.locus_id
        JOIN expectedhet_1a_{s} eh ON eh.locus_id=p.locus_id AND eh.gen={pg}
        WHERE p.gen={g}
        GROUP BY p.gen,p.litter_id,p.sire_id,p.dam_id,p.puppy_id""".format(
        pa=avg_tbl,cl=count_loci,pt=pup_tbl,s=s,pg=parent_gen,thr=thr,g=gen))

    cursor.execute("UPDATE {pa} SET unusual_anc=((rarehom+rarehet)/{cl})*100,typical_anc=((comhom+comhet)/{cl})*100,balanced_anc=(normhom/{cl})*100,mixed_anc=(mixedhet/{cl})*100 WHERE gen={g}".format(pa=avg_tbl,cl=count_loci,g=gen))
    cursor.execute("DROP TABLE IF EXISTS {}".format(thr))

    # keeper selection
    cursor.execute("SELECT IFNULL(MAX(dog_id),0) FROM alleles_1a_{} WHERE gen={}".format(s,gen))
    next_id = int(cursor.fetchone()[0]) + 1
    selected = []

    for k in range(pair_count):
        cursor.execute("SELECT puppy_id FROM {} WHERE litter_id={} AND gen={}".format(avg_tbl,k,gen))
        litter = [int(r[0]) for r in cursor.fetchall()]
        if len(litter) < 2: continue
        random.shuffle(litter)
        half = len(litter)//2
        g1,g2 = litter[:half], litter[half:]

        if strategy == 'RANDOM':
            c1=random.choice(g1) if g1 else None
            c2=random.choice(g2) if g2 else None
        else:
            g1c=','.join(str(p) for p in g1); g2c=','.join(str(p) for p in g2)
            cursor.execute("SELECT puppy_id FROM {} WHERE litter_id={} AND gen={} AND puppy_id IN ({}) ORDER BY oi DESC LIMIT 1".format(avg_tbl,k,gen,g1c))
            r=cursor.fetchone(); c1=int(r[0]) if r else (g1[0] if g1 else None)
            cursor.execute("SELECT puppy_id FROM {} WHERE litter_id={} AND gen={} AND puppy_id IN ({}) ORDER BY oi DESC LIMIT 1".format(avg_tbl,k,gen,g2c))
            r=cursor.fetchone(); c2=int(r[0]) if r else (g2[0] if g2 else None)

        if not c1 or not c2: continue
        selected.extend([c1,c2])
        sexes=['M','F']; random.shuffle(sexes)
        for idx,cp in enumerate([c1,c2]):
            gn=sexes[idx]; did=next_id; next_id+=1
            cursor.execute("INSERT INTO alleles_1a_{s} (dog_id,gender,locus_id,str_a,str_b,homozygous,gen,sire_id,dam_id) SELECT {d},'{gn}',locus_id,str_a,str_b,homozygous,{g},sire_id,dam_id FROM {pt} WHERE puppy_id={cp} AND litter_id={k} AND gen={g}".format(s=s,d=did,gn=gn,g=gen,pt=pup_tbl,cp=cp,k=k))

    # frq + eh for keeper gen
    cursor.execute("SELECT COUNT(*) FROM frq_1a_{} WHERE gen={}".format(s,gen))
    if int(cursor.fetchone()[0]) == 0:
        keeper_slots = pair_count * 4
        build_frq(cursor, s, gen, keeper_slots)

    cursor.execute("SELECT COUNT(*) FROM expectedhet_1a_{} WHERE gen={}".format(s,gen))
    if int(cursor.fetchone()[0]) == 0:
        build_eh(cursor, s, gen)

    # mean OI/IR of keepers
    mean_oi=mean_ir=0.0
    if selected:
        ids=','.join(str(p) for p in selected)
        cursor.execute("SELECT AVG(oi),AVG(ir) FROM {} WHERE gen={} AND puppy_id IN ({})".format(avg_tbl,gen,ids))
        row=cursor.fetchone()
        if row: mean_oi=round(float(row[0]) if row[0] else 0.0,6); mean_ir=round(float(row[1]) if row[1] else 0.0,6)

    # bands + stats from keeper gen
    bi,bn,bh,total_alleles = get_bands(cursor,s,gen,thr_low,thr_high)

    cursor.execute("SELECT AVG(he),AVG(effective_alleles) FROM expectedhet_1a_{} WHERE gen={}".format(s,gen))
    sr=cursor.fetchone(); avg_he=float(sr[0]) if sr and sr[0] else 0.0; avg_ne=float(sr[1]) if sr and sr[1] else 0.0
    cursor.execute("SELECT AVG(allele_count) FROM (SELECT locus_id,COUNT(DISTINCT str) AS allele_count FROM frq_1a_{} WHERE gen={} GROUP BY locus_id) AS lc".format(s,gen))
    nr=cursor.fetchone(); avg_na=float(nr[0]) if nr and nr[0] else 0.0
    elapsed=round(time.time()-t0,1)

    cursor.execute("""INSERT INTO sim1a_progress
        (strategy,gen,completed_at,elapsed_seconds,avg_he,avg_ne,avg_na,
         avg_oi,avg_ir,band_infr,band_norm,band_hfreq,num_breeders,breed_suffix,replicate_num,total_alleles)
        VALUES (%s,%s,NOW(),%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
        (strategy,gen,elapsed,avg_he,avg_ne,avg_na,mean_oi,mean_ir,bi,bn,bh,pair_count*2,breed_raw,rep_num,total_alleles))

    return {'gen':gen,'avg_he':avg_he,'avg_ne':avg_ne,'avg_na':avg_na,
            'pair_count':pair_count,'elapsed':elapsed,
            'avg_oi':mean_oi,'avg_ir':mean_ir,
            'band_infr':bi,'band_norm':bn,'band_hfreq':bh,'total_alleles':total_alleles}


def record_replicate(cursor, strategy, s, breed_raw, breed_id, count_loci, g20, thr_low, thr_high, rep_num):
    cursor.execute("SELECT AVG(he),AVG(effective_alleles) FROM expectedhet_1a_{} WHERE gen=0".format(s))
    r0=cursor.fetchone()
    cursor.execute("SELECT AVG(allele_count) FROM (SELECT locus_id,COUNT(DISTINCT str) AS allele_count FROM frq_1a_{} WHERE gen=0 GROUP BY locus_id) AS lc".format(s))
    na0=cursor.fetchone()
    cursor.execute("SELECT COUNT(*) FROM frq_1a_{} WHERE gen=0".format(s))
    g0_total = int(cursor.fetchone()[0])
    bi0,bn0,bh0,_ = get_bands(cursor,s,0,thr_low,thr_high)

    # gen0 OI/IR from sim1a_founders
    cursor.execute("SELECT AVG(oi),AVG(ir) FROM sim1a_founders WHERE breed_suffix=%s AND replicate_num=%s",(breed_raw,rep_num))
    fr=cursor.fetchone()
    g0_oi=round(float(fr[0]) if fr and fr[0] else 0.0,6)
    g0_ir=round(float(fr[1]) if fr and fr[1] else 0.0,6)

    g0_he=round(float(r0[0]) if r0 and r0[0] else 0.0,4)
    g0_ne=round(float(r0[1]) if r0 and r0[1] else 0.0,4)
    g0_na=round(float(na0[0]) if na0 and na0[0] else 0.0,2)
    g20_he=round(g20['avg_he'],4); g20_ne=round(g20['avg_ne'],4); g20_na=round(g20['avg_na'],2)
    g20_oi=round(g20['avg_oi'],6); g20_ir=round(g20['avg_ir'],6)
    bi20=g20['band_infr']; bn20=g20['band_norm']; bh20=g20['band_hfreq']
    t20=g20['total_alleles']

    cursor.execute("""INSERT INTO sim1a_replicates
        (strategy,breed_suffix,breed_id,replicate_num,completed_at,pop_size,loci,
         gen0_he,gen0_ne,gen0_na,gen20_he,gen20_ne,gen20_na,he_delta,ne_delta,na_delta,
         gen0_oi,gen20_oi,oi_delta,gen0_ir,gen20_ir,ir_delta,
         gen0_band_infr,gen20_band_infr,gen0_band_norm,gen20_band_norm,
         gen0_band_hfreq,gen20_band_hfreq,band_infr_delta,band_norm_delta,band_hfreq_delta,
         gen0_total_alleles,gen20_total_alleles,total_alleles_delta)
        VALUES (%s,%s,%s,%s,NOW(),%s,%s,
                %s,%s,%s,%s,%s,%s,%s,%s,%s,
                %s,%s,%s,%s,%s,%s,
                %s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)""",
        (strategy,breed_raw,breed_id,rep_num,g20['pair_count']*2,count_loci,
         g0_he,g0_ne,g0_na,g20_he,g20_ne,g20_na,
         round(g20_he-g0_he,4),round(g20_ne-g0_ne,4),round(g20_na-g0_na,2),
         g0_oi,g20_oi,round(g20_oi-g0_oi,6),
         g0_ir,g20_ir,round(g20_ir-g0_ir,6),
         bi0,bi20,bn0,bn20,bh0,bh20,
         round(bi20-bi0,6),round(bn20-bn0,6),round(bh20-bh0,6),
         g0_total,t20,t20-g0_total))


def main(req: func.HttpRequest) -> func.HttpResponse:
    logging.info('run_sim_1a called')
    try: body=req.get_json()
    except ValueError: body={}

    strategy=str(body.get('strategy','')).upper()
    breed_suffix=str(body.get('breed_suffix','')).lower().strip()
    breed_id=int(body.get('breed_id',0))

    if strategy not in VALID_STRATEGIES:
        return func.HttpResponse(json.dumps({'success':False,'error':'Invalid strategy. Use T1-T5 or RANDOM'}),mimetype='application/json',status_code=400)
    if breed_id<=0:
        return func.HttpResponse(json.dumps({'success':False,'error':'Invalid breed_id'}),mimetype='application/json',status_code=400)

    thr_low,thr_high=THR_MAP[strategy]
    breed_sfx='' if breed_suffix=='' else '_'+breed_suffix
    s=SFX_MAP[strategy]+breed_sfx
    t0=time.time()

    try:
        conn=get_db(); cursor=conn.cursor()
        cursor.execute("SELECT MAX(gen) FROM alleles_1a_{}".format(s))
        row=cursor.fetchone()
        if row is None or row[0] is None:
            cursor.close();conn.close()
            return func.HttpResponse(json.dumps({'success':False,'error':'alleles_1a_{} empty. Seed founders first.'.format(s)}),mimetype='application/json',status_code=400)

        current_max=int(row[0])
        if current_max>=20:
            cursor.close();conn.close()
            return func.HttpResponse(json.dumps({'success':True,'message':'Already at gen 20','gen':20}),mimetype='application/json',status_code=200)

        cursor.execute("SELECT COUNT(DISTINCT locus_id) FROM alleles_1a_{}".format(s))
        count_loci=int(cursor.fetchone()[0])

        # get rep_num from sim1a_founders
        cursor.execute("SELECT MAX(replicate_num) FROM sim1a_founders WHERE breed_suffix=%s",(breed_suffix,))
        rr=cursor.fetchone(); rep_num=int(rr[0]) if rr and rr[0] else 1

        ensure_progress_table(cursor)
        ensure_replicates_table(cursor)

        results=[]
        for target_gen in range(current_max+1,21):
            g=run_generation(cursor,strategy,s,breed_suffix,target_gen,target_gen-1,count_loci,t0,thr_low,thr_high,rep_num)
            results.append(g)
            logging.info("1A Gen {} done: He={:.4f} Na={:.2f} {:.0f}s".format(target_gen,g['avg_he'],g['avg_na'],g['elapsed']))
            if target_gen==20:
                record_replicate(cursor,strategy,s,breed_suffix,breed_id,count_loci,g,thr_low,thr_high,rep_num)

        cursor.close();conn.close()
        final=results[-1] if results else {}
        return func.HttpResponse(json.dumps({'success':True,'strategy':strategy,'breed':breed_suffix,'gens_run':len(results),'final':final,'thr_low':thr_low,'thr_high':thr_high}),mimetype='application/json',status_code=200)

    except Exception as e:
        logging.exception("run_sim_1a error")
        return func.HttpResponse(json.dumps({'success':False,'error':str(e)}),mimetype='application/json',status_code=500)
