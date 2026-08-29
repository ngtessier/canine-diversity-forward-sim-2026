# Data and code for: Selecting puppies for underrepresented alleles conserves genetic diversity: a forward simulation in three dog breeds

Companion data and code repository for Tessier (2026).

This repository contains the complete materials for a forward simulation study comparing four keeper-selection strategies for managing genetic diversity in closed purebred dog populations: outlier index (OI), average genetic relatedness (AGR), internal relatedness (IR), and random selection. Founder populations of 200 real dogs with 33-locus STR microsatellite genotypes were bred forward for 20 generations, 50 replicates per breed, across three breeds with distinct bottleneck histories: Standard Poodle, Doberman Pinscher, and Flat-Coated Retriever. Selection acts on which puppies are kept, not on which parents are bred.

## Directory map

```
manuscript/
  SimStudyI-v41.docx                    assembled manuscript (AUTHORITATIVE)
  SimStudyI-v41.md                      pandoc text extraction of the docx, for
                                        accessibility and search only; not a build source
  figures/                              submission-resolution figures
    Figure1_allelic_richness.png
    Figure2_expected_heterozygosity.png
    Figure3_observed_heterozygosity.png
    Figure4_inbreeding_coefficient.png
    FigureS1_mean_internal_relatedness.png
    FigureS2_OI_AGR_vs_MCB_2panel.{png,pdf,eps}
    make_FigureS2.py                    regenerates Figure S2 from the
                                        deposited source data
  supplementary/
    SimStudyI_SupplementaryTables_S1-S5.xlsx
    SimStudyI_S6_threshold_sensitivity_data.xlsx
  data/figureS2/
    S2_mcb_oi_agr_deposit.csv           source data for Supplementary Figure S2

simulation/
  run_sim1.py                           main study runner (OI / AGR / IR / RANDOM)
  run_sim_1a.py                         threshold-sensitivity runner (T1–T5 + RANDOM),
                                        source for Supplementary Table S6
  founder-genotypes/
    founder_genotypes_anonymized.csv    33-locus STR genotypes for the drawn founders
    founder_derived_stats.csv           founder-pool summary statistics, one row per
                                        breed x replicate
  results/
    sim1_replicates_*_50rep.csv         one row per replicate x strategy
    sim1_progress_*_50rep.csv           one row per generation x replicate x strategy
    founder_replicate_rosters_anonymized.csv
                                        per-dog founder OI/IR/AGR for every
                                        replicate roster, all three breeds
```

The manuscript DOCX is authoritative. The Markdown file is a pandoc extraction of that DOCX, provided so the text is readable and searchable without Word; it is not a build source and regenerating the DOCX from it is not supported.

## Simulation source

Two runners are included. Both are Azure Function HTTP endpoints that operate against a MySQL working schema; database credentials are supplied through environment variables (`BB_DB_HOST`, `BB_DB_USER`, `BB_DB_PASS`, `BB_DB_NAME`) and no credentials are present in the source.

**`run_sim1.py`** is the main study runner and produced every result reported in the manuscript body. It implements the four keeper-selection strategies (OI, AGR, IR, RANDOM), the Wang (2002) relatedness estimator used for average genetic relatedness, per-generation observed and expected heterozygosity with F<sub>IS</sub>, and the allele frequency band composition reported in Table 3. It writes the `sim1_progress`, `sim1_replicates`, and `sim1_founders` tables. The first two are exported here as the `sim1_*` files in `results/`; the founders table is exported, re-keyed to the released study codes, as `results/founder_replicate_rosters_anonymized.csv`. Litter size, number of generations, and sires per dam are runtime parameters.

**`run_sim_1a.py`** is the threshold-sensitivity runner behind Supplementary Table S6. It re-runs the design across five low/high frequency-band threshold pairs (T1 = 0.900/1.100 through T5 = 0.600/1.400) plus a random control, where T3 = 0.750/1.250 is the standard used throughout the main study. It writes the separate `sim1a_*` tables.

Allele frequency band composition is computed inside `run_sim1.py` and is already present in the deposited replicate files as the `gen0_band_*` and `gen20_band_*` columns; no separate reproduction script is required.

## Figures

Figures 1–4 and Supplementary Figure S1 are PNG at 300 dpi (4320 × 1432 px). Supplementary Figure S2 is supplied at 600 dpi PNG and as vector PDF and EPS.

`make_FigureS2.py` regenerates Supplementary Figure S2 in all three formats directly from `manuscript/data/figureS2/S2_mcb_oi_agr_deposit.csv`, and prints the correlation coefficients it plots (r = −0.634 for outlier index, r = +0.628 for average genetic relatedness). It requires only `matplotlib` and `numpy`.

The plotting code for Figures 1–4 and S1 is not included; those figures are provided as rendered images. All values plotted in them are present in `simulation/results/sim1_progress_*_50rep.csv` (`avg_na`, `avg_he`, `avg_ho`, `avg_fis`, `avg_ir` by generation, strategy, and replicate).

## Table numbering (v41)

Table numbering changed twice across drafts: at v37 a breed-wide VGL statistics table entered the main text as Table 1, and at v40 that table moved to Supplementary Table S8, returning the main tables to a 1–4 sequence (with the per-breed outcome tables merged into a single Table 1). Readers working from an earlier draft should map as follows:

| v40–v41 (current) | v37-era drafts | Pre-v37 drafts | Contents |
|---|---|---|---|
| Table 1 | Tables 2a–c | Tables 1a–c | Founder (generation 0) and generation-20 outcomes, by strategy in each breed |
| Table 2 | Table 3 | Table 2 | Allele loss over the final five generations |
| Table 3 | Table 4 | Table 3 | Allele frequency band composition (% of distinct alleles) |
| Table 4 | Table 5 | Table 4 | Two highly inbred Standard Poodles: matching IR, divergent OI |
| Supplementary Table S8 | Table 1 | *(absent)* | Breed-wide VGL genetic diversity statistics for the three breeds |

Supplementary tables S1–S6 are unchanged throughout. Supplementary Table S7 (genome positions of the 33 panel loci on canFam4, with inter-locus distances) was added at v40. S7 and S8 are embedded in the manuscript itself; they have no separate spreadsheet files.

## Founder genotypes

`simulation/founder-genotypes/founder_genotypes_anonymized.csv` contains 33-locus STR microsatellite genotypes for the **4,835 dogs actually drawn as founders** across the 50-replicate study — 3,540 Standard Poodles, 859 Doberman Pinschers, and 436 Flat-Coated Retrievers. Each dog contributes exactly 33 rows, one per locus (`locus_id` 1–33), giving 159,555 rows.

Columns: `anon_id`, `breed`, `locus_id`, `str_a`, `str_b`.

Dogs are identified by breed-prefixed study code only: `SP0001`–`SP3540` (Standard Poodle), `DP0001`–`DP0859` (Doberman Pinscher), `FCR0001`–`FCR0436` (Flat-Coated Retriever). Codes were assigned in a randomized order so that code sequence carries no information about database order, registration date, or any other attribute. The same codes appear in `results/founder_replicate_rosters_anonymized.csv`, so per-dog founder metrics can be joined to genotypes within this repository.

The panel is the UC Davis Veterinary Genetics Laboratory 33-marker set: 21 ISAG parentage markers plus 12 VGL-selected markers, distributed across 25 autosomes. DLA-linked markers are excluded from the OI, IR, and AGR calculations.

## Founder rosters and founder-pool statistics

`simulation/results/founder_replicate_rosters_anonymized.csv` lists the 200 founders drawn for each replicate — 30,000 rows (3 breeds × 50 replicates × 200 dogs). Columns: `breed`, `replicate_num`, `anon_id`, `gender`, `oi`, `ir`, `agr`. Per-founder allele-frequency band counts are not exported at the dog level; band composition is reported at the population level in the `gen0_band_*` and `gen20_band_*` columns of the replicate files.

`simulation/founder-genotypes/founder_derived_stats.csv` summarizes each founder pool — 150 rows, one per breed × replicate. Columns: `breed`, `breed_suffix`, `replicate_num`, `n_founders`, `n_males`, `n_females`, `total_alleles`, `alleles_per_locus`, `expected_heterozygosity`, `observed_heterozygosity`, `effective_alleles`, `fis`, `fis_global`, `mean_outlier_index`, `mean_internal_relatedness`, `mean_avg_genetic_relatedness`.

## Supplementary Figure S2 dataset

`manuscript/data/figureS2/S2_mcb_oi_agr_deposit.csv` holds the source data for Supplementary Figure S2: per-dog Mid-Century Bottleneck (MCB) ancestry percentage alongside outlier index and average genetic relatedness for 632 Standard Poodles.

Columns: `study_code`, `mcb_pct`, `oi`, `agr`. 632 rows, `SP001`–`SP632`.

These values reproduce the correlations reported in the manuscript: r = −0.634 for MCB against outlier index, r = +0.628 for MCB against average genetic relatedness.

**These three-digit `SP001`–`SP632` study codes are a separate, unlinked code space from the four-digit `SP0001`-style codes in the founder files.** The two numbering schemes were assigned independently: equal numbers do not refer to the same dog, the datasets cannot be joined, and no crosswalk between the two is released. This is deliberate.

## Known artifact in the replicate files

> The `band_infr_delta`, `band_norm_delta` and `band_hfreq_delta` columns in the replicate files subtract two band measures computed on different denominators and are not interpretable. All other delta columns are valid.

Use the paired `gen0_band_*` and `gen20_band_*` columns directly rather than the delta columns.

## Anonymization

All released data are anonymized. Dogs carry study codes only. No registered names, owner details, production database identifiers, birth dates, or other identifying fields appear in any file in this repository. Code-to-identity crosswalks are held privately by the author and are not part of this deposit.

## Licensing

Code is released under the MIT License. Data, manuscript, and figures are released under CC-BY-4.0. See `LICENSE-CODE` and `LICENSE-DATA`.

## Citation

Tessier, N. G. (2026). *Selecting puppies for underrepresented alleles conserves genetic diversity: a forward simulation in three dog breeds.* Data and code: https://doi.org/10.5281/zenodo.22161273

ORCID: [0009-0007-5339-0822](https://orcid.org/0009-0007-5339-0822)
